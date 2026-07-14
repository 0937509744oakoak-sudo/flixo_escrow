<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/platform_rules.php';
$page_title = 'ข้อกำหนดการใช้งาน';
$terms_version = TERMS_VERSION;
include __DIR__ . '/includes/header.php';
?>
<main class="container" style="max-width:800px;margin:40px auto;padding:0 20px 60px;line-height:1.7;">
    <h1 style="margin-bottom:8px;">ข้อกำหนดและเงื่อนไขการใช้งาน</h1>
    <p style="color:#666;margin-bottom:24px;">เวอร์ชัน <?php echo safeText($terms_version); ?> — อัปเดตล่าสุด: 14 กรกฎาคม 2026</p>

    <section style="margin-bottom:24px;">
        <h2>1. การยอมรับข้อกำหนด</h2>
        <p>การสมัครสมาชิกและใช้งาน FLIXO Escrow ถือว่าท่านยอมรับข้อกำหนดนี้ทั้งหมด รวมถึงกฎเหล็กสินค้าต้องห้ามและมาตรการความปลอดภัยของแพลตฟอร์ม</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>2. บทบาทของแพลตฟอร์ม</h2>
        <p>FLIXO เป็นตัวกลางล็อกเงิน (Escrow) ระหว่างผู้ซื้อและผู้ขาย ไม่ใช่คู่สัญญาในการซื้อขายสินค้าโดยตรง อย่างไรก็ตาม เรามีสิทธิ์ถอดประกาศ ระงับบัญชี และแจ้งหน่วยงานที่เกี่ยวข้อง หากพบการใช้งานที่ละเมิดกฎหมายหรือข้อกำหนดนี้</p>
    </section>

    <section id="hard-rules" style="margin-bottom:24px;padding:20px;border:1px solid #ffcdd2;background:#fff8f8;border-radius:14px;">
        <h2 style="margin-top:0;color:#b71c1c;">3. กฎเหล็กและสินค้าต้องห้าม</h2>
        <p>เพื่อความปลอดภัยของผู้ใช้และการปฏิบัติตามกฎหมายไทย <strong>ห้ามผู้ใช้งานนำสินค้าต่อไปนี้มาลงขายบนแพลตฟอร์ม</strong></p>
        <ul>
            <?php foreach (flixo_prohibited_item_examples() as $item): ?>
                <li><?php echo safeText($item); ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-bottom:0;">หากพบการละเมิด FLIXO อาจดำเนินการอย่างหนึ่งหรือหลายอย่าง ได้แก่ ถอดประกาศทันที ระงับ/ยกเลิกบัญชี ยึดเงินที่เกี่ยวข้องตามกระบวนการข้อพิพาท และส่งเรื่องต่อให้เจ้าหน้าที่ตามกฎหมาย</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>4. ข้อห้ามในการใช้งานทั่วไป</h2>
        <ul>
            <li>ห้ามโอนเงินนอกระบบ Escrow หรือชักจูงผู้ใช้ติดต่อนอกแพลตฟอร์มเพื่อเลี่ยงการควบคุม</li>
            <li>ห้ามใช้บัญชีปลอมหรือข้อมูล KYC ที่ไม่เป็นความจริง</li>
            <li>ห้ามพยายามเข้าถึงข้อมูลผู้อื่นโดยไม่ได้รับอนุญาต</li>
            <li>ห้ามโพสต์เนื้อหาหลอกลวง คุกคาม หรือก่อให้เกิดอันตรายต่อผู้อื่น</li>
        </ul>
    </section>

    <section style="margin-bottom:24px;">
        <h2>5. การชำระเงินและ Escrow</h2>
        <p>เงินจะถูกล็อกในระบบจนกว่าเงื่อนไขการซื้อขายจะสำเร็จ หรือมีการตัดสินข้อพิพาทโดยผู้ดูแลระบบ</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>6. การยืนยันตัวตน (KYC)</h2>
        <p>เราอาจขอเอกสารยืนยันตัวตนเพื่อความปลอดภัย การปฏิเสธอาจจำกัดฟีเจอร์บางอย่าง เช่น การถอนเงิน</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>7. การตรวจสอบประกาศและมาตรการความปลอดภัย</h2>
        <p>FLIXO อาจตรวจสอบรูปภาพ ข้อความแชท และรายละเอียดประกาศด้วยระบบอัตโนมัติและ/หรือเจ้าหน้าที่ เพื่อคัดกรองสินค้าต้องห้ามและพฤติกรรมเสี่ยง ประกาศที่น่าสงสัยอาจถูกซ่อน ระงับ หรือส่งตรวจเพิ่มเติม</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>8. ข้อพิพาท</h2>
        <p>เมื่อเปิดข้อพิพาท เงินจะถูกระงับชั่วคราว การตัดสินของผู้ดูแลระบบถือเป็นที่สุดในกรอบของแพลตฟอร์ม</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>9. การระงับบัญชี</h2>
        <p>เราสงวนสิทธิ์ระงับหรือยกเลิกบัญชีที่ละเมิดข้อกำหนด โดยไม่จำเป็นต้องแจ้งล่วงหน้าในกรณีฉ้อโกง สินค้าผิดกฎหมาย หรือความเสี่ยงต่อความปลอดภัยของผู้ใช้</p>
    </section>

    <p><a href="index.php">← กลับหน้าแรก</a> · <a href="register.php">สมัครสมาชิก</a></p>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
