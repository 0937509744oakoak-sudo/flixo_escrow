# -*- coding: utf-8 -*-
"""Merge appendix templates into บทของทุน.docx (replace empty appendix section)."""
from __future__ import annotations

import shutil
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / "docs"
FONT = "TH Sarabun New"


def find_tun() -> Path:
    for p in DOCS.glob("*.docx"):
        if "ทุน" in p.name and "Appendix" not in p.name and "Templates" not in p.name:
            return p
    raise FileNotFoundError("บทของทุน.docx not found")


def set_run_font(run, size=16, bold=False):
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


def add_p(doc, text="", size=16, bold=False, center=False, space_after=6):
    p = doc.add_paragraph()
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.space_before = Pt(0)
    p.paragraph_format.line_spacing = 1.5
    p.paragraph_format.first_line_indent = Pt(0)
    if center:
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = p.add_run(text)
    set_run_font(run, size=size, bold=bold)
    return p


def add_page_break(doc):
    p = doc.add_paragraph()
    run = p.add_run()
    br = OxmlElement("w:br")
    br.set(qn("w:type"), "page")
    run._element.append(br)


def add_table(doc, headers, rows):
    table = doc.add_table(rows=1 + len(rows), cols=len(headers))
    table.style = "Table Grid"
    for j, h in enumerate(headers):
        cell = table.rows[0].cells[j]
        cell.text = ""
        run = cell.paragraphs[0].add_run(h)
        set_run_font(run, size=14, bold=True)
    for i, row in enumerate(rows):
        for j, val in enumerate(row):
            cell = table.rows[i + 1].cells[j]
            cell.text = ""
            run = cell.paragraphs[0].add_run(str(val))
            set_run_font(run, size=14, bold=False)
    add_p(doc, "")
    return table


def find_appendix_start(doc: Document) -> int:
    """Return paragraph index of the real end-of-book ภาคผนวก (after bibliography)."""
    bib = None
    for i, p in enumerate(doc.paragraphs):
        if p.text.strip() == "บรรณานุกรม":
            bib = i
    if bib is None:
        raise RuntimeError("บรรณานุกรม not found")
    for i, p in enumerate(doc.paragraphs):
        if i > bib and p.text.strip() == "ภาคผนวก":
            return i
    raise RuntimeError("ภาคผนวก after bibliography not found")


def remove_from_paragraph(doc: Document, start_idx: int) -> None:
    body = doc.element.body
    start_el = doc.paragraphs[start_idx]._element
    children = list(body)
    try:
        idx = children.index(start_el)
    except ValueError as e:
        raise RuntimeError("Could not locate appendix start element") from e
    for child in children[idx:]:
        if child.tag == qn("w:sectPr"):
            continue
        body.remove(child)


