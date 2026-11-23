// Tabler's JavaScript includes Bootstrap's components (dropdowns, modals,
// collapse for the mobile navigation, closing alerts).
import '@tabler/core/dist/js/tabler.esm.min.js';
import './alpine.js';

// Success messages close by themselves; warnings and errors stay until the
// user closes them (<x-flash :toast="true">, docs/DESIGN.md "Hinweise").
// At least eight seconds, longer for long texts (about 15 characters per
// second), at most 20. Pointer or focus on the message pauses the clock.
const AUTOHIDE_MIN_MS = 8000;
const AUTOHIDE_MAX_MS = 20000;

function autohide(toast) {
    const length = toast.textContent.trim().length;
    let remaining = Math.min(AUTOHIDE_MAX_MS, Math.max(AUTOHIDE_MIN_MS, length * 66));
    let started = Date.now();
    let timer = null;

    const close = () => toast.querySelector('[data-bs-dismiss="alert"]')?.click();
    const run = () => {
        started = Date.now();
        timer = setTimeout(close, remaining);
    };
    const pause = () => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
            remaining = Math.max(2000, remaining - (Date.now() - started));
        }
    };

    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('mouseleave', () => timer === null && run());
    toast.addEventListener('focusout', () => timer === null && run());
    run();
}

document.querySelectorAll('[data-ow-autohide]').forEach(autohide);

const ICONS = { success: 'check', warning: 'alert-triangle', danger: 'alert-circle' };

// Toasts from Livewire components, e.g. when a server task finishes:
// $this->dispatch('owpanel-toast', type: 'success', message: '…').
// Same markup as <x-flash>; the text is set as text, never as HTML.
window.addEventListener('owpanel-toast', (event) => {
    const stack = document.querySelector('.ow-toasts-stack');
    const type = Object.hasOwn(ICONS, event.detail?.type) ? event.detail.type : 'warning';
    if (!stack || typeof event.detail?.message !== 'string') {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `alert alert-${type} alert-dismissible ow-toast`;
    toast.setAttribute('role', type === 'success' ? 'status' : 'alert');

    const row = document.createElement('div');
    row.className = 'd-flex';
    const icon = document.createElement('i');
    icon.className = `ti ti-${ICONS[type]} alert-icon`;
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('div');
    text.textContent = event.detail.message;
    row.append(icon, text);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.dataset.bsDismiss = 'alert';
    close.setAttribute('aria-label', stack.dataset.closeLabel ?? 'Close');

    toast.append(row, close);
    stack.append(toast);
    if (type === 'success') {
        autohide(toast);
    }
});

// Copy buttons (<x-copy-button>). The Clipboard API exists only in a
// secure context (HTTPS or localhost); a panel reached over plain HTTP,
// e.g. by IP before it has a certificate, falls back to a hidden textarea.
function copyFallback(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.append(area);
    area.select();
    try {
        return document.execCommand('copy');
    } finally {
        area.remove();
    }
}

async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch {
            // Denied by the browser: try the fallback below.
        }
    }

    return copyFallback(text);
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-ow-copy]');
    if (!button) {
        return;
    }

    const copied = await copyText(button.dataset.owCopy);
    const label = copied ? button.dataset.owCopied : button.dataset.owCopyFailed;
    const icon = button.querySelector('.ti');
    button.title = label;
    button.setAttribute('aria-label', label);
    icon.className = `ti ti-${copied ? 'check' : 'x'}`;
    clearTimeout(button.owCopyTimer);
    button.owCopyTimer = setTimeout(() => {
        button.title = button.dataset.owCopyLabel;
        button.setAttribute('aria-label', button.dataset.owCopyLabel);
        icon.className = 'ti ti-copy';
    }, 1500);
});

// Ctrl+Enter (Cmd+Enter on a Mac) sends forms marked with
// data-ow-submit-shortcut, e.g. the SQL console. Plain JavaScript, because
// Alpine is only loaded on pages with a Livewire component.
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !(event.ctrlKey || event.metaKey)) {
        return;
    }
    const form = event.target.closest?.('form[data-ow-submit-shortcut]');
    if (form) {
        event.preventDefault();
        form.requestSubmit();
    }
});

// Logs open at their end, where the newest lines are (data-ow-scroll-end,
// e.g. website logs), also when Livewire puts them in after the page has
// loaded; each one only once, so reading further up is not interrupted.
const scrollToEnd = () => {
    document.querySelectorAll('[data-ow-scroll-end]:not([data-ow-scrolled])').forEach((element) => {
        element.scrollTop = element.scrollHeight;
        element.dataset.owScrolled = '1';
    });
};
scrollToEnd();
new MutationObserver(scrollToEnd).observe(document.body, { childList: true, subtree: true });

