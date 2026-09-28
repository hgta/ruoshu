"""W1 零宽字符水印（MVP 唯一水印方案）。

编码方案：
  - 候选零宽字符：U+200B（零宽空格）, U+200C（零宽不连字）, U+200D（零宽连字）
    → 每字符承载 1.585 bit（log2(3)）

  - payload 结构（从高到位）：
      [version:4][user_id:16][chapter_id:16][ecc:8][reserved:8] = 52 bits
      → ceil(52 / log2(3)) = 33 个零宽字符

  - 锚点：选择章节中前 33 个标点符号（逗号/句号/问号/感叹号/分号/冒号/顿号/省略号）作为插入位
    若标点不足 33 则降级到段尾/文末
"""
from __future__ import annotations

import hashlib
import re
from typing import Iterable
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

router = APIRouter(prefix="/v1/watermark", tags=["watermark"])

# 三个零宽字符：0, 1, 2（三进制）
_ZW_CHARS = ("\u200b", "\u200c", "\u200d")

# 锚点候选标点
_PUNCT_RE = re.compile(r"[，。！？；：、…]")

PAYLOAD_BITS = 52  # version(4) + user_id(16) + chapter_id(16) + ecc(8) + reserved(8)
# 52 bit 需要 ceil(52 / log2(3)) ≈ 33 个三进制字符承载
PAYLOAD_TERNARY_LEN = 33


def _ecc8(data: int) -> int:
    """轻量 8 位校验：取 data 的全部字节的 XOR 折叠到 8 位。
    不是纠错码但能检测单字节错误；MVP 足够。
    """
    s = 0
    for i in range(0, 64, 8):
        s ^= (data >> i) & 0xFF
    return s & 0xFF


def _build_payload(user_id: int, chapter_id: int, version: int = 1) -> int:
    if not (0 <= user_id < (1 << 16)):
        raise ValueError("user_id 超出 16 位范围")
    if not (0 <= chapter_id < (1 << 16)):
        raise ValueError("chapter_id 超出 16 位范围")
    if not (0 <= version < (1 << 4)):
        raise ValueError("version 超出 4 位范围")
    base = (version << 48) | (user_id << 32) | (chapter_id << 16)
    return base | _ecc8(base)


def _int_to_ternary(value: int, length: int) -> list[int]:
    """整数 → 固定长度的三进制列表（高位在前）。"""
    digits: list[int] = []
    for _ in range(length):
        digits.append(value % 3)
        value //= 3
    return digits[::-1]


def _ternary_to_int(digits: Iterable[int]) -> int:
    out = 0
    for d in digits:
        out = out * 3 + d
    return out


def _embed_positions(text: str, count: int) -> list[int]:
    """返回 `count` 个插入位置（按文档顺序的标点之后）。

    要求章节中至少有 `count` 个标点符号；不足时抛 ValueError。
    典型 2000-4000 字章节有 50-200+ 标点，不会触发该异常。
    """
    positions: list[int] = []
    for m in _PUNCT_RE.finditer(text):
        positions.append(m.end())
        if len(positions) >= count:
            break
    if len(positions) < count:
        raise ValueError(
            f"标点不足：需要 {count} 个用于水印，仅找到 {len(positions)}。"
            "建议章节正文 ≥ 1000 字，或降低 payload 长度。"
        )
    return positions


class PlanRequest(BaseModel):
    text: str
    user_id: int = Field(..., ge=0, lt=1 << 16)
    chapter_id: int = Field(..., ge=0, lt=1 << 16)
    version: int = Field(1, ge=0, lt=1 << 4)


class PlanResponse(BaseModel):
    payload_hex: int
    ternary: list[int]
    anchor_positions: list[int]
    zw_chars: str


@router.post("/plan", response_model=PlanResponse, summary="水印嵌入锚点规划（发布时一次性）")
def plan_route(req: PlanRequest) -> PlanResponse:
    payload = _build_payload(req.user_id, req.chapter_id, req.version)
    ternary = _int_to_ternary(payload, PAYLOAD_TERNARY_LEN)
    try:
        positions = _embed_positions(req.text, PAYLOAD_TERNARY_LEN)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    zw = "".join(_ZW_CHARS[d] for d in ternary)
    return PlanResponse(
        payload_hex=payload,
        ternary=ternary,
        anchor_positions=positions,
        zw_chars=zw,
    )


class EmbedRequest(BaseModel):
    text: str
    payload: int = Field(..., ge=0, lt=1 << 64)


class EmbedResponse(BaseModel):
    watermarked_text: str
    payload_hex: int
    inserted_count: int


@router.post("/embed", response_model=EmbedResponse, summary="按 payload 实际嵌入文本")
def embed_route(req: EmbedRequest) -> EmbedResponse:
    ternary = _int_to_ternary(req.payload, PAYLOAD_TERNARY_LEN)
    try:
        positions = _embed_positions(req.text, PAYLOAD_TERNARY_LEN)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    chars = list(req.text)
    offset = 0
    inserted = 0
    for idx, pos in enumerate(positions):
        actual = pos + offset
        if actual > len(chars):
            actual = len(chars)
        zw = _ZW_CHARS[ternary[idx]]
        chars.insert(actual, zw)
        offset += 1
        inserted += 1

    return EmbedResponse(
        watermarked_text="".join(chars),
        payload_hex=req.payload,
        inserted_count=inserted,
    )


class ExtractRequest(BaseModel):
    text: str = Field(..., description="疑似盗版文本（含零宽字符）")
    expected_chapter_id: int | None = Field(None, ge=0, lt=1 << 16)


class ExtractResponse(BaseModel):
    found_zero_width: int
    payload_hex: int | None
    user_id: int | None
    chapter_id: int | None
    version: int | None
    ecc_valid: bool | None
    confidence: str  # high / medium / low


@router.post("/extract", response_model=ExtractResponse, summary="从疑似盗版文本提取水印 payload")
def extract_route(req: ExtractRequest) -> ExtractResponse:
    # 仅保留零宽字符，按出现顺序
    zw_seq = [c for c in req.text if c in _ZW_CHARS]
    found = len(zw_seq)

    if found < PAYLOAD_TERNARY_LEN:
        return ExtractResponse(
            found_zero_width=found,
            payload_hex=None,
            user_id=None,
            chapter_id=None,
            version=None,
            ecc_valid=None,
            confidence="low" if found == 0 else "medium",
        )

    ternary = [_ZW_CHARS.index(c) for c in zw_seq[:PAYLOAD_TERNARY_LEN]]
    payload = _ternary_to_int(ternary)

    version = (payload >> 48) & 0xF
    user_id = (payload >> 32) & 0xFFFF
    chapter_id = (payload >> 16) & 0xFFFF
    base = (version << 48) | (user_id << 32) | (chapter_id << 16)
    ecc_valid = _ecc8(base) == (payload & 0xFF)

    confidence = "low"
    if ecc_valid:
        if req.expected_chapter_id is None or req.expected_chapter_id == chapter_id:
            confidence = "high"
        else:
            confidence = "medium"

    return ExtractResponse(
        found_zero_width=found,
        payload_hex=payload,
        user_id=user_id if ecc_valid else None,
        chapter_id=chapter_id if ecc_valid else None,
        version=version if ecc_valid else None,
        ecc_valid=ecc_valid,
        confidence=confidence,
    )