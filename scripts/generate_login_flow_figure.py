# -*- coding: utf-8 -*-
"""Generate a print-ready 2-column login flow figure for Chapter 3."""
from __future__ import annotations

import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "thesis_figures" / "fig3_3_login_flow.png"
FONT = Path(r"C:\Windows\Fonts\leelawad.ttf")
FONT_BD = Path(r"C:\Windows\Fonts\leelawadb.ttf")
if not FONT_BD.exists():
    FONT_BD = Path(r"C:\Windows\Fonts\tahomabd.ttf")

W, H = 2000, 1320
BG = (252, 252, 253)
INK = (33, 37, 41)
MUTED = (90, 98, 110)
LINE = (110, 120, 135)

COL_PASS = ((232, 245, 233), (46, 125, 50))
COL_GOOGLE = ((227, 242, 253), (21, 101, 192))
COL_SESSION = ((255, 243, 224), (239, 108, 0))
COL_END = ((243, 229, 245), (106, 27, 154))
COL_OK = ((232, 245, 233), (46, 125, 50))
COL_DEC = ((255, 253, 231), (249, 168, 37))
COL_START = ((236, 239, 241), (69, 90, 100))


def font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    path = FONT_BD if bold and FONT_BD.exists() else FONT
    return ImageFont.truetype(str(path), size=size)


def center_text(draw: ImageDraw.ImageDraw, box, text: str, fill, fnt):
    x, y, w, h = box
    lines = text.split("\n")
    sizes = [draw.textbbox((0, 0), ln, font=fnt) for ln in lines]
    total_h = sum(s[3] - s[1] for s in sizes) + (len(lines) - 1) * 2
    cy = y + (h - total_h) / 2
    for ln, bb in zip(lines, sizes):
        tw = bb[2] - bb[0]
        th = bb[3] - bb[1]
        draw.text((x + (w - tw) / 2, cy - bb[1]), ln, fill=fill, font=fnt)
        cy += th + 2


def round_rect(draw, box, fill, outline, radius=16, width=3):
    draw.rounded_rectangle(box, radius=radius, fill=fill, outline=outline, width=width)


def diamond(draw, cx, cy, rw, rh, fill, outline, width=3):
    pts = [(cx, cy - rh), (cx + rw, cy), (cx, cy + rh), (cx - rw, cy)]
    draw.polygon(pts, fill=fill, outline=outline)
    draw.line(pts + [pts[0]], fill=outline, width=width)


def arrow(draw, x1, y1, x2, y2, color=LINE, width=3):
    draw.line((x1, y1, x2, y2), fill=color, width=width)
    ang = math.atan2(y2 - y1, x2 - x1)
    L = 14
    for da in (2.55, -2.55):
        ax = x2 - L * math.cos(ang + da)
        ay = y2 - L * math.sin(ang + da)
        draw.line((x2, y2, ax, ay), fill=color, width=width)


