<?php
require 'admin_check.php';
adminRequirePermission('users.manage');
$csrf_token = getCsrfToken();
$canManageRoles = Rbac::can($_SESSION['role'] ?? 'user', 'roles.manage');

// --- Admin Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['uid'])) {
    verifyCsrfToken($_POST['csrf_token'] ?? '');
    $uid = (int)$_POST['uid'];
    $action = $_POST['action'];

    // 🎖️ มอบบทบาท (Owner เท่านั้น)
    if ($action == 'set_role') {
        if (!$canManageRoles) {
            http_response_code(403);
            exit('Access Denied — เฉพาะ Owner เท่านั้นที่มอบบทบาทได้');
        }
        $newRole = strtolower(trim($_POST['new_role'] ?? ''));
        $stmtSelf = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmtSelf->bind_param('s', $_SESSION['user']);
        $stmtSelf->execute();
        $selfRow = $stmtSelf->get_result()->fetch_assoc();

        if (!in_array($newRole, Rbac::assignableRoles(), true)) {
            echo "<script>alert('บทบาทไม่ถูกต้อง'); window.location='users_list.php';</script>";
        } elseif ($selfRow && (int)$selfRow['id'] === $uid) {
            echo "<script>alert('เปลี่ยนบทบาทของตัวเองไม่ได้'); window.location='users_list.php';</script>";
        } else {
            $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->bind_param('si', $newRole, $uid);
            $stmt->execute();
            echo "<script>alert('🎖️ เปลี่ยนบทบาทเรียบร้อย!'); window.location='users_list.php';</script>";
        }

    // ✅ อนุมัติ KYC
    } elseif ($action == 'verify') {
        $stmtUser = $conn->prepare('SELECT username FROM users WHERE id = ?');
        $stmtUser->bind_param('i', $uid);
        $stmtUser->execute();
        $userRow = $stmtUser->get_result()->fetch_assoc();

        $stmt = $conn->prepare("UPDATE users SET is_verified = 1, kyc_status = 'approved', kyc_verified_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();

        if ($userRow) {
            require_once __DIR__ . '/../includes/support_chat.php';
            sendSupportMessage(
                $conn,
                $userRow['username'],
                "✅ ยืนยันตัวตน (eKYC) สำเร็จแล้ว!\n\nคุณสามารถถอนเงินและใช้งานฟีเจอร์ครบได้แล้ว หากมีคำถาม พิมพ์ถามในแชทนี้ได้เลย"
            );
        }
        echo "<script>alert('✅ ยืนยันตัวตนเรียบร้อย!'); window.location='users_list.php';</script>";
    
    // ❌ ปฏิเสธ KYC (ฟังก์ชันนี้กลับมาแล้ว)
    } elseif ($action == 'reject') {
        $stmtUser = $conn->prepare('SELECT username FROM users WHERE id = ?');
        $stmtUser->bind_param('i', $uid);
        $stmtUser->execute();
        $userRow = $stmtUser->get_result()->fetch_assoc();

        $stmtImg = $conn->prepare("SELECT id_card_image, selfie_image FROM users WHERE id = ?");
        $stmtImg->bind_param("i", $uid);
        $stmtImg->execute();
        $imgRow = $stmtImg->get_result()->fetch_assoc();
        foreach (['id_card_image', 'selfie_image'] as $col) {
            if (!empty($imgRow[$col])) {
                $imgPath = realpath(__DIR__ . '/../uploads/kyc/' . basename($imgRow[$col]));
                if ($imgPath && is_file($imgPath)) {
                    @unlink($imgPath);
                }
            }
        }
        $stmt = $conn->prepare("UPDATE users SET id_card_image = NULL, selfie_image = NULL, kyc_status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();

        if ($userRow) {
            require_once __DIR__ . '/../includes/support_chat.php';
            sendSupportMessage(
                $conn,
                $userRow['username'],
                "❌ การยืนยันตัวตน (eKYC) ยังไม่ผ่านในรอบนี้\n\nกรุณาตรวจสอบเอกสารแล้วลองส่งใหม่อีกครั้งที่หน้ายืนยันตัวตน หากไม่แน่ใจว่าติดปัญหาตรงไหน พิมพ์ถามเราในแชทนี้ได้เลย"
            );
        }
        echo "<script>alert('❌ ปฏิเสธเอกสารแล้ว! ผู้ใช้จะต้องส่งรูปมาใหม่'); window.location='users_list.php';</script>";

    // 🚫 แบนผู้ใช้
    } elseif ($action == 'ban') {
        $stmt = $conn->prepare("UPDATE users SET status = 'banned' WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        echo "<script>alert('🚫 แบนผู้ใช้เรียบร้อย!'); window.location='users_list.php';</script>";
        
    // ✅ ปลดแบน
    } elseif ($action == 'unban') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        echo "<script>alert('✅ ปลดแบนเรียบร้อย!'); window.location='users_list.php';</script>";
    } 
}
$page_title = 'จัดการสมาชิก';
include 'layout_head.php';
?>

<div class="admin-wrapper">
    <?php include 'sidebar.php'; ?>

    <div class="admin-content">
        <div class="admin-topbar">
            <div>
                <h1>👥 จัดการสมาชิก</h1>
                <p>ตรวจสอบ KYC, อนุมัติเอกสาร และจัดการสถานะบัญชี</p>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ชื่อผู้ใช้</th>
                            <th>บทบาท</th>
                            <th>สถานะ KYC</th>
                            <th>สถานะบัญชี</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    if ($canManageRoles) {
                        // Owner เห็นทุกบัญชี (ยกเว้นตัวเอง) เพื่อมอบบทบาทได้
                        $stmt = $conn->prepare("SELECT id, username, role, is_verified, status, kyc_status, ekyc_provider, id_card_image, selfie_image FROM users WHERE username != ? ORDER BY id DESC");
                        $stmt->bind_param('s', $_SESSION['user']);
                    } else {
                        // Moderator เห็นเฉพาะสมาชิกทั่วไป
                        $stmt = $conn->prepare("SELECT id, username, role, is_verified, status, kyc_status, ekyc_provider, id_card_image, selfie_image FROM users WHERE role = 'user' ORDER BY id DESC");
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $verified_badge = $row['is_verified']
                                ? '<span class="admin-text-success">✅ ยืนยันแล้ว</span>'
                                : '<span class="admin-text-muted">รอตรวจสอบ</span>';
                            $status_badge = $row['status'] === 'active'
                                ? '<span class="admin-text-success">ปกติ</span>'
                                : '<span class="admin-text-danger">🚫 ถูกแบน</span>';
                            $safeUsername = safeText($row['username']);
                            $safeKycStatus = safeText($row['kyc_status']);
                            $safeId = safeText($row['id']);

                            echo "<tr>";
                            echo "<td>#{$safeId}</td>";
                            echo "<td>
                                    <div style='font-weight:600;'>{$safeUsername}</div>
                                    <div class='admin-text-muted'>KYC: {$safeKycStatus} | Provider: " . safeText($row['ekyc_provider'] ?? 'manual') . "</div>
                                   </td>";

                            if ($canManageRoles) {
                                echo "<td><form method='post' action='users_list.php' style='display:flex;gap:4px;align-items:center;'>
                                        <input type='hidden' name='action' value='set_role'>
                                        <input type='hidden' name='uid' value='{$safeId}'>
                                        <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                        <select name='new_role' class='admin-btn admin-btn-sm' style='padding:4px 6px;'>";
                                foreach (Rbac::assignableRoles() as $r) {
                                    $sel = strtolower($row['role']) === $r || ($r === 'owner' && strtolower($row['role']) === 'admin') ? ' selected' : '';
                                    echo "<option value='{$r}'{$sel}>" . safeText(Rbac::roleLabel($r)) . "</option>";
                                }
                                echo "</select>
                                        <button type='submit' class='admin-btn admin-btn-muted admin-btn-sm' onclick=\"return confirm('เปลี่ยนบทบาทผู้ใช้นี้?')\">บันทึก</button>
                                    </form></td>";
                            } else {
                                echo "<td>" . safeText(Rbac::roleLabel($row['role'])) . "</td>";
                            }

                            echo "<td>$verified_badge</td>";
                            echo "<td>$status_badge</td>";
                            echo "<td><div class='admin-action-group'>";

                            if (!$row['is_verified']) {
                                if (!empty($row['id_card_image'])) {
                                    $imagePath = 'get_image.php?type=kyc&user=' . rawurlencode($row['username']) . '&file=' . rawurlencode($row['id_card_image']);
                                    echo "<a href='" . safeText($imagePath) . "' target='_blank' class='admin-btn admin-btn-muted admin-btn-sm'>👁️ บัตร</a>";
                                    if (!empty($row['selfie_image'])) {
                                        $selfiePath = 'get_image.php?type=selfie&user=' . rawurlencode($row['username']) . '&file=' . rawurlencode($row['selfie_image']);
                                        echo "<a href='" . safeText($selfiePath) . "' target='_blank' class='admin-btn admin-btn-info admin-btn-sm'>🤳 Selfie</a>";
                                    }
                                    echo "<form method='post' action='users_list.php'>
                                            <input type='hidden' name='action' value='verify'>
                                            <input type='hidden' name='uid' value='" . safeText($row['id']) . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <button type='submit' class='admin-btn admin-btn-success admin-btn-sm' onclick=\"return confirm('รูปถูกต้องใช่ไหม?')\">✅ อนุมัติ</button>
                                        </form>";
                                    echo "<form method='post' action='users_list.php'>
                                            <input type='hidden' name='action' value='reject'>
                                            <input type='hidden' name='uid' value='" . safeText($row['id']) . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <button type='submit' class='admin-btn admin-btn-danger admin-btn-sm' onclick=\"return confirm('ปฏิเสธเอกสารนี้?')\">❌ ปฏิเสธ</button>
                                        </form>";
                                } else {
                                    echo "<span class='admin-text-muted'>รอส่งเอกสาร</span>";
                                }
                            }

                            if ($row['status'] == 'active') {
                                echo "<form method='post' action='users_list.php'>
                                            <input type='hidden' name='action' value='ban'>
                                            <input type='hidden' name='uid' value='" . safeText($row['id']) . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <button type='submit' class='admin-btn admin-btn-danger admin-btn-sm' onclick=\"return confirm('แน่ใจนะว่าจะแบน?')\">🚫 Ban</button>
                                        </form>";
                            } else {
                                echo "<form method='post' action='users_list.php'>
                                            <input type='hidden' name='action' value='unban'>
                                            <input type='hidden' name='uid' value='" . safeText($row['id']) . "'>
                                            <input type='hidden' name='csrf_token' value='" . safeText($csrf_token) . "'>
                                            <button type='submit' class='admin-btn admin-btn-success admin-btn-sm' onclick=\"return confirm('ปลดแบนผู้ใช้นี้?')\">✅ Unban</button>
                                        </form>";
                            }

                            echo "</div></td></tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='empty'>ไม่มีสมาชิก</td></tr>";
                    }
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'layout_footer.php'; ?>