/**
 * FLIXO Chat — dashboard app shell
 */

let currentPartner = '';
let chatInterval;

const CHAT_CONTROLLER_URL = (() => {
    if (window.FLIXO_BASE_URL) {
        return String(window.FLIXO_BASE_URL).replace(/\/$/, '') + '/controllers/ChatController.php';
    }
    const script = document.currentScript || document.querySelector('script[src*="chat.js"]');
    if (script && script.src) {
        return script.src.replace(/\/assets\/js\/chat\.js(?:\?.*)?$/, '') + '/controllers/ChatController.php';
    }
    return window.location.origin + '/controllers/ChatController.php';
})();

function appendCsrfToken(formData) {
    if (!formData) return;
    if (typeof window.CSRF_TOKEN !== 'undefined' && window.CSRF_TOKEN && !formData.has('csrf_token')) {
        formData.append('csrf_token', window.CSRF_TOKEN);
    }
}

function chatFetch(formData) {
    appendCsrfToken(formData);
    return fetch(CHAT_CONTROLLER_URL, {
        method: 'POST',
        body: formData,
        credentials: 'include',
        headers: {
            'X-CSRF-Token': window.CSRF_TOKEN || ''
        }
    });
}

function syncModalBackdrop() {
    const modalIds = ['widget-share-product-modal', 'widget-offer-modal', 'widget-tracking-modal', 'widget-tracking-timeline-modal', 'flixo-dispute-overlay'];
    const anyOpen = modalIds.some(function (id) {
        const el = document.getElementById(id);
        if (!el) return false;
        return el.classList.contains('is-open')
            || el.style.display === 'block'
            || el.style.display === 'flex';
    });
    document.body.classList.toggle('chat-modal-open', anyOpen);
    const root = document.getElementById('flixo-chat-modals');
    if (root) {
        root.setAttribute('aria-hidden', anyOpen ? 'false' : 'true');
    }
}

function closeAllModals() {
    const offerModal = document.getElementById('widget-offer-modal');
    const trackingModal = document.getElementById('widget-tracking-modal');
    const timelineModal = document.getElementById('widget-tracking-timeline-modal');
    const shareModal = document.getElementById('widget-share-product-modal');

    if (offerModal) offerModal.style.display = 'none';
    if (trackingModal) trackingModal.style.display = 'none';
    if (timelineModal) timelineModal.style.display = 'none';
    if (shareModal) {
        shareModal.style.display = 'none';
        shareModal.classList.remove('is-open');
    }
    syncModalBackdrop();
}

function openChat(partner) {
    currentPartner = partner;
    closeAllModals();

    const chatTitle = document.getElementById('chat-title');
    if (chatTitle) {
        chatTitle.innerText = getPartnerDisplayName(partner);
    }

    const partnerAvatar = document.getElementById('chat-partner-avatar');
    if (partnerAvatar) {
        const displayName = getPartnerDisplayName(partner);
        const initial = isSupportPartner(partner) ? '🛡️' : displayName.charAt(0).toUpperCase();
        const dot = partnerAvatar.querySelector('.online-dot');
        partnerAvatar.textContent = initial;
        if (dot) partnerAvatar.appendChild(dot);
    }

    const chatBody = document.getElementById('chat-body');
    if (chatBody) {
        chatBody.innerHTML = '<div class="chat-loading"><div class="chat-loading-spinner"></div><p>กำลังโหลดข้อความ...</p></div>';
    }

    fetchMessages();

    if (chatInterval) clearInterval(chatInterval);
    chatInterval = setInterval(fetchMessages, 3000);

    syncSupportChatUI();
}

function fetchMessages() {
    if (!currentPartner) return;

    const formData = new FormData();
    formData.append('action', 'fetch');
    formData.append('partner', currentPartner);
    appendCsrfToken(formData);

    chatFetch(formData)
        .then(response => response.text())
        .then(data => {
            const body = document.getElementById('chat-body');
            if (!body) return;
            if (body.innerHTML !== data) {
                const isAtBottom = body.scrollHeight - body.scrollTop <= body.clientHeight + 150;
                body.innerHTML = data;
                if (isAtBottom || body.scrollTop === 0) body.scrollTop = body.scrollHeight;
            }
        })
        .catch(console.error);
}

