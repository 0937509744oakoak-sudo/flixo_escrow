# -*- coding: utf-8 -*-
"""Insert generated BMC image into บทของทุน.docx appendix ก."""
from __future__ import annotations

import shutil
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Inches, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
IMG = DOCS / "thesis_figures" / "fig_appendix_a_bmc.png"
FONT = "TH Sarabun New"


def find_tun() -> Path:
    for p in DOCS.glob("*.docx"):
        if "ทุน" in p.name and "backup" not in p.name and "Appendix" not in p.name and "Templates" not in p.name and "with" not in p.name:
            return p
    raise FileNotFoundError("funding docx not found")


def set_run_font(run, size=14, bold=False):
    run.font.name = FONT
    run.font.size = Pt(size)
    run.bold = bold
    run.font.color.rgb = RGBColor(0, 0, 0)
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = OxmlElement("w:rFonts")
        rpr.append(rfonts)
    rfonts.set(qn("w:ascii"), FONT)
    rfonts.set(qn("w:hAnsi"), FONT)
    rfonts.set(qn("w:eastAsia"), FONT)


def main():
    if not IMG.exists():
        raise FileNotFoundError(IMG)
    tun = find_tun()
    backup = DOCS / "บทของทุน_backup_before_bmc.docx"
    shutil.copy2(tun, backup)
    doc = Document(str(tun))

    placeholder = None
    for p in doc.paragraphs:
        if "วางรูป Business Model Canvas" in p.text or "[วางรูป Business Model Canvas" in p.text:
            placeholder = p
            break
    if placeholder is None:
        # insert after Business Model Canvas heading
        for i, p in enumerate(doc.paragraphs):
            if p.text.strip() == "Business Model Canvas":
                placeholder = doc.paragraphs[i + 1] if i + 1 < len(doc.paragraphs) else p
                break
    if placeholder is None:
        raise RuntimeError("BMC placeholder not found")

    # clear placeholder and add image + caption
    placeholder.clear()
    placeholder.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = placeholder.add_run()
    run.add_picture(str(IMG), width=Cm(16))

    # ensure caption paragraph exists after image
    # If next para already has caption text, leave it; else add after
    # Find index
    idx = None
    for i, p in enumerate(doc.paragraphs):
        if p._element is placeholder._element:
            idx = i
            break
    caption_text = "ภาพภาคผนวก ก แบบจำลองธุรกิจของแพลตฟอร์ม FLIXO ด้วย Business Model Canvas"
    # remove duplicate old caption lines nearby if they are only the caption
    # add caption under image if missing immediately after
    if idx is not None and idx + 1 < len(doc.paragraphs):
        nxt = doc.paragraphs[idx + 1]
        if "ภาพภาคผนวก ก" not in nxt.text:
            # insert new paragraph after by adding at end then moving? simpler: reuse empty next if any
            if not nxt.text.strip() or "ตารางช่วยกรอก" in nxt.text:
                # create caption by modifying a new paragraph inserted via XML is hard;
                # add_run on a fresh paragraph at document end then we accept order issues.
                pass

    # Add caption as following paragraph content if next is the helper intro
    # Walk: after image para, if caption not present among nearby, insert via placeholder's add next
    from docx.text.paragraph import Paragraph

    new_p = OxmlElement("w:p")
    placeholder._element.addnext(new_p)
    cap = Paragraph(new_p, placeholder._parent)
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cap.paragraph_format.space_before = Pt(6)
    cap.paragraph_format.space_after = Pt(10)
    r = cap.add_run(caption_text)
    set_run_font(r, size=14, bold=False)

    try:
        doc.save(str(tun))
        saved = tun
    except PermissionError:
        saved = DOCS / "บทของทุน_with_bmc.docx"
        doc.save(str(saved))

    (DOCS / "_tmp_bmc_insert_log.txt").write_text(
        f"saved={saved.name}\nbackup={backup.name}\nimg={IMG.name}\n",
        encoding="utf-8",
    )
    print("OK")


if __name__ == "__main__":
    main()
