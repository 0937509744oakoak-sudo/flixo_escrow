# -*- coding: utf-8 -*-
"""Generate academic thesis figures (Thai, print-ready PNG)."""

from __future__ import annotations

from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib import font_manager
from matplotlib.patches import FancyArrowPatch, FancyBboxPatch

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "thesis_figures"
OUT.mkdir(parents=True, exist_ok=True)

NAVY = "#1B365D"
TEAL = "#0E6B7A"
GOLD = "#8A5A12"
RED = "#8B2E2E"
GRAY = "#4A5568"
LIGHT = "#F4F7FA"
BOX = "#FFFFFF"
EDGE = "#1B365D"


def thai_font():
    candidates = [
        r"C:\Windows\Fonts\LeelawadeeUI.ttf",
        r"C:\Windows\Fonts\leelawad.ttf",
        r"C:\Windows\Fonts\THSarabunNew.ttf",
        r"C:\Windows\Fonts\THSarabun.ttf",
        r"C:\Windows\Fonts\tahoma.ttf",
        r"C:\Windows\Fonts\angsana.ttc",
    ]
    for path in candidates:
        p = Path(path)
        if p.exists():
            font_manager.fontManager.addfont(str(p))
            name = font_manager.FontProperties(fname=str(p)).get_name()
            plt.rcParams["font.family"] = name
            plt.rcParams["axes.unicode_minus"] = False
            return name
    plt.rcParams["font.family"] = "Tahoma"
    return "Tahoma"


FONT = thai_font()


def box(ax, x, y, w, h, text, *, fc=BOX, ec=EDGE, tc=NAVY, size=11, lw=1.4, weight="normal"):
    p = FancyBboxPatch(
        (x, y), w, h,
        boxstyle="round,pad=0.02,rounding_size=0.08",
        facecolor=fc, edgecolor=ec, linewidth=lw, zorder=2,
    )
    ax.add_patch(p)
    ax.text(
        x + w / 2, y + h / 2, text,
        ha="center", va="center", color=tc, fontsize=size,
        wrap=False, zorder=3, linespacing=1.2,
    )


def arrow(ax, x1, y1, x2, y2, color=NAVY):
    ax.add_patch(
        FancyArrowPatch(
            (x1, y1), (x2, y2),
            arrowstyle="-|>", mutation_scale=12,
            linewidth=1.3, color=color, zorder=1,
        )
    )


def finish(fig, name, pad=0.35):
    fig.savefig(OUT / name, dpi=220, bbox_inches="tight", facecolor="white", pad_inches=pad)
    plt.close(fig)
    print("Wrote", name)


def fig_1_1():
    fig, ax = plt.subplots(figsize=(11.2, 6.4))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 7)
    ax.axis("off")
    # Caption is added in Word; keep the diagram itself untitled.

    ax.text(2.0, 6.45, "ปัญหา", ha="center", fontsize=13, color=RED, fontweight="bold")
    ax.text(6.0, 6.45, "แนวทางของโครงงาน (ตัวแปรต้น)", ha="center", fontsize=13, color=TEAL, fontweight="bold")
    ax.text(10.1, 6.45, "ผลที่คาดว่าจะได้รับ", ha="center", fontsize=13, color=GOLD, fontweight="bold")

    box(ax, 0.35, 1.6, 3.3, 4.4,
        "ซื้อขายบนสื่อสังคมออนไลน์\nไม่มีตัวกลางกักเก็บเงิน\nไม่มียืนยันตัวตนมาตรฐาน\nถูกชักชวนโอนนอกระบบ\nข้อพิพาทไม่มีหลักฐานในระบบ",
        fc="#FDECEC", ec=RED, tc=RED, size=11)

    items = [
        (5.15, "ธุรกรรมและการเงิน\nบัญชีตัวกลาง พัก / ปล่อย / คืนเงิน"),
        (3.95, "ความปลอดภัยและยืนยันตัวตน\nบัตรประชาชน + เทียบใบหน้า"),
        (2.75, "ระบบอัจฉริยะ\nตรวจรูปสินค้าและเฝ้าแชท"),
        (1.55, "ข้อพิพาทและบริการลูกค้า\nอายัดเงิน แนบหลักฐาน ผู้ช่วยเอไอ"),
    ]
    for y, t in items:
        box(ax, 4.05, y, 3.9, 1.05, t, fc="#E7F4F6", ec=TEAL, tc=NAVY, size=10.5)

    box(ax, 8.45, 1.6, 3.3, 4.4,
        "เงินไม่โอนตรงถึงผู้ขาย\nลดบัญชีปลอม\nลดการโอนนอกระบบ\nข้อพิพาทมีหลักฐานและคำแนะนำ\nเพิ่มความน่าเชื่อถือของ C2C",
        fc="#FFF6E8", ec=GOLD, tc=GOLD, size=11)

    arrow(ax, 3.75, 3.8, 4.05, 3.8, TEAL)
    arrow(ax, 7.95, 3.8, 8.45, 3.8, GOLD)
    finish(fig, "fig1_1_conceptual_framework.png")


