# บทที่ 4  
# ผลการทดลองและการใช้งานจริง  
## โครงการ FLIXO Escrow  
### ฉบับสมบูรณ์ — อธิบาย AI/API ละเอียดครบทุกตัว + ผลการทดลอง N=100 ทุกโมดูล AI

| รายการ | รายละเอียด |
|--------|------------|
| ระบบ | FLIXO Escrow (PHP / XAMPP) |
| โหมดทดลองเชิงปริมาณ | `MOCK_API_MODE=true` |
| วันที่จัดทำ | 30 กรกฎาคม 2026 |
| ไฟล์ผลดิบ | `docs/appendix_ch4/*.csv` |
| ส่วนเสริมงานวิจัย | `docs/Chapter4_Research_Enhancement.md` + `_ch4_research_figures/` |

---

## 4.1 วัตถุประสงค์

1. อธิบาย **AI และ External API ทุกตัว** ที่ปรากฏใน Source Code อย่างละเอียด (สถาปัตยกรรม, Input/Output, Threshold, Fallback)  
2. ออกแบบและรันการทดลอง **N=100** สำหรับทุกโมดูล AI หลัก  
3. รายงาน Confusion Matrix / Agreement / Latency (Avg, P95)  
4. อภิปรายผลภายใต้บริบท Mock Environment และแนวทางทดลองบน API จริงต่อไป  
5. เสริมความเป็นงานวิจัยด้วยสถิติ (Wilson 95% CI) กราฟ และการเปรียบเทียบแนวทาง (ดู §4.15–4.21 ใน `Chapter4_Research_Enhancement.md`)  

---

## 4.2 สภาพแวดล้อมและระเบียบวิธี

| รายการ | ค่า |
|--------|-----|
| APP_ENV | local |
| MOCK_API_MODE | true |
| สคริปต์รวม | `tests/test_all_ai_modules.php` |
| สคริปต์ย่อย | `test_ekyc_100_runs.php`, `test_chat_100_runs.php`, `test_image_fraud_100_runs.php`, `test_dispute_ai_100_runs.php`, `test_sumsub_100_runs.php` |

**นิยามตัวชี้วัด:** Accuracy, Precision, Recall, F1, FPR, FRR, Agreement Rate, Avg/P95 Latency

> **คำเตือนเชิงวิชาการ:** ผล Accuracy/Agreement ในโหมด Mock ใช้ยืนยัน Logic Flow และการจัดการสถานะเป็นหลัก ไม่ใช่การรับรองความแม่นยำของโมเดลคลาวด์บนข้อมูลโลกจริง

---

## 4.0 สารบัญโมดูล AI / API ทั้งระบบ

| # | โมดูล | ประเภท | มีทดลอง N=100 | ไฟล์ผล |
|---|--------|--------|---------------|--------|
| 1 | AiGen OCR + Face Compare | AI eKYC | ✅ | `ekyc_results.csv` |
| 2 | Sumsub ID + Liveness | AI eKYC | ✅ | `sumsub_results.csv` |
| 3 | Manual eKYC Fallback | Human-in-the-loop | อธิบาย + Resilience | — |
| 4 | EXIF + Google Cloud Vision | AI Image Fraud | ✅ | `image_fraud_results.csv` |
| 5 | Chat Regex + Groq/OpenRouter/Gemini | AI Chat Safety | ✅ | `chat_results.csv` |
| 6 | Vision OCR ในแชท | AI Vision | รวมในสเปก Chat/Image | — |
| 7 | Dispute AI (Groq→OR→Gemini→Heuristic) | AI Advisory | ✅ | `dispute_ai_results.csv` |
| 8 | Google Sign-In | Auth API | สเปกละเอียด | — |
| 9 | Omise / EasySlip | Payment API | สเปกละเอียด | — |
| 10 | SMTP / WebPush / S3 / Redis | Infra | สเปก + Fallback | — |

---

# ส่วน ก — คำอธิบายละเอียดของ AI / API แต่ละตัว

## ก.1 Google Sign-In (Authentication — ไม่ใช่ Face Login)

