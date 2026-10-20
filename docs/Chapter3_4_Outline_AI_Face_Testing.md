# Outline บทที่ 3–4 และร่างเนื้อหาเชิงวิศวกรรม  
## โครงการ FLIXO Escrow — ระบบยืนยันตัวตนด้วยใบหน้า (eKYC Face Recognition)

> **หมายเหตุสำคัญสำหรับผู้เขียนรายงาน (อ่านก่อนเขียนลงเล่ม)**  
> จากโค้ดจริงของระบบ FLIXO การสแกนใบหน้า **ไม่ได้ใช้เป็นช่องทางล็อกอิน** แต่ใช้ในขั้นตอน **eKYC (Electronic Know Your Customer)** หลังผู้ใช้เข้าสู่ระบบแล้ว เพื่อจับคู่ใบหน้ากับรูปบนบัตรประชาชน  
> การล็อกอินใช้ **Username/Password** และ **Google Sign-In**  
> ระบบสำรองเมื่อ AI ล้มเหลวคือ **Manual Review โดยแอดมิน** (ไม่ใช่ OTP ในเวอร์ชันปัจจุบัน)  
> ควรเขียนให้ตรงความจริงนี้ เพื่อความน่าเชื่อถือทางวิชาการ และเตรียมข้อมูลสำหรับ Paper ได้ถูกต้อง — หากต้องการเสนอ OTP เป็นทางเลือกเพิ่ม ให้อยู่ในบทที่ 5 (ข้อเสนอแนะ)

---

## สรุปโครงสร้างเล่มรายงาน (ภาพรวม)

| บท | ชื่อบท | จุดเน้นตาม Feedback อาจารย์ |
|----|--------|------------------------------|
| 2 | ทฤษฎีและงานวิจัยที่เกี่ยวข้อง | กระชับ ตรงจุด: Escrow, eKYC, Face Matching, OCR, Liveness, Confusion Matrix |
| 3 | การออกแบบและพัฒนาระบบ | Architecture, AI Spec, UI จริง, Flowchart, Fallback |
| 4 | ผลการทดลองและการใช้งานจริง | ทดสอบ AI 100/500 ครั้ง, ตารางวัดผล, Response Time |
| 5 | สรุปผลและข้อเสนอแนะ | สรุปเชิงวิศวกรรม + ข้อจำกัด + งานวิจัยต่อ |

---

# ส่วนที่ 1 — Outline ละเอียด บทที่ 3  
## การออกแบบและพัฒนาระบบ

### 3.1 ภาพรวมสถาปัตยกรรมระบบ (System Architecture)

#### 3.1.1 ขอบเขตระบบที่พัฒนา
- แพลตฟอร์ม Escrow สำหรับซื้อขายสินค้ามือสอง/สินค้าดิจิทัล
- โมดูลความปลอดภัยที่เกี่ยวข้องกับ AI:
  1. **Authentication** — เข้าสู่ระบบ (Password / Google OAuth)
  2. **eKYC Face Verification** — ยืนยันตัวตนด้วย OCR + Face Compare
  3. **(เสริม)** Product Image Fraud Detection — Google Cloud Vision
  4. **(เสริม)** Chat Moderation — Regex + Gemini

#### 3.1.2 สถาปัตยกรรมแบบหลายชั้น (Layered Architecture)
เสนอให้วาด Diagram (แนะนำ Mermaid / draw.io) ดังนี้:

```
[Client Browser]
    │  HTTPS
    ▼
[Presentation Layer]  login.php | kyc_verify.php | dashboard.php | admin/*
    │
    ▼
[Application Layer]   AuthController | UserController | EkycController | EkycService
    │
    ├──► [AI Provider: AiGen]     /aiscript/idcard/v3 + /aiface/face-compare/v3
    ├──► [AI Provider: Sumsub]    WebSDK + id-and-liveness
    ├──► [Fallback: Manual]       Admin Review (users_list.php)
    │
    ▼
[Data Layer]          MySQL (users, ekyc_sessions) + Encrypted uploads/kyc/
```

**สิ่งที่ต้องใส่ในรายงาน:** รูป Architecture Diagram + ตารางองค์ประกอบระบบ

| ชั้น | เทคโนโลยี | หน้าที่ |
|-----|-----------|---------|
| Frontend | HTML/CSS/JS, getUserMedia API | UI ล็อกอิน / ถ่ายบัตร / ถ่ายใบหน้า |
| Backend | PHP (XAMPP), Session Auth | ควบคุม Flow, CSRF, อัปโหลดไฟล์ |
| AI Service | AiGen Cloud API | Thai ID OCR + Face Compare |
| AI Service (ทางเลือก) | Sumsub WebSDK | ID + Liveness Detection |
| Fallback | Admin Manual Review | ตรวจสอบเมื่อ AI ล้มเหลว |
| Storage | MySQL + AES-256-GCM | เก็บสถานะ KYC และภาพเข้ารหัส |

---

### 3.2 ระบบยืนยันตัวตนและการเข้าสู่ระบบ (Authentication)

> แยกหัวข้อนี้ชัดเจนจาก Face Scan เพื่อไม่ให้ผู้อ่านสับสนว่า Face = Login

