document.addEventListener("DOMContentLoaded", function() {
    document.documentElement.setAttribute('data-theme', 'light');
    document.documentElement.classList.remove('dark');
    localStorage.removeItem('theme');

    (function initNavProgress() {
        if (!document.getElementById('flixo-progress-styles')) {
            const style = document.createElement('style');
            style.id = 'flixo-progress-styles';
            style.textContent =
                '#flixo-progress{position:fixed;top:0;left:0;right:0;height:3px;z-index:100000;pointer-events:none;opacity:0;transition:opacity .2s ease}' +
                '#flixo-progress.is-active{opacity:1}' +
                '#flixo-progress .flixo-progress-bar{height:100%;width:0;background:#0050cb;box-shadow:0 0 10px rgba(0,80,203,.4);transition:width .35s ease}';
            document.head.appendChild(style);
        }

        const bar = document.createElement('div');
        bar.id = 'flixo-progress';
        bar.setAttribute('aria-hidden', 'true');
        bar.innerHTML = '<div class="flixo-progress-bar"></div>';
        document.body.appendChild(bar);

        const fill = bar.querySelector('.flixo-progress-bar');
        let value = 0;
        let trickleTimer = null;
        let hideTimer = null;
        let active = false;

        function setWidth(pct) {
            value = Math.max(0, Math.min(100, pct));
            fill.style.width = value + '%';
        }

        function clearTrickle() {
            if (trickleTimer) {
                clearInterval(trickleTimer);
                trickleTimer = null;
            }
        }

        function start() {
            if (active) return;
            active = true;
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
            bar.classList.add('is-active');
            fill.style.transition = 'none';
            setWidth(0);
            void fill.offsetWidth;
            fill.style.transition = '';
            setWidth(12);
            clearTrickle();
            trickleTimer = setInterval(function () {
                if (value >= 88) return;
                const step = value < 40 ? 8 : value < 70 ? 4 : 1.5;
                setWidth(value + step + Math.random() * 2);
            }, 400);
        }

        function done() {
            if (!active) return;
            clearTrickle();
            setWidth(100);
            hideTimer = setTimeout(function () {
                bar.classList.remove('is-active');
                hideTimer = setTimeout(function () {
                    fill.style.transition = 'none';
                    setWidth(0);
                    fill.style.transition = '';
                    active = false;
                    hideTimer = null;
                }, 220);
            }, 180);
        }

        function isInternalNavLink(anchor) {
            if (!anchor || !anchor.getAttribute('href')) return false;
            if (anchor.hasAttribute('download')) return false;
            if (anchor.getAttribute('data-no-progress') != null) return false;
            if (anchor.target && anchor.target !== '_self') return false;

            const href = anchor.getAttribute('href').trim();
            if (!href || href.charAt(0) === '#') return false;
            if (/^(mailto:|tel:|javascript:)/i.test(href)) return false;

            let url;
            try {
                url = new URL(anchor.href, window.location.href);
            } catch (err) {
                return false;
            }

            if (url.origin !== window.location.origin) return false;
            if (
                url.pathname === window.location.pathname &&
                url.search === window.location.search
            ) {
                return false;
            }

            return true;
        }

        document.addEventListener('click', function (e) {
            if (e.defaultPrevented) return;
            if (e.button !== 0) return;
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

            const anchor = e.target.closest('a[href]');
            if (!isInternalNavLink(anchor)) return;

            start();
        });

        document.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            const form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.getAttribute('data-no-progress') != null) return;
            if (form.target && form.target !== '_self') return;
            start();
        });

        window.addEventListener('pageshow', function (e) {
            if (e.persisted) done();
        });

        window.FlixoProgress = { start: start, done: done };
    })();

    const urlParams = new URLSearchParams(window.location.search);
    const chatUser = urlParams.get('open_chat');
    if (chatUser) {
        if (window.FlixoProgress) window.FlixoProgress.start();
        window.location.href = 'dashboard.php?tab=chat&user=' + encodeURIComponent(chatUser);
        return;
    }

    document.body.classList.add('page-loaded');
});
