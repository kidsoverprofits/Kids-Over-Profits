/**
 * Contact form on /contact/ (inc/contact-messages.php). The page is cached for everyone, so on load the form asks
 * GET kop/v1/contact for a fresh token (nonce + signed start time), then posts the message to POST kop/v1/contact
 * and swaps the form for a plain thank-you. Nothing is mailed to the sender.
 */
(function () {
    'use strict';

    function init(box) {
        const form = box.querySelector('.kop-contact-form');
        const done = box.querySelector('.kop-contact-done');
        if (!form || !done) return;
        const error = form.querySelector('.kop-vols-error');
        const send = form.querySelector('button[type="submit"]');
        const detailBox = form.querySelector('.kop-contact-detail');
        const detailLabel = detailBox ? detailBox.querySelector('label') : null;
        const el = form.elements;
        const rest = form.dataset.rest;
        let token = null;
        let retried = false;

        function getToken() {
            return fetch(rest, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.nonce && data.started) token = data;
                    return token;
                })
                .catch(function () { return null; });
        }

        function showError(text) {
            error.textContent = text;
            error.hidden = false;
        }

        function method() {
            const picked = form.querySelector('input[name="contact_method"]:checked');
            return picked ? picked.value : '';
        }

        // Signal or phone needs the name or number to use.
        function syncDetail() {
            if (!detailBox) return;
            const m = method();
            detailBox.hidden = !(m === 'signal' || m === 'phone');
            if (detailLabel) detailLabel.textContent = m === 'phone' ? 'Phone number' : 'Signal username or number';
        }
        form.querySelectorAll('input[name="contact_method"]').forEach(function (r) { r.addEventListener('change', syncDetail); });
        syncDetail();

        function finish() {
            send.disabled = false;
            send.textContent = 'Send';
        }

        function post(body) {
            return fetch(rest, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data }; });
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            error.hidden = true;
            const m = method();
            const body = {
                type: el.type.value,
                name: el.name.value.trim(),
                email: el.email.value.trim(),
                facility: el.facility.value.trim(),
                message: el.message.value,
                contact_method: m,
                contact_detail: (m === 'signal' || m === 'phone') ? el.contact_detail.value.trim() : '',
                website: el.website.value,
            };
            if (!body.type) { showError('Choose what your message is about.'); el.type.focus(); return; }
            if (!body.email) { showError('Give an email address so we can answer you.'); el.email.focus(); return; }
            if (body.message.trim().length < 5) { showError('Write your message.'); el.message.focus(); return; }
            if ((m === 'signal' || m === 'phone') && !body.contact_detail) {
                showError(m === 'signal' ? 'Add your Signal username or number, or choose email.' : 'Add the phone number to call, or choose email.');
                el.contact_detail.focus();
                return;
            }
            send.disabled = true;
            send.textContent = 'Sending...';
            (token ? Promise.resolve(token) : getToken())
                .then(function (t) {
                    if (!t) throw new Error('offline');
                    body.nonce = t.nonce;
                    body.started = t.started;
                    return post(body);
                })
                .then(function (r) {
                    if (r.ok && r.data.ok) {
                        form.hidden = true;
                        done.hidden = false;
                        done.focus();
                        return;
                    }
                    // The token went stale (page open for hours): get a new one; the person presses Send again.
                    if (r.data.stale && !retried) {
                        retried = true;
                        token = null;
                        getToken();
                    }
                    showError(r.data.error || r.data.message || 'Something went wrong. Please try again.');
                })
                .catch(function () {
                    showError('We could not reach the site. Check your connection and try again. Your message is still in the form.');
                })
                .finally(finish);
        });

        getToken();
    }

    function start() {
        document.querySelectorAll('.kop-contact').forEach(init);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