#### 3.2.1 ข้อกำหนดระบบ (System Specification) — Authentication
| รายการ | สเปก |
|--------|------|
| ช่องทางหลัก | Username/Email + Password (`password_verify`) |
| ช่องทางสำรอง | Google Sign-In (OAuth) |
| การกู้คืนบัญชี | Forgot/Reset Password ผ่านอีเมล |
| Session | PHP Session |
| หน้า UI | `login.php`, `register.php`, `forgot_password.php` |

#### 3.2.2 Flow การล็อกอิน (ใส่ Flowchart)
1. ผู้ใช้เปิด `login.php`
2. กรอกอีเมล/ชื่อผู้ใช้ + รหัสผ่าน **หรือ** เลือก Google Sign-In
3. ระบบตรวจสอบ credentials
4. สร้าง Session → เข้า `dashboard.php`
5. หากยังไม่ผ่าน eKYC → แจ้งให้ไปยืนยันที่ `kyc_verify.php` (กรณีต้องการขาย/ทำธุรกรรมที่บังคับ KYC)

#### 3.2.3 UI หน้าเว็บจริงที่ต้องแนบ Screenshot
- หน้า Login (ฟอร์ม Username/Password + Google)
- หน้า Register
- หน้า Dashboard / Profile ที่แสดงสถานะ KYC

---

### 3.3 ระบบสแกนใบหน้าและ eKYC (Face Recognition / AI Detection) ★ หัวใจบทที่ 3

#### 3.3.1 วัตถุประสงค์ของโมดูล
ใช้ AI เพื่อยืนยันว่า **บุคคลที่ถือบัญชี = เจ้าของบัตรประชาชน** โดยเปรียบเทียบใบหน้าบนบัตรกับภาพ Selfie ที่ถ่ายสดจากกล้องหน้า

#### 3.3.2 สถาปัตยกรรม AI (AI Architecture)

**แนวทางที่ระบบใช้จริง: Cloud-based Face Matching (ไม่เทรนโมเดลเองในเครื่อง)**

| องค์ประกอบ | รายละเอียดทางเทคนิค |
|------------|---------------------|
| ผู้ให้บริการหลัก | **AiGen** (`api.aigen.online`) |
| โมเดล/API ที่เรียกใช้ | (1) `POST /aiscript/idcard/v3` — Thai ID Card OCR  
|  | (2) `POST /aiface/face-compare/v3` — Face Matching |
| การสร้าง/เทรนโมเดล | **ไม่มีการเทรนโมเดลเองในโปรเจกต์** — ใช้ Pre-trained / Managed Model ของผู้ให้บริการผ่าน API |
| จุดที่ปรับแต่ง (Configuration/Fine-tuning ระดับระบบ) | ค่า `AIGEN_FACE_THRESHOLD` (ค่าเริ่มต้น **80**), ขนาดภาพ Selfie, รูปแบบไฟล์, Timeout |
| ผู้ให้บริการทางเลือก | **Sumsub** ระดับ `id-and-liveness` (มี Liveness Detection จริงผ่าน WebSDK) |
| โหมดสำรอง | `EKYC_PROVIDER=manual` หรือ Auto-fallback เมื่อ API ล้มเหลว |

**การเลือก Provider ในระบบ (`EKYC_PROVIDER`):**
- `auto` → ใช้ AiGen หากมี API Key → ไม่มีแล้วใช้ Sumsub → ไม่มีแล้วใช้ Manual
- หรือบังคับเป็น `aigen` / `sumsub` / `manual`

#### 3.3.3 System Specification ของ Face Pipeline

| รายการ | ค่าที่กำหนดในระบบ |
|--------|-------------------|
| ความละเอียด Selfie | 640 × 480 px (Canvas) |
| รูปแบบไฟล์ | JPEG (quality 0.9), รองรับ PNG/WebP ตอนอัปโหลด |
| กล้อง | `getUserMedia({ video: { facingMode: 'user' } })` |
| ขนาดไฟล์สูงสุด | 5 MB |
| Face Match Threshold | 80 (configurable ผ่าน env) |
| พารามิเตอร์ Face Compare | `image1_is_document=true`, `verified_document=true` |
| HTTP Timeout (AiGen) | 90 วินาที |
| ผลลัพธ์ตัดสิน | `match` (boolean) + `score` (ตัวเลข) |
| หลังตรวจสอบ | เข้ารหัสภาพด้วย AES-256-GCM ก่อนจัดเก็บ |

#### 3.3.4 Flow การทำงานของระบบ Face Scan / eKYC (ใส่ Flowchart)

```
เริ่มต้น (ผู้ใช้ล็อกอินแล้ว)
        │
        ▼
เปิดหน้า kyc_verify.php
        │
        ├─ Provider = Sumsub ──► WebSDK (ID + Liveness) ──► Webhook/Sync ──► approved/rejected
        │
        └─ Provider = AiGen / Manual
                │
                ▼
        Step 1: อัปโหลด/ถ่ายบัตรประชาชน
                │
                ▼
        Step 2: เปิดกล้องหน้า → ถ่าย Selfie 640×480
                │
                ▼
        ยินยอม PDPA / Consent
                │
                ▼
        POST submit_kyc (UserController)
                │
        ┌───────┴────────┐
        │ AiGen ใช้งานได้ │
        └───────┬────────┘
                ▼
        OCR บัตร (/aiscript/idcard/v3)
                │
        ┌───────┴────────┐
        │ สำเร็จ/ไม่สำเร็จ │
        └───────┬────────┘
                ▼ (สำเร็จ)
        Face Compare (/aiface/face-compare/v3, threshold=80)
                │
        ┌───────┼───────────────┐
        │       │               │
     match   ไม่ match     API Error
        │       │               │
   approved  rejected     Fallback Manual
                              (pending)
                                │
                                ▼
                     Admin Review → approve/reject
```

