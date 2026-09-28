// Package auth 提供 JWT 验签（仅握手时用，不持久化任何状态）。
package auth

import (
	"crypto/rsa"
	"errors"
	"fmt"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

var (
	ErrMissingToken = errors.New("missing token")
	ErrInvalidToken = errors.New("invalid token")
	ErrExpired      = errors.New("token expired")
)

type Claims struct {
	UserID int64  `json:"uid"`
	Role   string `json:"role"` // reader / author / admin
	jwt.RegisteredClaims
}

type Verifier struct {
	pub    *rsa.PublicKey
	issuer string
}

func NewVerifier(publicKeyPEM, issuer string) (*Verifier, error) {
	pub, err := jwt.ParseRSAPublicKeyFromPEM([]byte(publicKeyPEM))
	if err != nil {
		return nil, fmt.Errorf("解析公钥失败: %w", err)
	}
	return &Verifier{pub: pub, issuer: issuer}, nil
}

// Verify 校验 token 签名、iss、exp；返回解析后的 Claims。
func (v *Verifier) Verify(tokenStr string) (*Claims, error) {
	if tokenStr == "" {
		return nil, ErrMissingToken
	}
	tok, err := jwt.ParseWithClaims(tokenStr, &Claims{}, func(t *jwt.Token) (any, error) {
		if _, ok := t.Method.(*jwt.SigningMethodRSA); !ok {
			return nil, fmt.Errorf("unexpected signing method: %v", t.Header["alg"])
		}
		return v.pub, nil
	}, jwt.WithIssuer(v.issuer), jwt.WithLeeway(30*time.Second))
	if err != nil {
		if errors.Is(err, jwt.ErrTokenExpired) {
			return nil, ErrExpired
		}
		return nil, ErrInvalidToken
	}
	c, ok := tok.Claims.(*Claims)
	if !ok || !tok.Valid {
		return nil, ErrInvalidToken
	}
	return c, nil
}