function sendMessage() {
    const input = document.getElementById('full-chat-input');
    if (!input) return;

    const msg = input.value.trim();
    if (!msg || !currentPartner) return;

    const formData = new FormData();
    formData.append('action', 'send');
    formData.append('partner', currentPartner);
    formData.append('message', msg);
    appendCsrfToken(formData);

    input.value = '';

    chatFetch(formData)
        .then(async (response) => {
            const text = await response.text();
            let data = null;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = null;
            }

            if (!response.ok) {
                input.value = msg;
                const errMsg = (data && data.message) ? data.message : text;
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่สามารถส่งข้อความได้',
                        text: errMsg || 'ข้อความมีเนื้อหาที่อาจละเมิดนโยบายความปลอดภัย กรุณาซื้อขายผ่านระบบ Escrow'
                    });
                } else {
                    alert(errMsg || 'ไม่สามารถส่งข้อความได้');
                }
                return;
            }

            fetchMessages();

            const mod = data && data.moderation;
            if (mod && mod.verdict === 'flag' && typeof Swal !== 'undefined') {
                const reasons = (mod.reasons && mod.reasons.length)
                    ? mod.reasons.join('; ')
                    : 'ข้อความนี้อาจละเมิดนโยบายความปลอดภัย';
                Swal.fire({
                    icon: 'info',
                    title: 'คำเตือนความปลอดภัย',
                    text: reasons + ' — ข้อความถูกส่งแล้วและอยู่ในคิวตรวจสอบของทีมงาน',
                    confirmButtonText: 'รับทราบ'
                });
            }
        })
        .catch(err => {
            input.value = msg;
            console.error('Send error:', err);
        });
}

function toggleShareProductModal() {
    const modal = document.getElementById('widget-share-product-modal');
    if (!modal) return;

    const isOpen = modal.classList.contains('is-open');
    if (isOpen) {
        modal.classList.remove('is-open');
        modal.style.display = 'none';
        syncModalBackdrop();
        return;
    }

    closeAllModals();
    modal.classList.add('is-open');
    modal.style.display = 'flex';
    syncModalBackdrop();
    loadShareProducts();
}

function loadShareProducts() {
    const list = document.getElementById('share-product-list');
    if (!list) return;

    list.innerHTML = '<div class="share-product-loading">กำลังโหลดสินค้า...</div>';

    const formData = new FormData();
    formData.append('action', 'fetch_my_products');
    appendCsrfToken(formData);

    chatFetch(formData)
        .then(res => res.text())
        .then(html => { list.innerHTML = html; })
        .catch(() => { list.innerHTML = '<div class="share-product-empty"><p>โหลดรายการไม่สำเร็จ</p></div>'; });
}

function sendShareProduct(productId) {
    if (!currentPartner || !productId) return;

    const formData = new FormData();
    formData.append('action', 'share_product');
    formData.append('partner', currentPartner);
    formData.append('product_id', productId);
    appendCsrfToken(formData);

    chatFetch(formData)
        .then(res => res.text())
        .then(text => {
            if (text.trim() === 'success') {
                closeAllModals();
                fetchMessages();
            } else {
                alert(text || 'ไม่สามารถส่งประกาศได้');
            }
        })
        .catch(() => alert('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้'));
}

function toggleOfferModal() {
    const modal = document.getElementById('widget-offer-modal');
    if (!modal) return;

    const isOpen = modal.style.display === 'block';
    if (isOpen) {
        modal.style.display = 'none';
        syncModalBackdrop();
        return;
    }

    closeAllModals();
    modal.style.display = 'block';
    syncModalBackdrop();
}

