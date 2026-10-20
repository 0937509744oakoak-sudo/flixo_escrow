# -*- coding: utf-8 -*-
"""จัดหน้าบทที่ 1-3 เป็นไฟล์ Word รูปแบบรายงาน (TH Sarabun New, A4, 1.5 บรรทัด)."""

from __future__ import annotations

import re
import struct
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING, WD_TAB_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor, Emu

ROOT = Path(__file__).resolve().parents[1]
FONT_NAME = "TH Sarabun New"

CHAPTERS = [
    (ROOT / "docs" / "Chapter1_Introduction.md", ROOT / "docs" / "FLIXO_Chapter1_Thesis.docx", "1", "บทนำ"),
    (ROOT / "docs" / "Chapter2_Literature_Review.md", ROOT / "docs" / "FLIXO_Chapter2_Thesis.docx", "2", "ทฤษฎีและงานวิจัยที่เกี่ยวข้อง"),
    (ROOT / "docs" / "Chapter3_System_Design_and_Development.md", ROOT / "docs" / "FLIXO_Chapter3_Thesis.docx", "3", "การออกแบบและพัฒนาระบบ"),
    (ROOT / "docs" / "Chapter4_Empirical_Evaluation.md", ROOT / "docs" / "FLIXO_Chapter4_Thesis.docx", "4", "ผลการทดลองและการประเมินระบบ"),
    (ROOT / "docs" / "Chapter5_Conclusion.md", ROOT / "docs" / "FLIXO_Chapter5_Thesis.docx", "5", "สรุปผลการวิจัยและข้อเสนอแนะ"),
]
COMBINED = ROOT / "docs" / "FLIXO_Chapter1_3_Thesis.docx"
COMBINED_ALL = ROOT / "docs" / "FLIXO_Chapter1_5_Thesis.docx"


def academic_text(text: str) -> str:
    if text is None:
        return ""
    s = str(text)
    for a, b in [
        ("**", ""),
        ("`", ""),
        ("—", " "),
        ("–", "-"),
        ("…", "..."),
        ("\\(", ""),
        ("\\)", ""),
        ("\\[", ""),
        ("\\]", ""),
    ]:
        s = s.replace(a, b)
    s = re.sub(r"[ \t]{2,}", " ", s.replace("\u00a0", " "))
    return s.strip()


def set_run_font(run, size=16, bold=False, italic=False):
    run.font.name = FONT_NAME
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), FONT_NAME)
    rfonts.set(qn("w:hAnsi"), FONT_NAME)
    rfonts.set(qn("w:eastAsia"), FONT_NAME)
    rfonts.set(qn("w:cs"), FONT_NAME)
    run.font.size = Pt(size)
    run.bold = bold
    run.italic = italic
    run.font.color.rgb = RGBColor(0, 0, 0)


def set_style_font(style, size, bold=False):
    style.font.name = FONT_NAME
    style.font.size = Pt(size)
    style.font.bold = bold
    style.font.color.rgb = RGBColor(0, 0, 0)
    rpr = style._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), FONT_NAME)
    rfonts.set(qn("w:hAnsi"), FONT_NAME)
    rfonts.set(qn("w:eastAsia"), FONT_NAME)


def new_document() -> Document:
    doc = Document()
    section = doc.sections[0]
    section.page_width = Cm(21.0)
    section.page_height = Cm(29.7)
    section.top_margin = Cm(2.54)
    section.bottom_margin = Cm(2.54)
    section.left_margin = Cm(3.0)
    section.right_margin = Cm(2.54)

    set_style_font(doc.styles["Normal"], 16, False)
    doc.styles["Normal"].paragraph_format.line_spacing = 1.15
    doc.styles["Normal"].paragraph_format.space_after = Pt(6)
    doc.styles["Normal"].paragraph_format.space_before = Pt(0)
    for name, size in (("Heading 1", 18), ("Heading 2", 16), ("Heading 3", 16)):
        try:
            set_style_font(doc.styles[name], size, True)
            doc.styles[name].paragraph_format.space_before = Pt(8)
            doc.styles[name].paragraph_format.space_after = Pt(4)
            doc.styles[name].paragraph_format.line_spacing = 1.15
        except KeyError:
            pass

    footer = section.footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = footer.add_run()
    set_run_font(run, 14)
    fld1 = OxmlElement("w:fldChar")
    fld1.set(qn("w:fldCharType"), "begin")
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = " PAGE "
    fld2 = OxmlElement("w:fldChar")
    fld2.set(qn("w:fldCharType"), "end")
    run._element.append(fld1)
    run._element.append(instr)
    run._element.append(fld2)
    return doc


