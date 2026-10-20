# Paper Core Lock — Chat Moderation

สถานะ: ฐานเปเปอร์แกนแชทครบแล้ว (RQ · Failure Analysis · Mock/Live · +20 eval · Limitations)

## Research Question

ระบบ hybrid (regex ก่อน + LLM เมื่อกำกวม) ตรวจจับข้อความชักชวนซื้อขายนอกแพลตฟอร์มภาษาไทยในแชท C2C ได้แม่นยำและ latency เท่าใด เมื่อเทียบกับ regex อย่างเดียว และข้อผิดพลาดที่เหลือมีลักษณะอย่างไร?

## Framing (ขายแบบนี้เท่านั้น)

- **แกนเปเปอร์:** Chat Moderation — Hybrid Regex + LLM ตรวจหลอกออกนอกแพลตฟอร์ม
- **ไม่ใช่แกน:** eKYC, Slip, Dispute AI (ใช้เป็น supporting / system context เท่านั้น)
- **จุดขาย:** Trust Stack สำหรับ C2C ไทย ที่ใช้ AI แบบผสมผสาน (cascaded) และแยก Mock/Live ตรงไปตรงมา
- **ห้ามเคลม:** “คิดค้น AI ใหม่” หรือ “ความแม่นยำ 100%” จากข้อมูลจำลอง

## ตาราง Mock vs Live (หน้าเปเปอร์)

**ไฟล์เต็ม:** [`docs/paper_chat_mock_vs_live.md`](paper_chat_mock_vs_live.md)

### ชุดหลัก N=100

| Metric | Mock | Live | Δ |
|--------|------|------|---|
| Accuracy | 97.0% | 97.0% | 0.0 pp |
| Precision / Recall / F1 | 0.9796 / 0.9600 / 0.9697 | เท่ากัน | 0 |
| TP/TN/FP/FN | 48/49/1/2 | เท่ากัน | — |
| avg / p95 latency | 399 / 1676 ms | 427 / 1908 ms | +28 / +232 |

### ชุดขยาย N=120 (รวม stress +20)

| Metric | Mock | Live | Δ |
|--------|------|------|---|
| Accuracy | 89.17% | 89.17% | 0.0 pp |
| Precision / Recall / F1 | 0.9273 / 0.8500 / 0.8870 | เท่ากัน | 0 |
| TP/TN/FP/FN | 51/56/4/9 | เท่ากัน | — |
| avg / p95 latency | 414 / 1653 ms | 448 / 2112 ms | +34 / +459 |
| MISS | 13 | 13 | identical |

### Stress slice +20 อย่างเดียว

Acc **50%** ทั้ง Mock และ Live (TP/TN/FP/FN = 3/7/3/7) — ตั้งใจเจาะเคสยากจาก failure analysis ไม่ใช่ชุดสุ่ม

**ข้อค้นพบจากรอบ eval นี้:** Mock กับ Live ให้ผล classifcation ตรงกันทุกชุดที่รัน → ปัญหาหลักคือ pipeline/keyword/gate ไม่ใช่ “mock โกหกตัวเลข”

Miss เดิมบน N=100: FN #43, FN #52, FP #84  
Miss ใหม่บน +20: FN 101–105, 108, 110 · FP 111, 114, 116

## Misses ที่ต้องวิเคราะห์ (3 เคส)

| No. | Group | GT | Pred | Type | Provider | ข้อความ |
|-----|-------|----|------|------|----------|---------|
| 43 | C2 | 1 (risky) | pass (0) | FN | none | `Line ID sell_cash โอนสดรับของเร็ว` |
| 52 | C2 | 1 (risky) | pass (0) | FN | none | `ไลน์: escrow_skip คุยโอนเงินนอก` |
| 84 | C1 | 0 (safe) | flag (1) | FP | groq | `ลดเหลือเท่านี้นะคับ แล้วกดซื้อผ่านแอป` |

## Failure Analysis (จบแล้ว)

แหล่งโค้ด: `includes/ChatModerationService.php`  
Pipeline: Tier-1 regex → escalate AI เฉพาะเมื่อ `risk_score ∈ [25, 80)` หรือ score=0 แต่มี digit run ≥8 ตัว → merge ด้วย `max(regex, ai)` (AI ลดคะแนน regex ไม่ได้)

### FN #43 — cascade gap (regex miss + ไม่ escalate)

