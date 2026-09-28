// Package hub 管理 WebSocket 连接池与广播循环。
//
// 设计：Hub 是单实例的全局对象；连接 (Conn) 由 ws 包处理收发；
// Redis 订阅循环把外站消息按频道路由到订阅该章节的连接。
// 跨节点 fanout 由 Laravel Redis Pub/Sub 天然承担（每个节点只广播本节点连接）。
package hub

import (
	"context"
	"encoding/json"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog"
)

// Conn 是 WebSocket 连接的抽象（由 ws 包实现）。
type Conn interface {
	Key() int64          // (book_id << 32) | chapter_id
	UserID() int64
	Send() chan []byte   // Hub 向连接推送消息的通道
}

// 频道命名约定：book:{book_id}:ch:{chapter_id}:{kind}；author:{author_id}:notify
const (
	ChannelSuffixComment  = "comment"
	ChannelSuffixDanmu    = "danmu"
	ChannelSuffixDonation = "donation"
)

// Message 是 Redis 频道上传输的统一信封。
type Message struct {
	Type    string `json:"type"`
	Payload any    `json:"payload"`
}

// Hub 是连接池 + 广播中心。
type Hub struct {
	logger zerolog.Logger

	mu    sync.RWMutex
	conns map[int64]map[Conn]struct{} // (book,chapter) 组合键 → 连接集合
}

// New 构造 Hub。
func New(logger zerolog.Logger) *Hub {
	return &Hub{
		logger: logger,
		conns:  make(map[int64]map[Conn]struct{}),
	}
}

// StartRedisSubscriber 启动订阅循环（异步、单 goroutine）。
func (h *Hub) StartRedisSubscriber(ctx context.Context, rdb *redis.Client) {
	go h.subscribeLoop(ctx, rdb)
}

func (h *Hub) subscribeLoop(ctx context.Context, rdb *redis.Client) {
	// pattern 订阅：book:* 与 author:*
	sub := rdb.PSubscribe(ctx, "book:*", "author:*")
	defer func() { _ = sub.Close() }()

	ch := sub.Channel()
	for {
		select {
		case <-ctx.Done():
			return
		case msg, ok := <-ch:
			if !ok {
				return
			}
			h.dispatch(msg.Channel, []byte(msg.Payload))
		}
	}
}

// dispatch 把消息路由到订阅目标章节的连接集合。
// 频道格式 book:{book_id}:ch:{chapter_id}:{kind} → 只发对应章节的连接；
// author:{author_id}:notify → 逐节点全量（MVP：作者连接暂未单独分组）。
func (h *Hub) dispatch(channel string, payload []byte) {
	var m Message
	if err := json.Unmarshal(payload, &m); err != nil {
		h.logger.Warn().Err(err).Str("channel", channel).Msg("消息载荷解析失败")
		return
	}
	body, _ := json.Marshal(m)

	key, kind := routeChannel(channel)
	switch kind {
	case routeDrop:
		h.logger.Warn().Str("channel", channel).Msg("无法识别的频道，丢弃")
		return
	case routeBroadcast:
		// 作者通知：广播到本节点全部连接（作者连接暂未单独分组，MVP 可接受）
	}

	h.mu.RLock()
	defer h.mu.RUnlock()
	if key != 0 { // 章节频道：精确路由到订阅该章节的连接
		if set, ok := h.conns[key]; ok {
			for c := range set {
				h.trySend(c, body, channel)
			}
		}
		return
	}
	// 全节点广播
	for _, set := range h.conns {
		for c := range set {
			h.trySend(c, body, channel)
		}
	}
}

func (h *Hub) trySend(c Conn, body []byte, channel string) {
	select {
	case c.Send() <- body:
	case <-time.After(2 * time.Second):
		h.logger.Warn().Str("channel", channel).Msg("消息发送超时，丢弃")
	}
}

// 频道路由类型。
type routeKind int

const (
	routeDrop      routeKind = iota // 未知频道：丢弃
	routeChapter                    // 章节频道：精确路由
	routeBroadcast                  // 作者通知：全节点广播
)

// routeChannel 解析频道名：(组合键, 路由类型)。
// book:{book_id}:ch:{chapter_id}:{kind} → 精确路由；
// author:{author_id}:notify → 广播；
// 其他 → 丢弃。
func routeChannel(channel string) (int64, routeKind) {
	parts := strings.Split(channel, ":")
	switch {
	case len(parts) == 5 && parts[0] == "book" && parts[2] == "ch":
		book, err := strconv.ParseInt(parts[1], 10, 64)
		if err != nil {
			return 0, routeDrop
		}
		ch, err := strconv.ParseInt(parts[3], 10, 64)
		if err != nil {
			return 0, routeDrop
		}
		return (book << 32) | (ch & 0xFFFFFFFF), routeChapter
	case len(parts) == 3 && parts[0] == "author" && parts[2] == "notify":
		if _, err := strconv.ParseInt(parts[1], 10, 64); err != nil {
			return 0, routeDrop
		}
		return 0, routeBroadcast
	default:
		return 0, routeDrop
	}
}

// Register / Unregister 由 ws 包调用（管理连接生命周期）。
func (h *Hub) Register(c Conn) {
	h.mu.Lock()
	defer h.mu.Unlock()
	set, ok := h.conns[c.Key()]
	if !ok {
		set = make(map[Conn]struct{})
		h.conns[c.Key()] = set
	}
	set[c] = struct{}{}
	h.logger.Info().Int64("user_id", c.UserID()).Msg("连接注册")
}

func (h *Hub) Unregister(c Conn) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if set, ok := h.conns[c.Key()]; ok {
		delete(set, c)
		if len(set) == 0 {
			delete(h.conns, c.Key())
		}
	}
	h.logger.Info().Int64("user_id", c.UserID()).Msg("连接注销")
}

func (h *Hub) Close() error {
	h.mu.Lock()
	defer h.mu.Unlock()
	for _, set := range h.conns {
		for c := range set {
			close(c.Send())
		}
	}
	h.conns = nil
	return nil
}
