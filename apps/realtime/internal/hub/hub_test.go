package hub

import (
	"testing"
	"time"

	"github.com/rs/zerolog"
)

// testConn 实现 hub.Conn 接口的测试替身。
type testConn struct {
	key    int64
	userID int64
	send   chan []byte
	got    [][]byte
}

func newTestConn(book, chapter, user int64) *testConn {
	return &testConn{
		key:    (book << 32) | (chapter & 0xFFFFFFFF),
		userID: user,
		send:   make(chan []byte, 8),
	}
}

func (c *testConn) Key() int64         { return c.key }
func (c *testConn) UserID() int64      { return c.userID }
func (c *testConn) Send() chan []byte  { return c.send }

// drain 非阻塞收集已收到的消息。
func (c *testConn) drain() [][]byte {
	for {
		select {
		case m := <-c.send:
			c.got = append(c.got, m)
		default:
			return c.got
		}
	}
}

func newTestHub() *Hub {
	return New(zerolog.Nop())
}

// 注册/注销生命周期。
func TestRegisterUnregister(t *testing.T) {
	h := newTestHub()
	c := newTestConn(1, 10, 100)
	h.Register(c)

	if got := len(h.conns[1<<32|10]); got != 1 {
		t.Fatalf("注册后连接数 = %d, want 1", got)
	}

	h.Unregister(c)
	if _, ok := h.conns[1<<32|10]; ok {
		t.Fatal("注销后组合键应被清理")
	}
}

// 章节频道消息只路由到订阅同一章节的连接。
func TestDispatchRoutesByChapter(t *testing.T) {
	h := newTestHub()
	target := newTestConn(1, 10, 100)  // book1 ch10
	other := newTestConn(1, 11, 101)   // book1 ch11（同书不同章）
	otherBook := newTestConn(2, 10, 102)

	h.Register(target)
	h.Register(other)
	h.Register(otherBook)

	h.dispatch("book:1:ch:10:danmu", []byte(`{"type":"danmu","payload":{"id":1}}`))

	if got := len(target.drain()); got != 1 {
		t.Fatalf("目标连接应收到 1 条, got %d", got)
	}
	if got := len(other.drain()); got != 0 {
		t.Fatalf("同书他章连接不应收到, got %d", got)
	}
	if got := len(otherBook.drain()); got != 0 {
		t.Fatalf("他书连接不应收到, got %d", got)
	}
}

// 作者通知频道：全节点广播。
func TestDispatchAuthorNotifyBroadcasts(t *testing.T) {
	h := newTestHub()
	a := newTestConn(1, 10, 100)
	b := newTestConn(2, 20, 101)
	h.Register(a)
	h.Register(b)

	h.dispatch("author:7:notify", []byte(`{"type":"notify","payload":{}}`))

	if got := len(a.drain()); got != 1 {
		t.Fatalf("连接 A 应收到 1 条, got %d", got)
	}
	if got := len(b.drain()); got != 1 {
		t.Fatalf("连接 B 应收到 1 条, got %d", got)
	}
}

// 非法载荷不 panic、不投递。
func TestDispatchInvalidPayload(t *testing.T) {
	h := newTestHub()
	c := newTestConn(1, 10, 100)
	h.Register(c)

	h.dispatch("book:1:ch:10:danmu", []byte(`not-json`))
	h.dispatch("bogus-channel", []byte(`{"type":"x"}`))

	time.Sleep(10 * time.Millisecond)
	if got := len(c.drain()); got != 0 {
		t.Fatalf("非法消息不应投递, got %d", got)
	}
}

// routeChannel 解析。
func TestRouteChannel(t *testing.T) {
	if k, kind := routeChannel("book:1:ch:10:comment"); kind != routeChapter || k != 1<<32|10 {
		t.Fatalf("章节频道解析错误: k=%d kind=%v", k, kind)
	}
	if _, kind := routeChannel("author:1:notify"); kind != routeBroadcast {
		t.Fatalf("作者频道应广播, got %v", kind)
	}
	if _, kind := routeChannel("book:x:ch:y:z"); kind != routeDrop {
		t.Fatalf("非法数字应丢弃, got %v", kind)
	}
	if _, kind := routeChannel("bogus-channel"); kind != routeDrop {
		t.Fatalf("未知频道应丢弃, got %v", kind)
	}
}
