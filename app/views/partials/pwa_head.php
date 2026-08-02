<?php
declare(strict_types=1);

/**
 * What turns the site into something a client can install on their phone.
 *
 * Included by every layout head, from ONE file, because four copies of these
 * tags is four places for the icon path to drift and three of them to be
 * forgotten. admin_header, client_header, staff_header and the public header
 * all pull this in.
 *
 * There is no native app here and there does not need to be one: with a
 * manifest, an icon and a service worker the browser offers "Add to Home
 * Screen", and what opens has its own icon, its own task in the switcher and no
 * browser chrome. On Android that is indistinguishable from a store app. On
 * iOS it is the same, minus background push — which this app does not use.
 *
 * The trade nobody mentions is that it updates when the server updates, so
 * there is no review queue between a fix and the counter that needs it.
 */
?>
<link rel="manifest" href="/manifest.webmanifest">
<?php // iOS reads NONE of the manifest's icons for the home screen. Leave this
      // out and the phone uses a screenshot of whatever page was open. ?>
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="M.Bista">
<?php // A phone keyboard must never be able to zoom the layout out of reach,
      // but pinch-zoom stays available — capping it would fail an accessibility
      // check and make a dense figure unreadable for anyone who needs it. ?>
<script>
(function () {
    if (!('serviceWorker' in navigator)) { return; }
    // Registered after load so it never competes with the page for the
    // connection — the shop is waiting on the page, not on the worker.
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (reg) {
            // A worker that has updated sits waiting until every tab closes,
            // which on a phone is approximately never. Told to skip waiting, a
            // deploy reaches the counter on the next page load instead of next
            // week.
            if (reg.waiting) { reg.waiting.postMessage({ type: 'SKIP_WAITING' }); }
            reg.addEventListener('updatefound', function () {
                var installing = reg.installing;
                if (!installing) { return; }
                installing.addEventListener('statechange', function () {
                    if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                        installing.postMessage({ type: 'SKIP_WAITING' });
                    }
                });
            });
        }).catch(function () {
            // An install that fails leaves an ordinary website, which works.
            // Nothing here is worth an error in front of a user.
        });
    });
})();
</script>
