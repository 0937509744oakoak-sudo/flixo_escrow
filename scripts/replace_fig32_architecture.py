# -*- coding: utf-8 -*-
"""Replace figure 3.2 with layered architecture diagram."""

from __future__ import annotations

import shutil
import struct
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt
from docx.text.paragraph import Paragraph

ROOT = Path(__file__).resolve().parents[1]
IMAGE = ROOT / "docs" / "thesis_figures" / "fig3_module_relations.png"
CAPTION = "ภาพที่ 3.2 สถาปัตยกรรมและความสัมพันธ์ของโมดูล"
OLD_LEAD = (
    "ความสัมพันธ์ระหว่างโมดูลหลักของระบบแสดงดังภาพที่ 3.2 โดยการเข้าสู่ระบบเชื่อมต่อไปยังการยืนยันตัวตน "
    "จากนั้นแยกไปยังการลงประกาศ บัญชีตัวกลาง และห้องสนทนา ก่อนรวมที่ข้อพิพาทและผู้ดูแลระบบ"
)
NEW_LEAD = (
    "สถาปัตยกรรมของระบบแสดงดังภาพที่ 3.2 โดยผู้ซื้อ ผู้ขาย และผู้ดูแลระบบติดต่อผ่านชั้นนำเสนอ "
    "ชั้นประมวลผลเชื่อมต่อไปยังชั้นข้อมูลและบริการภายนอก"
)
TARGETS = [
    Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx"),
    Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ.docx"),
    ROOT / "docs" / "บทที่ 3 การออกแบบและพัฒนาระบบ.docx",
]
FONT_NAME = "TH Sarabun New"


def png_size(path: Path):
    with path.open("rb") as f:
        header = f.read(24)
    return struct.unpack(">II", header[16:24])


def figure_width_cm(path: Path) -> float:
    max_width_cm = 14.6
    max_height_cm = 12.0
    img_w, img_h = png_size(path)
    height_cm = max_width_cm * (img_h / img_w)
    if height_cm > max_height_cm:
        return max_height_cm * (img_w / img_h)
    return max_width_cm


def set_run_font(run, size=14):
    run.font.name = FONT_NAME
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    for attr in ("w:ascii", "w:hAnsi", "w:eastAsia", "w:cs"):
        rfonts.set(qn(attr), FONT_NAME)
    run.font.size = Pt(size)


def find_para(doc: Document, exact: str):
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


def clear_paragraph_content(p: Paragraph):
    for child in list(p._element):
        if not child.tag.endswith("}pPr"):
            p._element.remove(child)


def replace_image(caption_p: Paragraph, image_path: Path):
    img_p = find_image_para(caption_p)
    if img_p is None:
        raise RuntimeError("image paragraph not found")
    clear_paragraph_content(img_p)
    img_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    img_p.paragraph_format.first_line_indent = Cm(0)
    run = img_p.add_run()
    run.add_picture(str(image_path), width=Cm(figure_width_cm(image_path)))
    if caption_p.runs:
        set_run_font(caption_p.runs[0], 14)


def patch(path: Path) -> None:
    doc = Document(str(path))
    lead = find_para(doc, OLD_LEAD) or find_para(doc, NEW_LEAD)
    if lead is None:
        raise RuntimeError("lead-in not found in " + path.name)
    if lead.runs:
        lead.runs[0].text = NEW_LEAD
        for r in lead.runs[1:]:
            r.text = ""
    cap = find_para(doc, CAPTION)
    if cap is None:
        raise RuntimeError("caption not found in " + path.name)
    replace_image(cap, IMAGE)
    doc.save(str(path))


def main():
    if not IMAGE.exists():
        raise FileNotFoundError(IMAGE)
    source = next(p for p in TARGETS if p.exists())
    patch(source)
    for dest in TARGETS:
        if dest == source:
            continue
        try:
            shutil.copy2(source, dest)
        except OSError:
            pass
    print("updated figure 3.2")


if __name__ == "__main__":
    main()
