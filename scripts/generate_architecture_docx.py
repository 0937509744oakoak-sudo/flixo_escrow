# -*- coding: utf-8 -*-
"""FLIXO architecture doc — emphasize original design & build contribution."""

from __future__ import annotations

import base64
import zlib
from pathlib import Path

import requests
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.shared import Cm, Inches, Pt, RGBColor

OUT_DIR = Path(__file__).resolve().parents[1] / "docs"
OUT_FILE = OUT_DIR / "FLIXO_Escrow_Architecture.docx"
IMG_DIR = OUT_DIR / "_arch_v2_diagrams"

DIAGRAMS = {
    "fig01": r"""flowchart TD
  A[ปัญหาจริงที่พบ] --> B[ความเสี่ยงซื้อขายมือสอง]
  B --> C[ออกแบบแนวทางพักเงินกลาง]
  C --> D[พัฒนาระบบ FLIXO Escrow]
  D --> E[ทดสอบและปรับปรุงต่อเนื่อง]""",
    "fig02": r"""flowchart TD
  A[เป้าหมายที่ตั้งไว้ตอนออกแบบ] --> B[ลดความเสี่ยงผู้ซื้อ]
  A --> C[คุ้มครองผู้ขาย]
  A --> D[ให้แอดมินควบคุมเงินและข้อพิพาทได้]
  B --> E[ระบบที่พัฒนาขึ้นจริง]
  C --> E
  D --> E""",
    "fig03": r"""flowchart TD
  A[ผู้ใช้] --> B[หน้าเว็บที่พัฒนา]
  B --> C[จุดรับคำสั่ง]
  C --> D[กฎธุรกิจที่ออกแบบเอง]
  D --> E[(ฐานข้อมูล)]
  D --> F[บริการภายนอกที่เชื่อมเข้ามา]
  B --> G[แชท / แจ้งเตือน]
  G --> E""",
    "fig04": r"""flowchart TD
  A[สร้างออเดอร์] --> B[รอชำระเงิน]
  B --> C[รอตรวจยอด]
  C --> D[เงินเข้าพักกลาง]
  D --> E[ส่งของแล้วรอยืนยัน]
  E --> F[สำเร็จและปล่อยเงิน]
  E --> G[ข้อพิพาท]
  B --> H[ยกเลิก]
  C --> H
  G --> F
  G --> H""",
    "fig05": r"""flowchart TD
  A[ผู้ซื้อชำระเงินสำเร็จ] --> B[เงินเข้าพักกลาง]
  B --> C{ออเดอร์จบแบบใด}
  C -->|ยืนยันรับของ| D[ปล่อยเงินผู้ขาย]
  C -->|ยกเลิกรอบเงินเข้า| E[คืนเงินผู้ซื้อ]
  C -->|ข้อพิพาท| F[ตัดสินหรือตกลง]
  F --> D
  F --> E
  F --> G[แบ่งเงิน]""",
    "fig06": r"""flowchart TD
  A[กดซื้อ] --> B[สร้างออเดอร์]
  B --> C[ชำระเงิน]
  C --> D{ตรวจยอดผ่าน?}
  D -->|ผ่าน| E[พักเงินกลาง]
  D -->|ไม่ชัวร์| F[คิวแอดมิน]
  F -->|ผ่าน| E
  E --> G[ผู้ขายส่งของ]
  G --> H{ผู้ซื้อพอใจ?}
  H -->|ใช่| I[ปล่อยเงิน]
  H -->|ไม่| J[เปิดข้อพิพาท]""",
    "fig07": r"""flowchart TD
  A[เปิดข้อพิพาท] --> B[ให้ผู้ขายชี้แจง]
  B --> C[รวบรวมหลักฐาน]
  C --> D{ตกลงกันได้?}
  D -->|ได้| E[ปิดตามข้อตกลง]
  D -->|ไม่ได้| F[แอดมินตัดสิน]
  E --> G[ปรับเงินตามผล]
  F --> G""",
    "fig08": r"""flowchart TD
  A[ข้อความเข้ามา] --> B[บันทึกลงฐานข้อมูล]
  B --> C{ช่องทางเร็วพร้อม?}
  C -->|พร้อม| D[ส่งเกือบทันที]
  C -->|ไม่พร้อม| E[ทางสำรองจากฐานข้อมูล]
  D --> F[หน้าแชทอัปเดต]
  E --> F""",
    "fig09": r"""flowchart LR
  subgraph Decide [การตัดสินใจออกแบบ]
    A1[เลือกทำเป็นระบบก้อนเดียว]
    A2[แยกเงินพักกลางกับเงินพร้อมถอน]
    A3[บังคับออเดอร์เดินทีละขั้น]
  end
  subgraph Why [เหตุผล]
    B1[เงินกับออเดอร์ต้องตรงกัน]
    B2[ผู้ใช้เข้าใจสถานะเงิน]
    B3[กันขั้นตอนกระโดดผิด]
  end
  A1 --> B1
  A2 --> B2
  A3 --> B3""",
    "fig10": r"""flowchart TD
  A[สิ่งที่พัฒนามาจริง] --> B[ตลาดซื้อขาย + พักเงินกลาง]
  A --> C[แชทเจรจาดีล]
  A --> D[กระเป๋าเงินและถอนเงิน]
  A --> E[ข้อพิพาทเป็นขั้น]
  A --> F[แผงแอดมินแยกบทบาท]
  A --> G[เชื่อมตรวจสลิป / ชำระเงิน / AI]""",
    "fig11": r"""flowchart LR
  subgraph Now [สถานะปัจจุบันของงานที่สร้าง]
    A1[แกนออเดอร์และเงินใช้งานได้]
    A2[แชทและข้อพิพาทผูกธุรกิจแล้ว]
  end
  subgraph Next [แผนพัฒนาต่อจากของที่สร้าง]
    B1[แยกงานกินแรงออกจากแกนเงิน]
    B2[เตรียมขยายหลายเครื่อง]
  end
  A1 --> B1
  A2 --> B2""",
}


