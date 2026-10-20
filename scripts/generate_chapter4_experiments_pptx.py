# -*- coding: utf-8 -*-
"""Generate dense Chapter 4 Experiments PowerPoint (thesis DOCX parity)."""

from __future__ import annotations

import csv
import json
from pathlib import Path

from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN
from pptx.util import Inches, Pt

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_thesis.pptx"
APP = ROOT / "docs" / "appendix_ch4"
FIG = ROOT / "docs" / "_ch4_research_figures"
SHOTS = APP / "screenshots"
TESTS = ROOT / "tests"

NAVY = RGBColor(0x1B, 0x3A, 0x4B)
TEAL = RGBColor(0x2C, 0x6E, 0x8A)
SLATE = RGBColor(0x3D, 0x4F, 0x5F)
INK = RGBColor(0x1A, 0x1A, 0x1A)
MUTED = RGBColor(0x5A, 0x6A, 0x75)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT = RGBColor(0xF3, 0xF6, 0xF8)
WARN = RGBColor(0x8A, 0x4B, 0x12)
ROW_ALT = RGBColor(0xE8, 0xEE, 0xF2)
LINE = RGBColor(0xD5, 0xDE, 0xE5)
CREAM = RGBColor(0xFC, 0xF3, 0xE8)


def jload(path: Path, default=None):
    if not path.exists():
        return {} if default is None else default
    return json.loads(path.read_text(encoding="utf-8"))


def csv_rows(path: Path, limit=8):
    if not path.exists():
        return [], []
    with path.open(encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        rows = list(reader)
    return reader.fieldnames or [], rows[:limit]


def set_run(run, size=15, bold=False, color=INK):
    run.font.name = "TH Sarabun New"
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
    spTree = slide.shapes._spTree
    sp = shape._element
    spTree.remove(sp)
    spTree.insert(2, sp)


def add_bar(slide):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(0.12)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = NAVY
    shape.line.fill.background()


def footer(slide, page, total, section=""):
    box = slide.shapes.add_textbox(Inches(0.4), Inches(7.12), Inches(12.5), Inches(0.28))
    tf = box.text_frame
    tf.clear()
    p = tf.paragraphs[0]
    run = p.add_run()
    run.text = f"FLIXO Escrow · บทที่ 4 ผลการทดลอง · {section} · {page}/{total}"
    set_run(run, 10, False, MUTED)
    p.alignment = PP_ALIGN.RIGHT


def textbox(slide, left, top, width, height, text, size=15, bold=False, color=INK, align=PP_ALIGN.LEFT):
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


def bullets(slide, left, top, width, height, items, size=14, color=INK):
    box = slide.shapes.add_textbox(Inches(left), Inches(top), Inches(width), Inches(height))
    tf = box.text_frame
    tf.word_wrap = True
    tf.clear()
    for i, item in enumerate(items):
        p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
        p.space_after = Pt(3)
        run = p.add_run()
        run.text = f"•  {item}"
        set_run(run, size, False, color)


def warn_box(slide, top, text, height=1.3):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.45), Inches(top), Inches(12.4), Inches(height)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = CREAM
    shape.line.color.rgb = WARN
    textbox(slide, 0.65, top + 0.2, 12.0, height - 0.3, text, 13, True, WARN)


def section_title(slide, title, subtitle=""):
    textbox(slide, 0.45, 0.28, 12.4, 0.4, title, 24, True, NAVY)
    if subtitle:
        textbox(slide, 0.45, 0.72, 12.4, 0.35, subtitle, 13, False, SLATE)


def metric_cards(slide, items, top=1.15, height=1.1):
    n = max(len(items), 1)
    w = 12.3 / n
    for i, (label, value) in enumerate(items):
        x = 0.45 + i * w
        shape = slide.shapes.add_shape(
            MSO_SHAPE.ROUNDED_RECTANGLE, Inches(x), Inches(top), Inches(w - 0.12), Inches(height)
        )
        shape.fill.solid()
        shape.fill.fore_color.rgb = WHITE
        shape.line.color.rgb = LINE
        textbox(slide, x + 0.05, top + 0.1, w - 0.22, 0.3, label, 11, False, MUTED, PP_ALIGN.CENTER)
        textbox(slide, x + 0.05, top + 0.42, w - 0.22, 0.55, str(value), 18, True, NAVY, PP_ALIGN.CENTER)


def table_grid(slide, headers, rows, left=0.45, top=1.15, col_widths=None, font=12, row_h=0.48):
    if not col_widths:
        col_widths = [12.4 / len(headers)] * len(headers)
    x = left
    for h, w in zip(headers, col_widths):
        shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(top), Inches(w), Inches(0.42))
        shape.fill.solid()
        shape.fill.fore_color.rgb = NAVY
        shape.line.fill.background()
        textbox(slide, x + 0.05, top + 0.05, w - 0.08, 0.32, h, font, True, WHITE)
        x += w
    for ri, row in enumerate(rows):
        y = top + 0.42 + ri * row_h
        x = left
        bg = WHITE if ri % 2 == 0 else ROW_ALT
        for cell, w in zip(row, col_widths):
            shape = slide.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(x), Inches(y), Inches(w), Inches(row_h))
            shape.fill.solid()
            shape.fill.fore_color.rgb = bg
            shape.line.color.rgb = LINE
            textbox(slide, x + 0.05, y + 0.08, w - 0.08, row_h - 0.12, str(cell), font, False, INK)
            x += w


def add_picture_safe(slide, path: Path, left, top, width):
    if path.exists():
        slide.shapes.add_picture(str(path), Inches(left), Inches(top), width=Inches(width))
        return True
    textbox(slide, left, top, width, 0.5, f"(ไม่พบภาพ {path.name})", 12, False, MUTED)
    return False


def as_pct(v):
    try:
        x = float(v)
    except (TypeError, ValueError):
        return "N/A"
    if abs(x) <= 1.0:
        x *= 100
    return f"{x:.1f}"


def confusion_2x2(slide, tp, tn, fp, fn, left=0.45, top=2.5, title="เมทริกซ์ความสับสน"):
    textbox(slide, left, top, 6, 0.35, title, 14, True, NAVY)
    table_grid(
        slide,
        ["", "ทำนาย Positive", "ทำนาย Negative"],
        [
            ["จริง Positive", f"TP = {tp}", f"FN = {fn}"],
            ["จริง Negative", f"FP = {fp}", f"TN = {tn}"],
        ],
        left=left,
        top=top + 0.4,
        col_widths=[2.2, 2.2, 2.2],
        font=13,
        row_h=0.55,
    )


class Deck:
    def __init__(self, prs):
        self.prs = prs
        self.slides = []
        self.sections = []

    def add(self, section=""):
        s = self.prs.slides.add_slide(self.prs.slide_layouts[6])
        self.slides.append(s)
        self.sections.append(section)
        return s

    def finish_footers(self):
        total = len(self.slides)
        for i, s in enumerate(self.slides, 1):
            footer(s, i, total, self.sections[i - 1])


