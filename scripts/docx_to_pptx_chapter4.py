# -*- coding: utf-8 -*-
"""Convert FLIXO Chapter 4 thesis DOCX -> PPTX with full content."""

from __future__ import annotations

import io
import re
import tempfile
from pathlib import Path

from docx import Document
from docx.document import Document as DocumentObject
from docx.oxml.ns import qn
from docx.table import Table
from docx.text.paragraph import Paragraph
from pptx import Presentation
from pptx.dml.color import RGBColor
from pptx.enum.shapes import MSO_SHAPE
from pptx.enum.text import PP_ALIGN, MSO_ANCHOR
from pptx.util import Inches, Pt, Emu

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_thesis.docx"
OUT = ROOT / "docs" / "FLIXO_Chapter4_Experiments_Report_thesis.pptx"

NAVY = RGBColor(0x1B, 0x3A, 0x4B)
TEAL = RGBColor(0x2C, 0x6E, 0x8A)
SLATE = RGBColor(0x3D, 0x4F, 0x5F)
INK = RGBColor(0x1A, 0x1A, 0x1A)
MUTED = RGBColor(0x5A, 0x6A, 0x75)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
LIGHT = RGBColor(0xF4, 0xF7, 0xF9)
LINE = RGBColor(0xD5, 0xDE, 0xE5)
ROW_ALT = RGBColor(0xE8, 0xEE, 0xF2)
WARN = RGBColor(0x8A, 0x4B, 0x12)
CREAM = RGBColor(0xFC, 0xF3, 0xE8)

FONT = "TH Sarabun New"
MAX_BODY_CHARS = 1200
MAX_BULLETS = 8


def set_run(run, size=16, bold=False, color=INK):
    run.font.name = FONT
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


def add_top_bar(slide):
    shape = slide.shapes.add_shape(
        MSO_SHAPE.RECTANGLE, Inches(0), Inches(0), Inches(13.333), Inches(0.12)
    )
    shape.fill.solid()
    shape.fill.fore_color.rgb = NAVY
    shape.line.fill.background()


def add_footer(slide, page: int, total: int, section: str = ""):
    box = slide.shapes.add_textbox(Inches(0.4), Inches(7.1), Inches(12.5), Inches(0.3))
    tf = box.text_frame
    tf.clear()
    p = tf.paragraphs[0]
    p.alignment = PP_ALIGN.RIGHT
    run = p.add_run()
    sec = f" · {section}" if section else ""
    run.text = f"FLIXO Escrow · บทที่ 4{sec} · {page}/{total}"
    set_run(run, 10, False, MUTED)


def textbox(slide, left, top, width, height, text, size=16, bold=False, color=INK, align=PP_ALIGN.LEFT):
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


def iter_block_items(parent: DocumentObject):
    """Yield Paragraph and Table objects in document order."""
    parent_elm = parent.element.body
    for child in parent_elm.iterchildren():
        if child.tag == qn("w:p"):
            yield Paragraph(child, parent)
        elif child.tag == qn("w:tbl"):
            yield Table(child, parent)


def heading_level(para: Paragraph) -> int | None:
    name = (para.style.name if para.style else "") or ""
    if name.startswith("Heading"):
        m = re.search(r"(\d+)", name)
        return int(m.group(1)) if m else 1
    return None


def para_images(para: Paragraph, doc: Document) -> list[bytes]:
    """Extract image blobs from a paragraph."""
    blobs = []
    for blip in para._element.xpath(".//a:blip"):
        embed = blip.get(qn("r:embed"))
        if not embed:
            continue
        try:
            part = doc.part.related_parts[embed]
            blobs.append(part.blob)
        except Exception:
            continue
    return blobs


def table_matrix(table: Table) -> list[list[str]]:
    rows = []
    for row in table.rows:
        cells = []
        for cell in row.cells:
            t = " ".join(cell.text.split())
            cells.append(t)
        # de-dupe merged cell repeats horizontally if identical adjacent
        cleaned = []
        for c in cells:
            if cleaned and cleaned[-1] == c and len(cells) > 6:
                # keep duplicates for small tables; skip only obvious merges in wide tables
                cleaned.append(c)
            else:
                cleaned.append(c)
        rows.append(cells)
    return rows