### ก.1.1 บทบาทในระบบ
Google Sign-In เป็นช่องทาง **Authentication** เพื่อยืนยันว่าผู้ใช้ถือบัญชี Google ที่ยืนยันอีเมลแล้ว สามารถเข้าสู่ระบบ FLIXO ได้ โดย **ไม่ได้ใช้ใบหน้า** และแยกชั้นจาก eKYC อย่างชัดเจน

### ก.1.2 สถาปัตยกรรม
1. เบราว์เซอร์โหลด GIS (`accounts.google.com/gsi/client`)  
2. ได้ Google ID Token (JWT)  
3. `AuthController` ส่ง Token ไป `GoogleIdTokenVerifier`  
4. เซิร์ฟเวอร์ดึง public certificates จาก `https://www.googleapis.com/oauth2/v1/certs`  
5. ตรวจลายเซ็น RS256, `aud`, `iss`, `email_verified`, เวลา `iat`  
6. สร้างหรือผูกบัญชี แล้วเปิด PHP Session  

### ก.1.3 พารามิเตอร์สำคัญ
| รายการ | ค่า |
|--------|-----|
| Config | `GOOGLE_CLIENT_ID` |
| Cert cache | 3600 วินาที |
| iat skew | ≤ +300 วินาที |
| Fallback | Password Login / Forgot Password |

### ก.1.4 ความสัมพันธ์กับ AI อื่น
ผ่าน Authentication แล้ว ผู้ใช้จึงเข้าสู่ eKYC (AiGen/Sumsub) ได้ — เป็นลำดับ Defense in Depth

---

## ก.2 AiGen Thai ID OCR (`/aiscript/idcard/v3`)

### ก.2.1 บทบาท
อ่านข้อความบนบัตรประชาชนไทยด้วย OCR สกัดชื่อและเลขบัตร และดึงข้อมูลใบหน้าจากเอกสาร (`return_face=true`)

### ก.2.2 จุดที่ปรับแต่งในโปรเจกต์
โปรเจกต์ **ไม่ได้เทรนโมเดล OCR เอง** แต่ Integrate API สำเร็จรูป และกำหนดพารามิเตอร์ระดับระบบ: API Key, Timeout 90s, การ validate ฟิลด์, การ mask เลขบัตรใน Log

### ก.2.3 Input / Output
- Input: ภาพบัตร base64  
- Output ที่ใช้จริง: `status`, `data[0].id_number`, `name_th`, `surname_th`  
- เมื่อล้มเหลว: `rejected` หรือถ้า Exception ทั้ง pipeline → Manual pending  

---

## ก.3 AiGen Face Compare (`/aiface/face-compare/v3`)

### ก.3.1 บทบาท
เปรียบเทียบใบหน้าบนบัตรกับ Selfie จากกล้องหน้า เพื่อยืนยันว่าบุคคลถือบัตรตรงกับเจ้าของบัญชี

### ก.3.2 พารามิเตอร์วิศวกรรม
| รายการ | ค่าในโค้ด |
|--------|-----------|
| Threshold | **80** (`AIGEN_FACE_THRESHOLD`) |
| Selfie | 640×480 JPEG q=0.9 |
| Flags | `image1_is_document=true`, `verified_document=true` |
| ผลธุรกิจ | match→`approved`; ไม่ match→`rejected` |
| Fallback | `markAigenPending()` → Admin Manual Review |

### ก.3.3 ผลการทดลอง N=100 (ดูรายละเอียด §4.3)
Accuracy **100%**, Avg E2E **2,429 ms**, P95 **3,407 ms** (Mock)

### ก.3.4 ผลบนภาพจริง (2 คน / 4 คู่, AiGen Live)
TP=2 TN=2 FP=0 FN=0 → Acc **100%**, Avg E2E **4,657 ms**  
Genuine คะแนนใบหน้า ≈98–100; Impostor คะแนน 0 (reject)  
CSV: `appendix_ch4/ekyc_real_results.csv` (N เล็ก — สาธิตบนข้อมูลจริง)

---

## ก.4 Sumsub (`id-and-liveness`)