#### 3.3.5 UI หน้าเว็บจริง (ต้องแนบ Screenshot)
หน้า `kyc_verify.php` ประกอบด้วย:
1. Step indicator: (1) บัตรประชาชน (2) รูปใบหน้า  
2. โซนอัปโหลดบัตร / กล้องหลัง (`capture="environment"`)  
3. Preview กล้องหน้า + ปุ่ม เปิดกล้อง / ถ่ายรูป / ถ่ายใหม่  
4. Checkbox ยินยอมจัดเก็บข้อมูล KYC ตาม PDPA  
5. ปุ่มส่งตรวจ (AiGen: “ตรวจสอบและยืนยันตัวตน”)  
6. หน้าสถานะรอแอดมิน / ไม่ผ่าน / ผ่านแล้ว  

แนะนำให้ใส่ตาราง Mapping UI ↔ Function:

| องค์ประกอบ UI | ฟังก์ชันระบบ |
|---------------|--------------|
| อัปโหลดบัตร | รับไฟล์ → `secureUpload()` |
| เปิดกล้อง/ถ่าย Selfie | MediaDevices API → Canvas 640×480 |
| ส่งยืนยัน | `UserController?action=submit_kyc` |
| ผลตรวจทันที | `EkycService::verifyWithAigen()` |
| รอตรวจสอบ | `kyc_status=pending` → Admin |

---

### 3.4 ระบบสำรอง (Fallback / Manual System) ★ ตามที่อาจารย์เน้น

#### 3.4.1 หลักการออกแบบ
ระบบต้องมี **Graceful Degradation** — หาก AI ไม่พร้อมใช้หรือผลตรวจไม่น่าเชื่อถือ ระบบต้องไม่หยุดทำงาน แต่สลับไปช่องทางมนุษย์ตรวจสอบแทน

#### 3.4.2 ตารางสถานการณ์และทางเลือก

| สถานการณ์ | สาเหตุทางเทคนิค | ระบบสำรอง / การจัดการ |
|-----------|-----------------|------------------------|
| Face ไม่ตรงบัตร | `match=false` หรือ score < threshold | ตั้ง `rejected` → ให้ผู้ใช้ถ่ายใหม่ |
| ตรวจไม่พบใบหน้า | `FACE_NOT_DETECTED` | แจ้งผู้ใช้ปรับมุมกล้อง/แสง → ลองใหม่ |
| อ่านบัตรไม่สำเร็จ | OCR fail / ข้อมูลไม่ครบ | Reject + แนะนำถ่ายบัตรใหม่ |
| AiGen API ล่ม / Timeout / เครดิตหมด | HTTP/Service error | **Fallback → Manual Review** (`kyc_status=pending`) |
| ไม่มี API Key | Provider = manual | อัปโหลดเอกสารอย่างเดียว → แอดมินตรวจ |
| Sumsub ผล RED | Liveness/ID ไม่ผ่าน | Reject → ให้ลองใหม่ |
| Sumsub Webhook ไม่ถึง (เช่น localhost) | Network | ปุ่ม “ตรวจสอบผลอีกครั้ง” (`sync_status`) |
| ผู้ใช้ลืมรหัสผ่าน (Authentication) | ไม่เกี่ยวกับ Face | Reset Password ผ่านอีเมล / Google Sign-In |

#### 3.4.3 ขั้นตอน Manual Fallback (ใส่ Sequence Diagram)
1. ระบบบันทึกไฟล์บัตร + Selfie (เข้ารหัส)  
2. ตั้งค่า `kyc_status = pending`  
3. แสดงข้อความบน UI: รอแอดมินตรวจสอบ (ภายใน 24 ชม.)  
4. แอดมินเปิด `admin/users_list.php` / ดูภาพผ่าน `admin/get_image.php`  
5. อนุมัติ → `is_verified=1`, `kyc_status=approved`  
6. หรือปฏิเสธ → `rejected` + ล้างไฟล์ที่เกี่ยวข้องตามนโยบาย  

#### 3.4.4 ข้อเสนอสำหรับรายงาน (ถ้าอาจารย์ถามถึง OTP)
ระบุชัดว่า:
- **เวอร์ชันปัจจุบัน:** Fallback ของ Face/eKYC = Manual Admin Review  
- **Authentication ปัจจุบัน:** Password + Google (ไม่ใช้ Face Login)  
- **งานอนาคต (บทที่ 5):** เพิ่ม Manual OTP / 2FA เมื่อ Face Scan ล้มเหลวซ้ำ หรือใช้เป็น Step-up Authentication ก่อนธุรกรรมมูลค่าสูง

---

### 3.5 การออกแบบฐานข้อมูลที่เกี่ยวข้อง (ย่อ)
- `users`: `is_verified`, `kyc_status`, `id_card_image`, `selfie_image`, `ekyc_provider`, `real_name`
- `ekyc_sessions`: บันทึก provider, สถานะ, score/payload (sanitize แล้ว)
- อธิบาย State Machine ของ `kyc_status`: `none → pending → approved | rejected`

