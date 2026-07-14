<?php
/** Shared dispute open modal — used from chat offer card & orders tab */
?>
<div id="flixo-dispute-overlay" class="flixo-dispute-overlay" style="display:none;" aria-hidden="true">
    <div id="widget-dispute-modal" class="flixo-dispute-modal" role="dialog" aria-modal="true" aria-labelledby="dispute-modal-title">
        <button type="button" class="flixo-dispute-close" onclick="FlixoDispute.close()" aria-label="ปิด">&times;</button>

        <div class="flixo-dispute-header">
            <div class="flixo-dispute-icon" aria-hidden="true">⚖️</div>
            <h4 id="dispute-modal-title">เปิดข้อพิพาท</h4>
            <p class="flixo-dispute-subtitle">แจ้งปัญหาการซื้อขาย — เงินจะถูก<strong>ระงับใน Escrow</strong>จนกว่าแอดมินตัดสิน</p>
        </div>

        <div class="flixo-dispute-notice">
            <span class="flixo-dispute-notice-icon" aria-hidden="true">ℹ️</span>
            <span>กรุณาเลือกเหตุผลและอธิบายปัญหาให้ชัดเจน คุณสามารถแนบหลักฐานได้ในขั้นตอนถัดไป</span>
        </div>

        <input type="hidden" id="dispute-order-id" value="">

        <div class="flixo-dispute-field">
            <label class="flixo-dispute-label">เหตุผลหลัก</label>
            <div class="flixo-dispute-reasons" role="radiogroup" aria-label="เหตุผลข้อพิพาท">
                <label class="flixo-dispute-reason is-selected">
                    <input type="radio" name="dispute_reason" value="ไม่ได้รับสินค้า" checked>
                    <span class="flixo-dispute-reason-icon">📦</span>
                    <span class="flixo-dispute-reason-text">ไม่ได้รับสินค้า</span>
                </label>
                <label class="flixo-dispute-reason">
                    <input type="radio" name="dispute_reason" value="สินค้าไม่ตรงปก">
                    <span class="flixo-dispute-reason-icon">🔍</span>
                    <span class="flixo-dispute-reason-text">ไม่ตรงปก</span>
                </label>
                <label class="flixo-dispute-reason">
                    <input type="radio" name="dispute_reason" value="สินค้าชำรุด/เสียหาย">
                    <span class="flixo-dispute-reason-icon">💔</span>
                    <span class="flixo-dispute-reason-text">ชำรุด / เสียหาย</span>
                </label>
                <label class="flixo-dispute-reason">
                    <input type="radio" name="dispute_reason" value="ได้รับไม่ครบ/ของปลอม">
                    <span class="flixo-dispute-reason-icon">⚠️</span>
                    <span class="flixo-dispute-reason-text">ไม่ครบ / ของปลอม</span>
                </label>
                <label class="flixo-dispute-reason flixo-dispute-reason-wide">
                    <input type="radio" name="dispute_reason" value="อื่นๆ">
                    <span class="flixo-dispute-reason-icon">✏️</span>
                    <span class="flixo-dispute-reason-text">อื่นๆ (ระบุเพิ่ม)</span>
                </label>
            </div>
        </div>

        <div class="flixo-dispute-field">
            <label class="flixo-dispute-label" for="dispute-description">รายละเอียดเพิ่มเติม</label>
            <textarea id="dispute-description" class="flixo-dispute-textarea" rows="4" maxlength="1000"
                placeholder="อธิบายปัญหา เช่น สั่งเมื่อไหร่ ได้รับอะไร ต่างจากที่ตกลงอย่างไร..."></textarea>
            <div class="flixo-dispute-char-count"><span id="dispute-char-num">0</span> / 1000</div>
        </div>

        <p id="dispute-form-error" class="flixo-dispute-error" style="display:none;" role="alert"></p>

        <div class="flixo-dispute-actions">
            <button type="button" class="flixo-dispute-btn-cancel" onclick="FlixoDispute.close()">ยกเลิก</button>
            <button type="button" id="dispute-submit-btn" class="flixo-dispute-btn-submit" onclick="FlixoDispute.submit()">
                <span class="flixo-dispute-btn-label">ส่งเรื่องข้อพิพาท</span>
            </button>
        </div>
    </div>
</div>
