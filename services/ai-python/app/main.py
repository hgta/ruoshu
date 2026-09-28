"""若书 AI 文本处理边车 — MVP 入口。

仅内网绑定（127.0.0.1），仅供 apps/web 调用。
提供：
  /healthz
  /v1/normalize               文本规范化
  /v1/fingerprint/chapter     分段 + SHA-256 + Merkle root
  /v1/watermark/plan          水印嵌入锚点规划
  /v1/watermark/embed         实际嵌入
  /v1/watermark/extract       提取水印 payload
  /v1/trace/match             盗版文本溯源匹配（本地指纹库）
  /v1/forensic/pdf            取证包 PDF 生成（reportlab + CID 中文字体）
"""
from __future__ import annotations

import os
from fastapi import FastAPI

from .routers import health, fingerprint, watermark, normalize, forensic, statement

app = FastAPI(
    title="ruoshu-ai-python",
    version="0.1.0",
    description="若书小说平台 AI 文本处理边车：指纹 / 水印 / 溯源",
)

# 注册路由
app.include_router(health.router)
app.include_router(normalize.router)
app.include_router(fingerprint.router)
app.include_router(watermark.router)
app.include_router(forensic.router)
app.include_router(statement.router)


@app.get("/", include_in_schema=False)
def root() -> dict:
    return {
        "service": "ruoshu-ai-python",
        "version": app.version,
        "docs": "/docs",
        "health": "/healthz",
    }


if __name__ == "__main__":
    import uvicorn
    host = os.environ.get("AI_BIND_HOST", "127.0.0.1")
    port = int(os.environ.get("AI_PORT", "9000"))
    uvicorn.run("app.main:app", host=host, port=port, reload=False)