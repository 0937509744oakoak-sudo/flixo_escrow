<?php
/** Chat modals — rendered outside #app-views so position:fixed works correctly */
?>
<div class="flixo-chat-modals" id="flixo-chat-modals" aria-hidden="true">
<div id="widget-share-product-modal" class="super-chat-modal modern-modal" style="display:none;">
    <div class="modal-header-modern">
        <div class="modal-icon-large">🛍️</div>
        <h4 style="margin: 0;">ส่งประกาศขาย</h4>
        <p style="margin: 5px 0 15px; font-size: 12px; color: #888;">เลือกจากรายการที่คุณลงประกาศไว้ แล้วส่งให้อีกฝ่ายในห้องแชท</p>
    </div>
    <div id="share-product-list" class="share-product-list-wrap">
        <div class="share-product-loading">กำลังโหลดสินค้า...</div>
    </div>
    <div class="modal-actions-modern">
        <button type="button" onclick="toggleShareProductModal()" class="btn-modern-cancel">ปิด</button>
    </div>
</div>

<div id="widget-offer-modal" class="super-chat-modal modern-modal" style="display:none;">
    <div class="modern-input-group">
        <label>รูปภาพ (ทางเลือก)</label>
        <input type="file" id="w-offer-image" accept="image/*" class="modern-input" style="padding: 8px;">
    </div>
    <div class="modal-header-modern">
        <div class="modal-icon-large">📋</div>
        <h4 style="margin: 0;">ตกลงดีลเอง</h4>
        <p style="margin: 5px 0 15px; font-size: 12px; color: #888;">ใช้เมื่อยังไม่ได้ลงประกาศ หรือราคา/รายละเอียดตกลงกันในห้องแชท</p>
    </div>
    <div class="modern-input-group">
        <label>ชื่อสินค้า / รายละเอียด</label>
        <input type="text" id="w-offer-item" placeholder="เช่น ไอโฟน 13 มือสอง สภาพ 90%" class="modern-input">
    </div>
    <div class="modern-input-group">
        <label>ราคาสินค้า (บาท)</label>
        <div class="input-with-icon">
            <span class="currency-symbol">฿</span>
            <input type="number" id="w-offer-price" placeholder="0.00" class="modern-input has-icon">
        </div>
    </div>
    <div class="modal-actions-modern">
        <button type="button" onclick="toggleOfferModal()" class="btn-modern-cancel">ยกเลิก</button>
        <button type="button" onclick="sendWidgetOffer()" class="btn-modern-confirm">ส่งข้อเสนอ</button>
    </div>
</div>

<div id="widget-tracking-modal" class="super-chat-modal" style="display:none;">
    <h4 style="margin-top:0;">แจ้งรายละเอียดการจัดส่ง</h4>
    <input type="hidden" id="tracking-oid">
    <label style="font-size:12px; color:#666; display:block; margin-bottom:5px;">บริการขนส่ง:</label>
    <select id="w-courier" class="modal-input" style="background:#fff; height:40px;">
        <option value="">-- กรุณาเลือกขนส่ง --</option>
        <option value="Thailand Post">ไปรษณีย์ไทย (Thailand Post)</option>
        <option value="Kerry Express">Kerry Express</option>
        <option value="Flash Express">Flash Express</option>
        <option value="J&T Express">J&T Express</option>
        <option value="DHL">DHL</option>
        <option value="Ninja Van">Ninja Van</option>
        <option value="Other">อื่นๆ</option>
    </select>
    <label style="font-size:12px; color:#666; display:block; margin-bottom:5px;">Tracking Number:</label>
    <input type="text" id="w-tracking-num" placeholder="เช่น EB123456789TH" class="modal-input">
    <div style="display:flex; gap:10px; margin-top:10px;">
        <button type="button" onclick="submitTracking()" class="btn-confirm">ยืนยันการส่ง</button>
        <button type="button" onclick="closeTrackingModal()" class="btn-cancel">ยกเลิก</button>
    </div>
</div>

<div id="widget-tracking-timeline-modal" class="super-chat-modal" style="display:none; max-width:420px;">
    <h4 style="margin-top:0;">📍 ติดตามพัสดุ</h4>
    <p style="font-size:13px;color:#666;margin:0 0 12px;line-height:1.5;">
        ยังไม่มีสถานะเรียลไทม์ในแอป — คัดลอกเลขพัสดุแล้วเปิดเว็บเช็คของขนส่งได้เลย
    </p>
    <div id="tracking-timeline-meta" style="font-size:13px;color:#555;margin-bottom:10px;"></div>
    <label style="font-size:12px;color:#666;display:block;margin-bottom:4px;">เลขพัสดุ</label>
    <div style="display:flex;gap:8px;margin-bottom:14px;">
        <input type="text" id="tracking-number-display" readonly class="modal-input" style="margin:0;flex:1;font-family:ui-monospace,monospace;letter-spacing:0.02em;" value="">
        <button type="button" id="tracking-copy-btn" onclick="copyTrackingNumber()" class="btn-confirm" style="white-space:nowrap;min-width:88px;">คัดลอก</button>
    </div>
    <ul id="tracking-timeline-list" style="list-style:none;padding:0;margin:0 0 12px;max-height:160px;overflow:auto;display:none;"></ul>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a id="tracking-external-link" href="#" target="_blank" rel="noopener noreferrer" class="btn-confirm" style="display:none;text-align:center;text-decoration:none;flex:1;">เปิดเว็บเช็คพัสดุ</a>
        <button type="button" onclick="closeTrackingTimelineModal()" class="btn-cancel">ปิด</button>
    </div>
</div>
</div>

<?php include __DIR__ . '/dispute_modal.php'; ?>
