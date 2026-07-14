document.addEventListener("DOMContentLoaded", function() {
    document.documentElement.setAttribute('data-theme', 'light');
    document.documentElement.classList.remove('dark');
    localStorage.removeItem('theme');

    const urlParams = new URLSearchParams(window.location.search);
    const chatUser = urlParams.get('open_chat');
    if (chatUser) {
        window.location.href = 'dashboard.php?tab=chat&user=' + encodeURIComponent(chatUser);
        return;
    }

    document.body.classList.add('page-loaded');
});
