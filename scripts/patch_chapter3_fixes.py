# -*- coding: utf-8 -*-
"""Patch Chapter 3 Word: replace fig 3.2, refresh 3.10/3.11, remove Ch.4 result language."""

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
SRC = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ.docx")
DST = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx")
DOCS_COPY = ROOT / "docs" / "บทที่ 3 การออกแบบและพัฒนาระบบ.docx"
FIG = ROOT / "docs" / "thesis_figures"
FONT_NAME = "TH Sarabun New"

TEXT_REPLACEMENTS = [
    (
        "บทนี้นำเสนอการออกแบบและพัฒนาระบบ FLIXO ตามวัตถุประสงค์ข้อ 1.2.2 และขอบเขตที่กำหนดไว้ในบทที่ 1 โดยมุ่งเน้นการออกแบบแพลตฟอร์มซื้อขายระหว่างบุคคลที่มีระบบยืนยันตัวตน ระบบบัญชีตัวกลาง ระบบคัดกรองเนื้อหา และระบบจัดการข้อพิพาท เนื้อหาในบทนี้เป็นข้ออ้างเชิงการออกแบบและการทำงานของระบบ ไม่ใช่ข้อสรุปว่าระบบหรือแต่ละโมดูลมีประสิทธิภาพในระดับใด ซึ่งผลการทดลองและการประเมินประสิทธิภาพจะนำเสนอในบทที่ 4",
        "บทนี้นำเสนอการออกแบบและพัฒนาระบบ FLIXO ตามวัตถุประสงค์ข้อ 1.2.2 และขอบเขตที่กำหนดไว้ในบทที่ 1 โดยมุ่งเน้นการออกแบบแพลตฟอร์มซื้อขายระหว่างบุคคลที่มีระบบยืนยันตัวตน ระบบบัญชีตัวกลาง ระบบคัดกรองเนื้อหา และระบบจัดการข้อพิพาท เนื้อหาในบทนี้เป็นข้ออ้างเชิงการออกแบบและการทำงานของระบบ ไม่ใช่ข้อสรุปว่าระบบหรือแต่ละโมดูลมีประสิทธิภาพในระดับใด",
    ),
    (
        "การออกแบบระบบมีข้อจำกัดสำคัญ ได้แก่ ช่องทางยืนยันตัวตนหลักที่ใช้ภาพนิ่ง การพึ่งพาบริการภายนอก ความเป็นส่วนตัวของภาพบัตรประชาชนและภาพใบหน้า และข้อจำกัดด้านขนาดตัวอย่างที่ใช้ประเมินระบบ ข้อจำกัดดังกล่าวถูกนำไปกำหนดเป็นประเด็นสำหรับการประเมินและอภิปรายในบทที่ 4",
        "การออกแบบระบบมีข้อจำกัดสำคัญ ได้แก่ ช่องทางยืนยันตัวตนหลักที่ใช้ภาพนิ่ง การพึ่งพาบริการภายนอก ความเป็นส่วนตัวของภาพบัตรประชาชนและภาพใบหน้า และข้อจำกัดของชั้นอัตโนมัติที่พึ่งบริการภายนอก ข้อจำกัดเหล่านี้ถูกออกแบบให้มีชั้นสำรองรองรับ ไม่ได้นำเสนอเป็นผลการวัดในบทนี้",
    ),
    (
        "แผนผังขั้นตอนการดำเนินงานวิจัยแสดงดังภาพที่ 3.2",
        "ความสัมพันธ์ระหว่างโมดูลหลักของระบบแสดงดังภาพที่ 3.2 โดยการเข้าสู่ระบบเชื่อมต่อไปยังการยืนยันตัวตน จากนั้นแยกไปยังการลงประกาศ บัญชีตัวกลาง และห้องสนทนา ก่อนรวมที่ข้อพิพาทและผู้ดูแลระบบ",
    ),
    (
        "ภาพที่ 3.2 แผนผังขั้นตอนการดำเนินงานวิจัย",
        "ภาพที่ 3.2 สถาปัตยกรรมและความสัมพันธ์ของโมดูล",
    ),
    (
        "จากการประเมินในบทที่ 4 พบว่าข้อความที่มีลักษณะกำกวมเกี่ยวกับการใช้ Line หรือการโอนเงินนอกระบบบางข้อความอาจได้คะแนนจากชั้นกฎเป็นศูนย์ ดังนั้นระบบจึงออกแบบให้มีสัญญาณเพิ่มเติมสำหรับส่งข้อความที่กล่าวถึง Line หรือการโอนนอกระบบเข้าสู่แบบจำลองภาษา แม้คะแนนรวมของกฎจะยังเป็นศูนย์ โดยสัญญาณดังกล่าวไม่ทำหน้าที่บล็อกข้อความด้วยตัวเอง แต่ใช้เพื่อส่งต่อให้ชั้นวิเคราะห์เพิ่มเติม",
        "ระบบออกแบบให้มีสัญญาณเพิ่มเติมสำหรับส่งข้อความที่กล่าวถึง Line หรือการโอนเงินนอกระบบเข้าสู่แบบจำลองภาษา แม้คะแนนรวมของกฎจะยังเป็นศูนย์ โดยสัญญาณดังกล่าวไม่ทำหน้าที่บล็อกข้อความด้วยตัวเอง แต่ใช้เพื่อส่งต่อให้ชั้นวิเคราะห์เพิ่มเติม",
    ),
    (
        "3.10 ข้อจำกัดของระบบที่นำไปสู่การประเมิน",
        "3.10 ข้อจำกัดของการออกแบบระบบ",
    ),
    (
        "การออกแบบระบบในบทนี้มีข้อจำกัดสำคัญที่ต้องนำไปประเมินในบทที่ 4",
        "การออกแบบระบบในบทนี้มีข้อจำกัดสำคัญที่ต้องคำนึงถึงขณะพัฒนาระบบ",
    ),
    (
        "ประการที่สาม ภาพบัตรประชาชนและภาพใบหน้าเป็นข้อมูลที่มีความอ่อนไหว ระบบจึงมีการเข้ารหัสไฟล์และปกปิดเลขบัตรประชาชนบางส่วนในบันทึกเหตุการณ์ อย่างไรก็ตาม การส่งภาพไปประมวลผลกับบริการภายนอกยังคงเป็นประเด็นด้านความเป็นส่วนตัวที่ต้องแจ้งให้ผู้ใช้ทราบและต้องนำไปพิจารณาในการประเมินระบบ",
        "ประการที่สาม ภาพบัตรประชาชนและภาพใบหน้าเป็นข้อมูลที่มีความอ่อนไหว ระบบจึงมีการเข้ารหัสไฟล์และปกปิดเลขบัตรประชาชนบางส่วนในบันทึกเหตุการณ์ อย่างไรก็ตาม การส่งภาพไปประมวลผลกับบริการภายนอกยังคงเป็นประเด็นด้านความเป็นส่วนตัวที่ต้องแจ้งให้ผู้ใช้ทราบ",
    ),
    (
        "อย่างไรก็ตาม การที่ระบบมีฟังก์ชันดังกล่าวไม่ได้หมายความว่าทุกฟังก์ชันได้รับการพิสูจน์ว่ามีประสิทธิภาพในทุกสถานการณ์ ผลการวัด ความถูกต้อง ข้อผิดพลาด และข้อจำกัดของแต่ละโมดูลจะนำเสนอและอภิปรายในบทที่ 4 ต่อไป",
        "อย่างไรก็ตาม การที่ระบบมีฟังก์ชันดังกล่าวไม่ได้หมายความว่าทุกฟังก์ชันได้รับการพิสูจน์ว่ามีประสิทธิภาพในทุกสถานการณ์ บทนี้จึงสรุปเฉพาะการออกแบบและการทำงานของระบบ",
    ),
]

