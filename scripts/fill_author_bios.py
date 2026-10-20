# -*- coding: utf-8 -*-
"""Fill author biographies into บทของทุน.docx appendix ค."""
from __future__ import annotations

import shutil
from pathlib import Path

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
FONT = "TH Sarabun New"

BIOS = [
    {
        "th": "นาย ธนพัต ฉุยสุริฉาย",
        "en": "Mr. Tanapat Chuisurichay",
        "position": "นักศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "org": "คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "address": "81 ถ. สิรินธร แขวงบางบำหรุ เขตบางพลัด กรุงเทพมหานคร 10700",
        "phone": "093-750-9744",
        "email": "s66122519066@ssru.ac.th",
        "edu": "กำลังศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "role": "พัฒนาระบบและงานวิจัย FLIXO Escrow",
        "match": ("ธนพัต", "Thanaphat", "Tanapat"),
    },
    {
        "th": "นางสาว ณภัทร แซ่ตั้ง",
        "en": "Ms. Naphat Sae-tang",
        "position": "นักศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "org": "คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "address": "รามคำแหง 68 หัวหมาก บางกะปิ กรุงเทพมหานคร 10240",
        "phone": "083-015-8022",
        "email": "meechiki1514@gmail.com",
        "edu": "กำลังศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "role": "พัฒนาระบบและงานวิจัย FLIXO Escrow",
        "match": ("ณภัทร", "Napat", "Naphat"),
    },
    {
        "th": "นางสาว อริษรา ราชนิยม",
        "en": "Miss Arisara Rachaniyom",
        "position": "นักศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "org": "คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "address": "38/5 สุขสวัสดิ์ 70 ซอย 17 ต.บางครุ อ.พระประแดง จ.สมุทรปราการ 10130",
        "phone": "063-054-9854",
        "email": "alisarama.a@gmail.com",
        "edu": "กำลังศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา",
        "role": "พัฒนาระบบและงานวิจัย FLIXO Escrow",
        "match": ("อริษรา", "Arisara"),
    },
]


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


def set_cell(cell, text: str, bold: bool = False):
    cell.text = ""
    p = cell.paragraphs[0]
    run = p.add_run(text)
    set_run_font(run, size=14, bold=bold)


def table_blob(table) -> str:
    parts = []
    for row in table.rows:
        for cell in row.cells:
            parts.append(cell.text)
    return "\n".join(parts)


def fill_bio_table(table, bio: dict) -> None:
    mapping = {
        "ชื่อ–นามสกุล (ไทย)": bio["th"],
        "ชื่อ-นามสกุล (ไทย)": bio["th"],
        "ชื่อ–นามสกุล (อังกฤษ)": bio["en"],
        "ชื่อ-นามสกุล (อังกฤษ)": bio["en"],
        "ตำแหน่ง": bio["position"],
        "สังกัด": bio["org"],
        "ที่อยู่": bio["address"],
        "โทรศัพท์": bio["phone"],
        "อีเมล": bio["email"],
    }
    for row in table.rows:
        key = row.cells[0].text.strip()
        if key in mapping:
            set_cell(row.cells[0], key, bold=True)
            set_cell(row.cells[1], mapping[key], bold=False)


def update_edu_paragraphs(doc: Document, bio: dict) -> int:
    """Update the education bullet near a matching name table context via sequential scan."""
    # Simpler: replace placeholder education lines that follow each bio by scanning paragraphs
    return 0