def add_chapter_title(doc: Document, number: str, title: str):
    p1 = doc.add_paragraph()
    p1.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p1.paragraph_format.space_before = Pt(0)
    p1.paragraph_format.space_after = Pt(0)
    p1.paragraph_format.line_spacing = 1.15
    r = p1.add_run(f"บทที่ {number}")
    set_run_font(r, 18, True)

    p2 = doc.add_paragraph()
    p2.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p2.paragraph_format.space_before = Pt(0)
    p2.paragraph_format.space_after = Pt(10)
    p2.paragraph_format.line_spacing = 1.15
    r = p2.add_run(title)
    set_run_font(r, 18, True)


def add_heading_th(doc: Document, text: str, level: int):
    para = doc.add_paragraph()
    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    para.paragraph_format.space_before = Pt(8)
    para.paragraph_format.space_after = Pt(4)
    para.paragraph_format.line_spacing = 1.15
    para.paragraph_format.first_line_indent = Cm(0)
    size = 16 if level >= 2 else 16
    r = para.add_run(academic_text(text))
    set_run_font(r, size, True)


def png_size(path: Path):
    with path.open("rb") as f:
        header = f.read(24)
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    width, height = struct.unpack(">II", header[16:24])
    return width, height


def resolve_image(md_path: Path, rel: str) -> Path | None:
    rel = rel.strip().lstrip("./")
    for candidate in (md_path.parent / rel, ROOT / "docs" / rel, ROOT / rel):
        if candidate.exists() and candidate.is_file():
            return candidate.resolve()
    return None


def add_figure(doc: Document, image_path: Path, caption: str):
    # Prefer readable diagram text in thesis printouts. Tall Mermaid exports
    # (TB flowcharts) may exceed one page; keep a minimum width so labels stay legible.
    max_width_cm = 15.5
    max_height_cm = 22.0
    min_width_cm = 13.5
    width_cm = max_width_cm
    size = png_size(image_path) if image_path.suffix.lower() == ".png" else None
    if size and size[0] > 0:
        img_w, img_h = size
        height_cm = width_cm * (img_h / img_w)
        if height_cm > max_height_cm:
            width_cm = max(min_width_cm, max_height_cm * (img_w / img_h))

    para = doc.add_paragraph()
    para.alignment = WD_ALIGN_PARAGRAPH.CENTER
    para.paragraph_format.first_line_indent = Cm(0)
    para.paragraph_format.space_before = Pt(10)
    para.paragraph_format.space_after = Pt(2)
    para.paragraph_format.line_spacing = 1.0
    run = para.add_run()
    run.add_picture(str(image_path), width=Cm(width_cm))

    cap = doc.add_paragraph()
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cap.paragraph_format.first_line_indent = Cm(0)
    cap.paragraph_format.space_before = Pt(2)
    cap.paragraph_format.space_after = Pt(10)
    cap.paragraph_format.line_spacing = 1.15
    r = cap.add_run(academic_text(caption))
    set_run_font(r, 14, False)


def add_body(doc: Document, text: str):
    para = doc.add_paragraph()
    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    para.paragraph_format.first_line_indent = Cm(1.0)
    para.paragraph_format.space_after = Pt(6)
    para.paragraph_format.space_before = Pt(0)
    para.paragraph_format.line_spacing = 1.15
    r = para.add_run(academic_text(text))
    set_run_font(r, 16, False)


def add_list_item(doc: Document, text: str, numbered: bool):
    para = doc.add_paragraph()
    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    para.paragraph_format.left_indent = Cm(1.25)
    para.paragraph_format.first_line_indent = Cm(0)
    para.paragraph_format.space_after = Pt(2)
    para.paragraph_format.line_spacing = 1.15
    prefix = "" if numbered else "- "
    r = para.add_run(prefix + academic_text(text))
    set_run_font(r, 16, False)


def add_table(doc: Document, headers, rows):
    caption = None
    t = doc.add_table(rows=1 + len(rows), cols=len(headers))
    t.style = "Table Grid"
    t.autofit = True
    for i, hd in enumerate(headers):
        cell = t.rows[0].cells[i]
        cell.text = ""
        p = cell.paragraphs[0]
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
        r = p.add_run(academic_text(hd))
        set_run_font(r, 14, True)
    for ri, row in enumerate(rows, 1):
        for ci, val in enumerate(row):
            if ci >= len(headers):
                break
            cell = t.rows[ri].cells[ci]
            cell.text = ""
            p = cell.paragraphs[0]
            r = p.add_run(academic_text(str(val)))
            set_run_font(r, 14, False)
            p.paragraph_format.line_spacing = 1.0
            p.paragraph_format.space_after = Pt(0)
    spacer = doc.add_paragraph()
    spacer.paragraph_format.space_after = Pt(4)


