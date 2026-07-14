<?php

/** Profile tab: account, wallet, security */

$csrf_token = getCsrfToken();
$profile_stats = $userModel->getReviewStats($me);

$avg_score = number_format($profile_stats['avg_score'] ?? 0, 1);

$total_reviews = (int) ($profile_stats['total_reviews'] ?? 0);

$display_name = User::resolveDisplayName($user['real_name'] ?? '', $me);

?>

<div class="flixo-card profile-hero-card">

    <div class="profile-avatar-lg"><?php echo strtoupper(mb_substr($display_name, 0, 1)); ?></div>

    <h2 style="margin:0 0 4px;font-family:'Hanken Grotesk',sans-serif;"><?php echo htmlspecialchars($display_name); ?></h2>

    <p class="tx-meta">รหัสบัญชี (ใช้เข้าสระบบ): @<?php echo htmlspecialchars($me); ?></p>

    <p class="tx-meta">เข้าร่วมเมื่อ: <?php echo date('d/m/Y', strtotime($user['created_at'] ?? 'now')); ?></p>



    <?php if (!empty($user['is_verified'])): ?>

        <p style="margin:12px 0;"><span class="pill-success">ยืนยันตัวตนแล้ว</span></p>

    <?php else: ?>

        <p style="margin:12px 0;"><a href="kyc_verify.php" class="btn-flixo-primary" style="display:inline-block;padding:8px 20px;">ยืนยันตัวตน</a></p>

    <?php endif; ?>



    <div class="profile-stats-row">

        <div class="profile-stat">

            <strong><?php echo $avg_score; ?></strong>

            <span class="tx-meta">คะแนนเฉลี่ย</span>

        </div>

        <div class="profile-stat">

            <strong><?php echo $total_reviews; ?></strong>

            <span class="tx-meta">รีวิวทั้งหมด</span>

        </div>

    </div>



    <?php if (!empty($_GET['logout_error']) && $_GET['logout_error'] === 'csrf'): ?>
        <p class="tx-meta" style="color:#ba1a1a;margin:12px 0 0;">ไม่สามารถออกจากระบบได้ กรุณารีเฟรชหน้าแล้วลองใหม่</p>
    <?php endif; ?>

    <form method="post" action="logout.php" style="margin:20px 0 0;text-align:center;">

        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">

        <button type="submit" class="btn-flixo-outline" style="padding:10px 20px;color:#ba1a1a;border-color:#ba1a1a;">ออกจากระบบ</button>

    </form>

</div>



<div class="flixo-card">

    <div class="card-header-flex">

        <div>

            <h3>ชื่อที่แสดงต่อผู้อื่น</h3>

            <p class="tx-meta" style="margin:4px 0 0;">ชื่อนี้จะปรากฏในแชท ประกาศขาย และออเดอร์ — ไม่ใช่รหัสเข้าสระบบ</p>

        </div>

    </div>

    <form id="profile-display-name-form" class="profile-name-form" style="display:flex;flex-direction:column;gap:12px;margin-top:12px;">

        <input type="hidden" name="csrf_token" value="<?php echo safeText($csrf_token); ?>">

        <label class="tx-meta" for="profile-real-name">ชื่อ-นามสกุล</label>

        <input type="text" id="profile-real-name" name="real_name" maxlength="100" required

               value="<?php echo htmlspecialchars($user['real_name'] ?? ''); ?>"

               class="profile-name-input"

               placeholder="เช่น สมชาย ใจดี"

               style="padding:12px 14px;border:1px solid rgba(0,0,0,0.12);border-radius:12px;font-size:15px;">

        <p id="profile-name-msg" class="tx-meta" style="margin:0;display:none;"></p>

        <button type="submit" class="btn-flixo-primary" style="align-self:flex-start;padding:10px 20px;">บันทึกชื่อ</button>

    </form>

</div>