function sendWidgetOffer() {
    const item = document.getElementById('w-offer-item').value;
    const price = document.getElementById('w-offer-price').value;
    const imageFile = document.getElementById('w-offer-image').files[0];

    if (!item || !price) {
        alert('กรุณากรอกข้อมูลให้ครบ');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'create_offer');
    formData.append('partner', currentPartner);
    formData.append('item', item);
    formData.append('price', price);
    if (imageFile) formData.append('offer_image', imageFile);
    appendCsrfToken(formData);

    const btn = document.querySelector('#widget-offer-modal .btn-modern-confirm');
    if (!btn) return;
    const oldText = btn.innerText;
    btn.innerText = 'กำลังส่ง...';
    btn.disabled = true;

    chatFetch(formData)
        .then(res => res.text())
        .then(() => {
            closeAllModals();
            fetchMessages();
            document.getElementById('w-offer-item').value = '';
            document.getElementById('w-offer-price').value = '';
            document.getElementById('w-offer-image').value = '';
            btn.innerText = oldText;
            btn.disabled = false;
        });
}

function sendChatImage(input) {
    if (!input.files[0] || !currentPartner) return;

    const formData = new FormData();
    formData.append('action', 'send_image');
    formData.append('partner', currentPartner);
    formData.append('chat_image', input.files[0]);
    appendCsrfToken(formData);

    const body = document.getElementById('chat-body');
    if (body) {
        body.innerHTML += '<div style="text-align:right; color:#007bff; font-size:12px; padding:10px;">กำลังอัปโหลดรูปภาพ...⏳</div>';
        body.scrollTop = body.scrollHeight;
    }

    chatFetch(formData)
        .then(async (response) => {
            if (!response.ok) {
                const text = await response.text();
                input.value = '';
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ไม่สามารถส่งรูปภาพได้',
                        text: text || 'รูปภาพมีเนื้อหาที่อาจละเมิดนโยบายความปลอดภัย'
                    });
                } else {
                    alert(text || 'ไม่สามารถส่งรูปภาพได้');
                }
                fetchMessages();
                return;
            }
            input.value = '';
            fetchMessages();
        });
}

function updateOrderStatus(oid, type) {
    const titles = {
        confirm_offer: 'ยืนยันรับดีล?',
        complete_order: 'ยืนยันได้รับสินค้า?'
    };
    const texts = {
        confirm_offer: 'ระบบจะพาคุณไปหน้าชำระเงิน',
        complete_order: 'ระบบจะโอนเงินให้ผู้ขาย'
    };
    Swal.fire({
        title: titles[type] || 'ยืนยันรายการ?',
        text: texts[type] || 'คุณต้องการดำเนินการต่อหรือไม่',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#007bff',
        confirmButtonText: 'ยืนยัน'
    }).then((result) => {
        if (!result.isConfirmed) return;

        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('oid', oid);
        formData.append('type', type);
        appendCsrfToken(formData);

        chatFetch(formData)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (data.command === 'redirect') {
                        window.location.href = data.url;
                    } else if (data.command === 'reload_chat') {
                        fetchMessages();
                        Swal.fire('สำเร็จ', 'อัปเดตสถานะเรียบร้อย', 'success');
                    }
                } else {
                    Swal.fire('ผิดพลาด', data.message || 'เกิดข้อผิดพลาด', 'error');
                }
            })
            .catch(console.error);
    });
}

function openTrackingModal(oid) {
    document.getElementById('tracking-oid').value = oid;
    closeAllModals();
    document.getElementById('widget-tracking-modal').style.display = 'block';
    syncModalBackdrop();
}

function closeTrackingModal() {
    document.getElementById('widget-tracking-modal').style.display = 'none';
    syncModalBackdrop();
}