def main():
    tun = find_tun()
    backup = DOCS / "บทของทุน_backup_before_bios.docx"
    shutil.copy2(tun, backup)
    doc = Document(str(tun))

    filled = []
    for table in doc.tables:
        blob = table_blob(table)
        if "ชื่อ–นามสกุล (ไทย)" not in blob and "ชื่อ-นามสกุล (ไทย)" not in blob:
            continue
        for bio in BIOS:
            if any(m in blob for m in bio["match"]):
                fill_bio_table(table, bio)
                filled.append(bio["th"])
                break

    # Update education / responsibility paragraphs that still have placeholders,
    # by walking paragraphs after each biography header and matching nearby names.
    paras = doc.paragraphs
    for i, p in enumerate(paras):
        t = p.text.strip()
        for bio in BIOS:
            if bio["th"] in t or bio["en"] in t:
                # look ahead for education / role placeholders
                for j in range(i, min(i + 25, len(paras))):
                    tj = paras[j].text.strip()
                    if tj.startswith("- มัธยมศึกษา:") or tj.startswith("- ปริญญาตรี"):
                        # replace the education block lines once per bio
                        if "มัธยมศึกษา" in tj:
                            # clear high-school placeholder; user didn't provide
                            paras[j].clear()
                            run = paras[j].add_run(f"- {bio['edu']}")
                            set_run_font(run, size=14)
                        elif "ปริญญาตรี" in tj and "[โรงเรียน]" not in tj:
                            # if duplicate edu line left, remove placeholder duplicate
                            if "กำลังศึกษา" in tj or "วิศวกรรมศาสตรบัณฑิต" in tj:
                                paras[j].clear()
                                run = paras[j].add_run("")
                                set_run_font(run, size=14)
                    if tj == "- [...]":
                        paras[j].clear()
                        run = paras[j].add_run(f"- {bio['role']}")
                        set_run_font(run, size=14)

    # Also fix leftover generic education bullets under appendix ค by ordered assignment
    # Find all "- มัธยมศึกษา" or old edu placeholders still present
    edu_idxs = []
    role_idxs = []
    for i, p in enumerate(paras):
        t = p.text.strip()
        if t.startswith("- มัธยมศึกษา:") or t.startswith("- กำลังศึกษาปริญญาตรี") or (
            t.startswith("- ปริญญาตรี") and "วิศวกรรม" in t
        ):
            edu_idxs.append(i)
        if t == "- [...]" or t.startswith("- พัฒนาระบบและงานวิจัย"):
            role_idxs.append(i)

    # Re-apply education in order of appearance for the three bios if needed
    # Collect bio table order already filled; rewrite consecutive edu lines after ค section
    # Safer pass: after each filled name in paragraph text, set next edu line

    # Clean duplicate empty education lines created above
    # Final ordered fill for remaining placeholders
    remaining_edu = [i for i, p in enumerate(doc.paragraphs) if p.text.strip().startswith("- มัธยมศึกษา:")]
    for idx, bio in zip(remaining_edu, BIOS):
        doc.paragraphs[idx].clear()
        run = doc.paragraphs[idx].add_run(f"- {bio['edu']}")
        set_run_font(run, size=14)

    remaining_degree = [
        i
        for i, p in enumerate(doc.paragraphs)
        if p.text.strip().startswith("- ปริญญาตรี (กำลังศึกษา)")
    ]
    for idx in remaining_degree:
        doc.paragraphs[idx].clear()

    remaining_role = [i for i, p in enumerate(doc.paragraphs) if p.text.strip() == "- [...]"]
    for idx, bio in zip(remaining_role, BIOS):
        doc.paragraphs[idx].clear()
        run = doc.paragraphs[idx].add_run(f"- {bio['role']}")
        set_run_font(run, size=14)

    # IOC note: ensure placeholder text remains clear
    for p in doc.paragraphs:
        if "[คำนวณ]" in p.text:
            p.clear()
            run = p.add_run("ค่าเฉลี่ย IOC ทั้งฉบับ: ยังไม่มีข้อมูล (รอเก็บจากผู้เชี่ยวชาญ 5 ท่าน)")
            set_run_font(run, size=14)

    for table in doc.tables:
        blob = table_blob(table)
        if "ค่าเฉลี่ย IOC ทั้งฉบับ" in blob:
            for row in table.rows:
                if "ค่าเฉลี่ย IOC" in row.cells[0].text or "IOC" == row.cells[0].text.strip():
                    # last cell
                    if len(row.cells) >= 2:
                        set_cell(row.cells[-1], "ยังไม่มี", bold=False)

    try:
        doc.save(str(tun))
        saved = tun
    except PermissionError:
        saved = DOCS / "บทของทุน_with_bios.docx"
        doc.save(str(saved))

    # verify
    check = Document(str(saved))
    found = []
    for table in check.tables:
        b = table_blob(table)
        for token in ("Tanapat Chuisurichay", "Naphat Sae-tang", "Arisara Rachaniyom", "093-750-9744", "meechiki1514", "alisarama.a"):
            if token in b:
                found.append(token)
    log = DOCS / "_tmp_bio_fill_log.txt"
    log.write_text(
        f"saved={saved.name}\nbackup={backup.name}\nfilled={filled}\nfound={found}\n",
        encoding="utf-8",
    )
    print("OK")


if __name__ == "__main__":
    main()
