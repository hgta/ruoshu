// Package ws 处理 WebSocket 升级与连接读写循环。
package ws

import (
	"net/http"
	"strconv"
	"time"

	"github.com/gorilla/websocket"
	"github.com/rs/zerolog"

	"github.com/ruoshu/realtime/internal/auth"
	"github.com/ruoshu/realtime/internal/hub"
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 4096,
	CheckOrigin: func(r *http.Request) bool {
		// 同源策略在网关层做（生产环境按部署域名收紧）
		return true
	},
}

// Handler 返回 /ws 端点的 http.HandlerFunc。
func Handler(h *hub.Hub, verifier *auth.Verifier, logger zerolog.Logger) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		// 1. 取 token（query 参数），验签
		token := r.URL.Query().Get("token")
		claims, err := verifier.Verify(token)
		if err != nil {
			logger.Warn().Err(err).Msg("握手鉴权失败")
			http.Error(w, "unauthorized", http.StatusUnauthorized)
			return
		}

		// 2. 取订阅目标（book_id + chapter_id）
		bookID, _ := strconv.ParseInt(r.URL.Query().Get("book_id"), 10, 64)
		chapterID, _ := strconv.ParseInt(r.URL.Query().Get("chapter_id"), 10, 64)
		if bookID == 0 || chapterID == 0 {
			http.Error(w, "missing book_id or chapter_id", http.StatusBadRequest)
			return
		}

		// 3. WebSocket 升级
		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			logger.Warn().Err(err).Msg("WebSocket 升级失败")
			return
		}

		// 4. 启动读写循环
		c := &connImpl{
			userID:    claims.UserID,
			bookID:   bookID,
			chapterID: chapterID,
			ws:       conn,
			send:     make(chan []byte, 32),
		}
		h.Register(c)
		go c.writeLoop(logger)
		c.readLoop(h, logger)
	}
}

// connImpl 实现 hub.Conn 接口。
type connImpl struct {
	userID    int64
	bookID    int64
	chapterID int64
	ws        *websocket.Conn
	send      chan []byte
}

// Key 返回 (book_id << 32) | chapter_id 组合键（与 hub.channelKey 对齐）。
func (c *connImpl) Key() int64 {
	return (c.bookID << 32) | (c.chapterID & 0xFFFFFFFF)
}

func (c *connImpl) UserID() int64   { return c.userID }
func (c *connImpl) Send() chan []byte { return c.send }

const (
	writeWait      = 10 * time.Second
	pongWait       = 60 * time.Second
	pingPeriod     = (pongWait * 9) / 10
	maxMessageSize = 512
)

func (c *connImpl) readLoop(h *hub.Hub, logger zerolog.Logger) {
	defer func() {
		h.Unregister(c)
		_ = c.ws.Close()
	}()
	c.ws.SetReadLimit(maxMessageSize)
	_ = c.ws.SetReadDeadline(time.Now().Add(pongWait))
	c.ws.SetPongHandler(func(string) error {
		_ = c.ws.SetReadDeadline(time.Now().Add(pongWait))
		return nil
	})
	for {
		// MVP：客户端只发心跳；弹幕/评论发布走 Laravel HTTP 接口（鉴权 + 敏感词统一收口）
		if _, _, err := c.ws.ReadMessage(); err != nil {
			if !websocket.IsCloseError(err, websocket.CloseNormalClosure, websocket.CloseGoingAway) {
				logger.Debug().Err(err).Int64("user_id", c.userID).Msg("read error")
			}
			return
		}
	}
}

func (c *connImpl) writeLoop(logger zerolog.Logger) {
	ticker := time.NewTicker(pingPeriod)
	defer func() {
		ticker.Stop()
		_ = c.ws.Close()
	}()
	for {
		select {
		case msg, ok := <-c.send:
			_ = c.ws.SetWriteDeadline(time.Now().Add(writeWait))
			if !ok {
				_ = c.ws.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}
			if err := c.ws.WriteMessage(websocket.TextMessage, msg); err != nil {
				logger.Debug().Err(err).Msg("write error")
				return
			}
		case <-ticker.C:
			_ = c.ws.SetWriteDeadline(time.Now().Add(writeWait))
			if err := c.ws.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