def chunk_texts(texts: list[str], max_chars=MAX_BODY_CHARS, max_items=MAX_BULLETS) -> list[list[str]]:
    chunks: list[list[str]] = []
    cur: list[str] = []
    cur_len = 0
    for t in texts:
        t = t.strip()
        if not t:
            continue
        add_len = len(t) + 1
        if cur and (len(cur) >= max_items or cur_len + add_len > max_chars):
            chunks.append(cur)
            cur = []
            cur_len = 0
        cur.append(t)
        cur_len += add_len
    if cur:
        chunks.append(cur)
    return chunks or [[]]


class SlideBuilder:
    def __init__(self):
        self.prs = Presentation()
        self.prs.slide_width = Inches(13.333)
        self.prs.slide_height = Inches(7.5)
        self.slides = []
        self.sections = []
        self._img_tmpdir = tempfile.TemporaryDirectory(prefix="ch4_pptx_")

    def add_blank(self, section=""):
        s = self.prs.slides.add_slide(self.prs.slide_layouts[6])
        self.slides.append(s)
        self.sections.append(section)
        return s

    def finish(self):
        total = len(self.slides)
        for i, s in enumerate(self.slides, 1):
            add_footer(s, i, total, self.sections[i - 1])

    def title_slide(self, lines: list[str]):
        s = self.add_blank("ปก")
        add_bg(s, NAVY)
        add_top_bar(s)
        title = lines[0] if lines else "บทที่ 4"
        subtitle = lines[1] if len(lines) > 1 else ""
        rest = "\n".join(lines[2:]) if len(lines) > 2 else ""
        textbox(s, 0.8, 2.1, 11.7, 0.6, title, 36, True, WHITE)
        if subtitle:
            textbox(s, 0.8, 2.9, 11.7, 0.6, subtitle, 24, True, RGBColor(0xC9, 0xD7, 0xE0))
        if rest:
            textbox(s, 0.8, 3.8, 11.7, 2.2, rest, 16, False, RGBColor(0xB8, 0xC9, 0xD4))

    def content_slide(self, section: str, title: str, bodies: list[str], warn: bool = False):
        s = self.add_blank(section[:40])
        add_bg(s)
        add_top_bar(s)
        bar = s.shapes.add_shape(MSO_SHAPE.RECTANGLE, Inches(0), Inches(0.12), Inches(0.12), Inches(6.98))
        bar.fill.solid()
        bar.fill.fore_color.rgb = TEAL
        bar.line.fill.background()

        textbox(s, 0.45, 0.28, 12.4, 0.5, title, 22 if len(title) < 60 else 18, True, NAVY)
        y = 0.95
        bodies = [t for t in bodies if t.strip()]
        if warn and bodies:
            # estimate height by length
            h = 1.15 if len(bodies[0]) < 180 else 1.55
            shape = s.shapes.add_shape(
                MSO_SHAPE.ROUNDED_RECTANGLE, Inches(0.45), Inches(y), Inches(12.4), Inches(h)
            )
            shape.fill.solid()
            shape.fill.fore_color.rgb = CREAM
            shape.line.color.rgb = WARN
            textbox(s, 0.65, y + 0.12, 12.0, h - 0.2, bodies[0], 14, True, WARN)
            bodies = bodies[1:]
            y += h + 0.15

        box = s.shapes.add_textbox(Inches(0.45), Inches(y), Inches(12.4), Inches(6.85 - y))
        tf = box.text_frame
        tf.word_wrap = True
        tf.clear()
        for i, body in enumerate(bodies):
            p = tf.paragraphs[0] if i == 0 else tf.add_paragraph()
            p.space_after = Pt(7)
            run = p.add_run()
            run.text = f"•  {body}" if len(bodies) > 1 else body
            size = 14 if len(body) > 280 else (15 if len(body) > 160 else 16)
            set_run(run, size, False, SLATE)

    def table_slide(self, section: str, title: str, matrix: list[list[str]], note: str = ""):
        s = self.add_blank(section[:40])
        add_bg(s)
        add_top_bar(s)
        textbox(s, 0.4, 0.28, 12.5, 0.45, title, 20, True, NAVY)
        if not matrix:
            textbox(s, 0.4, 1.2, 12, 1, "(ตารางว่าง)", 14, False, MUTED)
            return

        headers = matrix[0]
        rows = matrix[1:] if len(matrix) > 1 else []
        cols = max(len(r) for r in matrix)
        # normalize
        headers = (headers + [""] * cols)[:cols]
        rows = [(r + [""] * cols)[:cols] for r in rows]

        # fit rows: if too many, split across slides
        max_rows = 10 if cols <= 4 else (8 if cols <= 6 else 6)
        chunks = [rows[i : i + max_rows] for i in range(0, max(len(rows), 1), max_rows)] or [[]]

        # render first on this slide; overflow recursively via caller normally — handle here
        self._draw_table(s, headers, chunks[0], top=0.9, note=note if len(chunks) == 1 else "")
        for idx, chunk in enumerate(chunks[1:], 2):
            s2 = self.add_blank(section[:40])
            add_bg(s2)
            add_top_bar(s2)
            textbox(s2, 0.4, 0.28, 12.5, 0.45, f"{title} (ต่อ {idx})", 20, True, NAVY)
            self._draw_table(s2, headers, chunk, top=0.9, note=note if idx == len(chunks) else "")

    def _draw_table(self, slide, headers, rows, top=0.9, note=""):
        cols = len(headers)
        total_w = 12.5
        # weight by header length
        weights = [max(len(h), 4) for h in headers]
        sw = sum(weights) or 1
        col_w = [total_w * w / sw for w in weights]
        # clamp extreme
        col_w = [max(1.1, min(5.5, w)) for w in col_w]
        scale = total_w / sum(col_w)
        col_w = [w * scale for w in col_w]

        row_h = 0.42 if len(rows) >= 8 else (0.5 if len(rows) >= 5 else 0.58)
        font = 11 if cols >= 5 or any(len(h) > 18 for h in headers) else 12

        x0 = 0.4
        x = x0
        for h, w in zip(headers, col_w):
            shape = slide.shapes.add_shape(
                MSO_SHAPE.RECTANGLE, Inches(x), Inches(top), Inches(w), Inches(0.45)
            )
            shape.fill.solid()
            shape.fill.fore_color.rgb = NAVY
            shape.line.fill.background()
            textbox(slide, x + 0.04, top + 0.05, w - 0.08, 0.35, h, font, True, WHITE)
            x += w

        for ri, row in enumerate(rows):
            y = top + 0.45 + ri * row_h
            x = x0
            bg = WHITE if ri % 2 == 0 else ROW_ALT
            for cell, w in zip(row, col_w):
                shape = slide.shapes.add_shape(
                    MSO_SHAPE.RECTANGLE, Inches(x), Inches(y), Inches(w), Inches(row_h)
                )
                shape.fill.solid()
                shape.fill.fore_color.rgb = bg
                shape.line.color.rgb = LINE
                txt = cell  # เก็บข้อความเต็มจาก DOCX
                size = font - 1
                if len(txt) > 90:
                    size = max(9, font - 2)
                textbox(slide, x + 0.04, y + 0.04, w - 0.08, row_h - 0.06, txt, size, False, INK)
                x += w

        if note:
            y_note = top + 0.45 + max(len(rows), 1) * row_h + 0.15
            if y_note < 6.7:
                textbox(slide, 0.4, y_note, 12.5, 0.5, note, 13, False, SLATE)

    def image_slide(self, section: str, title: str, blob: bytes, caption: str = ""):
        s = self.add_blank(section[:40])
        add_bg(s)
        add_top_bar(s)
        textbox(s, 0.4, 0.25, 12.5, 0.4, title or "ภาพประกอบ", 18, True, NAVY)
        # save temp
        ext = ".png"
        if blob[:3] == b"\xff\xd8\xff":
            ext = ".jpg"
        path = Path(self._img_tmpdir.name) / f"img_{len(self.slides)}{ext}"
        path.write_bytes(blob)
        # fit image
        max_w, max_h = 11.8, 5.6
        try:
            from PIL import Image

            with Image.open(path) as im:
                w, h = im.size
            aspect = w / max(h, 1)
            draw_w = max_w
            draw_h = draw_w / aspect
            if draw_h > max_h:
                draw_h = max_h
                draw_w = draw_h * aspect
        except Exception:
            draw_w, draw_h = 10.5, 5.2
        left = (13.333 - draw_w) / 2
        top = 0.85
        s.shapes.add_picture(str(path), Inches(left), Inches(top), width=Inches(draw_w))
        if caption:
            textbox(s, 0.5, 6.55, 12.3, 0.4, caption, 12, False, MUTED, PP_ALIGN.CENTER)


