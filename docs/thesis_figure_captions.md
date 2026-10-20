# รายการภาพสำหรับเล่มโครงงาน FLIXO

คำบรรยายใช้ใต้ภาพในเล่ม (กึ่งกลาง ตัวอักษร 14 พอยต์) อย่าใส่ชื่อภาพซ้ำในไฟล์ PNG

รวมที่แนะนำทั้งเล่ม **15 ภาพ** (บทที่ 1–3 ใส่แล้วใน Word · บทที่ 4 เลือกชุดด้านล่าง ไม่ใส่ซ้ำหน้าจอ eKYC/ล็อกอิน)

## บทที่ 1 (1 ภาพ) — ใส่แล้ว

| ภาพ | ไฟล์ | คำบรรยาย |
|-----|------|----------|
| 1.1 | `docs/thesis_figures/fig1_1_conceptual_framework.png` | ภาพที่ 1.1 กรอบแนวคิดการวิจัยของโครงงาน |

ตำแหน่ง: หลังวัตถุประสงค์ 1.2 ก่อนขอบเขต 1.3

## บทที่ 2 (3 ภาพ) — ใส่แล้ว

| ภาพ | ไฟล์ | คำบรรยาย |
|-----|------|----------|
| 2.1 | `docs/thesis_figures/fig2_1_escrow_process.png` | ภาพที่ 2.1 หลักการทำงานของระบบบัญชีตัวกลาง |
| 2.2 | `docs/thesis_figures/fig2_2_face_verification.png` | ภาพที่ 2.2 ลำดับการตรวจสอบใบหน้าแบบหนึ่งต่อหนึ่ง |
| 2.3 | `docs/thesis_figures/fig2_3_chat_cascade.png` | ภาพที่ 2.3 การคัดกรองข้อความแบบหลายชั้น |

ไม่ใส่หน้าจอระบบและไม่ใส่กราฟผลทดลองในบทนี้

## บทที่ 3 (ภาพหลัก + ไดอะแกรมเทคนิค)

แหล่ง Mermaid พร้อม export: `docs/Chapter3_Architecture_Diagrams.md`

| ภาพ | ไฟล์ | คำบรรยาย |
|-----|------|----------|
| 3.1 | `docs/thesis_figures/fig3_1_research_process.png` | ภาพที่ 3.1 แผนผังขั้นตอนการดำเนินงานวิจัย |
| 3.2 | `docs/thesis_figures/fig3_2_architecture.png` | ภาพที่ 3.2 สถาปัตยกรรมระบบสี่ชั้น (Browser / PHP / MySQL+Redis+Object Storage / External APIs) |
| 3.3 | `docs/thesis_figures/fig3_3_login_flow.png` | ภาพที่ 3.3 ขั้นตอนเข้าสู่ระบบ Password และ Google (สองคอลัมน์บรรจบ Session/KYC) |
| 3.3ก | `docs/appendix_ch4/screenshots/ui10_login.png` | ภาพที่ 3.3ก หน้าเข้าสู่ระบบของแพลตฟอร์ม |
| 3.3.2 | `docs/thesis_figures/fig3_3_3_er.png` | ภาพที่ 3.3.2 แผนภาพความสัมพันธ์เอนทิตีหลัก |
| 3.4 | `docs/thesis_figures/fig3_4_ekyc_sequence.png` | ภาพที่ 3.4 ขั้นตอนการยืนยันตัวตนด้วยบัตรและใบหน้า |
| 3.5 | `docs/appendix_ch4/screenshots/ui02_ekyc.png` | ภาพที่ 3.5 หน้ายืนยันตัวตนอิเล็กทรอนิกส์ |
| 3.6 | `docs/appendix_ch4/screenshots/ui03_sell.png` | ภาพที่ 3.6 หน้าลงประกาศขาย |
| 3.6บ | `docs/thesis_figures/fig3_6b_chat_sequence.png` | ภาพที่ 3.6บ แผนภาพลำดับการคัดกรองข้อความแบบหลายชั้น |
| 3.8 | `docs/thesis_figures/fig3_8_escrow_sequence.png` | ภาพที่ 3.8 ขั้นตอนการชำระเงิน กักเก็บ และปล่อยหรือคืนเงิน |

หมายเหตุ: ภาพที่ 3.3 ไม่มีช่องสแกนใบหน้า เพราะการยืนยันตัวตนทำหลังเข้าสู่ระบบ ภาพที่ 3.5 ไม่มีรูปบัตรหรือใบหน้าจริง ตารางที่ 3.1 (บริการภายนอก) อยู่ในบทที่ 3

