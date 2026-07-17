# FLIXO — Backlog / TODO

สถาปัตยกรรม Stateless (SSE + Redis Pub/Sub + S3/R2 Object Storage) เสร็จสมบูรณ์แล้ว
ไฟล์นี้ใช้จดงานที่ตั้งใจ "ยกไป Backlog" ไว้ทำภายหลัง เพื่อไม่ให้ตกหล่น

---

## 1. ลบ Orphan Objects บน Cloud Storage (R2 / S3)

**ปัญหา:** เมื่อผู้ใช้ลบสินค้า / ยกเลิกออเดอร์ / เปลี่ยนรูป ไฟล์เก่าบน R2/S3
จะยังค้างอยู่ (orphan) เพราะปัจจุบันเราลบเฉพาะ record ใน DB แต่ไม่ได้ลบ object
บน cloud → เปลืองพื้นที่และค่าใช้จ่ายในระยะยาว

**สิ่งที่ต้องทำ:**
- [ ] เพิ่มการเรียก `ObjectStorage::delete()` ในทุกจุดที่ลบ/แทนที่รูป
      (products, product_images, chat images) โดยทำแบบ best-effort (ไม่ให้ล้ม flow หลัก)
- [ ] เขียน CLI reconciler `scripts/cleanup_orphan_objects.php`:
      - ไล่ list objects ในแต่ละ prefix (`products/`, `chat/`, ...)
      - cross-check กับค่าที่อ้างอิงจริงใน DB
      - object ที่ไม่มี reference + เก่ากว่า N วัน → ลบ (มี `--dry-run` เป็น default)
- [ ] **ข้อควรระวัง:** slips (`slips/`, payout slips) ห้ามลบตาม retention policy /
      ข้อกำหนดทางกฎหมาย — ต้อง exclude prefix เหล่านี้ออกจาก cleanup

## 2. ย้ายรูป KYC ขึ้น Cloud Storage (Private)

**ปัญหา:** รูป KYC (บัตรประชาชน / selfie) ยังเก็บใน Local Storage → ขวาง
Horizontal Scaling และเป็นข้อมูล PII/sensitive ที่ต้องเก็บแบบ private เท่านั้น

**สิ่งที่ต้องทำ:**
- [ ] ปรับ upload flow ของ KYC ให้ใช้ `secureUploadCloud(..., public=false)`
      เก็บเป็น **object key** (ไม่ใช่ public URL) prefix `kyc/`
- [ ] ปรับจุดแสดงผล/ตรวจสอบ KYC (admin) ให้ stream ผ่าน authorized endpoint
      โดยดึงจาก cloud ด้วย `ObjectStorage::get()` (pattern เดียวกับ SlipController)
- [ ] เขียน backfill script `scripts/migrate_kyc_to_r2.php` (idempotent, dry-run,
      batch limit) — เน้นความปลอดภัยของ PII เป็นหลัก
- [ ] ทบทวน field ใน DB (เช่น `users.id_card_image`, `users.selfie_image`) ว่า
      รองรับค่า object key ได้ และมี fallback อ่าน Local ของเดิม

---

_อัปเดตล่าสุด: 2026-07-17_
