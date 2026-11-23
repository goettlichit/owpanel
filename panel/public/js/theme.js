// Sets the colour scheme before the page is drawn, so dark mode does not
// flash. The server writes the chosen setting (system, light or dark) into
// <html data-theme-preference>; "system" follows the operating system and
// reacts when it changes.
(function () {
    var root = document.documentElement;
    var query = window.matchMedia('(prefers-color-scheme: dark)');

    function apply() {
        var preference = root.getAttribute('data-theme-preference');
        var dark = preference === 'dark' || (preference !== 'light' && query.matches);
        root.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    }

    apply();
    query.addEventListener('change', apply);
})();
