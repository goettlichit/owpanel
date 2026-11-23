// Service worker of OWPanel (E-101). It only shows push messages, sets the
// badge on the app icon (E-119) and opens the panel when one is tapped; it caches nothing and works offline not at
// all, so every page still comes from the server.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }

    const title = typeof data.title === 'string' && data.title !== '' ? data.title : 'OWPanel';
    event.waitUntil(Promise.all([updateBadge(data.badge), self.registration.showNotification(title, {
        body: typeof data.body === 'string' ? data.body : '',
        tag: typeof data.tag === 'string' ? data.tag : undefined,
        renotify: typeof data.tag === 'string',
        icon: '/icons/icon-192.png',
        badge: '/icons/badge-96.png',
        data: { url: typeof data.url === 'string' ? data.url : '/' },
    })]));
});

// Number of open alerts on the app icon (E-119); 0 removes the badge.
// Only where the browser supports it, e.g. Home Screen apps on iOS 16.4+.
async function updateBadge(count) {
    if (!Number.isInteger(count) || count < 0 || !('setAppBadge' in self.navigator)) {
        return;
    }
    try {
        await (count === 0 ? self.navigator.clearAppBadge() : self.navigator.setAppBadge(count));
    } catch {
        // Badge permission turned off in the system settings.
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Only pages of the panel itself are opened.
    let url;
    try {
        url = new URL(event.notification.data?.url ?? '/', self.location.origin);
    } catch {
        url = new URL('/', self.location.origin);
    }
    if (url.origin !== self.location.origin) {
        url = new URL('/', self.location.origin);
    }

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                await client.focus();
                return client.navigate(url.href);
            }
        }
        return self.clients.openWindow(url.href);
    })());
});