### ก.4.1 บทบาท
ทางเลือก eKYC แบบ Hosted ที่รวม **Active Liveness** (โต้ตอบจริง ไม่ใช่ภาพนิ่งอย่างเดียว) เหมาะเมื่อต้องการความแข็งแรงด้าน anti-spoof สูงกว่า AiGen path

### ก.4.2 Flow
1. ขอ Access Token SDK (TTL 1200s)  
2. สร้าง Applicant (`flixo_{userId}`)  
3. ผู้ใช้ทำ ID + Liveness ใน WebSDK  
4. Webhook `applicantReviewed` หรือปุ่ม `sync_status`  
5. GREEN→approved / RED→rejected  

### ก.4.3 ผลการทดลอง N=100 (Mock Pipeline)
Accuracy **100%**, Avg E2E **3,063 ms**, P95 **4,048 ms**  
(วัด latency ของ Token + Applicant + Review ภายใต้ Mock)

---

## ก.5 Manual eKYC (Fallback)

เมื่อไม่มี API key, Provider ถูกบังคับเป็น manual หรือ AiGen โยน Exception ระบบเก็บเอกสารเข้ารหัสแล้วตั้ง `pending` ให้แอดมินตรวจ — นี่คือหัวใจของ **Graceful Degradation**

---

## ก.6 Product Image Fraud — EXIF + Google Cloud Vision

### ก.6.1 ปัญหาที่แก้
ผู้ขายอาจนำ Screenshot จากแชทหรือรูปจากเว็บตลาดอื่นมาลงขาย ทำให้เกิดการหลอกลวง

### ก.6.2 สถาปัตยกรรมสองชั้น
1. **Tier 1 EXIF (ฟรี):** ประเมินความน่าเชื่อถือจาก Make/Model/DateTime/GPS; Trust Threshold=65; ไฟล์เล็กกว่า 80KB ลดคะแนน  
2. **Tier 2 Vision (เสียเมื่อจำเป็น):** เรียกเฉพาะภาพน่าสงสัย ด้วย SAFE_SEARCH / WEB_DETECTION / LABEL / TEXT  
3. Block Threshold=75, Flag Threshold=50  

### ก.6.3 Fallback
ไม่มี Vision key / Vision error → flag ด้วย risk ≈ 55 ไม่ hard-reject ทันที

### ก.6.4 ผลการทดลอง N=100
Clean 50 / Risky 50 → Accuracy **100%**, Avg **1,129 ms**, P95 **2,137 ms**

---

## ก.7 Chat Moderation — Regex + Groq / OpenRouter / Gemini + Vision OCR

### ก.7.1 ปัญหาที่แก้
ผู้ซื้อ–ผู้ขายอาจชวนโอนนอกระบบ ส่งไลน์ หรือเลขบัญชี ทำให้เลี่ยงเงินพักกลาง

### ก.7.2 สถาปัตยกรรม
1. Tier-1 Regex ให้คะแนนเบอร์โทร/บัญชี/คำนอกแพลตฟอร์ม  
2. ถ้าย่านคะแนน 25–80 → เรียก LLM ตามลำดับ **Groq → OpenRouter → Gemini**  
3. ≥80 อาจตัดสินด้วย Regex อย่างเดียว; ภาพใช้ Vision OCR ก่อนสแกนข้อความ  
4. Block=70 / Flag=45  

### ก.7.3 โมเดล LLM ที่เกี่ยวข้อง
| Provider | Model default | บทบาท |
|----------|---------------|--------|
| Groq | `llama-3.3-70b-versatile` | ลำดับแรก |
| OpenRouter | `google/gemini-2.5-flash` | สำรอง |
| Gemini | `gemini-2.0-flash` | สำรอง |

### ก.7.4 ผลการทดลอง N=100 (ชุดข้อความไทย 100 ข้อความ)
**Mock LLM:** Accuracy **97%** (TP=48, TN=49, FP=1, FN=2), Avg **399 ms**, P95 **1,676 ms** (`none` 66 / `groq` 34)  
**Live LLM:** Accuracy **97%** (confusion เดียวกัน), Avg **427 ms**, P95 **1,908 ms** (`none` 66 / `groq` 27 / `openrouter` 7)  
MISS คู่เดียว: No.43, 52 (FN สแลง), No.84 (FP “ผ่านแอป”)  
แหล่ง: `chat_results.csv` / `chat_real_results.csv`

