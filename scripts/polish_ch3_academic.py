# -*- coding: utf-8 -*-
"""Academic layout polish for Chapter 3 without changing FLIXO logic."""

from __future__ import annotations

import io
import shutil
import struct
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt
from docx.text.paragraph import Paragraph

ROOT = Path(__file__).resolve().parents[1]
FIG = ROOT / "docs" / "thesis_figures"
SHOT = ROOT / "docs" / "appendix_ch4" / "screenshots"
LATIN = "Leelawadee UI"
THAI = "Leelawadee UI"
SRC = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx")
COPIES = [
    Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ.docx"),
    ROOT / "docs" / "บทที่ 3 การออกแบบและพัฒนาระบบ.docx",
]

FIGURES = {
    "ภาพที่ 3.1 องค์ประกอบหลักของระบบทั้งสี่ขอบเขต": (FIG / "fig3_scopes.png", 15.0, 9.0),
    "ภาพที่ 3.2 สถาปัตยกรรมและความสัมพันธ์ของโมดูล": (FIG / "fig3_module_relations.png", 15.0, 10.5),
    "ภาพที่ 3.3 สถาปัตยกรรมระบบสามชั้น": (FIG / "fig3_2_architecture.png", 14.8, 7.0),
    "ภาพที่ 3.4 ขั้นตอนการเข้าสู่ระบบและการแยกยืนยันตัวตน": (FIG / "fig3_login_flow.png", 15.0, 6.8),
    "ภาพที่ 3.5 หน้าเข้าสู่ระบบของแพลตฟอร์ม": (FIG / "shot_login.png", 13.6, 16.0),
    "ภาพที่ 3.6 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า": (FIG / "fig3_3_ekyc_flow.png", 16.0, 7.2),
    "ภาพที่ 3.7 หน้ายืนยันตัวตนอิเล็กทรอนิกส์": (FIG / "shot_ekyc.png", 13.6, 16.0),
    "ภาพที่ 3.8 ขั้นตอนการตรวจสอบภาพสินค้าแบบสองชั้น": (FIG / "fig3_image_check.png", 15.2, 13.4),
    "ภาพที่ 3.9 หน้าลงประกาศขาย": (FIG / "shot_sell.png", 14.2, 16.0),
    "ภาพที่ 3.10 การคัดกรองข้อความในห้องสนทนาแบบหลายชั้น": (FIG / "fig3_chat_moderation.png", 15.2, 14.2),
    "ภาพที่ 3.11 ภาพรวมขั้นตอนข้อพิพาทและหน้าห้องสนทนา": (FIG / "fig3_dispute_ui.png", 15.2, 16.5),
    "ภาพที่ 3.12 ภาพรวมบัญชีตัวกลางและหน้ารายการสั่งซื้อ": (FIG / "fig3_escrow_ui.png", 15.2, 16.5),
    "ภาพที่ 3.13 ชั้นสำรองเมื่อบริการภายนอกไม่พร้อมใช้งาน": (FIG / "fig3_fallback.png", 15.0, 11.0),
}

CAPTION_RENAMES = {
    "ภาพที่ 3.11 ขั้นตอนการจัดการข้อพิพาทและผู้ช่วยผู้ดูแลระบบ":
        "ภาพที่ 3.11 ภาพรวมขั้นตอนข้อพิพาทและหน้าห้องสนทนา",
    "ภาพที่ 3.12 ขั้นตอนการทำงานของบัญชีตัวกลาง":
        "ภาพที่ 3.12 ภาพรวมบัญชีตัวกลางและหน้ารายการสั่งซื้อ",
}

TERM_FIXES = [
    ("ยอดเงินในบัญชีกลางจะถูกระงับ", "ยอดเงินในบัญชีตัวกลางจะถูกระงับ"),
    ("ระงับเงินในบัญชีกลาง", "ระงับเงินในบัญชีตัวกลาง"),
    (
        "ขั้นตอนการจัดการข้อพิพาทแสดงดังภาพที่ 3.11",
        "ภาพรวมขั้นตอนข้อพิพาทและหน้าห้องสนทนาแสดงดังภาพที่ 3.11",
    ),
    (
        "ขั้นตอนการทำงานของบัญชีตัวกลางแสดงดังภาพที่ 3.12",
        "ภาพรวมบัญชีตัวกลางและหน้ารายการสั่งซื้อแสดงดังภาพที่ 3.12",
    ),
]


def fix_text(text: str) -> str:
    if not text:
        return text
    text = text.replace("\u0e4d\u0e32", "\u0e33")
    text = text.replace("ธรุกรรม", "ธุรกรรม")
    text = text.replace("อจัฉริยะ", "อัจฉริยะ")
    for old, new in TERM_FIXES:
        text = text.replace(old, new)
    return text


