// Applies the chosen appearance before the page is drawn: system, light or
// dark, kept in this browser only. Without storage the system decides.
(function () {
    var preference = 'system';
    try {
        preference = localStorage.getItem('owpanel-theme') || 'system';
    } catch (e) {}
    var dark = preference === 'dark'
        || (preference === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme-preference', preference);
    document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
})();
