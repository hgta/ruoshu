package auth

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"encoding/pem"
	"testing"
	"time"

	"github.com/golang-jwt/jwt/v5"
)

// 生成测试用 RSA 密钥对。
func testKeys(t *testing.T) (string, string) {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		t.Fatalf("生成密钥失败: %v", err)
	}
	priv := pem.EncodeToMemory(&pem.Block{
		Type:  "RSA PRIVATE KEY",
		Bytes: x509.MarshalPKCS1PrivateKey(key),
	})
	pub := pem.EncodeToMemory(&pem.Block{
		Type:  "PUBLIC KEY",
		Bytes: x509.MarshalPKCS1PublicKey(&key.PublicKey),
	})
	return string(priv), string(pub)
}

func TestVerifyValidToken(t *testing.T) {
	priv, pub := testKeys(t)
	v, err := NewVerifier(pub, "ruoshu-realtime")
	if err != nil {
		t.Fatalf("NewVerifier: %v", err)
	}

	now := time.Now()
	tok := jwt.NewWithClaims(jwt.SigningMethodRS256, &Claims{
		UserID: 42,
		Role:   "reader",
		RegisteredClaims: jwt.RegisteredClaims{
			Issuer:    "ruoshu-realtime",
			ExpiresAt: jwt.NewNumericDate(now.Add(time.Minute)),
			IssuedAt:  jwt.NewNumericDate(now),
		},
	})
	s, err := tok.SignedString(mustKey(t, priv))
	if err != nil {
		t.Fatalf("签名失败: %v", err)
	}

	claims, err := v.Verify(s)
	if err != nil {
		t.Fatalf("合法 token 验签失败: %v", err)
	}
	if claims.UserID != 42 || claims.Role != "reader" {
		t.Fatalf("claims 解析错误: %+v", claims)
	}
}

func TestVerifyRejects(t *testing.T) {
	priv, pub := testKeys(t)
	v, _ := NewVerifier(pub, "ruoshu-realtime")

	// 空 token
	if _, err := v.Verify(""); err != ErrMissingToken {
		t.Fatalf("空 token 应 ErrMissingToken, got %v", err)
	}

	// 过期 token
	s := sign(t, priv, "ruoshu-realtime", time.Now().Add(-time.Hour))
	if _, err := v.Verify(s); err != ErrExpired {
		t.Fatalf("过期 token 应 ErrExpired, got %v", err)
	}

	// 错误 issuer
	s = sign(t, priv, "other-issuer", time.Now().Add(time.Hour))
	if _, err := v.Verify(s); err == nil {
		t.Fatal("错误 issuer 应被拒绝")
	}
}

func sign(t *testing.T, privPEM, issuer string, exp time.Time) string {
	t.Helper()
	key := mustKey(t, privPEM)
	tok := jwt.NewWithClaims(jwt.SigningMethodRS256, &Claims{
		UserID: 1,
		RegisteredClaims: jwt.RegisteredClaims{
			Issuer:    issuer,
			ExpiresAt: jwt.NewNumericDate(exp),
		},
	})
	s, err := tok.SignedString(key)
	if err != nil {
		t.Fatalf("签名失败: %v", err)
	}
	return s
}

func mustKey(t *testing.T, pemStr string) *rsa.PrivateKey {
	t.Helper()
	block, _ := pem.Decode([]byte(pemStr))
	key, err := x509.ParsePKCS1PrivateKey(block.Bytes)
	if err != nil {
		t.Fatalf("解析私钥失败: %v", err)
	}
	return key
}