DELETE_EXACT = [
    "จากข้อจำกัดดังกล่าว การประเมินในบทที่ 4 จึงต้องพิจารณามากกว่าสถานะผ่านหรือไม่ผ่าน โดยต้องรายงานอัตราความผิดพลาด พิจารณาเหตุผลของเกณฑ์ความคล้าย 80 แยกผลของท่อจำลองออกจากบริการจริง และระบุส่วนที่ยังไม่ได้ทดลอง โดยเฉพาะการโจมตีด้วยการนำเสนอและผลของผู้ช่วยปัญญาประดิษฐ์ต่อเวลาการตัดสินข้อพิพาทของผู้ดูแลระบบ",
]

REPLACE_IMAGES = {
    "ภาพที่ 3.2 สถาปัตยกรรมและความสัมพันธ์ของโมดูล": FIG / "fig3_module_relations.png",
    "ภาพที่ 3.10 การคัดกรองข้อความในห้องสนทนาแบบหลายชั้น": FIG / "fig3_chat_moderation.png",
    "ภาพที่ 3.11 ขั้นตอนการจัดการข้อพิพาทและผู้ช่วยผู้ดูแลระบบ": FIG / "fig3_dispute_flow.png",
}

KEEP_LEADINS = [
    "กระบวนการคัดกรองข้อความในห้องสนทนาแสดงดังภาพที่ 3.10",
    "ขั้นตอนการจัดการข้อพิพาทแสดงดังภาพที่ 3.11",
]