def set_run_font(run, size, bold=False):
    run.font.name = LATIN
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), LATIN)
    rfonts.set(qn("w:hAnsi"), LATIN)
    rfonts.set(qn("w:eastAsia"), THAI)
    rfonts.set(qn("w:cs"), THAI)
    run.font.size = Pt(size)
    run.bold = bold
    for attr in ("w:asciiTheme", "w:hAnsiTheme", "w:eastAsiaTheme", "w:cstheme"):
        if rfonts.get(qn(attr)):
            del rfonts.attrib[qn(attr)]


def png_size(path: Path):
    with path.open("rb") as f:
        header = f.read(24)
    return struct.unpack(">II", header[16:24])


def width_for(path: Path, max_w: float, max_h: float) -> float:
    w, h = png_size(path)
    height = max_w * (h / w)
    if height > max_h:
        return max_h * (w / h)
    return max_w


def find_para(doc, exact):
    for p in doc.paragraphs:
        if p.text.strip() == exact:
            return p
    return None


def find_any(doc, *texts):
    for text in texts:
        para = find_para(doc, text)
        if para is not None:
            return para
    return None


def set_para_text(p: Paragraph, text: str, size=11, bold=False):
    align = p.alignment
    indent = p.paragraph_format.first_line_indent
    clear_content(p)
    run = p.add_run(text)
    set_run_font(run, size, bold)
    p.alignment = align
    p.paragraph_format.first_line_indent = indent


def find_image_para(caption_p: Paragraph):
    el = caption_p._element.getprevious()
    while el is not None:
        if el.tag.endswith("}p") and "a:blip" in el.xml:
            return Paragraph(el, caption_p._parent)
        el = el.getprevious()
    return None


def clear_content(p: Paragraph):
    for child in list(p._element):
        if not child.tag.endswith("}pPr"):
            p._element.remove(child)


def set_keep(p: Paragraph, keep_next: bool, keep_lines: bool = True):
    pPr = p._element.get_or_add_pPr()

    def flag(name: str, on: bool):
        el = pPr.find(qn(name))
        if el is None:
            el = OxmlElement(name)
            pPr.append(el)
        if on:
            if qn("w:val") in el.attrib:
                del el.attrib[qn("w:val")]
        else:
            el.set(qn("w:val"), "0")

    flag("w:keepNext", keep_next)
    flag("w:keepLines", keep_lines)
    wc = pPr.find(qn("w:widowControl"))
    if wc is None:
        wc = OxmlElement("w:widowControl")
        pPr.append(wc)
    wc.set(qn("w:val"), "0")


def set_spacing(p: Paragraph, before: int, after: int, line: float | None = None):
    pf = p.paragraph_format
    pf.space_before = Pt(before)
    pf.space_after = Pt(after)
    if line is not None:
        pf.line_spacing_rule = WD_LINE_SPACING.MULTIPLE
        pf.line_spacing = line


def replace_image(caption_p: Paragraph, image_path: Path, max_w: float, max_h: float):
    img_p = find_image_para(caption_p)
    if img_p is None:
        raise RuntimeError("no image before " + caption_p.text.strip())
    clear_content(img_p)
    img_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    img_p.paragraph_format.first_line_indent = Cm(0)
    set_spacing(img_p, 6, 2)
    run = img_p.add_run()
    run.add_picture(str(image_path), width=Cm(width_for(image_path, max_w, max_h)))
    caption_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    caption_p.paragraph_format.first_line_indent = Cm(0)
    set_spacing(caption_p, 0, 10)
    return img_p


def move_after(anchor: Paragraph, paras: list[Paragraph]):
    node = anchor._element
    for p in paras:
        node.addnext(p._element)
        node = p._element


def iter_paragraphs(doc: Document):
    yield from doc.paragraphs
    for table in doc.tables:
        for row in table.rows:
            for cell in row.cells:
                yield from cell.paragraphs


