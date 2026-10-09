(function () {
    'use strict';

    var config = window.psduPayment || {};

    function copyText(value, button) {
        function complete() {
            var original = button.textContent;
            button.textContent = config.copiedLabel || 'Copied';
            button.classList.add('is-copied');
            window.setTimeout(function () {
                button.textContent = original || config.copyLabel || 'Copy';
                button.classList.remove('is-copied');
            }, 1600);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(complete);
            return;
        }

        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        complete();
    }

    function formatRemaining(milliseconds) {
        var seconds = Math.max(0, Math.floor(milliseconds / 1000));
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var remainder = seconds % 60;

        if (hours > 0) {
            return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
        }
        return String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
    }

    function initPanel(panel) {
        var orderId = panel.getAttribute('data-order-id');
        var orderKey = panel.getAttribute('data-order-key');
        var address = panel.getAttribute('data-address');
        var expiresValue = panel.getAttribute('data-expires');
        var expiry = expiresValue ? Date.parse(expiresValue) : NaN;
        var countdown = panel.querySelector('[data-payment-countdown]');
        var help = panel.querySelector('[data-payment-help]');
        var expiredHelp = panel.getAttribute('data-expired-help') || config.expiredHelp;
        var verifiedHelp = panel.getAttribute('data-verified-help') || config.verifiedHelp;
        var reviewHelp = panel.getAttribute('data-review-help') || config.reviewHelp;
        var stopped = panel.getAttribute('data-state') === 'paid';
        var reloadScheduled = false;
        var pollDelay = 5000;

        panel.querySelectorAll('[data-copy-value]').forEach(function (button) {
            button.addEventListener('click', function () {
                copyText(button.getAttribute('data-copy-value') || '', button);
            });
        });

        var qrTarget = panel.querySelector('[data-payment-qr]');
        if (qrTarget && address && window.QRCode) {
            qrTarget.textContent = '';
            new window.QRCode(qrTarget, {
                text: address,
                width: 220,
                height: 220,
                colorDark: '#112238',
                colorLight: '#ffffff',
                correctLevel: window.QRCode.CorrectLevel.M
            });
        }

        function setState(state, label) {
            panel.className = panel.className.replace(/psdu-panel--[a-z_-]+/g, '').trim();
            panel.classList.add('psdu-panel--' + state);
            panel.setAttribute('data-state', state);
            var statusLabel = panel.querySelector('[data-payment-status-label]');
            if (statusLabel) {
                statusLabel.textContent = label || config.checkingLabel || 'Checking payment status';
            }
        }

        function updateCountdown() {
            if (!countdown || Number.isNaN(expiry) || stopped) {
                return;
            }

            var remaining = expiry - Date.now();
            countdown.textContent = formatRemaining(remaining);
            if (remaining <= 0 && panel.getAttribute('data-state') === 'pending') {
                setState('expired', config.expiredLabel || 'Payment expired');
                stopped = true;
                if (help) {
                    help.textContent = expiredHelp || 'The payment window has ended. Create a new order before sending funds.';
                }
            }
        }

        function poll() {
            if (stopped || !config.ajaxUrl || !orderId || !orderKey) {
                return;
            }

            var body = new URLSearchParams();
            body.set('action', 'psdu_status');
            body.set('order_id', orderId);
            body.set('order_key', orderKey);

            window.fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('status');
                }
                return response.json();
            }).then(function (response) {
                if (!response || !response.success || !response.data) {
                    throw new Error('payload');
                }

                pollDelay = 5000;
                setState(response.data.status || 'unknown', response.data.label);

                if (response.data.expires_at) {
                    expiry = Date.parse(response.data.expires_at);
                }

                if (response.data.paid) {
                    stopped = true;
                    if (help) {
                        help.textContent = verifiedHelp || 'Payment verified. Updating the order.';
                    }
                    if (!reloadScheduled) {
                        reloadScheduled = true;
                        window.setTimeout(function () { window.location.reload(); }, 1400);
                    }
                    return;
                }

                if (response.data.status === 'review' && help) {
                    help.textContent = reviewHelp || 'Payment received. Contact support for a manual review.';
                }

                if (['expired', 'failed', 'cancelled', 'refunded', 'review'].indexOf(response.data.status) !== -1) {
                    stopped = true;
                    return;
                }

                window.setTimeout(poll, pollDelay);
            }).catch(function () {
                pollDelay = Math.min(20000, pollDelay + 5000);
                if (help) {
                    help.textContent = config.networkError || 'Connection interrupted. Retrying automatically.';
                }
                window.setTimeout(poll, pollDelay);
            });
        }

        updateCountdown();
        window.setInterval(updateCountdown, 1000);
        window.setTimeout(poll, 1500);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-psdu-payment]').forEach(initPanel);
    });
}());