### 3.6 ความมั่นคงปลอดภัยที่เกี่ยวข้อง
- CSRF Token บนฟอร์ม KYC  
- Permissions-Policy: `camera=(self)`  
- CSP อนุญาตโดเมน Sumsub/AiGen ที่จำเป็น  
- เข้ารหัสภาพ PII ที่ Rest (AES-256-GCM)  
- Mask เลขบัตรใน Log  

### 3.7 สรุปบทที่ 3
สรุปว่าโมดูล Face ไม่ได้ถูกเทรนในเครื่อง แต่ถูก **Integrate แบบ API-driven** พร้อม Threshold ที่ปรับได้ และมี Manual Fallback เพื่อความต่อเนื่องของบริการ

---

# ส่วนที่ 2 — Outline ละเอียด บทที่ 4  
## ผลการทดลองและการใช้งานจริง

### 4.1 วัตถุประสงค์การทดลอง
1. วัดประสิทธิภาพของระบบ Face Matching (AiGen Face Compare) ภายใต้เงื่อนไขการใช้งานจริง  
2. วัดความเสถียรเมื่อจำนวนรอบทดสอบเพิ่มขึ้น (100 → 500 ครั้ง)  
3. วัด Response Time ของ Pipeline (OCR + Face Compare)  
4. ประเมินประสิทธิผลของ Fallback เมื่อเกิด Failure  

### 4.2 ระเบียบวิธีวิจัย/การทดสอบ (Methodology) ★ สำคัญสำหรับ Paper

#### 4.2.1 สภาพแวดล้อมการทดสอบ
| รายการ | ค่า |
|--------|-----|
| ระบบ | FLIXO Escrow (PHP/XAMPP) |
| AI Provider | AiGen Face Compare v3 + ID OCR v3 |
| Threshold | 80 |
| ความละเอียด Selfie | 640×480 JPEG q=0.9 |
| อุปกรณ์กล้อง | (ระบุรุ่นมือถือ/Webcam ที่ใช้จริง) |
| เครือข่าย | (ระบุ เช่น Wi-Fi / 4G ความเร็วโดยประมาณ) |
| จำนวนผู้เข้าร่วม | (ระบุ เช่น N คน) |

#### 4.2.2 ชุดข้อมูลทดสอบ (Test Dataset Design)
ออกแบบคลาสคำตอบจริง (Ground Truth) อย่างน้อย 4 กลุ่ม:

| กลุ่ม | ความหมาย | ผลที่คาดหวัง |
|------|----------|--------------|
| G1 Genuine | Selfie ของเจ้าของบัตรคนเดียวกัน | Match / Approved (TP) |
| G2 Impostor | Selfie คนละคนกับเจ้าของบัตร | Non-match / Rejected (TN) |
| G3 Poor Quality | ภาพมืด/เบลอ/มุมเอียง | Reject หรือ Error ที่จัดการได้ |
| G4 Attack/Abuse (ถ้าทำได้) | ภาพถ่ายจากจอ / หน้ากาก (optional) | Reject |

> สำหรับรอบ 100 และ 500 ครั้ง แนะนำสัดส่วน เช่น Genuine 50% / Impostor 30% / Poor Quality 20% (ปรับได้ตามข้อจำกัดจริยธรรมและ PDPA)

#### 4.2.3 นิยาม Confusion Matrix สำหรับ Face Match

|  | ระบบตัดสิน Match | ระบบตัดสิน Non-match |
|--|------------------|----------------------|
| **จริงคือคนเดียวกัน (Genuine)** | TP (True Positive) | FN (False Negative) |
| **จริงคือคนละคน (Impostor)** | FP (False Positive) | TN (True Negative) |

#### 4.2.4 ตัวชี้วัดทางวิศวกรรม (Metrics)

\[
Accuracy = \frac{TP + TN}{TP + TN + FP + FN}
\]

\[
Precision = \frac{TP}{TP + FP}
\]

\[
Recall\ (TPR) = \frac{TP}{TP + FN}
\]

\[
F1 = 2 \cdot \frac{Precision \cdot Recall}{Precision + Recall}
\]

\[
FPR = \frac{FP}{FP + TN},\quad FAR \approx FPR
\]

\[
FRR = \frac{FN}{TP + FN}
\]

ตัวชี้วัดด้านประสิทธิภาพระบบ:
- **Average Response Time (ms)** ของ OCR, Face Compare, และ End-to-End
- **Success Rate** ของการเรียก API
- **Fallback Rate** = จำนวนครั้งที่เข้า Manual / จำนวนครั้งทั้งหมด
- **Retry Rate** หลังถูก reject

### 4.3 การทดลองที่ 1: ทดสอบ 100 ครั้ง
- เป้าหมาย: ประเมินเบื้องต้น (Pilot Test)
- บันทึกผลลงตาราง Template A
- รายงาน Confusion Matrix ขนาด 2×2
- วิเคราะห์เคสที่พลาด (Error Analysis)

### 4.4 การทดลองที่ 2: ทดสอบ 500 ครั้ง
- เป้าหมาย: ยืนยันความเสถียรเมื่อปริมาณเพิ่มขึ้น
- เปรียบเทียบกับผล 100 ครั้ง (Accuracy/FPR/FRR/Latency)
- วิเคราะห์ว่า Metric เปลี่ยนไปหรือไม่เมื่อ Sample ใหญ่ขึ้น