function submitTracking() {
    const oid = document.getElementById('tracking-oid').value;
    const courierEl = document.getElementById('w-courier');
    const trackEl = document.getElementById('w-tracking-num');
    const courier = courierEl ? courierEl.value : '';
    const track = trackEl ? trackEl.value.trim() : '';

    if (!courier || !track) {
        Swal.fire('แจ้งเตือน', 'กรุณากรอกข้อมูลให้ครบ', 'warning');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_tracking');
    formData.append('oid', oid);
    formData.append('tracking', track);
    formData.append('courier', courier);
    appendCsrfToken(formData);

    const btn = document.querySelector('#widget-tracking-modal .btn-confirm');
    const oldText = btn ? btn.innerText : '';
    if (btn) {
        btn.innerText = 'กำลังบันทึก...';
        btn.disabled = true;
    }

    chatFetch(formData)
        .then(res => res.text())
        .then(text => {
            let data = null;
            let ok = false;
            let message = text;
            try {
                data = JSON.parse(text);
                ok = data && data.status === 'success';
                message = (data && data.message) ? data.message : text;
            } catch (e) {
                ok = text.trim() === 'success';
                message = ok ? 'แจ้งเลขพัสดุเรียบร้อย' : text;
            }

            // Close form modal before Swal so they never stack.
            closeTrackingModal();
            if (trackEl) trackEl.value = '';
            if (courierEl) courierEl.value = '';

            if (ok) {
                Swal.fire({
                    icon: 'success',
                    title: 'สำเร็จ!',
                    text: message || 'แจ้งเลขพัสดุเรียบร้อย',
                    confirmButtonText: 'ตกลง'
                }).then(() => {
                    if (document.getElementById('chat-body')) {
                        fetchMessages();
                    } else {
                        location.reload();
                    }
                });
            } else {
                Swal.fire('ข้อผิดพลาด', message || 'บันทึกไม่สำเร็จ', 'error');
            }
        })
        .catch(() => {
            closeTrackingModal();
            Swal.fire('ข้อผิดพลาด', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้', 'error');
        })
        .finally(() => {
            if (btn) {
                btn.innerText = oldText;
                btn.disabled = false;
            }
        });
}

let trackingTimelineOrderId = 0;

function showTrackingTimeline(oid) {
    trackingTimelineOrderId = oid;
    closeAllModals();
    const modal = document.getElementById('widget-tracking-timeline-modal');
    if (!modal) return;
    modal.style.display = 'block';
    syncModalBackdrop();
    loadTrackingTimeline(oid);
}

function closeTrackingTimelineModal() {
    const modal = document.getElementById('widget-tracking-timeline-modal');
    if (modal) modal.style.display = 'none';
    syncModalBackdrop();
}

function loadTrackingTimeline(oid) {
    fetch('controllers/TrackingController.php?action=timeline&order_id=' + encodeURIComponent(oid))
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') return;
            const meta = document.getElementById('tracking-timeline-meta');
            const list = document.getElementById('tracking-timeline-list');
            const link = document.getElementById('tracking-external-link');
            if (meta) {
                meta.innerHTML = `<strong>${data.courier || '-'}</strong><br>เลขพัสดุ: ${data.tracking_number || '-'}<br>สถานะ: ${data.tracking_status || '—'}`;
            }
            if (list) {
                list.innerHTML = (data.events || []).map(ev =>
                    `<li style="padding:8px 0;border-bottom:1px solid #eee;font-size:13px;"><strong>${ev.status_label}</strong><br><span style="color:#888;">${ev.event_at}${ev.location ? ' · ' + ev.location : ''}</span></li>`
                ).join('') || '<li style="color:#888;">ยังไม่มีเหตุการณ์เพิ่มเติม</li>';
            }
            if (link && data.tracking_url) {
                link.href = data.tracking_url;
                link.style.display = 'inline-block';
            } else if (link) {
                link.style.display = 'none';
            }
        });
}

