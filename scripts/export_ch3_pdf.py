# -*- coding: utf-8 -*-
from pathlib import Path

import fitz
import win32com.client

SRC = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.docx")
PDF = Path(r"c:\Users\Tanapat\Downloads\บทที่ 3 การออกแบบและพัฒนาระบบ_แก้ไข.pdf")
OUT = Path(r"C:\xampp\htdocs\flixo_escrow\docs\_ch3_preview")
OUT.mkdir(parents=True, exist_ok=True)


def export_pdf():
    word = win32com.client.Dispatch("Word.Application")
    word.Visible = False
    word.DisplayAlerts = 0
    try:
        doc = word.Documents.Open(str(SRC), ReadOnly=False)
        doc.EmbedTrueTypeFonts = True
        try:
            doc.SaveSubsetFonts = False
        except Exception:
            pass
        try:
            doc.DoNotEmbedSystemFonts = False
        except Exception:
            pass
        doc.Save()
        pages = doc.ComputeStatistics(2)
        doc.ExportAsFixedFormat(
            OutputFileName=str(PDF),
            ExportFormat=17,
            OpenAfterExport=False,
            OptimizeFor=0,
            CreateBookmarks=1,
            DocStructureTags=True,
            BitmapMissingFonts=True,
            UseISO19005_1=False,
        )
        doc.Close(False)
        print("word_pages", pages)
        print("pdf_bytes", PDF.stat().st_size)
    finally:
        word.Quit()


def preview():
    pdf = fitz.open(PDF)
    print("pdf_pages", pdf.page_count)
    hits = {"3.6": [], "3.7": [], "3.11": [], "sara": []}
    for i, page in enumerate(pdf, start=1):
        t = page.get_text()
        if "ภาพที่ 3.6" in t or "3.6" in t and "ยืนยันตัวตน" in t:
            hits["3.6"].append(i)
        if "3.7 ระบบข้อพิพาท" in t:
            hits["3.7"].append(i)
        if "ภาพที่ 3.11" in t:
            hits["3.11"].append(i)
        if "นำเสนอ" in t or "สำหรับ" in t or "คำสั่ง" in t or "ชั้นนำเสนอ" in t:
            hits["sara"].append(i)
        broken = []
        if "น าเสนอ" in t or "ค าสั่ง" in t or "ส าหรับ" in t or "ค าขอ" in t:
            broken.append("space_am")
        if "ธรุกรรม" in t:
            broken.append("thurakam")
        if "อจัฉริยะ" in t:
            broken.append("ajchariya")
        if broken:
            print("page", i, "broken", broken)
    print("hits", hits)
    render = set()
    for key in hits:
        render.update(hits[key][:2])
    if hits["3.7"] and hits["3.11"]:
        print("fig311_with_37", hits["3.7"][0] == hits["3.11"][0], "p7", hits["3.7"], "p11", hits["3.11"])
    for i in sorted(render):
        pix = pdf[i - 1].get_pixmap(matrix=fitz.Matrix(1.6, 1.6), alpha=False)
        out = OUT / f"p{i:02d}.png"
        pix.save(str(out))
        print("wrote", out.name)
    pdf.close()


if __name__ == "__main__":
    export_pdf()
    preview()
