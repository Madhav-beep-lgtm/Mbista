/**
 * Send ZPL to a Zebra printer through Zebra Browser Print.
 *
 * Browser Print is a small agent Zebra ships that runs on the billing PC and
 * exposes the attached printers over a local HTTP API. This talks to that API
 * directly rather than loading Zebra's BrowserPrint SDK: the SDK would have to
 * be fetched from Zebra and served from here, and the API it wraps is three
 * calls. Nothing in this file leaves the machine.
 *
 * HTTPS is tried first and matters more than it looks. When the app is served
 * over https (mbca.com.np is), a call to http://127.0.0.1 is blocked by the
 * browser as mixed content and fails with no useful error — so the https port
 * the agent also listens on is the one that works in production, and the plain
 * one is only reached on a local http dev server.
 */
(function (window, document) {
    'use strict';

    var BASES = ['https://127.0.0.1:9101/', 'http://127.0.0.1:9100/'];
    var agentBase = null;

    function request(base, path, options) {
        var opts = options || {};
        opts.cache = 'no-store';

        return fetch(base + path, opts).then(function (response) {
            if (!response.ok) {
                throw new Error('Browser Print replied ' + response.status);
            }
            return response;
        });
    }

    /** The first port the agent answers on, remembered for later calls. */
    function findAgent() {
        if (agentBase) {
            return Promise.resolve(agentBase);
        }

        return BASES.reduce(function (chain, base) {
            return chain.catch(function () {
                return request(base, 'available').then(function () {
                    agentBase = base;
                    return base;
                });
            });
        }, Promise.reject()).catch(function () {
            throw new Error(
                'Zebra Browser Print is not answering on this PC. Install it from zebra.com, '
                + 'make sure it is running (its icon sits in the system tray), and that the ZD230 is '
                + 'switched on and connected.'
            );
        });
    }

    /** The printer Browser Print considers default, or the first one it has. */
    function defaultDevice() {
        return findAgent().then(function (base) {
            return request(base, 'default?type=printer')
                .then(function (r) { return r.json(); })
                .catch(function () {
                    return request(base, 'available')
                        .then(function (r) { return r.json(); })
                        .then(function (list) {
                            var printers = (list && list.printer) || [];
                            if (!printers.length) {
                                throw new Error('Browser Print is running but no printer is attached to it.');
                            }
                            return printers[0];
                        });
                });
        });
    }

    /** Push a raw ZPL string at the printer. */
    function send(zpl) {
        return findAgent().then(function (base) {
            return defaultDevice().then(function (device) {
                return request(base, 'write', {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                    body: JSON.stringify({ device: device, data: zpl })
                }).then(function () { return device; });
            });
        });
    }

    window.ZebraTagPrint = {
        available: function () { return findAgent().then(function () { return true; }); },
        device: defaultDevice,
        send: send
    };

    // ---- Page wiring -------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        var status = document.getElementById('tag-print-status');

        function say(message, tone) {
            if (!status) { return; }
            status.textContent = message;
            status.className = 'notice ' + (tone || 'success');
            status.style.display = 'block';
        }

        // Every button that prints carries the URL its ZPL comes from, so the
        // server stays the single author of what a tag looks like.
        document.querySelectorAll('[data-zpl-url]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var url = button.getAttribute('data-zpl-url');
                say('Building the label…', 'success');
                button.disabled = true;

                fetch(url, { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) {
                        if (!r.ok) { throw new Error('The label could not be built (' + r.status + ').'); }
                        return r.text();
                    })
                    .then(function (zpl) {
                        if (zpl.indexOf('^XA') === -1) {
                            throw new Error('The server did not return a label. Are any items selected?');
                        }
                        return window.ZebraTagPrint.send(zpl).then(function (device) {
                            var count = (zpl.match(/\^XA/g) || []).length;
                            say('Sent ' + count + ' label' + (count === 1 ? '' : 's') + ' to '
                                + ((device && (device.name || device.uid)) || 'the printer') + '.', 'success');
                        });
                    })
                    .catch(function (error) {
                        // The download link beside the button is the way out when
                        // the agent is missing, so the message points at it.
                        say(error.message + ' You can still use “Download .zpl” and send the file to the printer.', 'error');
                    })
                    .then(function () { button.disabled = false; });
            });
        });
    });
}(window, document));