def fig_2_1():
    fig, ax = plt.subplots(figsize=(11.2, 4.4))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 4.2)
    ax.axis("off")
    # Caption is added in Word.

    steps = [
        (0.3, "1. ผู้ซื้อชำระเงิน\nเข้าบัญชีกลาง"),
        (2.55, "2. ระบบกักเก็บเงิน\nแจ้งผู้ขายให้จัดส่ง"),
        (4.8, "3. ผู้ขายส่งสินค้า\nแจ้งเลขพัสดุ"),
        (7.05, "4. ผู้ซื้อยืนยันรับ\nหรือเปิดข้อพิพาท"),
        (9.3, "5. ปล่อยเงินผู้ขาย\nหรือคืนเงินผู้ซื้อ"),
    ]
    for x, t in steps:
        box(ax, x, 1.55, 2.1, 1.55, t, fc=LIGHT, size=10.5)
    for i in range(len(steps) - 1):
        x = steps[i][0] + 2.1
        arrow(ax, x, 2.32, steps[i + 1][0], 2.32)
    box(ax, 7.05, 0.25, 4.35, 0.95, "หากมีข้อพิพาท: ระงับเงินไว้ก่อน จนกว่าผู้ดูแลระบบจะวินิจฉัย", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=10)
    arrow(ax, 8.1, 1.55, 8.1, 1.2, GOLD)
    finish(fig, "fig2_1_escrow_process.png")


def fig_2_2():
    fig, ax = plt.subplots(figsize=(11.2, 4.2))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 3.8)
    ax.axis("off")
    # Caption is added in Word.
    steps = [
        "1. ตรวจจับใบหน้า\nในภาพบัตรและเซลฟี",
        "2. จัดแนวใบหน้า\nตา จมูก ปาก",
        "3. แปลงเป็นเวกเตอร์\nลักษณะใบหน้า",
        "4. คำนวณความคล้าย\nเป็นคะแนน",
        "5. ตัดเกณฑ์\nค่า 80 จึงถือว่าผ่าน",
    ]
    for i, t in enumerate(steps):
        x = 0.25 + i * 2.35
        box(ax, x, 1.15, 2.15, 1.7, t, fc="#E7F4F6", ec=TEAL, size=10.5)
        if i < 4:
            arrow(ax, x + 2.15, 2.0, x + 2.35, 2.0, TEAL)
    ax.text(6, 0.45, "ไม่ใช่การค้นหาใบหน้าจากฐานข้อมูลทั้งระบบ และไม่ใช่ช่องทางเข้าสู่ระบบ", ha="center", fontsize=10.5, color=GRAY)
    finish(fig, "fig2_2_face_verification.png")