def set_run_font(run, size=12, bold=False, color=None):
    run.font.name = "TH Sarabun New"
    run._element.rPr.rFonts.set(qn("w:eastAsia"), "TH Sarabun New")
    run.font.size = Pt(size)
    run.bold = bold
    if color is not None:
        run.font.color.rgb = color


def add_paragraph(doc, text, *, size=14, bold=False, align="left", space_after=8, first_line_indent=False):
    p = doc.add_paragraph()
    if align == "center":
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    elif align == "justify":
        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
    pf = p.paragraph_format
    pf.space_after = Pt(space_after)
    pf.line_spacing_rule = WD_LINE_SPACING.ONE_POINT_FIVE
    if first_line_indent:
        pf.first_line_indent = Cm(1)
    run = p.add_run(text)
    set_run_font(run, size=size, bold=bold)
    return p


def add_heading_th(doc, text, level=1):
    sizes = {1: 18, 2: 16, 3: 15}
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(14 if level == 1 else 10)
    p.paragraph_format.space_after = Pt(8)
    run = p.add_run(text)
    set_run_font(run, size=sizes.get(level, 14), bold=True, color=RGBColor(0x1E, 0x3A, 0x5F))


def add_bullets(doc, items):
    for item in items:
        p = doc.add_paragraph(style="List Bullet")
        p.paragraph_format.space_after = Pt(4)
        p.paragraph_format.line_spacing_rule = WD_LINE_SPACING.ONE_POINT_FIVE
        set_run_font(p.add_run(item), size=14)


def add_numbered(doc, items):
    for item in items:
        p = doc.add_paragraph(style="List Number")
        p.paragraph_format.space_after = Pt(4)
        p.paragraph_format.line_spacing_rule = WD_LINE_SPACING.ONE_POINT_FIVE
        set_run_font(p.add_run(item), size=14)


