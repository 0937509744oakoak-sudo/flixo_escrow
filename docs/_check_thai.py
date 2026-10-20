# -*- coding: utf-8 -*-
from docx import Document
from docx.oxml.ns import qn
from lxml import etree

path = r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx"
doc = Document(path)
out = r"C:\xampp\htdocs\flixo_escrow\docs\_ch3_text_check.txt"
with open(out, "w", encoding="utf-8") as f:
    # theme / default
    f.write("=== sample runs ===\n")
    n = 0
    for p in doc.paragraphs:
        t = p.text
        if not t.strip():
            continue
        # show codepoints for first body with sara am
        if "คำ" in t or "นำ" in t or "สำหรับ" in t or "ธุรกรรม" in t or "อัจฉริยะ" in t:
            f.write(f"PARA {p.style.name if p.style else ''}: {t[:120]}\n")
            snippet = t
            for ch in ["คำสั่ง", "คำขอ", "สำหรับ", "นำเสนอ", "ธุรกรรม", "อัจฉริยะ", "กระเป๋าเงิน", "กำหนด"]:
                if ch in t:
                    i = t.index(ch)
                    chunk = t[i:i+len(ch)]
                    f.write(f"  {ch} cps={[hex(ord(c)) for c in chunk]}\n")
            n += 1
            if n >= 8:
                break
    f.write("\n=== full text scan typos ===\n")
    full = "\n".join(p.text for p in doc.paragraphs)
    for bad in ["ธรุกรรม", "อจัฉริยะ", "ค า", "น า", "ส าหร", "กระเป๋ า", "G oogle", "Google"]:
        f.write(f"{bad!r} count={full.count(bad)}\n")
print("ok")
