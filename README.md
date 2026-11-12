
# FLIXO: An AI-Driven Smart Escrow System and Fraud Detection Platform for C2C Online Trading

**FLIXO ระบบเอสโครว์อัจฉริยะและแพลตฟอร์มตรวจจับการทุจริตด้วยเอไอสำหรับการซื้อขายออนไลน์ระหว่างบุคคล (C2C)**

[![PHP](https://img.shields.io/badge/Backend-PHP-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/Database-MySQL%20%2F%20MariaDB-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Redis](https://img.shields.io/badge/Realtime-Redis%20Pub%2FSub-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io/)
[![AI Moderation](https://img.shields.io/badge/AI-Groq%20%7C%20OpenRouter%20%7C%20Gemini-8E75B2?style=flat-square)](https://deepmind.google/technologies/gemini/)

---

## 📖 เกี่ยวกับโครงการ (Overview)

**FLIXO** เป็นต้นแบบเว็บแอปพลิเคชันสำหรับซื้อขายสินค้าออนไลน์ระหว่างบุคคลทั่วไป (Consumer-to-Consumer: C2C) ที่ออกแบบมาเพื่อแก้ปัญหาการฉ้อโกงบนสื่อสังคมออนไลน์ โดยผสานการทำงานของ **ระบบบัญชีตัวกลาง (Smart Escrow)** เข้ากับ **ระบบยืนยันตัวตนอิเล็กทรอนิกส์ (e-KYC)** และ **ระบบตรวจจับความเสี่ยงด้วยปัญญาประดิษฐ์ (AI Risk Detection)** ไว้ในวงจรธุรกรรมเดียว

### 🎯 วัตถุประสงค์หลัก
1. พัฒนาต้นแบบแพลตฟอร์มซื้อขายออนไลน์ C2C ที่มีระบบพักเงินระหว่างผู้ซื้อและผู้ขาย (Escrow) พร้อมกลไกคัดกรองความเสี่ยงด้วย AI
2. ทดสอบประสิทธิภาพการทำงานของแต่ละโมดูล (e-KYC, Chat Moderation, Dispute Assistant, Failover) และประเมินความพึงพอใจของผู้ใช้งาน
3. วางรากฐานสถาปัตยกรรมที่ปลอดภัย สอดคล้องกับ พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) และมีระบบสำรอง (Graceful Degradation) เมื่อบริการภายนอกขัดข้อง

---

## ✨ ฟีเจอร์หลัก (Key Features)

### 1. 💰 ระบบบัญชีตัวกลาง (Smart Escrow & Payment)
* **การกักเก็บเงิน (Payment Holding):** รองรับการชำระเงินผ่านบัตรเครดิต/เดบิต และ PromptPay (ผ่าน Omise Webhook) รวมถึงการอัปโหลดสลิปโอนเงินเพื่อตรวจสอบอัตโนมัติ (EasySlip) โดยระบบจะล็อกยอดเงินไว้ที่บัญชีกลางจนกว่าผู้ซื้อจะยืนยันการรับสินค้า
* **สถานะธุรกรรมที่ชัดเจน:** แบ่งสถานะเงินออกเป็น *ยังไม่ชำระเงิน (Unpaid)*, *กักเก็บเงิน (Held)*, *ระงับเงินชั่วคราว (Frozen/Disputed)*, *ปล่อยยอดเงิน (Released)* และ *คืนเงิน (Refunded)*
* **ระบบติดตามพัสดุ:** ผู้ขายระบุบริษัทขนส่ง (เช่น Flash Express, Kerry Express, ไปรษณีย์ไทย) และหมายเลขพัสดุในระบบ

### 2. 🪪 ระบบยืนยันตัวตนอิเล็กทรอนิกส์ (e-KYC)
* **แยกสิทธิ์การเข้าสู่ระบบออกจากตัวตน:** รองรับการเข้าสู่ระบบผ่าน Email/Password และ Google Sign-In (ตรวจสอบ Token ฝั่งเซิร์ฟเวอร์) แต่ต้องผ่าน e-KYC ก่อนจึงจะได้รับตราสัญลักษณ์ยืนยันตัวตน (**Verified Badge**) และปลดล็อกสิทธิ์การขาย
* **OCR & 1:1 Face Verification:** อ่านข้อมูลบัตรประจำตัวประชาชนไทยและเปรียบเทียบใบหน้าแบบ 1:1 กับภาพถ่ายจากกล้องหน้า (ผ่าน AiGen / Sumsub) ที่เกณฑ์ความคล้าย 80 คะแนน
* **PDPA Compliance:** เข้ารหัสไฟล์ภาพบัตรประชาชนและใบหน้า ปกปิดเลขบัตรในบันทึกเหตุการณ์ (Log) และจำกัดสิทธิ์การเข้าถึงเฉพาะผู้ดูแลระบบ

### 3. 🤖 ระบบคัดกรองความเสี่ยงด้วย AI (AI Risk & Fraud Detection)
* **Multi-Layer Chat Moderation (Cascade + Soft-Trigger):**
  * **Tier 1 (Rule-based / Regex):** ตรวจจับเบอร์โทรศัพท์ เลขบัญชีธนาคาร ลิงก์ภายนอก และคำชักชวนโอนเงินนอกระบบแบบทันที
  * **Tier 2 (LLM Escalation):** ส่งต่อข้อความกำกวมหรือข้อความที่เข้าเงื่อนไข *Soft-Trigger* ไปวิเคราะห์บริบทผ่าน LLM ตามลำดับ **Groq $\rightarrow$ OpenRouter $\rightarrow$ Google Gemini**
  * รองรับการอ่านข้อความจากรูปภาพที่แนบในห้องสนทนา (OCR) เพื่อป้องกันการส่งสลิปปลอมหรือคิวอาร์โค้ดชักชวนออกนอกระบบ
* **Product Image Forensics:** ตรวจสอบข้อมูล EXIF เบื้องต้นของรูปสินค้า และส่งภาพที่น่าสงสัยเข้า **Google Cloud Vision** เพื่อคัดกรองภาพหน้าจอ (Screenshot) หรือภาพที่คัดลอกจากแหล่งอื่น

### 4. ⚖️ ระบบข้อพิพาทและผู้ช่วยผู้ดูแลระบบ (Dispute & Human-in-the-Loop)
* เมื่อผู้ซื้อหรือผู้ขายเปิดข้อพิพาท ระบบจะ **ระงับยอดเงินในบัญชีกลางทันที** และเปิดพื้นที่แนบหลักฐานแยกฝั่งผู้ซื้อและผู้ขาย
* **AI Dispute Assistant:** สรุปประวัติห้องสนทนา สถานะพัสดุ และหลักฐาน เพื่อเสนอแนวทางให้ผู้ดูแลระบบ (*คืนเงินผู้ซื้อ*, *ปล่อยเงินผู้ขาย*, *แบ่งสัดส่วน* หรือ *ตรวจสอบเพิ่มเติม*) โดย **ผู้ดูแลระบบยังคงเป็นผู้ตัดสินใจและกดอนุมัติยอดเงิน (Human-in-the-Loop)**

### 5. 🛡️ ความต่อเนื่องของระบบและชั้นสำรอง (Resilience & Failover)
* หากบริการภายนอกล้มเหลว (เช่น Redis, LLM API, e-KYC API หรือ Payment Gateway) ระบบจะสลับไปใช้ชั้นสำรองอัตโนมัติ (เช่น อ่านแชทจาก MySQL แทน Redis, ใช้ผล Tier 1 Rule แทน LLM หรือส่งเอกสารเข้าคิวให้ผู้ดูแลระบบตรวจสอบ) โดยไม่ทำให้ธุรกรรมหลักหยุดชะงัก

---

## 🏗️ สถาปัตยกรรมระบบ (System Architecture)

ระบบถูกออกแบบตามสถาปัตยกรรม 4 ชั้น (4-Tier Web Application Architecture):

```text
+-------------------------------------------------------------------+
| 1. Presentation Layer (Client Browser)                            |
|    - HTML5, CSS3, Vanilla JS, Responsive UI (Light/Dark Theme)    |
|    - Real-time Chat via Server-Sent Events (SSE)                  |
+-------------------------------------------------------------------+
                                 | HTTPS / SSE
+-------------------------------------------------------------------+
| 2. Processing Layer (Apache + PHP)                                |
|    - Controllers (Auth, KYC, Order, Chat, Dispute, Admin)         |
|    - Services (EscrowEngine, ModerationService, eKYC, AI Helper)  |
|    - Webhook Receivers (Omise, e-KYC)                             |
+-------------------------------------------------------------------+
         | SQL / PubSub                              | HTTPS APIs
+-----------------------------------+   +---------------------------+
| 3. Data Layer                     |   | 4. External Services      |
|    - MySQL / MariaDB (Source of   |   |    - Auth: Google Sign-In |
|      Truth for Escrow & Ledger)   |   |    - Pay: Omise, EasySlip |
|    - Redis (Realtime Pub/Sub)     |   |    - KYC: AiGen, Sumsub   |
|    - Storage: Local / R2 / S3     |   |    - AI: Vision, Groq,    |
+-----------------------------------+   |      OpenRouter, Gemini   |
                                        +---------------------------+

```

---

## 📊 ผลการทดลองและประเมินประสิทธิภาพ (Experimental Results)

| โมดูล / การทดสอบ | ชุดข้อมูล / วิธีการทดสอบ | ผลลัพธ์สำคัญ |
| --- | --- | --- |
| **Chat Moderation (Cascade + Soft-Trigger)** | ชุดข้อความจากแชทฉ้อโกงจริงหลัง OCR ($N = 333$) | **Recall: 95.2%**, Accuracy: 93.4%, F1-Score: 0.845 (ลด False Negative จาก 16 เหลือ 3 และจบที่ Tier 1 Rule ได้ **70.6%** ช่วยประหยัดค่าเรียก LLM) |
| **e-KYC Pipeline** | ท่อจำลอง ($N = 100$) และภาพจริง ($N = 4$ คู่) | ท่อสถานะทำงานถูกต้อง **100%** (95% CI: 96.3%–100%), ภาพจริงแยกคลาสเจ้าของจริงและคนละคนได้ครบที่เกณฑ์ 80 |
| **System Failover** | การจำลองความล้มเหลวของบริการภายนอก (10 กรณี) | ชั้นสำรองทำงานสำเร็จ **10/10 กรณี** ระบบไม่หยุดทำงานและไม่มีการปล่อยเงินโดยไม่มีเงื่อนไข |
| **User Satisfaction** | แบบประเมินกลุ่มทดลองใช้งาน ($N = 33$) | คะแนนความพึงพอใจโดยรวมเฉลี่ย **4.30 / 5.00** (ค่าเฉลี่ยรวมทุกด้าน **4.20 / 5.00**, $SD = 0.74$) |

> **หมายเหตุข้อจำกัดของงานวิจัย:** ช่องทาง e-KYC หลักรับภาพนิ่งจากกล้องหน้า (1:1 Face Verification) จึงยังไม่ได้ทดสอบการป้องกันการโจมตีด้วยการนำเสนอภาพ (Presentation Attack / Liveness) ในเส้นทางหลัก และการทดสอบภาคสนามในตลาดจริงขนาดใหญ่เป็นแผนการพัฒนาต่อยอดในอนาคต

---

## 🚀 การติดตั้งและเริ่มต้นใช้งาน (Getting Started)

### ความต้องการของระบบ (Prerequisites)

* **Web Server:** Apache / Nginx
* **PHP:** เวอร์ชัน 8.1 ขึ้นไป (เปิดใช้งาน Extensions: `pdo_mysql`, `curl`, `json`, `mbstring`, `exif`, `openssl`)
* **Database:** MySQL 8.0+ หรือ MariaDB 10.6+
* **Optional Services:** Redis Server (สำหรับแชทเรียลไทม์), Cloudflare R2 / AWS S3 (สำหรับจัดเก็บไฟล์บนคลาวด์)

### ขั้นตอนการติดตั้ง

1. **โคลนโปรเจกต์ (Clone Repository)**
```bash
git clone [https://github.com/your-username/flixo-escrow.git](https://github.com/your-username/flixo-escrow.git)
cd flixo-escrow

```


2. **ตั้งค่าฐานข้อมูล (Database Setup)**
* สร้างฐานข้อมูลใหม่ใน MySQL/MariaDB (เช่น `flixo_db`)
* นำเข้าไฟล์โครงสร้างตาราง (SQL Schema) จากโฟลเดอร์ฐานข้อมูลของโปรเจกต์


3. **ตั้งค่าตัวแปรสภาพแวดล้อม (Environment Configuration)**
คัดลอกไฟล์ตัวอย่างการตั้งค่า และกรอก API Keys ที่เกี่ยวข้อง:
```bash
cp .env.example .env

```


```env
# Database
DB_HOST=127.0.0.1
DB_NAME=flixo_db
DB_USER=root
DB_PASS=

# Authentication
GOOGLE_CLIENT_ID=your_google_client_id

# Payment & Slip Verification
OMISE_PUBLIC_KEY=your_omise_public_key
OMISE_SECRET_KEY=your_omise_secret_key
EASYSLIP_API_KEY=your_easyslip_api_key

# e-KYC & Vision
AIGEN_API_KEY=your_aigen_api_key
GOOGLE_VISION_API_KEY=your_vision_api_key

# LLM Cascade (Groq -> OpenRouter -> Gemini)
GROQ_API_KEY=your_groq_api_key
OPENROUTER_API_KEY=your_openrouter_api_key
GEMINI_API_KEY=your_gemini_api_key

# Realtime (Optional - Fallback to MySQL if disabled)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

```


4. **เริ่มต้นใช้งาน**
* วางโปรเจกต์ไว้ภายใต้ Document Root ของเว็บเซิร์ฟเวอร์ (เช่น `htdocs` หรือ `/var/www/html`)
* ตั้งค่าสิทธิ์การเขียนโฟลเดอร์สำหรับจัดเก็บไฟล์อัปโหลดที่เข้ารหัส (เช่น `storage/uploads`)
* เข้าใช้งานผ่านเว็บเบราว์เซอร์ที่ `http://localhost/flixo-escrow`



---

## 👥 คณะผู้วิจัยและอาจารย์ที่ปรึกษา (Authors & Advisors)

งานวิจัยนี้ได้รับทุนอุดหนุนจาก **มหาวิทยาลัยราชภัฏสวนสุนันทา ปีงบประมาณ 2569**
สาขาวิชาวิศวกรรมคอมพิวเตอร์ คณะวิศวกรรมศาสตร์และเทคโนโลยีอุตสาหกรรม

### คณะผู้วิจัย

* **นายธนพัต ฉุยสุริฉาย** (Thanaphat Chuisurichai)
* **นางสาวณภัทร แซ่ตั้ง** (Napat Saetang)
* **นางสาวอริษรา ราชนิยม** (Arisara Rachaniyom)

### อาจารย์ที่ปรึกษา

* ผู้ช่วยศาสตราจารย์ ดร.พรภวิษย์ บุญศรีเมือง
* ผู้ช่วยศาสตราจารย์ ดร.รวิ อุตตมธนินทร์
* ผู้ช่วยศาสตราจารย์ ดร.ขวัญเรือน รัศมี
* ผู้ช่วยศาสตราจารย์ ดร.เศรษฐกาล โปร่งนุช
* อาจารย์ กานต์ เจริญจิตร
* อาจารย์ ทักษอร อักษรศิลป์

---

## 📄 สัญญาอนุญาต (License)

สงวนลิขสิทธิ์เพื่อการศึกษาและการวิจัย © 2569 คณะผู้วิจัย สาขาวิชาวิศวกรรมคอมพิวเตอร์ มหาวิทยาลัยราชภัฏสวนสุนันทา