def fig_2_3():
    fig, ax = plt.subplots(figsize=(11.0, 5.6))
    ax.set_xlim(0, 11)
    ax.set_ylim(0, 6)
    ax.axis("off")
    # Caption is added in Word.

    box(ax, 3.5, 4.85, 4.0, 0.75, "ข้อความในห้องสนทนา", fc=LIGHT, size=12)
    arrow(ax, 5.5, 4.85, 5.5, 4.35)
    box(ax, 2.4, 3.35, 6.2, 1.0, "ชั้นที่ 1  กฎที่กำหนดไว้  (เร็ว ไม่มีค่าใช้จ่าย)\nจับเลขบัญชี  เบอร์โทร  ลิงก์  คำชวนโอนนอกระบบ", fc="#E7F4F6", ec=TEAL, size=11)
    arrow(ax, 3.3, 3.35, 2.2, 2.45)
    arrow(ax, 7.7, 3.35, 8.8, 2.45)
    box(ax, 0.4, 1.35, 3.6, 1.1, "ชัดเจน\nผ่าน / บล็อกด้วยกฎ", fc="#EDF2F7", size=11)
    box(ax, 6.9, 1.35, 3.7, 1.1, "กำกวม\nส่งต่อแบบจำลองภาษา", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=11)
    ax.text(5.5, 0.45, "หากแบบจำลองภาษาไม่พร้อม ให้ใช้ผลชั้นที่ 1 ต่อไปได้", ha="center", fontsize=10.5, color=GRAY)
    finish(fig, "fig2_3_chat_cascade.png")


def barrow(ax, x1, y1, x2, y2, color=NAVY):
    ax.add_patch(
        FancyArrowPatch(
            (x1, y1), (x2, y2),
            arrowstyle="<|-|>", mutation_scale=12,
            linewidth=1.3, color=color, zorder=1,
        )
    )


def fig_3_module_relations():
    fig, ax = plt.subplots(figsize=(7.8, 6.0))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 8.0)
    ax.axis("off")

    box(ax, 0.7, 7.15, 4.2, 0.7, "ผู้ซื้อ / ผู้ขาย", fc=LIGHT, size=12)
    box(ax, 7.1, 7.15, 4.2, 0.7, "ผู้ดูแลระบบ", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=12)
    arrow(ax, 2.8, 7.15, 2.8, 6.75)
    arrow(ax, 9.2, 7.15, 9.2, 6.75, GOLD)

    box(
        ax, 0.4, 5.35, 11.2, 1.35,
        "ชั้นนำเสนอ  (Frontend)\nเว็บเบราว์เซอร์   เข้าสู่ระบบ   ยืนยันตัวตน   ลงประกาศ   ชำระเงิน   ห้องสนทนา   ข้อพิพาท   แผงควบคุม",
        fc="#E7F4F6", ec=TEAL, size=11,
    )
    barrow(ax, 6.0, 5.35, 6.0, 4.95, TEAL)

    box(
        ax, 0.4, 3.5, 11.2, 1.4,
        "ชั้นประมวลผล  (Backend)\nกฎธุรกิจ   บัญชีตัวกลาง   e-KYC   คัดกรองเนื้อหา   ผู้ช่วยข้อพิพาท   ชั้นสำรอง",
        fc="#EEF2FF", ec=NAVY, size=11,
    )
    barrow(ax, 3.05, 3.5, 3.05, 3.1, GOLD)
    barrow(ax, 8.95, 3.5, 8.95, 3.1, RED)

    box(
        ax, 0.4, 0.25, 5.3, 2.8,
        "ชั้นข้อมูล  (Database)\nฐานข้อมูลเชิงสัมพันธ์\nไฟล์เอกสารเข้ารหัส\nบันทึกผลการตรวจสอบ",
        fc="#FFF6E8", ec=GOLD, size=11,
    )
    box(
        ax, 6.3, 0.25, 5.3, 2.8,
        "บริการภายนอก\nGoogle  ยืนยันบัญชี\nOCR / เทียบใบหน้า\nชำระเงิน / ตรวจสลิป\nวิเคราะห์ภาพ   แบบจำลองภาษา",
        fc="#FDECEC", ec=RED, tc=RED, size=11,
    )
    finish(fig, "fig3_module_relations.png")