def apply_styles_and_fonts(doc: Document):
    style_sizes = {
        "Normal": 11,
        "Body Text": 11,
        "First Paragraph": 11,
        "Compact": 11,
        "Heading 1": 18,
        "Heading 2": 16,
        "Heading 3": 14,
    }
    for name, size in style_sizes.items():
        try:
            st = doc.styles[name]
        except KeyError:
            continue
        st.font.name = LATIN
        st.font.size = Pt(size)
        if name in ("Normal", "Body Text", "First Paragraph"):
            st.paragraph_format.space_before = Pt(0)
            st.paragraph_format.space_after = Pt(6)
            st.paragraph_format.line_spacing = 1.08
        if name == "Compact":
            st.paragraph_format.space_before = Pt(1)
            st.paragraph_format.space_after = Pt(1)
            st.paragraph_format.line_spacing = 1.08
        if name == "Heading 2":
            st.paragraph_format.space_before = Pt(14)
            st.paragraph_format.space_after = Pt(6)
        if name == "Heading 3":
            st.paragraph_format.space_before = Pt(10)
            st.paragraph_format.space_after = Pt(4)
        rpr = st._element.get_or_add_rPr()
        rfonts = rpr.find(qn("w:rFonts"))
        if rfonts is None:
            rfonts = OxmlElement("w:rFonts")
            rpr.append(rfonts)
        rfonts.set(qn("w:ascii"), LATIN)
        rfonts.set(qn("w:hAnsi"), LATIN)
        rfonts.set(qn("w:eastAsia"), THAI)
        rfonts.set(qn("w:cs"), THAI)
        for attr in ("w:asciiTheme", "w:hAnsiTheme", "w:eastAsiaTheme", "w:cstheme"):
            if rfonts.get(qn(attr)):
                del rfonts.attrib[qn(attr)]

    for p in iter_paragraphs(doc):
        text = p.text.strip()
        style = p.style.name if p.style else "Normal"
        if text.startswith("ภาพที่ "):
            size, bold = 11, False
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            set_spacing(p, 0, 8, 1.08)
        elif style == "Heading 1":
            size, bold = 18, True
            set_spacing(p, 0 if text.startswith("บทที่") else 4, 6, 1.08)
        elif style == "Heading 2":
            size, bold = 16, True
            set_spacing(p, 12, 6, 1.08)
        elif style.startswith("Heading"):
            size, bold = 14, True
            set_spacing(p, 8, 4, 1.08)
        elif style == "Compact":
            size, bold = 11, False
            set_spacing(p, 1, 1, 1.08)
        else:
            size, bold = 11, False
            set_spacing(p, 0, 6, 1.08)
        p.paragraph_format.first_line_indent = Cm(0)
        for run in p.runs:
            xml = run._element.xml
            if "a:blip" in xml or "w:drawing" in xml:
                continue
            if run.text:
                run.text = fix_text(run.text)
            set_run_font(run, size, bold)


def keep_figure_block(doc: Document, caption: str):
    cap = find_para(doc, caption)
    if cap is None:
        return
    img = find_image_para(cap)
    if img is None:
        return
    lead = None
    el = img._element.getprevious()
    while el is not None:
        if el.tag.endswith("}p"):
            prev = Paragraph(el, cap._parent)
            if prev.text.strip():
                lead = prev
                break
        el = el.getprevious()
    if lead is not None and "ภาพที่" in lead.text:
        set_keep(lead, True)
    set_keep(img, True)
    set_keep(cap, False)


