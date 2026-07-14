# ระบบข้อพิพาท (Dispute System) - FLIXO

## 📋 ภาพรวม

ระบบข้อพิพาทเป็นหัวใจของ Escrow Marketplace ที่ให้ผู้ซื้อและผู้ขายมีช่องทางในการแก้ไขปัญหาที่เกิดขึ้นระหว่างการทำธุรกรรม เมื่อเกิดข้อพิพาท เงินจะถูกระงับไว้ชั่วคราว จนกว่าแอดมินจะตรวจสอบและตัดสินใจแก้ไข

---

## 🗂️ โครงสร้างไฟล์

```
dispute_system/
├── models/Dispute.php              # Model สำหรับจัดการข้อพิพาท
├── controllers/OrderController.php  # Handler สำหรับ open_dispute action
├── my_disputes.php                 # หน้าดูรายการข้อพิพาทของผู้ใช้
├── dispute_detail.php              # หน้ารายละเอียดข้อพิพาท
├── admin/dispute_list.php          # หน้าจัดการข้อพิพาท (Admin)
└── uploads/disputes/               # Folder เก็บไฟล์หลักฐาน
```

---

## 🗄️ ตารางฐานข้อมูล

### 1. `disputes` - บันทึกข้อพิพาท
```sql
CREATE TABLE disputes (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL UNIQUE,         -- ออเดอร์ที่มีข้อพิพาท
    initiator VARCHAR(64) NOT NULL,                 -- ผู้เปิดข้อพิพาท
    reason VARCHAR(255) NOT NULL,                   -- เหตุผล (ไม่ได้รับสินค้า, ชำรุด, ฯลฯ)
    description LONGTEXT,                           -- รายละเอียดเพิ่มเติม
    status VARCHAR(20) DEFAULT 'PENDING',           -- PENDING, EVIDENCE_REVIEW, RESOLVED
    resolution VARCHAR(20),                         -- REFUND_BUYER, PAYOUT_SELLER, SPLIT
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,                      -- วันที่แอดมินตัดสินใจ
    FOREIGN KEY (order_id) REFERENCES orders(id)
);
```

### 2. `dispute_evidences` - หลักฐานข้อพิพาท
```sql
CREATE TABLE dispute_evidences (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    dispute_id INT UNSIGNED NOT NULL,               -- ข้อพิพาทที่เกี่ยวข้อง
    uploader VARCHAR(64) NOT NULL,                  -- ผู้อัปโหลด
    description TEXT,                               -- คำอธิบายหลักฐาน
    file_path VARCHAR(255) NOT NULL,                -- ที่เก็บไฟล์
    file_type VARCHAR(50),                          -- ประเภท (image, document, video)
    file_size INT,                                  -- ขนาดไฟล์ (bytes)
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id)
);
```

### 3. `dispute_comments` - ความเห็นในข้อพิพาท
```sql
CREATE TABLE dispute_comments (
    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    dispute_id INT UNSIGNED NOT NULL,               -- ข้อพิพาทที่เกี่ยวข้อง
    commenter VARCHAR(64) NOT NULL,                 -- ผู้แสดงความเห็น
    comment LONGTEXT NOT NULL,                      -- เนื้อหา
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispute_id) REFERENCES disputes(id)
);
```

---

## 🔄 ขั้นตอนการจัดการข้อพิพาท

### ขั้นที่ 1: เปิดข้อพิพาท
```
🔴 ผู้ซื้อคลิก "มีปัญหา/แจ้งพิพาท" ใน my_orders.php
    ↓
📋 เลือกเหตุผล: ไม่ได้รับสินค้า, ชำรุด, ไม่ตรงปก, ฯลฯ
    ↓
✍️ เพิ่มรายละเอียด
    ↓
📤 ส่งเรื่องให้แอดมิน
    ↓
⏸️ เงินถูกระงับ (DISPUTE status)
    ↓
📬 ผู้ขายได้รับการแจ้งเตือน
```

