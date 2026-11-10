/**
 * KOP Tools > Plan Board (inc/plan-board.php): the views (This week, Everything for you, Waiting for Claude, Whole
 * plan), the kind, section and search filters and their counts; choices, Done / Not now, steps, notes, Edit and
 * Retire, each saved at once; keeps an unsent note per card in the browser, and copies the open answers for a
 * Claude session. A card answered in this view stays in sight until the view or a filter changes, so its Undo
 * stays at hand.
 */
(function () {
    var cfg = window.KOP_PLAN_BOARD || {};
    var root = document.querySelector('.kop-pb');
    if (!root) return;
    var VIEWS = ['week', 'needs', 'waiting', 'all'];
    var FLAT = { week: true, needs: true };
    var state = { view: 'week', kind: '', group: '', q: '' };
    try { var v = localStorage.getItem('kop-pb-view2'); if (VIEWS.indexOf(v) !== -1) state.view = v; } catch (e) {}

    function draftKey(id) { return 'kop-pb-draft:' + id; }
    function getDraft(id) { try { return localStorage.getItem(draftKey(id)) || ''; } catch (e) { return ''; } }
    function setDraft(id, val) { try { if (val) localStorage.setItem(draftKey(id), val); else localStorage.removeItem(draftKey(id)); } catch (e) {} }

    function restoreDrafts(scope) {
        scope.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var form = card.querySelector('[data-form="answer"]');
            var ta = form && form.querySelector('.kop-pb-ta');
            var d = getDraft(card.dataset.id);
            if (ta && d && !ta.value) { ta.value = d; form.hidden = false; }
        });
    }

    function inView(card, view) {
        var needs = card.dataset.needs === '1';
        if (view === 'week') return needs && card.dataset.focus === '1';
        if (view === 'needs') return needs;
        if (view === 'waiting') return card.dataset.waiting === '1';
        return true;
    }

    // This week and Everything for you are one list: decisions first (quickest), then checks, then tasks; newest
    // first within each. The other views put each card back in its section. A placeholder marks every card's home.
    var flat = root.querySelector('.kop-pb-flat');
    var KIND_ORDER = { decide: 0, check: 1, 'do': 2 };
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
        if (FLAT[state.view]) {
            cards.sort(function (a, b) {
                var ka = KIND_ORDER[a.dataset.kind] || 0, kb = KIND_ORDER[b.dataset.kind] || 0;
                if (ka !== kb) return ka - kb;
                var da = a.dataset.date || '', db = b.dataset.date || '';
                return da === db ? a.kopOrder - b.kopOrder : (da < db ? 1 : -1);
            });
            cards.forEach(function (card) { flat.appendChild(card); });
        } else {
            cards.forEach(function (card) { card.kopHome.parentNode.insertBefore(card, card.kopHome.nextSibling); });
        }
        flat.hidden = !FLAT[state.view];
    }

    function filter() {
        var shown = 0, counts = { week: 0, needs: 0, waiting: 0, all: 0 };
        root.classList.toggle('is-view-flat', !!FLAT[state.view]);
        root.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var match = (!state.group || card.dataset.group === state.group)
                && (!state.kind || card.dataset.kind === state.kind)
                && (!state.q || card.dataset.search.indexOf(state.q) !== -1);
            if (match) VIEWS.forEach(function (vw) { if (inView(card, vw)) counts[vw]++; });
            var ok = match && (inView(card, state.view) || card.dataset.keep === '1');
            card.hidden = !ok;
            if (ok) shown++;
        });
        VIEWS.forEach(function (vw) {
            var n = root.querySelector('[data-count="' + vw + '"]');
            if (n) n.textContent = counts[vw];
        });
        root.querySelectorAll('.kop-pb-group').forEach(function (g) {
            g.hidden = !g.querySelector('.kop-pb-card:not([hidden])');
        });
        root.querySelectorAll('.kop-pb-section').forEach(function (s) {
            s.hidden = !!FLAT[state.view] || !s.querySelector('.kop-pb-card:not([hidden])');
        });
        var none = root.querySelector('.kop-pb-none');
        if (none) {
            none.hidden = shown > 0;
            var plain = !state.q && !state.group && !state.kind;
            none.textContent = state.view === 'week' && plain ? 'Nothing left for this week. Everything for you has the rest.'
                : (state.view === 'needs' && plain ? 'Nothing is waiting on you.' : 'Nothing matches.');
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

    function showForm(card, name) {
        card.querySelectorAll('.kop-pb-form').forEach(function (f) { f.hidden = f.dataset.form !== name; });
        var f = name && card.querySelector('[data-form="' + name + '"]');
        var input = f && f.querySelector('textarea, input');
        if (input) input.focus();
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
        if (act === 'open' || act === 'edit-answer') { showForm(card, 'answer'); return; }
        if (act === 'open-edit') { showForm(card, 'edit'); return; }
        if (act === 'open-retire') { showForm(card, 'retire'); return; }
        if (act === 'cancel') { showForm(card, ''); return; }

        var data = { act: act, item: id };
        if (btn.dataset.step) data.step = btn.dataset.step;
        if (act === 'choice') { data.act = 'answer'; data.text = btn.dataset.choice || ''; }
        if (act === 'answer') {
            var ta = card.querySelector('[data-form="answer"] textarea');
            data.text = ta ? ta.value.trim() : '';
            if (!data.text) { say(card, 'Type something first, or use one of the buttons.', true); if (ta) ta.focus(); return; }
        }
        if (act === 'retire') {
            var why = card.querySelector('.kop-pb-retire-why');
            data.text = why ? why.value.trim() : '';
        }
        if (act === 'edit') {
            var f = card.querySelector('[data-form="edit"]');
            data.text = JSON.stringify({
                ask: f.querySelector('.kop-pb-edit-ask').value.trim(),
                why: f.querySelector('.kop-pb-edit-why').value.trim(),
                text: f.querySelector('.kop-pb-edit-text').value.trim()
            });
        }
        btn.disabled = true;
        say(card, 'Saving...');
        post(data).then(function (d) {
            if (!d || !d.success) { btn.disabled = false; say(card, (d && d.data && d.data.message) || 'Could not save.', true); return; }
            if (data.act === 'answer') setDraft(id, '');
            var home = card.kopHome, order = card.kopOrder;
            var fresh = replaceCard(card, d.data.html || '');
            if (fresh) {
                fresh.kopHome = home; fresh.kopOrder = order;
                // A step leaves the card in view (the next step is up); anything else keeps it in sight for Undo.
                fresh.dataset.keep = '1';
                say(fresh, d.data.message || 'Saved.');
            }
            var c = root.querySelector('.kop-pb-open-count');
            if (c && typeof d.data.open === 'number') c.textContent = d.data.open;
            filter();
        }).catch(function () { btn.disabled = false; say(card, 'Could not reach the site. Try again.', true); });
    });

    function setView(view) {
        if (VIEWS.indexOf(view) === -1) return;
        state.view = view;
        try { localStorage.setItem('kop-pb-view2', view); } catch (e) {}
        root.querySelectorAll('.kop-pb-tab').forEach(function (t) { t.setAttribute('aria-pressed', t.dataset.view === view ? 'true' : 'false'); });
        unkeep();
        layout();
        filter();
    }

    // Ctrl+Enter (Cmd+Enter) in a box saves it.
    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey)) return;
        var form = e.target.closest('.kop-pb-form');
        var save = form && form.querySelector('button.kop-ak-btn--primary, button.kop-ak-btn--danger');
        if (save) { e.preventDefault(); save.click(); }
    });

    root.addEventListener('input', function (e) {
        if (e.target.classList.contains('kop-pb-search')) { state.q = e.target.value.trim().toLowerCase(); unkeep(); filter(); return; }
        if (e.target.classList.contains('kop-pb-ta')) {
            var card = e.target.closest('.kop-pb-card');
            if (card && card.dataset.state === '') setDraft(card.dataset.id, e.target.value);
        }
    });
    root.addEventListener('change', function (e) {
        if (e.target.classList.contains('kop-pb-group-select')) { state.group = e.target.value; unkeep(); filter(); }
        if (e.target.classList.contains('kop-pb-kind-select')) { state.kind = e.target.value; unkeep(); filter(); }
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