// Forms marked with data-ow-submit-on-change send themselves when a select
// changes, e.g. the log source; the button stays for keyboards and no JS.
document.querySelectorAll('form[data-ow-submit-on-change] select').forEach((select) => {
    select.addEventListener('change', () => select.form.requestSubmit());
});

// A select with data-ow-fill="<id>" writes its value into that field, e.g.
// the schedule presets for cron jobs; the field stays editable.
document.querySelectorAll('select[data-ow-fill]').forEach((select) => {
    select.addEventListener('change', () => {
        const target = document.getElementById(select.dataset.owFill);
        if (target && select.value !== '') {
            target.value = select.value;
            target.focus();
        }
    });
});

// Push messages on the notifications page (E-101).
import './push.js';

// Badge on the app icon (E-119): every page brings the current number of
// open alerts, so opening the panel corrects a badge that is out of date.
const openAlerts = document.querySelector('meta[name="ow-open-alerts"]');
if (openAlerts && 'setAppBadge' in navigator) {
    const count = Number.parseInt(openAlerts.content, 10);
    if (Number.isInteger(count) && count >= 0) {
        (count === 0 ? navigator.clearAppBadge() : navigator.setAppBadge(count)).catch(() => {});
    }
}

// Forms marked with data-ow-busy show that the panel is working once they
// are sent: the submit button turns into a spinner with the given text and
// cannot be pressed twice, and an optional [data-ow-busy-note] appears.
const showBusy = (form) => {
    const button = form.querySelector('button[type="submit"]');
    if (button) {
        button.disabled = true;
        // The spinner and text may be longer than the label: the button
        // grows instead of letting them run out of it (or out of the card).
        button.classList.add('text-nowrap', 'flex-shrink-0');
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('role', 'status');
        button.replaceChildren(spinner, document.createTextNode(form.dataset.owBusy));
    }
    form.querySelector('[data-ow-busy-note]')?.classList.remove('d-none');
};

// A form is known by where it goes; the same page has each action once.
const formKey = (form) => `${form.getAttribute('action')}|${form.querySelector('input[name="_method"]')?.value ?? form.method}`;
const PENDING = 'ow-pending-task';
const readPending = () => {
    try {
        return JSON.parse(sessionStorage.getItem(PENDING) ?? 'null');
    } catch {
        return null;
    }
};
const writePending = (value) => {
    try {
        if (value === null) {
            sessionStorage.removeItem(PENDING);
        } else {
            sessionStorage.setItem(PENDING, JSON.stringify(value));
        }
    } catch {
        // Private windows may refuse storage; the fallback line still shows.
    }
};

// Delegated, so forms that Livewire renders later work the same way. A form
// whose sending was stopped (e.g. by the browser's validation) stays as is.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-ow-busy]') || event.defaultPrevented) {
        return;
    }
    showBusy(form);
    writePending({ key: formKey(form), at: Date.now() });
});

// Server tasks (E-152): the page stays where it is. While the tasks of the
// page run, the button that started one keeps its spinner, also after the
// page loads again; a button for the running script (data-ow-task) when it
// was started elsewhere. Without either, a line above the content says what
// runs. The task watcher reloads the page when the tasks are done.
const watcher = document.querySelector('[data-ow-task-watcher]');
if (watcher) {
    const open = Number.parseInt(watcher.dataset.open ?? '0', 10);
    const pending = readPending();
    if (open === 0) {
        writePending(null);
    } else {
        const forms = [...document.querySelectorAll('form[data-ow-busy]')];
        const scripts = (watcher.dataset.scripts ?? '').split(' ').filter(Boolean);
        let busy = pending && Date.now() - pending.at < 6 * 3600 * 1000
            ? forms.filter((form) => formKey(form) === pending.key)
            : [];
        if (busy.length === 0) {
            busy = forms.filter((form) => (form.dataset.owTask ?? '').split(' ').some((script) => scripts.includes(script)));
        }
        busy.forEach(showBusy);
        if (busy.length === 0) {
            watcher.querySelector('[data-ow-task-fallback]')?.classList.remove('d-none');
        }
    }
}

// Livewire shows failed requests in a window. While a server script
// reloads nginx or PHP-FPM on the panel's own server, a single poll can
// get 502, 503 or 504 from nginx; the next poll works again, so these are
// not shown.
const quietGatewayErrors = () => window.Livewire.interceptRequest(({ onError }) => {
    onError(({ response, preventDefault }) => {
        if ([502, 503, 504].includes(response.status)) {
            preventDefault();
        }
    });
});
if (window.Livewire) {
    quietGatewayErrors();
} else {
    document.addEventListener('livewire:init', quietGatewayErrors, { once: true });
}
