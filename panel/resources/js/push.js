// Push messages (E-101): registers the service worker, asks the browser
// for permission and sends the subscription to the panel. Only on the
// page "Account → Notifications" ([data-ow-push]).

function keyToBytes(base64url) {
    const padded = base64url.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(base64url.length / 4) * 4, '=');
    return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
}

async function sha256Hex(text) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function isIos() {
    return /iPhone|iPad|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
}

async function markCurrentDevice(registration) {
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        return false;
    }
    const hash = await sha256Hex(subscription.endpoint);
    const row = document.querySelector(`[data-ow-push-device="${hash}"]`);
    row?.querySelector('[data-ow-push-current]')?.classList.remove('d-none');

    return row !== null;
}

async function setUp(box) {
    const show = (selector) => box.querySelector(selector)?.classList.remove('d-none');
    const button = box.querySelector('[data-ow-push-enable]');
    const message = box.querySelector('[data-ow-push-message]');
    const fail = (text) => {
        message.textContent = text;
        message.classList.remove('d-none');
    };

    if (!window.isSecureContext) {
        show('[data-ow-push-insecure]');
        return;
    }
    if (isIos() && !isStandalone()) {
        show('[data-ow-push-ios]');
        return;
    }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        show('[data-ow-push-unsupported]');
        return;
    }

    const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    if (await markCurrentDevice(registration)) {
        return;
    }
    if (Notification.permission === 'denied') {
        fail(box.dataset.denied);
        return;
    }

    button.classList.remove('d-none');
    button.addEventListener('click', async () => {
        const label = button.innerHTML;
        button.disabled = true;
        button.textContent = box.dataset.enabling;
        try {
            if (await Notification.requestPermission() !== 'granted') {
                fail(box.dataset.denied);
                return;
            }
            const ready = await navigator.serviceWorker.ready;
            const old = await ready.pushManager.getSubscription();
            await old?.unsubscribe();
            const subscription = await ready.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: keyToBytes(box.dataset.vapidKey),
            });
            const response = await fetch(box.dataset.subscribeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': box.dataset.csrf,
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON()),
            });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                await subscription.unsubscribe();
                fail(typeof data.message === 'string' ? data.message : box.dataset.failed);
                return;
            }
            window.location.reload();
        } catch {
            fail(box.dataset.failed);
        } finally {
            button.disabled = false;
            button.innerHTML = label;
        }
    });
}

document.querySelectorAll('[data-ow-push]').forEach((box) => {
    setUp(box).catch(() => {
        const message = box.querySelector('[data-ow-push-message]');
        message.textContent = box.dataset.failed;
        message.classList.remove('d-none');
    });
});
