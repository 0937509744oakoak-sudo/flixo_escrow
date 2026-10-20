# -*- coding: utf-8 -*-
"""Generate FLIXO Chapter 4 system-framing PowerPoint (thesis pitch)."""

from __future__ import annotations

from pathlib import Path

from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.util import Inches, Pt, Emu

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "FLIXO_Chapter4_System_Framing.pptx"

# Academic navy / slate (avoid purple-default AI look)
NAVY = RGBColor(0x1B, 0x3A, 0x4B)
TEAL = RGBColor(0x2C, 0x6E, 0x8A)
SLATE = RGBColor(0x3D, 0x4F, 0x5F)
INK = RGBColor(0x1A, 0x1A, 0x1A)
MUTED = RGBColor(0x5A, 0x6A, 0x75)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT = RGBColor(0xF3, 0xF6, 0xF8)
ACCENT = RGBColor(0xC4, 0x7B, 0x2D)  # muted amber


def set_run(run, size=18, bold=False, color=INK, font="TH Sarabun New"):
    run.font.name = font
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.color.rgb = color


def add_bg(slide, color=LIGHT):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(7.5)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = color
    shape.line.fill.background()
    # send to back
    spTree = slide.shapes._spTree
    sp = shape._element
    spTree.remove(sp)
    spTree.insert(2, sp)


def add_bar(slide, color=NAVY):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(0.18)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = color
    shape.line.fill.background()


def add_footer(slide, page: int, total: int):
    box = slide.shapes.add_textbox(Inches(0.5), Inches(7.05), Inches(12.3), Inches(0.35))
    tf = box.text_frame
    tf.clear()
    p = tf.paragraphs[0]
    run = p.add_run()
    run.text = f"FLIXO Escrow  ·  กรอบการออกแบบเชิงระบบ  ·  {page}/{total}"
    set_run(run, 12, False, MUTED)
    p.alignment = PP_ALIGN.RIGHT


def textbox(slide, left, top, width, height, text, size=18, bold=False, color=INK, align=PP_ALIGN.LEFT):
    box = slide.shapes.add_textbox(Inches(left), Inches(top), Inches(width), Inches(height))
    tf = box.text_frame
    tf.word_wrap = True
    tf.clear()
    p = tf.paragraphs[0]
    p.alignment = align
    run = p.add_run()
    run.text = text
    set_run(run, size, bold, color)
    return box


def bullets(slide, left, top, width, height, items, size=16, color=INK):
    box = slide.shapes.add_textbox(Inches(left), Inches(top), Inches(width), Inches(height))
    tf = box.text_frame
    tf.word_wrap = True
    tf.clear()
    for i, item in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.level = 0
        p.space_after = Pt(6)
        run = p.add_run()
        run.text = f"•  {item}"
        set_run(run, size, False, color)
    return box


def card(slide, left, top, width, height, title, body_lines, title_color=TEAL):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.ROUNDED_RECTANGLE, Inches(left), Inches(top), Inches(width), Inches(height)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = WHITE
    shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
    shape.adjustments[0] = 0.08

    textbox(slide, left + 0.2, top + 0.15, width - 0.35, 0.4, title, 16, True, title_color)
    bullets(slide, left + 0.15, top + 0.55, width - 0.3, height - 0.7, body_lines, 14, SLATE)


def title_slide(prs, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide, NAVY)
    add_bar(slide, ACCENT)
    textbox(slide, 0.8, 2.0, 11.5, 0.5, "โครงการ FLIXO Escrow", 22, False, RGBColor(0xB8, 0xC9, 0xD4), PP_ALIGN.LEFT)
    textbox(slide, 0.8, 2.6, 11.5, 1.2, "กรอบการออกแบบเชิงระบบ", 40, True, WHITE, PP_ALIGN.LEFT)
    textbox(
        slide,
        0.8,
        4.0,
        11.5,
        1.0,
        "5 ประเด็นหลัก  ·  การเชื่อม API  ·  มุมมอง 3 ระดับ\nSoftware Engineer  ·  E-commerce / Fintech  ·  Tech StartUp",
        18,
        False,
        RGBColor(0xC9, 0xD7, 0xE0),
        PP_ALIGN.LEFT,
    )
    textbox(slide, 0.8, 6.4, 11.5, 0.4, "บทที่ 4 ผลการทดลองและการใช้งานจริง", 14, False, RGBColor(0x9A, 0xB0, 0xBD))
    add_footer(slide, 1, total)


