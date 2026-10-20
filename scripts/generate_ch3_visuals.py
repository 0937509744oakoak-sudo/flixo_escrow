# -*- coding: utf-8 -*-
"""Chapter 3 visuals: short-label system diagrams + cropped FLIXO screenshots."""

from __future__ import annotations

from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib import font_manager
from matplotlib.patches import (
    Arc,
    Circle,
    FancyArrowPatch,
    FancyBboxPatch,
    Polygon,
    Rectangle,
)
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "thesis_figures"
SHOT = ROOT / "docs" / "appendix_ch4" / "screenshots"
OUT.mkdir(parents=True, exist_ok=True)

NAVY = "#1B365D"
TEAL = "#0E6B7A"
GOLD = "#8A5A12"
RED = "#8B2E2E"
GRAY = "#5A6570"
LIGHT = "#F4F7FA"
BOX = "#FFFFFF"
EDGE = "#1B365D"
SOFT = "#EEF3F8"
TEAL_BG = "#E7F4F6"
GOLD_BG = "#FFF6E8"
RED_BG = "#F7EDED"
ICON_LW = 1.45


def thai_font():
    for path in (
        r"C:\Windows\Fonts\LeelawadeeUI.ttf",
        r"C:\Windows\Fonts\leelawad.ttf",
        r"C:\Windows\Fonts\tahoma.ttf",
    ):
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


def box(ax, x, y, w, h, text, *, fc=BOX, ec=EDGE, tc=NAVY, size=12, lw=1.3, weight="bold"):
    ax.add_patch(
        FancyBboxPatch(
            (x, y), w, h,
            boxstyle="round,pad=0.018,rounding_size=0.12",
            facecolor=fc, edgecolor=ec, linewidth=lw, zorder=2,
        )
    )
    ax.text(
        x + w / 2, y + h / 2, text,
        ha="center", va="center", color=tc, fontsize=size,
        fontweight=weight, zorder=3, linespacing=1.15,
    )


def chevron(ax, x1, x2, y, color=NAVY):
    ax.add_patch(
        FancyArrowPatch(
            (x1, y), (x2, y),
            arrowstyle="-|>", mutation_scale=11,
            linewidth=1.35, color=color, zorder=1,
        )
    )


def canvas(xmax, ymax, width=10.8):
    fig, ax = plt.subplots(figsize=(width, width * ymax / xmax))
    ax.set_xlim(0, xmax)
    ax.set_ylim(0, ymax)
    ax.set_aspect("equal")
    ax.axis("off")
    return fig, ax


def arrow(ax, x1, y1, x2, y2, r1, r2, color=NAVY, lw=1.05):
    dx, dy = x2 - x1, y2 - y1
    length = (dx * dx + dy * dy) ** 0.5
    ux, uy = dx / length, dy / length
    ax.annotate(
        "",
        xy=(x2 - ux * r2, y2 - uy * r2),
        xytext=(x1 + ux * r1, y1 + uy * r1),
        arrowprops=dict(
            arrowstyle="-|>",
            color=color,
            lw=lw,
            mutation_scale=9,
            shrinkA=0,
            shrinkB=0,
        ),
        zorder=1,
    )


def node(ax, x, y, r, icon, label, *, fc=LIGHT, ec=NAVY, tc=NAVY, size=11.5):
    ax.add_patch(Circle((x, y), r, facecolor=fc, edgecolor=ec, linewidth=1.2, zorder=3))
    icon(ax, x, y, r, ec)
    ax.text(
        x, y - r - 0.32, label,
        ha="center", va="top", color=tc, fontsize=size,
        fontweight="bold", linespacing=1.15, zorder=4,
    )


