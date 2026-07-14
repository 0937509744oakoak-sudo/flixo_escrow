<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/legal.php';
$page_title = 'ข้อกำหนดการใช้งาน';
$terms_version = TERMS_VERSION;
include __DIR__ . '/includes/header.php';
?>
<main class="container" style="max-width:800px;margin:40px auto;padding:0 20px 60px;line-height:1.7;">
    <h1 style="margin-bottom:8px;">ข้อกำหนดและเงื่อนไขการใช้งาน</h1>
    <p style="color:#666;margin-bottom:24px;">เวอร์ชัน <?php echo safeText($terms_version); ?> — อัปเดตล่าสุด: 11 กรกฎาคม 2026</p>

    <section style="margin-bottom:24px;">
        <h2>1. การยอมรับข้อกำหนด</h2>
        <p>การสมัครสมาชิกและใช้งาน FLIXO Escrow ถือว่าท่านยอมรับข้อกำหนดนี้ทั้งหมด</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>2. บทบาทของแพลตฟอร์ม</h2>
        <p>FLIXO เป็นตัวกลางล็อกเงิน (Escrow) ระหว่างผู้ซื้อและผู้ขาย ไม่ใช่คู่สัญญาในการซื้อขายสินค้าโดยตรง</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>3. ข้อห้าม</h2>
        <ul>
            <li>ห้ามโอนเงินนอกระบบ Escrow</li>
            <li>ห้ามลงขายสินค้าผิดกฎหมายหรือสินค้าปลอม</li>
            <li>ห้ามใช้บัญชีปลอมหรือข้อมูล KYC ที่ไม่เป็นความจริง</li>
            <li>ห้ามพยายามเข้าถึงข้อมูลผู้อื่นโดยไม่ได้รับอนุญาต</li>
        </ul>
    </section>

    <section style="margin-bottom:24px;">
        <h2>4. การชำระเงินและ Escrow</h2>
        <p>เงินจะถูกล็อกในระบบจนกว่าเงื่อนไขการซื้อขายจะสำเร็จ หรือมีการตัดสินข้อพิพาทโดยผู้ดูแลระบบ</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>5. การยืนยันตัวตน (KYC)</h2>
        <p>เราอาจขอเอกสารยืนยันตัวตนเพื่อความปลอดภัย การปฏิเสธอาจจำกัดฟีเจอร์บางอย่าง เช่น การถอนเงิน</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>6. ข้อพิพาท</h2>
        <p>เมื่อเปิดข้อพิพาท เงินจะถูกระงับชั่วคราว การตัดสินของผู้ดูแลระบบถือเป็นที่สุดในกรอบของแพลตฟอร์ม</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>7. การระงับบัญชี</h2>
        <p>เราสงวนสิทธิ์ระงับหรือยกเลิกบัญชีที่ละเมิดข้อกำหนด โดยไม่จำเป็นต้องแจ้งล่วงหน้าในกรณีฉ้อโกง</p>
    </section>

    <p><a href="register.php">← กลับหน้าสมัครสมาชิก</a></p>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