def patch_package(path: Path):
    buf = io.BytesIO()
    with ZipFile(path, "r") as zin:
        names = zin.namelist()
        with ZipFile(buf, "w", ZIP_DEFLATED) as zout:
            for name in names:
                data = zin.read(name)
                if name.endswith(".xml") and name.startswith("word/"):
                    xml = data.decode("utf-8")
                    xml = xml.replace("\u0e4d\u0e32", "\u0e33")
                    xml = xml.replace("ธรุกรรม", "ธุรกรรม")
                    xml = xml.replace("อจัฉริยะ", "อัจฉริยะ")
                    xml = xml.replace("ยอดเงินในบัญชีกลางจะถูกระงับ", "ยอดเงินในบัญชีตัวกลางจะถูกระงับ")
                    if name == "word/theme/theme1.xml":
                        xml = xml.replace('typeface="TH Sarabun New"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('typeface="Aptos"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('typeface="Aptos Display"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('script="Thai" typeface="Angsana New"', f'script="Thai" typeface="{THAI}"')
                        xml = xml.replace('script="Thai" typeface="Cordia New"', f'script="Thai" typeface="{THAI}"')
                    elif name == "word/settings.xml":
                        if "<w:embedTrueTypeFonts" not in xml:
                            start = xml.find("<w:settings")
                            idx = xml.find(">", start)
                            xml = xml[: idx + 1] + "<w:embedTrueTypeFonts/>" + xml[idx + 1 :]
                        if "<w:saveSubsetFonts" not in xml:
                            start = xml.find("<w:settings")
                            idx = xml.find(">", start)
                            xml = xml[: idx + 1] + '<w:saveSubsetFonts w:val="0"/>' + xml[idx + 1 :]
                        else:
                            xml = xml.replace("<w:saveSubsetFonts/>", '<w:saveSubsetFonts w:val="0"/>')
                    data = xml.encode("utf-8")
                zout.writestr(name, data)
    path.write_bytes(buf.getvalue())


def main():
    missing = [str(path) for path, _w, _h in FIGURES.values() if not path.exists()]
    if missing:
        raise FileNotFoundError(missing)
    if not SRC.exists():
        raise FileNotFoundError(SRC)

    doc = Document(str(SRC))

    for old, new in CAPTION_RENAMES.items():
        cap = find_any(doc, old, new)
        if cap is None:
            raise RuntimeError("missing caption: " + old)
        if cap.text.strip() != new:
            set_para_text(cap, new, size=11)

    intro7 = find_para(
        doc,
        "ระบบข้อพิพาทถูกออกแบบเพื่อจัดการปัญหาที่เกิดขึ้นระหว่างผู้ซื้อและผู้ขาย โดยเชื่อมโยงข้อมูลข้อพิพาทเข้ากับสถานะเงินในบัญชีตัวกลาง",
    )
    lead11 = find_any(
        doc,
        "ขั้นตอนการจัดการข้อพิพาทแสดงดังภาพที่ 3.11",
        "ภาพรวมขั้นตอนข้อพิพาทและหน้าห้องสนทนาแสดงดังภาพที่ 3.11",
    )
    cap11 = find_para(doc, "ภาพที่ 3.11 ภาพรวมขั้นตอนข้อพิพาทและหน้าห้องสนทนา")
    head7 = find_para(doc, "3.7 ระบบข้อพิพาทและผู้ช่วยผู้ดูแลระบบ")
    if intro7 and lead11 and cap11:
        img11 = find_image_para(cap11)
        if img11 is not None:
            move_after(intro7, [lead11, img11, cap11])

    intro8 = find_para(
        doc,
        "ระบบบัญชีตัวกลางเป็นส่วนสำคัญของ FLIXO โดยออกแบบให้เงินของผู้ซื้อถูกเก็บไว้ก่อน และจะถูกปล่อยให้ผู้ขายเมื่อเงื่อนไขของคำสั่งซื้อสำเร็จ",
    )
    lead12 = find_any(
        doc,
        "ขั้นตอนการทำงานของบัญชีตัวกลางแสดงดังภาพที่ 3.12",
        "ภาพรวมบัญชีตัวกลางและหน้ารายการสั่งซื้อแสดงดังภาพที่ 3.12",
    )
    cap12 = find_para(doc, "ภาพที่ 3.12 ภาพรวมบัญชีตัวกลางและหน้ารายการสั่งซื้อ")
    if intro8 and lead12 and cap12:
        img12 = find_image_para(cap12)
        if img12 is not None:
            move_after(intro8, [lead12, img12, cap12])

    for caption, (path, max_w, max_h) in FIGURES.items():
        cap = find_para(doc, caption)
        if cap is None:
            raise RuntimeError("missing caption: " + caption)
        replace_image(cap, path, max_w, max_h)
        keep_figure_block(doc, caption)

    if head7 is not None:
        set_keep(head7, True)
        if intro7 is not None:
            set_keep(intro7, True)

    head8 = find_para(doc, "3.8 ระบบชำระเงินและบัญชีตัวกลาง")
    if head8 is not None:
        set_keep(head8, True)
        if intro8 is not None:
            set_keep(intro8, True)

    apply_styles_and_fonts(doc)
    for p in doc.paragraphs:
        style = p.style.name if p.style else ""
        if style == "Heading 3":
            set_keep(p, False, keep_lines=False)

    for caption in FIGURES:
        keep_figure_block(doc, caption)
    if head7 is not None:
        set_keep(head7, True)
        if intro7 is not None:
            set_keep(intro7, True)
    if head8 is not None:
        set_keep(head8, True)
        if intro8 is not None:
            set_keep(intro8, True)
    for title in (
        "3.4.2 การอ่านข้อมูลจากบัตรประชาชน",
        "3.5.1 การตรวจสอบข้อมูลเบื้องต้น",
        "3.6.3 การกำหนดผลตามระดับความเสี่ยง",
    ):
        heading = find_para(doc, title)
        if heading is not None:
            set_keep(heading, True)
    doc.save(str(SRC))
    patch_package(SRC)
    for dest in COPIES:
        try:
            shutil.copy2(SRC, dest)
        except OSError:
            pass
    print("academic polish done")


if __name__ == "__main__":
    main()