def fig_3_1():
    fig, ax = plt.subplots(figsize=(11.2, 3.8))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 3.4)
    ax.axis("off")
    # Caption is added in Word.
    steps = [
        "1. ศึกษาข้อมูล\nและกำหนดขอบเขต",
        "2. ออกแบบ\nสถาปัตยกรรม",
        "3. พัฒนาระบบ\nตามสี่ขอบเขต",
        "4. ทดสอบ\nและวัดผล",
        "5. ประเมินผล\nและจัดทำรายงาน",
    ]
    for i, t in enumerate(steps):
        x = 0.3 + i * 2.35
        box(ax, x, 0.9, 2.15, 1.55, t, fc=LIGHT, size=11)
        if i < 4:
            arrow(ax, x + 2.15, 1.67, x + 2.35, 1.67)
    finish(fig, "fig3_1_research_process.png")


def fig_3_2():
    fig, ax = plt.subplots(figsize=(7.8, 5.6))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 7.4)
    ax.axis("off")
    # Caption is added in Word.

    layers = [
        (5.35, "#E7F4F6", TEAL, "ชั้นนำเสนอ\nเว็บเบราว์เซอร์  หน้าเข้าสู่ระบบ  ยืนยันตัวตน\nประกาศสินค้า  ชำระเงิน  ห้องสนทนา  ข้อพิพาท  แผงผู้ดูแล"),
        (3.35, "#EEF2FF", NAVY, "ชั้นประมวลผล\nกฎธุรกิจ  บัญชีตัวกลาง  e-KYC  คัดกรองเนื้อหา"),
        (1.35, "#FFF6E8", GOLD, "ชั้นข้อมูล\nฐานข้อมูลธุรกรรม  ไฟล์เอกสารเข้ารหัส\nบันทึกผลการตรวจสอบ"),
    ]
    for y, fc, ec, t in layers:
        box(ax, 0.35, y, 7.5, 1.7, t, fc=fc, ec=ec, size=12)
    arrow(ax, 4.1, 5.35, 4.1, 5.05)
    arrow(ax, 4.1, 3.35, 4.1, 3.05)
    box(
        ax, 8.2, 3.05, 3.5, 2.3,
        "บริการภายนอก\nชำระเงิน\nอ่านบัตร / เทียบใบหน้า\nวิเคราะห์ภาพ\nแบบจำลองภาษา",
        fc="#FDECEC", ec=RED, tc=RED, size=11,
    )
    arrow(ax, 7.85, 4.2, 8.2, 4.2, RED)
    finish(fig, "fig3_2_architecture.png")


