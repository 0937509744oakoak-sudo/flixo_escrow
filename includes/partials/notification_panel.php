<?php
/** Bell button + dropdown, included from navbar.php's nav-right block */
?>
<div class="notif-bell-wrap">
    <button type="button" class="notif-bell-btn" id="notif-bell-btn" title="การแจ้งเตือน" aria-haspopup="true" aria-expanded="false">
        <span class="notif-bell-icon" aria-hidden="true">🔔</span>
        <span class="notif-bell-badge" id="notif-bell-badge" style="display:none;">0</span>
    </button>
    <div class="notif-dropdown" id="notif-dropdown" aria-hidden="true">
        <div class="notif-dropdown-header">
            <span>การแจ้งเตือน</span>
            <button type="button" id="notif-mark-all-read">อ่านทั้งหมด</button>
        </div>
        <div class="notif-dropdown-list" id="notif-dropdown-list">
            <div class="notif-empty">กำลังโหลด...</div>
        </div>
    </div>
</div>
