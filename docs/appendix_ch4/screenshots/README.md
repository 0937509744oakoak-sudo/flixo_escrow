# ภาคผนวก — Screenshot UI (FLIXO Escrow)

ถ่ายจาก local (`http://localhost/flixo_escrow/`) วันที่ 30 ก.ค. 2026  
บัญชี demo: `test2` (Owner / Admin Staff) — ไม่มีรูปบัตรประชาชนหรือใบหน้าจริงในภาพชุดนี้

| ไฟล์ | หน้าจอ | คำอธิบายสั้น |
|------|--------|-------------|
| `ui01_dashboard.png` | Dashboard (ล็อกอินแล้ว) | หน้าหลักตลาด + ค้นหา + ประกาศล่าสุด |
| `ui02_ekyc.png` | `kyc_verify.php` | ฟอร์ม eKYC ขั้นที่ 1 อัปโหลดบัตร (AiGen) |
| `ui03_sell.png` | Dashboard → ลงประกาศขาย | ฟอร์มลงขาย + แบนเนอร์เตือนยังไม่ยืนยัน eKYC |
| `ui04_chat.png` | ห้องคุยคู่สัญญา | แชทกับทีมงาน FLIXO + สถานะข้อพิพาทออเดอร์ |
| `ui05_orders.png` | รายการสั่งซื้อ | รายการออเดอร์ซื้อ/ขายและสถานะ Escrow |
| `ui06_product.png` | `product.php?id=1` | หน้ารายละเอียดสินค้า + ข้อความเงินพักกลาง |
| `ui07_admin.png` | Admin Dashboard | ภาพรวม Escrow / สมาชิก / คิวงาน |
| `ui08_admin_chat_ai.png` | ตรวจแชท (AI) | คิว moderation (Regex + AI tier) |
| `ui09_landing.png` | `index.php` (ยังไม่ล็อกอิน) | Landing แนะนำ Escrow 3 ขั้นตอน |
| `ui10_login.png` | `login.php` | เข้าสู่ระบบ (รหัสผ่าน / Google) |

## หมายเหตุ PDPA / PII

- **ไม่มี** ภาพบัตรประชาชนหรือใบหน้าในชุดนี้
- `ui04_chat.png` / `ui05_orders.png` อาจมีชื่อแสดงจากข้อมูลทดสอบใน DB local
- `ui08_admin_chat_ai.png` มีตัวอย่างข้อความที่ระบบจับได้ (เบอร์/เลขบัญชีจำลองในเทส) — **ก่อนใส่เล่มควรเบลอ** ตัวเลขและชื่อจริงถ้ามี
- ใช้บัญชีทดสอบเท่านั้น ไม่ควร commit ข้อมูล KYC จริง (`data_real/`)