def overview_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "FLIXO เป็นแกนกลางของระบบ", 28, True, NAVY)
    textbox(
        slide,
        0.6,
        1.0,
        12,
        0.7,
        "แพลตฟอร์มตัวกลางพักเงินที่มีชั้นป้องกันความเสี่ยงหลายระดับ เชื่อมต่อเป็นกระบวนการเดียว",
        16,
        False,
        SLATE,
    )
    steps = [
        ("1", "เข้าสู่ระบบ", "Password / Google"),
        ("2", "eKYC", "Thai ID + Face"),
        ("3", "ลงประกาศ", "EXIF + Vision"),
        ("4", "แชท", "Regex + LLM"),
        ("5", "ชำระเงิน", "Omise / EasySlip"),
        ("6", "ข้อพิพาท", "Dispute AI"),
    ]
    x0 = 0.55
    for i, (n, t, s) in enumerate(steps):
        x = x0 + i * 2.1
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(2.1), Inches(1.95), Inches(2.2))
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
        textbox(slide, x + 0.1, 2.25, 1.75, 0.4, n, 22, True, TEAL, PP_ALIGN.CENTER)
        textbox(slide, x + 0.1, 2.85, 1.75, 0.5, t, 16, True, NAVY, PP_ALIGN.CENTER)
        textbox(slide, x + 0.1, 3.45, 1.75, 0.6, s, 13, False, MUTED, PP_ALIGN.CENTER)
        if i < len(steps) - 1:
            textbox(slide, x + 1.85, 2.85, 0.3, 0.4, "→", 18, True, TEAL, PP_ALIGN.CENTER)
    textbox(
        slide,
        0.6,
        4.7,
        12,
        1.5,
        "Authentication แยกจาก eKYC อย่างชัดเจน\n"
        "Login = รหัสผ่าน / Google Sign-In\n"
        "eKYC = ยืนยันว่าบุคคลตรงกับบัตรประชาชนไทย (OCR + Face Compare)",
        16,
        False,
        SLATE,
    )
    add_footer(slide, page, total)


def five_pillars_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "กรอบ 5 ประเด็นหลัก", 28, True, NAVY)
    pillars = [
        ("1", "ปัญหาที่ต้องแก้", "ตัวตนปลอม รูปคัดลอก\nโอนนอกระบบ สลิปผิด\nข้อพิพาท"),
        ("2", "เทคนิคและการเชื่อม", "API เชื่อมต่อเป็นชั้น\nOCR→Face, EXIF→Vision\nRegex→LLM"),
        ("3", "กระบวนการ AI", "ดึงข้อมูลจากบัตร ใบหน้า\nรูป ข้อความ หลักฐาน\nบันทึกสถานะธุรกรรม"),
        ("4", "วิธีทดลองวัดผล", "Mock N=100 + Live\nAcc / P / R / ROC\nκ McNemar UAT"),
        ("5", "ออกแบบรองรับงาน", "Fallback ครบชั้น\nสามชั้นสถาปัตยกรรม\nทำงานต่อเมื่อ API ล่ม"),
    ]
    for i, (n, title, body) in enumerate(pillars):
        x = 0.45 + i * 2.55
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(1.3), Inches(2.4), Inches(4.8))
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
        badge = slide.shapes.add_shape(MSO_SHAPE.OVAL, Inches(x + 0.85), Inches(1.55), Inches(0.7), Inches(0.7))
        badge.fill.solid()
        badge.fill.fore_color.rgb = TEAL if i % 2 == 0 else NAVY
        badge.line.fill.background()
        textbox(slide, x + 0.85, 1.65, 0.7, 0.5, n, 20, True, WHITE, PP_ALIGN.CENTER)
        textbox(slide, x + 0.15, 2.5, 2.1, 0.9, title, 15, True, NAVY, PP_ALIGN.CENTER)
        textbox(slide, x + 0.15, 3.5, 2.1, 2.2, body, 13, False, SLATE, PP_ALIGN.CENTER)
    add_footer(slide, page, total)


