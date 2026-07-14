/**
 * FLIXO — shared dispute open modal (chat + orders)
 */
(function () {
    'use strict';

    var state = {
        orderId: 0,
        onSuccess: null,
        reloadChat: false
    };

    function $(id) {
        return document.getElementById(id);
    }

    function getSelectedReason() {
        var checked = document.querySelector('input[name="dispute_reason"]:checked');
        return checked ? checked.value : '';
    }

    function syncReasonSelection() {
        document.querySelectorAll('.flixo-dispute-reason').forEach(function (label) {
            var input = label.querySelector('input[type="radio"]');
            label.classList.toggle('is-selected', input && input.checked);
        });
    }

    function setError(msg) {
        var el = $('dispute-form-error');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.style.display = 'block';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function setLoading(loading) {
        var btn = $('dispute-submit-btn');
        if (!btn) return;
        btn.disabled = loading;
        btn.classList.toggle('is-loading', loading);
        var label = btn.querySelector('.flixo-dispute-btn-label');
        if (label) {
            label.textContent = loading ? 'กำลังส่ง...' : 'ส่งเรื่องข้อพิพาท';
        }
    }

    function updateCharCount() {
        var ta = $('dispute-description');
        var num = $('dispute-char-num');
        if (!ta || !num) return;
        num.textContent = String(ta.value.length);
    }

    function resetForm() {
        var first = document.querySelector('input[name="dispute_reason"][value="ไม่ได้รับสินค้า"]');
        if (first) first.checked = true;
        syncReasonSelection();
        var ta = $('dispute-description');
        if (ta) ta.value = '';
        updateCharCount();
        setError('');
        setLoading(false);
    }

    function syncBodyClass(open) {
        document.body.classList.toggle('flixo-dispute-open', open);
        document.body.classList.toggle('chat-modal-open', open);
    }

    function closeOtherModals() {
        ['widget-share-product-modal', 'widget-offer-modal', 'widget-tracking-modal', 'widget-tracking-timeline-modal'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.style.display = 'none';
            el.classList.remove('is-open');
        });
        if (typeof syncModalBackdrop === 'function') {
            syncModalBackdrop();
        }
    }

    window.FlixoDispute = {
        open: function (orderId, options) {
            options = options || {};
            state.orderId = parseInt(orderId, 10) || 0;
            state.onSuccess = typeof options.onSuccess === 'function' ? options.onSuccess : null;
            state.reloadChat = !!options.reloadChat;

            if (state.orderId <= 0) return;

            closeOtherModals();

            var oidInput = $('dispute-order-id');
            if (oidInput) oidInput.value = String(state.orderId);

            resetForm();

            var overlay = $('flixo-dispute-overlay');
            if (overlay) {
                overlay.style.display = 'flex';
                overlay.setAttribute('aria-hidden', 'false');
            }
            syncBodyClass(true);

            setTimeout(function () {
                var ta = $('dispute-description');
                if (ta) ta.focus();
            }, 120);
        },

        close: function () {
            var overlay = $('flixo-dispute-overlay');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
            syncBodyClass(false);
            setLoading(false);
            setError('');
            state.orderId = 0;
        },

        submit: function () {
            if (state.orderId <= 0) return;

            var reason = getSelectedReason();
            var desc = ($('dispute-description') && $('dispute-description').value || '').trim();

            if (!reason) {
                setError('กรุณาเลือกเหตุผล');
                return;
            }
            if (reason === 'อื่นๆ' && !desc) {
                setError('กรุณาอธิบายปัญหาเมื่อเลือก "อื่นๆ"');
                return;
            }
            if (desc.length < 10 && reason !== 'ไม่ได้รับสินค้า' && reason !== 'อื่นๆ') {
                setError('กรุณาอธิบายรายละเอียดอย่างน้อย 10 ตัวอักษร');
                return;
            }

            setError('');
            setLoading(true);

            var fd = new FormData();
            fd.append('action', 'open_dispute');
            fd.append('order_id', state.orderId);
            fd.append('reason', reason);
            fd.append('description', desc);

            if (typeof window.CSRF_TOKEN !== 'undefined' && window.CSRF_TOKEN) {
                fd.append('csrf_token', window.CSRF_TOKEN);
            }

            var url = 'controllers/OrderController.php';
            if (typeof window.FLIXO_BASE_URL !== 'undefined' && window.FLIXO_BASE_URL && url.indexOf('://') === -1) {
                url = window.FLIXO_BASE_URL.replace(/\/$/, '') + '/' + url;
            }

            fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (res) {
                    return res.text().then(function (text) {
                        try {
                            return { ok: res.ok, data: JSON.parse(text) };
                        } catch (e) {
                            throw new Error(text ? text.substring(0, 200) : ('HTTP ' + res.status));
                        }
                    });
                })
                .then(function (result) {
                    setLoading(false);
                    var data = result.data;
                    if (data.status === 'success') {
                        FlixoDispute.close();
                        if (state.onSuccess) {
                            state.onSuccess(data);
                            return;
                        }
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: 'เปิดข้อพิพาทแล้ว',
                                text: data.message || 'เงินถูกระงับชั่วคราว',
                                confirmButtonText: 'ดูรายละเอียด',
                                confirmButtonColor: '#2563eb'
                            }).then(function () {
                                if (data.dispute_id) {
                                    window.location.href = 'dispute_detail.php?dispute_id=' + data.dispute_id;
                                } else if (state.reloadChat && typeof fetchMessages === 'function') {
                                    fetchMessages();
                                } else {
                                    location.reload();
                                }
                            });
                        } else if (data.dispute_id) {
                            window.location.href = 'dispute_detail.php?dispute_id=' + data.dispute_id;
                        } else {
                            location.reload();
                        }
                    } else {
                        setError(data.message || 'เปิดข้อพิพาทไม่ได้ กรุณาลองใหม่');
                    }
                })
                .catch(function (err) {
                    setLoading(false);
                    var msg = (err && err.message) ? err.message : '';
                    if (msg.indexOf('<') !== -1 || msg.length > 120) {
                        msg = 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง กรุณาลองใหม่';
                    }
                    setError(msg || 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่');
                });
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[name="dispute_reason"]').forEach(function (input) {
            input.addEventListener('change', syncReasonSelection);
        });

        var ta = $('dispute-description');
        if (ta) {
            ta.addEventListener('input', updateCharCount);
        }

        var overlay = $('flixo-dispute-overlay');
        if (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) FlixoDispute.close();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $('flixo-dispute-overlay') && $('flixo-dispute-overlay').style.display === 'flex') {
                FlixoDispute.close();
            }
        });
    });

    window.chatOpenDispute = function (oid) {
        FlixoDispute.open(oid, { reloadChat: true });
    };

    window.ordersOpenDispute = function (oid) {
        FlixoDispute.open(oid, { reloadChat: false });
    };
})();