- **ข้อความ:** `Line ID sell_cash โอนสดรับของเร็ว` | GT=risky | Pred=pass | provider=`none`
- **สิ่งที่ระบบทำ:** `matchLineId()` ใช้แพทเทิร์นประมาณ `line` + optional `[@:id]` + id ≥4 ตัวอักษร — รูปแบบเว้นวรรคแบบ `Line ID sell_cash` ไม่เข้าเงื่อนไข; keyword list มี `id line` แต่ไม่มี `line id`; คำว่า `โอนสด` ก็ไม่อยู่ใน off-platform keywords → **score = 0**
- **ทำไม AI ไม่ช่วย:** score 0 และไม่มีเลขยาว ≥8 ตัว → `shouldTriggerGemini()` = false → จบที่ Tier-1
- **ชนิดความผิดพลาด:** False Negative จากช่องว่างระหว่าง regex coverage กับการ escalate
- **แก้ได้ไหมโดยไม่ overfit:** ได้แบบระวัง — (1) เพิ่ม keyword/`line id`/`ไลน์ไอดี` รูปแบบที่พบบ่อย (2) หรือ escalate เมื่อเจอคำว่า Line/ไลน์ แม้ score ต่ำ (cheap heuristic) — **อย่า** hardcode ข้อความ `sell_cash`
- **บทเรียนเปเปอร์:** Hybrid ช่วยได้เฉพาะเคสที่ Tier-1 “สงสัยเล็กน้อย”; ถ้า Tier-1 มองว่าปลอดภัยสนิท เคสจะหลุดทั้ง pipeline

### FN #52 — ช่องว่างภาษาไทย + คำพ้องที่ไม่ตรง substring

- **ข้อความ:** `ไลน์: escrow_skip คุยโอนเงินนอก` | GT=risky | Pred=pass | provider=`none`
- **สิ่งที่ระบบทำ:** `matchLineId()` จับเฉพาะ `line` ภาษาอังกฤษ ไม่จับ `ไลน์:`; keyword มี `ติดต่อไลน์` / `แอดไลน์` / `ไลน์ไอดี` แต่ไม่มี `ไลน์` เดี่ยว; `โอนเงินนอก` **ไม่ใช่** substring ของ `โอนนอก`; `คุยโอนเงินนอก` ก็ไม่ตรง `คุยนอกแอพ` → **score = 0** อีกครั้ง
- **ทำไม AI ไม่ช่วย:** เงื่อนไขเดียวกับ #43 — ไม่ escalate
- **ชนิดความผิดพลาด:** False Negative จาก morphological/lexical gap (คำไทยใกล้เคียงแต่ไม่ตรง keyword) + ไม่มี soft trigger
- **แก้ได้ไหมโดยไม่ overfit:** (1) เพิ่ม `ไลน์:` / `line:` เป็น soft signal → ส่ง AI (2) ขยาย keyword แบบ phrase ที่สื่อ “โอน…นอก” ด้วย pattern ไม่ใช่ exact string — ทดสอบบน hold-out ก่อน เพื่อไม่ดัน FP
- **บทเรียนเปเปอร์:** Exact keyword matching อ่อนต่อภาษาพูดไทย; จุดแข็งของ LLM ควรอยู่ที่ชั้นนี้ แต่ระบบปัจจุบันยังไม่เรียก LLM

### FP #84 — keyword กว้างเกิน + merge แบบ max ไม่ยอมให้ AI ลดความเสี่ยง

- **ข้อความ:** `ลดเหลือเท่านี้นะคับ แล้วกดซื้อผ่านแอป` | GT=safe | Pred=flag | provider=`groq`
- **สิ่งที่ระบบทำ:** off-platform keywords รวม **`ผ่านแอป` / `ผ่านแอพ`** → match ได้ score 65 → verdict `flag`; ช่วงคะแนนทำให้เรียก Groq
- **สิ่งที่ AI ทำ:** Live result ระบุ AI score ≈ 0 (“พูดถึงการซื้อผ่านแอป”) = ตีความถูกว่าเป็น in-app
- **ทำไมยังผิด:** `mergeResults()` ใช้ `max(regex, ai)` → คะแนนสุดท้ายยังเป็น 65 จาก regex; AI **ลด FP ไม่ได้**
- **ชนิดความผิดพลาด:** False Positive จาก keyword ที่กำกวม (polysemy: “ผ่านแอป” = ในระบบ vs นอกระบบ) + นโยบายรวมผลแบบ conservative
- **แก้ได้ไหมโดยไม่ overfit:** (1) เอา `ผ่านแอป`/`ผ่านแอพ` ออกจากรายการ risky เดี่ยว หรือให้คะแนนต่ำมาก (2) เปลี่ยน merge: ถ้า AI มั่นใจว่า safe และมี negation/in-app cue ให้ลดคะแนนได้ภายในขอบเขต — ต้องวัด trade-off กับ FN
- **บทเรียนเปเปอร์:** Cascade “regex เข้ม + AI เสริม” ดีต่อความปลอดภัย แต่จะสร้าง FP คงที่ถ้า AI override ไม่ได้; ควรรายงานเป็น design trade-off ไม่ใช่ bug อย่างเดียว

