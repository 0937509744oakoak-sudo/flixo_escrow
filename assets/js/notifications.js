/**
 * FLIXO Notifications — bell badge polling, dropdown, and Web Push opt-in.
 */

const NOTIF_CONTROLLER_URL = (() => {
    const script = document.currentScript || document.querySelector('script[src*="notifications.js"]');
    if (script && script.src) {
        return script.src.replace(/\/assets\/js\/notifications\.js(?:\?.*)?$/, '') + '/controllers/NotificationController.php';
    }
    return window.location.origin + '/controllers/NotificationController.php';
})();

let notifPollInterval = null;
let notifDropdownOpen = false;

function notifAppendCsrf(formData) {
    if (typeof window.CSRF_TOKEN !== 'undefined' && window.CSRF_TOKEN) {
        formData.append('csrf_token', window.CSRF_TOKEN);
    }
}

function notifFetch(action, extraParams) {
    const formData = new FormData();
    formData.append('action', action);
    if (extraParams) {
        Object.keys(extraParams).forEach((key) => formData.append(key, extraParams[key]));
    }
    notifAppendCsrf(formData);
    return fetch(NOTIF_CONTROLLER_URL, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
    }).then((res) => res.json());
}

function notifUpdateBadges(count) {
    document.querySelectorAll('.notif-bell-badge').forEach((el) => {
        if (count > 0) {
            el.textContent = count > 99 ? '99+' : String(count);
            el.style.display = 'inline-flex';
        } else {
            el.style.display = 'none';
        }
    });
}

function notifPollUnreadCount() {
    notifFetch('unread_count')
        .then((data) => {
            if (data && data.status === 'success') {
                notifUpdateBadges(data.count || 0);
            }
        })
        .catch(() => {});
}

function notifIconFor(type) {
    const icons = { order: '📦', dispute: '⚠️', payout: '💰', ekyc: '🪪', system: '🔔' };
    return icons[type] || '🔔';
}

function notifRenderList(items) {
    const list = document.getElementById('notif-dropdown-list');
    if (!list) return;

    if (!items || items.length === 0) {
        list.innerHTML = '<div class="notif-empty">ยังไม่มีการแจ้งเตือน</div>';
        return;
    }

    list.innerHTML = items.map((item) => {
        const icon = item.icon || notifIconFor(item.type);
        const unreadClass = item.is_read ? '' : ' notif-item--unread';
        const title = item.title ? `<div class="notif-item-title">${escapeNotifHtml(item.title)}</div>` : '';
        return `
            <button type="button" class="notif-item${unreadClass}" data-id="${item.id}" data-url="${escapeNotifHtml(item.url || '')}">
                <span class="notif-item-icon">${icon}</span>
                <span class="notif-item-body">
                    ${title}
                    <div class="notif-item-message">${escapeNotifHtml(item.message)}</div>
                    <div class="notif-item-time">${escapeNotifHtml(item.time || '')}</div>
                </span>
            </button>
        `;
    }).join('');
}

function escapeNotifHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

function notifLoadList() {
    const list = document.getElementById('notif-dropdown-list');
    if (list) list.innerHTML = '<div class="notif-empty">กำลังโหลด...</div>';

    notifFetch('list')
        .then((data) => {
            if (data && data.status === 'success') {
                notifRenderList(data.notifications);
            }
        })
        .catch(() => {
            if (list) list.innerHTML = '<div class="notif-empty">โหลดไม่สำเร็จ</div>';
        });
}

function notifSetDropdownOpen(open) {
    const dropdown = document.getElementById('notif-dropdown');
    const btn = document.getElementById('notif-bell-btn');
    if (!dropdown || !btn) return;

    notifDropdownOpen = open;
    dropdown.classList.toggle('is-open', open);
    dropdown.setAttribute('aria-hidden', open ? 'false' : 'true');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');

    if (open) notifLoadList();
}

function notifToggleDropdown() {
    notifSetDropdownOpen(!notifDropdownOpen);
}

function notifHandleItemClick(e) {
    const item = e.target.closest('.notif-item');
    if (!item) return;

    const id = item.dataset.id;
    const url = item.dataset.url;

    notifFetch('mark_read', { id }).then(() => {
        notifPollUnreadCount();
        if (url) {
            if (window.FlixoProgress) window.FlixoProgress.start();
            window.location.href = url;
        } else {
            item.classList.remove('notif-item--unread');
        }
    });
}

function notifMarkAllRead() {
    notifFetch('mark_all_read').then(() => {
        notifPollUnreadCount();
        notifLoadList();
    });
}

function notifUrlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function notifArrayBufferToBase64(buffer) {
    return window.btoa(String.fromCharCode.apply(null, new Uint8Array(buffer)));
}

function notifEnablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return Promise.reject(new Error('Push ไม่รองรับบนเบราว์เซอร์นี้'));
    }

    return notifFetch('get_preferences').then((data) => {
        if (!data || data.status !== 'success' || !data.push_configured || !data.vapid_public_key) {
            throw new Error('ระบบ Push ยังไม่ได้ตั้งค่า');
        }

        return Notification.requestPermission().then((permission) => {
            if (permission !== 'granted') {
                throw new Error('คุณไม่ได้อนุญาตการแจ้งเตือน');
            }
            return navigator.serviceWorker.ready;
        }).then((registration) => registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: notifUrlBase64ToUint8Array(data.vapid_public_key),
        })).then((subscription) => {
            const key = subscription.getKey('p256dh');
            const auth = subscription.getKey('auth');
            return notifFetch('save_push_subscription', {
                endpoint: subscription.endpoint,
                p256dh: key ? notifArrayBufferToBase64(key) : '',
                auth: auth ? notifArrayBufferToBase64(auth) : '',
            });
        });
    });
}

function notifDisablePush() {
    if (!('serviceWorker' in navigator)) {
        return Promise.resolve();
    }
    return navigator.serviceWorker.ready.then((registration) => registration.pushManager.getSubscription()).then((subscription) => {
        if (!subscription) return null;
        const endpoint = subscription.endpoint;
        return subscription.unsubscribe().then(() => notifFetch('remove_push_subscription', { endpoint }));
    });
}

window.flixoEnablePushNotifications = notifEnablePush;
window.flixoDisablePushNotifications = notifDisablePush;

function notifInit() {
    const btn = document.getElementById('notif-bell-btn');
    if (btn && !btn.dataset.bound) {
        btn.dataset.bound = '1';
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            notifToggleDropdown();
        });
    }

    const list = document.getElementById('notif-dropdown-list');
    if (list && !list.dataset.bound) {
        list.dataset.bound = '1';
        list.addEventListener('click', notifHandleItemClick);
    }

    const markAllBtn = document.getElementById('notif-mark-all-read');
    if (markAllBtn && !markAllBtn.dataset.bound) {
        markAllBtn.dataset.bound = '1';
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            notifMarkAllRead();
        });
    }

    if (!document.body.dataset.notifOutsideBound) {
        document.body.dataset.notifOutsideBound = '1';
        document.addEventListener('click', function (e) {
            const wrap = document.querySelector('.notif-bell-wrap');
            if (notifDropdownOpen && wrap && !wrap.contains(e.target)) {
                notifSetDropdownOpen(false);
            }
        });
    }

    notifPollUnreadCount();
    if (!notifPollInterval) {
        notifPollInterval = setInterval(notifPollUnreadCount, 20000);
    }
}

document.addEventListener('DOMContentLoaded', notifInit);
