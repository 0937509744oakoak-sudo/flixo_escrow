# -*- coding: utf-8 -*-
"""Insert workflow/component figures into Chapter 3 Word file."""

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
BACKUP = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_ต้นฉบับ.docx")
DST = SRC
DOCS_COPY = ROOT / "docs" / "บทที่ 3 การออกแบบและพัฒนาระบบ.docx"
FIG = ROOT / "docs" / "thesis_figures"
SHOT = ROOT / "docs" / "appendix_ch4" / "screenshots"
FONT_NAME = "TH Sarabun New"

TEXT_REPLACEMENTS = [
    (
        "แผนผังขั้นตอนการดำเนินงานของระบบแสดงดังภาพที่ 3.1",
        "แผนผังขั้นตอนการดำเนินงานวิจัยแสดงดังภาพที่ 3.2",
    ),
    (
        "สถาปัตยกรรมของระบบ FLIXO ถูกออกแบบให้ผู้ใช้เข้าถึงระบบผ่านเว็บเบราว์เซอร์ โดยแบ่งการทำงานออกเป็น 3 ชั้น ได้แก่ ชั้นนำเสนอ ชั้นประมวลผล และชั้นข้อมูล ดังภาพที่ 3.2",
        "สถาปัตยกรรมของระบบ FLIXO ถูกออกแบบให้ผู้ใช้เข้าถึงระบบผ่านเว็บเบราว์เซอร์ โดยแบ่งการทำงานออกเป็น 3 ชั้น ได้แก่ ชั้นนำเสนอ ชั้นประมวลผล และชั้นข้อมูล ดังภาพที่ 3.3",
    ),
    (
        "หน้าเข้าสู่ระบบจึงไม่มีขั้นตอนสแกนใบหน้า เนื่องจากการตรวจสอบใบหน้าเป็นส่วนหนึ่งของกระบวนการ e-KYC ที่ทำหลังจากเข้าสู่ระบบแล้ว ดังภาพที่ 3.3",
        "ลำดับการเข้าสู่ระบบและการแยกออกจากการยืนยันตัวตนแสดงดังภาพที่ 3.4 ส่วนหน้าเข้าสู่ระบบแสดงดังภาพที่ 3.5 โดยหน้าเข้าสู่ระบบไม่มีขั้นตอนสแกนใบหน้า เพราะการตรวจสอบใบหน้าเป็นส่วนหนึ่งของกระบวนการ e-KYC ที่ทำหลังจากเข้าสู่ระบบแล้ว",
    ),
    (
        "ขั้นตอนการยืนยันตัวตนแสดงดังภาพที่ 3.4 และหน้าจอยืนยันตัวตนแสดงดังภาพที่ 3.5",
        "ขั้นตอนการยืนยันตัวตนแสดงดังภาพที่ 3.6 และหน้าจอยืนยันตัวตนแสดงดังภาพที่ 3.7",
    ),
    (
        "หน้าลงประกาศสินค้าจะแสดงดังภาพที่ 3.6 โดยระบบจะแจ้งผู้ใช้หากยังไม่ผ่านการยืนยันตัวตน และแสดงเงื่อนไขเกี่ยวกับการกักเก็บเงินของผู้ซื้อจนกว่าจะมีการยืนยันรับสินค้า",
        "หน้าลงประกาศสินค้าแสดงดังภาพที่ 3.9 โดยระบบจะแจ้งผู้ใช้หากยังไม่ผ่านการยืนยันตัวตน และแสดงเงื่อนไขเกี่ยวกับการกักเก็บเงินของผู้ซื้อจนกว่าจะมีการยืนยันรับสินค้า",
    ),
    ("ภาพที่ 3.1 แผนผังขั้นตอนการดำเนินงานวิจัย", "ภาพที่ 3.2 แผนผังขั้นตอนการดำเนินงานวิจัย"),
    ("ภาพที่ 3.2 สถาปัตยกรรมระบบสามชั้น", "ภาพที่ 3.3 สถาปัตยกรรมระบบสามชั้น"),
    ("ภาพที่ 3.3 หน้าเข้าสู่ระบบของแพลตฟอร์ม", "ภาพที่ 3.5 หน้าเข้าสู่ระบบของแพลตฟอร์ม"),
    ("ภาพที่ 3.4 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า", "ภาพที่ 3.6 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า"),
    ("ภาพที่ 3.5 หน้ายืนยันตัวตนอิเล็กทรอนิกส์", "ภาพที่ 3.7 หน้ายืนยันตัวตนอิเล็กทรอนิกส์"),
    ("ภาพที่ 3.6 หน้าลงประกาศขาย", "ภาพที่ 3.9 หน้าลงประกาศขาย"),
]