### สรุปข้าม 3 เคส (ใช้ใน Discussion ได้)

| เคส | Error | ชั้นที่พัง | สาเหตุหลัก | ทิศทางแก้ที่ปลอดภัยต่อเปเปอร์ |
|-----|-------|-----------|------------|-------------------------------|
| #43 | FN | Tier-1 + gate | `Line ID …` ไม่เข้า regex/keyword | soft trigger เมื่อมี Line/ไลน์ |
| #52 | FN | Tier-1 + gate | `ไลน์:` + `โอนเงินนอก` ไม่ตรง exact keyword | soft trigger + Thai phrase patterns |
| #84 | FP | keyword + merge | `ผ่านแอป` กว้าง + `max()` | แคบ keyword / ให้ AI ลดคะแนนได้บางเงื่อนไข |

**ข้อค้นพบสำคัญสำหรับ RQ:** ข้อผิดพลาดที่เหลือไม่ได้มาจาก “LLM อ่อน” เป็นหลัก แต่มาจาก (ก) gate ที่ไม่เรียก LLM เมื่อ regex score = 0 และ (ข) นโยบาย merge ที่ไม่ยอมให้ LLM ลด false alarm ของ regex

**สิ่งที่ยังไม่ทำตอนนี้:** ไม่แก้โค้ดในรอบนี้ — วิเคราะห์อย่างเดียวเพื่อล็อกบท Discussion; การแก้เป็น micro-task ถัดไปหลังขยายข้อมูล +20

## ข้อจำกัดที่ยอมรับตั้งแต่ต้น

ดูฉบับเต็มสำหรับแปะในเปเปอร์: [`docs/paper_chat_limitations.md`](paper_chat_limitations.md)

- N แชทยังเล็ก (ชุดหลัก 100, รวมขยาย 120) — ห้ามเคลม general population; ไม่กระโดดไป 500 จนกว่าจะมีแผน sampling
- ชุด +20 เป็น stress set ที่เจาะ miss เดิม → Acc 50% ใช้โชว์ช่องโหว่ ไม่ใช่ตัวเลขหลักของเปเปอร์
- ช่องว่างหลักอยู่ที่ escalate/merge/keyword ไม่ใช่ความต่าง Mock–Live
- โมดูลอื่นยังมี mock/fixture มาก → ไม่ใช้เป็นหลักฐานหลักของเปเปอร์
- โดเมนเฉพาะ Thai C2C escrow chat — generalization นอกโดเมนยังไม่เคลม
- ไม่เคลมโมเดล AI ใหม่ และไม่เคลมความแม่นยำ 100%

## ชุดขยาย +20 (label แล้ว + รัน eval แล้ว)

ไฟล์:
- `docs/appendix_ch4/chat_moderation_dataset_plus20.csv` (No. 101–120 + Note)
- `docs/appendix_ch4/chat_moderation_dataset_120.csv` (= เดิม 100 + ใหม่ 20)
- ผล: `chat_120_mock_*`, `chat_120_real_*`, `chat_plus20_mock_*`, `chat_plus20_real_*`
- Runner: `tests/test_chat_eval_dataset.php`

องค์ประกอบ +20: **C2 risky = 10**, **C1 safe = 10**  
ผล stress slice: Acc 50% (Mock = Live) — ยืนยันช่องโหว่จาก Failure Analysis ซ้ำได้

## Micro-task checklist

- [x] ล็อก RQ + แกนเปเปอร์ (ไฟล์นี้)
- [x] Failure analysis 3 miss ให้จบ (สาเหตุ + แก้ได้ไหมโดยไม่ overfit)
- [x] เพิ่มข้อความแชท + label อีก 20 ข้อความ
- [x] ตาราง Mock vs Live 1 หน้า (`docs/paper_chat_mock_vs_live.md`)
- [x] รัน eval รอบเล็กบน +20 / N=120 แล้วอัปเดตตาราง
- [x] เขียน Limitations ½ หน้า (`docs/paper_chat_limitations.md`)

## Next (เมื่อพร้อมขยายรอบใหม่ — เลือกข้อเดียว)

- แก้ soft trigger / keyword ตาม failure analysis แล้วรัน eval ซ้ำ
- เขียน Related Work สั้น ๆ เฉพาะ Chat Moderation / off-platform detection
- คำนวณ cost บาทต่อข้อความจาก provider ที่ใช้จริง

ยังไม่เก็บ eKYC / ไม่รัน N=500 เป็นค่าเริ่มต้นของรอบถัดไป
