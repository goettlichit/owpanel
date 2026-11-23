// Small helpers of the website: the appearance switch and copy buttons on
// code blocks. Everything works without JavaScript as well.
(function () {
    var order = ['system', 'light', 'dark'];
    var icons = { system: 'device-desktop', light: 'sun', dark: 'moon' };
    var root = document.documentElement;
    var button = document.querySelector('[data-theme-toggle]');

    function apply(preference) {
        var dark = preference === 'dark'
            || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        root.setAttribute('data-theme-preference', preference);
        root.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        if (button) {
            var label = button.getAttribute('data-label-' + preference);
            button.querySelector('[data-theme-icon]').className = 'ti ti-' + icons[preference];
            button.setAttribute('title', label);
            button.setAttribute('aria-label', label);
        }
    }

    if (button) {
        apply(root.getAttribute('data-theme-preference') || 'system');
        button.addEventListener('click', function () {
            var current = root.getAttribute('data-theme-preference') || 'system';
            var next = order[(order.indexOf(current) + 1) % order.length];
            try {
                localStorage.setItem('owpanel-theme', next);
            } catch (e) {}
            apply(next);
        });
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            apply(root.getAttribute('data-theme-preference') || 'system');
        });
    }

    // Copy buttons on code blocks.
    var labels = root.lang === 'de' ? ['Kopieren', 'Kopiert'] : ['Copy', 'Copied'];
    document.querySelectorAll('.ow-prose pre').forEach(function (pre) {
        if (!navigator.clipboard) {
            return;
        }
        var copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'btn btn-sm btn-ghost-secondary ow-copy';
        copy.textContent = labels[0];
        copy.addEventListener('click', function () {
            navigator.clipboard.writeText(pre.querySelector('code').innerText).then(function () {
                copy.textContent = labels[1];
                setTimeout(function () { copy.textContent = labels[0]; }, 1500);
            });
        });
        pre.appendChild(copy);
    });
})();
