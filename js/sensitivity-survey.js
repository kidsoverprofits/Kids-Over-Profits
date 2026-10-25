/**
 * Sensitivity reader survey on /volunteer/ (inc/sensitivity-survey.php): posts the answers to
 * kop/v1/volunteer/sensitivity-survey and swaps the form for a thank-you. A link to
 * #kop-sens-survey or #kop-volpage-sensitivity opens the folded survey.
 */
(function () {
    'use strict';

    function init(box) {
        const form = box.querySelector('.kop-sens-form');
        const done = box.querySelector('.kop-sens-done');
        if (!form || !done) return;
        const error = form.querySelector('.kop-vols-error');
        const send = form.querySelector('button[type="submit"]');
        const el = form.elements;

        function showError(text) {
            error.textContent = text;
            error.hidden = false;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            error.hidden = true;
            const groups = Array.prototype.filter.call(form.querySelectorAll('input[name="groups"]'), function (c) { return c.checked; })
                .map(function (c) { return c.value; });
            const body = {
                groups: groups,
                group_other: el.group_other.value.trim(),
                gaps: el.gaps.value,
                harm: el.harm.value,
                context: el.context.value,
                pages: el.pages.value,
                more: el.more.value,
                name: el.name.value.trim(),
                email: el.email.value.trim(),
                read_later: el.read_later.checked ? 1 : 0,
                website: el.website.value,
            };
            if (!groups.length && !body.group_other) {
                showError('Tick the community you are answering for, or write it in.');
                return;
            }
            if (body.read_later && !body.email) {
                showError('To be asked to read drafts later, leave an email address so we can reach you.');
                el.email.focus();
                return;
            }
            send.disabled = true;
            send.textContent = 'Sending...';
            fetch(form.dataset.rest, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data }; });
                })
                .then(function (r) {
                    if (!r.ok || !r.data.ok) {
                        showError(r.data.error || r.data.message || 'Something went wrong. Please try again.');
                        return;
                    }
                    form.hidden = true;
                    done.hidden = false;
                    done.focus();
                })
                .catch(function () {
                    showError('We could not reach the site. Check your connection and try again. Your answers are still in the form.');
                })
                .finally(function () {
                    send.disabled = false;
                    send.textContent = 'Send my answers';
                });
        });
    }

    function openFromHash() {
        const box = document.getElementById('kop-sens-survey');
        if (box && (location.hash === '#kop-sens-survey' || location.hash === '#kop-volpage-sensitivity')) box.open = true;
    }

    function start() {
        document.querySelectorAll('.kop-sens').forEach(init);
        openFromHash();
        window.addEventListener('hashchange', openFromHash);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