function syncTrackingStatus() {
    if (!trackingTimelineOrderId) return;
    const fd = new FormData();
    fd.append('action', 'sync');
    fd.append('order_id', trackingTimelineOrderId);
    appendCsrfToken(fd);
    fetch('controllers/TrackingController.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => loadTrackingTimeline(trackingTimelineOrderId));
}

window.showTrackingTimeline = showTrackingTimeline;

function isAppShellChat() {
    return !!document.getElementById('view-chat');
}

function getSupportUsername() {
    const root = document.getElementById('chat-page-root');
    return root && root.dataset.supportUser ? root.dataset.supportUser : 'FLIXO_Support';
}

function isSupportPartner(partner) {
    if (!partner) return false;
    return partner.toLowerCase() === getSupportUsername().toLowerCase();
}

function getPartnerDisplayName(partner) {
    if (isSupportPartner(partner)) return 'ทีมงาน FLIXO';
    const map = window.FLIXO_DISPLAY_NAMES || {};
    return map[partner] || partner;
}

function syncSupportChatUI() {
    const isSupport = isSupportPartner(currentPartner);
    const actions = document.querySelector('#chat-room-panel .chat-header-actions');
    if (actions) {
        actions.style.display = isSupport ? 'none' : '';
    }

    const note = document.querySelector('.chat-compose-note');
    if (note) {
        note.textContent = isSupport
            ? 'แชทกับทีมงาน FLIXO — ถามเรื่องบัญชี eKYC หรือปัญหาการใช้งานได้เลย'
            : 'เงินถูกเก็บใน Escrow จนกว่าจะได้รับของ — ไม่ต้องโอนให้คนแปลกหน้าโดยตรง';
    }

    const chatTitle = document.getElementById('chat-title');
    if (chatTitle && isSupport) {
        chatTitle.textContent = 'ทีมงาน FLIXO';
    }

    const partnerAvatar = document.getElementById('chat-partner-avatar');
    if (partnerAvatar && isSupport) {
        const dot = partnerAvatar.querySelector('.online-dot');
        partnerAvatar.textContent = '🛡️';
        if (dot) partnerAvatar.appendChild(dot);
    }
}

function showChatRoomPanel(show) {
    const placeholder = document.getElementById('chat-inbox-placeholder');
    const room = document.getElementById('chat-room-panel');
    const root = document.getElementById('chat-page-root');

    if (placeholder) placeholder.style.display = show ? 'none' : '';
    if (room) room.style.display = show ? 'flex' : 'none';
    if (root) root.classList.toggle('has-partner', !!show);
}

function openChatPartner(partner, updateUrl) {
    if (!partner) return;

    showChatRoomPanel(true);
    document.querySelectorAll('.chat-sidebar-item[data-partner]').forEach(function (el) {
        el.classList.toggle('is-active', el.dataset.partner === partner);
    });

    if (updateUrl !== false && typeof window.flixoSetChatUrl === 'function') {
        window.flixoSetChatUrl(partner);
    }

    openChat(partner);
}

function closeChatPartner(updateUrl) {
    showChatRoomPanel(false);
    currentPartner = '';
    clearInterval(chatInterval);
    closeAllModals();

    document.querySelectorAll('.chat-sidebar-item[data-partner]').forEach(function (el) {
        el.classList.remove('is-active');
    });

    if (updateUrl !== false && typeof window.flixoSetChatUrl === 'function') {
        window.flixoSetChatUrl(null);
    }
}

function pauseChatPolling() {
    clearInterval(chatInterval);
}

window.openChatPartner = openChatPartner;
window.closeChatPartner = closeChatPartner;
window.pauseChatPolling = pauseChatPolling;

function initAppShellChatNav() {
    if (!isAppShellChat()) return;

    const list = document.getElementById('chat-sidebar-list');
    if (list && !list.dataset.bound) {
        list.dataset.bound = '1';
        list.addEventListener('click', function (e) {
            const item = e.target.closest('[data-chat-partner-link]');
            if (!item) return;
            e.preventDefault();
            const partner = item.dataset.partner;
            if (partner) openChatPartner(partner);
        });
    }

    document.querySelectorAll('[data-chat-partner-link]').forEach(function (btn) {
        if (btn.closest('#chat-sidebar-list')) return;
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const partner = btn.dataset.partner;
            if (partner) openChatPartner(partner);
        });
    });

    const back = document.querySelector('[data-chat-back]');
    if (back && !back.dataset.bound) {
        back.dataset.bound = '1';
        back.addEventListener('click', function (e) {
            e.preventDefault();
            closeChatPartner();
        });
    }

    const filter = document.getElementById('chat-sidebar-filter');
    if (filter && !filter.dataset.bound) {
        filter.dataset.bound = '1';
        filter.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.chat-sidebar-item[data-partner]').forEach(function (item) {
                const username = (item.dataset.partner || '').toLowerCase();
                const displayName = (item.dataset.displayName || '').toLowerCase();
                item.style.display = !q || username.includes(q) || displayName.includes(q) ? '' : 'none';
            });
        });
    }
}

window.flixoBootChatTab = function (partner) {
    initAppShellChatNav();
    if (partner) {
        openChatPartner(partner, false);
    } else {
        closeChatPartner(false);
    }
};

document.addEventListener('DOMContentLoaded', initAppShellChatNav);

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('chat-modal-open')) {
        closeAllModals();
    }
});