def set_run_font(run, size=16, bold=False):
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


def png_size(path: Path):
    with path.open("rb") as f:
        header = f.read(24)
    width, height = struct.unpack(">II", header[16:24])
    return width, height


def figure_width_cm(path: Path) -> float:
    max_width_cm = 14.6
    max_height_cm = 12.0
    img_w, img_h = png_size(path)
    height_cm = max_width_cm * (img_h / img_w)
    if height_cm > max_height_cm:
        return max_height_cm * (img_w / img_h)
    return max_width_cm


def find_para(doc: Document, exact: str):
    for p in doc.paragraphs:
        if p.text.strip() == exact:
            return p
    return None


def set_paragraph_text(p, text: str):
    if p.runs:
        p.runs[0].text = text
        for run in p.runs[1:]:
            run.text = ""
    else:
        p.add_run(text)


def delete_paragraph(p):
    el = p._element
    parent = el.getparent()
    if parent is not None:
        parent.remove(el)


def find_image_para(caption_p: Paragraph):
    el = caption_p._element.getprevious()
    while el is not None:
        if el.tag.endswith("}p"):
            xml = el.xml
            if "a:blip" in xml:
                return Paragraph(el, caption_p._parent)
        el = el.getprevious()
    return None


def clear_paragraph_content(p: Paragraph):
    for child in list(p._element):
        if not child.tag.endswith("}pPr"):
            p._element.remove(child)


def set_keep(p: Paragraph, keep_with_next=False):
    pPr = p._element.get_or_add_pPr()
    if keep_with_next and pPr.find(qn("w:keepNext")) is None:
        pPr.append(OxmlElement("w:keepNext"))
    if pPr.find(qn("w:keepLines")) is None:
        pPr.append(OxmlElement("w:keepLines"))


def replace_caption_image(caption_p: Paragraph, image_path: Path):
    img_p = find_image_para(caption_p)
    if img_p is None:
        raise RuntimeError("ไม่พบภาพก่อนคำบรรยาย: " + caption_p.text.strip())
    clear_paragraph_content(img_p)
    img_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    img_p.paragraph_format.first_line_indent = Cm(0)
    img_p.paragraph_format.space_before = Pt(10)
    img_p.paragraph_format.space_after = Pt(4)
    img_p.paragraph_format.line_spacing = 1.0
    run = img_p.add_run()
    run.add_picture(str(image_path), width=Cm(figure_width_cm(image_path)))
    caption_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    caption_p.paragraph_format.first_line_indent = Cm(0)
    if caption_p.runs:
        set_run_font(caption_p.runs[0], size=14, bold=False)
    set_keep(img_p, keep_with_next=True)
    set_keep(caption_p, keep_with_next=False)
    return img_p


def main():
    for path in REPLACE_IMAGES.values():
        if not path.exists():
            raise FileNotFoundError(path)

    doc = Document(str(SRC))

    missing = []
    for old, new in TEXT_REPLACEMENTS:
        p = find_para(doc, old)
        if p is None:
            missing.append(old)
            continue
        set_paragraph_text(p, new)
    if missing:
        raise RuntimeError("missing replacements:\n- " + "\n- ".join(missing))

    for exact in DELETE_EXACT:
        p = find_para(doc, exact)
        if p is None:
            raise RuntimeError("missing delete: " + exact)
        delete_paragraph(p)

    for caption, path in REPLACE_IMAGES.items():
        p = find_para(doc, caption)
        if p is None:
            raise RuntimeError("missing caption: " + caption)
        replace_caption_image(p, path)

    for lead in KEEP_LEADINS:
        p = find_para(doc, lead)
        if p is not None:
            set_keep(p, keep_with_next=True)

    doc.save(str(DST))
    shutil.copy2(DST, DOCS_COPY)
    try:
        shutil.copy2(DST, SRC)
        print("also overwrote original")
    except OSError:
        print("original is locked; saved edited copy instead")
    print("patched chapter 3")
    print("images", sum(1 for rel in Document(str(DST)).part.rels.values() if "image" in rel.reltype))


if __name__ == "__main__":
    main()