### 4.5 การทดลองที่ 3: Response Time & Fallback
- วัดเวลาเฉลี่ย/ค่ามัธยฐาน/เปอร์เซ็นไทล์ (P50, P95)
- จำลองกรณี API Timeout → ตรวจว่าเข้า Manual ได้ถูกต้อง

### 4.6 ผลการใช้งานจริงในระบบ Escrow (Usability / Functional Test)
ตารางทดสอบฟังก์ชัน:

| รหัส | กรณีทดสอบ | ผลที่คาดหวัง | ผลจริง | ผ่าน/ไม่ผ่าน |
|------|-----------|--------------|--------|---------------|
| F-01 | ล็อกอินด้วย Password ถูกต้อง | เข้า Dashboard ได้ | | |
| F-02 | ล็อกอินรหัสผิด | แจ้งเตือน | | |
| F-03 | eKYC Genuine ผ่าน Threshold | approved | | |
| F-04 | eKYC Impostor | rejected | | |
| F-05 | API ล่ม | pending + Manual | | |
| F-06 | Admin อนุมัติ Manual | is_verified=1 | | |

### 4.7 อภิปรายผล (Discussion)
- เปรียบเทียบกับงานวิจัยที่เกี่ยวข้องจากบทที่ 2
- อภิปราย Trade-off ของ Threshold=80 (FAR ต่ำลง vs FRR สูงขึ้น)
- ข้อจำกัด: แสง, คุณภาพกล้อง, การพึ่งพา Cloud API, ยังไม่มี Active Liveness ในโหมด AiGen (ยังเป็นภาพนิ่ง)

### 4.8 สรุปบทที่ 4

---

# ส่วนที่ 3 — ร่างข้อความเชิงวิชาการ/วิศวกรรม  
## (คัดลอกไปปรับในเล่มได้)

### 3.A ร่าง: สถาปัตยกรรมระบบ AI สำหรับการยืนยันตัวตนด้วยใบหน้า

ระบบยืนยันตัวตนของ FLIXO Escrow ถูกออกแบบในลักษณะ **API-driven Face Verification Architecture** โดยแยกชั้นการประมวลผลภาพใบหน้าออกจากเครื่องแม่ข่ายของแอปพลิเคชัน เพื่อลดภาระการพัฒนาโมเดลในเครื่องและอาศัยความสามารถของบริการจดจำใบหน้าที่ผ่านการฝึกมาแล้วจากผู้ให้บริการภายนอก

กระบวนการหลักประกอบด้วยสองขั้นตอนเรียงลำดับ ได้แก่ (1) **Optical Character Recognition (OCR)** ของบัตรประชาชนไทยผ่านจุดเชื่อมต่อ `/aiscript/idcard/v3` เพื่อสกัดชื่อและเลขบัตร พร้อมดึงข้อมูลใบหน้าจากเอกสาร และ (2) **Face Comparison** ผ่าน `/aiface/face-compare/v3` เพื่อเปรียบเทียบใบหน้าบนบัตร (`image1`) กับภาพเซลฟีที่ถ่ายจากกล้องหน้า (`image2`)

ในการตัดสินผล ระบบกำหนดค่าเกณฑ์ความคล้ายคลึง (Similarity Threshold) เท่ากับ 80 ซึ่งสามารถปรับผ่านค่าคอนฟิกูเรชัน `AIGEN_FACE_THRESHOLD` ได้ โดยผลลัพธ์จากบริการประกอบด้วยค่าความจริงเชิงตรรกะ `match` และค่าคะแนน `score` หาก `match` เป็นจริง ระบบจะเปลี่ยนสถานะผู้ใช้เป็น `approved` และตั้งค่า `is_verified = 1` หากไม่ตรงกันจะตั้งค่าเป็น `rejected` และเปิดโอกาสให้ผู้ใช้ดำเนินการใหม

ทั้งนี้ โปรเจกต์นี้ **ไม่ได้สร้างหรือฝึกโมเดลโครงข่ายประสาทเทียมขึ้นใหม่ภายในเครื่อง** แต่เป็นการบูรณาการ (System Integration) โมเดลสำเร็จรูปผ่านคลาวด์ พร้อมกำหนดพารามิเตอร์เชิงวิศวกรรม ได้แก่ ขนาดภาพอินพุต 640×480 พิกเซล รูปแบบ JPEG คุณภาพ 0.9 ขนาดไฟล์สูงสุด 5 เมกะไบต์ และเวลาหมดอายุของการเรียกบริการที่ 90 วินาที ซึ่งถือเป็นการปรับแต่งระดับระบบ (System-level Configuration) มากกว่าการ Fine-tune น้ำหนักโมเดล

ในกรณีที่ต้องการการตรวจสอบความเป็นบุคคลจริงแบบโต้ตอบ (Active Liveness) ระบบรองรับการสลับไปใช้ Sumsub ที่ระดับการตรวจสอบ `id-and-liveness` ผ่าน WebSDK เป็นทางเลือกสถาปัตยกรรมขนานภายใต้ตัวแปร `EKYC_PROVIDER`

### 3.B ร่าง: Flow การทำงานของระบบล็อกอินและ Face Scan บน UI จริง

