# -*- coding: utf-8 -*-
"""รายงานบรรยายเทคนิค — อธิบายระบบ FLIXO Escrow เชิงระบบ (.docx)"""

from __future__ import annotations

import base64
import subprocess
import tempfile
from pathlib import Path

import requests
from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.shared import Cm, Inches, Pt, RGBColor

OUT_DIR = Path(__file__).resolve().parents[1] / "docs"
OUT_FILE = OUT_DIR / "FLIXO_Technical_Narrative_Report.docx"
IMG_DIR = OUT_DIR / "_tech_narrative_diagrams"

CHROME_CANDIDATES = [
    Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files\Microsoft\Edge\Application\msedge.exe"),
    Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"),
]

DIAGRAMS: dict[str, str] = {
    "fig01": r"""flowchart TD
  A[ปัญหา: ความเสี่ยงซื้อขายมือสอง] --> B[ออกแบบกลไกพักเงินกลาง]
  B --> C[พัฒนาระบบ FLIXO Escrow]
  C --> D[ออเดอร์ + เงิน + ข้อพิพาท + แชท]
  D --> E[ระบบที่ใช้งานได้จริง]""",
    "fig02": r"""flowchart LR
  A[ผู้ซื้อ] --> D[ระบบคนกลางถือเงิน]
  B[ผู้ขาย] --> D
  C[ทีมแอดมิน] --> D
  D --> E[ออเดอร์]
  D --> F[เงินพักกลาง]
  D --> G[แชทเจรจา]
  D --> H[ข้อพิพาท]""",
    "fig03": r"""flowchart TD
  A[ผู้ใช้เปิดเว็บ] --> B[ชั้นนำเสนอ]
  B --> C[ชั้นรับคำสั่ง]
  C --> D[ชั้นกฎธุรกิจ]
  D --> E[ชั้นจัดเก็บข้อมูล]
  D --> F[ชั้นเชื่อมต่อภายนอก]
  B --> G[แชทและการแจ้งเตือน]
  G --> E
  F --> D""",
    "fig04": r"""flowchart LR
  A[หน้าเว็บ] -->|ส่งคำสั่ง| B[จุดรับคำสั่ง]
  B -->|ตรวจและตัดสิน| C[กฎธุรกิจ]
  C -->|บันทึก| D[(ฐานข้อมูล)]
  C -->|เรียกเมื่อจำเป็น| E[บริการภายนอก]
  E -->|ส่งผลกลับ| C
  D -->|อ่านมาแสดง| A""",
    "fig05": r"""flowchart TD
  A[สร้างออเดอร์] --> B[รอชำระเงิน]
  B --> C[รอตรวจยอด]
  C --> D[เงินเข้าพักกลาง]
  D --> E[ส่งของแล้วรอยืนยัน]
  E --> F[สำเร็จ]
  E --> G[ข้อพิพาท]
  B --> H[ยกเลิก]
  C --> H
  D --> H
  G --> F
  G --> H""",
    "fig06": r"""flowchart TD
  A[ผู้ซื้อชำระเงินสำเร็จ] --> B[เงินเข้าพักกลาง]
  B --> C{ออเดอร์จบแบบใด}
  C -->|ยืนยันรับของ| D[ปล่อยเงินให้ผู้ขาย]
  C -->|ยกเลิกหลังเงินเข้า| E[คืนเงินผู้ซื้อ]
  C -->|ข้อพิพาท| F[ตัดสินหรือตกลงกัน]
  F --> D
  F --> E
  F --> G[แบ่งเงินทั้งสองฝ่าย]
  D --> H[ผู้ขายถอนเงินได้]""",
    "fig07": r"""flowchart TD
  A[1 กดซื้อหรือตกลงดีล] --> B[2 สร้างออเดอร์]
  B --> C[3 ผู้ซื้อชำระเงิน]
  C --> D{4 ตรวจยอดผ่าน?}
  D -->|ผ่าน| E[5 พักเงินกลาง]
  D -->|ไม่แน่ใจ| F[คิวแอดมินตรวจ]
  F -->|ผ่าน| E
  F -->|ไม่ผ่าน| X[ยกเลิกหรือให้แก้ไข]
  E --> G[6 ผู้ขายส่งของ]
  G --> H{7 ผู้ซื้อพอใจ?}
  H -->|ใช่| I[8 ยืนยันรับของ]
  I --> J[ปล่อยเงินให้ผู้ขาย]
  H -->|ไม่| K[เปิดข้อพิพาท]
  K --> L[ไกล่เกลี่ยหรือแอดมินตัดสิน]""",
    "fig08": r"""flowchart TD
  A[1 เปิดข้อพิพาท] --> B[2 รอผู้ขายชี้แจง]
  B --> C{3 ชี้แจงทันเวลา?}
  C -->|ใช่| D[4 ช่วงส่งหลักฐาน]
  C -->|ไม่ทัน| D
  D --> E{5 มีข้อเสนอตกลงกัน?}
  E -->|มีและอีกฝ่ายยอมรับ| F[ปิดตามข้อตกลง]
  E -->|ไม่มีหรือไม่ยอมรับ| G[6 แอดมินตัดสิน]
  F --> H[7 ปรับเงินตามผล]
  G --> H
  H --> I[คืนเงิน / ปล่อยเงิน / แบ่งเงิน]
  I --> J[ปิดข้อพิพาท]""",
    "fig09": r"""flowchart TD
  A[ผู้ใช้พิมพ์ข้อความ] --> B[บันทึกข้อความลงฐานข้อมูล]
  B --> C{ช่องทางเร็วพร้อม?}
  C -->|พร้อม| D[ส่งถึงอีกฝ่ายเกือบทันที]
  C -->|ไม่พร้อม| E[อ่านจากฐานข้อมูลเป็นทางสำรอง]
  D --> F[หน้าแชทอัปเดต]
  E --> F""",
    "fig10": r"""flowchart TD
  A[1 ผู้ใช้ส่งคำสั่ง] --> B{2 ล็อกอินแล้ว?}
  B -->|ไม่| C[ไปหน้าเข้าสู่ระบบ]
  B -->|ใช่| D{3 บัญชีถูกระงับ?}
  D -->|ใช่| X[ปฏิเสธการใช้งาน]
  D -->|ไม่| E{4 คำสั่งถูกต้องและไม่ปลอม?}
  E -->|ไม่| X
  E -->|ใช่| F{5 มีสิทธิ์ทำรายการนี้?}
  F -->|ไม่มี| X
  F -->|มี| G[6 เข้าสู่กฎธุรกิจ]
  X --> H[จบคำสั่ง]""",
    "fig11": r"""flowchart TD
  A[เงินถูกปล่อยเข้ากระเป๋า] --> B[ผู้ขายเห็นยอดพร้อมถอน]
  B --> C[กรอกคำขอถอน]
  C --> D{ยอดพอและบัญชีถูกต้อง?}
  D -->|ไม่| E[ปฏิเสธ]
  D -->|ใช่| F[กันเงินไว้ก่อน]
  F --> G[แอดมินตรวจสอบ]
  G --> H{อนุมัติ?}
  H -->|ใช่| I[โอนเงินและปิดคำขอ]
  H -->|ไม่| J[คืนสถานะเงิน]""",
    "fig12": r"""flowchart TD
  A[เกิดเหตุการณ์สำคัญ] --> B[สร้างรายการแจ้งเตือน]
  B --> C[แจ้งในเว็บ]
  B --> D{เปิดอีเมลไว้?}
  D -->|ใช่| E[ส่งอีเมล]
  B --> F{เปิดแจ้งเตือนเบราว์เซอร์ไว้?}
  F -->|ใช่| G[ส่งการแจ้งเตือน]""",
    "fig13": r"""flowchart LR
  subgraph Decide [การตัดสินใจออกแบบ]
    A1[ระบบก้อนเดียว]
    A2[แยกเงินพักกลางกับเงินพร้อมถอน]
    A3[บังคับออเดอร์ทีละขั้น]
    A4[ข้อพิพาทเป็นกระบวนการ]
  end
  subgraph Why [เหตุผล]
    B1[เงินกับออเดอร์ต้องตรงกัน]
    B2[ผู้ใช้เข้าใจสถานะเงิน]
    B3[กันขั้นตอนกระโดดผิด]
    B4[ลดการตัดสินเร่งร้อน]
  end
  A1 --> B1
  A2 --> B2
  A3 --> B3
  A4 --> B4""",
    "fig14": r"""flowchart TD
  A[ผู้ขายกรอกสินค้า] --> B[อัปโหลดรูป]
  B --> C{ต้องยืนยันตัวตนก่อน?}
  C -->|ต้องและยังไม่ผ่าน| D[ไปยืนยันตัวตน]
  C -->|ผ่านแล้ว| E[ตรวจความเสี่ยงของรูป]
  E --> F{ผ่านเกณฑ์?}
  F -->|ไม่| G[ปฏิเสธหรือให้แก้ไข]
  F -->|ใช่| H[สร้างประกาศและแสดงในตลาด]""",
    "fig15": r"""flowchart TD
  A[แอดมินเข้าหลังบ้าน] --> B{บทบาทอะไร}
  B -->|การเงิน| C[ตรวจสลิป / อนุมัติถอน]
  B -->|ซัพพอร์ต| D[แชทช่วยเหลือ / ข้อพิพาท]
  B -->|ตรวจเนื้อหา| E[สินค้า / แชท / ตัวตน]
  B -->|เจ้าของระบบ| F[เข้าถึงทุกอย่าง + มอบสิทธิ์]""",
    "fig16": r"""sequenceDiagram
  actor Buyer as ผู้ซื้อ
  participant Web as หน้าเว็บ
  participant Sys as ระบบ
  participant DB as ฐานข้อมูล
  participant Pay as ตรวจจ่ายเงิน
  Buyer->>Web: กดซื้อสินค้า
  Web->>Sys: สร้างออเดอร์
  Sys->>DB: บันทึกรอชำระเงิน
  Buyer->>Web: ส่งหลักฐานชำระเงิน
  Web->>Sys: ขอตรวจยอด
  Sys->>Pay: ตรวจสลิปหรือรายการชำระ
  alt ยอดถูกต้อง
    Sys->>DB: พักเงินกลาง
  else ไม่แน่ใจ
    Sys->>DB: ส่งคิวแอดมิน
  end
  Sys-->>Web: แสดงผลลัพธ์""",
    "fig17": r"""flowchart TD
  A[สรุปเชิงระบบ] --> B[แกน: ออเดอร์ + เงินพักกลาง]
  A --> C[เสริม: แชท ข้อพิพาท แจ้งเตือน]
  A --> D[ควบคุม: สิทธิ์ ความปลอดภัย]
  B --> E[ลดความเสี่ยงซื้อขายมือสอง]
  C --> E
  D --> E""",
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
    # Avoid Word JUSTIFY with Thai+English: it stretches the few Latin spaces
    # (e.g. around "FLIXO Escrow") into huge gaps.
    if align == "center":
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    else:
        p.alignment = WD_ALIGN_PARAGRAPH.LEFT
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


def find_chrome() -> Path:
    for p in CHROME_CANDIDATES:
        if p.exists():
            return p
    raise RuntimeError("ไม่พบ Chrome/Edge สำหรับเรนเดอร์แผนภาพภาษาไทย")


def _crop_whitespace(png_bytes: bytes, pad: int = 16) -> bytes:
    from PIL import Image
    import io

    im = Image.open(io.BytesIO(png_bytes)).convert("RGB")
    # Treat near-white as background
    pixels = im.load()
    w, h = im.size
    left, top, right, bottom = w, h, 0, 0
    found = False
    for y in range(h):
        for x in range(w):
            r, g, b = pixels[x, y]
            if r < 250 or g < 250 or b < 250:
                found = True
                if x < left:
                    left = x
                if y < top:
                    top = y
                if x > right:
                    right = x
                if y > bottom:
                    bottom = y
    if not found:
        return png_bytes
    left = max(0, left - pad)
    top = max(0, top - pad)
    right = min(w - 1, right + pad)
    bottom = min(h - 1, bottom + pad)
    cropped = im.crop((left, top, right + 1, bottom + 1))
    buf = io.BytesIO()
    cropped.save(buf, format="PNG")
    return buf.getvalue()


def local_chrome_mermaid_png(src: str) -> bytes:
    """Render Mermaid with local Chrome + Thai-capable fonts (avoids Kroki tofu glyphs)."""
    chrome = find_chrome()
    safe = (
        src.replace("&", "&amp;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )
    html = f"""<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8"/>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@400;600;700&display=swap" rel="stylesheet"/>
<style>
  html, body {{
    margin: 0;
    padding: 32px;
    background: #ffffff;
    font-family: "Noto Sans Thai", "TH Sarabun New", Tahoma, sans-serif;
  }}
  .mermaid svg {{
    max-width: none !important;
  }}
</style>
</head>
<body>
<pre class="mermaid">{safe}</pre>
<script type="module">
  import mermaid from "https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.esm.min.mjs";
  mermaid.initialize({{
    startOnLoad: false,
    securityLevel: "loose",
    theme: "default",
    fontFamily: "Noto Sans Thai, TH Sarabun New, Tahoma, sans-serif",
    themeVariables: {{
      fontFamily: "Noto Sans Thai, TH Sarabun New, Tahoma, sans-serif"
    }}
  }});
  await document.fonts.ready;
  await mermaid.run();
  document.body.dataset.ready = "1";
</script>
</body>
</html>"""

    with tempfile.TemporaryDirectory(prefix="flixo_mmd_") as tmp:
        tmp_path = Path(tmp)
        html_path = tmp_path / "diagram.html"
        shot_path = tmp_path / "shot.png"
        html_path.write_text(html, encoding="utf-8")

        # virtual-time-budget advances timers so fonts/CDN scripts can finish
        subprocess.run(
            [
                str(chrome),
                "--headless=new",
                "--disable-gpu",
                "--hide-scrollbars",
                "--force-device-scale-factor=1.25",
                "--window-size=1400,5000",
                "--virtual-time-budget=15000",
                f"--screenshot={shot_path}",
                html_path.resolve().as_uri(),
            ],
            check=True,
            timeout=90,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        if not shot_path.exists():
            raise RuntimeError("chrome did not write screenshot")
        data = shot_path.read_bytes()
        if not data.startswith(b"\x89PNG"):
            raise RuntimeError("chrome screenshot not png")
        return _crop_whitespace(data)


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
    if out.exists() and out.stat().st_size > 5000:
        print(f"reuse {key} ({out.stat().st_size} bytes)")
        return out
    errors = []
    # Prefer local Chrome (Thai fonts). Avoid Kroki — it returns PNG but without Thai glyphs.
    for fn in (local_chrome_mermaid_png, mermaid_ink_png):
        try:
            data = fn(src)
            out.write_bytes(data)
            print(f"rendered {key} via {fn.__name__} ({len(data)} bytes)")
            return out
        except Exception as e:  # noqa: BLE001
            errors.append(f"{fn.__name__}: {e}")
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
    # Keep existing locally-rendered Thai diagrams; delete only tiny/broken leftovers.
    if IMG_DIR.exists():
        for f in IMG_DIR.glob("*_test.png"):
            f.unlink()

    doc = setup_doc()

    # Cover
    add_paragraph(doc, "รายงานบรรยายเทคนิค", size=18, bold=True, align="center", space_after=4)
    add_paragraph(doc, "ระบบ FLIXO Escrow", size=22, bold=True, align="center", space_after=8)
    add_paragraph(
        doc,
        "อธิบายเชิงระบบ — จากปัญหา การออกแบบ โครงสร้าง และการทำงานของกระบวนการหลัก",
        size=14,
        align="center",
        space_after=12,
    )
    add_paragraph(
        doc,
        "เอกสารนี้อธิบายสิ่งที่ออกแบบและพัฒนามาเอง ไม่ใช่คำบรรยายของสำเร็จรูป",
        size=14,
        bold=True,
        align="center",
        space_after=24,
    )

    # 1
    add_heading_th(doc, "1. บทนำ: ระบบนี้ถูกสร้างขึ้นเพื่ออะไร", 1)
    add_paragraph(
        doc,
        "FLIXO Escrow เป็นเว็บซื้อขายของมือสองที่ถูกออกแบบและพัฒนาขึ้นเพื่อลดความเสี่ยงหลักสองอย่างของการซื้อขายออนไลน์ คือ ผู้ซื้อจ่ายแล้วไม่ได้ของ และผู้ขายส่งของแล้วไม่ได้เงิน จุดกลางของระบบจึงไม่ใช่แค่หน้ารายการสินค้า แต่คือกลไกพักเงินกลาง (Escrow) ที่ทำหน้าที่เป็นคนกลางถือเงินระหว่างทำธุรกรรม",
        align="justify",
        first_line_indent=True,
    )
    add_paragraph(
        doc,
        "รายงานฉบับนี้อธิบายระบบในมุมเทคนิคเชิงระบบ เริ่มจากปัญหาและการตัดสินใจออกแบบ จากนั้นอธิบายโครงสร้างชั้นงาน กระบวนการหลัก การไหลของเงิน ความปลอดภัย และบทบาทของผู้เกี่ยวข้อง โดยใช้ภาษาที่อ่านตามได้ง่าย แต่ยังคงความถูกต้องของแนวคิดทางเทคนิค",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig01", "ภาพที่ 1 จากปัญหาสู่ระบบที่พัฒนาขึ้น")

    add_paragraph(doc, "สิ่งที่เป็นผลงานจากการออกแบบและพัฒนา มีอย่างน้อยดังนี้:", align="justify")
    add_bullets(
        doc,
        [
            "ระบบตลาดกลางพักเงิน ทั้งฝั่งผู้ซื้อ ผู้ขาย และแอดมิน",
            "ลำดับสถานะออเดอร์ที่บังคับเดินทีละขั้น และผูกกับการขยับเงิน",
            "กระเป๋าเงินที่แยกเงินพักกลางออกจากเงินพร้อมถอน",
            "แชทที่ผูกกับธุรกิจ เช่น เสนอดีล แจ้งพัสดุ และพาไปจ่ายเงิน",
            "ระบบข้อพิพาทแบบเป็นขั้น ไม่จบด้วยปุ่มเดียว",
            "แผงแอดมินแยกบทบาท เช่น การเงิน ซัพพอร์ต และตรวจเนื้อหา",
            "การเชื่อมบริการภายนอกเท่าที่จำเป็น เช่น ตรวจสลิป ชำระเงินออนไลน์ การแจ้งเตือน และ AI ช่วยตรวจบางจุด",
        ],
    )

    # 2
    add_heading_th(doc, "2. แนวคิดหลักของระบบ", 1)
    add_heading_th(doc, "2.1 คนกลางถือเงิน", 2)
    add_paragraph(
        doc,
        "หลักการทั้งระบบสรุปได้สั้น ๆ ว่า เงินของผู้ซื้อจะพักไว้ตรงกลางก่อน ผู้ขายถอนไม่ได้ จนกว่าผู้ซื้อจะยืนยันว่าได้รับของแล้ว หรือมีผลจากข้อพิพาทที่ชัดเจน การออกแบบนี้ทำให้สถานะออเดอร์กับสถานะเงินต้องเดินคู่กันเสมอ",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig02", "ภาพที่ 2 ผู้มีส่วนร่วมและแกนของระบบ")

    add_heading_th(doc, "2.2 การตัดสินใจออกแบบที่สำคัญ", 2)
    add_paragraph(
        doc,
        "สถาปัตยกรรมของ FLIXO ไม่ได้เกิดจากของสำเร็จรูป แต่เกิดจากการเลือกทางออกแบบหลายข้อ ดังนี้",
        align="justify",
        first_line_indent=True,
    )
    add_numbered(
        doc,
        [
            "ทำเป็นระบบก้อนเดียว เพราะออเดอร์ เงิน และข้อพิพาทต้องตรงกันเสมอ",
            "บังคับให้ออเดอร์เดินทีละขั้น เพื่อกันขั้นตอนกระโดดผิด เช่น ยังไม่จ่ายแล้วไปสำเร็จไม่ได้",
            "แยกเงินพักกลางกับเงินพร้อมถอน เพื่อให้ผู้ใช้เข้าใจสถานะเงินชัด",
            "ทำข้อพิพาทเป็นกระบวนการ มีช่วงชี้แจง หลักฐาน ตกลงกัน และแอดมินตัดสิน",
            "ทำให้แชทเป็นส่วนหนึ่งของธุรกิจ ไม่ใช่ของตกแต่ง",
        ],
    )
    add_figure(doc, "fig13", "ภาพที่ 3 การตัดสินใจออกแบบและเหตุผล")

    # 3
    add_heading_th(doc, "3. สถาปัตยกรรมเชิงระบบ", 1)
    add_heading_th(doc, "3.1 ลักษณะโดยรวม", 2)
    add_paragraph(
        doc,
        "ระบบถูกจัดเป็นระบบก้อนเดียวแบบแบ่งชั้นงาน ส่วนหลักอยู่ด้วยกันในโปรเจกต์เดียว และถูกนำไปใช้งานเป็นหน่วยเดียว เหตุผลสำคัญคือโดเมนธุรกิจต้องการความถูกต้องสูงระหว่างสถานะออเดอร์ ยอดเงิน และการตัดสินข้อพิพาท หากแยกเป็นบริการย่อยจำนวนมากเร็วเกินไป จะเพิ่มความเสี่ยงที่ข้อมูลเงินกับออเดอร์ไม่ตรงกัน",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "3.2 ชั้นงานหลัก", 2)
    add_numbered(
        doc,
        [
            "ชั้นนำเสนอ — หน้าที่ผู้ใช้เห็น เช่น หน้าแรก แดชบอร์ด หน้าสินค้า หน้าชำระเงิน",
            "ชั้นรับคำสั่ง — รับการกระทำจากผู้ใช้ เช่น กดซื้อ ส่งสลิป ส่งแชท ถอนเงิน",
            "ชั้นกฎธุรกิจ — ตัดสินว่าทำได้ไหม และเงินต้องขยับอย่างไร",
            "ชั้นจัดเก็บข้อมูล — บันทึกสถานะจริงของระบบลงฐานข้อมูล",
            "ชั้นเชื่อมต่อภายนอก — ตรวจสลิป ชำระเงิน AI การแจ้งเตือน และที่เก็บไฟล์บนคลาวด์",
        ],
    )
    add_figure(doc, "fig03", "ภาพที่ 4 ชั้นงานหลักของระบบ")

    add_heading_th(doc, "3.3 ความสัมพันธ์ระหว่างส่วนประกอบ", 2)
    add_paragraph(
        doc,
        "หน้าเว็บเป็นทางเข้า จุดรับคำสั่งเป็นผู้คุมคิว กฎธุรกิจเป็นสมองตัดสิน ฐานข้อมูลเป็นความจริงหลัก และบริการภายนอกเป็นผู้ช่วยเฉพาะทางที่ถูกดึงเข้ามาเสริมงานที่สร้างเอง",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig04", "ภาพที่ 5 ความสัมพันธ์ระหว่างส่วนประกอบหลัก")

    add_table(
        doc,
        ["ชั้นงาน", "หน้าที่หลัก", "ตัวอย่างงาน"],
        [
            ["นำเสนอ", "แสดงผลและรับการโต้ตอบ", "หน้าสินค้า แดชบอร์ด แชท"],
            ["รับคำสั่ง", "ตรวจคำสั่งเบื้องต้น", "ซื้อ ส่งสลิป ถอนเงิน"],
            ["กฎธุรกิจ", "ตัดสินตามกติกา", "เปลี่ยนสถานะออเดอร์ ขยับเงิน"],
            ["จัดเก็บข้อมูล", "เก็บสถานะจริง", "ออเดอร์ กระเป๋าเงิน ข้อความ"],
            ["เชื่อมต่อภายนอก", "ช่วยงานเฉพาะทาง", "ตรวจสลิป ชำระเงิน แจ้งเตือน"],
        ],
    )

    # 4
    add_heading_th(doc, "4. กระบวนการหลักที่ออกแบบไว้", 1)

    add_heading_th(doc, "4.1 ลำดับสถานะออเดอร์", 2)
    add_paragraph(
        doc,
        "ออเดอร์ถูกออกแบบให้เดินจากสร้างรายการ ไปรอชำระเงิน รอตรวจยอด พักเงินกลาง ส่งของ แล้วจึงไปสู่สำเร็จหรือข้อพิพาท แต่ละขั้นมีเงื่อนไขและสิทธิ์ของผู้เกี่ยวข้องกำกับอยู่ การบังคับลำดับนี้คือหัวใจของความถูกต้องในระบบ เพราะการปล่อยเงินถูกผูกกับสถานะจริงของออเดอร์",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig05", "ภาพที่ 6 ลำดับสถานะออเดอร์")

    add_table(
        doc,
        ["สถานะ", "ความหมายโดยสรุป", "ใครผลักดันได้เป็นหลัก"],
        [
            ["สร้าง / รอชำระเงิน", "มีออเดอร์แล้ว แต่ยังไม่จ่าย", "ผู้ซื้อ / ผู้ขายตามบริบท"],
            ["รอตรวจยอด", "ส่งหลักฐานแล้ว รอตรวจ", "ผู้ซื้อแจ้ง / ระบบหรือแอดมินยืนยัน"],
            ["เงินเข้าพักกลาง", "เงินอยู่ในระบบแล้ว", "ระบบหลังตรวจผ่าน"],
            ["ส่งของแล้ว", "ผู้ขายแจ้งส่งของ", "ผู้ขาย"],
            ["สำเร็จ", "ผู้ซื้อยืนยันรับของ", "ผู้ซื้อ / แอดมินกรณีพิเศษ"],
            ["ข้อพิพาท", "มีปัญหาหลังส่งของ", "ผู้ซื้อเปิด / แอดมินปิดผล"],
            ["ยกเลิก", "จบโดยไม่สำเร็จ", "ผู้ซื้อหรือแอดมินตามเงื่อนไข"],
        ],
    )

    add_heading_th(doc, "4.2 การพักเงิน ปล่อยเงิน และคืนเงิน", 2)
    add_paragraph(
        doc,
        "เมื่อตรวจจ่ายผ่าน เงินจะเข้าพักกลางก่อน ไม่เข้ากระเป๋าพร้อมถอนของผู้ขายทันที เมื่อผู้ซื้อยืนยันรับของ ระบบจึงปล่อยเงินให้ผู้ขาย หากยกเลิกหลังเงินเข้า ระบบคืนเงินผู้ซื้อ และถ้าจบด้วยข้อพิพาท ผลอาจเป็นการคืน ปล่อย หรือแบ่งเงินตามผลการตัดสิน",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig06", "ภาพที่ 7 การเคลื่อนไหวของเงินในระบบ")
    add_table(
        doc,
        ["เหตุการณ์", "ผลต่อเงิน"],
        [
            ["ตรวจจ่ายผ่าน", "เข้าพักกลาง"],
            ["ผู้ซื้อยืนยันรับของ", "ปล่อยให้ผู้ขาย"],
            ["ยกเลิกหลังเงินเข้า", "คืนผู้ซื้อ"],
            ["ข้อพิพาท", "คืน / ปล่อย / แบ่ง ตามผล"],
        ],
    )

    add_heading_th(doc, "4.3 เส้นทางซื้อของแบบพักเงินกลาง", 2)
    add_numbered(
        doc,
        [
            "ผู้ซื้อกดซื้อหรือตกลงดีลผ่านแชท",
            "ระบบสร้างออเดอร์และแจ้งผู้ขาย",
            "ผู้ซื้อชำระเงินหรืออัปโหลดสลิป",
            "ระบบตรวจยอด แล้วพักเงินกลาง หรือส่งคิวแอดมินถ้ายังไม่แน่ใจ",
            "ผู้ขายส่งของและแจ้งพัสดุ",
            "ผู้ซื้อยืนยันรับของแล้วปล่อยเงิน หรือเปิดข้อพิพาท",
        ],
    )
    add_figure(doc, "fig07", "ภาพที่ 8 เส้นทางซื้อของทั้งรอบ")
    add_figure(doc, "fig16", "ภาพที่ 9 ลำดับเหตุการณ์ตั้งแต่กดซื้อถึงพักเงินกลาง")

    add_heading_th(doc, "4.4 ข้อพิพาทแบบเป็นขั้น", 2)
    add_paragraph(
        doc,
        "ข้อพิพาทไม่ได้ถูกออกแบบเป็นปุ่มเดียวจบ แต่มีช่วงให้ผู้ขายชี้แจง ช่วงส่งหลักฐาน ช่วงเสนอข้อตกลง และช่วงแอดมินตัดสินเมื่อตกลงกันไม่ได้ การออกแบบนี้ช่วยลดการตัดสินเร่งร้อน และทำให้ระบบมีกลไกแก้ปัญหาเมื่อดีลมีปัญหาจริง",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig08", "ภาพที่ 10 กระบวนการข้อพิพาท")

    add_heading_th(doc, "4.5 แชทที่ผูกกับธุรกิจและทนทานต่อความล่ม", 2)
    add_paragraph(
        doc,
        "แชทในระบบไม่ได้มีไว้คุยเล่นอย่างเดียว แต่เป็นช่องทางเจรจาดีล แชร์สินค้า แจ้งพัสดุ และพาไปจ่ายเงินหรือเปิดข้อพิพาท ทางเทคนิคจึงออกแบบให้บันทึกข้อความลงฐานข้อมูลก่อนเสมอ จากนั้นค่อยใช้ช่องทางเร็วพิเศษถ้าพร้อม ถ้าไม่พร้อมยังอ่านต่อจากฐานข้อมูลได้ เพื่อไม่ให้การเจรจาดีลพังเมื่อระบบเสริมล่ม",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig09", "ภาพที่ 11 การออกแบบแชทพร้อมทางสำรอง")

    add_heading_th(doc, "4.6 การถอนเงินของผู้ขาย", 2)
    add_paragraph(
        doc,
        "เมื่อเงินถูกปล่อยจากพักกลางเข้ากระเป๋าพร้อมถอน ผู้ขายจึงขอถอนได้ ระบบจะกันเงินไว้ก่อน แล้วส่งคำขอให้แอดมินฝั่งการเงินตรวจ ถ้าอนุมัติจึงโอนออก ถ้าปฏิเสธจะคืนสถานะเงินและแจ้งเหตุผล",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig11", "ภาพที่ 12 กระบวนการถอนเงิน")

    add_heading_th(doc, "4.7 การลงประกาศสินค้า", 2)
    add_paragraph(
        doc,
        "การขายสินค้ามีเกณฑ์ควบคุมเพิ่ม เช่น ในบางเงื่อนไขต้องยืนยันตัวตนก่อน และมีการตรวจความเสี่ยงของรูปหรือข้อมูลก่อนประกาศขึ้นตลาด เพื่อลดประกาศที่มีแนวโน้มหลอกลวง",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig14", "ภาพที่ 13 เส้นทางการลงประกาศสินค้า")

    # 5
    add_heading_th(doc, "5. ผู้มีส่วนร่วมและสิทธิ์ในระบบ", 1)
    add_paragraph(
        doc,
        "ระบบแยกบทบาทชัด เพื่อให้แต่ละกลุ่มทำได้เฉพาะงานที่จำเป็น และลดความเสี่ยงจากการใช้สิทธิ์เกินหน้าที่",
        align="justify",
        first_line_indent=True,
    )
    add_table(
        doc,
        ["กลุ่ม", "บทบาทหลัก"],
        [
            ["ผู้ซื้อ", "สั่งซื้อ ชำระเงิน ยืนยันรับของ เปิดข้อพิพาท"],
            ["ผู้ขาย", "ลงประกาศ ส่งของ รับเงิน ชี้แจงข้อพิพาท"],
            ["แอดมินการเงิน", "ตรวจสลิป อนุมัติถอนเงิน"],
            ["แอดมินซัพพอร์ต", "ดูแลแชทช่วยเหลือและข้อพิพาท"],
            ["แอดมินตรวจเนื้อหา", "ตรวจสินค้า ข้อความ และตัวตน"],
            ["เจ้าของระบบ", "ดูแลภาพรวมและมอบสิทธิ์ทีมงาน"],
        ],
    )
    add_figure(doc, "fig15", "ภาพที่ 14 การแยกบทบาทของทีมแอดมิน")

    # 6
    add_heading_th(doc, "6. ความปลอดภัยและการควบคุมทางเข้า", 1)
    add_paragraph(
        doc,
        "เพราะระบบจัดการเงิน ความปลอดภัยจึงถูกออกแบบเป็นส่วนหนึ่งของทางเข้า ไม่ใช่ของติดทีหลัง ทุกคำสั่งสำคัญควรถูกตรวจว่าล็อกอินแล้วหรือยัง บัญชีถูกระงับหรือไม่ คำสั่งปลอมหรือไม่ และผู้ใช้มีสิทธิ์ทำรายการนั้นหรือไม่",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig10", "ภาพที่ 15 ชั้นตรวจก่อนเข้าสู่กฎธุรกิจ")
    add_bullets(
        doc,
        [
            "กันคำสั่งปลอมจากภายนอก",
            "จำกัดสิทธิ์ผู้ใช้และแอดมินตามบทบาท",
            "แยกเงินพักกลางออกจากเงินถอนได้",
            "ตรวจสลิปหรือการชำระเงินก่อนขยับสถานะเงิน",
            "มีทางออกเมื่อเกิดข้อพิพาทหลังส่งของ",
            "แจ้งเตือนเหตุการณ์สำคัญผ่านหลายช่องทางตามที่ผู้ใช้เปิดไว้",
        ],
    )
    add_figure(doc, "fig12", "ภาพที่ 16 การแจ้งเตือนเมื่อเกิดเหตุการณ์สำคัญ")

    # 7
    add_heading_th(doc, "7. มุมมองเชิงระบบโดยสรุป", 1)
    add_paragraph(
        doc,
        "เมื่อมองทั้งระบบในภาพเดียว FLIXO มีแกนอยู่ที่ออเดอร์และเงินพักกลาง ส่วนแชท ข้อพิพาท การแจ้งเตือน และแผงแอดมินเป็นชั้นเสริมที่ทำให้ธุรกิจเดินได้จริง และมีชั้นควบคุมสิทธิ์กับความปลอดภัยคอยกันช่องโหว่เชิงขั้นตอน",
        align="justify",
        first_line_indent=True,
    )
    add_figure(doc, "fig17", "ภาพที่ 17 สรุปองค์ประกอบเชิงระบบ")

    add_paragraph(
        doc,
        "จุดที่แสดงถึงงานออกแบบและพัฒนาขึ้นเองชัดที่สุด คือการบังคับลำดับออเดอร์ การแยกบัญชีเงินพักกลางกับเงินพร้อมถอน การทำให้แชทเป็นส่วนหนึ่งของดีล และการทำข้อพิพาทเป็นกระบวนการหลายขั้น บริการภายนอกถูกใช้เป็นตัวช่วยเฉพาะทาง ไม่ใช่ตัวแทนของตรรกะธุรกิจหลัก",
        align="justify",
        first_line_indent=True,
    )

    add_heading_th(doc, "8. สรุป", 1)
    add_paragraph(
        doc,
        "FLIXO Escrow เป็นระบบที่ถูกออกแบบและพัฒนาขึ้นเพื่อแก้ปัญหาความเสี่ยงในการซื้อขายมือสอง โดยมีสถาปัตยกรรมแบบก้อนเดียวที่เน้นความถูกต้องของออเดอร์และเงินพักกลาง รายงานฉบับนี้อธิบายเชิงระบบตั้งแต่ปัญหา การตัดสินใจออกแบบ ชั้นงาน กระบวนการหลัก จนถึงการควบคุมสิทธิ์และความปลอดภัย เพื่อให้เห็นภาพว่าแต่ละส่วนทำงานร่วมกันอย่างไร และเหตุใดโครงสร้างนี้จึงเหมาะสมกับโดเมนที่เงินกับสถานะธุรกรรมต้องตรงกันเสมอ",
        align="justify",
        first_line_indent=True,
    )

    try:
        doc.save(OUT_FILE)
        print(f"Wrote {OUT_FILE}")
    except PermissionError:
        for name in (
            "FLIXO_Technical_Narrative_Report_fixed.docx",
            "FLIXO_Technical_Narrative_Report_v2.docx",
            "FLIXO_Technical_Narrative_Report_new.docx",
        ):
            alt = OUT_DIR / name
            try:
                doc.save(alt)
                print(f"Original file locked; wrote {alt}")
                print("Close the old .docx in Word, then use this file.")
                break
            except PermissionError:
                continue
        else:
            raise


if __name__ == "__main__":
    build()
