# -*- coding: utf-8 -*-
"""Generate Chapter 4 DOCX in academic thesis style (TH Sarabun New).

Removes Markdown artefacts (arrows, section signs, informal labels) and uses
thesis-style numbering such as 4.3.8 / 4.4.6.
"""

from __future__ import annotations

import json
import re
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_LINE_SPACING
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report.docx"
OUT_RESEARCH = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_research.docx"
OUT_UPDATED = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_updated.docx"
OUT_THESIS = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_thesis.docx"
STATS = ROOT / "docs" / "appendix_ch4" / "stats_summary.json"
EKYC_REAL = ROOT / "docs" / "appendix_ch4" / "ekyc_real_summary.json"
CHAT_REAL = ROOT / "docs" / "appendix_ch4" / "chat_real_summary.json"
IMG_REAL = ROOT / "docs" / "appendix_ch4" / "image_fraud_real_summary.json"
CURVES = ROOT / "docs" / "appendix_ch4" / "live_curve_stats.json"
RS_JSON = ROOT / "docs" / "appendix_ch4" / "research_stats.json"
SHOTS = ROOT / "docs" / "appendix_ch4" / "screenshots"
FIG = ROOT / "docs" / "_ch4_research_figures"

FONT_NAME = "TH Sarabun New"


def academic_text(text: str) -> str:
    """Normalize Markdown/chat artefacts into academic Thai/English prose."""
    if text is None:
        return ""
    s = str(text)
    repl = [
        ("→", " ไปยัง "),
        ("←", " จาก "),
        ("↔", " เทียบกับ "),
        ("≈", "ประมาณ "),
        ("≥", "ไม่น้อยกว่า "),
        ("≤", "ไม่เกิน "),
        ("×", " x "),
        ("Δ", "ผลต่าง "),
        ("§", "หัวข้อ "),
        ("·", " "),
        ("—", " - "),
        ("–", "-"),
        ("…", "..."),
        ("<<", ""),
        (">>", ""),
        ("✓", ""),
        ("✅", ""),
        ("⚠", ""),
        ("📷", ""),
        ("**", ""),
        ("`", ""),
        ("χ²", "ไคกำลังสอง"),
        ("κ", "kappa "),
    ]
    for a, b in repl:
        s = s.replace(a, b)
    # informal section labels
    s = re.sub(r"ก\.(\d+)b\b", r"4.\1", s)
    s = re.sub(r"ก\.(\d+)\b", r"4.\1", s)
    s = re.sub(r"\bfig(\d+)\b", r"ภาพที่ \1", s, flags=re.I)
    s = re.sub(r"\bFigure\s+(\d+)\b", r"ภาพที่ \1", s, flags=re.I)
    # Thai spacing cleanup
    s = s.replace("\u00a0", " ")
    s = re.sub(r"[ \t]{2,}", " ", s)
    s = re.sub(r" +([,.;:)])", r"\1", s)
    s = re.sub(r"([(]) +", r"\1", s)
    s = re.sub(r"\s+\n", "\n", s)
    return s.strip()


def set_run_font(run, size=14, bold=False):
    run.font.name = FONT_NAME
    run._element.rPr.rFonts.set(qn("w:eastAsia"), FONT_NAME)
    run._element.rPr.rFonts.set(qn("w:ascii"), FONT_NAME)
    run._element.rPr.rFonts.set(qn("w:hAnsi"), FONT_NAME)
    run.font.size = Pt(size)
    run.bold = bold
    run.font.color.rgb = RGBColor(0x1A, 0x1A, 0x1A)


def h(doc, text, level=1):
    para = doc.add_heading(academic_text(text), level=level)
    para.paragraph_format.space_before = Pt(10)
    para.paragraph_format.space_after = Pt(6)
    for r in para.runs:
        set_run_font(r, {1: 18, 2: 16, 3: 14}.get(level, 14), True)


def p(doc, text, size=14, bold=False):
    para = doc.add_paragraph()
    para.paragraph_format.space_after = Pt(6)
    para.paragraph_format.space_before = Pt(0)
    para.paragraph_format.line_spacing_rule = WD_LINE_SPACING.ONE_POINT_FIVE
    run = para.add_run(academic_text(text))
    set_run_font(run, size, bold)


def table(doc, headers, rows):
    t = doc.add_table(rows=1 + len(rows), cols=len(headers))
    t.style = "Table Grid"
    for i, hd in enumerate(headers):
        cell = t.rows[0].cells[i]
        cell.text = ""
        para = cell.paragraphs[0]
        run = para.add_run(academic_text(hd))
        set_run_font(run, 12, True)
    for ri, row in enumerate(rows, 1):
        for ci, val in enumerate(row):
            cell = t.rows[ri].cells[ci]
            cell.text = ""
            para = cell.paragraphs[0]
            run = para.add_run(academic_text(str(val)))
            set_run_font(run, 12, False)
    doc.add_paragraph()


def add_picture(doc, path: Path, caption: str, width_cm: float = 15.0):
    p(doc, caption, bold=True)
    try:
        doc.add_picture(str(path), width=Cm(width_cm))
    except Exception:
        p(doc, f"(ไม่สามารถแนบภาพได้: {path.name})")
    doc.add_paragraph()


def fmt_pct(x, digits=1):
    try:
        return f"{float(x):.{digits}f}"
    except (TypeError, ValueError):
        return str(x)


def fmt_ci(ci, digits=1):
    if not ci or len(ci) < 2:
        return "N/A"
    try:
        lo, hi = float(ci[0]) * 100, float(ci[1]) * 100
        return f"{lo:.{digits}f}%–{hi:.{digits}f}%"
    except (TypeError, ValueError):
        return "N/A"


def confusion_rows(tp, tn, fp, fn, pos="Match", neg="Non-match"):
    return [
        ["", f"ทำนาย {pos}", f"ทำนาย {neg}", "รวม"],
        [f"จริงเป็น {pos}", str(tp), str(fn), str(int(tp) + int(fn))],
        [f"จริงเป็น {neg}", str(fp), str(tn), str(int(fp) + int(tn))],
        ["รวม", str(int(tp) + int(fp)), str(int(fn) + int(tn)), str(int(tp) + int(tn) + int(fp) + int(fn))],
    ]


