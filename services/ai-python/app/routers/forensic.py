"""取证包 PDF 生成（任务 7.5）。

用 reportlab + STSong-Light（Adobe CID 字体，阅读器内置解析，无需分发字体文件）
生成中文 PDF 取证报告：溯源结论 + 存证记录 + 段落指纹清单。
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

router = APIRouter(prefix="/v1/forensic", tags=["forensic"])

_FONT = "STSong-Light"
pdfmetrics.registerFont(UnicodeCIDFont(_FONT))


class ForensicReport(BaseModel):
    book_title: str = Field(..., description="作品名")
    book_id: int
    chapters: list[dict[str, Any]] = Field(default_factory=list, description="涉及章节 [{title, chapter_id, version, para_nos}]")
    evidence: list[dict[str, Any]] = Field(default_factory=list, description="存证记录 [{merkle_root, chain_name, tx_id, cert_no, status, confirmed_at}]")
    paragraph_hits: list[dict[str, Any]] = Field(default_factory=list, description="指纹命中 [{chapter_title, para_no, hash}]")
    watermark: dict[str, Any] | None = Field(None, description="水印提取结果 {user_id, chapter_id, ecc_valid, confidence}")
    suspect: dict[str, Any] | None = Field(None, description="嫌疑账号 {id, name}")
    confidence: str = Field("low", description="high / medium / low")
    operator_id: int = Field(..., description="发起取证的作者 user_id")
    pirate_source: str | None = Field(None, description="盗版来源链接")


class ForensicPdfResponse(BaseModel):
    pdf_base64: str
    page_count: int = 1


def _style(name: str, size: int, bold: bool = False, color: colors.Color = colors.black) -> ParagraphStyle:
    return ParagraphStyle(
        name,
        fontName=_FONT,
        fontSize=size,
        leading=size * 1.6,
        textColor=color,
        spaceAfter=2 * mm,
    )


@router.post("/pdf", response_model=ForensicPdfResponse, summary="生成取证包 PDF")
def forensic_pdf(report: ForensicReport) -> ForensicPdfResponse:
    try:
        buf = io.BytesIO()
        doc = SimpleDocTemplate(
            buf,
            pagesize=A4,
            title=f"若书版权取证报告 - {report.book_title}",
            topMargin=20 * mm,
            bottomMargin=20 * mm,
        )

        h1 = _style("h1", 18)
        h2 = _style("h2", 14)
        body = _style("body", 10.5)
        small = _style("small", 8.5, color=colors.grey)
        mono = _style("mono", 8, color=colors.HexColor("#333366"))

        story: list[Any] = []
        story.append(Paragraph("版权存证与泄露溯源取证报告", h1))
        story.append(Paragraph(
            f"生成时间：{datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')} · "
            f"平台：若书（ruoshu） · 操作人 ID：{report.operator_id}",
            small,
        ))
        story.append(Spacer(1, 6 * mm))

        # 1. 作品与结论
        story.append(Paragraph("一、作品与溯源结论", h2))
        conf_cn = {"high": "高", "medium": "中", "low": "低"}.get(report.confidence, "低")
        story.append(Paragraph(f"作品：《{report.book_title}》（ID {report.book_id}）", body))
        conf_color = colors.HexColor("#1a7f37") if report.confidence == "high" else colors.HexColor("#9a6700")
        story.append(Paragraph(f"溯源置信度：{conf_cn}", _style("conf", 12, color=conf_color)))
        if report.suspect:
            story.append(Paragraph(
                f"嫌疑账号：{report.suspect.get('name', '未知')}（ID {report.suspect.get('id')}）", body))
        else:
            story.append(Paragraph("嫌疑账号：无（指纹命中已可证明存证归属）", body))
        if report.pirate_source:
            story.append(Paragraph(f"盗版来源：{report.pirate_source}", small))
        story.append(Paragraph(
            "本报告由平台自动生成。低置信结果仅作人工比对参考，不构成处置依据。", small))
        story.append(Spacer(1, 4 * mm))

        # 2. 水印提取
        story.append(Paragraph("二、水印提取结果", h2))
        if report.watermark:
            wm = report.watermark
            rows = [
                ["检出身份标记", str(wm.get("user_id", "—"))],
                ["章节 ID", str(wm.get("chapter_id", "—"))],
                ["ECC 校验", "通过" if wm.get("ecc_valid") else "失败"],
            ]
            t = Table(rows, colWidths=[40 * mm, 120 * mm])
            t.setStyle(TableStyle([
                ("FONTNAME", (0, 0), (-1, -1), _FONT),
                ("FONTSIZE", (0, 0), (-1, -1), 10),
                ("GRID", (0, 0), (-1, -1), 0.4, colors.grey),
                ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#f6f8fa")),
            ]))
            story.append(t)
        else:
            story.append(Paragraph("未检出零宽水印（可能已被转码剥离或来源为免费章节）。", body))
        story.append(Spacer(1, 4 * mm))

        # 3. 存证记录
        story.append(Paragraph("三、链上存证记录", h2))
        if report.evidence:
            rows = [["Merkle Root", "链", "证书号/tx_id", "状态"]]
            for ev in report.evidence:
                status_cn = {2: "已上链", 1: "提交中", 0: "本地存证"}.get(ev.get("status", 0), str(ev.get("status")))
                rows.append([
                    Paragraph(str(ev.get("merkle_root", ""))[:40] + "…", mono),
                    str(ev.get("chain_name", "—")),
                    str(ev.get("cert_no") or ev.get("tx_id") or "—"),
                    status_cn,
                ])
            t = Table(rows, colWidths=[70 * mm, 18 * mm, 50 * mm, 22 * mm])
            t.setStyle(TableStyle([
                ("FONTNAME", (0, 1), (-1, -1), _FONT),
                ("FONTNAME", (0, 0), (-1, 0), _FONT),
                ("FONTSIZE", (0, 0), (-1, -1), 8.5),
                ("GRID", (0, 0), (-1, -1), 0.4, colors.grey),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#f6f8fa")),
            ]))
            story.append(t)
        else:
            story.append(Paragraph("暂无关联存证记录。", body))
        story.append(Spacer(1, 4 * mm))

        # 4. 指纹命中清单
        story.append(Paragraph("四、段落指纹命中清单", h2))
        if report.paragraph_hits:
            rows = [["章节", "段落", "SHA-256"]]
            for p in report.paragraph_hits[:30]:  # 前 30 条，PDF 摘要性质
                rows.append([
                    str(p.get("chapter_title", "—")),
                    f"第 {p.get('para_no')} 段",
                    Paragraph(str(p.get("hash", "")), mono),
                ])
            t = Table(rows, colWidths=[50 * mm, 20 * mm, 90 * mm])
            t.setStyle(TableStyle([
                ("FONTNAME", (0, 0), (-1, -1), _FONT),
                ("FONTSIZE", (0, 0), (-1, -1), 8),
                ("GRID", (0, 0), (-1, -1), 0.4, colors.grey),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#f6f8fa")),
            ]))
            story.append(t)
            if len(report.paragraph_hits) > 30:
                story.append(Paragraph(f"……共 {len(report.paragraph_hits)} 条命中，已截断", small))
        else:
            story.append(Paragraph("无指纹命中。", body))

        doc.build(story)
        data = buf.getvalue()
        return ForensicPdfResponse(
            pdf_base64=base64.b64encode(data).decode("ascii"),
            page_count=max(1, len(data) // 6000),  # 粗略页数估计，仅供显示
        )
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"PDF 生成失败: {e}") from e
