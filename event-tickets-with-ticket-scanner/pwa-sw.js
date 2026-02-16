var CACHE_NAME = 'ticket-scanner-v1';

var CACHE_URLS_SUBSTRING = [
    'html5-qrcode',
    'qr-scanner',
    'jquery',
    'ticket_scanner.js',
    'jquery.qrcode',
    '.css',
    '.woff',
    '.woff2',
    '.ttf',
    '.png',
    '.gif',
    '.jpg',
    '.svg'
];

var NEVER_CACHE_SUBSTRING = [
    '/wp-json/',
    'admin-ajax.php',
    'wp-login.php',
    'wp-cron.php'
];

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return name.startsWith('ticket-scanner-') && name !== CACHE_NAME;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(event) {
    var url = event.request.url;

    if (event.request.method !== 'GET') return;

    for (var i = 0; i < NEVER_CACHE_SUBSTRING.length; i++) {
        if (url.indexOf(NEVER_CACHE_SUBSTRING[i]) !== -1) return;
    }

    var shouldCacheFirst = false;
    for (var j = 0; j < CACHE_URLS_SUBSTRING.length; j++) {
        if (url.indexOf(CACHE_URLS_SUBSTRING[j]) !== -1) {
            shouldCacheFirst = true;
            break;
        }
    }

    if (shouldCacheFirst) {
        event.respondWith(
            caches.match(event.request).then(function(cached) {
                if (cached) return cached;
                return fetch(event.request).then(function(response) {
                    if (response.ok) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(event.request, clone);
                        });
                    }
                    return response;
                });
            })
        );
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(function() {
                return caches.match(event.request);
            })
        );
        return;
    }
});