---

## ก.8 Dispute AI Assistant

### ก.8.1 บทบาท
ช่วยแอดมินวิเคราะห์ข้อพิพาทจากแชท พัสดุ และหลักฐาน แล้ว **แนะนำ** การกระทำ ไม่ตัดสินอัตโนมัติแทนมนุษย์

### ก.8.2 ลำดับ Provider และ Fallback
Groq → OpenRouter → Gemini → **Heuristic** (กฎจาก tracking/เหตุผล/หลักฐาน)

### ก.8.3 Output
`REFUND_BUYER` | `PAYOUT_SELLER` | `SPLIT` | `NEEDS_REVIEW` พร้อม % ความผิดและ confidence

### ก.8.4 ผลการทดลอง N=100
Gold ละ 25 เคสต่อ action → Agreement **100%**, Avg **1,333 ms**, P95 **1,971 ms**

---

## ก.9 Omise และ EasySlip (Payment APIs — ไม่ใช่ AI แต่เกี่ยวพันระบบ)

**Omise:** Card/PromptPay ผ่าน `api.omise.co` — Fallback เป็นสลิป/แอดมิน  
**EasySlip:** ตรวจสลิป + สร้าง QR PromptPay — Fallback `WAITING_VERIFY`  
**qrserver.com:** เรนเดอร์ภาพ QR  

### ก.9.1 ผลการทดลอง N=100
| โมดูล | โหมด | Pass/Acc | Avg / P95 | หมายเหตุ |
|-------|------|----------|-----------|----------|
| EasySlip | Fixture decision (กฎเดียวกับ OrderController) | **100%** | 299 / 439 ms | ไม่ยิง live upload |
| Omise | Sandbox live (O1–O5) | **100% Pass** | 1033 / 2438 ms | PromptPay QR อาจ null ใน sandbox |
| Google Auth | Certs live + JWT negative | **100%** (FAR=0) | 94 / 465 ms | ยังไม่มี valid GIS token แบบ interactive |

---

## ก.10 Infrastructure

Email SMTP, Web Push VAPID, S3/R2, Redis — แต่ละตัวมี Fallback (ปิด mail ได้ / local disk / DB polling) เพื่อให้ระบบหลักไม่ล่ม

---

## ก.11 ตาราง Fallback รวม

| โมดูล | เมื่อล้มเหลว | สำรอง |
|-------|--------------|--------|
| Google Auth | Token ไม่ผ่าน | Password |
| AiGen | Exception | Manual KYC |
| Sumsub | Webhook ไม่ถึง | sync_status |
| Vision Image | ไม่มี key/error | EXIF flag |
| Chat LLM | ทุกตัวล้ม | Regex-only |
| Dispute LLM | ทุกตัวล้ม | Heuristic |
| Omise/EasySlip | error | Admin review |
| S3/Redis | ไม่พร้อม | Local / DB poll |

---

# ส่วน ข — ผลการทดลองเชิงปริมาณ N=100

## 4.3 eKYC — AiGen OCR + Face Compare

### Confusion Matrix
|  | Pred Match | Pred Non-match |
|--|------------|----------------|
| Actual Genuine | TP=50 | FN=0 |
| Actual Impostor | FP=0 | TN=50 |

### Metrics
| Metric | ค่า |
|--------|-----|
| Accuracy / Precision / Recall / F1 | 100% / 100% / 100% / 1.0 |
| FPR / FRR | 0% / 0% |
| Avg / P95 E2E | **2429 / 3407 ms** |
| แหล่งข้อมูล | `appendix_ch4/ekyc_results.csv` |

### อภิปรายย่อย
Pipeline สองขั้น (OCR แล้ว Face) ทำให้ Latency สูงกว่า Chat โดยธรรมชาติ ค่าเฉลี่ย ~2.4 วินาทียังเหมาะกับงานยืนยันตัวตนที่ไม่เรียกทุกวินาที

