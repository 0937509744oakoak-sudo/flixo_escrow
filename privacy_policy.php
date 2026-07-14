<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/legal.php';
$page_title = 'นโยบายความเป็นส่วนตัว (PDPA)';
$policy_version = PRIVACY_VERSION;
include __DIR__ . '/includes/header.php';
?>
<main class="container" style="max-width:800px;margin:40px auto;padding:0 20px 60px;line-height:1.7;">
    <h1 style="margin-bottom:8px;">นโยบายความเป็นส่วนตัว</h1>
    <p style="color:#666;margin-bottom:24px;">เวอร์ชัน <?php echo safeText($policy_version); ?> — อัปเดตล่าสุด: 11 กรกฎาคม 2026</p>

    <section style="margin-bottom:24px;">
        <h2>1. ผู้ควบคุมข้อมูล</h2>
        <p>FLIXO Escrow (“เรา”) เป็นผู้ควบคุมข้อมูลส่วนบุคคลของท่านตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA)</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>2. ข้อมูลที่เราเก็บรวบรวม</h2>
        <ul>
            <li>ข้อมูลบัญชี: ชื่อผู้ใช้, อีเมล, รหัสผ่าน (เข้ารหัส), ชื่อ-นามสกุล, เบอร์โทรศัพท์</li>
            <li>ข้อมูล KYC: รูปถ่ายบัตรประชาชน (เข้ารหัสเมื่อจัดเก็บ)</li>
            <li>ข้อมูลธุรกรรม: ประวัติการซื้อขาย, สลิปการโอนเงิน, ข้อความแชท</li>
            <li>ข้อมูลทางเทคนิค: IP Address, User Agent (เมื่อให้ความยินยอม)</li>
        </ul>
    </section>

    <section style="margin-bottom:24px;">
        <h2>3. วัตถุประสงค์ในการใช้ข้อมูล</h2>
        <ul>
            <li>ให้บริการแพลตฟอร์มซื้อขายและระบบ Escrow</li>
            <li>ยืนยันตัวตนและป้องกันการฉ้อโกง</li>
            <li>ดำเนินการชำระเงินและคืนเงิน</li>
            <li>แก้ไขข้อพิพาทและให้บริการลูกค้า</li>
            <li>ปฏิบัติตามกฎหมายที่เกี่ยวข้อง</li>
        </ul>
    </section>

    <section style="margin-bottom:24px;">
        <h2>4. ฐานทางกฎหมาย</h2>
        <p>เราประมวลผลข้อมูลบนฐานความยินยอม (Consent) และความจำเป็นเพื่อปฏิบัติตามสัญญา (Contract) ระหว่างท่านกับแพลตฟอร์ม</p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>5. การเก็บรักษาและความปลอดภัย</h2>
        <ul>
            <li>เข้ารหัสข้อมูลระหว่างการรับส่ง (HTTPS/TLS)</li>
            <li>เข้ารหัสข้อมูลสำคัญเมื่อจัดเก็บ (เบอร์โทร, รูปบัตรประชาชน)</li>
            <li>จำกัดสิทธิ์ผู้ดูแลระบบในการเข้าถึงข้อมูลส่วนบุคคล พร้อมบันทึก Audit Log</li>
        </ul>
    </section>

    <section style="margin-bottom:24px;">
        <h2>6. สิทธิของเจ้าของข้อมูล</h2>
        <p>ท่านมีสิทธิขอเข้าถึง แก้ไข ลบ ถอนความยินยอม หรือคัดค้านการประมวลผล โดยติดต่อ <a href="mailto:support@flixo.local">support@flixo.local</a></p>
    </section>

    <section style="margin-bottom:24px;">
        <h2>7. ระยะเวลาเก็บรักษา</h2>
        <p>เก็บข้อมูลตลอดระยะเวลาที่บัญชียังใช้งาน และตามระยะเวลาที่กฎหมายกำหนดหลังปิดบัญชี</p>
    </section>

    <p><a href="register.php">← กลับหน้าสมัครสมาชิก</a></p>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