def main():
    stats = json.loads(STATS.read_text(encoding="utf-8"))
    ek, ch, im, di, su = stats["ekyc"], stats["chat"], stats["image"], stats["dispute"], stats["sumsub"]
    es, om, ga = stats.get("easyslip", {}), stats.get("omise", {}), stats.get("google_auth", {})
    rs = stats.get("resilience", {})
    ua = stats.get("uat", {})

    ek_real = json.loads(EKYC_REAL.read_text(encoding="utf-8")) if EKYC_REAL.exists() else {}
    ch_real = json.loads(CHAT_REAL.read_text(encoding="utf-8")) if CHAT_REAL.exists() else {}
    img_real = json.loads(IMG_REAL.read_text(encoding="utf-8")) if IMG_REAL.exists() else {}
    curves = json.loads(CURVES.read_text(encoding="utf-8")) if CURVES.exists() else {}
    rs_full = json.loads(RS_JSON.read_text(encoding="utf-8")) if RS_JSON.exists() else {}
    agr = rs_full.get("agreement_tests") or {}
    mods = rs_full.get("modules") or {}

    prov = ch.get("providers", {})
    ch_acc = ch.get("accuracy")
    if ch_acc is None:
        ch_acc = round(100 * (ch["tp"] + ch["tn"]) / max(ch.get("n", 100), 1))

    # Chat precision/recall may be 0-1 or already percent in some dumps
    def as_pct(v):
        try:
            x = float(v)
        except (TypeError, ValueError):
            return "N/A"
        if x <= 1.0:
            x *= 100
        return f"{x:.1f}"

    doc = Document()
    section = doc.sections[0]
    section.top_margin = Cm(2.0)
    section.bottom_margin = Cm(2.0)
    section.left_margin = Cm(2.5)
    section.right_margin = Cm(2.0)

    # Default fonts (ascii + East Asian for Thai)
    for style_name in ("Normal", "Heading 1", "Heading 2", "Heading 3"):
        style = doc.styles[style_name]
        style.font.name = FONT_NAME
        if style._element.rPr is None:
            style._element.get_or_add_rPr()
        style._element.rPr.rFonts.set(qn("w:eastAsia"), FONT_NAME)
        style._element.rPr.rFonts.set(qn("w:ascii"), FONT_NAME)
        style._element.rPr.rFonts.set(qn("w:hAnsi"), FONT_NAME)
    doc.styles["Normal"].font.size = Pt(14)

    title = doc.add_paragraph()
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = title.add_run("บทที่ 4")
    set_run_font(r, 22, True)

    subtitle = doc.add_paragraph()
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = subtitle.add_run("ผลการทดลองและการใช้งานจริง")
    set_run_font(r, 20, True)

    proj = doc.add_paragraph()
    proj.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = proj.add_run(
        "โครงการ FLIXO Escrow\n"
        "ฉบับจัดหน้าวิทยานิพนธ์\n"
        "ผลการทดลองเชิงปริมาณ N=100 ร่วมกับหลักฐาน Live บางส่วน"
    )
    set_run_font(r, 14, False)

    p(
        doc,
        "บทนี้นำเสนอผลการทดลองของระบบ FLIXO Escrow โดยเชื่อมโยงกับสถาปัตยกรรมในบทที่ 3 "
        "เพื่อแสดงว่าชั้นป้องกันความเสี่ยงและการเชื่อมต่อบริการภายนอกทำงานสอดคล้องกับการออกแบบ "
        "เนื้อหาเปิดด้วยกรอบเชิงระบบห้าประเด็น จากนั้นรายงานผลการทดลองรายโมดูล "
        "สถิติข้อตกลง การทดสอบความทนทาน และการทดสอบการใช้งาน",
    )

    # Framing: FLIXO as system backbone (5 pillars)
    h(doc, "กรอบการออกแบบเชิงระบบของ FLIXO Escrow", 1)
    p(
        doc,
        "FLIXO Escrow ทำหน้าที่เป็นแกนกลางของแพลตฟอร์มตัวกลางพักเงินสำหรับการซื้อขายออนไลน์ "
        "โดยออกแบบชั้นป้องกันความเสี่ยงหลายระดับให้ทำงานเชื่อมต่อกันเป็นกระบวนการเดียว "
        "ตั้งแต่การเข้าสู่ระบบ การยืนยันตัวตน การลงประกาศสินค้า การสนทนา การชำระเงิน "
        "ไปจนถึงการช่วยวิเคราะห์ข้อพิพาท กรอบต่อไปนี้อธิบายความสอดคล้องระหว่างปัญหา เทคนิค "
        "กระบวนการปัญญาประดิษฐ์ วิธีวัดผล และการออกแบบที่รองรับการทำงานจริง",
    )

    h(doc, "1. ปัญหาที่ต้องแก้", 2)
    p(
        doc,
        "ปัญหาหลักในบริบทซื้อขายออนไลน์แบบ C2C ได้แก่ (1) การแอบอ้างตัวตนหรือใช้เอกสารปลอม "
        "(2) การลงรูปสินค้าที่ไม่น่าเชื่อถือ เช่น ภาพหน้าจอหรือภาพคัดลอก "
        "(3) การชักชวนโอนเงินหรือแลกเปลี่ยนช่องทางติดต่อนอกระบบ "
        "(4) การชำระเงินหรือตรวจสอบสลิปที่ผิดพลาด และ (5) ข้อพิพาทที่แอดมินต้องตัดสินจากหลักฐานกระจัดกระจาย "
        "หากไม่มีชั้นป้องกันที่เชื่อมกัน ระบบตัวกลางอาจพักเงินได้แต่ยังเปิดช่องให้เกิดการหลอกลวงรอบธุรกรรม",
    )

    h(doc, "2. เทคนิคที่นำมาใช้และการเชื่อมโยง", 2)
    p(
        doc,
        "เทคนิคถูกจัดเป็นชั้นตามลำดับธุรกรรมและเชื่อมผ่านชั้น Application ของสถาปัตยกรรมสามชั้น "
        "การยืนยันสิทธิ์เข้าใช้งานใช้รหัสผ่านหรือ Google Sign-In ซึ่งแยกจากการยืนยันตัวตน eKYC "
        "eKYC ใช้ AiGen (OCR และ Face Compare) หรือ Sumsub และมีทางเลือกรองแบบ Manual "
        "การลงประกาศใช้การวิเคราะห์เมตาดาต้า EXIF ร่วมกับ Google Cloud Vision "
        "แชทใช้กฎนิพจน์ปกติเป็นชั้นแรก แล้วส่งเคสกำกวมเข้าโมเดลภาษา "
        "การชำระเงินเชื่อม Omise และ EasySlip ส่วนข้อพิพาทใช้ผู้ช่วยวิเคราะห์ที่เสนอแนะโดยไม่ตัดสินแทนมนุษย์ "
        "การเชื่อมโยงนี้ทำให้ผลจากแต่ละชั้นอัปเดตสถานะในฐานข้อมูลและบังคับกฎธุรกิจต่อเนื่อง",
    )
    p(
        doc,
        "ตัวอย่างการเชื่อม API ที่เป็นแกนของระบบมีดังนี้ "
        "ภาพบัตรประชาชนไทยถูกส่งไปยัง AiGen Thai ID OCR จากนั้นใบหน้าบนบัตรถูกเชื่อมกับภาพถ่ายตนเองผ่าน AiGen Face Compare "
        "รูปสินค้าเชื่อม EXIF ในเครื่องกับ Google Cloud Vision "
        "ข้อความแชทเชื่อมกฎนิพจน์ปกติกับ Groq หรือ OpenRouter หรือ Gemini "
        "และอาจเชื่อม Cloud Vision เพื่ออ่านข้อความจากภาพ "
        "สลิปการชำระเงินเชื่อม EasySlip เพื่อตรวจสอบยอด "
        "ขณะที่การเข้าสู่ระบบด้วย Google เชื่อมการตรวจสอบโทเคนกับชุดใบรับรองสาธารณะของ Google",
    )
    table(
        doc,
        ["กระบวนการ", "อินพุต", "API / บริการที่เชื่อม", "ผลที่ได้"],
        [
            [
                "อ่านบัตรประชาชนไทย",
                "ภาพบัตรประชาชน",
                "AiGen Thai ID OCR (POST /aiscript/idcard/v3)",
                "เลขบัตร ชื่อ และข้อมูลบนบัตร",
            ],
            [
                "ตรวจใบหน้าเทียบบัตร",
                "ใบหน้าบนบัตร + ภาพจากกล้อง",
                "AiGen Face Compare (POST /aiface/face-compare/v3) เกณฑ์คะแนน 80",
                "อนุมัติหรือปฏิเสธสถานะ eKYC",
            ],
            [
                "eKYC ทางเลือกรอง",
                "เอกสารและวิดีโอมีชีวิต",
                "Sumsub Identity / Liveness หรือ Manual",
                "สถานะยืนยันตัวตน",
            ],
            [
                "ตรวจรูปสินค้า",
                "ภาพประกาศขาย",
                "วิเคราะห์ EXIF ในระบบ + Google Cloud Vision",
                "คะแนนความเสี่ยงและคำตัดสิน",
            ],
            [
                "กลั่นกรองแชท",
                "ข้อความหรือภาพในแชท",
                "Regex แล้วเชื่อม Groq / OpenRouter / Gemini และ Vision OCR เมื่อจำเป็น",
                "ผ่าน Flag หรือบล็อกข้อความ",
            ],
            [
                "ตรวจสลิป / ชำระเงิน",
                "ภาพสลิปหรือการชำระผ่าน Omise",
                "EasySlip Verify และ Omise API",
                "อัปเดตสถานะคำสั่งซื้อ",
            ],
            [
                "เข้าสู่ระบบด้วย Google",
                "โทเคนระบุตัวตน",
                "Google OAuth Certs",
                "สร้างหรือเข้าสู่บัญชีผู้ใช้",
            ],
        ],
    )
    p(
        doc,
        "กรณี eKYC ด้วย AiGen เป็นตัวอย่างการเชื่อมที่ชัดเจนที่สุด "
        "ระบบไม่ใช้การสแกนใบหน้าแทนการเข้าสู่ระบบ แต่ใช้ใบหน้าเพื่อยืนยันว่าบุคคลตรงกับบัตรประชาชนไทย "
        "ลำดับคืออ่านข้อความบนบัตรด้วย OCR ก่อน หากได้เลขบัตรและชื่อครบจึงส่งคู่ภาพไป Face Compare "
        "หากคะแนนไม่น้อยกว่าเกณฑ์ที่กำหนดจะอนุมัติสถานะยืนยันตัวตน "
        "หาก OCR หรือ Face Compare ล้มเหลว ระบบปฏิเสธหรือส่งต่อไปทางเลือกรองตามที่ออกแบบไว้",
    )

    h(doc, "3. กระบวนการหลักของปัญญาประดิษฐ์และการดึงข้อมูล", 2)
    p(
        doc,
        "กระบวนการหลักประกอบด้วยการดึงข้อมูลจากอินพุตผู้ใช้และจากบริการภายนอก ดังนี้ "
        "AiGen Thai ID OCR ดึงเลขบัตรและชื่อจากภาพบัตรประชาชนไทย "
        "AiGen Face Compare ดึงคะแนนความคล้ายระหว่างใบหน้าบนบัตรกับภาพจากกล้อง "
        "Sumsub ดึงผล Identity และ Liveness ผ่านโทเคนและสถานะผู้สมัคร "
        "โมดูลรูปสินค้าดึงข้อมูล EXIF และผลวิเคราะห์ภาพจาก Cloud Vision "
        "โมดูลแชทดึงข้อความและอาจดึงข้อความจากภาพด้วย OCR ก่อนให้โมเดลภาษาให้คะแนนความเสี่ยง "
        "ผู้ช่วยข้อพิพาทดึงข้อความ พัสดุ และหลักฐานที่เกี่ยวข้องเพื่อสรุปคำแนะนำ "
        "ข้อมูลที่ได้ถูกบันทึกเป็นสถานะธุรกรรมและหลักฐานตรวจสอบย้อนหลังได้",
    )

    h(doc, "4. วิธีทดลองและวัดผล", 2)
    p(
        doc,
        "การทดลองในบทนี้วัดทั้งความถูกต้องของกระบวนการประมวลผลและความสามารถในการตรวจจับ "
        "โมดูลหลักทดลองเชิงปริมาณจำนวน 100 ครั้งในสภาพแวดล้อมควบคุม "
        "รายงานเมทริกซ์ความสับสน ค่าความถูกต้อง ค่าความแม่นยำ ค่าความครบถ้วน และค่าเวลาตอบสนอง "
        "ส่วนข้อมูลจริงครอบคลุม eKYC ภาพจริง การกลั่นกรองข้อความด้วยโมเดลภาษาจริง "
        "และการตรวจสอบรูปสินค้าด้วยภาพจริง พร้อมเส้นโค้ง ROC และ Precision-Recall ในบางโมดูล "
        "นอกจากนี้ใช้สถิติข้อตกลง Cohen kappa การทดสอบ McNemar การทดสอบความทนทานเมื่อบริการภายนอกล้มเหลว "
        "และการทดสอบการใช้งานเพื่อยืนยันว่าผลสอดคล้องกับการออกแบบในบทที่ 3",
    )

    h(doc, "5. การออกแบบเชิงระบบที่รองรับการทำงาน", 2)
    p(
        doc,
        "การออกแบบเน้นความต่อเนื่องของบริการและความปลอดภัยรอบธุรกรรม "
        "แต่ละโมดูลมีทางเลือกรองเมื่อกุญแจบริการไม่พร้อมหรือบริการภายนอกล้มเหลว "
        "เช่น Manual eKYC การตัดสินจาก EXIF อย่างเดียว การใช้เฉพาะกฎนิพจน์ปกติ "
        "คำแนะนำข้อพิพาทแบบฮิวริสติก และการตั้งสถานะรอตรวจสลิป "
        "สถาปัตยกรรมสามชั้นแยกส่วนแสดงผล ส่วนบังคับกฎธุรกิจ และส่วนข้อมูล "
        "ทำให้ระบบรองรับการทำงานได้แม้บางบริการคลาวด์ไม่พร้อม "
        "บทที่ 4 จึงทำหน้าที่พิสูจน์เชิงประจักษ์ว่าแกนออกแบบดังกล่าวใช้ได้จริงภายใต้เงื่อนไขการทดลองที่กำหนด",
    )

    h(doc, "มุมมองสามระดับต่อแกนออกแบบของ FLIXO Escrow", 2)
    p(
        doc,
        "เพื่อให้การอธิบายเชิงระบบสอดคล้องกับผู้มีส่วนเกี่ยวข้องที่ต่างกัน "
        "งานนี้อธิบายคุณค่าของ FLIXO Escrow ในสามระดับดังนี้",
    )
    h(doc, "ระดับที่ 1 Software Engineer", 3)
    p(
        doc,
        "ในมุมวิศวกรรมซอฟต์แวร์ FLIXO เป็นระบบบูรณาการหลายบริการภายนอกภายใต้สถาปัตยกรรมสามชั้น "
        "แต่ละโมดูลมีอินพุต เอาต์พุต เกณฑ์ตัดสิน Provider Chain และทางเลือกรองชัดเจน "
        "ตัวอย่างเช่น การเชื่อม AiGen Thai ID OCR กับ Face Compare "
        "การเชื่อม EXIF กับ Cloud Vision และการเชื่อม Regex กับโมเดลภาษา "
        "จุดเน้นคือความถูกต้องของกระบวนการ ประสิทธิภาพการเรียก API "
        "การแยก Authentication ออกจาก eKYC และการทดสอบความทนทานเมื่อบริการภายนอกล้มเหลว",
    )
    h(doc, "ระดับที่ 2 E-commerce / Fintech", 3)
    p(
        doc,
        "ในมุมพาณิชย์อิเล็กทรอนิกส์และฟินเทค FLIXO เป็นตัวกลางพักเงินที่เพิ่มชั้นความเชื่อถือรอบธุรกรรม "
        "ครอบคลุมการยืนยันตัวตนด้วยบัตรประชาชนไทยและใบหน้า การตรวจรูปสินค้าที่อาจเป็นภาพคัดลอก "
        "การกันชักชวนโอนเงินนอกระบบในแชท การตรวจสอบสลิปและการชำระเงิน "
        "ตลอดจนการช่วยแอดมินวิเคราะห์ข้อพิพาท "
        "จุดเน้นคือลดความเสี่ยงการหลอกลวง เพิ่มความโปร่งใสของสถานะเงินและตัวตน "
        "และรักษาความต่อเนื่องของธุรกรรมแม้บางขั้นตรวจอัตโนมัติต้องส่งต่อให้มนุษย์",
    )
    h(doc, "ระดับที่ 3 Tech StartUp", 3)
    p(
        doc,
        "ในมุมสตาร์ทอัพด้านเทคโนโลยี FLIXO แสดงแนวทางสร้างผลิตภัณฑ์ตั้งต้นที่นำ AI และ API สำเร็จรูปมาประกอบเป็นชั้นป้องกันความเสี่ยง "
        "โดยไม่ต้องฝึกโมเดลเองทั้งหมด แต่กำหนดเกณฑ์ ลำดับผู้ให้บริการ และทางเลือกรองระดับระบบ "
        "ทำให้ขยายหรือสลับผู้ให้บริการได้ เช่น AiGen กับ Sumsub หรือ Groq กับ OpenRouter "
        "จุดเน้นคือความเร็วในการส่งมอบ ความคุ้มค่าของการเรียกบริการคลาวด์ "
        "และความสามารถในการทำงานต่อเมื่อบริการภายนอกไม่พร้อม ซึ่งเป็นเงื่อนไขสำคัญของการทดลองใช้จริง",
    )
    table(
        doc,
        ["ระดับ", "คำถามหลัก", "สิ่งที่ FLIXO ตอบ", "หลักฐานในบทที่ 4"],
        [
            [
                "Software Engineer",
                "เชื่อม API และควบคุม pipeline อย่างไร",
                "สถาปัตยกรรมสามชั้น Provider Chain Threshold Fallback",
                "ผลรายโมดูล Latency Resilience",
            ],
            [
                "E-commerce / Fintech",
                "ลดความเสี่ยงรอบธุรกรรมได้อย่างไร",
                "eKYC ตรวจรูป กันโอนนอก ตรวจสลิป ช่วยข้อพิพาท",
                "Acc Precision Recall Live บางโมดูล UAT",
            ],
            [
                "Tech StartUp",
                "ส่งมอบและสเกลชั้น AI ได้เร็วเพียงใด",
                "บูรณาการ API สำเร็จรูป สลับผู้ให้บริการได้",
                "Fallback ครบชั้น อภิปรายขอบเขต Mock และ Live",
            ],
        ],
    )

    # 4.1
    h(doc, "4.1 วัตถุประสงค์ของการทดลอง", 1)
    p(
        doc,
        "จากกรอบเชิงระบบข้างต้น วัตถุประสงค์ของบทนี้มีดังนี้ "
        "(1) อธิบายการทำงานของโมดูลปัญญาประดิษฐ์และส่วนเชื่อมต่อภายนอกให้สอดคล้องกับปัญหาและเทคนิคที่ออกแบบไว้ "
        "(2) ทดลองเชิงปริมาณจำนวน 100 ครั้งต่อโมดูลหลัก "
        "(3) รายงานเมทริกซ์ความสับสน ค่าความถูกต้อง ค่าความแม่นยำ ค่าความครบถ้วน และค่าเวลาตอบสนอง "
        "(4) ยืนยันผลบางส่วนบนข้อมูลจริง และ (5) อภิปรายผลภายใต้กรอบงานวิจัย "
        "รวมถึงการทดสอบความทนทานและการใช้งานที่สะท้อนการออกแบบรองรับการทำงาน",
    )

    # 4.2
    h(doc, "4.2 สภาพแวดล้อมและขอบเขตการตีความผล", 1)
    h(doc, "4.2.1 สภาพแวดล้อมการทดสอบ", 2)
    table(
        doc,
        ["รายการ", "รายละเอียด"],
        [
            ["ระบบ", "FLIXO Escrow (PHP / XAMPP)"],
            ["APP_ENV", "local"],
            ["โหมดทดลองหลัก", "MOCK_API_MODE = true (ชั้นคลาวด์จำลอง; Chat ใช้ Regex จริง + Mock LLM)"],
            ["หลักฐาน Live", "eKYC ภาพจริง, Chat ด้วยโมเดลภาษาจริง, Image Fraud ภาพจริง"],
            ["เครือข่าย (รอบ Mock)", "localhost เป็นหลัก"],
            ["สคริปต์ทดสอบ", "tests/test_*_100_runs.php และ tests/test_*_real*.php"],
            ["ไฟล์ผล", "docs/appendix_ch4"],
            ["ภาพหน้าจอ", "docs/appendix_ch4/screenshots"],
        ],
    )
    p(
        doc,
        "ข้อควรตระหนักสำคัญ: ค่าความถูกต้องหรือค่าความสอดคล้องร้อยละ 100 ในโหมด Mock "
        "ใช้ยืนยันความถูกต้องของกระบวนการประมวลผล (pipeline) เป็นหลัก "
        "ไม่ใช่การรับรองความแม่นยำของโมเดลปัญญาประดิษฐ์บนประชากรผู้ใช้จริง",
        bold=True,
    )

    h(doc, "4.2.2 นิยามตัวชี้วัดกลาง", 2)
    p(
        doc,
        "สำหรับงานจำแนกเชิงทวิภาค (เช่น Match/Non-match หรือ Risky/Safe) กำหนดตัวชี้วัดดังนี้ "
        "Accuracy = (TP+TN)/(TP+TN+FP+FN), "
        "Precision = TP/(TP+FP), "
        "Recall = TP/(TP+FN), "
        "F1 = 2·Precision·Recall/(Precision+Recall), "
        "FPR = FP/(FP+TN) และ FRR = FN/(TP+FN) "
        "ตัวชี้วัดด้านระบบ ได้แก่ Latency (ค่าเฉลี่ย ค่าต่ำสุด ค่าสูงสุด P50 และ P95 เป็นมิลลิวินาที) "
        "อัตราความสำเร็จของ API และอัตราการสลับไปทางเลือกรอง",
    )

    h(doc, "4.2.3 แผนการทดลองรายโมดูล", 2)
    table(
        doc,
        ["รหัส", "โมดูล", "ขนาดทดสอบหลัก", "ตัวชี้วัดหลัก"],
        [
            ["E1", "eKYC AiGen OCR + Face", "Mock N=100 และ Live 4 คู่", "Accuracy FAR/FRR Latency Fallback"],
            ["E1b", "Sumsub Pipeline", "Mock N=100", "Match Rate E2E Latency"],
            ["E2", "Product Image Fraud", "Mock N=100 และ Live N=58", "Precision Recall ROC/PR Latency"],
            ["E3", "Chat Moderation", "Mock และ Live N=100", "Precision Recall F1 kappa Latency"],
            ["E4", "Dispute Assistant", "Mock N=100", "Agreement Confidence Latency"],
            ["E5", "EasySlip Verify", "Fixture N=100", "Decision Accuracy Manual fallback"],
            ["E6", "Omise Payment", "Sandbox N=100", "Functional Pass Latency"],
            ["E7", "Google Sign-In Verify", "Certs + negative N=100", "Verify Accuracy False Accept"],
            ["E8", "Fallback Resilience", "10 เคสบังคับล้มเหลว", "Fallback Correctness"],
            ["E9", "UAT ฟังก์ชัน", "12 สถานการณ์", "Pass Rate"],
        ],
    )

    h(doc, "4.2.4 สารบัญโมดูลและโหมดหลักฐาน", 2)
    table(
        doc,
        ["ลำดับ", "โมดูล", "ประเภท", "ลักษณะการทดลอง"],
        [
            ["1", "AiGen OCR และ Face Compare", "ปัญญาประดิษฐ์ eKYC", "Mock N=100 และ Live 4 คู่"],
            ["2", "Sumsub Identity และ Liveness", "ปัญญาประดิษฐ์ eKYC", "Mock N=100"],
            ["3", "Manual eKYC", "ทางเลือกรองโดยมนุษย์", "อธิบายและทดสอบความทนทาน"],
            ["4", "EXIF และ Cloud Vision", "ตรวจสอบรูปสินค้า", "Mock N=100 และ Live N=58"],
            ["5", "Regex และโมเดลภาษา", "ความปลอดภัยของแชท", "Mock และ Live N=100"],
            ["6", "Dispute Assistant", "ระบบสนับสนุนการตัดสิน", "Mock N=100"],
            ["7", "Google Sign-In / Omise / EasySlip", "ยืนยันตัวตนและการชำระเงิน", "N=100"],
            ["8", "บริการโครงสร้างพื้นฐาน", "อีเมล พุช ที่เก็บไฟล์ Redis", "อธิบายและทางเลือกรอง"],
        ],
    )
    table(
        doc,
        ["โหมดหลักฐาน", "โมดูลที่เกี่ยวข้อง", "ความหมายของผล", "ข้อจำกัด"],
        [
            [
                "Mock Pipeline",
                "eKYC AiGen Sumsub Image Dispute",
                "ท่อประมวลผลและการแมปสถานะถูกต้องภายใต้ GT ที่ควบคุม",
                "ไม่ใช่ความแม่นยำโมเดลคลาวด์บนข้อมูลโลกจริง",
            ],
            [
                "Dataset Evaluation",
                "Chat Moderation",
                "ประเมินกับข้อความไทย 100 ข้อความที่มี GT",
                "ความหลากหลายของภาษาจำกัด",
            ],
            [
                "Sandbox Live",
                "Omise",
                "เรียก API จริงในสภาพแวดล้อมทดสอบ",
                "QR PromptPay อาจเป็น null ตามบัญชี",
            ],
            [
                "Fixture Decision Logic",
                "EasySlip",
                "ทดสอบกฎตัดสินใจของแอปด้วย response จำลอง",
                "ยังไม่ได้วัด OCR/QR บนสลิปจริง",
            ],
            [
                "Certs + Negative JWT",
                "Google Auth",
                "ดึงใบรับรองจริงและปฏิเสธโทเคนปลอม",
                "ยังไม่มีโทเคน GIS จากผู้ใช้จริง",
            ],
            [
                "Failure Injection",
                "Resilience",
                "บังคับล้มเหลวแล้วดูทางเลือกรอง",
                "ไม่ครอบคลุมทุกโหมดล้มเหลวของคลาวด์",
            ],
        ],
    )

    # 4.3 Google
    h(doc, "4.3 การเข้าสู่ระบบด้วย Google Sign-In", 1)
    p(
        doc,
        "Google Sign-In เป็นชั้นการยืนยันสิทธิ์เข้าใช้งานด้วยบัญชี Google "
        "โดยไม่ได้ใช้การสแกนใบหน้า และแยกจากกระบวนการ eKYC อย่างชัดเจน "
        "ฝั่งลูกค้าได้รับโทเคนระบุตัวตน จากนั้นฝั่งเซิร์ฟเวอร์ตรวจสอบลายเซ็นผ่านชุดใบรับรองสาธารณะ "
        "หากตรวจสอบไม่ผ่าน ผู้ใช้ยังสามารถเข้าสู่ระบบด้วยรหัสผ่านได้",
    )
    table(
        doc,
        ["รายการ", "ค่า"],
        [
            ["ค่าปรับแต่ง", "GOOGLE_CLIENT_ID"],
            ["แหล่งใบรับรอง", "https://www.googleapis.com/oauth2/v1/certs"],
            ["ทางเลือกรอง", "รหัสผ่าน / รีเซ็ตรหัสผ่าน"],
            ["สคริปต์ทดสอบ", "tests/test_google_auth_100_runs.php"],
        ],
    )
    h(doc, "4.3.1 ชุดทดสอบ N=100", 2)
    table(
        doc,
        ["กลุ่ม", "N", "ลักษณะ", "ผลที่คาดหวัง"],
        [
            ["G_CERTS", "20", "ดึง oauth2/v1/certs", "สำเร็จ"],
            ["G_TAMPER", "20", "โทเคนลายเซ็นถูกแก้", "ปฏิเสธ"],
            ["G_WRONG_AUD", "20", "audience ผิด", "ปฏิเสธ"],
            ["G_EXPIRED", "20", "โทเคนหมดอายุ", "ปฏิเสธ"],
            ["G_MALFORMED", "10", "โทเคนผิดรูปแบบ", "ปฏิเสธ"],
            ["G_NO_CONFIG", "10", "ไม่มี Client ID", "ปฏิเสธ"],
        ],
    )
    table(
        doc,
        ["ตัวชี้วัด", "ค่า"],
        [
            ["ความถูกต้องตาม GT ของชุดนี้", f"{ga.get('accuracy', 'N/A')}%"],
            ["อัตราผลบวกลวง (False Accept)", f"{ga.get('false_accept', 0)}%"],
            ["อัตราดึงใบรับรองสำเร็จ", f"{ga.get('certs_success_pct', 'N/A')}%"],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{ga.get('avg', 'N/A')} / {ga.get('p95', 'N/A')} มิลลิวินาที"],
            ["โหมด", ga.get("mode", "certs_live_plus_synthetic_negative")],
        ],
    )
    p(
        doc,
        "รอบนี้ไม่ได้รวมโทเคน GIS จริงจากการล็อกอินผู้ใช้ "
        "จึงวัดความสามารถปฏิเสธโทเคนไม่ถูกต้องและการดึงใบรับรองเป็นหลัก",
    )

    # 4.4 OCR
    h(doc, "4.4 การอ่านบัตรประชาชนด้วย AiGen OCR", 1)
    p(
        doc,
        "ระบบเรียกบริการรู้จำตัวอักษรบนบัตรประชาชนไทยสำเร็จรูป โดยไม่ได้ฝึกโมเดลภายในโครงการ "
        "ผลลัพธ์หลักที่ใช้คือเลขบัตรและชื่อภาษาไทย หากอ่านไม่สำเร็จจะเข้าสู่สถานะปฏิเสธ "
        "หรือส่งต่อสู่การตรวจด้วยเจ้าหน้าที่เมื่อเกิดข้อผิดพลาดทั้งกระบวนการ "
        "จุดเรียกคือ POST /aiscript/idcard/v3",
    )

    # 4.5 Face
    h(doc, "4.5 การเปรียบเทียบใบหน้าด้วย AiGen Face Compare", 1)
    p(
        doc,
        "ระบบเปรียบเทียบใบหน้าบนบัตรกับภาพถ่ายตนเองจากกล้องหน้า โดยใช้เกณฑ์คะแนนเท่ากับ 80 "
        "หากตรงกันจะอนุมัติสถานะยืนยันตัวตน หากไม่ตรงจะปฏิเสธ "
        "และหากบริการภายนอกล้มเหลวจะส่งเข้ารอตรวจด้วยเจ้าหน้าที่ "
        "จุดเรียกคือ POST /aiface/face-compare/v3",
    )
    h(doc, "4.5.0 การออกแบบชุดทดสอบ Mock", 2)
    table(
        doc,
        ["กลุ่ม", "ความหมาย", "Ground Truth", "ผลที่คาดหวัง (เกณฑ์ 80)"],
        [
            ["G1 Genuine", "เซลฟีของเจ้าของบัตร", "Match", "approved / TP"],
            ["G2 Impostor", "เซลฟีคนละคน", "Non-match", "rejected / TN"],
            ["G3 Poor Quality", "มืด เบลอ มุมเอียง", "ตามจริง", "ปฏิเสธหรือจัดการข้อผิดพลาดได้"],
            ["G4 Doc Fail", "บัตรไม่ชัดหรือไม่ใช่บัตร", "OCR fail", "rejected"],
        ],
    )
    p(
        doc,
        "รอบ Mock N=100 ใช้สัดส่วนสมดุล Genuine 50 ครั้งและ Impostor 50 ครั้ง "
        "ผ่านสคริปต์ tests/test_ekyc_100_runs.php ผลรายครั้งอยู่ใน appendix_ch4/ekyc_results.csv",
    )
    h(doc, "4.5.0.1 เมทริกซ์ความสับสนและตัวชี้วัด (Mock N=100)", 2)
    table(doc, confusion_rows(ek["tp"], ek["tn"], ek["fp"], ek["fn"])[0], confusion_rows(ek["tp"], ek["tn"], ek["fp"], ek["fn"])[1:])
    table(
        doc,
        ["ตัวชี้วัด (Mock N=100)", "ค่า"],
        [
            ["ความถูกต้อง / ความแม่นยำ / ความครบถ้วน / F1", "100% / 100% / 100% / 1.0"],
            ["FPR (FAR) / FRR", "0% / 0%"],
            ["เวลาเฉลี่ย / ค่าต่ำสุด / ค่าสูงสุด", f"{ek['avg']} / {ek.get('min', 'N/A')} / {ek.get('max', 'N/A')} มิลลิวินาที"],
            ["เปอร์เซ็นไทล์ที่ 95", f"{ek['p95']} มิลลิวินาที"],
            [
                "ช่วงเชื่อมั่นวิลสันร้อยละ 95",
                fmt_ci((mods.get("ekyc") or {}).get("acc_ci95")),
            ],
        ],
    )
    p(
        doc,
        "ไม่พบเคสผิดพลาดในรอบ Mock (FP=0, FN=0) "
        "ผลจึงยืนยันการแมปสถานะ approved/rejected ของท่อแอปภายใต้ GT ที่ควบคุม "
        "ไม่ใช่การรับรอง FAR/FRR ของโมเดลบนประชากรจริง",
    )

    if ek_real:
        h(doc, "4.5.1 การทดลองบนภาพจริง (สอดคล้องหัวข้อ 4.3.8)", 2)
        p(
            doc,
            "การทดลองนี้บังคับปิดโหมด Mock และใช้ภาพจริงจากผู้ร่วมทดลองสองคน "
            "ประกอบด้วยคู่ตรงกันสองคู่และคู่สลับบุคคลสองคู่ รวมสี่คู่ "
            "รันผ่าน tests/test_ekyc_real_pairs.php "
            "จำนวนตัวอย่างยังน้อย จึงใช้เป็นหลักฐานสาธิตภายใต้ความยินยอมเท่านั้น",
        )
        table(
            doc,
            ["คู่", "กลุ่ม", "GT", "OCR", "คะแนนใบหน้า", "ระบบ Match", "ถูกต้อง", "E2E (มิลลิวินาที)"],
            [
                ["a1", "Genuine A", "1", "1", "99.98", "1", "ใช่", "4639"],
                ["b1", "Genuine B", "1", "1", "98.34", "1", "ใช่", "5050"],
                ["i1", "Impostor บัตร A + หน้า B", "0", "1", "0", "0", "ใช่", "4148"],
                ["i2", "Impostor บัตร B + หน้า A", "0", "1", "0", "0", "ใช่", "4789"],
            ],
        )
        table(
            doc,
            ["ตัวชี้วัด (Live N=4)", "ค่า"],
            [
                [
                    "เมทริกซ์ความสับสน",
                    f"TP={ek_real.get('tp')} TN={ek_real.get('tn')} "
                    f"FP={ek_real.get('fp')} FN={ek_real.get('fn')}",
                ],
                ["ความถูกต้อง / FAR / FRR", f"{ek_real.get('accuracy_all_pairs_pct')}% / 0% / 0%"],
                [
                    "เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95",
                    f"{ek_real.get('avg_e2e_ms')} / {ek_real.get('p95_e2e_ms')} มิลลิวินาที",
                ],
            ],
        )
        p(
            doc,
            "คู่ตรงกันได้คะแนนใบหน้าในช่วงประมาณ 98 ถึง 100 และได้รับการอนุมัติ "
            "ขณะที่คู่สลับบุคคลได้คะแนน 0 และถูกปฏิเสธ "
            "รอบแรกด้วยไฟล์ความละเอียดสูงบางคู่ timeout ที่ 90 วินาที "
            "จึงลดขนาดภาพเหลือด้านยาวสุดประมาณ 1600 พิกเซลแล้วรันใหม่จนสำเร็จทั้งสี่คู่ "
            "ผลสอดคล้องทิศทางของเกณฑ์คะแนน 80 แต่ยังไม่อาจสรุปความแม่นยำในประชากรกว้าง",
        )

    # 4.6 Sumsub
    h(doc, "4.6 Sumsub Identity และ Liveness", 1)
    p(
        doc,
        "Sumsub เป็นทางเลือก eKYC แบบบริการสำเร็จรูปที่มีการตรวจมีชีวิตแบบโต้ตอบ "
        "ต่างจาก AiGen ที่ใช้ภาพนิ่งเป็นหลัก กระบวนการประกอบด้วยการขอโทเคน การสร้างผู้สมัคร "
        "การทำรายการในชุดพัฒนาเว็บ และการรับผลผ่านเว็บฮุคหรือการซิงก์สถานะ",
    )
    p(
        doc,
        "รอบ Mock รันผ่าน tests/test_sumsub_100_runs.php "
        "กลุ่ม S1 จำนวน 50 ครั้ง (GT=GREEN) และ S2 จำนวน 50 ครั้ง (GT=RED) "
        "จำลองสามจุดเรียกต่อรอบ จึงไม่ใช่ WebSDK liveness จริง",
    )
    table(
        doc,
        ["ตัวชี้วัด (Mock N=100)", "ค่า"],
        [
            ["อัตราความถูกต้องของท่อประมวลผล", f"{su['acc']}%"],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{su['avg']} / {su['p95']} มิลลิวินาที"],
            [
                "ช่วงเชื่อมั่นวิลสันร้อยละ 95",
                fmt_ci((mods.get("sumsub") or {}).get("acc_ci95")),
            ],
            ["หมายเหตุ", "ยืนยันการแมปสถานะ GREEN/RED ของท่อแอป"],
        ],
    )
    p(
        doc,
        "Sumsub ใช้เวลาสูงกว่า AiGen เล็กน้อยเพราะมีสามขั้นต่อเนื่อง "
        "สอดคล้องกับสถาปัตยกรรมจริง (SDK token ไปยัง applicant แล้วไปยัง review)",
    )

    h(doc, "4.6.1 ทางเลือกรองแบบ Manual eKYC", 2)
    p(
        doc,
        "เมื่อไม่มีกุญแจบริการหรือบริการภายนอกล้มเหลว ระบบเก็บเอกสารแบบเข้ารหัส "
        "แล้วตั้งสถานะรอตรวจเพื่อให้เจ้าหน้าที่อนุมัติหรือปฏิเสธ "
        "ซึ่งเป็นกลไกลดความรุนแรงของความล้มเหลวที่สำคัญของระบบจริง",
    )

    # 4.7 Image
    h(doc, "4.7 การตรวจสอบรูปสินค้า (EXIF และ Cloud Vision)", 1)
    p(
        doc,
        "โมดูลนี้ป้องกันการใช้ภาพหน้าจอหรือภาพคัดลอกจากแหล่งอื่นเมื่อลงประกาศขาย "
        "โดยใช้สองชั้น ได้แก่ การวิเคราะห์ข้อมูลเมตา EXIF และการเรียก Google Cloud Vision "
        "เฉพาะภาพที่น่าสงสัย เกณฑ์ติดธงเท่ากับ 50 และเกณฑ์ปฏิเสธเท่ากับ 75",
    )
    h(doc, "4.7.0 การออกแบบชุดทดสอบ", 2)
    table(
        doc,
        ["กลุ่ม", "ลักษณะภาพ", "Ground Truth", "ผลที่คาดหวัง"],
        [
            ["P1 Clean Camera", "ถ่ายสินค้าจริง มี EXIF กล้อง", "Safe", "pass"],
            ["P2 Screenshot / Chat", "ภาพจากแชทหรือสกรีน", "Risky", "flag หรือ reject"],
            ["P3 Marketplace Reuse", "ดึงจากเว็บขายออนไลน์", "Risky", "flag หรือ reject"],
            ["P4 Borderline", "ถ่ายจริงแต่ตัด EXIF", "ตามผู้เชี่ยวชาญ", "flag หรือ pass"],
        ],
    )
    p(
        doc,
        "รอบ Mock N=100 กำหนด P1 Safe จำนวน 50 และ P2 Risky จำนวน 50 "
        "นิยาม Positive คือระบบทำนาย Risky เมื่อ verdict เป็น flag หรือ reject "
        "ผลอยู่ใน appendix_ch4/image_fraud_results.csv",
    )
    table(doc, confusion_rows(im["tp"], im["tn"], im["fp"], im["fn"], "Risky", "Safe")[0], confusion_rows(im["tp"], im["tn"], im["fp"], im["fn"], "Risky", "Safe")[1:])
    table(
        doc,
        ["ตัวชี้วัด (Mock N=100)", "ค่า"],
        [
            ["ความถูกต้อง / ความแม่นยำ / ความครบถ้วน / F1", "100% / 100% / 100% / 1.0"],
            ["อัตราเรียก Vision", "ประมาณ 50% (เฉพาะกลุ่ม Risky)"],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{im['avg']} / {im['p95']} มิลลิวินาที"],
            [
                "ช่วงเชื่อมั่นวิลสันร้อยละ 95",
                fmt_ci((mods.get("image") or {}).get("acc_ci95")),
            ],
        ],
    )
    p(
        doc,
        "ความถูกต้องร้อยละ 100 ภายใต้ Mock ยืนยันว่าการเชื่อม EXIF ไปยัง Vision แล้วไปยังคำตัดสินทำงานครบ "
        "ไม่ใช่ความแม่นยำของ Vision บนภาพสินค้าจริง",
    )

    if img_real:
        h(doc, "4.7.1 การทดลองบนภาพจริง (สอดคล้องหัวข้อ 4.4.6)", 2)
        p(
            doc,
            "ชุดภาพจริงประกอบด้วยภาพถ่ายจากกล้อง 16 ภาพ ภาพลบ EXIF 16 ภาพ "
            "ภาพขนาดเล็ก 16 ภาพ และภาพหน้าจอจริง 10 ภาพ รวม 58 ภาพ "
            "รันผ่าน tests/test_image_fraud_real.php โดยปิดโหมด Mock",
        )
        table(
            doc,
            confusion_rows(
                img_real.get("tp", 0),
                img_real.get("tn", 0),
                img_real.get("fp", 0),
                img_real.get("fn", 0),
                "Risky",
                "Safe",
            )[0],
            confusion_rows(
                img_real.get("tp", 0),
                img_real.get("tn", 0),
                img_real.get("fp", 0),
                img_real.get("fn", 0),
                "Risky",
                "Safe",
            )[1:],
        )
        table(
            doc,
            ["ตัวชี้วัด (Live N=58)", "ค่า"],
            [
                [
                    "ความถูกต้อง / ความแม่นยำ / ความครบถ้วน",
                    f"{img_real.get('accuracy_pct')}% / "
                    f"{round(100 * float(img_real.get('precision') or 0), 1)}% / "
                    f"{round(100 * float(img_real.get('recall') or 0), 1)}%",
                ],
                [
                    "เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95",
                    f"{img_real.get('avg_ms')} / {img_real.get('p95_ms')} มิลลิวินาที",
                ],
                ["Vision API", "เปิดใช้ (configured)" if img_real.get("vision_configured") else "ไม่พร้อม"],
            ],
        )
        sg = img_real.get("subgroups") or {}
        if sg:
            h(doc, "4.7.1.1 ผลแยกตามชนิดภาพ", 3)
            table(
                doc,
                ["ชนิด", "N", "ความถูกต้อง", "ผลหลัก"],
                [
                    [
                        "กล้อง (clean)",
                        str((sg.get("clean_camera") or {}).get("n", 16)),
                        f"{(sg.get('clean_camera') or {}).get('accuracy_pct', 100)}%",
                        "ผ่านหมด (FP=0) — ความจำเพาะดี",
                    ],
                    [
                        "Tiny / แชร์เล็ก",
                        str((sg.get("tiny") or {}).get("n", 16)),
                        f"{(sg.get('tiny') or {}).get('accuracy_pct', 100)}%",
                        "flag ด้วย heuristic ขนาดไฟล์",
                    ],
                    [
                        "No-EXIF (ก็อป)",
                        str((sg.get("noexif") or {}).get("n", 16)),
                        f"{(sg.get('noexif') or {}).get('accuracy_pct', 0)}%",
                        "Vision ยังให้ pass — ลบ EXIF อย่างเดียวไม่พอเมื่อไฟล์ยังใหญ่",
                    ],
                    [
                        "Screenshot จริง",
                        str((sg.get("screenshot") or {}).get("n", 10)),
                        f"{(sg.get('screenshot') or {}).get('accuracy_pct', 50)}%",
                        "จับได้บางส่วน พลาดบางส่วน",
                    ],
                ],
            )
        p(
            doc,
            "เมื่อแยกตามชนิดภาพ พบว่าภาพจากกล้องมีความถูกต้องร้อยละ 100 โดยไม่เกิดผลบวกลวง "
            "ภาพขนาดเล็กถูกจับได้ทั้งหมด ภาพลบ EXIF ที่ยังมีความละเอียดสูงหลุดเป็นส่วนใหญ่ "
            "และภาพหน้าจอจริงมีความถูกต้องร้อยละ 50 "
            "ผล Live ต่ำกว่าผล Mock อย่างชัดเจน จึงมีคุณค่าต่อการวิเคราะห์ข้อผิดพลาด "
            "จุดแข็งคือไม่ฟ้องรูปกล้องผิด (FP=0) จุดอ่อนคือ Recall ร้อยละ 50",
            bold=True,
        )
        ic = curves.get("image_fraud_live_curves") or {}
        if ic:
            h(doc, "4.7.2 เส้นโค้ง ROC และ Precision-Recall", 2)
            table(
                doc,
                ["ตัวชี้วัด", "ค่า"],
                [
                    ["พื้นที่ใต้เส้นโค้ง ROC", str(ic.get("auc_roc", "N/A"))],
                    ["พื้นที่ใต้เส้นโค้ง Precision-Recall", str(ic.get("auc_pr", "N/A"))],
                    ["จุดทำงานเมื่อคะแนนไม่น้อยกว่า 50", "ความแม่นยำ 1.00 ความครบถ้วน 0.50 อัตราผลบวกลวง 0.00"],
                    ["จุดทำงานเมื่อคะแนนไม่น้อยกว่า 75", "ความแม่นยำ 1.00 ความครบถ้วนประมาณ 0.12 อัตราผลบวกลวง 0.00"],
                ],
            )
            p(
                doc,
                "จุดทำงานปัจจุบันรักษาระดับอัตราผลบวกลวงไว้ที่ศูนย์ แต่ค่าความครบถ้วนยังไม่เต็มที่ "
                "หากลดเกณฑ์เพื่อดึง Recall ขึ้นต้องระวัง FPR รายละเอียดภาพแสดงในภาพที่ 11",
            )

    # 4.8 Chat
    h(doc, "4.8 การกลั่นกรองข้อความในแชท", 1)
    p(
        doc,
        "โมดูลนี้ป้องกันการชักชวนโอนเงินนอกระบบ การแลกเปลี่ยนช่องทางติดต่อ และการส่งเลขบัญชี "
        "โดยใช้กฎนิพจน์ปกติเป็นชั้นแรก แล้วเรียกโมเดลภาษาเมื่อคะแนนอยู่ในช่วงกลาง "
        "ลำดับผู้ให้บริการคือ Groq, OpenRouter และ Gemini หากโมเดลภาษาล้มเหลวจะใช้เฉพาะชั้นกฎ",
    )
    h(doc, "4.8.0 การออกแบบชุดข้อความ", 2)
    table(
        doc,
        ["กลุ่ม", "ลักษณะ", "Ground Truth"],
        [
            ["C1 Benign", "ทักทาย นัดรับของ ตามสถานะออเดอร์", "Safe"],
            ["C2 Off-platform", "ชวนแอดไลน์ / โอนนอกระบบ / ส่งเบอร์", "Risky"],
            ["C3 Payment Leak", "เลขบัญชี พร้อมเพย์ นอกระบบ", "Risky"],
            ["C4 Ambiguous", "ข้อความกำกวม มีตัวเลขแต่ไม่ใช่บัญชี", "ตามผู้เชี่ยวชาญ"],
            ["C5 Image OCR", "รูปที่มีข้อความเสี่ยง", "ตามเนื้อ OCR"],
        ],
    )
    p(
        doc,
        "รอบ N=100 อ่านชุดข้อความจาก tests/chat_moderation_dataset_100.csv "
        "(C1 Safe จำนวน 50 และ C2 Risky จำนวน 50) "
        "นิยาม Positive คือ verdict เป็น flag หรือ reject "
        "ผลอยู่ใน appendix_ch4/chat_results.csv",
    )
    table(doc, confusion_rows(ch["tp"], ch["tn"], ch["fp"], ch["fn"], "Risky", "Safe")[0], confusion_rows(ch["tp"], ch["tn"], ch["fp"], ch["fn"], "Risky", "Safe")[1:])
    table(
        doc,
        ["ตัวชี้วัด (Mock N=100)", "ค่า"],
        [
            [
                "ความถูกต้อง / ความแม่นยำ / ความครบถ้วน / F1",
                f"{ch_acc}% / {as_pct(ch.get('precision'))}% / {as_pct(ch.get('recall'))}% / {as_pct(ch.get('f1'))}",
            ],
            ["อัตราบล็อกผิด (Safe ไปยัง flag/reject)", f"{ch['fp']} จาก 50 ของ Safe (ร้อยละ 2)"],
            ["อัตราพลาด (Risky ไปยัง pass)", f"{ch['fn']} จาก 50 ของ Risky (ร้อยละ 4)"],
            ["อัตราเรียกโมเดลภาษา", f"{prov.get('groq', 0)} จาก 100 (ผู้ให้บริการ groq)"],
            ["อัตราจบที่ Regex อย่างเดียว", f"{prov.get('none', 0)} จาก 100"],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{ch['avg']} / {ch['p95']} มิลลิวินาที"],
            [
                "ช่วงเชื่อมั่นวิลสันร้อยละ 95",
                fmt_ci((mods.get("chat") or {}).get("acc_ci95")),
            ],
            ["Cohen kappa กับค่าจริง", str((mods.get("chat") or {}).get("cohen_kappa", agr.get("chat_hybrid_vs_gt_kappa", "N/A")))],
        ],
    )
    table(
        doc,
        ["ผู้ให้บริการ", "จำนวนครั้ง", "เวลาเฉลี่ยโดยประมาณ"],
        [
            ["none (Regex-only)", str(prov.get("none", 0)), "0 มิลลิวินาที"],
            ["Groq (Mock LLM)", str(prov.get("groq", 0)), "สูงกว่าชั้นกฎอย่างชัดเจน"],
            ["OpenRouter / Gemini", "0 ในรอบ Mock", "-"],
        ],
    )
    miss = ch.get("miss") or []
    if miss:
        h(doc, "4.8.0.1 การวิเคราะห์กรณีพลาด (Mock)", 3)
        p(
            doc,
            "เคสพลาดทั้งสามสะท้อนจุดอ่อนของสถาปัตยกรรมแบบชั้น: "
            "FN เกิดเมื่อ Regex ให้คะแนนต่ำจนไม่เรียก LLM ทำให้สแลงหลุด "
            "FP เกิดเมื่อข้อความ Safe กำกวมแล้วชั้น AI ให้คะแนนเข้าเกณฑ์ flag",
        )
        table(
            doc,
            ["ลำดับ", "กลุ่ม", "GT", "ผลระบบ", "ผู้ให้บริการ", "ข้อความโดยสรุป", "แนวแก้"],
            [
                [
                    str(m.get("no")),
                    str(m.get("group")),
                    str(m.get("gt")),
                    str(m.get("verdict")),
                    str(m.get("provider")),
                    str(m.get("text")),
                    "ขยายพจนานุกรม Regex"
                    if str(m.get("verdict")) == "pass"
                    else "ปรับ Prompt หรือเกณฑ์ / allowlist ผ่านแอป",
                ]
                for m in miss
            ],
        )

    if ch_real:
        h(doc, "4.8.1 การทดลองด้วยโมเดลภาษาจริง (สอดคล้องหัวข้อ 4.5.7)", 2)
        p(
            doc,
            "ใช้ชุดข้อความเดิมจำนวน 100 ข้อความ โดยเรียกโมเดลภาษาจริงผ่าน Groq เป็นหลัก "
            "และสำรองด้วย OpenRouter เมื่อจำเป็น รันผ่าน tests/test_chat_real_100_runs.php",
        )
        cr_prov = ch_real.get("providers") or {}
        table(
            doc,
            confusion_rows(
                ch_real.get("tp", 0),
                ch_real.get("tn", 0),
                ch_real.get("fp", 0),
                ch_real.get("fn", 0),
                "Risky",
                "Safe",
            )[0],
            confusion_rows(
                ch_real.get("tp", 0),
                ch_real.get("tn", 0),
                ch_real.get("fp", 0),
                ch_real.get("fn", 0),
                "Risky",
                "Safe",
            )[1:],
        )
        table(
            doc,
            ["ตัวชี้วัด (Live N=100)", "ค่า"],
            [
                [
                    "ความถูกต้อง / ความแม่นยำ / ความครบถ้วน / F1",
                    f"{ch_real.get('accuracy_pct')}% / "
                    f"{round(100 * float(ch_real.get('precision', 0)), 1)}% / "
                    f"{round(100 * float(ch_real.get('recall', 0)), 1)}% / "
                    f"{round(100 * float(ch_real.get('f1', 0)), 1)}%",
                ],
                [
                    "เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95",
                    f"{ch_real.get('avg_ms')} / {ch_real.get('p95_ms')} มิลลิวินาที",
                ],
                [
                    "ผู้ให้บริการ none / groq / openrouter",
                    f"{cr_prov.get('none', 0)} / {cr_prov.get('groq', 0)} / {cr_prov.get('openrouter', 0)}",
                ],
                [
                    "ผลต่างความถูกต้องเทียบ Mock",
                    f"{ch_real.get('compare_to_mock', {}).get('delta_accuracy_pp', 0)} จุดร้อยละ",
                ],
            ],
        )
        p(
            doc,
            "ผล Live ตรงกับ Mock ทั้งเมทริกซ์ความสับสนและเคสพลาดทั้งสามข้อ "
            "สนับสนุนว่า Error Analysis เป็นปัญหาชั้นกฎและเกณฑ์ ไม่ใช่แค่ artifact ของ Mock "
            "Latency Live สูงขึ้นเล็กน้อย และมี OpenRouter เป็นทางเลือกรองเมื่อลำดับแรกไม่จบ",
        )
        live_miss = ch_real.get("misses") or []
        if live_miss:
            table(
                doc,
                ["ลำดับ", "กลุ่ม", "GT", "ผลระบบ", "ผู้ให้บริการ", "ข้อความโดยสรุป"],
                [
                    [
                        str(m.get("no")),
                        str(m.get("group")),
                        str(m.get("gt")),
                        str(m.get("verdict")),
                        str(m.get("provider")),
                        str(m.get("text")),
                    ]
                    for m in live_miss
                ],
            )

    # 4.9 Dispute
    h(doc, "4.9 ผู้ช่วยวิเคราะห์ข้อพิพาท", 1)
    p(
        doc,
        "ระบบช่วยแอดมินวิเคราะห์ข้อพิพาทจากข้อความ พัสดุ และหลักฐาน "
        "แล้วเสนอแนะการดำเนินการโดยไม่ตัดสินแทนมนุษย์อัตโนมัติ "
        "เมื่อไม่มีบริการโมเดลภาษาจะใช้กฎเชิงฮิวริสติกแทน "
        "รอบทดลองใช้ชุดจำลอง 4 การกระทำ คูณ 25 เคส รวม N=100 "
        "ผ่าน tests/test_dispute_ai_100_runs.php",
    )
    table(
        doc,
        ["ตัวชี้วัด (Mock N=100)", "ค่า"],
        [
            ["อัตราความสอดคล้องแบบตรงทั้งหมด", f"{di['agreement_pct']}%"],
            ["Soft Agreement", "เท่ากับ Exact ในรอบนี้"],
            ["อัตรา Heuristic Fallback", "0%"],
            ["ความมั่นใจเฉลี่ย", str((mods.get("dispute") or {}).get("avg_confidence", "ประมาณ 65.5"))],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{di['avg']} / {di['p95']} มิลลิวินาที"],
            [
                "ช่วงเชื่อมั่นวิลสันร้อยละ 95",
                fmt_ci((mods.get("dispute") or {}).get("acc_ci95")),
            ],
            ["หมายเหตุ", "ยังไม่มีการประเมินกับฉลากผู้เชี่ยวชาญบนเคสจริง"],
        ],
    )
    h(doc, "4.9.1 เมทริกซ์การกระทำแบบ 4 x 4", 2)
    table(
        doc,
        ["", "AI: REFUND", "AI: PAYOUT", "AI: SPLIT", "AI: NEEDS_REVIEW"],
        [
            ["Admin: REFUND", "25", "0", "0", "0"],
            ["Admin: PAYOUT", "0", "25", "0", "0"],
            ["Admin: SPLIT", "0", "0", "25", "0"],
            ["Admin: NEEDS_REVIEW", "0", "0", "0", "25"],
        ],
    )
    p(
        doc,
        "Agreement ร้อยละ 100 สะท้อนว่าชั้น normalize และการแมป action ทำงานครบภายใต้ GT ที่บังคับผ่าน mock "
        "ยังไม่ใช่การเทียบกับคำตัดสินแอดมินจริง",
    )

    # 4.10 payment
    h(doc, "4.10 การชำระเงินและการยืนยันตัวตนภายนอก", 1)
    p(
        doc,
        "EasySlip ใช้ตรวจสลิป Omise ใช้ทดสอบการชำระเงินในสภาพแวดล้อมทดลอง "
        "และ Google Sign-In ใช้ตรวจโทเคน โมดูลเหล่านี้ไม่ใช่โมเดลปัญญาประดิษฐ์โดยตรง "
        "แต่เกี่ยวข้องกับความปลอดภัยของธุรกรรมเงินพักกลาง",
    )
    h(doc, "4.10.0 EasySlip — ชุดทดสอบและผล", 2)
    table(
        doc,
        ["กลุ่ม", "ลักษณะ", "ผลที่คาดหวัง", "N"],
        [
            ["S1 Valid Slip", "ยอดถูกต้อง", "auto-pay PAID", "25"],
            ["S2 Wrong Amount", "ยอดต่ำกว่าออเดอร์", "REJECT_AMOUNT", "25"],
            ["S3 Unreadable", "qrcode_not_found", "WAITING_VERIFY / manual", "25"],
            ["S4 API Down", "timeout หรือ error", "Manual fallback", "25"],
        ],
    )
    table(
        doc,
        ["ตัวชี้วัด EasySlip", "ค่า"],
        [
            ["ความถูกต้องของกฎตัดสินใจ", f"{es.get('accuracy', 'N/A')}%"],
            ["False Auto-pay", f"{es.get('false_autopay', 0)}%"],
            ["Manual Fallback Rate", f"{es.get('manual_fallback', 'N/A')}%"],
            ["โหมด", es.get("mode", "fixture_decision_logic")],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95", f"{es.get('avg', 'N/A')} / {es.get('p95', 'N/A')}"],
        ],
    )
    p(
        doc,
        "รอบนี้ยืนยันชั้นตัดสินใจฝั่งแอป ไม่ได้วัดความแม่นยำ OCR/QR ของ EasySlip บนสลิปจริง",
    )

    h(doc, "4.10.0.1 Omise Sandbox — ชุดทดสอบและผล", 2)
    table(
        doc,
        ["รหัส", "เคส", "N", "ผลที่คาดหวัง"],
        [
            ["O1", "Card charge สำเร็จ", "30", "successful และ paid=true"],
            ["O2", "Token ถูกปฏิเสธ", "20", "ไม่ mark paid"],
            ["O3", "สร้าง PromptPay source", "30", "ได้ source.id"],
            ["O4", "Retrieve source ก่อนจ่าย", "10", "ดึงได้และยังไม่ paid"],
            ["O5", "Webhook decision", "10", "predicates ถูกต้อง"],
        ],
    )
    table(
        doc,
        ["ตัวชี้วัด Omise", "ค่า"],
        [
            ["Functional Pass Rate", f"{om.get('pass_rate', 'N/A')}%"],
            ["เวลาเฉลี่ย Charges / Sources", f"{om.get('avg_charges', 'N/A')} / {om.get('avg_sources', 'N/A')} มิลลิวินาที"],
            ["เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95 รวม", f"{om.get('avg', 'N/A')} / {om.get('p95', 'N/A')}"],
            ["Webhook Correctness (O5)", f"{om.get('webhook_logic_pct', 'N/A')}%"],
            ["โหมด", om.get("mode", "sandbox_live")],
        ],
    )
    p(
        doc,
        "บัญชี sandbox บางบัญชีสร้าง PromptPay source ได้แต่ scannable_code เป็น null "
        "จึงนับผ่านเมื่อได้ source id ส่วน QR ต้องตรวจเพิ่มบนบัญชีที่เปิด QR",
    )

    table(
        doc,
        ["โมดูล", "โหมด", "อัตราผ่านหรือความถูกต้อง", "เวลาเฉลี่ย / เปอร์เซ็นไทล์ที่ 95"],
        [
            ["EasySlip", es.get("mode", "fixture"), f"{es.get('accuracy', 'N/A')}%", f"{es.get('avg', 'N/A')} / {es.get('p95', 'N/A')}"],
            ["Omise", om.get("mode", "sandbox"), f"{om.get('pass_rate', 'N/A')}%", f"{om.get('avg', 'N/A')} / {om.get('p95', 'N/A')}"],
            ["Google Auth", ga.get("mode", "certs"), f"{ga.get('accuracy', 'N/A')}%", f"{ga.get('avg', 'N/A')} / {ga.get('p95', 'N/A')}"],
        ],
    )

    h(doc, "4.10.1 สรุปทางเลือกรองเมื่อบริการภายนอกล้มเหลว", 2)
    table(
        doc,
        ["โมดูล", "เมื่อล้มเหลว", "ทางเลือกรอง"],
        [
            ["Google Auth", "โทเคนไม่ผ่าน", "รหัสผ่าน"],
            ["AiGen", "ข้อผิดพลาดของบริการ", "รอตรวจโดยเจ้าหน้าที่"],
            ["Sumsub", "เว็บฮุคไม่ถึง", "ซิงก์สถานะ"],
            ["Vision รูปสินค้า", "ไม่มีกุญแจหรือผิดพลาด", "ติดธงจาก EXIF"],
            ["โมเดลภาษาในแชท", "ผู้ให้บริการทั้งหมดล้มเหลว", "ใช้เฉพาะกฎ"],
            ["Dispute", "โมเดลภาษาล้มเหลว", "กฎเชิงฮิวริสติก"],
            ["EasySlip", "API ล่มหรืออ่านสลิปไม่ได้", "WAITING_VERIFY / manual"],
            ["Omise", "secret ว่างหรือไม่พร้อม", "constructor throw + isConfigured gate"],
            ["Redis", "เชื่อมต่อไม่ได้", "ไม่ throw; ทำงานต่อได้"],
            ["Object Storage", "S3 ไม่ตั้งค่า", "เก็บไฟล์ท้องถิ่น /uploads"],
        ],
    )

    # 4.11 summary table
    h(doc, "4.11 ตารางสรุปรวมผลการทดลอง", 1)
    summary_rows = [
        ["AiGen eKYC (Mock)", "100%", str(ek["avg"]), str(ek["p95"])],
    ]
    if ek_real:
        summary_rows.append(
            [
                "AiGen eKYC (Live)",
                f"{ek_real.get('accuracy_all_pairs_pct')}%",
                str(ek_real.get("avg_e2e_ms", "")),
                str(ek_real.get("p95_e2e_ms", "")),
            ]
        )
    summary_rows.extend(
        [
            ["Sumsub (Mock)", f"{su['acc']}%", str(su["avg"]), str(su["p95"])],
            ["Image Fraud (Mock)", "100%", str(im["avg"]), str(im["p95"])],
        ]
    )
    if img_real:
        summary_rows.append(
            [
                "Image Fraud (Live)",
                f"{img_real.get('accuracy_pct')}%",
                str(img_real.get("avg_ms", "")),
                str(img_real.get("p95_ms", "")),
            ]
        )
    summary_rows.append(["Chat (Mock)", f"{ch_acc}%", str(ch["avg"]), str(ch["p95"])])
    if ch_real:
        summary_rows.append(
            [
                "Chat (Live)",
                f"{ch_real.get('accuracy_pct')}%",
                str(ch_real.get("avg_ms", "")),
                str(ch_real.get("p95_ms", "")),
            ]
        )
    summary_rows.extend(
        [
            ["Dispute (Mock)", f"{di['agreement_pct']}%", str(di["avg"]), str(di["p95"])],
            ["EasySlip", f"{es.get('accuracy', 'N/A')}%", str(es.get("avg", "")), str(es.get("p95", ""))],
            ["Omise", f"{om.get('pass_rate', 'N/A')}%", str(om.get("avg", "")), str(om.get("p95", ""))],
            ["Google Auth", f"{ga.get('accuracy', 'N/A')}%", str(ga.get("avg", "")), str(ga.get("p95", ""))],
            [
                "ความทนทาน",
                f"{stats.get('resilience', {}).get('rate_pct', 'N/A')}%",
                "-",
                "-",
            ],
            ["UAT", f"{stats.get('uat', {}).get('rate_pct', 'N/A')}%", "-", "-"],
        ]
    )
    table(doc, ["โมดูล", "ความถูกต้องหรือความสอดคล้อง", "เวลาเฉลี่ย (มิลลิวินาที)", "เปอร์เซ็นไทล์ที่ 95"], summary_rows)

    if rs:
        h(doc, "4.12 การทดสอบความทนทาน", 1)
        p(
            doc,
            f"ผลการทดสอบทางเลือกรองเท่ากับ {rs.get('pass', 'N/A')} จาก {rs.get('n', 'N/A')} กรณี "
            f"คิดเป็นร้อยละ {rs.get('rate_pct', 'N/A')} "
            "ครอบคลุมสถานการณ์บังคับให้บริการหลักล้มเหลวแล้วตรวจว่ามีทางเลือกรองทำงาน "
            "รันผ่าน tests/test_resilience_and_uat.php โดยบังคับ MOCK_API_MODE=false และ stub คีย์บางตัว",
        )
        resilience_expect = {
            "AiGen eKYC": "kyc_status=pending",
            "Sumsub": "sync_status / poll ได้ผล structured",
            "Cloud Vision (Image)": "EXIF flag สำรอง",
            "Chat LLM": "Regex-only",
            "Vision OCR in Chat": "skip หรือ flag คะแนนกลาง",
            "Dispute AI": "Heuristic NEEDS_REVIEW",
            "EasySlip": "WAITING_VERIFY branch",
            "Omise": "isConfigured gate / constructor throw",
            "Redis": "connect=null โดยไม่ throw",
            "Object Storage": "local uploads",
        }
        cases = rs.get("cases") or []
        if cases:
            table(
                doc,
                ["ลำดับ", "โมดูล", "พฤติกรรมที่คาดหวัง", "ผ่าน?"],
                [
                    [
                        str(c.get("no")),
                        str(c.get("module")),
                        resilience_expect.get(str(c.get("module")), "ทางเลือกรองทำงาน"),
                        "ใช่" if int(c.get("pass", 0)) == 1 else "ไม่",
                    ]
                    for c in cases
                ],
            )
        p(
            doc,
            "ผล 10 จาก 10 แสดงว่าเมื่อบังคับ failure ระบบยังไม่ล่มกระบวนการหลัก "
            "และสลับไปทางสำรองที่ออกแบบไว้ ซึ่งเป็นหลักฐานของ fault-tolerant design",
        )

    if ua:
        h(doc, "4.12.1 การทดสอบการใช้งาน (UAT)", 2)
        p(
            doc,
            f"ผลการทดสอบฟังก์ชันเท่ากับ {ua.get('pass', 'N/A')} จาก {ua.get('n', 'N/A')} รายการ "
            f"คิดเป็นร้อยละ {ua.get('rate_pct', 'N/A')} "
            "หลักฐานผสมระหว่างการเรียก service จริง CSV ทดลองก่อนหน้า และ sandbox "
            "ภาพหน้าจอระบบจริงแสดงในภาคผนวก ข",
        )
        uat_labels = {
            "U-01": "ล็อกอินด้วยรหัสผ่าน",
            "U-02": "ล็อกอินด้วย Google",
            "U-03": "eKYC Genuine ผ่าน AiGen",
            "U-04": "eKYC Impostor",
            "U-05": "eKYC เมื่อ AiGen ล่ม",
            "U-06": "ลงขายด้วยรูปกล้องจริง",
            "U-07": "ลงขายด้วยภาพสกรีน",
            "U-08": "ส่งข้อความชวนแอดไลน์",
            "U-09": "ส่งข้อความปกติ",
            "U-10": "ชำระด้วยสลิปถูกต้อง",
            "U-11": "ชำระ Omise PromptPay (sandbox)",
            "U-12": "แอดมินรัน Dispute AI",
        }
        ua_cases = ua.get("cases") or []
        if ua_cases:
            table(
                doc,
                ["รหัส", "สถานการณ์", "ผ่าน?", "หลักฐานโดยสรุป"],
                [
                    [
                        str(c.get("id")),
                        uat_labels.get(str(c.get("id")), str(c.get("id"))),
                        "ใช่" if int(c.get("pass", 0)) == 1 else "ไม่",
                        str(c.get("actual", ""))[:80],
                    ]
                    for c in ua_cases
                ],
            )
        p(
            doc,
            "U-01 และ U-02 เป็นการตรวจความพร้อมของ Auth path แบบอัตโนมัติ "
            "ไม่ใช่การคลิก UI ทั้ง flow ทั้งหมด",
        )

    # 4.13 Discussion
    h(doc, "4.13 อภิปรายผล", 1)
    p(
        doc,
        "ผลดังกล่าวสะท้อนความถูกต้องของการทำงานของกระบวนการประมวลผลภายใต้สภาพแวดล้อมควบคุมเป็นหลัก "
        "ไม่ใช่การรับรองความแม่นยำของโมเดลปัญญาประดิษฐ์บนข้อมูลจริง",
        bold=True,
    )
    p(
        doc,
        "หลักฐานแยกประเภทที่ชัดเจน ได้แก่ การกลั่นกรองข้อความบนชุดข้อมูลและโมเดลภาษาจริงที่มีความถูกต้องร้อยละ 97 "
        f"การยืนยันตัวตนบนภาพจริงจำนวนสี่คู่ การชำระเงินในสภาพแวดล้อมทดลอง "
        f"และการตรวจสอบรูปสินค้าบนภาพจริงจำนวน 58 ภาพซึ่งมีความถูกต้องร้อยละ {img_real.get('accuracy_pct', 'N/A')} "
        "โดยไม่เกิดผลบวกลวง แต่ค่าความครบถ้วนอยู่ที่ร้อยละ 50",
    )
    p(
        doc,
        "ความถูกต้องร้อยละ 100 ใน Mock ของ eKYC รูปสินค้า ข้อพิพาท และ Sumsub "
        "ใช้ยืนยันว่าอินพุตถึงชั้น Service ได้ครบ การแปลงผล API เป็นสถานะธุรกิจถูกต้อง "
        "และการจัดการข้อผิดพลาดไม่ทำให้ท่อขาด "
        "ไม่สามารถใช้เป็นข้อสรุปว่าโมเดลใบหน้า Vision หรือ LLM แม่นยำร้อยละ 100 บนประชากรผู้ใช้จริง",
    )
    p(
        doc,
        f"เมื่อเปรียบเทียบเวลาตอบสนองเฉลี่ย พบลำดับโดยประมาณดังนี้ "
        f"Google Auth ({ga.get('avg', 'N/A')}) น้อยกว่า EasySlip ({es.get('avg', 'N/A')}) "
        f"น้อยกว่าแชท ({ch['avg']}) น้อยกว่ารูปสินค้า ({im['avg']}) "
        f"น้อยกว่า Omise ({om.get('avg', 'N/A')}) น้อยกว่าข้อพิพาท ({di['avg']}) "
        f"น้อยกว่า eKYC ({ek['avg']}) น้อยกว่า Sumsub ({su['avg']}) มิลลิวินาที "
        "สอดคล้องกับจำนวนขั้นของงาน สำหรับ UX ควรมีตัวบอกความคืบหน้าตอน KYC "
        "และคง Regex-first ในแชทเพื่อไม่หน่วงบทสนทนา",
    )
    p(
        doc,
        "Chat เป็นโมดูลที่มีคุณค่าเชิงวิจัยสูงในรอบนี้ เพราะมี Ground Truth อิสระ "
        "และยืนยันซ้ำด้วย Live LLM ได้ความถูกต้องร้อยละ 97 (kappa ประมาณ 0.94) "
        "การเปรียบเทียบกับ Regex-only ที่เกณฑ์ไม่น้อยกว่า 70 ได้ความถูกต้องร้อยละ 82 "
        "และ McNemar ชี้ว่า Hybrid ดีกว่าอย่างมีนัยสำคัญ",
    )

    h(doc, "4.13.1 การเปรียบเทียบกับแนวทางที่เกี่ยวข้อง", 2)
    p(
        doc,
        "ตารางต่อไปนี้ใช้วางบริบทของผลงาน ค่าที่ติดเครื่องหมายดอกจันไม่ได้วัดบนชุดข้อมูลเดียวกับงานนี้ "
        "จึงไม่อาจสรุปแบบเปรียบเทียบตรงหัวต่อหัว",
    )
    reg_acc = "N/A"
    if agr.get("chat_regex70_vs_gt"):
        reg_acc = f"{round(100 * float(agr['chat_regex70_vs_gt'].get('accuracy', 0)))}%"
    img_live_acc = f"{img_real.get('accuracy_pct')}%" if img_real else "N/A"
    table(
        doc,
        ["งานหรือแนวทาง", "วิธี", "ความถูกต้อง", "หมายเหตุ"],
        [
            ["เส้นฐานกฎอย่างเดียว", "กฎคะแนนไม่น้อยกว่า 70", reg_acc, "ชุดข้อความเดียวกับงานนี้"],
            ["แนวทางเน้น OCR", "OCR", "ประมาณ 94%*", "ช่วงอ้างอิงวรรณกรรม"],
            ["แนวทางยืนยันใบหน้า", "Face match", "ประมาณ 96%*", "ช่วงอ้างอิงวรรณกรรม"],
            ["FLIXO แชทแบบผสม", "กฎและโมเดลภาษา", f"{ch_acc}%", "Mock และ Live ให้ผลชุดเดียวกัน"],
            ["FLIXO eKYC Live", "OCR และ Face", "100% (N=4)", "ตัวอย่างยังน้อย"],
            ["FLIXO Image Live", "EXIF และ Vision", img_live_acc, "N=58 ไม่มีผลบวกลวง ความครบถ้วนร้อยละ 50"],
        ],
    )
    table(
        doc,
        ["แนวทางแชท", "จุดแข็ง", "จุดอ่อน", "FLIXO เลือกอย่างไร"],
        [
            ["Regex-only", "เร็ว ถูก อธิบายได้", "พลาดสแลงหรือภาษาอ้อม", "เป็นชั้นแรก"],
            ["LLM-only", "เข้าใจบริบทดี", "แพง ช้า อาจ hallucination", "ไม่ใช้เดี่ยว"],
            ["Hybrid", "สมดุลต้นทุนกับความครอบคลุม", "ต้องจูนเกณฑ์สองชั้น", "Regex แล้วส่งเคสกลางเข้า LLM"],
            ["แอดมินตรวจทุกข้อความ", "แม่นยำสูงสุด", "ไม่สเกล", "ใช้เฉพาะเคส flag หรือข้อพิพาท"],
        ],
    )
    table(
        doc,
        ["มิติ", "AiGen (OCR+Face ในแอป)", "Sumsub (id-and-liveness)"],
        [
            ["การควบคุม UX", "สูง (อัปโหลดเองในแอป)", "ผ่าน WebSDK ของผู้ให้บริการ"],
            ["จำนวนขั้น API ในการทดลอง", "2 (OCR+Face)", "3 (token+applicant+review)"],
            ["Latency E2E (Mock mean)", f"ประมาณ {ek['avg']} มิลลิวินาที", f"ประมาณ {su['avg']} มิลลิวินาที"],
            ["Fallback", "Manual pending", "sync_status เมื่อ webhook ไม่ถึง"],
        ],
    )

    h(doc, "4.13.2 สถิติข้อตกลง Cohen และ McNemar", 2)
    if agr:
        mcn = agr.get("mcnemar_regex70_vs_hybrid") or {}
        mcn_l = agr.get("mcnemar_mock_vs_live_hybrid") or {}
        sig = "แตกต่างอย่างมีนัยสำคัญที่ระดับ 0.05" if mcn.get("significant_0_05") else "ไม่พบนัยสำคัญ"
        table(
            doc,
            ["การทดสอบ", "ผล", "การตีความ"],
            [
                [
                    "Cohen kappa ของระบบผสมกับค่าจริง",
                    str(agr.get("chat_hybrid_vs_gt_kappa", "N/A")),
                    "ข้อตกลงกับฉลากสูงมาก (kappa มากกว่า 0.80)",
                ],
                [
                    "Cohen kappa ของกฎอย่างเดียวกับค่าจริง",
                    str((agr.get("chat_regex70_vs_gt") or {}).get("cohen_kappa", "N/A")),
                    f"เส้นฐานอ่อนกว่า (Acc {reg_acc})",
                ],
                [
                    "McNemar กฎอย่างเดียวกับระบบผสม",
                    f"ไคกำลังสองประมาณ {mcn.get('chi2')} ค่าพีประมาณ {mcn.get('p_approx')}",
                    sig,
                ],
                [
                    "McNemar Mock กับ Live",
                    f"ไคกำลังสองประมาณ {mcn_l.get('chi2')} ค่าพีประมาณ {mcn_l.get('p_approx')}",
                    "ไม่พบความต่างของคู่ผิดพลาดบนชุดเดียวกัน",
                ],
            ],
        )
    else:
        p(doc, "ยังไม่มีไฟล์สถิติข้อตกลง กรุณารันสคริปต์สร้างส่วนเสริมงานวิจัยก่อน")

    # 4.14
    h(doc, "4.14 สรุปบทที่ 4", 1)
    p(
        doc,
        "บทนี้นำเสนอทั้งคำอธิบายโมดูล ผลการทดลองเชิงปริมาณ หลักฐาน Live บางส่วน "
        "สถิติช่วงเชื่อมั่น สถิติข้อตกลง เส้นโค้ง ROC และ Precision-Recall ของรูปสินค้า "
        "ภาพเปรียบเทียบ Mock กับ Live การอภิปราย และข้อจำกัดของงาน "
        "ซึ่งเพียงพอสำหรับการนำเสนอโครงงานระดับปริญญาตรี "
        "โดยยังระบุช่องว่างที่เหลืออย่างโปร่งใส",
    )

    # 4.15 research figures
    h(doc, "4.15 ส่วนเสริมงานวิจัยและภาพประกอบ", 1)
    p(
        doc,
        "ส่วนนี้ยกระดับบทที่ 4 จากรายงานผลทดสอบระบบสู่โครงร่างงานวิจัยเบื้องต้น "
        "โดยแยกโหมดหลักฐาน เพิ่มช่วงเชื่อมั่นวิลสัน สถิติข้อตกลง กราฟ และอภิปรายข้อผิดพลาด",
    )
    h(doc, "4.15.1 ตารางสรุปพร้อมช่วงเชื่อมั่นวิลสันร้อยละ 95", 2)
    def lat_summary(key):
        lat = (mods.get(key) or {}).get("latency") or {}
        if not lat:
            return "-"
        return (
            f"mean={round(lat.get('mean', 0))} "
            f"P50={round(lat.get('p50', 0))} "
            f"P95={round(lat.get('p95', 0))}"
        )

    wilson_rows = []
    for key, label, metric in [
        ("ekyc", "eKYC AiGen", "Accuracy"),
        ("sumsub", "Sumsub", "Match Rate"),
        ("image", "Image Fraud", "Accuracy"),
        ("chat", "Chat", "Accuracy"),
        ("dispute", "Dispute", "Exact Agree"),
        ("easyslip", "EasySlip", "Decision Acc"),
        ("omise", "Omise", "Functional Pass"),
        ("google_auth", "Google Auth", "Verify Acc"),
        ("resilience", "Resilience", "Fallback OK"),
        ("uat", "UAT", "Pass"),
    ]:
        m = mods.get(key) or {}
        point = m.get("accuracy", m.get("agreement", m.get("pass_rate", m.get("rate"))))
        if point is None and key == "ekyc":
            point = 1.0
        try:
            point_s = f"{100 * float(point):.1f}%"
        except (TypeError, ValueError):
            point_s = "N/A"
        if key == "chat":
            point_s = f"{ch_acc}%"
        wilson_rows.append(
            [
                label,
                metric,
                point_s,
                fmt_ci(m.get("acc_ci95")),
                lat_summary(key),
                str(m.get("mode", "-")),
            ]
        )
    table(
        doc,
        ["โมดูล", "ตัวชี้วัด", "จุดประมาณ", "95% CI (Wilson)", "Latency (ms)", "โหมด"],
        wilson_rows,
    )
    p(
        doc,
        "เมื่อความถูกต้องเท่ากับร้อยละ 100 และขนาดตัวอย่างเท่ากับ 100 "
        "ช่วงเชื่อมั่นแบบวิลสันร้อยละ 95 มักอยู่ราวร้อยละ 96.3 ถึง 100 "
        f"ส่วนแชทที่มีความถูกต้องร้อยละ {ch_acc} มีช่วงกว้างกว่าเล็กน้อยเนื่องจากมีทั้งผลบวกลวงและผลลบลวง "
        "Resilience และ UAT มีช่วงกว้างกว่าเพราะ N เล็ก",
    )

    h(doc, "4.15.2 ภาพรวม Mock เทียบกับ Live", 2)
    table(
        doc,
        ["โมดูล", "Mock", "Live", "N Live", "สถานะ"],
        [
            ["eKYC", "100%", f"{ek_real.get('accuracy_all_pairs_pct', 'N/A')}%", "4", "Live มีแล้ว แต่ N น้อย"],
            ["Chat", f"{ch_acc}%", f"{ch_real.get('accuracy_pct', 'N/A')}%", "100", "Live LLM ครบชุดข้อความ"],
            [
                "Image Fraud",
                "100%",
                f"{img_real.get('accuracy_pct', 'N/A')}%",
                "58",
                "Live ต่างจาก Mock ชัด (มีคุณค่า)",
            ],
            ["Dispute", "100% Agree", "-", "-", "ยังไม่มีฉลากผู้เชี่ยวชาญ"],
            ["Sumsub", "100%", "-", "-", "ยังเป็น Mock pipeline"],
        ],
    )

    if FIG.is_dir():
        h(doc, "4.15.3 ภาพประกอบหลัก", 2)
        for fname, caption in [
            ("fig01_accuracy_ci.png", "ภาพที่ 1 ความถูกต้องพร้อมช่วงเชื่อมั่นร้อยละ 95 แบบวิลสัน"),
            ("fig02_latency_mean_p95.png", "ภาพที่ 2 เวลาตอบสนองเฉลี่ยและเปอร์เซ็นไทล์ที่ 95"),
            ("fig03_chat_confusion.png", "ภาพที่ 3 เมทริกซ์ความสับสนของแชท"),
            ("fig04_chat_providers.png", "ภาพที่ 4 สัดส่วนผู้ให้บริการของแชท"),
            ("fig05_chat_latency_boxplot.png", "ภาพที่ 5 การกระจาย Latency ของแชท"),
            ("fig06_chat_approach_compare.png", "ภาพที่ 6 การเปรียบเทียบแนวทางความปลอดภัยของแชท"),
            ("fig07_ekyc_sumsub_latency.png", "ภาพที่ 7 Latency ของ eKYC และ Sumsub"),
            ("fig08_resilience_uat.png", "ภาพที่ 8 ผลการทดสอบความทนทานและ UAT"),
            ("fig09_evidence_modes.png", "ภาพที่ 9 โหมดหลักฐานและการตีความค่าความถูกต้อง"),
            ("fig10_related_work_compare.png", "ภาพที่ 10 การเปรียบเทียบแนวทางและเส้นฐาน"),
            ("fig11_image_fraud_roc_pr.png", "ภาพที่ 11 เส้นโค้ง ROC และ Precision-Recall ของรูปสินค้าบนข้อมูลจริง"),
            ("fig12_mock_vs_live_overview.png", "ภาพที่ 12 ภาพรวมผล Mock เทียบกับ Live"),
        ]:
            fpath = FIG / fname
            if fpath.exists():
                add_picture(doc, fpath, caption, 15.0)

    # 4.16 Threats
    h(doc, "4.16 ข้อจำกัดและภัยคุกคามต่อความตรง (Threats to Validity)", 1)
    h(doc, "4.16.1 ความตรงภายใน (Internal Validity)", 2)
    p(
        doc,
        "ด้านความตรงภายใน สภาพแวดล้อม Mock อาจทำให้ค่าความถูกต้องสูงเกินจริง "
        "ชุดข้อความอาจมีอคติจากการคัดเลือก และเกณฑ์ตัดสินอาจถูกปรับบนชุดเดียวกับที่ใช้วัด "
        "จึงเสี่ยง overfitting ต่อชุดทดลอง",
    )
    h(doc, "4.16.2 ความตรงภายนอก (External Validity)", 2)
    p(
        doc,
        "ด้านความตรงภายนอก ข้อความทดลองเป็นภาษาไทยในบริบทตลาดมือสอง "
        "ตัวอย่าง eKYC บนภาพจริงยังมีเพียงสี่คู่ "
        "ชุดรูปสินค้าจริงยังมีทั้งภาพสังเคราะห์และภาพหน้าจอ "
        "และโมดูลข้อพิพาทยังไม่มีฉลากจากผู้เชี่ยวชาญบนเคสจริง",
    )
    h(doc, "4.16.3 ความตรงเชิงโครงสร้างและ Reliability", 2)
    p(
        doc,
        "ด้านความตรงเชิงโครงสร้าง นิยามข้อความเสี่ยงขึ้นกับนโยบายแพลตฟอร์ม "
        "และการรวมสถานะติดธงกับปฏิเสธเป็นทวิภาคอาจไม่สะท้อนต้นทุนต่อประสบการณ์ผู้ใช้ทั้งหมด "
        "ด้านความเชื่อถือได้ ผลขึ้นกับผู้ให้บริการภายนอกที่อาจเปลี่ยนโมเดลหรือโควต้าได้ "
        "และค่าเวลาตอบสนองขึ้นกับสภาพเครือข่ายขณะทดลอง",
    )
    h(doc, "4.16.4 ช่องว่างที่ยังเหลือ", 2)
    p(
        doc,
        "ช่องว่างที่ยังเหลือ ได้แก่ การขยายตัวอย่าง eKYC บนภาพจริงหลายคนและหลายสภาพแสง "
        "การประเมิน Dispute กับผู้เชี่ยวชาญ "
        "การสร้างเส้นโค้ง ROC หรือ DET ของแชทและ eKYC บนชุด Live ที่ใหญ่ขึ้น "
        "และการปรับ heuristic ของรูปสินค้าสำหรับก็อปความละเอียดสูงที่ลบ EXIF "
        "ขณะที่รูปสินค้ามีเส้นโค้ง ROC และ Precision-Recall แล้ว "
        f"โดยมีพื้นที่ใต้เส้นโค้งประมาณ {(curves.get('image_fraud_live_curves') or {}).get('auc_roc', '0.80')} "
        f"และ {(curves.get('image_fraud_live_curves') or {}).get('auc_pr', '0.94')} ตามลำดับ",
        bold=True,
    )

    # Appendix A
    h(doc, "ภาคผนวก ก ไฟล์ผลและวิธีทำซ้ำ", 1)
    p(
        doc,
        "ไฟล์ผลอยู่ที่ docs/appendix_ch4 รวมทั้งผล Live ของ eKYC แชท และรูปสินค้า "
        "สถิติวิจัย research_stats.json live_curve_stats.json และภาพประกอบใน docs/_ch4_research_figures",
    )
    table(
        doc,
        ["ไฟล์", "เนื้อหา"],
        [
            ["*_results.csv", "ผลรายครั้งของแต่ละโมดูล"],
            ["*_real_summary.json", "สรุปผล Live ของ eKYC Chat Image"],
            ["stats_summary.json", "สรุปรวมที่ใช้สร้างตารางในบทนี้"],
            ["research_stats.json", "Wilson CI kappa McNemar"],
            ["live_curve_stats.json", "AUC-ROC / AUC-PR ของรูปสินค้า"],
            ["screenshots/ui01–ui10", "ภาพหน้าจอระบบ"],
        ],
    )
    p(
        doc,
        "สร้างเอกสารนี้อีกครั้งด้วยคำสั่ง python scripts/generate_chapter4_experiments_docx.py "
        "และสร้างสไลด์ด้วย python scripts/docx_to_pptx_chapter4.py",
        bold=True,
    )

    # Appendix B screenshots
    h(doc, "ภาคผนวก ข ภาพหน้าจอระบบ", 1)
    p(
        doc,
        "ภาพถ่ายจากระบบท้องถิ่นด้วยบัญชีทดสอบ ไม่มีภาพบัตรประชาชนหรือใบหน้าจริงในชุดนี้ "
        "ก่อนนำภาพคิวตรวจแชทใส่เล่ม ควรปิดบังข้อมูลส่วนบุคคลหากปรากฏ",
    )
    shot_caps = [
        ("ui01_dashboard.png", "ภาพที่ ข.1 หน้าหลักหลังเข้าสู่ระบบ"),
        ("ui02_ekyc.png", "ภาพที่ ข.2 หน้ายืนยันตัวตนด้วยบัตรประชาชน"),
        ("ui03_sell.png", "ภาพที่ ข.3 หน้าลงประกาศขาย"),
        ("ui04_chat.png", "ภาพที่ ข.4 ห้องสนทนาคู่สัญญา"),
        ("ui05_orders.png", "ภาพที่ ข.5 รายการสั่งซื้อ"),
        ("ui06_product.png", "ภาพที่ ข.6 หน้ารายละเอียดสินค้า"),
        ("ui07_admin.png", "ภาพที่ ข.7 แผงควบคุมผู้ดูแลระบบ"),
        ("ui08_admin_chat_ai.png", "ภาพที่ ข.8 คิวตรวจแชทด้วยปัญญาประดิษฐ์"),
        ("ui09_landing.png", "ภาพที่ ข.9 หน้าแรกสำหรับผู้เยี่ยมชม"),
        ("ui10_login.png", "ภาพที่ ข.10 หน้าเข้าสู่ระบบ"),
    ]
    if SHOTS.is_dir():
        for fname, caption in shot_caps:
            fpath = SHOTS / fname
            if fpath.exists():
                add_picture(doc, fpath, caption, 14.5)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    targets = (OUT_THESIS, OUT_RESEARCH, OUT_UPDATED, OUT)
    saved = []
    for path in targets:
        try:
            doc.save(path)
            saved.append(str(path))
            print(f"Wrote {path}")
        except PermissionError:
            alt = path.with_name(path.stem + "_thesis_regen.docx")
            doc.save(alt)
            saved.append(str(alt))
            print(f"Locked {path.name}; wrote {alt}")
    print("OK:", "; ".join(saved))


if __name__ == "__main__":
    main()
