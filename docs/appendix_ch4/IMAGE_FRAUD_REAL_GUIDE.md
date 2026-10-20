# Image Fraud Live — คู่มือเก็บภาพ + รันทดลอง

เป้าหมาย: **20–30 ภาพ** แล้วรัน Live (MOCK=false)

## ขั้นตอนสั้น ๆ

1. ใส่รูปถ่ายจากกล้อง **10–15 ไฟล์** ลง  
   `C:\xampp\htdocs\flixo_escrow\data_real\image_fraud\clean\`
2. สร้างชุด Risky อัตโนมัติ:
   ```bat
   C:\xampp\php\php.exe scripts\prepare_image_fraud_real.php
   ```
3. (แนะนำ) ใส่ Screenshot จริงเพิ่มใน `risky\` อีก 5–10 รูป
4. ตรวจจำนวน / รันทดลอง:
   ```bat
   C:\xampp\php\php.exe tests\test_image_fraud_real.php --count-only
   C:\xampp\php\php.exe tests\test_image_fraud_real.php
   ```
5. ผลจะอยู่ที่ `docs/appendix_ch4/image_fraud_real_results.csv`

รายละเอียดเต็ม: `data_real/image_fraud/README.md`  
(โฟลเดอร์ภาพจริงถูก gitignore — ไม่ commit)