def parse_table(lines):
    rows = []
    for line in lines:
        if not line.strip().startswith("|"):
            continue
        cells = [c.strip() for c in line.strip().strip("|").split("|")]
        if all(re.fullmatch(r":?-+:?", c.replace(" ", "").replace("-", "-")) or set(c.replace(":", "").replace(" ", "")) <= set("-") for c in cells):
            if all(re.sub(r"[:\s]", "", c) == re.sub(r"[:\s]", "", c).replace(" ", "") and set(re.sub(r"[:\s]", "", c)) <= set("-") for c in cells):
                continue
        if all(set(c.replace(":", "").replace(" ", "")) <= set("-") for c in cells):
            continue
        rows.append(cells)
    if not rows:
        return None
    headers = rows[0]
    width = len(headers)
    body = []
    for row in rows[1:]:
        while len(row) < width:
            row.append("")
        body.append(row[:width])
    return headers, body


def convert_markdown(doc: Document, path: Path, number: str, title: str):
    add_chapter_title(doc, number, title)
    lines = path.read_text(encoding="utf-8").splitlines()
    i = 0
    in_code = False
    skipped_titles = 0

    while i < len(lines):
        raw = lines[i]
        stripped = raw.strip()

        if stripped.startswith("```"):
            in_code = not in_code
            i += 1
            continue
        if in_code:
            i += 1
            continue
        if not stripped or stripped == "---":
            i += 1
            continue
        if stripped.startswith("> "):
            i += 1
            continue

        if stripped.startswith("# "):
            head = stripped[2:].strip()
            # Skip duplicate chapter banners already rendered.
            if skipped_titles < 2 and (head.startswith("บทที่") or head in {title, "โครงการ FLIXO Escrow", "บทนำ", "ทฤษฎีและงานวิจัยที่เกี่ยวข้อง", "การออกแบบและพัฒนาระบบ", "ผลการทดลองและการประเมินระบบ", "สรุปผลการวิจัยและข้อเสนอแนะ"}):
                skipped_titles += 1
                i += 1
                continue
            add_heading_th(doc, head, 1)
            i += 1
            continue
        if stripped.startswith("## "):
            add_heading_th(doc, stripped[3:], 2)
            i += 1
            continue
        if stripped.startswith("### "):
            add_heading_th(doc, stripped[4:], 3)
            i += 1
            continue
        if stripped.startswith("#### "):
            add_heading_th(doc, stripped[5:], 3)
            i += 1
            continue

        if stripped.startswith("|") and i + 1 < len(lines) and lines[i + 1].strip().startswith("|"):
            block = []
            while i < len(lines) and lines[i].strip().startswith("|"):
                block.append(lines[i])
                i += 1
            parsed = parse_table(block)
            if parsed:
                add_table(doc, parsed[0], parsed[1])
            continue

        m_img = re.match(r"^!\[(.+?)\]\((.+?)\)$", stripped)
        if m_img:
            caption, rel = m_img.group(1), m_img.group(2)
            img = resolve_image(path, rel)
            if img:
                add_figure(doc, img, caption)
            else:
                add_body(doc, f"(ไม่พบไฟล์ภาพ: {caption})")
            i += 1
            continue

        m_num_paren = re.match(r"^(\d+)\)\s+(.*)$", stripped)
        m_dash = stripped.startswith("- ")
        if m_num_paren:
            add_list_item(doc, m_num_paren.group(2), numbered=True)
            i += 1
            continue
        if m_dash:
            add_list_item(doc, stripped[2:], numbered=False)
            i += 1
            continue

        add_body(doc, stripped)
        i += 1


def main():
    combined = new_document()
    combined_all = new_document()
    for idx, (src, dest, number, title) in enumerate(CHAPTERS):
        if not src.exists():
            raise FileNotFoundError(src)
        single = new_document()
        convert_markdown(single, src, number, title)
        single.save(dest)
        print(f"Wrote {dest.name}")

        if number in {"1", "2", "3"}:
            if number != "1":
                combined.add_page_break()
            convert_markdown(combined, src, number, title)

        if idx:
            combined_all.add_page_break()
        convert_markdown(combined_all, src, number, title)

    combined.save(COMBINED)
    print(f"Wrote {COMBINED.name}")
    combined_all.save(COMBINED_ALL)
    print(f"Wrote {COMBINED_ALL.name}")


if __name__ == "__main__":
    main()