def fig_3_3():
    """Figure 3.6: e-KYC flow, sized for A4 print readability."""
    fig, ax = plt.subplots(figsize=(6.6, 7.4))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 11.2)
    ax.axis("off")
    fs = 15

    box(ax, 2.6, 9.95, 6.8, 1.05, "เปิดหน้ายืนยันตัวตนหลังเข้าสู่ระบบ", fc=LIGHT, size=fs)
    arrow(ax, 6.0, 9.95, 6.0, 9.45)

    box(ax, 0.25, 7.85, 5.5, 1.5, "ขั้นที่ 1\nถ่ายหรืออัปโหลดบัตรประชาชน", fc="#E7F4F6", ec=TEAL, size=fs)
    box(ax, 6.25, 7.85, 5.5, 1.5, "ขั้นที่ 2\nถ่ายภาพใบหน้าจากกล้องหน้า", fc="#E7F4F6", ec=TEAL, size=fs)
    arrow(ax, 5.75, 7.85, 6.0, 7.35)
    arrow(ax, 6.25, 7.85, 6.0, 7.35)

    box(ax, 2.1, 6.2, 7.8, 1.1, "ยินยอมตาม PDPA แล้วส่งเอกสาร", fc=LIGHT, size=fs)
    arrow(ax, 6.0, 6.2, 6.0, 5.7)

    box(ax, 1.7, 4.45, 8.6, 1.2, "อ่านข้อความบนบัตรประชาชน\nหากไม่ครบ ให้ถ่ายใหม่", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=fs)
    arrow(ax, 6.0, 4.45, 6.0, 3.95)

    box(ax, 1.7, 2.85, 8.6, 1.05, "เทียบใบหน้ากับรูปบนบัตร   เกณฑ์ 80", fc=LIGHT, size=fs)
    arrow(ax, 2.6, 2.85, 2.0, 2.45)
    arrow(ax, 6.0, 2.85, 6.0, 2.45)
    arrow(ax, 9.4, 2.85, 10.0, 2.45)

    box(ax, 0.2, 0.25, 3.6, 2.1, "ผ่านเกณฑ์\nยืนยันตัวตนแล้ว", fc="#E7F4F6", ec=TEAL, size=fs)
    box(ax, 4.2, 0.25, 3.6, 2.1, "ไม่ผ่านเกณฑ์\nให้ถ่ายใหม่", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=fs)
    box(ax, 8.2, 0.25, 3.6, 2.1, "บริการไม่พร้อม\nรอผู้ดูแลระบบ", fc="#FDECEC", ec=RED, tc=RED, size=fs)
    finish(fig, "fig3_3_ekyc_flow.png", pad=0.22)


def fig_3_scopes():
    fig, ax = plt.subplots(figsize=(7.8, 5.0))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.2)
    ax.axis("off")

    box(ax, 3.35, 2.35, 5.3, 1.5, "FLIXO\nแพลตฟอร์มซื้อขายระหว่างบุคคล\nพร้อมบัญชีตัวกลาง", fc=LIGHT, size=12, weight="bold")

    items = [
        (0.3, 4.35, TEAL, "#E7F4F6", "ธุรกรรมและการเงิน\nกักเก็บ  ปล่อย  คืน  ระงับเงิน"),
        (6.7, 4.35, NAVY, "#EEF2FF", "ความปลอดภัยและยืนยันตัวตน\nบัตรประชาชน  เทียบใบหน้า  ตราสัญลักษณ์"),
        (0.3, 0.35, GOLD, "#FFF6E8", "ระบบอัจฉริยะ\nตรวจรูปสินค้า  คัดกรองห้องสนทนา"),
        (6.7, 0.35, RED, "#FDECEC", "ข้อพิพาทและบริการลูกค้า\nเปิดคำร้อง  หลักฐาน  ผู้ช่วยผู้ดูแลระบบ"),
    ]
    for x, y, ec, fc, t in items:
        box(ax, x, y, 5.0, 1.55, t, fc=fc, ec=ec, tc=NAVY if ec != RED else RED, size=11)
    arrow(ax, 5.3, 4.35, 6.0, 3.85, TEAL)
    arrow(ax, 6.7, 4.35, 6.0, 3.85, NAVY)
    arrow(ax, 5.3, 1.9, 6.0, 2.35, GOLD)
    arrow(ax, 6.7, 1.9, 6.0, 2.35, RED)
    finish(fig, "fig3_scopes.png")


