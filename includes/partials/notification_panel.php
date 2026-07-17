<?php
/** Bell button + dropdown, included from navbar.php's nav-right block */
?>
<div class="notif-bell-wrap">
    <button type="button" class="notif-bell-btn" id="notif-bell-btn" title="การแจ้งเตือน" aria-label="การแจ้งเตือน" aria-haspopup="true" aria-expanded="false">
        <svg class="notif-bell-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
        </svg>
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
