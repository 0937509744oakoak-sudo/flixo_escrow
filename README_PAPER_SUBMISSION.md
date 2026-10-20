# คู่มือแพ็กเกจงานวิจัย (Paper Submission Package)

คู่มือสั้นสำหรับรวบรวมไฟล์ที่เตรียมไว้เพื่อยื่นอาจารย์ / จัดทำสไลด์ / คอมไพล์เปเปอร์บน Overleaf

---

## 1) แผนที่ไฟล์สำคัญ

| หมวด | ไฟล์ | ใช้ทำอะไร |
|------|------|-----------|
| **Dataset** | `dataset/benchmark_dataset.json` | ชุดข้อความ OCR จริง N=333 (ALLOW 270 / BLOCK 63) |
| | `dataset/benchmark_dataset_meta.json` | สถิติรายภาพ + anonymization |
| | `dataset/benchmark_runs/summary_ablation_live.json` | **ผล 3-way live (ตัวเลขหลักของเปเปอร์)** |
| | `dataset/benchmark_runs/paper_tables_ablation_live.tex` | ตาราง ablation จากรันจริง |
| | `dataset/benchmark_runs/results_ablation_live.csv` | ผลรายข้อความ 3 ระบบ |
| **Manuscript** | `docs/paper_manuscript.md` | ร่างเปเปอร์ภาษาอังกฤษ (Markdown) |
| | `docs/paper_main.tex` | **LaTeX IEEEtran พร้อมวางใน Overleaf** |
| **สรุป/นำเสนอ** | `docs/paper_summary_th.md` | บทสรุปผู้บริหารภาษาไทย |
| | `docs/presentation_slides.md` | โครงสไลด์ 10–12 หน้า |
| **Figures** | `docs/figure_1_cases.md` | เคสตัวอย่าง + caption สำหรับ **Figure 1** |
| | `docs/architecture_diagram.md` | Mermaid สำหรับ **Figure 2** |
| **โค้ดทดลอง** | `scripts/run_chat_benchmark_ablation.php` | สคริปต์ 3-way ablation |
| | `scripts/run_chat_benchmark.php` | สคริปต์ 2-way เดิม (legacy) |
| | `scripts/extract_fraud_screenshot_dataset.php` | สคริปต์ OCR → dataset |
| | `includes/ChatModerationService.php` | cascade + soft trigger |

---

## 2) วิธีรัน `docs/paper_main.tex` บน Overleaf