def problem_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "1. ปัญหาที่ต้องแก้", 28, True, NAVY)
    problems = [
        ("ตัวตน / เอกสารปลอม", "แอบอ้างบุคคลอื่นในตลาด C2C"),
        ("รูปสินค้าไม่น่าเชื่อถือ", "ภาพหน้าจอหรือภาพคัดลอกจากแหล่งอื่น"),
        ("โอนเงินนอกระบบ", "ชักชวน Line / บัญชี / พร้อมเพย์นอกแอป"),
        ("สลิป / การชำระผิดพลาด", "ยอดไม่ตรง หรือสลิปปลอม"),
        ("ข้อพิพาทกระจัดกระจาย", "แอดมินต้องตัดสินจากหลักฐานหลายแหล่ง"),
    ]
    for i, (t, d) in enumerate(problems):
        y = 1.2 + i * 1.0
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.6), Inches(y), Inches(12.1), Inches(0.85))
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
        num = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.8), Inches(y + 0.18), Inches(0.55), Inches(0.5))
        num.fill.solid()
        num.fill.fore_color.rgb = TEAL
        num.line.fill.background()
        textbox(slide, 0.8, y + 0.22, 0.55, 0.45, str(i + 1), 16, True, WHITE, PP_ALIGN.CENTER)
        textbox(slide, 1.55, y + 0.12, 10.8, 0.35, t, 18, True, NAVY)
        textbox(slide, 1.55, y + 0.45, 10.8, 0.35, d, 14, False, SLATE)
    add_footer(slide, page, total)


def api_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "2. เทคนิคและการเชื่อม API", 28, True, NAVY)
    rows = [
        ("อ่านบัตร ปชช. ไทย", "ภาพบัตร", "AiGen Thai ID OCR", "เลขบัตร / ชื่อ"),
        ("ตรวจใบหน้า", "ใบหน้าบนบัตร + กล้อง", "AiGen Face Compare", "อนุมัติ / ปฏิเสธ eKYC"),
        ("ตรวจรูปสินค้า", "ภาพประกาศ", "EXIF + Cloud Vision", "คะแนนความเสี่ยง"),
        ("กลั่นกรองแชท", "ข้อความ / ภาพ", "Regex → Groq/LLM", "ผ่าน / Flag / บล็อก"),
        ("ตรวจสลิป", "ภาพสลิป", "EasySlip + Omise", "สถานะคำสั่งซื้อ"),
        ("เข้าสู่ระบบ", "ID Token", "Google OAuth Certs", "สร้าง / เข้าบัญชี"),
    ]
    headers = ["กระบวนการ", "อินพุต", "API ที่เชื่อม", "ผลที่ได้"]
    col_w = [2.8, 3.2, 3.5, 2.6]
    x0, y0 = 0.55, 1.15
    # header
    x = x0
    for h, w in zip(headers, col_w):
        shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y0), Inches(w), Inches(0.55))
        shape.fill.solid()
        shape.fill.fore_color.rgb = NAVY
        shape.line.fill.background()
        textbox(slide, x + 0.08, y0 + 0.1, w - 0.1, 0.4, h, 14, True, WHITE)
        x += w
    for ri, row in enumerate(rows):
        y = y0 + 0.55 + ri * 0.75
        x = x0
        bg = WHITE if ri % 2 == 0 else RGBColor(0xE8, 0xEE, 0xF2)
        for cell, w in zip(row, col_w):
            shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y), Inches(w), Inches(0.75))
            shape.fill.solid()
            shape.fill.fore_color.rgb = bg
            shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
            textbox(slide, x + 0.08, y + 0.18, w - 0.1, 0.45, cell, 13, False, INK)
            x += w
    add_footer(slide, page, total)


def face_thai_id_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "ตัวอย่างแกน: ใบหน้าเชื่อมกับบัตรประชาชนไทย", 26, True, NAVY)
    textbox(
        slide,
        0.6,
        1.0,
        12,
        0.5,
        "ไม่ใช้สแกนใบหน้าแทนการล็อกอิน — ใช้ยืนยันว่าบุคคลตรงกับบัตร ปชช. ไทย",
        16,
        False,
        SLATE,
    )
    flow = [
        ("1", "อัปโหลดบัตร", "ภาพบัตรประชาชน"),
        ("2", "Thai ID OCR", "AiGen /aiscript/idcard/v3"),
        ("3", "ได้เลขบัตร+ชื่อ", "Validate ฟิลด์ครบ"),
        ("4", "Face Compare", "เทียบกับภาพกล้อง\nเกณฑ์คะแนน 80"),
        ("5", "ผล eKYC", "อนุมัติ / ปฏิเสธ\nหรือ Manual"),
    ]
    for i, (n, t, d) in enumerate(flow):
        x = 0.45 + i * 2.55
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(2.0), Inches(2.35), Inches(3.2))
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
        textbox(slide, x + 0.1, 2.2, 2.15, 0.45, n, 22, True, TEAL, PP_ALIGN.CENTER)
        textbox(slide, x + 0.1, 2.8, 2.15, 0.7, t, 16, True, NAVY, PP_ALIGN.CENTER)
        textbox(slide, x + 0.1, 3.6, 2.15, 1.3, d, 13, False, SLATE, PP_ALIGN.CENTER)
        if i < len(flow) - 1:
            textbox(slide, x + 2.2, 3.2, 0.4, 0.4, "→", 20, True, ACCENT, PP_ALIGN.CENTER)
    textbox(
        slide,
        0.6,
        5.5,
        12,
        1.0,
        "Fallback: OCR/Face ล้มเหลว → ปฏิเสธหรือส่ง Manual Review\nทางเลือกขนาน: Sumsub Identity + Liveness",
        15,
        False,
        SLATE,
    )
    add_footer(slide, page, total)