---

## 4.3b Sumsub — Token + Applicant + Review Pipeline

| Metric | ค่า |
|--------|-----|
| N | 100 (GREEN 50 / RED 50) |
| Accuracy (GT-driven mock) | **100%** |
| Avg / P95 E2E | **3063 / 4048 ms** |
| แหล่งข้อมูล | `appendix_ch4/sumsub_results.csv` |

### อภิปรายย่อย
Sumsub ใช้เวลาสูงกว่า AiGen เล็กน้อยเพราะจำลอง 3 จุดเรียก API ต่อรอบ สอดคล้องกับการใช้งานจริงที่มี SDK token + applicant + review

---

## 4.4 Product Image Fraud — EXIF + Cloud Vision

### Confusion Matrix
|  | Pred Risky | Pred Safe |
|--|------------|-----------|
| Actual Risky | TP=50 | FN=0 |
| Actual Safe | FP=0 | TN=50 |

| Metric | ค่า |
|--------|-----|
| Accuracy / Prec / Rec / F1 | 100% / 100% / 100% / 1.0 |
| Avg / P95 Latency | **1129 / 2137 ms** |
| แหล่งข้อมูล | `appendix_ch4/image_fraud_results.csv` |

### อภิปรายย่อย
เคส Clean จบที่ Tier-1 ได้เร็วกว่า เคส Risky ต้องเข้า Vision จึง Latency สูงกว่า — ยืนยันว่าสถาปัตยกรรมแบบประหยัดต้นทุนทำงานตามออกแบบ

---

## 4.5 Chat Moderation

### Confusion Matrix (ชุดข้อความไทย N=100)
|  | Pred Risky | Pred Safe |
|--|------------|-----------|
| Actual Risky | TP=48 | FN=2 |
| Actual Safe | FP=1 | TN=49 |

| Metric | ค่า |
|--------|-----|
| Accuracy / Prec / Rec / F1 | **97% / ≈98% / 96% / ≈0.97** |
| Avg / P95 | **399 / 1676 ms** |
| none / groq | **66 / 34** |
| แหล่งข้อมูล | `appendix_ch4/chat_results.csv` + `chat_moderation_dataset_100.csv` |

### Error Analysis (MISS 3 เคส)
| No. | Group | GT | ผลระบบ | Provider | ข้อความ | สาเหตุโดยย่อ |
|----:|-------|----|--------|----------|---------|--------------|
| 43 | C2 | Risky | pass (FN) | none | Line ID sell_cash โอนสดรับของเร็ว | คำสแลง/บัญชีนอกแพทเทิร์น — Regex คะแนน 0 ไม่เข้า LLM |
| 52 | C2 | Risky | pass (FN) | none | ไลน์: escrow_skip คุยโอนเงินนอก | รูปแบบ “ไลน์:” + handle แปลก — Regex พลาด |
| 84 | C1 | Safe | flag (FP) | groq | ลดเหลือเท่านี้นะคับ แล้วกดซื้อผ่านแอป | ข้อความกำกวม (“โอน/ซื้อ”) — Mock LLM ให้คะแนน 68 |

### อภิปรายย่อย
ค่าเฉลี่ยต่ำเพราะ Regex ตัดจบได้ 66% ของเคส แสดงประสิทธิภาพของ Tiered Architecture  
เคสพลาดส่วนใหญ่เป็น **FN จาก Regex ที่ไม่ครอบคลุมสแลง** และ **FP จากข้อความ Safe ที่กำกวมเมื่อเข้า LLM** — ใช้เป็นข้อมูลปรับพจนานุกรม Regex และ Threshold ต่อไป

---

## 4.6 Dispute AI Assistant

| Metric | ค่า |
|--------|-----|
| N | 100 (4 actions × 25) |
| Exact Agreement | **100%** |
| Avg / P95 Latency | **1333 / 1971 ms** |
| Provider ใน Mock | groq |
| แหล่งข้อมูล | `appendix_ch4/dispute_ai_results.csv` |