def add_table(doc, headers, rows):
    table = doc.add_table(rows=1 + len(rows), cols=len(headers))
    table.style = "Table Grid"
    for i, h in enumerate(headers):
        cell = table.rows[0].cells[i]
        cell.text = ""
        set_run_font(cell.paragraphs[0].add_run(h), size=13, bold=True)
    for r_idx, row in enumerate(rows):
        for c_idx, val in enumerate(row):
            cell = table.rows[r_idx + 1].cells[c_idx]
            cell.text = ""
            set_run_font(cell.paragraphs[0].add_run(str(val)), size=13)
    doc.add_paragraph()


def kroki_png(src: str) -> bytes:
    enc = base64.urlsafe_b64encode(zlib.compress(src.encode("utf-8"), 9)).decode("ascii")
    r = requests.get(f"https://kroki.io/mermaid/png/{enc}", timeout=60)
    r.raise_for_status()
    if not r.content.startswith(b"\x89PNG"):
        raise RuntimeError("bad png")
    return r.content


def mermaid_ink_png(src: str) -> bytes:
    enc = base64.urlsafe_b64encode(src.encode("utf-8")).decode("ascii")
    r = requests.get(f"https://mermaid.ink/img/{enc}?type=png&bgColor=white", timeout=60)
    r.raise_for_status()
    if not r.content.startswith(b"\x89PNG"):
        raise RuntimeError("bad png")
    return r.content


def render_diagram(key: str, src: str) -> Path:
    IMG_DIR.mkdir(parents=True, exist_ok=True)
    out = IMG_DIR / f"{key}.png"
    errors = []
    for fn in (kroki_png, mermaid_ink_png):
        try:
            out.write_bytes(fn(src))
            print(f"rendered {key}")
            return out
        except Exception as e:  # noqa: BLE001
            errors.append(str(e))
    raise RuntimeError(f"{key}: " + " | ".join(errors))


def add_figure(doc, key: str, caption: str, width=6.2):
    path = render_diagram(key, DIAGRAMS[key])
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.add_run().add_picture(str(path), width=Inches(width))
    cap = doc.add_paragraph()
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cap.paragraph_format.space_after = Pt(12)
    set_run_font(cap.add_run(caption), size=12, bold=True, color=RGBColor(0x33, 0x33, 0x33))


def setup_doc() -> Document:
    doc = Document()
    s = doc.sections[0]
    s.top_margin = s.bottom_margin = s.left_margin = s.right_margin = Cm(2.54)
    style = doc.styles["Normal"]
    style.font.name = "TH Sarabun New"
    style.font.size = Pt(14)
    style._element.rPr.rFonts.set(qn("w:eastAsia"), "TH Sarabun New")
    return doc