การเข้าใช้งานระบบถูกแบ่งเป็นสองเฟสที่แยกกันชัดเจน ได้แก่ **Authentication Phase** และ **Identity Verification Phase**

ในเฟสแรก ผู้ใช้เข้าสู่หน้า `login.php` เพื่อยืนยันสิทธิ์ด้วยชื่อผู้ใช้หรืออีเมลร่วมกับรหัสผ่าน หรือเลือกเข้าสู่ระบบด้วย Google Sign-In เมื่อตรวจสอบสำเร็จ ระบบจะสร้างเซสชันและนำผู้ใช้เข้าสู่แดชบอร์ด

ในเฟสที่สอง ซึ่งเป็นขั้นตอนยืนยันตัวตนด้วยใบหน้า ผู้ใช้เข้าสู่หน้า `kyc_verify.php` ผ่านสถานะล็อกอินแล้ว ส่วนต่อประสานผู้ใช้ถูกออกแบบเป็นลำดับขั้น (Wizard UI) ขั้นที่ 1 ให้ผู้ใช้อัปโหลดหรือถ่ายภาพบัตรประชาชน ขั้นที่ 2 เปิดกล้องหน้าด้วย MediaDevices API เพื่อถ่ายภาพเซลฟีแบบพรีวิวสด จากนั้นผู้ใช้ต้องให้ความยินยอมตาม PDPA ก่อนส่งข้อมูล เมื่อกดส่ง ระบบจะอัปโหลดไฟล์ไปยังคอนโทรลเลอร์ฝั่งเซิร์ฟเวอร์และเรียกท่อประมวลผล OCR ร่วมกับ Face Compare หากผ่านเกณฑ์ ผู้ใช้จะได้รับการยืนยันทันทีและถูกนำกลับไปยังโปรไฟล์

การแยกเฟสดังกล่าวทำให้การยืนยันใบหน้าทำหน้าที่เป็น **Identity Assurance Layer** สำหรับธุรกรรมที่ต้องการความน่าเชื่อถือสูง ขณะที่การล็อกอินยังคงใช้กลไกความรู้ความลับ (Something-you-know) และ/หรือสหพันธ์อัตลักษณ์ (Google OAuth) ซึ่งสอดคล้องหลักการป้องกันหลายชั้น (Defense in Depth)

### 3.C ร่าง: ระบบสำรอง (Fallback / Manual System)

เพื่อรองรับความไม่แน่นอนของบริการปัญญาประดิษฐ์บนคลาวด์ ระบบได้กำหนดกลไก **Fallback to Human-in-the-Loop** ไว้เป็นชั้นสำรอง เมื่อการเรียก AiGen เกิดข้อผิดพลาดด้านเครือข่าย หมดเวลา หรือไม่พร้อมให้บริการ ระบบจะไม่ปฏิเสธผู้ใช้โดยอัตโนมัติในทุกกรณี แต่จะบันทึกเอกสารบัตรและภาพเซลฟีในสถานะ `pending` จากนั้นส่งต่อไปยังกระบวนการตรวจสอบโดยผู้ดูแลระบบ

ผู้ดูแลระบบสามารถเรียกดูภาพที่เข้ารหัสผ่านส่วนงานผู้ดูแล และตัดสินใจอนุมัติหรือปฏิเสธตามหลักฐานที่ปรากฏ หากอนุมัติ สถานะผู้ใช้จะถูกอัปเดตเป็นยืนยันแล้วเช่นเดียวกับเส้นทางอัตโนมัติ หากปฏิเสธ ผู้ใช้สามารถเริ่มกระบวนการใหมบนหน้าเว็บได้

นอกจากนี้ ในกรณีที่ผล Face Compare ระบุว่าใบหน้าไม่ตรงกัน หรือตรวจไม่พบใบหน้า ระบบจะแจ้งเหตุผลเชิงปฏิบัติการกลับไปยังผู้ใช้เพื่อให้ปรับสภาพแวดล้อมการถ่ายภาพและลองใหม่ ซึ่งถือเป็น **User-side Recovery Path** ส่วนกรณีที่ผู้ใช้ไม่สามารถเข้าสู่ระบบได้เนื่องจากลืมรหัสผ่าน ระบบมีช่องทางกู้คืนผ่านอีเมลและ Google Sign-In ซึ่งเป็น Fallback ของชั้น Authentication แยกจากชั้น eKYC

การออกแบบเช่นนี้ทำให้ระบบมีทั้งความสามารถอัตโนมัติเมื่อ AI พร้อมใช้ และความต่อเนื่องของบริการเมื่อ AI ไม่พร้อมใช้ ซึ่งเป็นคุณสมบัติสำคัญของระบบวิศวกรรมที่นำไปใช้งานจริง

### 4.A ร่าง: ระเบียบวิธีทดสอบประสิทธิภาพ AI

การประเมินประสิทธิภาพของระบบจับคู่ใบหน้าในงานวิจัยนี้ใช้ระเบียบวิธีเชิงทดลองแบบควบคุม โดยกำหนดชุดทดสอบสองขนาด ได้แก่ 100 ครั้ง และ 500 ครั้ง เพื่อเปรียบเทียบความเสถียรของตัวชี้วัดเมื่อจำนวนตัวอย่างเพิ่มขึ้น แต่ละครั้งของการทดสอบถูกติดป้ายกำกับผลจริงล่วงหน้าว่าเป็นคู่ Genuine หรือ Impostor จากนั้นให้ระบบตัดสินภายใต้ค่า Threshold คงที่ที่ 80