INSERT_AFTER = [
    (
        "ระบบข้อพิพาทและบริการลูกค้า ทำหน้าที่รับคำร้อง เก็บหลักฐาน และช่วยผู้ดูแลระบบวิเคราะห์ข้อพิพาท โดยผู้ดูแลระบบยังเป็นผู้มีอำนาจตัดสินผลทางการเงิน",
        [
            ("body", "องค์ประกอบหลักของระบบทั้งสี่ขอบเขตแสดงดังภาพที่ 3.1"),
            ("caption", "ภาพที่ 3.1 องค์ประกอบหลักของระบบทั้งสี่ขอบเขต"),
        ],
    ),
    (
        "ลำดับการเข้าสู่ระบบและการแยกออกจากการยืนยันตัวตนแสดงดังภาพที่ 3.4 ส่วนหน้าเข้าสู่ระบบแสดงดังภาพที่ 3.5 โดยหน้าเข้าสู่ระบบไม่มีขั้นตอนสแกนใบหน้า เพราะการตรวจสอบใบหน้าเป็นส่วนหนึ่งของกระบวนการ e-KYC ที่ทำหลังจากเข้าสู่ระบบแล้ว",
        [("caption", "ภาพที่ 3.4 ขั้นตอนการเข้าสู่ระบบและการแยกยืนยันตัวตน")],
    ),
    (
        "หากบริการวิเคราะห์ภาพไม่พร้อมใช้งาน ระบบจะไม่บล็อกประกาศโดยอัตโนมัติจากผลของชั้นดังกล่าว แต่จะใช้ข้อมูลจากการตรวจสอบชั้นแรกเป็นข้อมูลสำหรับติดตามโดยผู้ดูแลระบบ",
        [
            ("body", "ขั้นตอนการตรวจสอบภาพสินค้าแบบสองชั้นแสดงดังภาพที่ 3.8"),
            ("caption", "ภาพที่ 3.8 ขั้นตอนการตรวจสอบภาพสินค้าแบบสองชั้น"),
        ],
    ),
    (
        "หากแบบจำลองภาษาทั้งหมดไม่พร้อมใช้งาน ระบบยังสามารถใช้ผลจากกฎชั้นแรกต่อไปได้ เพื่อไม่ให้ห้องสนทนาหยุดทำงานทั้งหมด",
        [
            ("body", "กระบวนการคัดกรองข้อความในห้องสนทนาแสดงดังภาพที่ 3.10"),
            ("caption", "ภาพที่ 3.10 การคัดกรองข้อความในห้องสนทนาแบบหลายชั้น"),
        ],
    ),
    (
        "หลักการสำคัญของส่วนนี้คือไม่มีเส้นทางที่แบบจำลองภาษาเป็นผู้สั่งโอนเงินโดยตรง การเปลี่ยนแปลงยอดเงินยังต้องเกิดจากคำสั่งของผู้ดูแลระบบ",
        [
            ("body", "ขั้นตอนการจัดการข้อพิพาทแสดงดังภาพที่ 3.11"),
            ("caption", "ภาพที่ 3.11 ขั้นตอนการจัดการข้อพิพาทและผู้ช่วยผู้ดูแลระบบ"),
        ],
    ),
    (
        "ระบบบัญชีตัวกลางเป็นส่วนสำคัญของ FLIXO โดยออกแบบให้เงินของผู้ซื้อถูกเก็บไว้ก่อน และจะถูกปล่อยให้ผู้ขายเมื่อเงื่อนไขของคำสั่งซื้อสำเร็จ",
        [
            ("body", "ขั้นตอนการทำงานของบัญชีตัวกลางแสดงดังภาพที่ 3.12"),
            ("caption", "ภาพที่ 3.12 ขั้นตอนการทำงานของบัญชีตัวกลาง"),
        ],
    ),
    (
        "หลักการร่วมของระบบทุกโมดูล คือ เมื่อบริการภายนอกล้มเหลว ระบบต้องไม่หยุดการทำงานทั้งแพลตฟอร์ม และต้องไม่เปิดช่องให้เงินถูกปล่อยโดยไม่มีเงื่อนไข โดยแบ่งการทำงานออกเป็นชั้นอัตโนมัติเมื่อบริการพร้อมใช้งาน และชั้นมนุษย์หรือกฎเชิงตรรกะเมื่อบริการไม่พร้อมใช้งาน",
        [
            ("body", "ชั้นสำรองของแต่ละโมดูลเมื่อบริการภายนอกไม่พร้อมใช้งานแสดงดังภาพที่ 3.13"),
            ("caption", "ภาพที่ 3.13 ชั้นสำรองเมื่อบริการภายนอกไม่พร้อมใช้งาน"),
        ],
    ),
]

