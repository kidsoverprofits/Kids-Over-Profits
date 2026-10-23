/**
 * KOP Tools > Plan Board (inc/plan-board.php): the views (Needs you, Waiting for Claude, Everything), the section
 * and search filters and their counts; saves and withdraws answers, keeps an unsent answer per item in the
 * browser, and copies the open answers for a Claude session. A card answered in this view stays in sight until
 * the view or filter changes, so its Change and Withdraw stay at hand.
 */
(function () {
    var cfg = window.KOP_PLAN_BOARD || {};
    var root = document.querySelector('.kop-pb');
    if (!root) return;
    var VIEWS = ['needs', 'waiting', 'all'];
    var state = { view: 'needs', group: '', q: '' };
    try { var v = localStorage.getItem('kop-pb-view'); if (VIEWS.indexOf(v) !== -1) state.view = v; } catch (e) {}

    function draftKey(id) { return 'kop-pb-draft:' + id; }
    function getDraft(id) { try { return localStorage.getItem(draftKey(id)) || ''; } catch (e) { return ''; } }
    function setDraft(id, v) { try { if (v) localStorage.setItem(draftKey(id), v); else localStorage.removeItem(draftKey(id)); } catch (e) {} }

    function restoreDrafts(scope) {
        scope.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var form = card.querySelector('.kop-pb-form');
            var ta = form && form.querySelector('.kop-pb-ta');
            var d = getDraft(card.dataset.id);
            if (ta && d && !ta.value) { ta.value = d; form.hidden = false; }
        });
    }

    function inView(card, view) {
        if (view === 'needs') return card.dataset.needs === '1' && !card.dataset.state;
        if (view === 'waiting') return !!card.dataset.state;
        return true;
    }

    // Needs you is one list, newest first (data-date; undated last, in plan order); the other views put each card
    // back in its section. A placeholder marks every card's home.
    var flat = root.querySelector('.kop-pb-flat');
    function layout() {
        var cards = Array.prototype.slice.call(root.querySelectorAll('.kop-pb-card'));
        cards.forEach(function (card, i) {
            if (!card.kopHome) {
                card.kopHome = document.createComment('pb');
                card.kopOrder = i;
                card.parentNode.insertBefore(card.kopHome, card);
            }
        });
        if (!flat) return;
        if (state.view === 'needs') {
            cards.sort(function (a, b) {
                var da = a.dataset.date || '', db = b.dataset.date || '';
                return da === db ? a.kopOrder - b.kopOrder : (da < db ? 1 : -1);
            });
            cards.forEach(function (card) { flat.appendChild(card); });
        } else {
            cards.forEach(function (card) { card.kopHome.parentNode.insertBefore(card, card.kopHome.nextSibling); });
        }
        flat.hidden = state.view !== 'needs';
    }

    function filter() {
        var shown = 0, counts = { needs: 0, waiting: 0, all: 0 };
        root.classList.toggle('is-view-needs', state.view === 'needs');
        root.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var match = (!state.group || card.dataset.group === state.group)
                && (!state.q || card.dataset.search.indexOf(state.q) !== -1);
            if (match) VIEWS.forEach(function (v) { if (inView(card, v)) counts[v]++; });
            var ok = match && (inView(card, state.view) || card.dataset.keep === '1');
            card.hidden = !ok;
            if (ok) shown++;
        });
        VIEWS.forEach(function (v) {
            var n = root.querySelector('[data-count="' + v + '"]');
            if (n) n.textContent = counts[v];
        });
        root.querySelectorAll('.kop-pb-group').forEach(function (g) {
            g.hidden = !g.querySelector('.kop-pb-card:not([hidden])');
        });
        root.querySelectorAll('.kop-pb-section').forEach(function (s) {
            s.hidden = state.view === 'needs' || !s.querySelector('.kop-pb-card:not([hidden])');
        });
        var none = root.querySelector('.kop-pb-none');
        if (none) {
            none.hidden = shown > 0;
            none.textContent = state.view === 'needs' && !state.q && !state.group ? 'Nothing is waiting on you.' : 'Nothing matches.';
        }
    }

    function unkeep() {
        root.querySelectorAll('.kop-pb-card[data-keep]').forEach(function (c) { delete c.dataset.keep; });
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
        if (tab) { setView(tab.dataset.view); return; }
        if (e.target.closest('.kop-pb-copy')) { copyOpen(e.target.closest('.kop-pb-copy')); return; }
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var card = btn.closest('.kop-pb-card');
        if (!card) return;
        var act = btn.dataset.act, id = card.dataset.id;
        if (act === 'edit' || act === 'open' || act === 'cancel') {
            var box = btn.closest('.kop-pb-edit, .kop-pb-form') || card.querySelector(act === 'edit' ? '.kop-pb-edit' : '.kop-pb-form');
            if (box) box.hidden = act === 'cancel';
            if (act !== 'cancel' && box) { var t = box.querySelector('textarea'); if (t) t.focus(); }
            return;
        }
        var data = { act: act, item: id };
        if (act === 'answer' || act === 'done' || act === 'later') {
            var form = btn.closest('.kop-pb-edit') || card.querySelector('.kop-pb-form');
            var ta = form && form.querySelector('textarea');
            data.text = ta ? ta.value.trim() : '';
            if (act === 'answer' && !data.text) { say(card, 'Type an answer first, or use Done or Not now.', true); if (ta) ta.focus(); return; }
        }
        btn.disabled = true;
        say(card, 'Saving...');
        post(data).then(function (d) {
            if (!d || !d.success) { btn.disabled = false; say(card, (d && d.data && d.data.message) || 'Could not save.', true); return; }
            setDraft(id, '');
            var home = card.kopHome, order = card.kopOrder;
            var fresh = replaceCard(card, d.data.html || '');
            if (fresh) { fresh.kopHome = home; fresh.kopOrder = order; fresh.dataset.keep = '1'; say(fresh, d.data.message || 'Saved.'); }
            var c = root.querySelector('.kop-pb-open-count');
            if (c && typeof d.data.open === 'number') c.textContent = d.data.open;
            filter();
        }).catch(function () { btn.disabled = false; say(card, 'Could not reach the site. Try again.', true); });
    });

    function setView(view) {
        if (VIEWS.indexOf(view) === -1) return;
        state.view = view;
        try { localStorage.setItem('kop-pb-view', view); } catch (e) {}
        root.querySelectorAll('.kop-pb-tab').forEach(function (t) { t.setAttribute('aria-pressed', t.dataset.view === view ? 'true' : 'false'); });
        unkeep();
        layout();
        filter();
    }

    // Ctrl+Enter (Cmd+Enter) in an answer box saves it.
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey) || !e.target.classList.contains('kop-pb-ta')) return;
        var save = e.target.parentNode.querySelector('button[data-act="answer"]');
        if (save) { e.preventDefault(); save.click(); }
    });

    root.addEventListener('input', function (e) {
        if (e.target.classList.contains('kop-pb-search')) { state.q = e.target.value.trim().toLowerCase(); unkeep(); filter(); return; }
        if (e.target.classList.contains('kop-pb-ta') && !e.target.closest('.kop-pb-edit')) {
            var card = e.target.closest('.kop-pb-card');
            if (card) setDraft(card.dataset.id, e.target.value);
        }
    });
    root.addEventListener('change', function (e) {
        if (e.target.classList.contains('kop-pb-group-select')) { state.group = e.target.value; unkeep(); filter(); }
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
    setView(state.view);
})();