def append_appendix(doc: Document) -> None:
    add_page_break(doc)
    add_p(doc, "ภาคผนวก", size=18, bold=True, center=True)

    # ก
    add_page_break(doc)
    add_p(doc, "ภาคผนวก ก", size=18, bold=True, center=True)
    add_p(doc, "แบบแผนธุรกิจ", size=18, bold=True, center=True)
    add_p(doc, "Business Model Canvas", size=18, bold=True, center=True)
    add_p(doc, "")
    add_p(
        doc,
        "ภาพภาคผนวก ก แบบจำลองธุรกิจของแพลตฟอร์ม FLIXO ด้วย Business Model Canvas",
        size=14,
        center=True,
    )
    add_p(doc, "")
    add_p(doc, "[วางรูป Business Model Canvas ที่นี่ — วาดจาก Canva/PowerPoint แล้วแทรกรูป PNG]", size=16, bold=True, center=True)
    add_p(doc, "")
    add_p(doc, "ตารางช่วยกรอก 9 ช่อง (ร่างเริ่มต้น — แก้ไขได้ก่อนส่ง)", size=16, bold=True)
    add_table(
        doc,
        ["ช่อง BMC", "เนื้อหาร่าง FLIXO"],
        [
            ["Customer Segments", "ผู้ซื้อ–ผู้ขาย C2C บนโซเชียลที่ต้องการโอนเงินอย่างปลอดภัย"],
            ["Value Propositions", "กักเก็บเงิน / e-KYC แยก Login / คัดกรองแชท / ข้อพิพาทมีแอดมินตัดสิน"],
            ["Channels", "เว็บแอป โซเชียล ชุมชนซื้อขายออนไลน์"],
            ["Customer Relationships", "บัญชีผู้ใช้ แชทในระบบ แจ้งเตือนออเดอร์ ศูนย์ช่วยเหลือ"],
            ["Revenue Streams", "[ระบุช่องทางรายได้ที่ทีมตัดสินใจจริง]"],
            ["Key Resources", "ระบบ PHP/MySQL, Omise, e-KYC, Vision/LLM, ทีมพัฒนา"],
            ["Key Activities", "พัฒนาแพลตฟอร์ม ตรวจข้อพิพาท ปรับกฎคัดกรอง ดูแลข้อมูล"],
            ["Key Partnerships", "Omise, AiGen/Sumsub/ThaiD, ผู้ให้บริการ AI, มหาวิทยาลัย"],
            ["Cost Structure", "โฮสติ้ง API ค่าธรรมเนียมชำระเงิน ค่าพัฒนา ค่าแอดมิน"],
        ],
    )

    # ข
    add_page_break(doc)
    add_p(doc, "ภาคผนวก ข", size=18, bold=True, center=True)
    add_p(
        doc,
        "รายนามผู้เชี่ยวชาญตรวจสอบประเมินแบบสอบถามและเอกสารแบบประเมิน",
        size=16,
        bold=True,
        center=True,
    )
    add_p(doc, "")
    add_p(doc, "ข.1 รายนามผู้เชี่ยวชาญ", size=16, bold=True)
    add_table(
        doc,
        ["ลำดับ", "ชื่อ–นามสกุล", "ตำแหน่ง / ความเชี่ยวชาญ", "สังกัด"],
        [
            ["1", "[ชื่อ]", "[ด้าน IT]", "[สังกัด]"],
            ["2", "[ชื่อ]", "[ด้าน IT]", "[สังกัด]"],
            ["3", "[ชื่อ]", "[ด้าน IT / UX]", "[สังกัด]"],
            ["4", "[ชื่อ]", "[ด้านระบบสารสนเทศ]", "[สังกัด]"],
            ["5", "[ชื่อ]", "[ด้านธุรกิจ / อื่น ๆ]", "[สังกัด]"],
        ],
    )
    add_p(doc, "หมายเหตุ: ต้องครบ 5 ท่านตามที่ระบุในบทคัดย่อ และต้องเป็นรายชื่อจริง", size=14)

    add_p(doc, "ข.2 แบบฟอร์มให้คะแนน IOC", size=16, bold=True)
    add_p(doc, "เกณฑ์: +1 = สอดคล้อง, 0 = ไม่แน่ใจ, −1 = ไม่สอดคล้อง", size=14)
    add_table(
        doc,
        ["ข้อ", "รายการประเมิน", "ผช.1", "ผช.2", "ผช.3", "ผช.4", "ผช.5", "IOC"],
        [
            ["1", "เข้าใจว่าเงินถูกกักเก็บที่บัญชีกลาง", "", "", "", "", "", ""],
            ["2", "เข้าใจสถานะคำสั่งซื้อแต่ละขั้น", "", "", "", "", "", ""],
            ["3", "ขั้นตอนซื้อขายไม่สับสน", "", "", "", "", "", ""],
            ["4", "Login แยกจาก e-KYC ชัดเจน", "", "", "", "", "", ""],
            ["5", "มั่นใจขึ้นเมื่อเทียบโอนตรง", "", "", "", "", "", ""],
            ["6", "พึงพอใจโดยรวมต่อการทดลองใช้", "", "", "", "", "", ""],
            ["", "ค่าเฉลี่ย IOC ทั้งฉบับ", "", "", "", "", "", "[คำนวณ]"],
        ],
    )
    add_p(doc, "ลงชื่อผู้เชี่ยวชาญ: _______________________ วันที่: ______________", size=14)

    add_p(doc, "ข.3 แบบประเมินผู้ใช้ (แนบฉบับเต็ม)", size=16, bold=True)
    add_p(doc, "ชื่อแบบประเมิน: แบบประเมินการทดลองใช้แพลตฟอร์ม FLIXO Escrow", size=14)
    add_p(
        doc,
        "คำชี้แจง: กรุณาทดลองใช้ระบบตามสถานการณ์ที่กำหนดก่อนตอบ คะแนน 1 = ไม่เห็นด้วยอย่างยิ่ง … 5 = เห็นด้วยอย่างยิ่ง ไม่เก็บรูปบัตรประชาชนหรือภาพใบหน้า",
        size=14,
    )
    add_p(doc, "ส่วนที่ 1 ข้อมูลทั่วไป", size=14, bold=True)
    add_p(doc, "1) บทบาทที่ทดลองใช้: ผู้ซื้อ / ผู้ขาย / ทั้งผู้ซื้อและผู้ขาย", size=14)
    add_p(doc, "2) เคยซื้อขายสินค้าผ่านโซเชียลมีเดียมาก่อนหรือไม่: เคย / ไม่เคย", size=14)
    add_p(doc, "ส่วนที่ 2 ความเข้าใจและการใช้งาน (Likert 1–5)", size=14, bold=True)
    for q in [
        "1) ข้าพเจ้าเข้าใจว่าเงินค่าสินค้าถูกกักเก็บไว้ที่บัญชีกลางก่อนส่งมอบสินค้า",
        "2) ข้าพเจ้าเข้าใจสถานะคำสั่งซื้อในแต่ละขั้นตอน",
        "3) ขั้นตอนการซื้อขายในระบบใช้งานได้โดยไม่สับสน",
        "4) หน้าเข้าสู่ระบบแยกจากการยืนยันตัวตนอย่างชัดเจน",
        "5) เมื่อเทียบกับการโอนเงินตรงนอกระบบ ข้าพเจ้ามั่นใจขึ้นเมื่อซื้อขายผ่านระบบนี้",
        "6) โดยรวมแล้ว ข้าพเจ้าพึงพอใจต่อการทดลองใช้ระบบ FLIXO Escrow",
    ]:
        add_p(doc, q, size=14)
    add_p(doc, "ส่วนที่ 3 ความคิดเห็นเพิ่มเติม", size=14, bold=True)
    add_p(doc, "1) จุดที่เข้าใจยากที่สุดในการทดลองใช้ระบบคืออะไร", size=14)
    add_p(doc, "2) สิ่งที่ควรปรับปรุงก่อนนำไปใช้งานจริงคืออะไร", size=14)

    add_p(doc, "ข.4 สรุปผลแบบประเมินผู้ใช้หลังทดลองใช้ (N=33)", size=16, bold=True)
    add_table(
        doc,
        ["ประเด็น", "ค่าเฉลี่ย", "S.D."],
        [
            ["[คัดลอกรายข้อจากตารางที่ 4.7 ในบทที่ 4]", "", ""],
            ["ค่าเฉลี่ยรวมทุกข้อ", "4.20", "0.74"],
            ["พึงพอใจโดยรวม", "4.30", ""],
        ],
    )
    add_p(
        doc,
        "หมายเหตุ: ผลนี้เป็นความเข้าใจและความพึงพอใจของกลุ่มทดลองใช้ ไม่ใช่หลักฐานว่าการโกงในตลาดจริงลดลง",
        size=14,
    )

    # ค
    bios = [
        ("นาย ธนพัต ฉุยสุริฉาย", "Mr. Thanaphat Chuisurichai"),
        ("นางสาว ณภัทร แซ่ตั้ง", "Miss Napat Saetang"),
        ("นางสาว อริษรา ราชนิยม", "Miss Arisara Rachaniyom"),
    ]
    add_page_break(doc)
    add_p(doc, "ภาคผนวก ค", size=18, bold=True, center=True)
    add_p(doc, "ประวัติผู้จัดทำ", size=18, bold=True, center=True)
    for i, (th, en) in enumerate(bios, 1):
        if i > 1:
            add_page_break(doc)
            add_p(doc, "ภาคผนวก ค (ต่อ)", size=16, bold=True, center=True)
        add_p(doc, "ประวัติผู้จัดทำ (BIOGRAPHY)", size=16, bold=True, center=True)
        add_p(doc, "[วางรูปถ่ายขนาด 1–1.5 นิ้ว มุมขวาบน]", size=14, center=True)
        add_table(
            doc,
            ["รายการ", "รายละเอียด"],
            [
                ["ชื่อ–นามสกุล (ไทย)", th],
                ["ชื่อ–นามสกุล (อังกฤษ)", en],
                ["ตำแหน่ง", "นักศึกษาปริญญาตรี สาขาวิชาวิศวกรรมคอมพิวเตอร์"],
                ["สังกัด", "คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา"],
                ["ที่อยู่", "[ที่อยู่ติดต่อได้]"],
                ["โทรศัพท์", "[เบอร์]"],
                ["อีเมล", "[อีเมล]"],
            ],
        )
        add_p(doc, "ประวัติการศึกษา", size=16, bold=True)
        add_p(doc, "- มัธยมศึกษา: [โรงเรียน] ปีที่จบ [พ.ศ.]", size=14)
        add_p(
            doc,
            "- ปริญญาตรี (กำลังศึกษา): วิศวกรรมศาสตรบัณฑิต สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา",
            size=14,
        )
        add_p(doc, "ผลงาน / ความรับผิดชอบในโครงงาน", size=16, bold=True)
        add_p(doc, "- [...]", size=14)

    # ง
    advisors = [
        ("ผู้ช่วยศาสตราจารย์ ดร.พรภวิษย์ บุญศรีเมือง", "สาขาวิชาวิศวกรรมคอมพิวเตอร์"),
        ("ผู้ช่วยศาสตราจารย์ ดร.รวิ อุตตมธนินทร์", "สาขาวิชาวิศวกรรมคอมพิวเตอร์"),
        ("ผู้ช่วยศาสตราจารย์ ดร.ขวัญเรือน รัศมี", "สาขาวิชาวิศวกรรมคอมพิวเตอร์"),
        ("อาจารย์ กานต์ เจริญจิตร", "สาขาวิชาวิศวกรรมคอมพิวเตอร์"),
        ("ผู้ช่วยศาสตราจารย์ ดร.เศรษฐกาล โปร่งนุช", "สาขาวิชาวิศวกรรมหุ่นยนต์"),
        ("อาจารย์ ทักษอร อักษรศิลป์", "สาขาวิชาวิศวกรรมหุ่นยนต์"),
    ]
    add_page_break(doc)
    add_p(doc, "ภาคผนวก ง", size=18, bold=True, center=True)
    add_p(doc, "ประวัติอาจารย์ที่ปรึกษา", size=18, bold=True, center=True)
    add_p(doc, "กรอกประวัติทีละท่าน โดยขอข้อมูลจากอาจารย์ก่อนลงเล่มส่ง", size=14, center=True)
    for i, (name, dept) in enumerate(advisors, 1):
        if i > 1:
            add_page_break(doc)
            add_p(doc, "ภาคผนวก ง (ต่อ)", size=16, bold=True, center=True)
        add_p(doc, f"{i}. {name}", size=16, bold=True)
        add_table(
            doc,
            ["รายการ", "รายละเอียด"],
            [
                ["ชื่อ–นามสกุล", name],
                ["ตำแหน่งทางวิชาการ", "[ผศ.ดร. / อาจารย์]"],
                [
                    "สังกัด",
                    f"{dept} คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา",
                ],
                ["ประวัติการศึกษาล่าสุด", "[ปริญญา / สาขา / สถาบัน / ปี]"],
                ["ผลงาน / ความสนใจทางวิชาการ", "[3–5 บรรทัด]"],
                ["อีเมล (ถ้าอนุญาตให้ลง)", "[อีเมลหน่วยงาน]"],
            ],
        )


def main():
    tun = find_tun()
    backup = DOCS / "บทของทุน_backup_before_appendix.docx"
    shutil.copy2(tun, backup)

    doc = Document(str(tun))
    start = find_appendix_start(doc)
    remove_from_paragraph(doc, start)
    append_appendix(doc)

    out_primary = tun
    out_alt = DOCS / "บทของทุน_with_appendix.docx"
    try:
        doc.save(str(out_primary))
        saved = out_primary
    except PermissionError:
        doc.save(str(out_alt))
        saved = out_alt

    # verify
    check = Document(str(saved))
    marks = [p.text.strip() for p in check.paragraphs if p.text.strip().startswith("ภาคผนวก")]
    log = DOCS / "_tmp_merge_appendix_log.txt"
    log.write_text(
        f"backup={backup.name}\nsaved={saved.name}\nstart_removed_at={start}\nmarks=\n"
        + "\n".join(marks[-20:]),
        encoding="utf-8",
    )
    print(f"OK saved={saved.name} backup={backup.name}")


if __name__ == "__main__":
    main()
