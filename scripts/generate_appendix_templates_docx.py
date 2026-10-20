# -*- coding: utf-8 -*-
"""Generate Word templates for funding-report appendices ก–ง."""
from __future__ import annotations

from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Cm, Pt, RGBColor

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "docs" / "FLIXO_Appendix_Templates_กขคง.docx"
FONT = "TH Sarabun New"


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
    if center:
        p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = p.add_run(text)
    set_run_font(run, size=size, bold=bold)
    return p


def add_table(doc, headers, rows):
    table = doc.add_table(rows=1 + len(rows), cols=len(headers))
    table.style = "Table Grid"
    for j, h in enumerate(headers):
        cell = table.rows[0].cells[j]
        cell.text = ""
        p = cell.paragraphs[0]
        run = p.add_run(h)
        set_run_font(run, size=14, bold=True)
    for i, row in enumerate(rows):
        for j, val in enumerate(row):
            cell = table.rows[i + 1].cells[j]
            cell.text = ""
            p = cell.paragraphs[0]
            run = p.add_run(str(val))
            set_run_font(run, size=14, bold=False)
    doc.add_paragraph()
    return table


def page_break(doc):
    doc.add_page_break()


def main():
    doc = Document()
    for section in doc.sections:
        section.top_margin = Cm(2.5)
        section.bottom_margin = Cm(2.5)
        section.left_margin = Cm(3)
        section.right_margin = Cm(2.5)

    add_p(doc, "ภาคผนวก", size=18, bold=True, center=True)
    add_p(doc, "เทมเพลตสำหรับรายงานทุน FLIXO — กรอกช่อง [...] ก่อนส่ง", size=14, center=True)
    add_p(doc, "ไฟล์ต้นฉบับ Markdown: docs/appendix_funding_templates.md", size=14, center=True)

    page_break(doc)
    add_p(doc, "ภาคผนวก ก", size=18, bold=True, center=True)
    add_p(doc, "แบบแผนธุรกิจ", size=18, bold=True, center=True)
    add_p(doc, "Business Model Canvas", size=18, bold=True, center=True)
    add_p(doc, "")
    add_p(doc, "คำบรรยายแนะนำ: ภาพภาคผนวก ก แบบจำลองธุรกิจของแพลตฟอร์ม FLIXO ด้วย Business Model Canvas", size=14)
    add_p(doc, "วิธีทำ: วาด BMC ใน Canva/PowerPoint แล้วแทรกรูป PNG ด้านล่างนี้ให้เต็มความกว้างหน้า", size=14)
    add_p(doc, "")
    add_p(doc, "[วางรูป Business Model Canvas ที่นี่]", size=16, bold=True, center=True)
    add_p(doc, "")
    add_p(doc, "ตารางช่วยกรอก 9 ช่อง (ร่างเริ่มต้น)", size=16, bold=True)
    add_table(
        doc,
        ["ช่อง BMC", "เนื้อหาร่าง FLIXO (แก้ได้)"],
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

    page_break(doc)
    add_p(doc, "ภาคผนวก ข", size=18, bold=True, center=True)
    add_p(doc, "รายนามผู้เชี่ยวชาญตรวจสอบประเมินแบบสอบถามและเอกสารแบบประเมิน", size=16, bold=True, center=True)
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
    add_p(doc, "คัดลอกจาก docs/appendix_user_evaluation_form.md", size=14)
    add_p(doc, "ส่วน ก ข้อมูลทั่วไป: บทบาทผู้ซื้อ/ผู้ขาย/ทั้งสอง · เคยซื้อขายบนโซเชียล ใช่/ไม่ใช่", size=14)
    add_p(doc, "ส่วน ข Likert 1–5: ข้อ 1–6 ตามแบบประเมิน", size=14)
    add_p(doc, "ส่วน ค ปลายเปิด: จุดที่เข้าใจยาก · สิ่งที่ควรปรับปรุง", size=14)

    add_p(doc, "ข.4 สรุปผล N=33 (อ้างอิงบทที่ 4)", size=16, bold=True)
    add_table(
        doc,
        ["ประเด็น", "ค่าเฉลี่ย", "S.D."],
        [
            ["[คัดจากตารางที่ 4.7]", "", ""],
            ["ค่าเฉลี่ยรวมทุกข้อ", "4.20", "0.74"],
            ["พึงพอใจโดยรวม", "4.30", ""],
        ],
    )
    add_p(doc, "หมายเหตุ: เป็นผลกลุ่มทดลองใช้ ไม่ใช่หลักฐานว่าการโกงในตลาดจริงลดลง", size=14)

    bios = [
        ("นาย ธนพัต ฉุยสุริฉาย", "Mr. Thanaphat Chuisurichai"),
        ("นางสาว ณภัทร แซ่ตั้ง", "Miss Napat Saetang"),
        ("นางสาว อริษรา ราชนิยม", "Miss Arisara Rachaniyom"),
    ]
    page_break(doc)
    add_p(doc, "ภาคผนวก ค", size=18, bold=True, center=True)
    add_p(doc, "ประวัติผู้จัดทำ", size=18, bold=True, center=True)
    for i, (th, en) in enumerate(bios, 1):
        if i > 1:
            page_break(doc)
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
        add_p(doc, "- ปริญญาตรี (กำลังศึกษา): วิศวกรรมศาสตรบัณฑิต สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา", size=14)
        add_p(doc, "ผลงาน / ความรับผิดชอบในโครงงาน", size=16, bold=True)
        add_p(doc, "- [...]", size=14)

    advisors = [
        "ผู้ช่วยศาสตราจารย์ ดร.พรภวิษย์ บุญศรีเมือง — สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "ผู้ช่วยศาสตราจารย์ ดร.รวิ อุตตมธนินทร์ — สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "ผู้ช่วยศาสตราจารย์ ดร.ขวัญเรือน รัศมี — สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "อาจารย์ กานต์ เจริญจิตร — สาขาวิชาวิศวกรรมคอมพิวเตอร์",
        "ผู้ช่วยศาสตราจารย์ ดร.เศรษฐกาล โปร่งนุช — สาขาวิชาวิศวกรรมหุ่นยนต์",
        "อาจารย์ ทักษอร อักษรศิลป์ — สาขาวิชาวิศวกรรมหุ่นยนต์",
    ]
    page_break(doc)
    add_p(doc, "ภาคผนวก ง", size=18, bold=True, center=True)
    add_p(doc, "ประวัติอาจารย์ที่ปรึกษา", size=18, bold=True, center=True)
    add_p(doc, "รายชื่อตามหน้าปกเล่มทุน — กรอกประวัติทีละท่าน (ขอข้อมูลจากอาจารย์ก่อนลงเล่ม)", size=14)
    for i, name in enumerate(advisors, 1):
        if i > 1:
            page_break(doc)
            add_p(doc, "ภาคผนวก ง (ต่อ)", size=16, bold=True, center=True)
        add_p(doc, f"{i}. {name}", size=16, bold=True)
        add_table(
            doc,
            ["รายการ", "รายละเอียด"],
            [
                ["ชื่อ–นามสกุล", name.split(" — ")[0]],
                ["ตำแหน่งทางวิชาการ", "[ผศ.ดร. / อาจารย์]"],
                ["สังกัด", name.split(" — ")[1] + " คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม มหาวิทยาลัยราชภัฏสวนสุนันทา"],
                ["ประวัติการศึกษาล่าสุด", "[ปริญญา / สาขา / สถาบัน / ปี]"],
                ["ผลงาน / ความสนใจทางวิชาการ", "[3–5 บรรทัด]"],
                ["อีเมล (ถ้าอนุญาตให้ลง)", "[อีเมลหน่วยงาน]"],
            ],
        )

    page_break(doc)
    add_p(doc, "เช็คลิสต์ก่อนส่งทุน", size=18, bold=True, center=True)
    for item in [
        "□ รูป BMC เสร็จและแปะในภาค ก",
        "□ รายชื่อผู้เชี่ยวชาญ 5 ท่านยืนยันแล้ว",
        "□ มีใบ IOC หรือตารางสรุป IOC",
        "□ แนบแบบประเมินผู้ใช้ฉบับเต็ม",
        "□ ประวัติผู้จัดทำ 3 คนครบที่อยู่/เบอร์/อีเมล",
        "□ ประวัติอาจารย์ได้ข้อมูลจากอาจารย์แล้ว",
        "□ ไม่มีตัวเลขสมมติในบทคัดย่อที่ยังไม่มีหลักฐาน",
    ]:
        add_p(doc, item, size=16)

    doc.save(OUT)
    print(f"wrote {OUT}")


if __name__ == "__main__":
    main()