### ขั้นที่ 2: เพิ่มหลักฐาน
```
📎 ทั้งผู้ซื้อและผู้ขายสามารถอัปโหลดหลักฐาน:
   - รูปภาพ (JPG, PNG, GIF)
   - เอกสาร (PDF, DOC, DOCX)
   - จำกัด 5 MB ต่อไฟล์
```

### ขั้นที่ 3: แสดงความเห็น
```
💬 ทั้งสองฝ่ายสามารถแสดงความเห็นเพื่อช่วยให้แอดมิน
   เข้าใจสถานการณ์ได้ชัดเจน
```

### ขั้นที่ 4: แอดมินตัดสินใจ
```
⚖️ Admin มี 2 ตัวเลือก:
   1️⃣ คืนเงินให้ผู้ซื้อ (REFUND_BUYER)
      → ผู้ซื้อ: รับคืนเงินแล้ว ✅
      → ผู้ขาย: ไม่ได้โอนเงิน ❌
      
   2️⃣ โอนเงินให้ผู้ขาย (PAYOUT_SELLER)
      → ผู้ซื้อ: เสียเงิน ❌
      → ผู้ขาย: ได้เงินตามที่คาด ✅
```

---

## 📂 ไฟล์และฟังก์ชันหลัก

### 1. **models/Dispute.php**
```php
// Method สำหรับจัดการข้อพิพาท
$dispute = new Dispute($conn);

$dispute->create($order_id, $initiator, $reason, $description);     // สร้างข้อพิพาท
$dispute->findById($dispute_id);                                     // หาข้อพิพาทตามหมายเลข
$dispute->findByOrderId($order_id);                                  // หาข้อพิพาทตามออเดอร์
$dispute->getUserDisputes($username, $limit, $offset);               // ดึงรายการข้อพิพาทของผู้ใช้

$dispute->getEvidences($dispute_id);                                 // ดึงหลักฐาน
$dispute->addEvidence($dispute_id, $uploader, $description, $file);  // เพิ่มหลักฐาน

$dispute->getComments($dispute_id);                                  // ดึงความเห็น
$dispute->addComment($dispute_id, $commenter, $comment);             // เพิ่มความเห็น

$dispute->updateStatus($dispute_id, $status, $resolution);           // อัปเดตสถานะ
$dispute->getPendingCount($username);                                // นับข้อพิพาทรอแก้
```

### 2. **controllers/OrderController.php**
```php
// Action: open_dispute
// อัปเดตออเดอร์สถานะเป็น DISPUTE
// สร้างบันทึกข้อพิพาท
// ส่งการแจ้งเตือนให้ผู้ขาย

if ($action == 'open_dispute') {
    $dispute_id = $disputeModel->create($order_id, $initiator, $reason, $description);
    $orderModel->changeStatus($order_id, $order['status'], OrderStateMachine::DISPUTE, ...);
}
```

### 3. **my_disputes.php**
- แสดงรายการข้อพิพาททั้งหมดของผู้ใช้ (เป็นผู้เปิด หรือ เกี่ยวข้อง)
- สถานะ: ⏳ รอแก้ไข, ✅ แก้ไขแล้ว
- ลิงก์ไปยัง dispute_detail.php

### 4. **dispute_detail.php**
- แสดงรายละเอียดข้อพิพาท
- ให้ดูหลักฐาน (evidences)
- เพิ่มความเห็น (comments)
- อัปโหลดหลักฐานใหม่
- Admin สามารถตัดสินใจ

### 5. **admin/dispute_list.php**
- แสดงรายการข้อพิพาทที่รอแก้ไข (PENDING, EVIDENCE_REVIEW)
- ปุ่มตัดสินใจ:
  - ↩️ คืนเงินผู้ซื้อ
  - 💰 โอนให้ผู้ขาย
- ลิงก์ไปดูรายละเอียด

---

## 💻 ตัวอย่างการใช้งาน

### เปิดข้อพิพาท
```javascript
// ใน my_orders.php
function openDispute(order_id) {
    let formData = new FormData();
    formData.append('action', 'open_dispute');
    formData.append('order_id', order_id);
    formData.append('reason', 'ไม่ได้รับสินค้า');
    formData.append('description', 'สั่งมา 3 วันแล้วยังไม่ได้รับ');
    
    fetch('controllers/OrderController.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            window.location.href = 'dispute_detail.php?dispute_id=' + data.dispute_id;
        }
    });
}
```