def ic_user(ax, x, y, r, c):
    ax.add_patch(Circle((x, y + 0.16 * r), 0.22 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.add_patch(Arc((x, y - 0.42 * r), 0.92 * r, 0.78 * r, theta1=12, theta2=168, ec=c, lw=ICON_LW, zorder=5))


def ic_id(ax, x, y, r, c):
    w, h = 0.86 * r, 0.58 * r
    ax.add_patch(FancyBboxPatch(
        (x - w / 2, y - h / 2), w, h,
        boxstyle="round,pad=0.01,rounding_size=0.05",
        fill=False, ec=c, lw=ICON_LW, zorder=5,
    ))
    ax.add_patch(Rectangle((x - w / 2 + 0.08 * r, y - 0.14 * r), 0.26 * r, 0.28 * r, fill=False, ec=c, lw=1.15, zorder=5))
    for dy in (0.12, 0.0, -0.12):
        ax.plot([x + 0.02 * r, x + w / 2 - 0.10 * r], [y + dy * r, y + dy * r], color=c, lw=1.15, zorder=5, solid_capstyle="round")


def ic_ocr(ax, x, y, r, c):
    ax.add_patch(Rectangle((x - 0.28 * r, y - 0.34 * r), 0.56 * r, 0.72 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    for i in range(3):
        yy = y + 0.18 * r - i * 0.18 * r
        ax.plot([x - 0.16 * r, x + 0.16 * r], [yy, yy], color=c, lw=1.15, zorder=5, solid_capstyle="round")
    ax.plot([x - 0.42 * r, x + 0.42 * r], [y - 0.02 * r, y - 0.02 * r], color=c, lw=1.7, zorder=6, solid_capstyle="round")


def ic_cam(ax, x, y, r, c):
    ax.add_patch(FancyBboxPatch(
        (x - 0.38 * r, y - 0.22 * r), 0.76 * r, 0.48 * r,
        boxstyle="round,pad=0.01,rounding_size=0.06",
        fill=False, ec=c, lw=ICON_LW, zorder=5,
    ))
    ax.add_patch(Circle((x, y + 0.02 * r), 0.16 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.add_patch(FancyBboxPatch(
        (x - 0.10 * r, y + 0.26 * r), 0.20 * r, 0.10 * r,
        boxstyle="round,pad=0.004,rounding_size=0.03",
        fill=False, ec=c, lw=1.1, zorder=5,
    ))


def ic_match(ax, x, y, r, c):
    ax.add_patch(Circle((x - 0.16 * r, y + 0.04 * r), 0.26 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.add_patch(Circle((x + 0.16 * r, y + 0.04 * r), 0.26 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.plot([x - 0.10 * r, x + 0.10 * r], [y - 0.36 * r, y - 0.36 * r], color=c, lw=1.3, zorder=5, solid_capstyle="round")
    ax.plot([x - 0.10 * r, x + 0.10 * r], [y - 0.46 * r, y - 0.46 * r], color=c, lw=1.3, zorder=5, solid_capstyle="round")


def ic_check(ax, x, y, r, c):
    ax.plot(
        [x - 0.22 * r, x - 0.04 * r, x + 0.28 * r],
        [y - 0.02 * r, y - 0.22 * r, y + 0.24 * r],
        color=c, lw=2.05, zorder=5, solid_capstyle="round", solid_joinstyle="miter",
    )


def ic_image(ax, x, y, r, c):
    ax.add_patch(FancyBboxPatch(
        (x - 0.38 * r, y - 0.28 * r), 0.76 * r, 0.56 * r,
        boxstyle="round,pad=0.01,rounding_size=0.05",
        fill=False, ec=c, lw=ICON_LW, zorder=5,
    ))
    ax.add_patch(Circle((x - 0.16 * r, y + 0.08 * r), 0.08 * r, fill=False, ec=c, lw=1.15, zorder=5))
    ax.plot(
        [x - 0.32 * r, x - 0.06 * r, x + 0.10 * r, x + 0.32 * r],
        [y - 0.22 * r, y + 0.00 * r, y - 0.12 * r, y - 0.22 * r],
        color=c, lw=1.2, zorder=5, solid_capstyle="round",
    )


def ic_layers(ax, x, y, r, c):
    for i, (ox, oy) in enumerate(((-0.08, 0.10), (0.0, 0.0), (0.08, -0.10))):
        ax.add_patch(FancyBboxPatch(
            (x - 0.28 * r + ox * r, y - 0.16 * r + oy * r), 0.56 * r, 0.34 * r,
            boxstyle="round,pad=0.008,rounding_size=0.04",
            fill=False, ec=c, lw=1.15, zorder=5 + i,
        ))


def ic_warn(ax, x, y, r, c):
    tri = [(x, y + 0.36 * r), (x - 0.34 * r, y - 0.26 * r), (x + 0.34 * r, y - 0.26 * r)]
    ax.add_patch(Polygon(tri, fill=False, ec=c, lw=ICON_LW, closed=True, zorder=5))
    ax.plot([x, x], [y + 0.12 * r, y - 0.04 * r], color=c, lw=1.55, zorder=5, solid_capstyle="round")
    ax.add_patch(Circle((x, y - 0.15 * r), 0.035 * r, fc=c, ec=c, zorder=5))


def ic_search(ax, x, y, r, c):
    ax.add_patch(Circle((x - 0.08 * r, y + 0.08 * r), 0.26 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.plot([x + 0.12 * r, x + 0.32 * r], [y - 0.10 * r, y - 0.30 * r], color=c, lw=ICON_LW, zorder=5, solid_capstyle="round")


def ic_block(ax, x, y, r, c):
    ax.add_patch(Circle((x, y), 0.32 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.plot([x - 0.22 * r, x + 0.22 * r], [y + 0.22 * r, y - 0.22 * r], color=c, lw=ICON_LW, zorder=5)


def ic_flag(ax, x, y, r, c):
    ax.plot([x - 0.20 * r, x - 0.20 * r], [y - 0.32 * r, y + 0.32 * r], color=c, lw=ICON_LW, zorder=5, solid_capstyle="round")
    ax.add_patch(Polygon(
        [(x - 0.20 * r, y + 0.32 * r), (x + 0.28 * r, y + 0.16 * r), (x - 0.20 * r, y + 0.02 * r)],
        fill=False, ec=c, lw=ICON_LW, closed=True, zorder=5,
    ))


def ic_msg(ax, x, y, r, c):
    ax.add_patch(FancyBboxPatch(
        (x - 0.38 * r, y - 0.10 * r), 0.76 * r, 0.42 * r,
        boxstyle="round,pad=0.01,rounding_size=0.08",
        fill=False, ec=c, lw=ICON_LW, zorder=5,
    ))
    ax.plot([x - 0.16 * r, x - 0.28 * r, x - 0.06 * r], [y - 0.10 * r, y - 0.32 * r, y - 0.10 * r], color=c, lw=ICON_LW, zorder=5)


def ic_rules(ax, x, y, r, c):
    for i in range(3):
        yy = y + 0.22 * r - i * 0.22 * r
        ax.add_patch(Circle((x - 0.28 * r, yy), 0.055 * r, fill=False, ec=c, lw=1.15, zorder=5))
        ax.plot([x - 0.12 * r, x + 0.32 * r], [yy, yy], color=c, lw=1.2, zorder=5, solid_capstyle="round")


def ic_ai(ax, x, y, r, c):
    pts = [(x, y + 0.26 * r), (x - 0.28 * r, y - 0.18 * r), (x + 0.28 * r, y - 0.18 * r)]
    for i in range(3):
        ax.plot([pts[i][0], pts[(i + 1) % 3][0]], [pts[i][1], pts[(i + 1) % 3][1]], color=c, lw=1.15, zorder=5)
        ax.add_patch(Circle(pts[i], 0.07 * r, fc="white", ec=c, lw=1.2, zorder=6))


def ic_ok(ax, x, y, r, c):
    ax.add_patch(Circle((x, y), 0.32 * r, fill=False, ec=c, lw=ICON_LW, zorder=5))
    ax.annotate(
        "",
        xy=(x + 0.14 * r, y),
        xytext=(x - 0.14 * r, y),
        arrowprops=dict(arrowstyle="-|>", color=c, lw=1.2, mutation_scale=8, shrinkA=0, shrinkB=0),
        zorder=5,
    )


def finish(fig, name, pad=0.22):
    fig.savefig(OUT / name, dpi=220, bbox_inches="tight", facecolor="white", pad_inches=pad)
    plt.close(fig)
    print("Wrote", name)


def pipeline(name, labels, figsize=(10.6, 1.7), fc=LIGHT, ec=EDGE):
    n = len(labels)
    fig, ax = plt.subplots(figsize=figsize)
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 2.0)
    ax.axis("off")
    gap = 0.32
    x0, x1 = 0.15, 11.85
    chip_w = ((x1 - x0) - gap * (n - 1)) / n
    y, h = 0.4, 1.2
    x = x0
    for i, lab in enumerate(labels):
        box(ax, x, y, chip_w, h, lab, fc=fc, ec=ec, size=12)
        if i < n - 1:
            chevron(ax, x + chip_w + 0.04, x + chip_w + gap - 0.04, y + h / 2)
        x += chip_w + gap
    finish(fig, name, pad=0.18)


def crop_shot(src: Path, dst: Path, box_frac, max_w=1500):
    im = Image.open(src).convert("RGB")
    w, h = im.size
    l, t, r, b = box_frac
    cropped = im.crop((int(l * w), int(t * h), int(r * w), int(b * h)))
    if cropped.width > max_w:
        nh = int(cropped.height * max_w / cropped.width)
        cropped = cropped.resize((max_w, nh), Image.Resampling.LANCZOS)
    cropped.save(dst, "PNG", optimize=True)
    print("Cropped", dst.name, cropped.size)


def stack_pipeline_and_shot(pipe_png: Path, shot_png: Path, out_name: str, shot_scale=1.0):
    top = Image.open(pipe_png).convert("RGB")
    bot = Image.open(shot_png).convert("RGB")
    width = max(top.width, int(bot.width * shot_scale))
    if top.width != width:
        top = top.resize((width, int(top.height * width / top.width)), Image.Resampling.LANCZOS)
    if bot.width != width:
        bot = bot.resize((width, int(bot.height * width / bot.width)), Image.Resampling.LANCZOS)
    gap = 18
    canvas = Image.new("RGB", (width, top.height + gap + bot.height), "white")
    canvas.paste(top, (0, 0))
    canvas.paste(bot, (0, top.height + gap))
    dest = OUT / out_name
    canvas.save(dest, "PNG", optimize=True)
    print("Stacked", dest.name, canvas.size)


def fig_scopes():
    fig, ax = plt.subplots(figsize=(8.4, 4.6))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.6)
    ax.axis("off")
    box(ax, 3.7, 2.55, 4.6, 1.5, "FLIXO\nบัญชีตัวกลาง", fc=SOFT, size=14)
    tiles = [
        (0.35, 4.55, TEAL, "#E7F4F6", "ธุรกรรม"),
        (6.95, 4.55, NAVY, "#EEF2FF", "ยืนยันตัวตน"),
        (0.35, 0.35, GOLD, "#FFF6E8", "ระบบอัจฉริยะ"),
        (6.95, 0.35, RED, "#FDECEC", "ข้อพิพาท"),
    ]
    for x, y, ec, fc, t in tiles:
        box(ax, x, y, 4.7, 1.7, t, fc=fc, ec=ec, tc=NAVY if ec != RED else RED, size=14)
    finish(fig, "fig3_scopes.png")


def fig_architecture():
    fig, ax = plt.subplots(figsize=(8.6, 5.4))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 8.2)
    ax.axis("off")
    box(ax, 0.5, 7.15, 5.3, 0.75, "ผู้ซื้อ / ผู้ขาย", fc=LIGHT, size=13)
    box(ax, 6.2, 7.15, 5.3, 0.75, "ผู้ดูแลระบบ", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=13)
    ax.add_patch(FancyArrowPatch((3.15, 7.15), (3.15, 6.55), arrowstyle="-|>", mutation_scale=11, color=NAVY))
    ax.add_patch(FancyArrowPatch((8.85, 7.15), (8.85, 6.55), arrowstyle="-|>", mutation_scale=11, color=GOLD))
    box(ax, 0.5, 5.35, 11.0, 1.15, "ชั้นนำเสนอ", fc="#E7F4F6", ec=TEAL, size=15)
    ax.add_patch(FancyArrowPatch((6.0, 5.35), (6.0, 4.85), arrowstyle="-|>", mutation_scale=11, color=TEAL))
    box(ax, 0.5, 3.55, 11.0, 1.2, "ชั้นประมวลผล", fc="#EEF2FF", size=15)
    ax.add_patch(FancyArrowPatch((3.2, 3.55), (3.2, 3.05), arrowstyle="-|>", mutation_scale=11, color=GOLD))
    ax.add_patch(FancyArrowPatch((8.8, 3.55), (8.8, 3.05), arrowstyle="-|>", mutation_scale=11, color=RED))
    box(ax, 0.5, 0.55, 5.3, 2.35, "ชั้นข้อมูล", fc="#FFF6E8", ec=GOLD, size=15)
    box(ax, 6.2, 0.55, 5.3, 2.35, "บริการภายนอก", fc="#FDECEC", ec=RED, tc=RED, size=15)
    finish(fig, "fig3_module_relations.png")


def fig_three_tier():
    fig, ax = plt.subplots(figsize=(8.4, 3.6))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 5.2)
    ax.axis("off")
    layers = [
        (3.55, TEAL, "#E7F4F6", "ชั้นนำเสนอ"),
        (1.9, NAVY, "#EEF2FF", "ชั้นประมวลผล"),
        (0.25, GOLD, "#FFF6E8", "ชั้นข้อมูล"),
    ]
    for y, ec, fc, t in layers:
        box(ax, 1.3, y, 9.4, 1.35, t, fc=fc, ec=ec, size=16)
        if y != 0.25:
            ax.add_patch(FancyArrowPatch((6.0, y), (6.0, y - 0.22), arrowstyle="-|>", mutation_scale=11, color=GRAY))
    finish(fig, "fig3_2_architecture.png")


def fig_login_split():
    fig, ax = plt.subplots(figsize=(8.8, 3.15))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 4.3)
    ax.axis("off")
    box(ax, 0.35, 2.85, 2.4, 1.05, "ผู้ใช้", fc=LIGHT, size=13)
    chevron(ax, 2.85, 3.3, 3.37)
    box(ax, 3.4, 2.85, 3.6, 1.05, "รหัสผ่าน / Google", fc="#E7F4F6", ec=TEAL, size=13)
    chevron(ax, 7.1, 7.55, 3.37)
    box(ax, 7.65, 2.85, 4.0, 1.05, "เข้าสู่ระบบ", fc=SOFT, size=13)
    ax.text(6.0, 2.2, "ไม่ใช่การยืนยันตัวตน", ha="center", color=GOLD, fontsize=11, fontweight="bold")
    box(ax, 2.0, 0.35, 8.0, 1.5, "e-KYC ทำหลังจากเข้าสู่ระบบ", fc="#FFF6E8", ec=GOLD, tc=GOLD, size=13)
    finish(fig, "fig3_login_flow.png")


def fig_ekyc():
    xmax, ymax = 13.4, 3.85
    fig, ax = canvas(xmax, ymax, width=11.4)
    r = 0.56
    y = 2.15
    items = [
        (ic_user, "ผู้ใช้", LIGHT, NAVY),
        (ic_id, "บัตรประชาชน", GOLD_BG, GOLD),
        (ic_ocr, "OCR", TEAL_BG, TEAL),
        (ic_cam, "ใบหน้า", LIGHT, NAVY),
        (ic_match, "เทียบใบหน้า", TEAL_BG, TEAL),
        (ic_check, "ยืนยัน", TEAL_BG, TEAL),
    ]
    xs = [1.15 + i * (11.1 / 5) for i in range(6)]
    for i, ((icon, lab, fc, ec), x) in enumerate(zip(items, xs)):
        node(ax, x, y, r, icon, lab, fc=fc, ec=ec, tc=ec, size=12)
        ax.text(x, y + r + 0.16, str(i + 1), ha="center", va="bottom", color=GRAY, fontsize=8.5, zorder=4)
        if i < 5:
            arrow(ax, x, y, xs[i + 1], y, r, r)
    finish(fig, "fig3_3_ekyc_flow.png", pad=0.14)


def fig_image():
    xmax, ymax = 13.2, 10.8
    fig, ax = canvas(xmax, ymax, width=9.0)
    r = 0.54
    up, l1 = (6.4, 9.45), (6.4, 7.05)
    ok1, l2 = (2.45, 4.45), (9.7, 4.45)
    ok2, watch, block = (7.15, 1.55), (9.7, 1.55), (12.25, 1.55)
    node(ax, *up, r, ic_image, "อัปโหลดภาพ", fc=LIGHT, ec=NAVY, size=12)
    node(ax, *l1, r, ic_layers, "ชั้นที่ 1", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=12)
    node(ax, *ok1, r, ic_check, "ผ่าน", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=12)
    node(ax, *l2, r, ic_search, "ชั้นที่ 2", fc=GOLD_BG, ec=GOLD, tc=GOLD, size=12)
    node(ax, *ok2, r, ic_check, "ผ่าน", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=12)
    node(ax, *watch, r, ic_flag, "ติดตาม", fc=GOLD_BG, ec=GOLD, tc=GOLD, size=12)
    node(ax, *block, r, ic_block, "บล็อก", fc=RED_BG, ec=RED, tc=RED, size=12)
    arrow(ax, *up, *l1, r, r)
    arrow(ax, *l1, *ok1, r, r)
    arrow(ax, *l1, *l2, r, r, color=GOLD)
    arrow(ax, *l2, *ok2, r, r)
    arrow(ax, *l2, *watch, r, r, color=GOLD)
    arrow(ax, *l2, *block, r, r, color=RED)
    ax.text(3.85, 5.95, "ปกติ", ha="center", va="center", color=TEAL, fontsize=10, fontweight="bold")
    ax.text(8.55, 5.95, "น่าสงสัย", ha="center", va="center", color=GOLD, fontsize=10, fontweight="bold")
    finish(fig, "fig3_image_check.png", pad=0.16)


def fig_chat():
    xmax, ymax = 13.4, 12.2
    fig, ax = canvas(xmax, ymax, width=9.0)
    r = 0.54
    msg, rules = (6.5, 10.85), (6.5, 8.35)
    clear, ai = (2.45, 5.65), (10.05, 5.65)
    hi, mid, lo = (7.45, 2.15), (10.05, 2.15), (12.55, 2.15)
    node(ax, *msg, r, ic_msg, "ข้อความ", fc=LIGHT, ec=NAVY, size=12)
    node(ax, *rules, r, ic_rules, "ตรวจสอบด้วยกฎ", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=12)
    node(ax, *clear, r, ic_ok, "ชัดเจน\nอนุญาต", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=11.5)
    node(ax, *ai, r, ic_ai, "วิเคราะห์ด้วย AI", fc=GOLD_BG, ec=GOLD, tc=GOLD, size=11.5)
    node(ax, *hi, r, ic_block, "สูง\nไม่บันทึก +แจ้ง", fc=RED_BG, ec=RED, tc=RED, size=10.5)
    node(ax, *mid, r, ic_flag, "กลาง\nบันทึก + Flag", fc=GOLD_BG, ec=GOLD, tc=GOLD, size=11)
    node(ax, *lo, r, ic_check, "ต่ำ\nปกติ", fc=TEAL_BG, ec=TEAL, tc=TEAL, size=11)
    arrow(ax, *msg, *rules, r, r)
    arrow(ax, *rules, *clear, r, r)
    arrow(ax, *rules, *ai, r, r, color=GOLD)
    arrow(ax, *ai, *hi, r, r, color=RED)
    arrow(ax, *ai, *mid, r, r, color=GOLD)
    arrow(ax, *ai, *lo, r, r)
    ax.text(8.90, 7.25, "กำกวม", ha="center", color=GOLD, fontsize=10, fontweight="bold")
    ax.add_patch(FancyBboxPatch(
        (0.28, 1.55), 4.15, 1.45,
        boxstyle="round,pad=0.02,rounding_size=0.10",
        facecolor="#F7F8FA", edgecolor=GRAY, linewidth=1.0, linestyle=(0, (3, 2)), zorder=2,
    ))
    ax.text(2.35, 2.48, "AI ไม่พร้อม", ha="center", color=GRAY, fontsize=10, fontweight="bold")
    ax.text(2.35, 1.95, "ใช้กฎชั้นที่ 1", ha="center", color=NAVY, fontsize=11, fontweight="bold")
    finish(fig, "fig3_chat_moderation.png", pad=0.16)


def fig_dispute():
    pipeline(
        "fig3_dispute_flow.png",
        ["เปิดข้อพิพาท", "ระงับเงิน", "หลักฐาน", "AI ช่วยวิเคราะห์", "ผู้ดูแลตัดสิน"],
        figsize=(10.8, 1.75),
        fc="#FFF6E8",
        ec=GOLD,
    )


def fig_escrow():
    pipeline(
        "fig3_escrow_flow.png",
        ["ผู้ซื้อ", "ชำระเงิน", "พักเงิน", "จัดส่ง", "ยืนยันรับ", "ปล่อยเงิน"],
        figsize=(10.8, 1.75),
    )


def fig_fallback():
    fig, ax = plt.subplots(figsize=(8.6, 4.6))
    ax.set_xlim(0, 12)
    ax.set_ylim(0, 6.4)
    ax.axis("off")
    box(ax, 0.4, 5.45, 11.2, 0.75, "เมื่อบริการภายนอกไม่พร้อม", fc=LIGHT, size=13)
    rows = [
        (4.15, "ยืนยันตัวตน", "คิวผู้ดูแลระบบ"),
        (3.15, "คัดกรองห้องสนทนา", "ใช้ผลกฎชั้นที่ 1"),
        (2.15, "ภาพสินค้า", "ไม่บล็อกอัตโนมัติ"),
        (1.15, "ข้อพิพาท", "กฎสถานะพัสดุ"),
        (0.15, "ชำระเงิน", "สลิป / ผู้ดูแลระบบ"),
    ]
    for y, left, right in rows:
        box(ax, 0.4, y, 4.7, 0.85, left, fc=SOFT, size=12)
        chevron(ax, 5.25, 6.15, y + 0.42, GRAY)
        box(ax, 6.3, y, 5.3, 0.85, right, fc=BOX, size=12)
    finish(fig, "fig3_fallback.png")


def crop_screenshots():
    specs = [
        (SHOT / "ui10_login.png", OUT / "shot_login.png", (0.22, 0.08, 0.78, 0.92)),
        (SHOT / "ui02_ekyc.png", OUT / "shot_ekyc.png", (0.18, 0.12, 0.82, 0.92)),
        (SHOT / "ui03_sell.png", OUT / "shot_sell.png", (0.12, 0.08, 0.88, 0.78)),
        (SHOT / "ui04_chat.png", OUT / "shot_chat.png", (0.0, 0.0, 1.0, 1.0)),
        (SHOT / "ui05_orders.png", OUT / "shot_orders.png", (0.12, 0.11, 0.88, 0.78)),
        (SHOT / "ui06_product.png", OUT / "shot_product.png", (0.08, 0.10, 0.92, 0.90)),
    ]
    for src, dst, boxf in specs:
        if not src.exists():
            raise FileNotFoundError(src)
        crop_shot(src, dst, boxf)


def main():
    crop_screenshots()
    fig_scopes()
    fig_architecture()
    fig_three_tier()
    fig_login_split()
    fig_ekyc()
    fig_image()
    fig_chat()
    fig_dispute()
    fig_escrow()
    fig_fallback()
    stack_pipeline_and_shot(OUT / "fig3_dispute_flow.png", OUT / "shot_chat.png", "fig3_dispute_ui.png")
    stack_pipeline_and_shot(OUT / "fig3_escrow_flow.png", OUT / "shot_orders.png", "fig3_escrow_ui.png")
    print("chapter 3 visuals ready")


if __name__ == "__main__":
    main()
