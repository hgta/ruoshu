"""段落指纹 + Merkle root 计算。

输入规范化文本，输出每段 SHA-256 与整章 Merkle root（双 SHA-256，类 Bitcoin 风格）。
所有哈希十六进制输出。
"""
from __future__ import annotations

import hashlib
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from .normalize import normalize, NormalizeRequest, NormalizeResponse

router = APIRouter(prefix="/v1/fingerprint", tags=["fingerprint"])


class FingerprintRequest(BaseModel):
    text: str = Field(..., description="章节原文")
    chapter_id: str = Field(..., description="章节 ID（用于追踪）")


class ParagraphInfo(BaseModel):
    para_no: int
    hash: str
    char_count: int


class FingerprintResponse(BaseModel):
    chapter_id: str
    paragraphs: list[ParagraphInfo]
    merkle_root: str
    paragraph_count: int
    total_chars: int


def _sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def _merkle_root(leaf_hashes: list[str]) -> str:
    if not leaf_hashes:
        return _sha256(b"")
    level = [_sha256(bytes.fromhex(h)) for h in leaf_hashes]
    while len(level) > 1:
        nxt: list[str] = []
        for i in range(0, len(level), 2):
            left = level[i]
            right = level[i + 1] if i + 1 < len(level) else left
            nxt.append(_sha256((left + right).encode("ascii")))
        level = nxt
    return level[0]


@router.post("/chapter", response_model=FingerprintResponse, summary="章节段落指纹 + Merkle root")
def chapter_fingerprint(req: FingerprintRequest) -> FingerprintResponse:
    if not req.text.strip():
        raise HTTPException(status_code=400, detail="text is empty")

    norm: NormalizeResponse = normalize(
        NormalizeRequest(text=req.text, fold_whitespace=True, split_paragraphs=True)
    )
    paragraphs = norm.paragraphs
    if not paragraphs:
        raise HTTPException(status_code=400, detail="no paragraphs after normalization")

    infos: list[ParagraphInfo] = []
    leaf_hashes: list[str] = []
    total = 0
    for idx, p in enumerate(paragraphs, start=1):
        h = _sha256(p.encode("utf-8"))
        leaf_hashes.append(h)
        total += len(p)
        infos.append(ParagraphInfo(para_no=idx, hash=h, char_count=len(p)))

    return FingerprintResponse(
        chapter_id=req.chapter_id,
        paragraphs=infos,
        merkle_root=_merkle_root(leaf_hashes),
        paragraph_count=len(paragraphs),
        total_chars=total,
    )