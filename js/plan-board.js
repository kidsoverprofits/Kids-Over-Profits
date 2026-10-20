/**
 * KOP Tools > Plan Board (inc/plan-board.php): filters the cards in the page, saves and withdraws answers,
 * keeps an unsent answer per item in the browser, and copies the open answers for a Claude session.
 */
(function () {
    var cfg = window.KOP_PLAN_BOARD || {};
    var root = document.querySelector('.kop-pb');
    if (!root) return;
    var state = { section: '', q: '', hide: false };

    function draftKey(id) { return 'kop-pb-draft:' + id; }
    function getDraft(id) { try { return localStorage.getItem(draftKey(id)) || ''; } catch (e) { return ''; } }
    function setDraft(id, v) { try { if (v) localStorage.setItem(draftKey(id), v); else localStorage.removeItem(draftKey(id)); } catch (e) {} }

    function restoreDrafts(scope) {
        scope.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var ta = card.querySelector(':scope > .kop-pb-answer > .kop-pb-ta');
            var d = getDraft(card.dataset.id);
            if (ta && d && !ta.value) ta.value = d;
        });
    }

    function filter() {
        var shown = 0;
        root.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var ok = (!state.section || card.dataset.section === state.section)
                && (!state.hide || !card.dataset.state)
                && (!state.q || card.dataset.search.indexOf(state.q) !== -1);
            card.hidden = !ok;
            if (ok) shown++;
        });
        root.querySelectorAll('.kop-pb-group').forEach(function (g) {
            g.hidden = !g.querySelector('.kop-pb-card:not([hidden])');
        });
        root.querySelectorAll('.kop-pb-section').forEach(function (s) {
            s.hidden = !s.querySelector('.kop-pb-card:not([hidden])');
        });
        var none = root.querySelector('.kop-pb-none');
        if (none) none.hidden = shown > 0;
    }

    function post(data) {
        var body = new FormData();
        body.append('action', 'kop_plan_board');
        body.append('nonce', cfg.nonce || '');
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function replaceCard(card, html) {
        var box = document.createElement('div');
        box.innerHTML = html;
        var fresh = box.firstElementChild;
        if (!fresh) { card.remove(); return null; }
        card.replaceWith(fresh);
        return fresh;
    }

    function say(card, text, bad) {
        var msg = card && card.querySelector('.kop-pb-msg');
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-bad', !!bad);
    }

    root.addEventListener('click', function (e) {
        var tab = e.target.closest('.kop-pb-tab');
        if (tab) {
            state.section = tab.dataset.section;
            root.querySelectorAll('.kop-pb-tab').forEach(function (t) { t.setAttribute('aria-pressed', t === tab ? 'true' : 'false'); });
            filter();
            return;
        }
        if (e.target.closest('.kop-pb-copy')) { copyOpen(e.target.closest('.kop-pb-copy')); return; }
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var card = btn.closest('.kop-pb-card');
        if (!card) return;
        var act = btn.dataset.act, id = card.dataset.id;
        if (act === 'edit' || act === 'cancel') {
            var edit = card.querySelector('.kop-pb-edit');
            if (edit) edit.hidden = act === 'cancel';
            if (act === 'edit' && edit) { var t = edit.querySelector('textarea'); if (t) t.focus(); }
            return;
        }
        var data = { act: act, item: id };
        if (act === 'answer' || act === 'done' || act === 'later') {
            var form = btn.closest('.kop-pb-edit') || card.querySelector('.kop-pb-answer');
            var ta = form.querySelector('textarea');
            data.text = ta ? ta.value.trim() : '';
            if (act === 'answer' && !data.text) { say(card, 'Type an answer first, or use Done or Not now.', true); if (ta) ta.focus(); return; }
        }
        btn.disabled = true;
        say(card, 'Saving...');
        post(data).then(function (d) {
            if (!d || !d.success) { btn.disabled = false; say(card, (d && d.data && d.data.message) || 'Could not save.', true); return; }
            setDraft(id, '');
            var fresh = replaceCard(card, d.data.html || '');
            if (fresh) { restoreDrafts(fresh.parentNode); say(fresh, d.data.message || 'Saved.'); }
            var c = root.querySelector('.kop-pb-open-count');
            if (c && typeof d.data.open === 'number') c.textContent = d.data.open;
            filter();
        }).catch(function () { btn.disabled = false; say(card, 'Could not reach the site. Try again.', true); });
    });

    root.addEventListener('input', function (e) {
        if (e.target.classList.contains('kop-pb-search')) { state.q = e.target.value.trim().toLowerCase(); filter(); return; }
        if (e.target.classList.contains('kop-pb-ta') && !e.target.closest('.kop-pb-edit')) {
            var card = e.target.closest('.kop-pb-card');
            if (card) setDraft(card.dataset.id, e.target.value);
        }
    });
    root.addEventListener('change', function (e) {
        if (e.target.classList.contains('kop-pb-hide-box')) { state.hide = e.target.checked; filter(); }
    });

    function copyOpen(btn) {
        btn.disabled = true;
        post({ act: 'export' }).then(function (d) {
            btn.disabled = false;
            var text = JSON.stringify((d && d.data && d.data.answers) || [], null, 1);
            var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy open answers'; }, 2000); };
            if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy the open answers:', text); });
            else window.prompt('Copy the open answers:', text);
        }).catch(function () { btn.disabled = false; });
    }

    restoreDrafts(root);
    filter();
})();