def fig_3_login_flow():
    fig, ax = plt.subplots(figsize=(7.6, 5.2))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.4)
    ax.axis("off")

    box(ax, 3.5, 5.45, 5.0, 0.7, "ผู้ใช้เปิดหน้าเข้าสู่ระบบ", fc=LIGHT, size=12)
    box(ax, 0.4, 3.85, 5.1, 1.05, "ช่องทางที่ 1\nชื่อผู้ใช้หรืออีเมล + รหัสผ่าน", fc="#E7F4F6", ec=TEAL, size=11)
    box(ax, 6.5, 3.85, 5.1, 1.05, "ช่องทางที่ 2\nบัญชี Google  ตรวจโทเคนที่เครื่องแม่ข่าย", fc="#E7F4F6", ec=TEAL, size=11)
    arrow(ax, 4.4, 5.45, 2.95, 4.9)
    arrow(ax, 7.6, 5.45, 9.05, 4.9)
    arrow(ax, 2.95, 3.85, 6.0, 3.15)
    arrow(ax, 9.05, 3.85, 6.0, 3.15)
    box(ax, 3.35, 2.4, 5.3, 0.75, "เข้าสู่ระบบสำเร็จ  เข้าสู่หน้าบัญชี", fc=LIGHT, size=11)
    arrow(ax, 6.0, 2.4, 6.0, 1.9)
    box(ax, 1.5, 0.35, 9.0, 1.5, "หากต้องการสิทธิ์ที่ต้องผ่านการยืนยันตัวตน\nระบบพาไปหน้า e-KYC   ไม่มีช่องสแกนใบหน้าในหน้าเข้าสู่ระบบ", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=11)
    finish(fig, "fig3_login_flow.png")


def fig_3_image_check():
    fig, ax = plt.subplots(figsize=(7.6, 5.8))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 7.2)
    ax.axis("off")

    box(ax, 3.3, 6.25, 5.4, 0.7, "ผู้ขายอัปโหลดภาพสินค้าตอนลงประกาศ", fc=LIGHT, size=12)
    arrow(ax, 6.0, 6.25, 6.0, 5.75)
    box(ax, 2.2, 4.55, 7.6, 1.15, "ชั้นที่ 1  วิเคราะห์ข้อมูลในไฟล์ภาพ\nยี่ห้อ  รุ่น  วันเวลา  พิกัด  ลักษณะภาพหน้าจอ", fc="#E7F4F6", ec=TEAL, size=12)
    arrow(ax, 3.6, 4.55, 2.3, 3.7)
    arrow(ax, 8.4, 4.55, 9.5, 3.7)
    box(ax, 0.35, 2.55, 3.9, 1.15, "ไม่น่าสงสัย\nผ่านการลงประกาศ", fc="#EDF2F7", size=12)
    box(ax, 7.55, 2.55, 4.1, 1.15, "น่าสงสัย\nส่งชั้นที่ 2 วิเคราะห์ภาพ", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=12)
    arrow(ax, 9.6, 2.55, 9.6, 2.05)
    box(ax, 6.35, 0.35, 5.3, 1.65, "ชั้นที่ 2  บริการวิเคราะห์ภาพ\nผ่าน  /  ติดตาม  /  บล็อก\nหากบริการล่ม จะไม่บล็อกอัตโนมัติ", fc="#FDECEC", ec=RED, tc=RED, size=12)
    finish(fig, "fig3_image_check.png", pad=0.22)


def fig_3_chat_moderation():
    fig, ax = plt.subplots(figsize=(7.6, 5.0))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.0)
    ax.axis("off")

    box(ax, 2.6, 5.15, 6.8, 0.7, "ข้อความในห้องสนทนา  (ถ้ามีภาพ  อ่านข้อความในภาพก่อน)", fc=LIGHT, size=11)
    arrow(ax, 6.0, 5.15, 6.0, 4.7)
    box(ax, 1.8, 3.6, 8.4, 1.05, "ชั้นที่ 1  กฎที่กำหนดไว้  (เร็ว ไม่มีค่าใช้จ่าย)\nจับเลขบัญชี  เบอร์โทร  ลิงก์  คำชวนโอนนอกระบบ", fc="#E7F4F6", ec=TEAL, size=11)
    arrow(ax, 3.4, 3.6, 2.15, 3.15)
    arrow(ax, 8.6, 3.6, 9.85, 3.15)
    box(ax, 0.25, 2.05, 3.8, 1.05, "ผลชัดเจน\nผ่าน หรือบล็อกด้วยกฎ", fc="#EDF2F7", size=11)
    box(ax, 7.95, 2.05, 3.8, 1.05, "กำกวม / สัญญาณ Line\nส่งต่อแบบจำลองภาษา", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=11)
    arrow(ax, 9.85, 2.05, 9.85, 1.6)
    box(ax, 6.35, 0.15, 5.4, 1.4, "ชั้นที่ 2  แบบจำลองภาษา\nสูง = ไม่บันทึก     กลาง = บันทึกและเข้าคิว\nต่ำ = บันทึก     หากแบบจำลองภาษาไม่พร้อม ใช้ผลชั้นที่ 1", fc="#EEF2FF", ec=NAVY, size=11)
    finish(fig, "fig3_chat_moderation.png")