ผลการตัดสินถูกจัดเก็บในรูปของ Confusion Matrix เพื่อคำนวณ Accuracy, Precision, Recall, F1-score รวมถึงอัตราการยอมรับผิด (FAR/FPR) และอัตราการปฏิเสธผิด (FRR) นอกจากนี้ยังบันทึกเวลาตอบสนองตั้งแต่เริ่มส่งภาพจนได้รับผลจาก API เพื่อวิเคราะห์ภาระหน่วงเวลาของท่อประมวลผล OCR และ Face Compare

สำหรับกรณีที่บริการภายนอกล้มเหลว การทดลองจะบันทึกอัตราการเข้าสู่โหมด Manual Review เพื่อประเมินว่ากลไก Fallback ทำงานครบถ้วนตามการออกแบบหรือไม่ ผลลัพธ์ที่ได้สามารถนำไปเปรียบเทียบกับเกณฑ์ที่ยอมรับได้ในงานยืนยันตัวตนออนไลน์ และใช้เป็นหลักฐานเชิงประจักษ์สำหรับการเขียนบทความวิจัยต่อไป

---

# ส่วนที่ 4 — Template ตารางบันทึกผลการทดลอง AI  
## (พร้อมเติมตัวเลข)

### Template A — บันทึกรายครั้ง (ใช้กับทั้งชุด 100 และ 500)

> คัดลอกไป Excel/Google Sheets ได้ — เพิ่มแถวจนครบ N

| No. | Dataset Group (G1/G2/G3/G4) | Ground Truth (Genuine=1 / Impostor=0) | OCR Result (Pass/Fail) | Face Score | System Decision (Match=1 / Non-match=0) | Final KYC Status (approved/rejected/pending/error) | OCR Time (ms) | Face Time (ms) | E2E Time (ms) | Device | Lighting (Good/Dim/Backlit) | Note |
|----:|-----------------------------|----------------------------------------|-------------------------|------------|------------------------------------------|---------------------------------------------------|---------------|----------------|---------------|--------|-----------------------------|------|
| 1 | G1 | 1 | | | | | | | | | | |
| 2 | G2 | 0 | | | | | | | | | | |
| 3 | G3 | 1 | | | | | | | | | | |
| … | | | | | | | | | | | | |
| 100 | | | | | | | | | | | | |

สำหรับชุด 500 ครั้ง ใช้หัวตารางเดียวกัน แล้วขยาย `No.` ถึง 500

---

### Template B — สรุป Confusion Matrix (N = 100)

|  | Predicted Match (Positive) | Predicted Non-match (Negative) | รวมจริง |
|--|----------------------------|--------------------------------|---------|
| **Actual Genuine** | TP = ____ | FN = ____ | TP+FN = ____ |
| **Actual Impostor** | FP = ____ | TN = ____ | FP+TN = ____ |
| **รวมทำนาย** | TP+FP = ____ | FN+TN = ____ | **N = 100** |

### Template C — สรุป Confusion Matrix (N = 500)

|  | Predicted Match (Positive) | Predicted Non-match (Negative) | รวมจริง |
|--|----------------------------|--------------------------------|---------|
| **Actual Genuine** | TP = ____ | FN = ____ | TP+FN = ____ |
| **Actual Impostor** | FP = ____ | TN = ____ | FP+TN = ____ |
| **รวมทำนาย** | TP+FP = ____ | FN+TN = ____ | **N = 500** |

---

### Template D — ตารางตัวชี้วัดเปรียบเทียบ 100 vs 500

| Metric | สูตร / ความหมาย | ผล N=100 | ผล N=500 | ต่างกัน (Δ) |
|--------|-----------------|----------|----------|-------------|
| Accuracy | (TP+TN)/N | ____ % | ____ % | ____ |
| Precision | TP/(TP+FP) | ____ % | ____ % | ____ |
| Recall (TPR) | TP/(TP+FN) | ____ % | ____ % | ____ |
| F1-score | 2·P·R/(P+R) | ____ | ____ | ____ |
| FPR / FAR | FP/(FP+TN) | ____ % | ____ % | ____ |
| FRR | FN/(TP+FN) | ____ % | ____ % | ____ |
| API Success Rate | สำเร็จ/ทั้งหมด | ____ % | ____ % | ____ |
| Fallback Rate | pending(manual)/N | ____ % | ____ % | ____ |
| Avg E2E Response Time | ms | ____ | ____ | ____ |
| P50 E2E Time | ms | ____ | ____ | ____ |
| P95 E2E Time | ms | ____ | ____ | ____ |

---

### Template E — ตาราง Response Time แยกรายบริการ (เติมหลังทดลอง)

| ขั้นตอน | Avg (ms) | Min | Max | P50 | P95 | N ที่วัดได้ |
|---------|----------|-----|-----|-----|-----|-------------|
| Thai ID OCR (`/aiscript/idcard/v3`) | | | | | | |
| Face Compare (`/aiface/face-compare/v3`) | | | | | | |
| End-to-End (อัปโหลดจนได้สถานะ) | | | | | | |
| Manual Fallback Handling (ถ้ามี) | | | | | | |

