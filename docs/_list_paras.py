# -*- coding: utf-8 -*-
from docx import Document

path = r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx"
doc = Document(path)
out = r"C:\xampp\htdocs\flixo_escrow\docs\_ch3_paras.txt"
with open(out, "w", encoding="utf-8") as f:
    for i, p in enumerate(doc.paragraphs):
        t = p.text.strip()
        if t:
            f.write(f"{i:04d} {t[:140]}\n")
print("ok", len(doc.paragraphs))
