"""月度对账单 PDF 生成（任务 10.7）。

reportlab + STSong-Light（复用 forensic 的 CID 字体方案）。
"""
from __future__ import annotations

import base64
import io
from datetime import datetime, timezone
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.cidfonts import UnicodeCIDFont
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle

router = APIRouter(prefix="/v1/statement", tags=["statement"])

_FONT = "STSong-Light"
pdfmetrics.registerFont(UnicodeCIDFont(_FONT))


class Statement(BaseModel):
    author_id: int
    author_name: str
    period: str = Field(..., description="对账周期 YYYY-MM")
    summary: dict[str, int] = Field(..., description="{entry_count, gross, fee, net}（分）")
    entries: list[dict[str, Any]] = Field(default_factory=list)


class StatementPdfResponse(BaseModel):
    pdf_base64: str


def _style(name: str, size: int, color: colors.Color = colors.black) -> ParagraphStyle:
    return ParagraphStyle(name, fontName=_FONT, fontSize=size, leading=size * 1.6,
                          textColor=color, spaceAfter=2 * mm)


def _yuan(fen: int | None) -> str:
    return f"¥{(fen or 0) / 100:,.2f}"


@router.post("/pdf", response_model=StatementPdfResponse, summary="生成月度对账单 PDF")
def statement_pdf(st: Statement) -> StatementPdfResponse:
    try:
        buf = io.BytesIO()
        doc = SimpleDocTemplate(buf, pagesize=A4, title=f"若书月度对账单 - {st.period}",
                                topMargin=20 * mm, bottomMargin=20 * mm)

        h1 = _style("h1", 18)
        h2 = _style("h2", 14)
        body = _style("body", 10.5)
        small = _style("small", 8.5, colors.grey)
        mono = _style("mono", 7.5, colors.HexColor("#333366"))

        story: list[Any] = []
        story.append(Paragraph("若书平台 作者月度对账单", h1))
        story.append(Paragraph(
            f"作者：{st.author_name}（ID {st.author_id}） · 对账周期：{st.period} · "
            f"生成时间：{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}",
            small,
        ))
        story.append(Spacer(1, 6 * mm))

        # 1. 汇总
        story.append(Paragraph("一、收入汇总", h2))
        s = st.summary
        rows = [
            ["账笔数", str(s.get("entry_count", 0))],
            ["毛额合计", _yuan(s.get("gross"))],
            ["平台抽成合计", _yuan(s.get("fee"))],
            ["作者净得合计", _yuan(s.get("net"))],
        ]
        t = Table(rows, colWidths=[40 * mm, 120 * mm])
        t.setStyle(TableStyle([
            ("FONTNAME", (0, 0), (-1, -1), _FONT),
            ("FONTSIZE", (0, 0), (-1, -1), 10),
            ("GRID", (0, 0), (-1, -1), 0.4, colors.grey),
            ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#f6f8fa")),
        ]))
        story.append(t)
        story.append(Paragraph("金额以账本 ledger_entries 为唯一真源，链式哈希可验证。", small))
        story.append(Spacer(1, 4 * mm))

        # 2. 逐笔明细（PDF 摘要性质，最多 200 笔）
        story.append(Paragraph("二、逐笔明细", h2))
        if st.entries:
            rows = [["作品", "来源", "毛额", "抽成", "净得", "账本哈希（前16位）"]]
            for e in st.entries:
                rows.append([
                    str(e.get("book", "—")),
                    str(e.get("source", "—")),
                    _yuan(e.get("gross")),
                    _yuan(e.get("fee")),
                    _yuan(e.get("net")),
                    Paragraph(str(e.get("hash", ""))[:16] + "…", mono),
                ])
            t = Table(rows, colWidths=[45 * mm, 20 * mm, 22 * mm, 22 * mm, 22 * mm, 39 * mm])
            t.setStyle(TableStyle([
                ("FONTNAME", (0, 0), (-1, -1), _FONT),
                ("FONTSIZE", (0, 0), (-1, -1), 8),
                ("GRID", (0, 0), (-1, -1), 0.4, colors.grey),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#f6f8fa")),
            ]))
            story.append(t)
        else:
            story.append(Paragraph("本周期无账目记录。", body))

        doc.build(story)
        return StatementPdfResponse(pdf_base64=base64.b64encode(buf.getvalue()).decode("ascii"))
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"对账单 PDF 生成失败: {e}") from e