def build(data):
    prs = Presentation()
    prs.slide_width = Inches(13.333)
    prs.slide_height = Inches(7.5)
    d = Deck(prs)

    stats = data["stats"]
    ek, ch, im, di, su = stats["ekyc"], stats["chat"], stats["image"], stats["dispute"], stats["sumsub"]
    es, om, ga = stats.get("easyslip", {}), stats.get("omise", {}), stats.get("google_auth", {})
    rs, ua = stats.get("resilience", {}), stats.get("uat", {})
    ek_real, ch_real, img_real = data["ek_real"], data["ch_real"], data["img_real"]
    curves, agr = data["curves"], data["agr"]
    mock_live = curves.get("mock_vs_live") or {}
    ch_acc = ch.get("accuracy")
    if ch_acc is None:
        ch_acc = round(100 * (ch["tp"] + ch["tn"]) / max(ch.get("n", 100), 1))
    prov = ch.get("providers") or {}

    # ---- 1 Title ----
    s = d.add("ปก")
    add_bg(s, NAVY)
    add_bar(s)
    textbox(s, 0.7, 1.7, 12, 0.4, "โครงการ FLIXO Escrow · ฉบับจัดหน้าวิทยานิพนธ์", 16, False, RGBColor(0xB8, 0xC9, 0xD4))
    textbox(s, 0.7, 2.3, 12, 0.8, "บทที่ 4  ผลการทดลองและการใช้งานจริง", 32, True, WHITE)
    textbox(
        s,
        0.7,
        3.4,
        12,
        2.2,
        "รายงานเชิงปริมาณ N=100 ต่อโมดูลหลัก\n"
        "หลักฐาน Live: eKYC (4 คู่) · Chat LLM จริง (N=100) · Image Fraud (N=58)\n"
        "สถิติ: Wilson CI · Cohen κ · McNemar · ROC/PR\n"
        "Resilience 10/10 · UAT 12/12 · ภาพหน้าจอระบบ",
        16,
        False,
        RGBColor(0xC9, 0xD7, 0xE0),
    )

    # ---- 2 Agenda ----
    s = d.add("สารบัญ")
    add_bg(s)
    add_bar(s)
    section_title(s, "โครงสไลด์ (สอดคล้องรายงานวิทยานิพนธ์)")
    table_grid(
        s,
        ["ส่วน", "หัวข้อในเล่ม", "เนื้อหาหลัก"],
        [
            ["ก", "4.1–4.2", "วัตถุประสงค์ สภาพแวดล้อม ขอบเขตตีความ Mock"],
            ["ข", "4.3–4.6", "Google Auth · AiGen OCR/Face · Sumsub · Manual"],
            ["ค", "4.7", "Image Fraud Mock/Live · subgroup · ROC/PR"],
            ["ง", "4.8–4.9", "Chat Mock/Live · Error Analysis · Dispute"],
            ["จ", "4.10–4.12", "ชำระเงิน · สรุปรวม · Resilience · UAT"],
            ["ฉ", "4.13–4.16", "อภิปราย Related Work · κ/McNemar · Threats"],
            ["ช", "ภาคผนวก", "กราฟวิจัย · ภาพหน้าจอ · ไฟล์ทำซ้ำ"],
        ],
        col_widths=[1.2, 2.5, 8.7],
        top=1.2,
        row_h=0.65,
        font=13,
    )

    # ---- 3 Objectives ----
    s = d.add("4.1")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.1  วัตถุประสงค์ของการทดลอง")
    bullets(
        s,
        0.6,
        1.3,
        12.2,
        5.2,
        [
            "อธิบายสถาปัตยกรรมและการทำงานของโมดูล AI/API ภายนอกให้สอดคล้องกับการออกแบบในบทที่ 3",
            "ทดลองเชิงปริมาณ N=100 ต่อโมดูลหลัก: eKYC, Sumsub, Image, Chat, Dispute, EasySlip, Omise, Google Auth",
            "รายงาน Confusion Matrix, Accuracy, Precision, Recall, F1, Latency (Avg, P95)",
            "ยืนยันผลบางส่วนบนข้อมูลจริง: eKYC ภาพจริง, Chat ด้วย LLM จริง, Image Fraud ภาพจริง (+ROC/PR)",
            "ประเมิน Fallback/Resilience เมื่อบริการภายนอกล้มเหลว และ UAT ตาม flow ผู้ใช้จริง",
            "จัดทำหลักฐานเชิงประจักษ์สำหรับอภิปรายและขยายเป็นงานวิจัยต่อ",
        ],
        15,
        SLATE,
    )

    # ---- 4 Environment ----
    s = d.add("4.2")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.2  สภาพแวดล้อมและขอบเขตการตีความผล")
    table_grid(
        s,
        ["รายการ", "ค่าที่ใช้"],
        [
            ["ระบบ", "FLIXO Escrow (PHP / XAMPP)"],
            ["APP_ENV", "local"],
            ["โหมดทดลองหลัก", "MOCK_API_MODE = true (ชั้นคลาวด์จำลอง)"],
            ["Chat Mock", "Regex จริง + Mock LLM"],
            ["หลักฐาน Live", "eKYC ภาพจริง · Chat Groq/OpenRouter · Image EXIF+Vision"],
            ["ไฟล์ผล", "docs/appendix_ch4/*.csv|json"],
            ["สคริปต์", "tests/test_*_100_runs.php · test_*_real.php"],
        ],
        col_widths=[3.2, 9.2],
        top=1.15,
        row_h=0.52,
        font=12,
    )
    warn_box(
        s,
        5.15,
        "ข้อควรตระหนัก: Acc/Agreement 100% ใน Mock ใช้ยืนยันความถูกต้องของ pipeline เป็นหลัก\n"
        "ไม่ใช่การรับรองความแม่นยำของโมเดล AI บนประชากรผู้ใช้จริง",
        1.5,
    )

    # ---- 5 Module index ----
    s = d.add("4.2.1")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.2.1  สารบัญโมดูลและลักษณะการทดลอง")
    table_grid(
        s,
        ["#", "โมดูล", "ประเภท", "ทดลอง", "ตัวชี้วัดหลัก"],
        [
            ["1", "AiGen OCR+Face", "eKYC", "Mock 100 + Live 4", "TP/TN Acc Latency"],
            ["2", "Sumsub", "eKYC", "Mock 100", "Acc E2E Latency"],
            ["3", "Manual eKYC", "Fallback", "Resilience", "pending/review"],
            ["4", "EXIF+Vision", "รูปสินค้า", "Mock 100 + Live 58", "Acc P/R ROC"],
            ["5", "Regex+LLM", "แชท", "Mock+Live 100", "Acc P/R F1 κ"],
            ["6", "Dispute AI", "ข้อพิพาท", "Mock 100", "Agreement"],
            ["7", "EasySlip", "สลิป", "Fixture 100", "Acc false-autopay"],
            ["8", "Omise", "ชำระเงิน", "Sandbox 100", "pass_rate"],
            ["9", "Google Auth", "เข้าสู่ระบบ", "Certs 100", "Acc false-accept"],
        ],
        col_widths=[0.8, 2.6, 2.0, 2.8, 4.2],
        top=1.15,
        row_h=0.52,
        font=12,
    )

    # ---- 6 Google ----
    s = d.add("4.3")
    add_bg(s)
    add_bar(s)
    section_title(
        s,
        "4.3  การเข้าสู่ระบบด้วย Google Sign-In",
        "แยกจาก eKYC อย่างชัดเจน — ไม่ใช้สแกนใบหน้าแทนการล็อกอิน",
    )
    metric_cards(
        s,
        [
            ("N", ga.get("n", 100)),
            ("Accuracy", f"{ga.get('accuracy', 100)}%"),
            ("False accept", ga.get("false_accept", 0)),
            ("Avg / P95", f"{ga.get('avg')} / {ga.get('p95')} ms"),
            ("โหมด", "certs live + neg"),
        ],
    )
    bullets(
        s,
        0.55,
        2.6,
        12.2,
        3.8,
        [
            "ฝั่งลูกค้าได้ ID Token จาก Google Identity Services",
            "ฝั่งเซิร์ฟเวอร์ตรวจลายเซ็นผ่านชุดใบรับรองสาธารณะ (oauth2/v1/certs)",
            "ค่าปรับแต่ง: GOOGLE_CLIENT_ID",
            "หากตรวจไม่ผ่าน ผู้ใช้ยังเข้าสู่ระบบด้วยรหัสผ่านได้",
            f"โหมดทดลอง: {ga.get('mode', '')} · certs_success {ga.get('certs_success_pct', 'N/A')}%",
        ],
        15,
        SLATE,
    )

    # ---- 7 OCR ----
    s = d.add("4.4")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.4  การอ่านบัตรประชาชนด้วย AiGen Thai ID OCR")
    bullets(
        s,
        0.55,
        1.25,
        12.2,
        5.2,
        [
            "Endpoint: POST {AIGEN_API_BASE}/aiscript/idcard/v3",
            "อินพุต: ภาพบัตรประชาชนไทย (ไม่ได้ฝึกโมเดลภายในโครงการ)",
            "ผลลัพธ์หลักที่ใช้ต่อ: เลขบัตร + ชื่อภาษาไทย",
            "หากอ่านไม่สำเร็จ → rejected หรือส่ง Manual เมื่อกระบวนการล้มเหลวทั้งชุด",
            "เชื่อมต่อไปยัง Face Compare เฉพาะเมื่อ OCR ได้ฟิลด์ครบ",
            "Timeout บริการประมาณ 90 วินาที · บันทึก log โดยปิดบังเลขบัตร",
        ],
        16,
        SLATE,
    )

    # ---- 8 Face Mock ----
    s = d.add("4.5 Mock")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.5  AiGen Face Compare — Mock N=100", "เกณฑ์คะแนน AIGEN_FACE_THRESHOLD = 80")
    metric_cards(
        s,
        [
            ("N", ek.get("n", 100)),
            ("TP/TN", f"{ek['tp']}/{ek['tn']}"),
            ("FP/FN", f"{ek['fp']}/{ek['fn']}"),
            ("Acc", "100%"),
            ("Avg/P95", f"{ek['avg']}/{ek['p95']} ms"),
        ],
    )
    confusion_2x2(s, ek["tp"], ek["tn"], ek["fp"], ek["fn"], top=2.5)
    bullets(
        s,
        7.5,
        2.5,
        5.3,
        3.8,
        [
            "Endpoint: /aiface/face-compare/v3",
            "อินพุต: ใบหน้าบนบัตร + selfie กล้อง",
            "G1 Genuine 50 · G2 Impostor 50",
            "คะแนน ≥ 80 → approved",
            "คะแนน < 80 → rejected",
            "API ล่ม → pending / Manual",
        ],
        14,
        SLATE,
    )

    # ---- 9 eKYC Live ----
    s = d.add("4.5.1 Live")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.5.1  eKYC บนภาพจริง (Live)", "สอดคล้องหัวข้อ 4.3.8 — 2 คน / 4 คู่ · MOCK_API=false")
    if ek_real:
        metric_cards(
            s,
            [
                ("คู่ภาพ", ek_real.get("n_pairs", 4)),
                ("TP/TN/FP/FN", f"{ek_real.get('tp')}/{ek_real.get('tn')}/{ek_real.get('fp')}/{ek_real.get('fn')}"),
                ("Acc", f"{ek_real.get('accuracy_all_pairs_pct')}%"),
                ("Avg E2E", f"{ek_real.get('avg_e2e_ms')} ms"),
                ("P95", f"{ek_real.get('p95_e2e_ms')} ms"),
            ],
        )
        bullets(
            s,
            0.55,
            2.55,
            12.2,
            3.8,
            [
                "คู่ตรงกัน: คะแนนใบหน้าประมาณ 98–100 → อนุมัติ",
                "คู่สลับบุคคล: คะแนน 0 → ปฏิเสธ",
                "ทิศทางสอดคล้องเกณฑ์ 80 แต่ N=4 ยังไม่อาจสรุปประชากรกว้าง",
                f"Subjects: {ek_real.get('subjects', '')}",
                f"CSV: {ek_real.get('csv', 'tests/ekyc_real_results.csv')}",
            ],
            14,
            SLATE,
        )

    # ---- 10 eKYC sample rows ----
    s = d.add("4.5 ตัวอย่าง")
    add_bg(s)
    add_bar(s)
    section_title(s, "ตัวอย่างผล eKYC Mock (จาก stats_summary)")
    sample = (ek.get("rows_sample") or [])[:6]
    rows = []
    for r in sample:
        rows.append(
            [
                r.get("No.", ""),
                r.get("Group", ""),
                r.get("GT Match (1/0)", ""),
                r.get("Face Score", ""),
                r.get("KYC Status", ""),
                r.get("E2E ms", ""),
                (r.get("Note", "") or "")[:28],
            ]
        )
    if rows:
        table_grid(
            s,
            ["No.", "Group", "GT", "Face", "Status", "E2E", "Note"],
            rows,
            col_widths=[0.9, 1.2, 0.9, 1.3, 1.8, 1.3, 5.0],
            top=1.2,
            row_h=0.7,
            font=12,
        )

    # ---- 11 Sumsub ----
    s = d.add("4.6")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.6  Sumsub Identity / Liveness + Manual eKYC")
    metric_cards(
        s,
        [
            ("N", su.get("n", 100)),
            ("Pipeline Acc", f"{su.get('acc', 100)}%"),
            ("Avg E2E", f"{su.get('avg')} ms"),
            ("P95", f"{su.get('p95')} ms"),
            ("ขั้น API", "3 ขั้น"),
        ],
    )
    table_grid(
        s,
        ["ขั้น", "การทำงาน", "ผล"],
        [
            ["1 Token", "ขอ SDK access token", "เริ่ม WebSDK"],
            ["2 Applicant", "สร้าง/ผูกผู้สมัคร", "ekyc_applicant_id"],
            ["3 Review", "รับ GREEN/RED ผ่าน webhook/sync", "approved/rejected"],
            ["Manual", "ไม่มี key / AiGen ล่ม", "pending + เอกสารเข้ารหัส"],
        ],
        col_widths=[2.2, 5.5, 4.7],
        top=2.55,
        row_h=0.7,
        font=13,
    )

    # ---- 12 Image Mock ----
    s = d.add("4.7 Mock")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.7  ตรวจสอบรูปสินค้า — Mock N=100", "EXIF (ชั้น 1) + Cloud Vision (ชั้น 2) · flag≥50 · reject≥75")
    metric_cards(
        s,
        [
            ("N", im.get("n", 100)),
            ("TP/TN", f"{im['tp']}/{im['tn']}"),
            ("FP/FN", f"{im['fp']}/{im['fn']}"),
            ("Acc", "100%"),
            ("Avg/P95", f"{im['avg']}/{im['p95']} ms"),
        ],
    )
    confusion_2x2(s, im["tp"], im["tn"], im["fp"], im["fn"], top=2.5)
    sample = (im.get("rows_sample") or [])[:4]
    rows = [
        [
            r.get("No.", ""),
            r.get("Group", ""),
            r.get("Risk Score", ""),
            r.get("Verdict", ""),
            r.get("Vision Used (0/1)", ""),
            r.get("Latency ms", ""),
        ]
        for r in sample
    ]
    if rows:
        textbox(s, 7.4, 2.5, 5.4, 0.35, "ตัวอย่างผล Mock", 13, True, NAVY)
        table_grid(
            s,
            ["No.", "G", "Risk", "Verdict", "Vis", "ms"],
            rows,
            left=7.4,
            top=2.9,
            col_widths=[0.7, 0.8, 0.9, 1.1, 0.7, 1.0],
            font=11,
            row_h=0.55,
        )

    # ---- 13 Image Live metrics ----
    s = d.add("4.7.1 Live")
    add_bg(s)
    add_bar(s)
    section_title(
        s,
        "4.7.1  Image Fraud Live N=58",
        "สอดคล้องหัวข้อ 4.4.6 — กล้อง 16 · noEXIF 16 · tiny 16 · screenshot 10",
    )
    if img_real:
        metric_cards(
            s,
            [
                ("N", img_real.get("n")),
                ("Acc", f"{img_real.get('accuracy_pct')}%"),
                ("Precision", f"{as_pct(img_real.get('precision'))}%"),
                ("Recall", f"{as_pct(img_real.get('recall'))}%"),
                ("TP/TN/FP/FN", f"{img_real.get('tp')}/{img_real.get('tn')}/{img_real.get('fp')}/{img_real.get('fn')}"),
            ],
        )
        confusion_2x2(
            s,
            img_real.get("tp"),
            img_real.get("tn"),
            img_real.get("fp"),
            img_real.get("fn"),
            top=2.5,
            title="Confusion (Live)",
        )
        bullets(
            s,
            7.4,
            2.5,
            5.3,
            3.8,
            [
                f"Avg/P95: {img_real.get('avg_ms')} / {img_real.get('p95_ms')} ms",
                f"Vision configured: {img_real.get('vision_configured')}",
                "FP = 0 (ไม่ฟ้องรูปกล้อง)",
                "Recall 50% — จุดทำงานระวัง",
                "ผล Live ต่ำกว่า Mock ชัดเจน",
                "มีคุณค่าต่อ Error Analysis",
            ],
            13,
            SLATE,
        )

    # ---- 14 Image subgroups ----
    s = d.add("4.7.1 กลุ่มย่อย")
    add_bg(s)
    add_bar(s)
    section_title(s, "ผลแยกตามชนิดภาพ (Image Live) — สำคัญต่อการอภิปราย")
    sg = (img_real or {}).get("subgroups") or {}
    table_grid(
        s,
        ["ชนิด", "N", "TP", "TN", "FP", "FN", "Acc%", "Vision calls", "Avg ms"],
        [
            [
                "clean_camera",
                sg.get("clean_camera", {}).get("n", ""),
                sg.get("clean_camera", {}).get("tp", ""),
                sg.get("clean_camera", {}).get("tn", ""),
                sg.get("clean_camera", {}).get("fp", ""),
                sg.get("clean_camera", {}).get("fn", ""),
                sg.get("clean_camera", {}).get("accuracy_pct", ""),
                sg.get("clean_camera", {}).get("vision_calls", ""),
                sg.get("clean_camera", {}).get("avg_ms", ""),
            ],
            [
                "noexif",
                sg.get("noexif", {}).get("n", ""),
                sg.get("noexif", {}).get("tp", ""),
                sg.get("noexif", {}).get("tn", ""),
                sg.get("noexif", {}).get("fp", ""),
                sg.get("noexif", {}).get("fn", ""),
                sg.get("noexif", {}).get("accuracy_pct", ""),
                sg.get("noexif", {}).get("vision_calls", ""),
                sg.get("noexif", {}).get("avg_ms", ""),
            ],
            [
                "tiny",
                sg.get("tiny", {}).get("n", ""),
                sg.get("tiny", {}).get("tp", ""),
                sg.get("tiny", {}).get("tn", ""),
                sg.get("tiny", {}).get("fp", ""),
                sg.get("tiny", {}).get("fn", ""),
                sg.get("tiny", {}).get("accuracy_pct", ""),
                sg.get("tiny", {}).get("vision_calls", ""),
                sg.get("tiny", {}).get("avg_ms", ""),
            ],
            [
                "screenshot",
                sg.get("screenshot", {}).get("n", ""),
                sg.get("screenshot", {}).get("tp", ""),
                sg.get("screenshot", {}).get("tn", ""),
                sg.get("screenshot", {}).get("fp", ""),
                sg.get("screenshot", {}).get("fn", ""),
                sg.get("screenshot", {}).get("accuracy_pct", ""),
                sg.get("screenshot", {}).get("vision_calls", ""),
                sg.get("screenshot", {}).get("avg_ms", ""),
            ],
        ],
        col_widths=[2.0, 0.9, 0.9, 0.9, 0.9, 0.9, 1.3, 1.8, 1.8],
        top=1.2,
        row_h=0.7,
        font=12,
    )
    bullets(
        s,
        0.55,
        4.4,
        12.2,
        2.2,
        [
            "clean: Acc 100% FP=0 — specificity ดีบนรูปกล้อง",
            "tiny: Acc 100% — heuristic ขนาดไฟล์ทำงาน",
            "noexif: Acc 0% — ภาพลบ EXIF ความละเอียดสูงหลุดเป็นกลุ่ม",
            "screenshot: Acc 50% — จับได้บางส่วน",
        ],
        14,
        SLATE,
    )

    # ---- 15 ROC numbers ----
    s = d.add("4.7.2 ROC")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.7.2  ROC / PR และจุดทำงาน (Image Live)")
    ic = curves.get("image_fraud_live_curves") or {}
    op = ic.get("operating_points") or {}
    metric_cards(
        s,
        [
            ("AUC-ROC", f"{ic.get('auc_roc', 0):.3f}"),
            ("AUC-PR", f"{ic.get('auc_pr', 0):.3f}"),
            ("Pos/Neg", f"{ic.get('positives')}/{ic.get('negatives')}"),
            ("N", ic.get("n", 58)),
        ],
    )
    flag = op.get("flag_threshold") or {}
    block = op.get("block_threshold") or {}
    table_grid(
        s,
        ["จุดทำงาน", "Threshold", "TP", "FP", "TN", "FN", "Precision", "Recall", "FPR"],
        [
            [
                "flag",
                flag.get("threshold", 50),
                flag.get("tp", ""),
                flag.get("fp", ""),
                flag.get("tn", ""),
                flag.get("fn", ""),
                flag.get("precision", ""),
                flag.get("recall", ""),
                flag.get("fpr", ""),
            ],
            [
                "block",
                block.get("threshold", 75),
                block.get("tp", ""),
                block.get("fp", ""),
                block.get("tn", ""),
                block.get("fn", ""),
                block.get("precision", ""),
                block.get("recall", ""),
                block.get("fpr", ""),
            ],
        ],
        col_widths=[1.4, 1.4, 1.0, 1.0, 1.0, 1.0, 1.6, 1.4, 1.4],
        top=2.55,
        row_h=0.7,
        font=12,
    )
    warn_box(
        s,
        4.5,
        "จุดทำงานปัจจุบันรักษา FPR=0 แต่ Recall ที่ flag=50 ยังแค่ 0.50\n"
        "ถ้าลด threshold เพื่อดึง Recall ขึ้น ต้องระวัง FPR",
        1.5,
    )

    # ---- 16 ROC figure ----
    s = d.add("ภาพที่ 11")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาพที่ 11  เส้นโค้ง ROC และ Precision-Recall")
    add_picture_safe(s, FIG / "fig11_image_fraud_roc_pr.png", 1.8, 1.2, 9.6)

    # ---- 17 Mock vs Live overview fig ----
    s = d.add("ภาพที่ 12")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาพที่ 12  ภาพรวม Mock เทียบกับ Live")
    add_picture_safe(s, FIG / "fig12_mock_vs_live_overview.png", 1.5, 1.2, 10.2)
    if mock_live:
        textbox(s, 0.5, 6.4, 12.3, 0.5, f"สรุปจาก live_curve_stats: {json.dumps(mock_live, ensure_ascii=False)[:180]}", 11, False, MUTED)

    # ---- 18 Chat Mock ----
    s = d.add("4.8 Mock")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.8  กลั่นกรองข้อความในแชท — Mock N=100", "Regex ชั้นแรก → Groq/OpenRouter/Gemini เมื่อคะแนนกลาง")
    metric_cards(
        s,
        [
            ("Acc", f"{ch_acc}%"),
            ("Precision", f"{as_pct(ch.get('precision'))}%"),
            ("Recall", f"{as_pct(ch.get('recall'))}%"),
            ("F1", f"{as_pct(ch.get('f1'))}"),
            ("TP/TN/FP/FN", f"{ch['tp']}/{ch['tn']}/{ch['fp']}/{ch['fn']}"),
        ],
    )
    metric_cards(
        s,
        [
            ("Avg ms", ch.get("avg")),
            ("P95 ms", ch.get("p95")),
            ("Provider none", prov.get("none", 0)),
            ("Provider groq", prov.get("groq", 0)),
            ("AI invoke", f"{prov.get('groq', 0)}%"),
        ],
        top=2.45,
    )
    confusion_2x2(s, ch["tp"], ch["tn"], ch["fp"], ch["fn"], top=3.85, title="Confusion Chat Mock")

    # ---- 19 Chat misses ----
    s = d.add("4.8 Error")
    add_bg(s)
    add_bar(s)
    section_title(s, "การวิเคราะห์กรณีพลาด (Chat Mock = Live)")
    misses = ch.get("miss") or ch_real.get("misses") or []
    rows = []
    for m in misses:
        rows.append(
            [
                m.get("no") or m.get("No.", ""),
                m.get("group") or m.get("Group", ""),
                m.get("gt", ""),
                m.get("verdict", ""),
                m.get("provider", ""),
                (m.get("text") or "")[:55],
            ]
        )
    table_grid(
        s,
        ["No.", "กลุ่ม", "GT", "ผลระบบ", "Provider", "ข้อความ"],
        rows,
        col_widths=[1.0, 1.1, 0.9, 1.4, 1.5, 6.5],
        top=1.2,
        row_h=0.85,
        font=12,
    )
    bullets(
        s,
        0.55,
        4.3,
        12.2,
        2.2,
        [
            "FN: สแลง/รูปแบบ Line ID ที่ Regex ไม่ครอบคลุม",
            "FP: ข้อความ Safe ที่มีคำว่า “ผ่านแอป” ถูก LLM ให้คะแนนเข้าเกณฑ์ flag",
            "สรุป: ปัญหาชั้นกฎ+เกณฑ์ ไม่ใช่แค่ artifact ของ Mock",
        ],
        14,
        SLATE,
    )

    # ---- 20 Chat Live ----
    s = d.add("4.8.1 Live")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.8.1  Chat ด้วยโมเดลภาษาจริง (Live N=100)", "สอดคล้องหัวข้อ 4.5.7")
    if ch_real:
        metric_cards(
            s,
            [
                ("Acc", f"{ch_real.get('accuracy_pct')}%"),
                ("Precision", f"{as_pct(ch_real.get('precision'))}%"),
                ("Recall", f"{as_pct(ch_real.get('recall'))}%"),
                ("F1", f"{as_pct(ch_real.get('f1'))}"),
                ("Δ vs Mock", f"{ch_real.get('compare_to_mock', {}).get('delta_accuracy_pp', 0)} pp"),
            ],
        )
        crp = ch_real.get("providers") or {}
        table_grid(
            s,
            ["Provider", "จำนวน", "สัดส่วนโดยประมาณ"],
            [
                [k, str(v), f"{round(100 * v / max(ch_real.get('n', 100), 1))}%"]
                for k, v in crp.items()
            ],
            col_widths=[3.5, 2.5, 3.5],
            top=2.55,
            row_h=0.6,
        )
        textbox(
            s,
            0.55,
            4.8,
            12.2,
            1.5,
            f"Avg/P95 = {ch_real.get('avg_ms')} / {ch_real.get('p95_ms')} ms · MOCK_API={ch_real.get('mock_api_mode')}\n"
            "ผล Live ตรงกับ Mock ทั้ง Confusion และเคสพลาดทั้ง 3 ข้อ",
            14,
            False,
            SLATE,
        )

    # ---- 21 Chat figures ----
    s = d.add("กราฟ Chat")
    add_bg(s)
    add_bar(s)
    section_title(s, "กราฟประกอบ Chat (ภาพที่ 3–6)")
    add_picture_safe(s, FIG / "fig03_chat_confusion.png", 0.4, 1.15, 6.1)
    add_picture_safe(s, FIG / "fig04_chat_providers.png", 6.7, 1.15, 6.1)
    add_picture_safe(s, FIG / "fig06_chat_approach_compare.png", 3.2, 4.15, 6.8)

    # ---- 22 Dispute ----
    s = d.add("4.9")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.9  ผู้ช่วยวิเคราะห์ข้อพิพาท — Mock N=100")
    metric_cards(
        s,
        [
            ("N", di.get("n", 100)),
            ("Agreement", f"{di.get('agreement_pct', di.get('agree', 100))}%"),
            ("Avg", f"{di.get('avg')} ms"),
            ("P95", f"{di.get('p95')} ms"),
            ("บทบาท", "แนะนำเท่านั้น"),
        ],
    )
    sample = (di.get("rows_sample") or [])[:5]
    rows = [
        [
            r.get("No.", ""),
            r.get("Admin Gold Action", ""),
            r.get("AI Action", ""),
            r.get("Provider", ""),
            r.get("Confidence", ""),
            r.get("Agree (1/0)", ""),
            r.get("Latency ms", ""),
        ]
        for r in sample
    ]
    if rows:
        table_grid(
            s,
            ["No.", "Gold", "AI", "Provider", "Conf", "Agree", "ms"],
            rows,
            col_widths=[0.9, 2.4, 2.4, 1.5, 1.2, 1.2, 1.4],
            top=2.55,
            row_h=0.55,
            font=11,
        )
    warn_box(
        s,
        5.6,
        "ข้อจำกัด: ยังไม่มีชุดเคสจริง / ฉลากผู้เชี่ยวชาญ — Agreement 100% เป็นผล Mock pipeline",
        1.0,
    )

    # ---- 23 Payment ----
    s = d.add("4.10")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.10  EasySlip · Omise · Google Auth (รายละเอียด)")
    table_grid(
        s,
        ["โมดูล", "โหมด", "N", "ผลหลัก", "Avg", "P95", "หมายเหตุ"],
        [
            [
                "EasySlip",
                str(es.get("mode", ""))[:22],
                es.get("n", 100),
                f"Acc {es.get('accuracy')}%",
                es.get("avg"),
                es.get("p95"),
                f"false_autopay={es.get('false_autopay', 0)}",
            ],
            [
                "Omise",
                str(om.get("mode", ""))[:22],
                om.get("n", 100),
                f"pass {om.get('pass_rate')}%",
                om.get("avg"),
                om.get("p95"),
                f"webhook {om.get('webhook_logic_pct')}%",
            ],
            [
                "Google",
                str(ga.get("mode", ""))[:22],
                ga.get("n", 100),
                f"Acc {ga.get('accuracy')}%",
                ga.get("avg"),
                ga.get("p95"),
                f"FA={ga.get('false_accept', 0)}",
            ],
        ],
        col_widths=[1.6, 2.6, 0.9, 2.0, 1.2, 1.2, 2.9],
        top=1.2,
        row_h=0.85,
        font=11,
    )
    textbox(s, 0.45, 4.1, 12.4, 0.35, "Omise แยกตามเคส (pass_by_case)", 14, True, NAVY)
    pbc = om.get("pass_by_case") or {}
    if pbc:
        table_grid(
            s,
            list(pbc.keys()),
            [[str(v) for v in pbc.values()]],
            col_widths=[12.4 / max(len(pbc), 1)] * max(len(pbc), 1),
            top=4.5,
            row_h=0.55,
            font=13,
        )

    # ---- 24 Fallback ----
    s = d.add("4.10.1")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.10.1  สรุปทางเลือกรอง (Fallback) ระดับระบบ")
    table_grid(
        s,
        ["โมดูล", "เงื่อนไขล้มเหลว", "ทางเลือกรอง", "ผลต่อผู้ใช้/ธุรกรรม"],
        [
            ["AiGen eKYC", "timeout / exception", "Manual Review", "kyc pending"],
            ["Sumsub", "ไม่มี credentials", "Manual / AiGen", "สลับผู้ให้บริการ"],
            ["Cloud Vision", "ไม่มี key / error", "EXIF-only flag", "ไม่ hard-reject จาก Vision"],
            ["Chat LLM", "ทุก provider ล่ม", "Regex-only", "ยังกันเคสชัดได้บางส่วน"],
            ["Vision OCR แชท", "Vision error", "flag คะแนนกลาง", "ไม่ปล่อยภาพเงียบ"],
            ["Dispute AI", "LLM ล่ม", "Heuristic", "NEEDS_REVIEW"],
            ["EasySlip", "ไม่มี key / API down", "WAITING_VERIFY", "แอดมินตรวจสลิป"],
            ["Omise", "ไม่ได้ตั้งค่า", "ปิดช่อง Omise", "ใช้สลิป/PromptPay"],
            ["Redis", "เชื่อมไม่ได้", "ข้าม pub/sub", "ระบบหลักยังเดิน"],
            ["Object Storage", "ไม่พร้อม", "local uploads", "เก็บไฟล์ต่อได้"],
        ],
        col_widths=[2.2, 3.0, 3.0, 4.2],
        top=1.15,
        row_h=0.48,
        font=11,
    )

    # ---- 25 Summary all ----
    s = d.add("4.11")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.11  ตารางสรุปรวมผลการทดลองทั้งหมด")
    summary_rows = [
        ["AiGen eKYC Mock", "100%", ek.get("avg"), ek.get("p95"), "pipeline"],
        ["AiGen eKYC Live", f"{ek_real.get('accuracy_all_pairs_pct', 'N/A')}%", ek_real.get("avg_e2e_ms", ""), ek_real.get("p95_e2e_ms", ""), "N=4"],
        ["Sumsub Mock", f"{su.get('acc', 100)}%", su.get("avg"), su.get("p95"), "3-step"],
        ["Image Mock", "100%", im.get("avg"), im.get("p95"), "pipeline"],
        ["Image Live", f"{img_real.get('accuracy_pct', 'N/A')}%", img_real.get("avg_ms", ""), img_real.get("p95_ms", ""), "FP=0 Rec50"],
        ["Chat Mock", f"{ch_acc}%", ch.get("avg"), ch.get("p95"), "3 miss"],
        ["Chat Live", f"{ch_real.get('accuracy_pct', 'N/A')}%", ch_real.get("avg_ms", ""), ch_real.get("p95_ms", ""), "Δ=0"],
        ["Dispute Mock", f"{di.get('agreement_pct', di.get('agree', 100))}%", di.get("avg"), di.get("p95"), "no expert"],
        ["EasySlip", f"{es.get('accuracy', 'N/A')}%", es.get("avg"), es.get("p95"), "fixture"],
        ["Omise", f"{om.get('pass_rate', 'N/A')}%", om.get("avg"), om.get("p95"), "sandbox"],
        ["Google Auth", f"{ga.get('accuracy', 'N/A')}%", ga.get("avg"), ga.get("p95"), "certs"],
        ["Resilience", f"{rs.get('rate_pct', 100)}%", "-", "-", f"{rs.get('pass')}/{rs.get('n')}"],
        ["UAT", f"{ua.get('rate_pct', 100)}%", "-", "-", f"{ua.get('pass')}/{ua.get('n')}"],
    ]
    table_grid(
        s,
        ["โมดูล", "Acc/Agree", "Avg ms", "P95 ms", "หมายเหตุ"],
        summary_rows,
        col_widths=[3.2, 2.0, 2.0, 2.0, 3.2],
        top=1.1,
        row_h=0.4,
        font=11,
    )

    # ---- 26 Acc CI fig ----
    s = d.add("ภาพที่ 1–2")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาพที่ 1–2  ความถูกต้องพร้อม Wilson CI และ Latency")
    add_picture_safe(s, FIG / "fig01_accuracy_ci.png", 0.35, 1.15, 6.2)
    add_picture_safe(s, FIG / "fig02_latency_mean_p95.png", 6.7, 1.15, 6.2)

    # ---- 27 Resilience detail ----
    s = d.add("4.12")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.12  การทดสอบความทนทาน (Resilience) 10/10")
    metric_cards(
        s,
        [
            ("Pass", f"{rs.get('pass', 10)}/{rs.get('n', 10)}"),
            ("Rate", f"{rs.get('rate_pct', 100)}%"),
            ("MOCK ในรอบนี้", str(rs.get("mock_api_mode"))),
        ],
    )
    cases = rs.get("cases") or []
    rows = [[c.get("no"), c.get("module"), "PASS" if int(c.get("pass", 0)) == 1 else "FAIL", (c.get("actual") or "")[:55]] for c in cases]
    if rows:
        table_grid(
            s,
            ["No.", "โมดูล", "ผล", "Actual (ย่อ)"],
            rows,
            col_widths=[0.8, 3.0, 1.2, 7.4],
            top=2.5,
            row_h=0.38,
            font=10,
        )

    # ---- 28 UAT ----
    s = d.add("4.12.1")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.12.1  การทดสอบการใช้งาน (UAT) 12/12")
    metric_cards(
        s,
        [
            ("Pass", f"{ua.get('pass', 12)}/{ua.get('n', 12)}"),
            ("Rate", f"{ua.get('rate_pct', 100)}%"),
        ],
    )
    cases = ua.get("cases") or []
    rows = [[c.get("id"), "PASS" if int(c.get("pass", 0)) == 1 else "FAIL", (c.get("actual") or "")[:70]] for c in cases]
    if rows:
        table_grid(
            s,
            ["ID", "ผล", "หลักฐาน / Actual"],
            rows,
            col_widths=[1.4, 1.2, 9.8],
            top=2.5,
            row_h=0.35,
            font=10,
        )

    # ---- 29 Resilience fig ----
    s = d.add("ภาพที่ 7–9")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาพที่ 7–9  Latency eKYC/Sumsub · Resilience/UAT · Evidence modes")
    add_picture_safe(s, FIG / "fig07_ekyc_sumsub_latency.png", 0.35, 1.15, 4.2)
    add_picture_safe(s, FIG / "fig08_resilience_uat.png", 4.55, 1.15, 4.2)
    add_picture_safe(s, FIG / "fig09_evidence_modes.png", 8.75, 1.15, 4.2)

    # ---- 30 Discussion ----
    s = d.add("4.13")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.13  อภิปรายผล (Discussion)")
    bullets(
        s,
        0.55,
        1.2,
        12.2,
        5.5,
        [
            "Mock Acc สูงสะท้อนความถูกต้องของ pipeline ภายใต้สภาพแวดล้อมควบคุม",
            f"Chat Hybrid ได้ {ch_acc}% ทั้ง Mock และ Live — Error Analysis ใช้ร่วมกันได้",
            f"Image Live Acc {img_real.get('accuracy_pct', 'N/A')}% (FP=0, Recall 50%) ต่างจาก Mock 100% ชัดเจน",
            f"eKYC Live Acc {ek_real.get('accuracy_all_pairs_pct', 'N/A')}% แต่ N=4 — ใช้เป็นหลักฐานสาธิต",
            "Sumsub E2E ช้ากว่า AiGen สอดคล้องจำนวนขั้น API",
            "Resilience/UAT ผ่านทั้งหมด สนับสนุน fault-tolerant design",
            "Dispute Agreement 100% ยังจำกัดที่ Mock — ต้องมีฉลากผู้เชี่ยวชาญก่อนสรุปเชิงประชากร",
        ],
        14,
        SLATE,
    )

    # ---- 31 Related work ----
    s = d.add("4.13.1")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.13.1  เปรียบเทียบกับแนวทางที่เกี่ยวข้อง")
    reg = agr.get("chat_regex70_vs_gt") or {}
    reg_acc = f"{round(100 * float(reg.get('accuracy', 0)))}%" if reg else "N/A"
    table_grid(
        s,
        ["งาน / แนวทาง", "วิธี", "Accuracy", "หมายเหตุ"],
        [
            ["Regex-only thr≥70", "กฎอย่างเดียว", reg_acc, f"κ={reg.get('cohen_kappa', 'N/A')}"],
            ["OCR-centric (lit.)", "OCR", "~94%", "ช่วงอ้างอิงวรรณกรรม"],
            ["Face 1:1 (lit.)", "Face", "~96%", "ช่วงอ้างอิงวรรณกรรม"],
            ["FLIXO Chat Hybrid", "Regex+LLM", f"{ch_acc}%", "Mock=Live, κ≈0.94"],
            ["FLIXO eKYC Live", "OCR+Face", f"{ek_real.get('accuracy_all_pairs_pct', 'N/A')}%", "N=4"],
            ["FLIXO Image Live", "EXIF+Vision", f"{img_real.get('accuracy_pct', 'N/A')}%", "FP=0 Recall50"],
        ],
        col_widths=[3.3, 2.2, 2.0, 4.9],
        top=1.2,
        row_h=0.7,
        font=13,
    )
    add_picture_safe(s, FIG / "fig10_related_work_compare.png", 3.5, 5.5, 6.2)

    # ---- 32 Kappa McNemar ----
    s = d.add("4.13.2")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.13.2  Cohen kappa และ McNemar")
    mcn = agr.get("mcnemar_regex70_vs_hybrid") or {}
    mcn2 = agr.get("mcnemar_mock_vs_live_hybrid") or {}
    metric_cards(
        s,
        [
            ("κ Hybrid↔GT", agr.get("chat_hybrid_vs_gt_kappa", "N/A")),
            ("κ Regex70↔GT", reg.get("cohen_kappa", "N/A")),
            ("McNemar χ²", mcn.get("chi2", "N/A")),
            ("p (Regex vs Hybrid)", mcn.get("p_approx", "N/A")),
        ],
    )
    table_grid(
        s,
        ["การทดสอบ", "b", "c", "χ²", "p", "นัยสำคัญ α=0.05"],
        [
            [
                "Regex70 vs Hybrid",
                mcn.get("b", ""),
                mcn.get("c", ""),
                mcn.get("chi2", ""),
                mcn.get("p_approx", ""),
                str(mcn.get("significant_0_05", "")),
            ],
            [
                "Mock vs Live Hybrid",
                mcn2.get("b", ""),
                mcn2.get("c", ""),
                mcn2.get("chi2", ""),
                mcn2.get("p_approx", ""),
                str(mcn2.get("significant_0_05", "")),
            ],
        ],
        col_widths=[3.2, 1.2, 1.2, 1.6, 2.2, 3.0],
        top=2.55,
        row_h=0.7,
        font=12,
    )
    bullets(
        s,
        0.55,
        4.4,
        12.2,
        2.2,
        [
            "Hybrid ดีกว่า Regex≥70 อย่างมีนัยสำคัญ (p≈0.0007)",
            "Mock vs Live ไม่ต่างบนชุดข้อความเดียวกัน (p≈1)",
            f"รายละเอียด: {mcn.get('note', '')}",
        ],
        14,
        SLATE,
    )

    # ---- 33 Threats ----
    s = d.add("4.16")
    add_bg(s)
    add_bar(s)
    section_title(s, "4.16  ข้อจำกัดและภัยคุกคามต่อความตรง (Threats to Validity)")
    table_grid(
        s,
        ["ประเภท", "ประเด็น", "ผลต่อการตีความ", "แนวทางลดผลกระทบ"],
        [
            ["ภายใน", "Mock Environment", "Acc สูงอาจเกินจริง", "ย้ำขอบเขต + รัน Live"],
            ["ภายใน", "Threshold บนชุดเดียว", "เสี่ยง overfitting", "hold-out / ชุดใหม่"],
            ["ภายนอก", "eKYC Live N=4", "สรุปประชากรไม่ได้", "ขยายผู้ร่วมทดลอง"],
            ["ภายนอก", "Dispute ไม่มี expert", "Agreement ไม่แทนโลกจริง", "ฉลากผู้เชี่ยวชาญ"],
            ["ภายนอก", "ภาษาไทย C2C", "generalize จำกัด", "ขยายโดเมน/ภาษา"],
            ["โครงสร้าง", "Vendor API เปลี่ยน", "ผลซ้ำได้ยาก", "บันทึกเวอร์ชัน/วันที่"],
            ["โครงสร้าง", "Image no-EXIF miss", "Recall ต่ำ", "ปรับ heuristic"],
        ],
        col_widths=[1.8, 3.0, 3.6, 4.0],
        top=1.15,
        row_h=0.65,
        font=12,
    )

    # ---- 34 Gaps ----
    s = d.add("ช่องว่าง")
    add_bg(s)
    add_bar(s)
    section_title(s, "ช่องว่างที่เหลือ (จาก live_curve_stats.gaps)")
    gaps = curves.get("gaps") or [
        "ขยาย eKYC Live เกิน N=4",
        "Dispute กับเคสจริง + expert labels",
        "ROC/DET สำหรับ Chat และ eKYC",
        "EasySlip สลิปจริง / Google valid GIS token",
        "ปรับปรุง heuristic ภาพ no-EXIF ความละเอียดสูง",
    ]
    if isinstance(gaps, dict):
        gaps = [f"{k}: {v}" for k, v in gaps.items()]
    bullets(s, 0.6, 1.3, 12.2, 5.2, [str(g) for g in gaps], 16, SLATE)

    # ---- 35-37 Screenshots ----
    shot_groups = [
        ("ภาคผนวก ข · UI 1", [("ui09_landing.png", "Landing"), ("ui10_login.png", "Login"), ("ui01_dashboard.png", "Dashboard")]),
        ("ภาคผนวก ข · UI 2", [("ui02_ekyc.png", "eKYC"), ("ui03_sell.png", "Sell"), ("ui06_product.png", "Product")]),
        ("ภาคผนวก ข · UI 3", [("ui04_chat.png", "Chat"), ("ui05_orders.png", "Orders"), ("ui07_admin.png", "Admin")]),
    ]
    for title, items in shot_groups:
        s = d.add(title)
        add_bg(s)
        add_bar(s)
        section_title(s, title, "ภาพหน้าจอระบบจริง (ปิดบัง PII ตามแนวทางภาคผนวก)")
        for i, (fn, label) in enumerate(items):
            x = 0.4 + i * 4.3
            textbox(s, x, 1.15, 4.0, 0.35, label, 14, True, NAVY, PP_ALIGN.CENTER)
            add_picture_safe(s, SHOTS / fn, x, 1.55, 4.0)

    # ---- 38 Admin chat AI shot ----
    s = d.add("UI Admin AI")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาพหน้าจอ Admin Chat / AI Assist")
    add_picture_safe(s, SHOTS / "ui08_admin_chat_ai.png", 2.2, 1.2, 8.8)

    # ---- 39 Reproduce ----
    s = d.add("ภาคผนวก ก")
    add_bg(s)
    add_bar(s)
    section_title(s, "ภาคผนวก ก  ไฟล์ผลและวิธีทำซ้ำ")
    table_grid(
        s,
        ["ไฟล์ / คำสั่ง", "คำอธิบาย"],
        [
            ["docs/appendix_ch4/stats_summary.json", "สรุป Mock ทุกโมดูล"],
            ["docs/appendix_ch4/*_real_summary.json", "สรุป Live eKYC/Chat/Image"],
            ["docs/appendix_ch4/research_stats.json", "κ / McNemar / CI"],
            ["docs/appendix_ch4/live_curve_stats.json", "ROC/PR + gaps"],
            ["tests/test_*_100_runs.php", "รันทดลอง Mock N=100"],
            ["tests/test_ekyc_real.php", "eKYC Live"],
            ["tests/test_chat_real.php / image_fraud_real", "Chat/Image Live"],
            ["scripts/generate_chapter4_experiments_docx.py", "สร้าง DOCX"],
            ["scripts/generate_chapter4_experiments_pptx.py", "สร้าง PPTX ชุดนี้"],
        ],
        col_widths=[5.5, 6.9],
        top=1.15,
        row_h=0.5,
        font=12,
    )

    # ---- 40 Close ----
    s = d.add("สรุป")
    add_bg(s, NAVY)
    add_bar(s)
    textbox(s, 0.7, 2.0, 12, 0.5, "สรุปสำหรับนำเสนอ", 16, False, RGBColor(0xB8, 0xC9, 0xD4))
    textbox(
        s,
        0.7,
        2.6,
        12,
        2.5,
        "บทที่ 4 พิสูจน์เชิงประจักษ์ว่า pipeline AI/API ของ FLIXO\n"
        "ทำงานครบโมดูล มี Live บางส่วน สถิติข้อตกลง และ Fallback\n"
        "พร้อมย้ำขอบเขต Mock เพื่อไม่ให้ตีความผลเกินจริง",
        22,
        True,
        WHITE,
    )
    textbox(
        s,
        0.7,
        5.4,
        12,
        1.0,
        f"Chat {ch_acc}% · Image Live {img_real.get('accuracy_pct', 'N/A')}% · eKYC Live {ek_real.get('accuracy_all_pairs_pct', 'N/A')}%\n"
        f"Resilience {rs.get('pass')}/{rs.get('n')} · UAT {ua.get('pass')}/{ua.get('n')}",
        15,
        False,
        RGBColor(0xC9, 0xD7, 0xE0),
    )

    d.finish_footers()
    return prs


def main():
    data = {
        "stats": jload(APP / "stats_summary.json"),
        "ek_real": jload(APP / "ekyc_real_summary.json"),
        "ch_real": jload(APP / "chat_real_summary.json"),
        "img_real": jload(APP / "image_fraud_real_summary.json"),
        "curves": jload(APP / "live_curve_stats.json"),
        "agr": (jload(APP / "research_stats.json").get("agreement_tests") or {}),
    }
    prs = build(data)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    prs.save(str(OUT))
    print(f"Wrote {OUT} ({len(prs.slides)} slides)")


if __name__ == "__main__":
    main()