### อภิปรายย่อย
Agreement สูงใน Mock เพราะบังคับ Ground Truth ผ่าน scenario เพื่อทดสอบว่าชั้น normalize/action mapping ทำงานครบ บน API จริงควรเปรียบกับคำตัดสินแอดมิน (Gold Label) แยกต่างหาก

---

## 4.11 ตารางสรุปรวมทุกโมดูล AI ที่ทดลองแล้ว

| โมดูล | N | Accuracy / Agreement | Avg (ms) | P95 (ms) | ไฟล์ CSV |
|-------|---|----------------------|----------|----------|----------|
| AiGen OCR+Face | 100 | 100% | 2429 | 3407 | ekyc_results.csv |
| AiGen (ภาพจริง 2 คน) | **4** | **100%** | **4657** | **5050** | ekyc_real_results.csv |
| Sumsub Pipeline | 100 | 100% | 3063 | 4048 | sumsub_results.csv |
| Image Fraud (EXIF+Vision) | 100 | 100% | 1129 | 2137 | image_fraud_results.csv |
| Chat Moderation | 100 | **97%** | 399 | 1676 | chat_results.csv |
| Chat (Live LLM) | 100 | **97%** | 427 | 1908 | chat_real_results.csv |
| Dispute AI | 100 | 100% Agreement | 1333 | 1971 | dispute_ai_results.csv |
| EasySlip (decision) | 100 | **100%** | 299 | 439 | easyslip_results.csv |
| Omise Sandbox | 100 | **100% Pass** | 1033 | 2438 | omise_results.csv |
| Google Auth Verify | 100 | **100%** | 94 | 465 | google_auth_results.csv |
| Resilience Fallback | 10 | **100%** | — | — | resilience_results.csv |
| UAT Functional | 12 | **100%** | — | — | uat_results.csv |

---

## 4.13 อภิปรายผลรวม (Discussion แบบละเอียด)

### 4.13.1 ระบบ AI หลายชั้น ไม่ใช่โมเดลเดียว
FLIXO ออกแบบให้ AI แยกตามปัญหา: ยืนยันตัวตน (AiGen/Sumsub), กันรูปปลอม (Vision), กันแชทนอกระบบ (Regex+LLM), ช่วยตัดสินข้อพิพาท (Dispute AI) การแยกชั้นนี้ทำให้ปรับ Threshold และ Fallback ทีละโมดูลได้ โดยไม่กระทบทั้งแพลตฟอร์ม

### 4.13.2 ความหมายของผลใน Mock / Dataset Eval
โมดูล eKYC / Image / Dispute / Sumsub ที่ได้ Accuracy/Agreement 100% ในรอบนี้สะท้อนว่า **ท่อประมวลผลและการแมปสถานะ** ทำงานถูกต้องภายใต้สถานการณ์ควบคุม  
ส่วน **Chat Moderation ได้ 97%** จากการประเมินกับชุดข้อความไทยที่มี Ground Truth จริง (ไม่ใช่ GT ที่บังคับผ่าน mock scenario) จึงมี Error Analysis ให้ปรับ Regex/Threshold — ยังไม่ใช่การรับรองความแม่นยำของโมเดลคลาวด์บนข้อมูลโลกจริง

### 4.13.3 เปรียบเทียบ Latency
| โมดูล | Avg | ลักษณะงาน |
|-------|-----|-----------|
| Chat | 399 ms | หลายเคสจบที่ Regex |
| Image Fraud | 1129 ms | Vision เฉพาะเคสเสี่ยง |
| Dispute | 1333 ms | LLM วิเคราะห์บริบท |
| eKYC AiGen | 2429 ms | OCR + Face สองขั้น |
| Sumsub | 3063 ms | Token+Applicant+Review |

ลำดับนี้สอดคล้องกับความซับซ้อนของ Pipeline จริง และใช้เป็นเกณฑ์ออกแบบ UX (เช่น แสดง progress ตอน KYC)

### 4.13.4 Graceful Degradation
ทุกโมดูล AI มีชั้นสำรอง: Manual KYC, EXIF-only, Regex-only, Heuristic, Admin payment review — ทำให้ระบบยังให้บริการได้เมื่อคลาวด์ล่มหรือโควต้าหมด ซึ่งเป็นคุณสมบัติสำคัญของระบบวิศวกรรมที่ใช้งานจริง

