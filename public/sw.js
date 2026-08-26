const OFFLINE_CACHE = 'shoutrrr-offline-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(OFFLINE_CACHE).then((cache) => cache.add(OFFLINE_URL)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.startsWith('shoutrrr-offline-') &&
                                key !== OFFLINE_CACHE,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (
        request.method !== 'GET' ||
        request.mode !== 'navigate' ||
        url.origin !== self.location.origin
    ) {
        return;
    }

    event.respondWith(
        fetch(request).catch(() =>
            caches.match(OFFLINE_URL).then((response) => response || Response.error()),
        ),
    );
});