---

### Template F — Error Analysis (เคสที่ระบบพลาด)

| Case ID | กลุ่ม | Ground Truth | ผลระบบ | Score | สาเหตุที่สันนิษฐาน | แนวทางแก้ไข |
|---------|------|--------------|--------|-------|---------------------|--------------|
| E-01 | | | | | แสงน้อย / มุมเอียง / ภาพเบลอ / คนละคน / API error | |
| E-02 | | | | | | |
| E-03 | | | | | | |

---

### Template G — เกณฑ์การยอมรับผล (Acceptance Criteria) เสนอไว้ก่อนทดลอง

| ตัวชี้วัด | เกณฑ์เสนอ (ปรับตามอาจารย์ได้) | ผ่าน? |
|-----------|--------------------------------|-------|
| Accuracy | ≥ 90% | ☐ |
| FAR (FPR) | ≤ 5% | ☐ |
| FRR | ≤ 10% | ☐ |
| Avg E2E Time | ≤ 10,000 ms | ☐ |
| Fallback ทำงานเมื่อ API ล่ม | 100% ของเคสจำลอง | ☐ |

---

# ส่วนที่ 5 — Outline ย่อสำหรับบทที่ 2 และบทที่ 5 (ให้ครบตาม Feedback)

## บทที่ 2 ทฤษฎีและงานวิจัยที่เกี่ยวข้อง (ปรับให้กระชับ)

### 2.1 ระบบ Escrow และการป้องกันการฉ้อโกงออนไลน์
### 2.2 การยืนยันตัวตนอิเล็กทรอนิกส์ (eKYC)
### 2.3 ทฤษฎี Face Recognition / Face Verification
- Face Detection vs Face Verification vs Face Identification  
- Similarity Score และ Decision Threshold  
- FAR, FRR, EER (ย่อ ๆ)
### 2.4 OCR สำหรับบัตรประชาชน
### 2.5 Liveness Detection (แยก Passive ภาพนิ่ง vs Active)
### 2.6 งานวิจัยที่เกี่ยวข้อง (3–7 เรื่อง ตารางเปรียบเทียบ)
### 2.7 สรุปช่องว่างงานวิจัย → นำไปสู่การออกแบบบทที่ 3

## บทที่ 5 สรุปผลและข้อเสนอแนะ

### 5.1 สรุปผลการพัฒนา
### 5.2 สรุปผลการทดลอง (อ้างตัวเลขจากบทที่ 4)
### 5.3 ข้อจำกัด
- พึ่งพา Cloud API  
- AiGen path ยังเป็นภาพนิ่ง (ยังไม่ใช่ Active Liveness ในตัว)  
- ชุดทดสอบอาจจำกัดด้านความหลากหลายของประชากร/แสง
### 5.4 ข้อเสนอแนะ
1. เพิ่ม Active Liveness ในโหมดหลัก  
2. ทดลองปรับ Threshold และวาด ROC/DET curve  
3. เพิ่ม Step-up Auth ด้วย OTP/2FA เมื่อ Face ล้มเหลวซ้ำหรือธุรกรรมมูลค่าสูง  
4. เก็บ Telemetry อัตโนมัติเพื่อทำ Paper รอบถัดไป  
5. เปรียบเทียบ AiGen กับ Sumsub แบบ Side-by-side บนชุดข้อมูลเดียวกัน

---

# ส่วนที่ 6 — Checklist สิ่งที่ต้องไปทำต่อก่อนส่งเล่ม/เขียน Paper

- [ ] ถ่าย Screenshot UI จริง: Login, KYC Step1, KYC Step2 (กล้อง), Approved, Rejected, Pending Manual, Admin Review  
- [ ] วาด Architecture Diagram + Sequence Diagram + Flowchart Fallback  
- [ ] เตรียมชุดภาพทดสอบ Genuine/Impostor ตามจริยธรรมและ PDPA (ขอความยินยอม)  
- [ ] รันทดลอง 100 ครั้ง → เติม Template B, D  
- [ ] รันทดลอง 500 ครั้ง → เติม Template C, D  
- [ ] วิเคราะห์ Error cases ด้วย Template F  
- [ ] ย้ายตัวเลขเข้าบทที่ 4 และสรุปในบทที่ 5  
- [ ] เตรียมส่วน Methodology + Results สำหรับ Paper จากหัวข้อ 4.2–4.5

---

## ไฟล์อ้างอิงในโค้ดจริง (ใช้ใส่เชิงอรรถ/ภาคผนวกได้)

| หัวข้อ | ไฟล์ |
|--------|------|
| หน้า eKYC + กล้อง | `kyc_verify.php` |
| Face Compare / OCR Service | `includes/EkycService.php` |
| ค่า Threshold / Provider | `includes/config.php` |
| Submit KYC | `controllers/UserController.php` |
| Sumsub Token/Sync | `controllers/EkycController.php` |
| ล็อกอิน | `login.php`, `controllers/AuthController.php` |
| Manual Admin Review | `admin/users_list.php` |

---

*เอกสารนี้จัดทำเพื่อตอบ Feedback อาจารย์ที่ปรึกษา และใช้เป็นต้นฉบับสำหรับปรับบทที่ 3–4 รวมถึงเตรียมโครง Paper วิจัยต่อ*