def ai_process_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "3. กระบวนการหลัก AI และการดึงข้อมูล", 26, True, NAVY)
    items = [
        ("AiGen Thai ID OCR", "ดึงเลขบัตรและชื่อจากภาพบัตรประชาชนไทย"),
        ("AiGen Face Compare", "ดึงคะแนนความคล้ายใบหน้าบนบัตรกับภาพจากกล้อง"),
        ("Sumsub", "ดึงผล Identity / Liveness ผ่านโทเคนและสถานะผู้สมัคร"),
        ("รูปสินค้า", "ดึง EXIF + ผลวิเคราะห์จาก Cloud Vision"),
        ("แชท", "ดึงข้อความ (+ OCR จากภาพ) แล้วให้คะแนนความเสี่ยงด้วย LLM"),
        ("Dispute AI", "ดึงข้อความ พัสดุ หลักฐาน → สรุปคำแนะนำให้แอดมิน"),
    ]
    for i, (t, d) in enumerate(items):
        col = i % 2
        row = i // 2
        x = 0.55 + col * 6.35
        y = 1.2 + row * 1.7
        card(slide, x, y, 6.1, 1.5, t, [d])
    add_footer(slide, page, total)


def experiment_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "4. วิธีทดลองและวัดผล", 28, True, NAVY)
    cards = [
        ("Mock N=100", ["ทดลองเชิงปริมาณต่อโมดูลหลัก", "ยืนยันความถูกต้องของ pipeline"]),
        ("ตัวชี้วัด", ["Confusion Matrix", "Accuracy / Precision / Recall", "Latency Avg & P95"]),
        ("Live บางส่วน", ["eKYC ภาพจริง", "Chat ด้วย LLM จริง", "Image Fraud ภาพจริง + ROC/PR"]),
        ("สถิติและระบบ", ["Wilson CI, Cohen κ, McNemar", "Resilience 10/10", "UAT 12/12"]),
    ]
    for i, (t, lines) in enumerate(cards):
        x = 0.5 + (i % 4) * 3.2
        card(slide, x, 1.3, 3.05, 3.6, t, lines, TEAL if i % 2 == 0 else NAVY)
    textbox(
        slide,
        0.6,
        5.3,
        12,
        1.2,
        "ข้อควรตระหนัก: ค่าความถูกต้องสูงใน Mock สะท้อนความถูกต้องของกระบวนการประมวลผล\n"
        "ไม่ใช่การรับรองความแม่นยำของโมเดลบนประชากรผู้ใช้จริง",
        15,
        True,
        ACCENT,
    )
    add_footer(slide, page, total)


def design_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "5. การออกแบบเชิงระบบที่รองรับการทำงาน", 26, True, NAVY)
    layers = [
        ("Presentation", "PHP Views + JS\nกล้อง / SDK / UI"),
        ("Application", "Controllers + Services\nกฎธุรกิจ + Fallback"),
        ("Data", "MySQL + Uploads\nสถานะและหลักฐาน"),
    ]
    for i, (t, d) in enumerate(layers):
        x = 0.7 + i * 4.1
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(1.3), Inches(3.8), Inches(2.0))
        shape.fill.solid()
        shape.fill.fore_color.rgb = NAVY if i == 1 else TEAL
        shape.line.fill.background()
        textbox(slide, x + 0.2, 1.55, 3.4, 0.5, t, 20, True, WHITE, PP_ALIGN.CENTER)
        textbox(slide, x + 0.2, 2.2, 3.4, 0.9, d, 14, False, RGBColor(0xE0, 0xEC, 0xF2), PP_ALIGN.CENTER)
    textbox(slide, 0.6, 3.7, 12, 0.4, "ทางเลือกรองเมื่อบริการภายนอกล้มเหลว", 18, True, NAVY)
    bullets(
        slide,
        0.7,
        4.2,
        12,
        2.2,
        [
            "eKYC → Manual Admin Review",
            "รูปสินค้า → EXIF-only flag",
            "แชท → Regex-only",
            "ข้อพิพาท → Heuristic / NEEDS_REVIEW",
            "สลิป → WAITING_VERIFY ให้แอดมินตรวจ",
        ],
        15,
        SLATE,
    )
    add_footer(slide, page, total)


