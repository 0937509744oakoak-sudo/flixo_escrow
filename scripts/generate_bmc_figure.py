# -*- coding: utf-8 -*-
"""Render FLIXO Business Model Canvas PNG for thesis appendix."""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "thesis_figures" / "fig_appendix_a_bmc.png"

FONT = Path(r"C:\Windows\Fonts\leelawad.ttf")
FONT_BD = Path(r"C:\Windows\Fonts\leelawadb.ttf")
if not FONT_BD.exists():
    FONT_BD = Path(r"C:\Windows\Fonts\tahomabd.ttf")

W, H = 2400, 1500
BG = (252, 252, 253)
INK = (33, 37, 41)
MUTED = (90, 98, 110)

BLOCKS = {
    "kp": (
        "พันธมิตรหลัก\nKey Partners",
        "• Omise (ชำระเงิน)\n• AiGen / Sumsub / ThaiD\n• Vision + LLM APIs\n• มหาวิทยาลัย / ที่ปรึกษา",
        (232, 245, 233),
        (46, 125, 50),
    ),
    "ka": (
        "กิจกรรมหลัก\nKey Activities",
        "• พัฒนาแพลตฟอร์ม\n• ดูแลบัญชีตัวกลาง\n• คัดกรองแชท/รูป\n• จัดการข้อพิพาท",
        (227, 242, 253),
        (21, 101, 192),
    ),
    "kr": (
        "ทรัพยากรหลัก\nKey Resources",
        "• ระบบ PHP / MySQL\n• บัญชีตัวกลาง\n• ทีมพัฒนา\n• API ภายนอก",
        (227, 242, 253),
        (21, 101, 192),
    ),
    "vp": (
        "คุณค่าที่เสนอ\nValue Propositions",
        "• กักเก็บเงินจนของถึงมือ\n• Login ≠ e-KYC\n• คัดกรองโอนนอกระบบ\n• ข้อพิพาทมีแอดมินตัดสิน",
        (255, 243, 224),
        (239, 108, 0),
    ),
    "cr": (
        "ความสัมพันธ์ลูกค้า\nCustomer Relationships",
        "• บัญชีผู้ใช้\n• แชทในระบบ\n• แจ้งเตือนออเดอร์\n• ศูนย์ช่วยเหลือ",
        (243, 229, 245),
        (106, 27, 154),
    ),
    "ch": (
        "ช่องทาง\nChannels",
        "• เว็บแอปพลิเคชัน\n• โซเชียลมีเดีย\n• ชุมชน C2C\n• โครงการวิจัย",
        (243, 229, 245),
        (106, 27, 154),
    ),
    "cs": (
        "กลุ่มลูกค้า\nCustomer Segments",
        "• ผู้ซื้อ C2C\n• ผู้ขายมือสอง/ดิจิทัล\n• ผู้ใช้โซเชียลที่กลัวโกง\n• ผู้ดูแลแพลตฟอร์ม",
        (255, 235, 238),
        (198, 40, 40),
    ),
    "cost": (
        "โครงสร้างต้นทุน\nCost Structure",
        "• โฮสติ้งและคลาวด์   • ค่า API ภายนอก   • ค่าธรรมเนียมชำระเงิน   • พัฒนาและดูแลระบบ   • งานแอดมิน",
        (236, 239, 241),
        (69, 90, 100),
    ),
    "rev": (
        "กระแสรายได้\nRevenue Streams",
        "• ค่าธรรมเนียมต่อธุรกรรม   • แพ็กเกจผู้ขายพรีเมียม   • สมัครสมาชิกรายเดือน (ร่างแผน)",
        (232, 245, 233),
        (46, 125, 50),
    ),
}


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    path = FONT_BD if bold and FONT_BD.exists() else FONT
    return ImageFont.truetype(str(path), size=size)


def wrap(draw: ImageDraw.ImageDraw, text: str, fnt, max_w: int) -> list[str]:
    lines: list[str] = []
    for raw in text.split("\n"):
        if not raw:
            lines.append("")
            continue
        words = raw.split(" ")
        # Thai often has no spaces; fall back to char chunks if single long token
        if len(words) == 1 and draw.textlength(raw, font=fnt) > max_w:
            buf = ""
            for ch in raw:
                trial = buf + ch
                if draw.textlength(trial, font=fnt) <= max_w:
                    buf = trial
                else:
                    if buf:
                        lines.append(buf)
                    buf = ch
            if buf:
                lines.append(buf)
            continue
        buf = words[0]
        for w in words[1:]:
            trial = f"{buf} {w}"
            if draw.textlength(trial, font=fnt) <= max_w:
                buf = trial
            else:
                lines.append(buf)
                buf = w
        lines.append(buf)
    return lines