<div class="flixo-card">

    <div class="card-header-flex">

        <div>

            <h3>รีวิวจากผู้ซื้อ</h3>

            <p class="tx-meta" style="margin:4px 0 0;">ความคิดเห็นจากการซื้อขายที่สำเร็จแล้ว</p>

        </div>

    </div>

    <?php
    $reviews = $profile_reviews ?? [];
    $empty_message = 'ยังไม่มีรีวิว — เมื่อมีคนซื้อและออเดอร์สำเร็จ รีวิวจะแสดงที่นี่';
    include __DIR__ . '/../partials/review_list.php';
    ?>

</div>



<div class="flixo-card" id="balance-widget-focus">

    <div class="card-header-flex">

        <div>

            <h3>กระเป๋าเงิน</h3>

            <p class="tx-meta" style="margin:4px 0 0;">ยอดเงินที่ถอนได้และเงินใน Escrow</p>

        </div>

    </div>

    <p class="tx-meta wallet-label">ยอดเงินที่ถอนได้</p>

    <div class="wallet-balance-lg">฿<?php echo number_format($withdrawable, 2); ?></div>

    <?php if ($money_pending > 0): ?>

        <p class="tx-meta wallet-escrow-note">เงินใน Escrow: ฿<?php echo number_format($money_pending, 2); ?></p>

    <?php endif; ?>

    <div class="wallet-actions-row">

        <a href="earnings.php" class="btn-flixo-primary">เปิดกระเป๋าเงิน</a>

    </div>

</div>



<div class="flixo-card profile-pdpa-card">

    <div class="card-header-flex">

        <div>

            <h3>ความเป็นส่วนตัว (PDPA)</h3>

            <p class="tx-meta" style="margin:4px 0 0;">จัดการสิทธิข้อมูลส่วนบุคคลตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล</p>

        </div>

    </div>

    <ul class="profile-pdpa-list">

        <li>ดาวน์โหลดข้อมูลของฉัน</li>

        <li>ถอนความยินยอม KYC</li>

        <li>ขอลบบัญชี / ดูประวัติความยินยอม</li>

    </ul>

    <div class="profile-pdpa-actions">

        <a href="privacy.php" class="btn-flixo-primary profile-pdpa-btn">

            <span class="material-symbols-outlined" aria-hidden="true">privacy_tip</span>

            เปิดการตั้งค่าความเป็นส่วนตัว

        </a>

        <a href="privacy_policy.php" class="btn-flixo-outline profile-pdpa-btn" target="_blank" rel="noopener">

            อ่านนโยบายความเป็นส่วนตัว

        </a>

    </div>

</div>

<script>
(function () {
    var form = document.getElementById('profile-display-name-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = document.getElementById('profile-name-msg');
        var fd = new FormData(form);
        fd.append('action', 'update_display_name');

        fetch('<?php echo BASE_URL; ?>/controllers/UserController.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!msg) return;
                msg.style.display = 'block';
                if (data.status === 'success') {
                    msg.style.color = '#1b5e20';
                    msg.textContent = 'บันทึกชื่อเรียบร้อยแล้ว';
                    var heroTitle = document.querySelector('.profile-hero-card h2');
                    if (heroTitle && data.display_name) {
                        heroTitle.textContent = data.display_name;
                    }
                    var avatar = document.querySelector('.profile-avatar-lg');
                    if (avatar && data.display_name) {
                        avatar.textContent = data.display_name.charAt(0).toUpperCase();
                    }
                    var greeting = document.querySelector('.hero-greeting h1');
                    if (greeting && data.display_name) {
                        greeting.textContent = 'สวัสดี, ' + data.display_name;
                    }
                } else {
                    msg.style.color = '#ba1a1a';
                    msg.textContent = data.message || 'ไม่สามารถบันทึกได้';
                }
            })
            .catch(function () {
                if (msg) {
                    msg.style.display = 'block';
                    msg.style.color = '#ba1a1a';
                    msg.textContent = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
                }
            });
    });
})();
</script>