def three_levels_overview(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "มุมมองสามระดับต่อแกนออกแบบ", 28, True, NAVY)
    levels = [
        ("1", "Software Engineer", "เชื่อม API และควบคุม pipeline อย่างไร", NAVY),
        ("2", "E-commerce / Fintech", "ลดความเสี่ยงรอบธุรกรรมได้อย่างไร", TEAL),
        ("3", "Tech StartUp", "ส่งมอบและสเกลชั้น AI ได้เร็วเพียงใด", ACCENT),
    ]
    for i, (n, title, q, color) in enumerate(levels):
        y = 1.3 + i * 1.7
        shape = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.6), Inches(y), Inches(12.1), Inches(1.5))
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
        badge = slide.shapes.add_shape(MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.85), Inches(y + 0.35), Inches(1.2), Inches(0.8))
        badge.fill.solid()
        badge.fill.fore_color.rgb = color
        badge.line.fill.background()
        textbox(slide, 0.85, y + 0.5, 1.2, 0.5, n, 24, True, WHITE, PP_ALIGN.CENTER)
        textbox(slide, 2.3, y + 0.3, 10, 0.5, title, 22, True, NAVY)
        textbox(slide, 2.3, y + 0.85, 10, 0.45, q, 16, False, SLATE)
    add_footer(slide, page, total)


def level_detail_slides(prs, start_page, total):
    details = [
        (
            "ระดับที่ 1  Software Engineer",
            [
                "สถาปัตยกรรมสามชั้น + Provider Chain + Threshold + Fallback",
                "ตัวอย่าง: Thai ID OCR → Face Compare (เกณฑ์ 80)",
                "EXIF ↔ Cloud Vision · Regex ↔ Groq/OpenRouter/Gemini",
                "แยก Authentication ออกจาก eKYC ชัดเจน",
                "วัดผลด้วย Latency, Resilience, ความถูกต้องของ pipeline",
            ],
            "หลักฐานบทที่ 4: ผลรายโมดูล · Latency · Resilience 10/10",
        ),
        (
            "ระดับที่ 2  E-commerce / Fintech",
            [
                "ตัวกลางพักเงิน + ชั้นความเชื่อถือรอบธุรกรรม",
                "ยืนยันตัวตนด้วยบัตร ปชช. ไทยและใบหน้า",
                "กันรูปคัดลอก · กันโอนนอกระบบ · ตรวจสลิป",
                "ช่วยแอดมินวิเคราะห์ข้อพิพาทโดยไม่ตัดสินแทนมนุษย์",
                "รักษาความต่อเนื่องของธุรกรรมเมื่อต้องส่งต่อให้คนตรวจ",
            ],
            "หลักฐานบทที่ 4: Acc / Precision / Recall · Live บางโมดูล · UAT 12/12",
        ),
        (
            "ระดับที่ 3  Tech StartUp",
            [
                "ผลิตภัณฑ์ตั้งต้นที่ใช้ AI/API สำเร็จรูปเป็นชั้นป้องกันความเสี่ยง",
                "ไม่ต้องฝึกโมเดลเองทั้งหมด — กำหนดเกณฑ์และลำดับผู้ให้บริการ",
                "สลับผู้ให้บริการได้ เช่น AiGen ↔ Sumsub, Groq ↔ OpenRouter",
                "เน้นความเร็วส่งมอบ ความคุ้มค่า และทำงานต่อเมื่อคลาวด์ล่ม",
                "เหมาะกับการทดลองใช้จริงและการขยายบริการทีละขั้น",
            ],
            "หลักฐานบทที่ 4: Fallback ครบชั้น · อภิปรายขอบเขต Mock และ Live",
        ),
    ]
    for i, (title, items, evidence) in enumerate(details):
        slide = prs.slides.add_slide(prs.slide_layouts[6])
        add_bg(slide)
        add_bar(slide)
        textbox(slide, 0.6, 0.4, 12, 0.5, title, 26, True, NAVY)
        bullets(slide, 0.8, 1.3, 11.5, 4.0, items, 18, SLATE)
        shape = slide.shapes.add_shape(
            MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.6), Inches(5.5), Inches(12.1), Inches(0.95)
        )
        shape.fill.solid()
        shape.fill.fore_color.rgb = NAVY
        shape.line.fill.background()
        textbox(slide, 0.85, 5.7, 11.6, 0.6, evidence, 16, True, WHITE)
        add_footer(slide, start_page + i, total)


