// 若书实时服务：WebSocket 网关，订阅 Redis 频道并广播。
//
// 服务边界（见 README）：
//   - 只做 WebSocket 鉴权握手、Redis 频道订阅转发、连接管理、健康检查
//   - 不访问业务数据库，不做文本处理，不发任何 HTTP 业务接口
package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
	"github.com/rs/zerolog"

	"github.com/ruoshu/realtime/internal/auth"
	"github.com/ruoshu/realtime/internal/config"
	"github.com/ruoshu/realtime/internal/hub"
	"github.com/ruoshu/realtime/internal/ws"
)

func main() {
	logger := zerolog.New(os.Stdout).With().Timestamp().Logger()

	cfg, err := config.Load()
	if err != nil {
		logger.Fatal().Err(err).Msg("配置加载失败")
	}

	// Redis 客户端
	rdb := redis.NewClient(&redis.Options{
		Addr:         cfg.RedisAddr,
		Password:     cfg.RedisPassword,
		DB:           cfg.RedisDB,
		DialTimeout:  3 * time.Second,
		ReadTimeout:  3 * time.Second,
		WriteTimeout: 3 * time.Second,
	})
	defer func() { _ = rdb.Close() }()

	// JWT 验签器
	verifier, err := auth.NewVerifier(cfg.JWTPublicKey, cfg.JWTIssuer)
	if err != nil {
		logger.Fatal().Err(err).Msg("JWT 验签器初始化失败")
	}

	// Hub：连接池 + 广播循环
	h := hub.New(logger)
	h.StartRedisSubscriber(context.Background(), rdb)

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	})
	mux.HandleFunc("/readyz", func(w http.ResponseWriter, r *http.Request) {
		if err := rdb.Ping(r.Context()).Err(); err != nil {
			http.Error(w, "redis not ready", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"status":"ready"}`))
	})
	mux.HandleFunc("/ws", ws.Handler(h, verifier, logger))

	addr := ":" + cfg.Port
	srv := &http.Server{
		Addr:              addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}

	// 后台优雅退出
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		logger.Info().Str("addr", addr).Msg("realtime 服务启动")
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Fatal().Err(err).Msg("ListenAndServe")
		}
	}()

	<-stop
	logger.Info().Msg("收到终止信号，开始优雅关闭")

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = srv.Shutdown(ctx)
	_ = h.Close()

	log.Println("realtime 服务退出")
}