### อัปโหลดหลักฐาน
```html
<!-- ใน dispute_detail.php -->
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="evidence_file" accept="image/*,.pdf,.doc,.docx">
    <textarea name="evidence_description">อธิบายหลักฐาน...</textarea>
    <button type="submit">⬆️ อัปโหลด</button>
</form>
```

### Admin ตัดสินใจ
```javascript
function judgeDispute(order_id, action) {
    // action: 'refund_buyer' หรือ 'payout_seller'
    let formData = new FormData();
    formData.append('action', action);
    formData.append('order_id', order_id);
    
    fetch('controllers/DisputeController.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alert(data.message);
            location.reload();
        }
    });
}
```

---

## 🔐 ความปลอดภัย

| ฟีเจอร์ | รายละเอียด |
|--------|-----------|
| **CSRF Token** | ทุก POST request ต้องมี CSRF token |
| **File Upload Validation** | ตรวจสอบประเภทและขนาดไฟล์ |
| **Permission Check** | เฉพาะผู้เกี่ยวข้องเท่านั้นดูได้ |
| **SQL Injection Prevention** | ใช้ Prepared Statements |
| **Input Sanitization** | ทุก input ถูกตรวจสอบและทำความสะอาด |

---

## 📊 Status Flow

```
Order Status                Dispute Status
──────────────────────────────────────────────
CREATED     ──┐
PENDING_PAYMENT ──┐
PAID        ──┤
SHIPPED     ──┴──→ เปิดข้อพิพาท → DISPUTE (Order Status)
                                    ↓
                              PENDING (Dispute)
                                    ↓
                           (Admin ตรวจสอบ)
                                    ↓
                              RESOLVED ✅
                                    ├─→ REFUND_BUYER
                                    │   (ยกเลิก, เงินคืน)
                                    │
                                    └─→ PAYOUT_SELLER
                                        (สมบูรณ์, เงินโอน)
```

---

## 🎯 ประโยชน์

✅ **สำหรับผู้ซื้อ**
- มีช่องทางแก้ไขปัญหาเมื่อได้รับสินค้าไม่ตรงปกหรือไม่ได้รับ
- ได้คืนเงินหากแอดมินตัดสินว่ามีความผิดของผู้ขาย
- ระบบเงินอัดฉะป้องกันการสูญเสียเงิน

✅ **สำหรับผู้ขาย**
- ได้รับการคุ้มครองจากการฟ้องร้องที่ไม่สมควร
- สามารถแสดงความเห็นและหลักฐาน
- เงินยังคงถูกโอนหากแอดมินตัดสินว่าไม่มีความผิด

✅ **สำหรับแพลตฟอร์ม**
- เพิ่มความน่าเชื่อถือให้แพลตฟอร์ม
- ลดการฉ้อโกงทั้งสองฝ่าย
- มีบันทึกครบถ้วนเพื่อใช้อ้างอิง

---

## 🚀 การเข้าถึง

| ประเภท | URL |
|--------|-----|
| **ดูรายการ** | `/my_disputes.php` |
| **รายละเอียด** | `/dispute_detail.php?dispute_id=123` |
| **Admin จัดการ** | `/admin/dispute_list.php` |

---

## 📝 Notes

- เมื่อเปิดข้อพิพาท เงินจะถูกระงับไว้ชั่วคราว ไม่สามารถโอนให้ผู้ขายได้จนกว่าแอดมินตัดสินใจ
- แอดมินจำเป็นต้องตรวจสอบหลักฐานทั้งสองฝ่ายอย่างรอบคอบ
- เมื่อแอดมินตัดสินใจแล้ว ไม่สามารถยกเลิกได้
- ทุก activity จะถูกบันทึกไว้ในตาราง `dispute_comments` เพื่อให้มีความใสแจง

---

**Version**: 1.0  
**Last Updated**: June 2026  
**Status**: ✅ Production Ready