def convert(src: Path, out: Path):
    doc = Document(str(src))
    b = SlideBuilder()

    blocks = list(iter_block_items(doc))

    # Title from leading normals before first heading
    title_lines = []
    i = 0
    while i < len(blocks):
        blk = blocks[i]
        if isinstance(blk, Paragraph):
            lvl = heading_level(blk)
            if lvl:
                break
            t = blk.text.strip()
            if t:
                title_lines.append(t)
            i += 1
        else:
            break
    b.title_slide(title_lines[:6] if title_lines else ["บทที่ 4", "ผลการทดลองและการใช้งานจริง"])

    section = "บทที่ 4"
    slide_title = "บทนำ"
    pending_bodies: list[str] = []
    pending_caption = ""

    def flush_bodies(warn=False):
        nonlocal pending_bodies
        if not pending_bodies:
            return
        texts = pending_bodies[:]
        pending_bodies = []
        chunks = chunk_texts(texts)
        for ci, chunk in enumerate(chunks):
            if not chunk:
                continue
            is_warn = (ci == 0) and (
                warn or any("ข้อควรตระหนัก" in t or "ไม่ใช่การรับรอง" in t for t in chunk[:1])
            )
            b.content_slide(section, slide_title, chunk, warn=is_warn)

    while i < len(blocks):
        blk = blocks[i]

        if isinstance(blk, Paragraph):
            lvl = heading_level(blk)
            text = blk.text.strip()
            imgs = para_images(blk, doc)

            if lvl:
                flush_bodies()
                slide_title = text or slide_title
                if lvl == 1:
                    section = text
                # ไม่แยกสไลด์ว่างเฉพาะหัวข้อ — ใช้หัวข้อเป็นชื่อสไลด์เนื้อหาถัดไป
                i += 1
                continue

            if imgs:
                flush_bodies()
                cap = text or pending_caption or slide_title
                for bi, blob in enumerate(imgs):
                    title = cap if bi == 0 else f"{cap} ({bi+1})"
                    b.image_slide(section, title, blob, caption=cap if text else "")
                pending_caption = text if text else pending_caption
                i += 1
                continue

            if text:
                # bold-looking captions often precede images; keep as body too
                pending_bodies.append(text)
                if text.startswith("ภาพที่") or text.startswith("รูปที่"):
                    pending_caption = text
            i += 1
            continue

        if isinstance(blk, Table):
            # attach any pending short note after heading before table
            note_bodies = pending_bodies[:]
            pending_bodies = []
            # if there were long intros, put them on content slides first
            long_notes = [t for t in note_bodies if len(t) > 160]
            short_notes = [t for t in note_bodies if len(t) <= 160]
            for chunk in chunk_texts(long_notes):
                if chunk:
                    b.content_slide(section, slide_title, chunk)
            matrix = table_matrix(blk)
            note = " | ".join(short_notes) if short_notes else ""
            b.table_slide(section, slide_title, matrix, note=note)
            i += 1
            continue

        i += 1

    flush_bodies()
    b.finish()
    out.parent.mkdir(parents=True, exist_ok=True)
    b.prs.save(str(out))
    # cleanup temp images after save (pptx embeds copies)
    b._img_tmpdir.cleanup()
    return len(b.slides)


def main():
    n = convert(SRC, OUT)
    print(f"Wrote {OUT} ({n} slides) from {SRC.name}")


if __name__ == "__main__":
    main()