def summary_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide)
    add_bar(slide)
    textbox(slide, 0.6, 0.4, 12, 0.5, "สรุปสามระดับในตารางเดียว", 28, True, NAVY)
    rows = [
        ("Software Engineer", "เชื่อม API / ควบคุม pipeline", "สามชั้น + Chain + Fallback", "โมดูล · Latency · Resilience"),
        ("E-commerce / Fintech", "ลดความเสี่ยงรอบธุรกรรม", "eKYC รูป แชท สลิป ข้อพิพาท", "Acc P/R · Live · UAT"),
        ("Tech StartUp", "ส่งมอบและสเกลชั้น AI", "API สำเร็จรูป สลับผู้ให้บริการได้", "Fallback · Mock/Live scope"),
    ]
    headers = ["ระดับ", "คำถามหลัก", "สิ่งที่ FLIXO ตอบ", "หลักฐานบทที่ 4"]
    col_w = [3.0, 3.3, 3.4, 2.4]
    x0, y0 = 0.55, 1.2
    x = x0
    for h, w in zip(headers, col_w):
        shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y0), Inches(w), Inches(0.6))
        shape.fill.solid()
        shape.fill.fore_color.rgb = NAVY
        shape.line.fill.background()
        textbox(slide, x + 0.08, y0 + 0.12, w - 0.1, 0.4, h, 14, True, WHITE)
        x += w
    for ri, row in enumerate(rows):
        y = y0 + 0.6 + ri * 1.35
        x = x0
        bg = WHITE if ri % 2 == 0 else RGBColor(0xE8, 0xEE, 0xF2)
        for cell, w in zip(row, col_w):
            shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y), Inches(w), Inches(1.35))
            shape.fill.solid()
            shape.fill.fore_color.rgb = bg
            shape.line.color.rgb = RGBColor(0xD5, 0xDE, 0xE5)
            textbox(slide, x + 0.1, y + 0.4, w - 0.15, 0.6, cell, 14, False, INK)
            x += w
    add_footer(slide, page, total)


def closing_slide(prs, page, total):
    slide = prs.slides.add_slide(prs.slide_layouts[6])
    add_bg(slide, NAVY)
    add_bar(slide, ACCENT)
    textbox(slide, 0.8, 2.0, 11.5, 0.6, "บทสรุปสำหรับนำเสนอ", 18, False, RGBColor(0xB8, 0xC9, 0xD4))
    textbox(
        slide,
        0.8,
        2.6,
        11.5,
        2.0,
        "FLIXO Escrow เป็นแกนออกแบบเชิงระบบ\nที่เชื่อมปัญหา เทคนิค API กระบวนการ AI\nวิธีวัดผล และการทำงานต่อเนื่องไว้ด้วยกัน",
        28,
        True,
        WHITE,
    )
    textbox(
        slide,
        0.8,
        5.0,
        11.5,
        1.0,
        "อ่านได้พร้อมกันสามระดับ: Software Engineer · E-commerce/Fintech · Tech StartUp\nหลักฐานเชิงประจักษ์อยู่ในบทที่ 4",
        16,
        False,
        RGBColor(0xC9, 0xD7, 0xE0),
    )
    add_footer(slide, page, total)


def main():
    prs = Presentation()
    prs.slide_width = Inches(13.333)
    prs.slide_height = Inches(7.5)
    total = 15
    title_slide(prs, total)
    overview_slide(prs, 2, total)
    five_pillars_slide(prs, 3, total)
    problem_slide(prs, 4, total)
    api_slide(prs, 5, total)
    face_thai_id_slide(prs, 6, total)
    ai_process_slide(prs, 7, total)
    experiment_slide(prs, 8, total)
    design_slide(prs, 9, total)
    three_levels_overview(prs, 10, total)
    level_detail_slides(prs, 11, total)
    summary_slide(prs, 14, total)
    closing_slide(prs, 15, total)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    prs.save(str(OUT))
    print(f"Wrote {OUT}")


if __name__ == "__main__":
    main()
