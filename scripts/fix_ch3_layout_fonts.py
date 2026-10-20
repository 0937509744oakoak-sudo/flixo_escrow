# -*- coding: utf-8 -*-
"""Layout, figure, and Thai-font fixes for Chapter 3."""

from __future__ import annotations

import io
import shutil
import struct
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt
from docx.text.paragraph import Paragraph

ROOT = Path(__file__).resolve().parents[1]
FIG = ROOT / "docs" / "thesis_figures"
LATIN = "Leelawadee UI"
THAI = "Leelawadee UI"
SRC = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx")
COPIES = [
    Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ.docx"),
    ROOT / "docs" / "บทที่ 3 การออกแบบและพัฒนาระบบ.docx",
]

FIG6 = FIG / "fig3_3_ekyc_flow.png"
FIG11 = FIG / "fig3_dispute_flow.png"
CAP6 = "ภาพที่ 3.6 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า"
CAP11 = "ภาพที่ 3.11 ขั้นตอนการจัดการข้อพิพาทและผู้ช่วยผู้ดูแลระบบ"
LEAD11 = "ขั้นตอนการจัดการข้อพิพาทแสดงดังภาพที่ 3.11"
INTRO7 = "ระบบข้อพิพาทถูกออกแบบเพื่อจัดการปัญหาที่เกิดขึ้นระหว่างผู้ซื้อและผู้ขาย โดยเชื่อมโยงข้อมูลข้อพิพาทเข้ากับสถานะเงินในบัญชีตัวกลาง"
HEAD7 = "3.7 ระบบข้อพิพาทและผู้ช่วยผู้ดูแลระบบ"
HEAD71 = "3.7.1 การเปิดข้อพิพาท"


def fix_sara_am(text: str) -> str:
    if not text:
        return text
    text = text.replace("\u0e4d\u0e32", "\u0e33")
    text = text.replace("ธรุกรรม", "ธุรกรรม")
    text = text.replace("อจัฉริยะ", "อัจฉริยะ")
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


def replace_image(caption_p: Paragraph, image_path: Path, max_w: float, max_h: float):
    img_p = find_image_para(caption_p)
    if img_p is None:
        raise RuntimeError("no image before " + caption_p.text.strip())
    clear_content(img_p)
    img_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    img_p.paragraph_format.first_line_indent = Cm(0)
    img_p.paragraph_format.space_before = Pt(4)
    img_p.paragraph_format.space_after = Pt(2)
    run = img_p.add_run()
    run.add_picture(str(image_path), width=Cm(width_for(image_path, max_w, max_h)))
    caption_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    caption_p.paragraph_format.space_before = Pt(0)
    caption_p.paragraph_format.space_after = Pt(8)
    if caption_p.runs:
        set_run_font(caption_p.runs[0], 11, False)
    return img_p


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


def apply_fonts(doc: Document):
    style_sizes = {
        "Normal": 11,
        "Body Text": 11,
        "First Paragraph": 11,
        "Compact": 11,
        "Heading 1": 20,
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
        if name == "Compact":
            st.paragraph_format.space_before = Pt(2)
            st.paragraph_format.space_after = Pt(2)
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
        elif style == "Heading 1":
            size, bold = 20, True
        elif style == "Heading 2":
            size, bold = 16, True
        elif style.startswith("Heading"):
            size, bold = 14, True
        else:
            size, bold = 11, False
        for run in p.runs:
            xml = run._element.xml
            if "a:blip" in xml or "w:drawing" in xml:
                continue
            if run.text:
                run.text = fix_sara_am(run.text)
            set_run_font(run, size, bold)


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
                    if name == "word/theme/theme1.xml":
                        xml = xml.replace('typeface="TH Sarabun New"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('typeface="Aptos"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('typeface="Aptos Display"', 'typeface="Leelawadee UI"')
                        xml = xml.replace('script="Thai" typeface="Angsana New"', f'script="Thai" typeface="{THAI}"')
                        xml = xml.replace('script="Thai" typeface="Cordia New"', f'script="Thai" typeface="{THAI}"')
                        xml = xml.replace('script="Thai" typeface="TH Sarabun New"', f'script="Thai" typeface="{THAI}"')
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
    for pth in (FIG6, FIG11, SRC):
        if not pth.exists():
            raise FileNotFoundError(pth)

    doc = Document(str(SRC))
    head7 = find_para(doc, HEAD7)
    head71 = find_para(doc, HEAD71)
    intro = find_para(doc, INTRO7)
    lead = find_para(doc, LEAD11)
    cap11 = find_para(doc, CAP11)
    cap6 = find_para(doc, CAP6)
    if not all([head7, intro, lead, cap11, cap6]):
        raise RuntimeError("required paragraphs missing")

    img11 = find_image_para(cap11)
    if img11 is None:
        raise RuntimeError("figure 3.11 image missing")

    move_after(intro, [lead, img11, cap11])

    img6 = replace_image(cap6, FIG6, max_w=15.8, max_h=14.5)
    img11 = replace_image(cap11, FIG11, max_w=12.0, max_h=5.8)
    set_keep(head7, True)
    set_keep(intro, True)
    set_keep(lead, True)
    set_keep(img11, True)
    set_keep(cap11, False)
    set_keep(img6, True)
    set_keep(cap6, False)
    if head71 is not None:
        set_keep(head71, False, keep_lines=False)
        nxt = head71._element.getnext()
        while nxt is not None and nxt.tag.endswith("}p"):
            np = Paragraph(nxt, head71._parent)
            if np.text.strip():
                set_keep(np, False, keep_lines=False)
                break
            nxt = nxt.getnext()

    apply_fonts(doc)
    doc.save(str(SRC))
    patch_package(SRC)
    for dest in COPIES:
        try:
            shutil.copy2(SRC, dest)
        except OSError:
            pass
    print("layout and font patch done")


if __name__ == "__main__":
    main()
