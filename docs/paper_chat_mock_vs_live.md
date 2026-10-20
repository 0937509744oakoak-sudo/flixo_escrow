# Table — Chat Moderation: Mock vs Live

**กฎการรายงาน:** แยก N=100 (ชุดหลัก) กับ N=120 / +20 (ชุดขยายที่เจาะเคสยาก) · **ห้ามปนกับ** eKYC / Slip / Dispute · **ห้ามเคลม 100%**

แหล่ง:
- N=100 Mock: `chat_eval_stats.json` / `chat_results.csv`
- N=100 Live: `chat_real_summary.json` / `chat_real_results.csv`
- N=120 / +20: `chat_120_*` , `chat_plus20_*` (ทั้ง `tests/` และ `docs/appendix_ch4/`)
- Runner: `tests/test_chat_eval_dataset.php`

---

## Table 1. Primary set N=100 (balanced C1/C2 = 50/50)

| Metric | Mock | Live | Δ (Live − Mock) |
|--------|------|------|-----------------|
| N | 100 | 100 | 0 |
| Accuracy | 97.0% | 97.0% | 0.0 pp |
| Precision | 0.9796 | 0.9796 | 0 |
| Recall | 0.9600 | 0.9600 | 0 |
| F1 | 0.9697 | 0.9697 | 0 |
| TP / TN / FP / FN | 48 / 49 / 1 / 2 | 48 / 49 / 1 / 2 | identical |
| Latency avg (ms) | 399 | 427 | +28 |
| Latency p95 (ms) | 1676 | 1908 | +232 |
| Providers none / groq / openrouter | 66 / 34 / 0 | 66 / 27 / 7 | Live fallback OR |
| `mock_api_mode` | true | false | — |

Miss IDs (เหมือนกัน): **FN #43, FN #52, FP #84**

---

## Table 2. Extended set N=120 (= N=100 + stress +20)

ชุด +20 ถูกออกแบบให้ใกล้เคส miss เดิม (Line ID spacing, `ไลน์:`, `โอนเงินนอก`, “ผ่านแอป” in-app) — **ไม่ใช่สุ่มเพิ่ม** จึงคาดว่า Acc จะลด และใช้เป็น stress / failure probe

| Metric | Mock | Live | Δ |
|--------|------|------|---|
| N | 120 | 120 | 0 |
| Accuracy | 89.17% | 89.17% | 0.0 pp |
| Precision | 0.9273 | 0.9273 | 0 |
| Recall | 0.8500 | 0.8500 | 0 |
| F1 | 0.8870 | 0.8870 | 0 |
| TP / TN / FP / FN | 51 / 56 / 4 / 9 | 51 / 56 / 4 / 9 | identical |
| Latency avg (ms) | 414 | 448 | +34 |
| Latency p95 (ms) | 1653 | 2112 | +459 |
| Providers none / groq / openrouter | 81 / 39 / 0 | 81 / 33 / 6 | — |
| MISS count | 13 | 13 | identical |

### Table 2b. Stress slice เท่านั้น (+20, No. 101–120)

| Metric | Mock | Live |
|--------|------|------|
| N | 20 | 20 |
| Accuracy | 50.0% | 50.0% |
| Precision / Recall / F1 | 0.50 / 0.30 / 0.375 | 0.50 / 0.30 / 0.375 |
| TP / TN / FP / FN | 3 / 7 / 3 / 7 | 3 / 7 / 3 / 7 |
| avg / p95 (ms) | 256 / 1073 | 201 / 834 |
| Providers none / groq | 15 / 5 | 15 / 5 |

Miss บน +20 (10 เคส): FN 101–105, 108, 110 · FP 111, 114, 116

---

## Table 3. Confusion matrix

### N=100 (ทั้ง Mock และ Live)

|  | Pred Risky | Pred Safe |
|--|------------|-----------|
| **GT Risky (50)** | TP 48 | FN 2 |
| **GT Safe (50)** | FP 1 | TN 49 |

### N=120 (ทั้ง Mock และ Live)

|  | Pred Risky | Pred Safe |
|--|------------|-----------|
| **GT Risky (60)** | TP 51 | FN 9 |
| **GT Safe (60)** | FP 4 | TN 56 |

---

## ประโยคสรุปสำหรับ Results (ก็อปได้)

บนชุดหลักภาษาไทย N=100 ระบบ hybrid regex→LLM ได้ความแม่นยำ 97% ทั้งโหมด Mock และ Live (Precision 0.98, Recall 0.96, F1 0.97) โดยมีข้อผิดพลาด 3 เคสเหมือนกัน เมื่อขยายด้วยชุด stress +20 ที่เจาะช่องโหว่จาก failure analysis (รวม N=120) ความแม่นยำลดเหลือ 89.17% ทั้ง Mock และ Live โดย classification metrics ยังตรงกันทุกประการ สะท้อนว่า (1) ช่องว่างหลักอยู่ที่ cascade gate/keyword ไม่ใช่ความต่าง Mock–Live และ (2) การรายงาน Acc ต้องระบุชัดว่าเป็นชุดหลักหรือชุด stress — ห้ามเคลมตัวเลขสูงโดยซ่อนชุดที่ยาก

---

## สิ่งที่ยังไม่รายงาน / ขั้นถัดไป

| รายการ | สถานะ |
|--------|--------|
| Regex-only baseline แยกคอลัมน์ | ยังไม่รันเป็นตารางแยก |
| Cost บาท/ข้อความ | ยังไม่คำนวณ |
| แก้โค้ดตาม failure analysis | ยังไม่ทำ (ตั้งใจวิเคราะห์ก่อน) |
| โมดูล eKYC / Slip / Dispute | **นอกตารางนี้** |
