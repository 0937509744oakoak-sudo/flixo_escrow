<?php
/**
 * Google Sign-In button + consent fallback (login/register pages).
 * Requires: $csrf_token, optional $redirectAfter
 * Optional: $googleConsentInline = true to skip duplicate consent UI when form already has checkboxes
 */
if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '') {
    return;
}
$redirectAfter = $redirectAfter ?? '';
$googleConsentInline = !empty($googleConsentInline);
$authEndpoint = rtrim(BASE_URL, '/') . '/controllers/AuthController.php';
?>
<div class="w-full" id="google-signin-wrap">
    <div id="google-btn-host" class="flex justify-center min-h-[44px]"></div>
    <p id="google-signin-error" class="hidden mt-3 text-center font-body-md text-body-md text-error"></p>

    <?php if (!$googleConsentInline): ?>
    <div id="google-consent-panel" class="hidden mt-4 flex flex-col gap-3 p-4 bg-slate-50/80 border border-outline-variant/40 rounded-2xl">
        <p class="font-body-md text-body-md text-on-surface-variant" id="google-consent-msg">
            กรุณายอมรับข้อกำหนดก่อนดำเนินการต่อ
        </p>
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" id="google_consent_terms" value="1" class="mt-1 rounded border-outline-variant"/>
            <span class="font-body-md text-body-md text-on-surface-variant">
                ฉันยอมรับ <a href="terms_of_service.php" target="_blank" class="text-primary font-semibold hover:underline">ข้อกำหนดการใช้งาน</a>
            </span>
        </label>
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" id="google_consent_privacy" value="1" class="mt-1 rounded border-outline-variant"/>
            <span class="font-body-md text-body-md text-on-surface-variant">
                ฉันยอมรับ <a href="privacy_policy.php" target="_blank" class="text-primary font-semibold hover:underline">นโยบายความเป็นส่วนตัว (PDPA)</a>
            </span>
        </label>
        <button type="button" id="google-consent-retry" class="w-full h-11 bg-primary text-on-primary font-headline-md rounded-xl text-sm">
            ยอมรับแล้วดำเนินการต่อด้วย Google
        </button>
    </div>
    <?php endif; ?>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
(function () {
    const CLIENT_ID = <?php echo json_encode(GOOGLE_CLIENT_ID, JSON_UNESCAPED_UNICODE); ?>;
    const CSRF = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE); ?>;
    const REDIRECT = <?php echo json_encode($redirectAfter, JSON_UNESCAPED_UNICODE); ?>;
    const AUTH_URL = <?php echo json_encode($authEndpoint, JSON_UNESCAPED_UNICODE); ?>;
    const INLINE_CONSENT = <?php echo $googleConsentInline ? 'true' : 'false'; ?>;

    let pendingCredential = null;
    const errEl = document.getElementById('google-signin-error');
    const consentPanel = document.getElementById('google-consent-panel');
    const consentMsg = document.getElementById('google-consent-msg');
    const retryBtn = document.getElementById('google-consent-retry');

    function showError(msg) {
        if (!errEl) return;
        errEl.textContent = msg || 'เกิดข้อผิดพลาด';
        errEl.classList.remove('hidden');
    }

    function clearError() {
        if (!errEl) return;
        errEl.textContent = '';
        errEl.classList.add('hidden');
    }

    function readConsent() {
        if (INLINE_CONSENT) {
            const terms = document.querySelector('input[name="consent_terms"]');
            const privacy = document.querySelector('input[name="consent_privacy"]');
            return {
                terms: !!(terms && terms.checked),
                privacy: !!(privacy && privacy.checked)
            };
        }
        const terms = document.getElementById('google_consent_terms');
        const privacy = document.getElementById('google_consent_privacy');
        return {
            terms: !!(terms && terms.checked),
            privacy: !!(privacy && privacy.checked)
        };
    }

    function showConsent(message) {
        if (INLINE_CONSENT) {
            showError(message || 'กรุณาติ๊กยอมรับข้อกำหนดและนโยบายด้านล่างก่อน แล้วกดปุ่ม Google อีกครั้ง');
            const box = document.querySelector('input[name="consent_terms"]');
            if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        if (consentMsg && message) consentMsg.textContent = message;
        if (consentPanel) consentPanel.classList.remove('hidden');
    }

    async function submitCredential(credential, withConsent) {
        clearError();
        const fd = new FormData();
        fd.append('action', 'google_login');
        fd.append('csrf_token', CSRF);
        fd.append('id_token', credential);
        if (REDIRECT) fd.append('redirect', REDIRECT);
        if (withConsent) {
            const c = readConsent();
            if (c.terms) fd.append('consent_terms', '1');
            if (c.privacy) fd.append('consent_privacy', '1');
        }

        const res = await fetch(AUTH_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => null);
        if (!data) {
            showError('ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง');
            return;
        }
        if (data.status === 'needs_consent') {
            pendingCredential = credential;
            showConsent(data.message);
            return;
        }
        if (data.status === 'success' && data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        showError(data.message || 'เข้าสู่ระบบด้วย Google ไม่สำเร็จ');
    }

    function handleCredentialResponse(response) {
        if (!response || !response.credential) {
            showError('ไม่ได้รับข้อมูลจาก Google');
            return;
        }
        const c = readConsent();
        // On register page, prefer sending consent if already checked
        submitCredential(response.credential, c.terms && c.privacy).catch(function (e) {
            showError(e.message || 'เชื่อมต่อไม่สำเร็จ');
        });
    }

    function initGoogle() {
        if (!window.google || !google.accounts || !google.accounts.id) {
            return false;
        }
        google.accounts.id.initialize({
            client_id: CLIENT_ID,
            callback: handleCredentialResponse,
            ux_mode: 'popup',
            auto_select: false,
            cancel_on_tap_outside: true
        });
        const host = document.getElementById('google-btn-host');
        if (host) {
            google.accounts.id.renderButton(host, {
                theme: 'outline',
                size: 'large',
                shape: 'pill',
                text: 'continue_with',
                width: Math.min(host.parentElement ? host.parentElement.clientWidth : 320, 400),
                locale: 'th'
            });
        }
        return true;
    }

    function waitForGoogle() {
        if (initGoogle()) return;
        let tries = 0;
        const t = setInterval(function () {
            tries++;
            if (initGoogle() || tries > 50) clearInterval(t);
        }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForGoogle);
    } else {
        waitForGoogle();
    }

    if (retryBtn) {
        retryBtn.addEventListener('click', function () {
            const c = readConsent();
            if (!c.terms || !c.privacy) {
                showError('กรุณายอมรับข้อกำหนดและนโยบายความเป็นส่วนตัว');
                return;
            }
            if (!pendingCredential) {
                showError('กรุณากดปุ่ม Google อีกครั้ง');
                return;
            }
            submitCredential(pendingCredential, true).catch(function (e) {
                showError(e.message || 'เชื่อมต่อไม่สำเร็จ');
            });
        });
    }
})();
</script>
