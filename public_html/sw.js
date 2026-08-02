/* Service worker for the installed app.
 *
 * WHAT A SERVICE WORKER MUST NOT DO IN AN ACCOUNTING APP
 * -----------------------------------------------------
 * The usual advice is "cache pages so it works offline". Do that here and the
 * shop is shown a ledger balance, a stock figure or a customer's outstanding
 * from some earlier visit, with nothing on screen to say it is old. A stale
 * balance is worse than an error: an error is obvious and a wrong number is
 * acted on. Somebody takes payment against it.
 *
 * So the rule here is narrow and deliberate:
 *
 *   STATIC ASSETS   cache-first. A stylesheet or an icon can be a day old
 *                   without misleading anybody, and this is where the speed of
 *                   an installed app actually comes from.
 *   EVERYTHING ELSE network-only. Pages, forms, exports, every response that
 *                   could carry a figure. If the network is not there, the
 *                   request FAILS — and a navigation gets the offline page,
 *                   which says plainly that it is offline and shows no data at
 *                   all.
 *
 * Nothing that could contain money is ever read back out of a cache.
 *
 * POST is never touched: the Cache API cannot store it anyway, and a queued
 * sale replayed later against a moved rate board is its own disaster.
 */

// Bumping this deploys a new cache and drops the old one. deploy/tasks.sh does
// not fingerprint asset filenames, so this is what makes a CSS change reach a
// phone that already has the app installed.
const VERSION = 'mbista-v1';
const STATIC_CACHE = VERSION + '-static';
const OFFLINE_URL = '/offline.html';

// The shell: what the offline page itself needs in order to look like the app
// rather than a browser error. Kept short on purpose — every entry here is
// fetched on install, and a long list makes installation fail on a bad
// connection, which loses the whole service worker rather than one file.
const PRECACHE = [
    OFFLINE_URL,
    '/assets/img/icon-192.png',
    '/assets/img/favicon.svg'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(function (cache) { return cache.addAll(PRECACHE); })
            // A precache miss must not abort the install. Losing the offline
            // page is a worse outcome than shipping without one file in it.
            .catch(function () { return undefined; })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.map(function (key) {
                return key.indexOf(VERSION) === 0 ? undefined : caches.delete(key);
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

// The page asks for this when it notices a new worker waiting. Without the
// listener the postMessage lands nowhere and the update sits until every tab of
// the app is closed — on a phone, approximately never.
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/** Static enough to be worth caching, and harmless if it is a version behind. */
function isStaticAsset(url) {
    return /\.(css|js|png|jpe?g|svg|webp|gif|ico|woff2?|ttf|eot)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
    const request = event.request;

    // Only GET, and only this origin. A cross-origin request is somebody
    // else's to answer, and anything else is a mutation.
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Exports and downloads are generated per request and can be large; there
    // is nothing to gain by holding one and a real chance of handing back last
    // week's spreadsheet.
    if (url.pathname.indexOf('/export') !== -1 || url.searchParams.has('export')) {
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(
            caches.match(request).then(function (hit) {
                if (hit) {
                    return hit;
                }

                return fetch(request).then(function (response) {
                    // Opaque and error responses are not worth keeping — a
                    // cached 404 stylesheet outlives the deploy that fixes it.
                    if (response && response.status === 200 && response.type === 'basic') {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then(function (cache) { cache.put(request, copy); });
                    }

                    return response;
                });
            })
        );

        return;
    }

    // A page. Straight to the network, every time — and if there is no network,
    // the offline page, which shows no figures rather than old ones.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(OFFLINE_URL).then(function (hit) {
                    return hit || new Response(
                        '<h1>Offline</h1><p>No connection. Nothing is shown because the figures could be out of date.</p>',
                        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                    );
                });
            })
        );
    }

    // Anything else falls through to the network untouched.
});
