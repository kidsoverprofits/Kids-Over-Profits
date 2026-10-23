/**
 * Sign up to review on /volunteer/ (inc/volunteer-signup.php): posts the name
 * and optional email to kop/v1/volunteer/signup and shows the new link once.
 * The answer also signs this device in, so "Start reviewing" opens the page.
 */
(function () {
    'use strict';

    function init(box) {
        const form = box.querySelector('.kop-vols-form');
        const done = box.querySelector('.kop-vols-done');
        const error = box.querySelector('.kop-vols-error');
        const send = form.querySelector('button[type="submit"]');
        if (!form || !done) return;

        function showError(html) {
            error.innerHTML = html;
            error.hidden = false;
        }

        function esc(text) {
            const div = document.createElement('div');
            div.textContent = String(text);
            return div.innerHTML;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            error.hidden = true;
            const name = form.elements.name.value.trim();
            if (name.length < 2) {
                showError('Give a name or a nickname, so we know who made each call.');
                form.elements.name.focus();
                return;
            }
            send.disabled = true;
            send.textContent = 'Making your link...';
            fetch(form.dataset.rest, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    email: form.elements.email.value.trim(),
                    website: form.elements.website.value,
                }),
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (r) {
                    if (!r.ok || !r.data.url) {
                        if (r.data.signed_in && r.data.page) {
                            showError(esc(r.data.error) + ' <a href="' + esc(r.data.page) + '">Open your volunteer page</a>.');
                        } else {
                            showError(esc(r.data.error || r.data.message || 'Something went wrong. Please try again.'));
                        }
                        return;
                    }
                    done.querySelector('.kop-vols-done-h').textContent = 'Your link is ready, ' + r.data.name + '.';
                    done.querySelector('.kop-vols-link input').value = r.data.url;
                    if (r.data.page) done.querySelector('.kop-vols-start').href = r.data.page;
                    form.hidden = true;
                    done.hidden = false;
                    done.focus();
                })
                .catch(function () {
                    showError('We could not reach the site. Check your connection and try again.');
                })
                .finally(function () {
                    send.disabled = false;
                    send.textContent = 'Get my link';
                });
        });

        // A device that already has a link goes to its page instead of filling the form in again.
        const back = box.querySelector('.kop-vols-back');
        if (back) {
            fetch(form.dataset.rest, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) { return res.ok ? res.json() : {}; })
                .then(function (data) {
                    if (!data || !data.signed_in || !done.hidden) return;
                    back.querySelector('.kop-vols-back-h').textContent = 'You are signed in on this device' + (data.name ? ' as ' + data.name : '') + '.';
                    if (data.page) back.querySelector('.kop-vols-start').href = data.page;
                    form.hidden = true;
                    back.hidden = false;
                })
                .catch(function () { /* the form stays */ });
        }

        box.querySelector('.kop-vols-copy').addEventListener('click', function () {
            const input = done.querySelector('.kop-vols-link input');
            const button = this;
            input.select();
            const copied = function () { button.textContent = 'Copied'; };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(copied, function () { document.execCommand('copy'); copied(); });
            } else {
                document.execCommand('copy');
                copied();
            }
        });
    }

    document.querySelectorAll('.kop-vols').forEach(init);
})();