def build():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    if IMG_DIR.exists():
        for f in IMG_DIR.glob("*.png"):
            f.unlink()

    doc = setup_doc()

    add_paragraph(doc, "สถาปัตยกรรมระบบ FLIXO Escrow", size=22, bold=True, align="center", space_after=4)
    add_paragraph(
        doc,
        "เอกสารอธิบายสิ่งที่ออกแบบและพัฒนามาเอง — ไม่ใช่คู่มือของสำเร็จรูป",
        size=14,
        bold=True,
        align="center",
        space_after=6,
    )
    add_paragraph(
        doc,
        "เน้นปัญหาที่พบ การตัดสินใจออกแบบ ส่วนที่สร้างขึ้นจริง และเหตุผลของโครงสร้างระบบ",
        size=14,
        align="center",
        space_after=18,
    )

    # 0 contribution framing
    add_heading_th(doc, "0. งานนี้สร้างอะไรขึ้นมา", 1)
    add_paragraph(
        doc,
        "FLIXO Escrow ไม่ใช่ระบบสำเร็จรูปที่นำมาอธิบายเฉย ๆ แต่เป็นระบบที่ถูกออกแบบและพัฒนาขึ้นเพื่อแก้ปัญหาการซื้อขายของมือสองออนไลน์ โดยมีเป้าหมายชัดเจนว่าต้องมีคนกลางถือเงินให้ก่อน เพื่อลดความเสี่ยงทั้งฝั่งผู้ซื้อและผู้ขาย",
        align="justify",
        first_line_indent=True,
    )
    add_paragraph(doc, "สิ่งที่เป็นผลงานจากการพัฒนา มีอย่างน้อยดังนี้:", align="justify")
    add_bullets(
        doc,
        [
            "ออกแบบและสร้างระบบตลาดกลางพักเงิน (Escrow) ทั้งฝั่งผู้ซื้อ ผู้ขาย และแอดมิน",
            "ออกแบบลำดับสถานะออเดอร์ และการขยับเงินให้สัมพันธ์กัน",
            "สร้างกระเป๋าเงิน แยกเงินพักกลางกับเงินพร้อมถอน รวมถึงการถอนเงิน",
            "สร้างแชทที่ผูกกับธุรกิจ เช่น เสนอดีล แจ้งพัสดุ เปิดทางไปจ่ายเงิน",
            "สร้างระบบข้อพิพาทแบบเป็นขั้น ไม่จบด้วยปุ่มเดียว",
            "สร้างแผงแอดมินแยกบทบาท เช่น การเงิน ซัพพอร์ต ตรวจเนื้อหา",
            "เชื่อมบริการภายนอกที่จำเป็น เช่น ตรวจสลิป ชำระเงินออนไลน์ การแจ้งเตือน และ AI ช่วยตรวจในบางจุด",
        ],
    )
    add_paragraph(
        doc,
        "เอกสารฉบับนี้จึงอธิบายสถาปัตยกรรมในฐานะผลลัพธ์จากการออกแบบและพัฒนา ไม่ใช่การพรรณนาผลิตภัณฑ์สำเร็จรูปที่ไม่ได้มีส่วนร่วมในการสร้าง",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig01", "ภาพที่ 1 จากปัญหา → การออกแบบ → การพัฒนาระบบ")
    add_figure(doc, "fig10", "ภาพที่ 2 สิ่งที่พัฒนามาจริงในระบบ")

    # 1 problem
    add_heading_th(doc, "1. ปัญหาที่นำไปสู่การออกแบบระบบ", 1)
    add_paragraph(
        doc,
        "จุดเริ่มต้นของงานนี้ไม่ได้เริ่มจากเทคโนโลยี แต่เริ่มจากปัญหาการใช้งานจริงของการซื้อขายมือสองออนไลน์ ซึ่งมักมีสองความเสี่ยงหลัก:",
        align="justify",
        first_line_indent=True,
    )
    add_numbered(
        doc,
        [
            "ผู้ซื้อจ่ายเงินแล้ว ไม่ได้รับของ หรือของไม่ตรงปก",
            "ผู้ขายส่งของแล้ว ไม่ได้รับเงิน",
        ],
    )
    add_paragraph(
        doc,
        "จากปัญหานี้ จึงตั้งเป้าหมายออกแบบว่าระบบต้องทำหน้าที่เป็นคนกลางถือเงิน ไม่ให้เงินวิ่งตรงจากผู้ซื้อไปผู้ขายทันที และต้องมีทางออกเมื่อเกิดข้อพิพาทได้",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig02", "ภาพที่ 3 เป้าหมายที่ตั้งไว้ตอนออกแบบ")

    # 2 decisions
    add_heading_th(doc, "2. การตัดสินใจออกแบบที่สำคัญ", 1)
    add_paragraph(
        doc,
        "สถาปัตยกรรมของ FLIXO เป็นผลจากการตัดสินใจหลายข้อ ไม่ได้เกิดขึ้นเองจากของสำเร็จรูป ข้อตัดสินใจหลักมีดังนี้",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "2.1 ทำเป็นระบบก้อนเดียว", 2)
    add_paragraph(
        doc,
        "เลือกพัฒนาแบบระบบก้อนเดียว เพราะแกนธุรกิจคือเงินกับออเดอร์ต้องตรงกันเสมอ ถ้าแยกเป็นบริการย่อยหลายตัวเร็วเกินไป จะเสี่ยงสถานะออเดอร์อัปเดตแล้ว แต่เงินไม่ขยับ หรือกลับกัน การเก็บแกนออเดอร์ เงิน และข้อพิพาทไว้ด้วยกันจึงเป็นทางเลือกที่ตั้งใจไว้ตั้งแต่ต้น",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "2.2 บังคับให้ออเดอร์เดินทีละขั้น", 2)
    add_paragraph(
        doc,
        "ออกแบบให้สถานะออเดอร์ไม่กระโดดมั่ว เช่น ยังไม่จ่ายแล้วไปสำเร็จไม่ได้ ยังไม่ส่งของแล้วยืนยันรับของไม่ได้ เพื่อกันช่องโหว่เชิงขั้นตอน และทำให้การปล่อยเงินผูกกับสถานะจริง",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "2.3 แยกเงินพักกลางกับเงินพร้อมถอน", 2)
    add_paragraph(
        doc,
        "ออกแบบบัญชีเงินให้แยกอย่างน้อยสองมุม คือเงินที่ยังพักอยู่ในออเดอร์ และเงินที่ผู้ขายถอนได้แล้ว เพื่อให้ผู้ใช้เข้าใจสถานะเงินชัด และลดความสับสนว่ารายได้ที่เห็นถอนได้ทันทีหรือยัง",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "2.4 ข้อพิพาทต้องเป็นกระบวนการ", 2)
    add_paragraph(
        doc,
        "ไม่ได้ทำข้อพิพาทแบบปุ่มเดียวจบ แต่ตั้งใจให้มีช่วงให้ผู้ขายชี้แจง ส่งหลักฐาน เสนอข้อตกลง แล้วค่อยให้แอดมินตัดสินเมื่อตกลงกันไม่ได้ เพื่อลดการตัดสินเร่งร้อนและลดภาระแอดมินในช่วงแรก",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "2.5 แชทต้องเป็นส่วนหนึ่งของธุรกิจ", 2)
    add_paragraph(
        doc,
        "แชทไม่ได้ถูกออกแบบเป็นของตกแต่ง แต่เป็นช่องทางเจรจาดีล แชร์สินค้า แจ้งพัสดุ และพาไปจ่ายเงินหรือเปิดข้อพิพาท จึงต้องใกล้เวลาจริง และต้องมีทางสำรองเมื่อระบบเสริมล่ม",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig09", "ภาพที่ 4 การตัดสินใจออกแบบและเหตุผล")

    # 3 architecture
    add_heading_th(doc, "3. สถาปัตยกรรมที่สร้างขึ้น", 1)
    add_paragraph(
        doc,
        "จากเป้าหมายและการตัดสินใจข้างต้น ระบบถูกจัดโครงสร้างเป็น 5 ชั้น ดังนี้",
        align="justify",
        first_line_indent=True,
    )
    add_numbered(
        doc,
        [
            "ชั้นหน้าที่ผู้ใช้เห็น — หน้าแรก แดชบอร์ด หน้าสินค้า หน้าจ่ายเงิน",
            "ชั้นรับคำสั่ง — รับปุ่มกด เช่น ซื้อ ส่งสลิป ส่งแชท ถอนเงิน",
            "ชั้นกฎธุรกิจ — ตัดสินว่าทำได้ไหม และเงินต้องขยับอย่างไร",
            "ชั้นเก็บข้อมูล — บันทึกสถานะจริงของระบบ",
            "ชั้นเชื่อมต่อภายนอก — ตรวจสลิป ชำระเงิน AI การแจ้งเตือน คลาวด์",
        ],
    )
    add_paragraph(
        doc,
        "สรุปสั้น ๆ คือ หน้าเว็บเป็นทางเข้า จุดรับคำสั่งเป็นผู้คุมคิว กฎธุรกิจเป็นสมองตัดสิน ฐานข้อมูลเป็นความจริงหลัก และบริการภายนอกเป็นผู้ช่วยเฉพาะทางที่ถูกดึงเข้ามาเสริมงานที่สร้างเอง",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig03", "ภาพที่ 5 โครงสร้างสถาปัตยกรรมที่พัฒนามา")

    # 4 core flows built
    add_heading_th(doc, "4. กระบวนการหลักที่ออกแบบและทำให้ทำงานได้", 1)

    add_heading_th(doc, "4.1 ลำดับออเดอร์", 2)
    add_paragraph(
        doc,
        "ออเดอร์ถูกออกแบบให้เดินจากสร้างรายการ → รอชำระเงิน → ตรวจยอด → พักเงินกลาง → ส่งของ → สำเร็จหรือข้อพิพาท โดยแต่ละขั้นมีเงื่อนไขและสิทธิ์ของผู้เกี่ยวข้องกำกับอยู่",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig04", "ภาพที่ 6 ลำดับสถานะออเดอร์ที่ออกแบบไว้")

    add_heading_th(doc, "4.2 การพักเงิน ปล่อยเงิน และคืนเงิน", 2)
    add_table(
        doc,
        ["เหตุการณ์ที่ออกแบบไว้", "ผลต่อเงิน"],
        [
            ["ตรวจจ่ายผ่าน", "เข้าพักกลาง"],
            ["ผู้ซื้อยืนยันรับของ", "ปล่อยให้ผู้ขาย"],
            ["ยกเลิกหลังเงินเข้า", "คืนผู้ซื้อ"],
            ["ข้อพิพาท", "คืน / ปล่อย / แบ่ง ตามผล"],
        ],
    )
    add_figure(doc, "fig05", "ภาพที่ 7 การเคลื่อนไหวของเงินตามที่ออกแบบ")

    add_heading_th(doc, "4.3 เส้นทางซื้อของแบบพักเงินกลาง", 2)
    add_numbered(
        doc,
        [
            "ผู้ซื้อกดซื้อหรือตกลงดีล",
            "ระบบสร้างออเดอร์และแจ้งผู้ขาย",
            "ผู้ซื้อชำระเงินหรืออัปโหลดสลิป",
            "ระบบตรวจยอด แล้วพักเงินกลาง หรือส่งคิวแอดมิน",
            "ผู้ขายส่งของ",
            "ผู้ซื้อยืนยันรับของแล้วปล่อยเงิน หรือเปิดข้อพิพาท",
        ],
    )
    add_figure(doc, "fig06", "ภาพที่ 8 เส้นทางซื้อของที่พัฒนามาให้ทำงานจริง")

    add_heading_th(doc, "4.4 ข้อพิพาท", 2)
    add_paragraph(
        doc,
        "ข้อพิพาทถูกออกแบบให้มีช่วงชี้แจง ช่วงหลักฐาน ช่วงตกลงกัน และช่วงแอดมินตัดสิน เพื่อให้ระบบไม่ได้เป็นแค่ตลาดประกาศ แต่เป็นตลาดที่มีกลไกแก้ปัญหาเมื่อดีลมีปัญหา",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig07", "ภาพที่ 9 กระบวนการข้อพิพาทที่สร้างขึ้น")

    add_heading_th(doc, "4.5 แชทที่ทนทาน", 2)
    add_paragraph(
        doc,
        "แชทถูกออกแบบให้บันทึกข้อความลงฐานข้อมูลก่อนเสมอ จากนั้นค่อยใช้ช่องทางเร็วพิเศษถ้าพร้อม ถ้าไม่พร้อมยังอ่านต่อจากฐานข้อมูลได้ เพื่อไม่ให้การเจรจาดีลพังเมื่อระบบเสริมล่ม",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig08", "ภาพที่ 10 การออกแบบแชทพร้อมทางสำรอง")

    # 5 security as design
    add_heading_th(doc, "5. ความปลอดภัยในฐานะส่วนหนึ่งของการออกแบบ", 1)
    add_paragraph(
        doc,
        "เพราะระบบจัดการเงิน จึงไม่ได้ใส่ความปลอดภัยทีหลังแบบติด ๆ แต่ถูกออกแบบให้มีชั้นตรวจตั้งแต่ทางเข้า เช่น ต้องล็อกอิน คำสั่งต้องไม่ปลอม สิทธิ์ต้องตรงบทบาท ข้อมูลอ่อนไหวบางชนิดถูกปกป้อง และถ้าตั้งค่าโหมดจริงไม่ครบ ระบบจะไม่ยอมเปิดบริการ",
        align="justify",
        first_line_indent=True,
    )
    add_bullets(
        doc,
        [
            "กันคำสั่งปลอมจากภายนอก",
            "จำกัดสิทธิ์ผู้ใช้และแอดมินตามบทบาท",
            "แยกเงินพักกลางออกจากเงินถอนได้",
            "ตรวจสลิป/การชำระเงินก่อนขยับสถานะเงิน",
            "มีทางออกเมื่อเกิดข้อพิพาทหลังส่งของ",
        ],
    )

    # 6 what was hard / next
    add_heading_th(doc, "6. สิ่งที่ได้เรียนรู้จากการสร้าง และทิศทางต่อไป", 1)
    add_paragraph(
        doc,
        "จากการพัฒนาระบบจริง พบว่าส่วนที่ยากและสำคัญที่สุดคือการทำให้สถานะออเดอร์กับเงินเดินคู่กันโดยไม่เพี้ยน และการทำให้แชทกับข้อพิพาทไม่แยกจากธุรกิจหลัก ส่วนที่ยังต้องพัฒนาต่อเมื่อระบบโตขึ้น คือการแยกงานที่กินแรง เช่น แชทยาว AI และงานพื้นหลัง ออกจากแกนเงิน รวมถึงการเตรียมสถานะล็อกอินและไฟล์ให้พร้อมขยายหลายเครื่อง โดยยังคงแกนออเดอร์และเงินไว้ด้วยกัน",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig11", "ภาพที่ 11 สถานะงานที่สร้างแล้ว และแผนพัฒนาต่อ")

    add_heading_th(doc, "สรุป", 1)
    add_paragraph(
        doc,
        "FLIXO Escrow เป็นระบบที่ถูกออกแบบและพัฒนาขึ้นเพื่อแก้ปัญหาความเสี่ยงในการซื้อขายมือสอง โดยมีสถาปัตยกรรมแบบก้อนเดียวที่เน้นความถูกต้องของออเดอร์และเงินพักกลาง จุดที่แสดงถึงงานที่สร้างขึ้นเองคือกลไกสถานะออเดอร์ บัญชีเงิน แชทที่ผูกธุรกิจ ข้อพิพาทแบบเป็นขั้น และแผงแอดมิน เอกสารนี้จึงเป็นคำอธิบายสถาปัตยกรรมของงานที่พัฒนามา ไม่ใช่คำบรรยายของสำเร็จรูปที่ไม่มีส่วนร่วมในการสร้าง",
        align="justify",
        first_line_indent=True,
    )

    doc.save(OUT_FILE)
    print(f"Wrote {OUT_FILE}")


if __name__ == "__main__":
    build()
