# บทสรุปผู้บริหาร (Thai Executive Summary)

**งานวิจัย:** Closing the Cascade Gap — Soft-Triggered Hybrid Moderation for Thai C2C Escrow Chat  
**เอกสารฉบับเต็ม:** [`docs/paper_main.tex`](paper_main.tex)  
**หลักฐาน live (3-way):** [`dataset/benchmark_runs/summary_ablation_live.json`](../dataset/benchmark_runs/summary_ablation_live.json)

---

## 1. หัวข้อและ punch ใหม่

งานนี้ไม่ได้ขายแค่ว่า “cascade ประหยัดเงิน” แต่พิสูจน์ว่า:

> **Cascade แบบเดิมมีช่องโหว่ (cascade gap)** — Tier~1 ให้คะแนน 0 กับข้อความ bait แบบ “โอนแล้วแจ้งสลิป” / placeholder หลัง anonymize ทำให้ LLM ไม่ถูกเรียก  
> **Soft-trigger** บังคับ escalate เมื่อเจอ cue อ่อน → Recall พุ่ง และ FN ลดฮวบ โดยยังประหยัด API ~70%

---

## 2. Dataset

| รายการ | ค่า |
|--------|-----|
| ภาพแคปแชทฉ้อโกง | 100 |
| ข้อความหลัง OCR | **N=333** |
| ALLOW / BLOCK | 270 / 63 |

---

## 3. ผล Live Ablation (ตัวเลขจริง)

| ระบบ | Acc | Precision | Recall | F1 | FN | FP | Tier1 |
|------|-----|-----------|--------|-----|----|----|-------|
| Regex-only | 94.3% | 0.978 | 0.714 | 0.826 | 18 | 1 | — |
| Cascade (ไม่มี soft) | 90.7% | 0.758 | 0.746 | 0.752 | 16 | 15 | 77.5% |
| **Cascade+Soft (proposed)** | **93.4%** | 0.759 | **0.952** | **0.845** | **3** | 19 | **70.6%** |

**Effect size ที่ควรพูด:**
- Soft กู้ FN จาก cascade: **13 เคส** (7 จาก LLM จริง, 6 จาก Tier3 fail-safe)
- Recall: 0.746 → **0.952** (+20.6 pp)
- FN: 16 → **3** (−81%)
- ยังประหยัด API call **70.6%** เทียบ LLM ทุกข้อความ
- **ไม่เคลม** ว่า Acc ชนะ regex (94.3% vs 93.4%, McNemar p≈0.73)
- McNemar cascade→soft: p≈0.081 (ใกล้เคียง แต่ยังไม่ <0.05) — เน้น effect size

---

## 4. Soft cues ที่ใช้

- `soft_payment_confirm` — โอนแล้ว / แจ้งสลิป / โอนเสร็จ  
- `anonymized_pii_token` — `[PHONE_NUM]` `[BANK_ACCOUNT]` `[LINE_ID]`  
- `account_holder_name`, `promptpay_ocr_variant`, `soft_phone_contact`, `bank_label_variant`

---

## 5. ประโยคพูดกับอาจารย์ (30 วินาที)

> เราพบว่า cascade แบบเดิมเกือบไม่ช่วย recall เพราะข้อความ bait ได้คะแนน regex = 0 เลยไม่เรียก LLM  
> หลังใส่ soft-trigger บนชุดแชทโกงจริง N=333 recall ขึ้นจาก 0.75 เป็น 0.95 FN เหลือ 3 จาก 16  
> ทั้งที่ยังจบที่ Tier1 ได้ 70% — จุดขายคือ **เมื่อไหร่ควรเรียก LLM** ไม่ใช่แค่มี LLM

---

## 6. ไฟล์สำคัญ

- LaTeX: `docs/paper_main.tex`
- Ablation runner: `scripts/run_chat_benchmark_ablation.php --mode=live`
- Soft trigger: `CHAT_MODERATION_SOFT_TRIGGER=1` (default ON) ใน `ChatModerationService`
