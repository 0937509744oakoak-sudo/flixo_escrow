(function () {
    const NAV_TABS = ['home', 'orders', 'chat', 'profile'];
    const SUB_TABS = ['sell', 'my_products'];
    const TAB_ORDER = {
        home: 0,
        orders: 1,
        chat: 2,
        profile: 3,
        sell: 4,
        my_products: 5,
    };
    const VIEW_MAP = {
        home: 'view-home',
        orders: 'view-orders',
        chat: 'view-chat',
        profile: 'view-profile',
        sell: 'view-sell-product',
        my_products: 'view-my-products',
    };
    const FADE_MS = 240;
    const SLIDE_MS = 300;

    function resolveViewId(tab) {
        return VIEW_MAP[tab] || null;
    }

    function getTabFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab') || 'home';
        return VIEW_MAP[tab] ? tab : 'home';
    }

    function getChatPartnerFromUrl() {
        return new URLSearchParams(window.location.search).get('user') || '';
    }

    function getTransitionType(fromTab, toTab) {
        if (!fromTab || !toTab) return 'slide';
        if (TAB_ORDER[fromTab] !== undefined && TAB_ORDER[toTab] !== undefined) {
            return 'slide';
        }
        return 'fade-up';
    }

    function getSlideDirection(fromTab, toTab) {
        return (TAB_ORDER[toTab] || 0) > (TAB_ORDER[fromTab] || 0) ? 1 : -1;
    }

    function getTransitionMs(type) {
        return type === 'slide' ? SLIDE_MS : FADE_MS;
    }

    function setUrlTab(tab, options) {
        options = options || {};
        const url = new URL(window.location.href);

        if (tab === 'home') {
            url.searchParams.delete('tab');
            url.searchParams.delete('user');
        } else {
            url.searchParams.set('tab', tab);
            if (tab === 'chat') {
                if (options.chatPartner) {
                    url.searchParams.set('user', options.chatPartner);
                } else if (!options.keepChatPartner) {
                    url.searchParams.delete('user');
                }
            } else {
                url.searchParams.delete('user');
            }
        }

        window.history.replaceState(
            { tab: tab, chatPartner: options.chatPartner || null },
            '',
            url.pathname + url.search + url.hash
        );
    }

    window.flixoSetChatUrl = function (partner) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'chat');
        if (partner) {
            url.searchParams.set('user', partner);
        } else {
            url.searchParams.delete('user');
        }
        window.history.replaceState(
            { tab: 'chat', chatPartner: partner || null },
            '',
            url.pathname + url.search + url.hash
        );
    };

    function syncHomeChrome(tab) {
        const homeChrome = document.getElementById('home-chrome');
        if (homeChrome) {
            homeChrome.classList.toggle('is-visible', tab === 'home');
        }
    }

    function syncNavHeightVar() {
        const nav = document.querySelector('.navbar');
        if (nav) {
            document.documentElement.style.setProperty('--flixo-nav-h', nav.offsetHeight + 'px');
        }
    }

    function syncMainLayout(tab) {
        const flixoMain = document.getElementById('flixo-main');
        if (!flixoMain) return;

        flixoMain.classList.toggle('flixo-main--overlap', tab === 'home');
        flixoMain.classList.toggle('flixo-main--tab', tab !== 'home' && tab !== 'chat');
        flixoMain.classList.toggle('flixo-main--chat', tab === 'chat');
        document.body.classList.toggle('flixo-chat-active', tab === 'chat');
        document.documentElement.classList.toggle('flixo-chat-active', tab === 'chat');
    }

    function syncNavActive(tab) {
        document.querySelectorAll('[data-app-tab]').forEach(function (el) {
            const elTab = el.getAttribute('data-app-tab');
            const isActive = elTab === tab;
            el.classList.toggle('active', isActive);
            if (el.classList.contains('mobile-nav-btn')) {
                const icon = el.querySelector('.material-symbols-outlined');
                if (icon && !el.classList.contains('mobile-nav-btn-sell')) {
                    icon.style.fontVariationSettings = isActive
                        ? "'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24"
                        : "'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24";
                }
            }
        });
    }

    function onChatTabActivated() {
        if (typeof window.flixoBootChatTab === 'function') {
            window.flixoBootChatTab(getChatPartnerFromUrl());
        }
    }

    function onChatTabDeactivated() {
        if (typeof window.pauseChatPolling === 'function') {
            window.pauseChatPolling();
        }
    }

    function clearViewMotionClasses(view) {
        view.classList.remove(
            'is-enter-fade-up',
            'is-fade-out',
            'is-exit-slide-left',
            'is-exit-slide-right',
            'is-enter-slide-left',
            'is-enter-slide-right'
        );
    }

    function hideViewElement(view) {
        clearViewMotionClasses(view);
        view.classList.remove('is-shown', 'is-active');
        view.style.display = 'none';
    }

    function revealFadeUp(view, callback) {
        view.style.display = 'block';
        view.classList.add('is-shown', 'is-enter-fade-up');
        requestAnimationFrame(function () {
            view.classList.remove('is-enter-fade-up');
            view.classList.add('is-active');
            if (callback) callback();
        });
    }

    function revealSlide(view, direction, callback) {
        const inClass = direction > 0 ? 'is-enter-slide-right' : 'is-enter-slide-left';
        view.style.display = 'block';
        view.classList.add('is-shown', inClass);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                view.classList.remove(inClass);
                view.classList.add('is-active');
                if (callback) callback();
            });
        });
    }

    function transitionFadeUp(current, target, callback) {
        if (!current) {
            revealFadeUp(target, callback);
            return;
        }

        current.classList.add('is-fade-out');
        current.classList.remove('is-active');

        setTimeout(function () {
            hideViewElement(current);
            revealFadeUp(target, callback);
        }, FADE_MS);
    }

    function transitionSlide(current, target, direction, callback) {
        if (!current) {
            revealSlide(target, direction, callback);
            return;
        }

        const outClass = direction > 0 ? 'is-exit-slide-left' : 'is-exit-slide-right';
        const inClass = direction > 0 ? 'is-enter-slide-right' : 'is-enter-slide-left';
        const appViews = document.getElementById('app-views');

        if (appViews) {
            appViews.style.minHeight = current.offsetHeight + 'px';
        }

        target.style.display = 'block';
        target.classList.add('is-shown', inClass);

        current.classList.remove('is-active');
        current.classList.add(outClass);

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                target.classList.remove(inClass);
                target.classList.add('is-active');
            });
        });

        setTimeout(function () {
            hideViewElement(current);
            if (appViews) {
                appViews.style.minHeight = '';
            }
            if (callback) callback();
        }, SLIDE_MS);
    }

    function showView(tab) {
        const viewId = resolveViewId(tab);
        if (!viewId) return;

        const target = document.getElementById(viewId);
        if (!target) return;

        const current = document.querySelector('.app-view.is-shown');
        const fromTab = current ? (current.dataset.tab || 'home') : null;

        if (current === target) {
            syncNavActive(tab);
            syncHomeChrome(tab);
            syncMainLayout(tab);
            setUrlTab(tab, tab === 'chat' ? { chatPartner: getChatPartnerFromUrl(), keepChatPartner: true } : {});
            if (tab === 'chat') {
                onChatTabActivated();
            }
            return;
        }

        syncNavActive(tab);
        syncHomeChrome(tab);
        syncMainLayout(tab);
        setUrlTab(tab, tab === 'chat' ? { chatPartner: getChatPartnerFromUrl(), keepChatPartner: true } : {});

        if (current && current.dataset.tab === 'chat') {
            onChatTabDeactivated();
        }

        const transition = getTransitionType(fromTab, tab);
        const appViews = document.getElementById('app-views');
        if (appViews) {
            appViews.classList.add('is-transitioning');
        }

        const finish = function () {
            if (appViews) {
                appViews.classList.remove('is-transitioning');
            }
            if (tab === 'chat') {
                onChatTabActivated();
            }
        };

        if (transition === 'slide') {
            transitionSlide(current, target, getSlideDirection(fromTab, tab), finish);
        } else {
            transitionFadeUp(current, target, finish);
        }
    }

    window.flixoSwitchTab = function (tab, options) {
        options = options || {};
        if (!VIEW_MAP[tab]) tab = 'home';

        const fromTab = (document.querySelector('.app-view.is-shown') || {}).dataset;
        const from = fromTab ? fromTab.tab : null;
        const transition = getTransitionType(from, tab);
        const delay = getTransitionMs(transition) * (document.querySelector('.app-view.is-shown') ? 2 : 1);

        if (tab === 'chat' && options.chatPartner && typeof window.openChatPartner === 'function') {
            showView('chat');
            setTimeout(function () {
                window.openChatPartner(options.chatPartner, true);
            }, options.immediate ? 0 : delay);
            return;
        }

        showView(tab);
        if (options.scrollTo) {
            setTimeout(function () {
                const el = document.getElementById(options.scrollTo);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, delay + 40);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        const app = document.getElementById('flixo-app');
        if (!app) return;

        const initialTab = getTabFromUrl();
        const initialViewId = resolveViewId(initialTab);

        syncNavHeightVar();
        window.addEventListener('resize', syncNavHeightVar);

        document.querySelectorAll('.app-view').forEach(function (view) {
            if (view.id === initialViewId) {
                view.style.display = 'block';
                view.classList.add('is-shown', 'is-active');
                return;
            }
            hideViewElement(view);
        });

        syncNavActive(initialTab);
        syncHomeChrome(initialTab);
        syncMainLayout(initialTab);

        if (initialTab === 'chat') {
            onChatTabActivated();
        }

        document.querySelectorAll('[data-app-tab]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                window.flixoSwitchTab(btn.getAttribute('data-app-tab'));
            });
        });

        document.querySelectorAll('[data-quick-tab]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (el.tagName === 'A' && el.getAttribute('href') && el.getAttribute('href').indexOf('javascript') === -1) {
                    return;
                }
                e.preventDefault();
                const quickTab = el.getAttribute('data-quick-tab');
                const scrollTo = el.getAttribute('data-scroll-to') || '';
                window.flixoSwitchTab(quickTab, scrollTo ? { scrollTo: scrollTo } : undefined);
            });
        });
    });

    if ('serviceWorker' in navigator) {
        // Push notifications need a SW; register a network-only worker after clearing old caches.
        window.addEventListener('load', function () {
            navigator.serviceWorker.getRegistrations().then(function (regs) {
                return Promise.all(regs.map(function (r) { return r.unregister(); }));
            }).then(function () {
                if (window.caches && caches.keys) {
                    return caches.keys().then(function (keys) {
                        return Promise.all(keys.map(function (k) { return caches.delete(k); }));
                    });
                }
            }).then(function () {
                return navigator.serviceWorker.register('sw.js?v=4').catch(function () {});
            });
        });
    }
})();