def main():
    img = Image.new("RGB", (W, H), BG)
    draw = ImageDraw.Draw(img)
    title_f = font(36, True)
    body_f = font(26)
    small_f = font(22)
    label_f = font(22, True)

    draw.text((70, 40), "ขั้นตอนเข้าสู่ระบบ FLIXO Escrow", fill=INK, font=title_f)
    draw.text(
        (70, 90),
        "สองช่องทาง (Password / Google) มาบรรจบที่ Session แล้วตรวจ KYC — ไม่มีสแกนใบหน้าตอน Login",
        fill=MUTED,
        font=small_f,
    )

    # Start
    sx, sy, sw, sh = 700, 145, 600, 72
    round_rect(draw, (sx, sy, sx + sw, sy + sh), COL_START[0], COL_START[1], radius=36)
    center_text(draw, (sx, sy, sw, sh), "เริ่ม · เปิดหน้า Login", INK, body_f)

    # Method diamond
    mcx, mcy = 1000, 300
    arrow(draw, 1000, sy + sh, 1000, mcy - 78)
    diamond(draw, mcx, mcy, 170, 78, COL_DEC[0], COL_DEC[1])
    center_text(draw, (mcx - 140, mcy - 30, 280, 60), "วิธีเข้าสู่ระบบ?", INK, small_f)

    # Lanes
    draw.rounded_rectangle((80, 400, 940, 860), radius=26, fill=(246, 251, 247), outline=(165, 214, 167), width=2)
    draw.rounded_rectangle((1060, 400, 1920, 860), radius=26, fill=(245, 249, 253), outline=(144, 202, 249), width=2)
    draw.text((110, 420), "ช่องทาง Password", fill=COL_PASS[1], font=label_f)
    draw.text((1090, 420), "ช่องทาง Google", fill=COL_GOOGLE[1], font=label_f)

    # Branch arrows into lanes
    arrow(draw, mcx - 170, mcy, 510, 400)
    arrow(draw, mcx + 170, mcy, 1490, 400)
    draw.text((760, 330), "Password", fill=COL_PASS[1], font=label_f)
    draw.text((1160, 330), "Google", fill=COL_GOOGLE[1], font=label_f)

    def column(x: int, color, steps: list[str]):
        y = 470
        w, h = 700, 86
        prev = None
        for text in steps:
            round_rect(draw, (x, y, x + w, y + h), color[0], color[1])
            center_text(draw, (x, y, w, h), text, INK, body_f if len(text) < 28 else small_f)
            if prev is not None:
                arrow(draw, x + w / 2, prev + h, x + w / 2, y)
            prev = y
            y += h + 42
        return x + w / 2, prev + h

    lx, ly = column(
        150,
        COL_PASS,
        ["กรอกอีเมล / รหัสผ่าน", "PHP ตรวจกับ MySQL", "ผ่าน?  ใช่ → Session | ไม่ → แจ้งรหัสผิด"],
    )
    rx, ry = column(
        1130,
        COL_GOOGLE,
        ["รับ Google ID Token", "PHP ตรวจโทเคนฝั่งเซิร์ฟเวอร์", "ถูกต้อง?  ใช่ → ผูก OAuth → Session | ไม่ → แจ้งไม่สำเร็จ"],
    )

    # Merge panel
    my = 900
    draw.rounded_rectangle((120, my, 1880, my + 340), radius=26, fill=(255, 248, 235), outline=(255, 183, 77), width=2)
    draw.text((150, my + 20), "จุดบรรจบร่วมหลังเข้าสู่ระบบสำเร็จ", fill=COL_SESSION[1], font=label_f)

    sess = (700, my + 70, 600, 78)
    round_rect(draw, (sess[0], sess[1], sess[0] + sess[2], sess[1] + sess[3]), COL_SESSION[0], COL_SESSION[1])
    center_text(draw, sess, "สร้าง PHP Session", INK, body_f)
    arrow(draw, lx, 860, sess[0] + 140, sess[1])
    arrow(draw, rx, 860, sess[0] + sess[2] - 140, sess[1])

    kcx, kcy = 1000, my + 230
    arrow(draw, 1000, sess[1] + sess[3], 1000, kcy - 78)
    diamond(draw, kcx, kcy, 170, 72, COL_DEC[0], COL_DEC[1])
    center_text(draw, (kcx - 140, kcy - 28, 280, 56), "บังคับ KYC?", INK, small_f)

    e1 = (280, my + 195, 300, 72)
    e2 = (1420, my + 195, 300, 72)
    round_rect(draw, (e1[0], e1[1], e1[0] + e1[2], e1[1] + e1[3]), COL_END[0], COL_END[1])
    round_rect(draw, (e2[0], e2[1], e2[0] + e2[2], e2[1] + e2[3]), COL_OK[0], COL_OK[1])
    center_text(draw, e1, "ไปหน้า e-KYC", INK, body_f)
    center_text(draw, e2, "หน้าหลัก", INK, body_f)
    draw.text((300, my + 165), "ยังไม่ผ่าน", fill=MUTED, font=small_f)
    draw.text((1450, my + 165), "ผ่าน / ยังไม่บังคับ", fill=MUTED, font=small_f)
    arrow(draw, kcx - 170, kcy, e1[0] + e1[2], e1[1] + e1[3] / 2)
    arrow(draw, kcx + 170, kcy, e2[0], e2[1] + e2[3] / 2)

    draw.text(
        (70, H - 42),
        "หมายเหตุ: Login พิสูจน์การถือบัญชีเท่านั้น · e-KYC ทำหลังเข้าสู่ระบบแล้ว · หน้าจอจริงดูภาพถัดไป",
        fill=MUTED,
        font=small_f,
    )

    OUT.parent.mkdir(parents=True, exist_ok=True)
    img.save(OUT, "PNG", optimize=True)
    print(f"wrote {OUT} {img.size[0]}x{img.size[1]}")


if __name__ == "__main__":
    main()