สร้างภาพ 3.3: export จาก Mermaid ใน `Chapter3_Architecture_Diagrams.md` → mermaid.live → PNG

## บทที่ 4 (5 ภาพในฉบับประเมินผล) — ใส่แล้วใน `FLIXO_Chapter4_Thesis.docx`

เล่มส่งใช้ `docs/Chapter4_Empirical_Evaluation.md` เป็นบทที่ 4 หลัก ไม่ใช้รายงานทดลองเชิงวิศวกรรมเป็นตัวนำ

| ภาพ | ไฟล์ | คำบรรยาย | ตำแหน่ง |
|-----|------|----------|---------|
| 4.1 | `docs/_ch4_research_figures/fig01_accuracy_ci.png` | ภาพที่ 4.1 ความถูกต้องของโมดูลหลักพร้อมช่วงเชื่อมั่นร้อยละ 95 | หลังตารางข้ออ้างที่พิสูจน์ได้ |
| 4.2 | `docs/thesis_figures/fig4_ekyc_threshold.png` | ภาพที่ 4.2 คะแนนเทียบใบหน้าบนภาพจริงเทียบกับเกณฑ์ 80 | เหตุผลของ threshold |
| 4.3 | `docs/_ch4_research_figures/fig03_chat_confusion.png` | ภาพที่ 4.3 เมทริกซ์ความสับสนของการคัดกรองห้องสนทนา | การคัดกรองแชท |
| 4.4 | `docs/appendix_ch4/screenshots/ui06_product.png` | ภาพที่ 4.4 หน้ารายละเอียดสินค้าที่แสดงการกักเก็บเงิน | ผลการใช้งาน |
| 4.5 | `docs/appendix_ch4/screenshots/ui05_orders.png` | ภาพที่ 4.5 หน้ารายการสั่งซื้อและสถานะบัญชีตัวกลาง | ผลการใช้งาน |

กราฟ `fig12_mock_vs_live_overview.png` ใช้ในภาคผนวกได้ แต่ไม่ใช่ภาพที่ 4.2 ของเล่ม เพราะภาพที่ 4.2 ต้องตอบคำถามเกณฑ์ 80

### ภาพสำรองบทที่ 4 (ใส่เมื่อกรรมการขอเพิ่ม ไม่นับในชุดหลัก)

| ภาพ | ไฟล์ | คำบรรยาย |
|-----|------|----------|
| 4.6 | `docs/_ch4_research_figures/fig02_latency_mean_p95.png` | ภาพที่ 4.6 เวลาตอบสนองเฉลี่ยและเปอร์เซ็นไทล์ที่ 95 |
| 4.7 | `docs/_ch4_research_figures/fig08_resilience_uat.png` | ภาพที่ 4.7 ผลการทดสอบความต่อเนื่องของระบบและฟังก์ชันการใช้งาน |
| 4.8 | `docs/appendix_ch4/screenshots/ui07_admin.png` | ภาพที่ 4.8 แผงควบคุมผู้ดูแลระบบ |
| 4.9 | `docs/appendix_ch4/screenshots/ui08_admin_chat_ai.png` | ภาพที่ 4.9 คิวตรวจข้อความห้องสนทนาของผู้ดูแลระบบ |

ก่อนใส่ภาพที่ 4.9 ให้เบลอเบอร์โทร เลขบัญชี และชื่อจริงถ้ามี

กราฟชุดเต็ม `fig01`–`fig12` ยังอยู่ใน `docs/_ch4_research_figures/` และรายงานทดลองเดิมใช้คำว่าภาพที่ 1–12 หากย้ายเข้าเล่มให้เปลี่ยนเป็นภาพที่ 4.x ตามตารางนี้

## สร้างไฟล์ใหม่เมื่อแก้แผนภาพ

```text
python scripts/generate_thesis_figures.py
python scripts/generate_chapter1_3_docx.py
```

ไฟล์ Word ที่ได้อัปเดตแล้ว

- `docs/FLIXO_Chapter1_Thesis.docx`
- `docs/FLIXO_Chapter2_Thesis.docx`
- `docs/FLIXO_Chapter3_Thesis.docx`
- `docs/FLIXO_Chapter4_Thesis.docx`
- `docs/FLIXO_Chapter1_3_Thesis.docx`
- `docs/FLIXO_Chapter1_4_Thesis.docx`