### 4.13.5 ข้อจำกัดและงานต่อ
1. ทดสอบบน API จริงด้วยชุดข้อมูลภายใต้ PDPA  
2. ขยาย N=500 เพื่อความเสถียรทางสถิติ  
3. วาด ROC/DET สำหรับ Threshold ของ Face/Image/Chat  
4. เปรียบเทียบ AiGen กับ Sumsub แบบ paired test  
5. วัด Agreement ของ Dispute AI กับแอดมินจริง  
6. แนบ Screenshot UAT จาก UI จริง  

---

## 4.14 สรุป

บทนี้อธิบาย AI/API ของ FLIXO พร้อมผลการทดลอง N=100 ของโมดูล AI หลักห้าชุด API ธุรกรรม/ยืนยันตัวตน (EasySlip / Omise / Google) และ **Resilience 10/10 + UAT 12/12** ซึ่งยืนยัน Logic Flow, Fallback และความพร้อมเชิงฟังก์ชันสำหรับจัดหน้าเล่มรายงาน

> **ส่วนเสริมงานวิจัย:** ดูรายละเอียดสถิติ / กราฟ / อภิปรายเชิงลึก / เปรียบเทียบแนวทางใน  
> [`Chapter4_Research_Enhancement.md`](Chapter4_Research_Enhancement.md) (§4.15–4.21)

---

## ภาคผนวก ก — ไฟล์ผลทดลอง

| ไฟล์ | คำอธิบาย |
|------|----------|
| [appendix_ch4/ekyc_results.csv](appendix_ch4/ekyc_results.csv) | AiGen eKYC 100 แถว (Mock) |
| [appendix_ch4/ekyc_real_results.csv](appendix_ch4/ekyc_real_results.csv) | AiGen eKYC 4 คู่ภาพจริง (Live) |
| [appendix_ch4/sumsub_results.csv](appendix_ch4/sumsub_results.csv) | Sumsub pipeline 100 แถว |
| [appendix_ch4/image_fraud_results.csv](appendix_ch4/image_fraud_results.csv) | Image Fraud 100 แถว |
| [appendix_ch4/chat_results.csv](appendix_ch4/chat_results.csv) | Chat Moderation 100 แถว |
| [appendix_ch4/dispute_ai_results.csv](appendix_ch4/dispute_ai_results.csv) | Dispute AI 100 แถว |
| [appendix_ch4/easyslip_results.csv](appendix_ch4/easyslip_results.csv) | EasySlip decision 100 แถว |
| [appendix_ch4/omise_results.csv](appendix_ch4/omise_results.csv) | Omise sandbox 100 แถว |
| [appendix_ch4/google_auth_results.csv](appendix_ch4/google_auth_results.csv) | Google Auth verify 100 แถว |
| [appendix_ch4/resilience_results.csv](appendix_ch4/resilience_results.csv) | Fallback 10 เคส |
| [appendix_ch4/uat_results.csv](appendix_ch4/uat_results.csv) | UAT U-01…U-12 |
| [appendix_ch4/stats_summary.json](appendix_ch4/stats_summary.json) | สรุปสถิติรวม |

## ภาคผนวก ข — วิธีรันทดสอบซ้ำ

```text
C:\xampp\php\php.exe tests/test_all_ai_modules.php
C:\xampp\php\php.exe tests/test_easyslip_100_runs.php
C:\xampp\php\php.exe tests/test_omise_100_runs.php
C:\xampp\php\php.exe tests/test_google_auth_100_runs.php
C:\xampp\php\php.exe tests/test_resilience_and_uat.php
```

AI โมดูลต้องตั้ง `MOCK_API_MODE=true` · Omise ใช้ sandbox keys · EasySlip รอบนี้เป็น fixture decision · Resilience สคริปต์บังคับ MOCK=false เอง

---

*เอกสารฉบับสมบูรณ์สำหรับส่งเล่ม — อธิบาย AI ครบทุกตัว + ทดลอง N=100 ทุกโมดูล AI หลัก*
