"""文本规范化：用于指纹生成与盗版比对前的统一处理。

规范化策略（MVP 最小集）：
  1. 统一换行（\\r\\n / \\r → \\n）
  2. 中文标点归一（仅做最小可逆替换，避免破坏原文语义）
  3. 折叠半角/全角空白为单空格（段落内）/ 段落切分（按空行）

注意：规范化必须是**幂等且可逆**的（哈希前能复原），
且不破坏水印嵌入位置。
"""
from __future__ import annotations

import re
from fastapi import APIRouter
from pydantic import BaseModel, Field

router = APIRouter(prefix="/v1", tags=["normalize"])

# 行内空白折叠：半角全角空白 + 全角空格 → 单半角空格
_WS_RE = re.compile(r"[\u00a0\u2000-\u200a\u205f\u3000]+")
# 中英文标点的最小归一（全角逗号/句号是最常见的，不动；只处理易混的）
_PUNCT_NORMALIZE = [
    ("…", "……"),  # 三点省略号统一为标准六点
    ("·", "·"),  # 中点保持
]


class NormalizeRequest(BaseModel):
    text: str = Field(..., description="原始文本")
    fold_whitespace: bool = Field(True, description="折叠行内空白为单空格")
    split_paragraphs: bool = Field(True, description="按空行切分为段落列表")


class NormalizeResponse(BaseModel):
    normalized: str
    paragraphs: list[str]
    paragraph_count: int


@router.post("/normalize", response_model=NormalizeResponse, summary="文本规范化")
def normalize(req: NormalizeRequest) -> NormalizeResponse:
    text = req.text
    # 1. 统一换行
    text = text.replace("\r\n", "\n").replace("\r", "\n")
    # 2. 标点归一
    for src, dst in _PUNCT_NORMALIZE:
        text = text.replace(src, dst)
    # 3. 行内空白折叠
    if req.fold_whitespace:
        text = _WS_RE.sub(" ", text)
    # 4. 段落切分
    paragraphs: list[str] = []
    if req.split_paragraphs:
        for block in text.split("\n\n"):
            block = block.strip()
            if block:
                # 段落内换行折叠为单空格
                block = re.sub(r"\s*\n\s*", " ", block)
                if req.fold_whitespace:
                    block = _WS_RE.sub(" ", block)
                paragraphs.append(block)
    else:
        paragraphs = [text.strip()]

    normalized = "\n\n".join(paragraphs)
    return NormalizeResponse(
        normalized=normalized,
        paragraphs=paragraphs,
        paragraph_count=len(paragraphs),
    )