def fig_3_dispute_flow():
    fig, ax = plt.subplots(figsize=(6.4, 3.7))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 5.15)
    ax.axis("off")
    fs = 13

    steps = [
        (0.2, "1. เปิดคำร้องข้อพิพาท"),
        (4.15, "2. ระงับเงินในบัญชีตัวกลางทันที"),
        (8.1, "3. แนบหลักฐานแยกฝั่ง\nผู้ซื้อและผู้ขาย"),
    ]
    for x, t in steps:
        box(ax, x, 3.7, 3.7, 1.2, t, fc=LIGHT, size=fs)
    arrow(ax, 3.9, 4.3, 4.15, 4.3)
    arrow(ax, 7.85, 4.3, 8.1, 4.3)
    arrow(ax, 2.05, 3.7, 6.0, 3.25)
    arrow(ax, 6.0, 3.7, 6.0, 3.25)
    arrow(ax, 9.95, 3.7, 6.0, 3.25)
    box(ax, 1.2, 1.85, 9.6, 1.35, "4. ผู้ช่วยปัญญาประดิษฐ์เสนอแนวทาง\nคืนเงิน  /  ปล่อยเงิน  /  แบ่งสัดส่วน  /  ตรวจเพิ่ม", fc="#E7F4F6", ec=TEAL, size=fs)
    arrow(ax, 6.0, 1.85, 6.0, 1.4)
    box(ax, 1.2, 0.15, 9.6, 1.2, "5. ผู้ดูแลระบบเป็นผู้กดคำสั่ง   คำแนะนำของ AI ไม่ผูกพัน\nหากแบบจำลองภาษาไม่พร้อม  ใช้กฎจากสถานะพัสดุเป็นชั้นสำรอง", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=fs)
    finish(fig, "fig3_dispute_flow.png", pad=0.22)


def fig_3_escrow_flow():
    fig, ax = plt.subplots(figsize=(6.6, 4.5))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.4)
    ax.axis("off")
    fs = 13

    row1 = [
        (0.25, "1. ผู้ซื้อชำระเงิน\nบัตร / พร้อมเพย์ / สลิป"),
        (4.15, "2. กักเก็บเงินในบัญชีตัวกลาง\nผู้ขายยังถอนไม่ได้"),
        (8.05, "3. ผู้ขายส่งสินค้า\nแจ้งเลขพัสดุ"),
    ]
    for x, t in row1:
        box(ax, x, 4.55, 3.7, 1.55, t, fc=LIGHT, size=fs)
    arrow(ax, 3.95, 5.3, 4.15, 5.3)
    arrow(ax, 7.85, 5.3, 8.05, 5.3)
    arrow(ax, 6.0, 4.55, 6.0, 4.05)

    row2 = [
        (1.15, "4. ผู้ซื้อยืนยันรับสินค้า\nหรือเปิดข้อพิพาท"),
        (6.35, "5. ปล่อยเงินให้ผู้ขาย\nหรือคืนเงินให้ผู้ซื้อ"),
    ]
    for x, t in row2:
        box(ax, x, 2.4, 4.5, 1.55, t, fc="#E7F4F6", ec=TEAL, size=fs)
    arrow(ax, 5.65, 3.15, 6.35, 3.15)
    arrow(ax, 6.0, 2.4, 6.0, 1.85)

    box(
        ax, 1.3, 0.2, 9.4, 1.55,
        "หากเกิดข้อพิพาท  ระบบระงับเงินในบัญชีตัวกลางไว้ก่อน\nจนกว่าผู้ดูแลระบบจะวินิจฉัย  ห้ามปล่อยเงินโดยไม่มีเงื่อนไข",
        fc="#FFF6E8", ec=GOLD, tc=GOLD, size=fs,
    )
    finish(fig, "fig3_escrow_flow.png", pad=0.22)


