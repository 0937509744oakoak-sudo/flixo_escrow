# Figure 1 — Representative Fraud & Benign Chat Examples

**Purpose:** Short, anonymized utterance pairs for the paper figure illustrating (a) normal trade flow (`ALLOW`) versus (b) off-platform circumvention (`BLOCK`).  
**Source:** `dataset/benchmark_dataset.json` (OCR from 100 Thai C2C fraud screenshots).  
**Privacy:** Identifiers already replaced with `[BANK_ACCOUNT]`, `[PHONE_NUM]`, `[LINE_ID]`, `[PROMPTPAY]`; remaining personal names in display text are further masked as `[NAME]` for figure use.

---

## Suggested Figure Layout (3 panels)

```text
┌─────────────────────────────┐  ┌─────────────────────────────┐  ┌─────────────────────────────┐
│ (a) Benign inquiry          │  │ (b) Direct payment bait     │  │ (c) Account / contact bait  │
│     ground_truth = ALLOW    │  │     ground_truth = BLOCK    │  │     ground_truth = BLOCK    │
└─────────────────────────────┘  └─────────────────────────────┘  └─────────────────────────────┘
```

---

## Case A — Benign / Normal Trade Flow (`ALLOW`)

These turns look like ordinary Marketplace bargaining or logistics. They motivate keeping **Tier~1 local** for the majority of traffic.

| ID | Category | Display text (anonymized) |
|----|----------|---------------------------|
| `FRAUD_002_INQUIRY_4` | `normal_inquiry` | ขายเท่าไหร่ |
| `FRAUD_002_INQUIRY_3` | `normal_inquiry` | ราคารวมส่งเท่าไหร่ |
| `FRAUD_008_INQUIRY_2` | `normal_inquiry` | รอบส่งของกี่โมงอ่ะครับ |
| `FRAUD_001_INQUIRY` | `normal_inquiry` | ครับพี่ถ้าส่งแล้วผมจะแจ้งเลขให้นะครับ |

**Panel copy (recommended 2 bubbles):**
1. Buyer: *ขายเท่าไหร่*  
2. Seller: *ราคารวมส่งเท่าไหร่* → reply context: *รวมส่งก็ 3800 ครับพี่ไม่บวกเพิ่มครับ* (`FRAUD_002_INQUIRY_2`)

---

## Case B — Direct Off-Platform Payment (`BLOCK`)

| ID | Category | Display text (anonymized) |
|----|----------|---------------------------|
| `FRAUD_003_PAYMENT_4` | `bank_account_share` / payment request | โอนเข้าบัญชีนี้แทนนะคะ ยอดโอน 420 บาทค่ะ |
| `FRAUD_001_PAYMENT` | `slip_request` | โอนแล้วแจ้งสลิป พร้อมที่อยู่ นะครับ |
| `FRAUD_003_PAYMENT_3` | `slip_request` | โอนเสร็จแล้วแจ้งสลิปมาด้วยนะคะ * ขอบคุณค่ะ |

**Panel copy (recommended):**
1. *โอนเข้าบัญชีนี้แทนนะคะ ยอดโอน 420 บาทค่ะ*  
2. *โอนแล้วแจ้งสลิป พร้อมที่อยู่ นะครับ*

---

## Case C — Bank Account / Line Contact Sharing (`BLOCK`)

| ID | Category | Display text (anonymized for figure) |
|----|----------|--------------------------------------|
| `FRAUD_003_PAYMENT` | `bank_account_share` | ธนาคาร : ไทยพาณิชย์ เลขบัญชี : `[BANK_ACCOUNT]` ชื่อบัญชี : `[NAME]` |
| `FRAUD_043_PAYMENT` | `line_contact_request` | … เปลี่ยนชื่อได้ … คน id: `[LINE_ID]` |
| `FRAUD_011_PAYMENT_3` | `bank_account_share` | ขอเลขบัญชีไว้เลยครับ THAI QR PAYMENT |

**Panel copy (recommended):**
1. *ธนาคาร : ไทยพาณิชย์ เลขบัญชี : [BANK_ACCOUNT] ชื่อบัญชี : [NAME]*  
2. *ขอเลขบัญชีไว้เลยครับ THAI QR PAYMENT*  
3. *(optional)* listing bait ending with `id: [LINE_ID]`

---

## Narrative Arc for the Figure (ties to the ~85% insight)

Use Cases A→B (or A→C) as a **single storyboard**:

1. Early turns = normal price/shipping talk (`ALLOW`) — cheap Tier~1 exit.  
2. Later turns = account / slip / Line bait (`BLOCK`) — escalate only if mid-score, else regex catch.

This visual supports the claim that LLM-on-every-message is wasteful under real Thai C2C fraud dialogue structure.

---

## Figure Caption (English, camera-ready)

**Fig. 1.** Representative anonymized Thai C2C chat utterances from the OCR fraud-screenshot corpus (\(N=333\)).  
**(a)** Benign marketplace inquiry and logistics (`ALLOW`), which dominate early turns of fraud conversations.  
**(b)** Direct off-platform payment instructions and slip requests (`BLOCK`).  
**(c)** Bank-account / QR / Line-contact sharing (`BLOCK`).  
Personal identifiers are replaced with placeholders (`[BANK_ACCOUNT]`, `[LINE_ID]`, `[PHONE_NUM]`, `[NAME]`).  
These examples motivate a cascaded design that resolves most benign traffic locally and reserves cloud LLM inference for ambiguous or high-risk mid-score messages.

---

## Thai caption (optional, for slides)

**รูปที่ 1** ตัวอย่างข้อความแชท C2C ภาษาไทยจากชุดภาพฉ้อโกงจริง (หลัง anonymize)  
(ก) สอบถามราคา/การส่งของปกติ (`ALLOW`)  
(ข) สั่งโอนตรงและขอสลิป (`BLOCK`)  
(ค) แชร์เลขบัญชี / QR / Line ID (`BLOCK`)  
สะท้อนว่าช่วงต้นมักเนียนเป็นซื้อขายปกติ จึงไม่จำเป็นต้องเรียก LLM ทุกข้อความ

---

## Designer Notes

- Prefer **short bubbles**; avoid long OCR dumps with UI chrome.  
- Do **not** show raw account digits, phones, or Line handles—even if present in source screenshots.  
- Keep Thai script readable at slide/paper column width (2–3 lines max per bubble).  
- Source image filenames are recorded in the JSON `source_image` field if a visual collage from originals is needed later (blur faces / crop PII first).
