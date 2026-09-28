// Package config 提供配置加载（env 变量优先）。
package config

import (
	"fmt"
	"os"
)

type Config struct {
	Port          string
	RedisAddr     string
	RedisPassword string
	RedisDB       int

	JWTPublicKey string // PEM 格式
	JWTIssuer    string
}

func Load() (*Config, error) {
	cfg := &Config{
		Port:          getenv("REALTIME_PORT", "8080"),
		RedisAddr:     os.Getenv("REDIS_ADDR"),
		RedisPassword: os.Getenv("REDIS_PASSWORD"),
		JWTPublicKey:  os.Getenv("JWT_PUBLIC_KEY"),
		JWTIssuer:     os.Getenv("JWT_ISSUER"),
	}
	if cfg.RedisAddr == "" {
		return nil, fmt.Errorf("REDIS_ADDR 不能为空")
	}
	if cfg.JWTPublicKey == "" {
		return nil, fmt.Errorf("JWT_PUBLIC_KEY 不能为空")
	}
	if cfg.JWTIssuer == "" {
		return nil, fmt.Errorf("JWT_ISSUER 不能为空")
	}
	return cfg, nil
}

func getenv(k, def string) string {
	v := os.Getenv(k)
	if v == "" {
		return def
	}
	return v
}