1. เปิด [Overleaf](https://www.overleaf.com) → **New Project** → **Blank Project**  
2. อัปโหลดไฟล์ `docs/paper_main.tex` เข้าโปรเจกต์ (หรือวางเนื้อหาทั้งไฟล์ทับ `main.tex`)  
3. ที่เมนู **Menu → Compiler** เลือก **pdfLaTeX**  
4. กด **Recompile** — ควรได้ PDF ครบ abstract / sections / ตาราง 3 ตารางโดยไม่ต้องมีรูปก่อน  
5. (ถ้าต้องการ bibliography จริง) แทนที่รายการใน `thebibliography` ด้วย citation ที่ถูกต้อง หรือเพิ่ม `refs.bib` + `\bibliographystyle{IEEEtran}`

### ถ้าติด Error ที่พบบ่อย
| อาการ | วิธีแก้ |
|--------|---------|
| ไม่รู้จัก `booktabs` | Overleaf TeX Live มีให้อยู่แล้ว; ตรวจว่า `\usepackage{booktabs}` ยังอยู่ |
| รูปไม่ขึ้น | ยังเป็นกล่อง placeholder จงใจ — ดูขั้นตอน Figure ด้านล่าง |
| ฟอนต์ไทยพัง | ไฟล์หลักใช้ ASCII + placeholders เพื่อให้ pdfLaTeX เสถียร; ข้อความไทยอยู่ใน figure ที่เป็นภาพ |

---

## 3) แทรก Figure 1 และ Figure 2 ตรงไหนในเล่ม

### Figure 1 — ตัวอย่างแชท ALLOW / BLOCK
- **แหล่งเนื้อหา:** `docs/figure_1_cases.md`  
- **ตำแหน่งใน LaTeX:** `docs/paper_main.tex` → ส่วน **Dataset and Experimental Setup** → `\label{fig:examples}`  
- **วิธีทำ:**
  1. เลือกข้อความจากตารางใน `figure_1_cases.md` (panel a/b/c)  
  2. จัดเลย์เอาต์เป็นภาพ 3 ช่อง (หรือแคปภาพจาก UI ที่เบลอ PII แล้ว)  
  3. บันทึกเป็น `fig1_examples.pdf` หรือ `.png`  
  4. อัปโหลดขึ้น Overleaf  
  5. แทนที่กล่อง `\fbox{...Insert Figure 1...}` ด้วย:

```latex
\includegraphics[width=\columnwidth]{fig1_examples.pdf}
```

- **Caption พร้อมใช้:** มีใน `figure_1_cases.md` (English + Thai)

### Figure 2 — สถาปัตยกรรม 3-Tier Cascade
- **แหล่งแผนภาพ:** `docs/architecture_diagram.md` (Mermaid)  
- **ตำแหน่งใน LaTeX:** ส่วน **Proposed Cascaded Architecture** → `\label{fig:architecture}`  
- **วิธีทำ:**
  1. เปิด https://mermaid.live  
  2. วางโค้ด Mermaid ชุดแรกใน `architecture_diagram.md`  
  3. Export PNG/SVG → แปลงเป็น PDF ตามต้องการ ตั้งชื่อ `fig2_cascade.pdf`  
  4. อัปโหลดขึ้น Overleaf  
  5. แทนที่กล่อง placeholder ด้วย:

```latex
\includegraphics[width=\columnwidth]{fig2_cascade.pdf}
```

---

## 4) Checklist ก่อนส่งอาจารย์ / ยื่นเปเปอร์

- [ ] คอมไพล์ `paper_main.tex` บน Overleaf ผ่าน  
- [ ] ตัวเลขในตารางตรงกับ `summary_ablation_live.json` (Soft Rec **0.952**, FN **3**, Tier1 **70.6%**, FN rescued **13**)  
- [ ] Figure 1 จาก `figure_1_cases.md` แทรกแล้ว และไม่มี PII  
- [ ] Figure 2 จาก `architecture_diagram.md` แทรกแล้ว (ควรมี soft-trigger gate)  
- [ ] บทสรุปไทย `paper_summary_th.md` พร้อมใช้พรีเซนต์  
- [ ] สไลด์ตาม `presentation_slides.md`  
- [ ] **ไม่เคลม** Acc ชนะ regex — เน้น cascade gap + recall recovery + API save ~70%

---

## 5) รันทดลองซ้ำ (ถ้าต้องการ regenerate ตัวเลข)

```bash
# จากโฟลเดอร์โปรเจกต์ — 3-way ablation (ตัวเลขหลักของเปเปอร์)
C:\xampp\php\php.exe scripts/run_chat_benchmark_ablation.php --mode=live ^
  --dataset=dataset/benchmark_dataset.json ^
  --out-dir=dataset/benchmark_runs
```

จากนั้นคัดลอกตัวเลขจาก `dataset/benchmark_runs/summary_ablation_live.json` /
`paper_tables_ablation_live.tex` ไปเทียบกับตารางใน `docs/paper_main.tex`

---

## 6) ลำดับแนะนำเมื่อนำเสนอด้วยปากเปล่า

1. อ่าน `paper_summary_th.md` (2–3 นาที)  
2. เปิดสไลด์ตาม `presentation_slides.md`  
3. โชว์ Figure 1 (insight ~85% normal flow) + Figure 2 (architecture)  
4. ชี้ตาราง Efficiency เป็นสไลด์ขายงาน  
5. ตอบคำถามด้วยน้ำเสียงตรงไปตรงมาเรื่อง McNemar / FP / FN

---

*แพ็กเกจนี้ปิดจบสายเตรียมเปเปอร์ Cascaded Hybrid Moderation สำหรับ Thai C2C Escrow Chat*
