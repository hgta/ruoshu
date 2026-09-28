from __future__ import annotations

from fastapi import APIRouter

router = APIRouter(tags=["health"])


@router.get("/healthz", summary="存活探针")
def healthz() -> dict:
    return {"status": "ok"}


@router.get("/readyz", summary="就绪探针（MVP 无外部依赖，直接返回 ok）")
def readyz() -> dict:
    # 后续接 Redis/数据库时在此检查依赖
    return {"status": "ready"}