FIGURES = {
    "ภาพที่ 3.1 องค์ประกอบหลักของระบบทั้งสี่ขอบเขต": FIG / "fig3_scopes.png",
    "ภาพที่ 3.2 สถาปัตยกรรมและความสัมพันธ์ของโมดูล": FIG / "fig3_module_relations.png",
    "ภาพที่ 3.3 สถาปัตยกรรมระบบสามชั้น": FIG / "fig3_2_architecture.png",
    "ภาพที่ 3.4 ขั้นตอนการเข้าสู่ระบบและการแยกยืนยันตัวตน": FIG / "fig3_login_flow.png",
    "ภาพที่ 3.5 หน้าเข้าสู่ระบบของแพลตฟอร์ม": SHOT / "ui10_login.png",
    "ภาพที่ 3.6 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า": FIG / "fig3_3_ekyc_flow.png",
    "ภาพที่ 3.7 หน้ายืนยันตัวตนอิเล็กทรอนิกส์": SHOT / "ui02_ekyc.png",
    "ภาพที่ 3.8 ขั้นตอนการตรวจสอบภาพสินค้าแบบสองชั้น": FIG / "fig3_image_check.png",
    "ภาพที่ 3.9 หน้าลงประกาศขาย": SHOT / "ui03_sell.png",
    "ภาพที่ 3.10 การคัดกรองข้อความในห้องสนทนาแบบหลายชั้น": FIG / "fig3_chat_moderation.png",
    "ภาพที่ 3.11 ขั้นตอนการจัดการข้อพิพาทและผู้ช่วยผู้ดูแลระบบ": FIG / "fig3_dispute_flow.png",
    "ภาพที่ 3.12 ขั้นตอนการทำงานของบัญชีตัวกลาง": FIG / "fig3_escrow_flow.png",
    "ภาพที่ 3.13 ชั้นสำรองเมื่อบริการภายนอกไม่พร้อมใช้งาน": FIG / "fig3_fallback.png",
}


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
    if len(header) < 24 or header[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    width, height = struct.unpack(">II", header[16:24])
    return width, height


def figure_width_cm(path: Path) -> float:
    max_width_cm = 15.0
    max_height_cm = 16.5
    size = png_size(path)
    if not size or size[0] <= 0:
        return max_width_cm
    img_w, img_h = size
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


def insert_paragraph_after(paragraph, style_name=None) -> Paragraph:
    new_p = OxmlElement("w:p")
    paragraph._element.addnext(new_p)
    new_para = Paragraph(new_p, paragraph._parent)
    if style_name:
        new_para.style = style_name
    return new_para


def insert_paragraph_before(paragraph, style_name=None) -> Paragraph:
    new_p = OxmlElement("w:p")
    paragraph._element.addprevious(new_p)
    new_para = Paragraph(new_p, paragraph._parent)
    if style_name:
        new_para.style = style_name
    return new_para


def add_body_run(p, text: str, size=16):
    run = p.add_run(text)
    set_run_font(run, size=size, bold=False)
    return run


def insert_blocks_after(anchor: Paragraph, blocks: list[tuple[str, str]]):
    cursor = anchor
    for kind, text in reversed(blocks):
        if kind == "body":
            p = insert_paragraph_after(cursor, "Body Text")
            add_body_run(p, text, 16)
        elif kind == "caption":
            p = insert_paragraph_after(cursor, "Body Text")
            p.alignment = WD_ALIGN_PARAGRAPH.CENTER
            p.paragraph_format.first_line_indent = Cm(0)
            p.paragraph_format.space_before = Pt(2)
            p.paragraph_format.space_after = Pt(10)
            add_body_run(p, text, 14)
        else:
            raise ValueError(kind)


def insert_image_before_caption(caption_para: Paragraph, image_path: Path):
    img_p = insert_paragraph_before(caption_para, "Body Text")
    img_p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    img_p.paragraph_format.first_line_indent = Cm(0)
    img_p.paragraph_format.space_before = Pt(10)
    img_p.paragraph_format.space_after = Pt(4)
    img_p.paragraph_format.line_spacing = 1.0
    run = img_p.add_run()
    run.add_picture(str(image_path), width=Cm(figure_width_cm(image_path)))
    caption_para.alignment = WD_ALIGN_PARAGRAPH.CENTER
    caption_para.paragraph_format.first_line_indent = Cm(0)
    caption_para.paragraph_format.space_before = Pt(2)
    caption_para.paragraph_format.space_after = Pt(10)
    if caption_para.runs:
        set_run_font(caption_para.runs[0], size=14, bold=False)


def main():
    if not SRC.exists():
        raise FileNotFoundError(SRC)
    for caption, path in FIGURES.items():
        if not path.exists():
            raise FileNotFoundError(f"{caption}: {path}")

    if not BACKUP.exists():
        shutil.copy2(SRC, BACKUP)
        print("Backup created")

    doc = Document(str(SRC))

    missing = []
    for old, new in TEXT_REPLACEMENTS:
        p = find_para(doc, old)
        if p is None:
            missing.append(old)
            continue
        set_paragraph_text(p, new)
    if missing:
        raise RuntimeError("ไม่พบข้อความที่ต้องแทนที่:\n- " + "\n- ".join(missing))

    missing = []
    for anchor_text, blocks in INSERT_AFTER:
        p = find_para(doc, anchor_text)
        if p is None:
            missing.append(anchor_text)
            continue
        insert_blocks_after(p, blocks)
    if missing:
        raise RuntimeError("ไม่พบจุดแทรกภาพ:\n- " + "\n- ".join(missing))

    missing = []
    for caption, path in FIGURES.items():
        p = find_para(doc, caption)
        if p is None:
            missing.append(caption)
            continue
        insert_image_before_caption(p, path)
    if missing:
        raise RuntimeError("ไม่พบคำบรรยายภาพ:\n- " + "\n- ".join(missing))

    doc.save(str(DST))
    shutil.copy2(DST, DOCS_COPY)
    print("Saved chapter 3 docx")
    print("Figures", len(FIGURES))


if __name__ == "__main__":
    main()