def draw_box(draw, xy, title, body, fill, outline, title_f, body_f):
    x1, y1, x2, y2 = xy
    draw.rounded_rectangle((x1, y1, x2, y2), radius=14, fill=fill, outline=outline, width=3)
    pad = 16
    max_w = x2 - x1 - pad * 2
    ty = y1 + pad
    for line in wrap(draw, title, title_f, max_w):
        draw.text((x1 + pad, ty), line, fill=outline, font=title_f)
        ty += title_f.size + 4
    ty += 6
    draw.line((x1 + pad, ty, x2 - pad, ty), fill=outline, width=2)
    ty += 10
    for line in wrap(draw, body, body_f, max_w):
        draw.text((x1 + pad, ty), line, fill=INK, font=body_f)
        ty += body_f.size + 5


def main():
    img = Image.new("RGB", (W, H), BG)
    draw = ImageDraw.Draw(img)
    title_f = font(34, True)
    box_title = font(20, True)
    box_body = font(18)
    small = font(16)

    draw.text((40, 24), "FLIXO — Business Model Canvas", fill=INK, font=title_f)
    draw.text(
        (40, 72),
        "ภาคผนวก ก · แบบจำลองธุรกิจแพลตฟอร์มบัญชีตัวกลางและคัดกรองความเสี่ยงด้วย AI",
        fill=MUTED,
        font=small,
    )

    m = 40
    top = 110
    gap = 14
    bottom_h = 210
    mid_h = H - top - bottom_h - m - gap
    usable_w = W - 2 * m
    # 5 columns classic proportions: KP, KA/KR, VP, CR/CH, CS
    c = (usable_w - 4 * gap) / 5
    x0 = m
    cols = [x0 + i * (c + gap) for i in range(5)]

    # column 0: KP full height
    draw_box(draw, (cols[0], top, cols[0] + c, top + mid_h), *BLOCKS["kp"][:2], BLOCKS["kp"][2], BLOCKS["kp"][3], box_title, box_body)
    # col1 split KA / KR
    half = (mid_h - gap) / 2
    draw_box(draw, (cols[1], top, cols[1] + c, top + half), *BLOCKS["ka"][:2], BLOCKS["ka"][2], BLOCKS["ka"][3], box_title, box_body)
    draw_box(draw, (cols[1], top + half + gap, cols[1] + c, top + mid_h), *BLOCKS["kr"][:2], BLOCKS["kr"][2], BLOCKS["kr"][3], box_title, box_body)
    # col2 VP full
    draw_box(draw, (cols[2], top, cols[2] + c, top + mid_h), *BLOCKS["vp"][:2], BLOCKS["vp"][2], BLOCKS["vp"][3], box_title, box_body)
    # col3 CR / CH
    draw_box(draw, (cols[3], top, cols[3] + c, top + half), *BLOCKS["cr"][:2], BLOCKS["cr"][2], BLOCKS["cr"][3], box_title, box_body)
    draw_box(draw, (cols[3], top + half + gap, cols[3] + c, top + mid_h), *BLOCKS["ch"][:2], BLOCKS["ch"][2], BLOCKS["ch"][3], box_title, box_body)
    # col4 CS full
    draw_box(draw, (cols[4], top, cols[4] + c, top + mid_h), *BLOCKS["cs"][:2], BLOCKS["cs"][2], BLOCKS["cs"][3], box_title, box_body)

    # bottom cost / revenue
    by = top + mid_h + gap
    left_w = cols[2] + c - m
    draw_box(draw, (m, by, m + left_w, by + bottom_h), *BLOCKS["cost"][:2], BLOCKS["cost"][2], BLOCKS["cost"][3], box_title, box_body)
    draw_box(draw, (m + left_w + gap, by, W - m, by + bottom_h), *BLOCKS["rev"][:2], BLOCKS["rev"][2], BLOCKS["rev"][3], box_title, box_body)

    draw.text(
        (40, H - 36),
        "หมายเหตุ: กระแสรายได้เป็นร่างแผนธุรกิจ ไม่ใช่ผลทดลองในบทที่ 4",
        fill=MUTED,
        font=small,
    )

    OUT.parent.mkdir(parents=True, exist_ok=True)
    img.save(OUT, "PNG", optimize=True)
    print(f"wrote {OUT} {img.size[0]}x{img.size[1]}")


if __name__ == "__main__":
    main()