def fig_3_fallback():
    fig, ax = plt.subplots(figsize=(7.6, 5.6))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.8)
    ax.axis("off")

    box(ax, 2.2, 5.75, 7.6, 0.8, "เมื่อบริการภายนอกล้มเหลว  ระบบหลักต้องเดินต่อได้", fc=LIGHT, size=12)
    rows = [
        (4.55, TEAL, "#E7F4F6", "ยืนยันตัวตน", "ส่งเอกสารเข้าคิวผู้ดูแลระบบ"),
        (3.45, NAVY, "#EEF2FF", "คัดกรองห้องสนทนา", "ใช้ผลกฎชั้นที่ 1 ต่อไป"),
        (2.35, GOLD, "#FFF6E8", "ตรวจรูปสินค้า", "ไม่บล็อกอัตโนมัติ  ให้ผู้ดูแลติดตาม"),
        (1.25, RED, "#FDECEC", "ผู้ช่วยข้อพิพาท", "ใช้กฎจากสถานะพัสดุและหลักฐาน"),
        (0.15, GRAY, "#F4F7FA", "ชำระเงินหลัก", "ใช้สลิปหรือรอผู้ดูแลระบบตรวจ"),
    ]
    for y, ec, fc, left, right in rows:
        box(ax, 0.35, y, 4.4, 0.95, left, fc=fc, ec=ec, size=11)
        arrow(ax, 4.85, y + 0.47, 5.55, y + 0.47, ec)
        box(ax, 5.55, y, 6.1, 0.95, right, fc=BOX, ec=ec, size=11)
    finish(fig, "fig3_fallback.png")


def fig_4_threshold():
    fig, ax = plt.subplots(figsize=(10.6, 5.4))
    labels = ["เจ้าของจริง ก", "เจ้าของจริง ข", "คนละคน\nบัตร ก + หน้า ข", "คนละคน\nบัตร ข + หน้า ก"]
    scores = [99.98, 98.34, 0.0, 0.0]
    colors = [TEAL, TEAL, RED, RED]
    xs = range(len(scores))
    bars = ax.bar(xs, scores, color=colors, width=0.55, zorder=2)
    ax.axhline(80, color=GOLD, linewidth=1.8, linestyle="--", zorder=3)
    ax.text(3.35, 82, "เกณฑ์ 80", color=GOLD, fontsize=11, va="bottom", ha="right")
    for x, s, c in zip(xs, scores, colors):
        ax.text(x, s + 2.5 if s > 5 else 4.5, f"{s:.2f}", ha="center", va="bottom", fontsize=11, color=c)
    ax.set_xticks(list(xs))
    ax.set_xticklabels(labels, fontsize=11)
    ax.set_ylim(0, 110)
    ax.set_ylabel("คะแนนความคล้าย", fontsize=12, color=NAVY)
    ax.spines["top"].set_visible(False)
    ax.spines["right"].set_visible(False)
    ax.tick_params(colors=NAVY)
    ax.set_axisbelow(True)
    ax.yaxis.grid(True, linestyle=":", alpha=0.5)
    finish(fig, "fig4_ekyc_threshold.png")


def main():
    fig_1_1()
    fig_2_1()
    fig_2_2()
    fig_2_3()
    fig_3_1()
    fig_3_2()
    fig_3_3()
    fig_3_scopes()
    fig_3_module_relations()
    fig_3_login_flow()
    fig_3_image_check()
    fig_3_chat_moderation()
    fig_3_dispute_flow()
    fig_3_escrow_flow()
    fig_3_fallback()
    fig_4_threshold()


if __name__ == "__main__":
    main()
