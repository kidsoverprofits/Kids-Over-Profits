/**
 * KOP Tools > Plan Board (inc/plan-board.php, inc/plan-board-threads.php): the views (Your list grouped by status,
 * Waiting for Claude, Whole plan), the kind, section and search filters and their counts; rows that open in place;
 * choices, Done / Not now, Edit, Retire, Needs research and Send to Claude; the tracker's part: the Pick up here
 * panel, the left-off note, steps ticked, reworded, moved, removed and added, a note per step, the status, items
 * started here. Every change is saved at once and the server sends the card back as it now stands.
 *
 * Nothing typed is lost when a card is redrawn: a box that was changed and not saved yet, the box with the focus and
 * its caret, an open form and an open step note are carried into the new card (carry()). Text boxes save when they
 * lose focus, and the card is redrawn a moment later, so a click on a button next to the box still lands.
 */
(function () {
    var cfg = window.KOP_PLAN_BOARD || {};
    var root = document.querySelector('.kop-pb');
    if (!root) return;
    var VIEWS = ['mine', 'claude', 'all'];
    var STATUSES = ['active', 'waiting', 'parked', 'done'];
    var state = { view: 'mine', kind: '', group: '', q: '', showDone: false };
    var openCards = {};      // item id => true: the rows that are open
    var seq = 0, newest = {}; // the order of replies per item, so a late redraw never puts back older html
    try { var v = localStorage.getItem('kop-pb-view3'); if (VIEWS.indexOf(v) !== -1) state.view = v; } catch (e) {}

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

    function cardOf(id) {
        var found = null;
        root.querySelectorAll('.kop-pb-card').forEach(function (c) { if (c.dataset.id === id) found = c; });
        return found;
    }

    function grow(el) {
        if (!el || el.offsetParent === null) return;
        el.style.height = 'auto';
        el.style.height = (el.scrollHeight + 2) + 'px';
    }
    function growAll(scope) { scope.querySelectorAll('textarea.kop-pb-grow').forEach(grow); }

    function inView(card, view) {
        var st = card.dataset.status;
        if (view === 'mine') return STATUSES.indexOf(st) !== -1;
        if (view === 'claude') return st === 'claude' || card.dataset.waiting === '1';
        return true;
    }

    // Your list is four lists, one per status, most recently touched first. The other views put each card back in
    // its section. A placeholder marks every card's home.
    var flat = root.querySelector('.kop-pb-flat');
    function listOf(status) { return flat && flat.querySelector('.kop-pb-status-group[data-status="' + status + '"] .kop-pb-list'); }
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
        var grouped = state.view === 'mine';
        if (grouped) {
            cards.sort(function (a, b) { return (+b.dataset.touched || 0) - (+a.dataset.touched || 0) || a.kopOrder - b.kopOrder; });
        }
        // A card is moved only when it is out of place: moving an element takes the focus out of it.
        var a = document.activeElement, sel = null;
        if (a && root.contains(a)) { try { sel = [a.selectionStart, a.selectionEnd]; } catch (e) {} }
        var at = {};
        cards.forEach(function (card) {
            var list = grouped ? listOf(card.dataset.status) : null;
            if (list) {
                var st = card.dataset.status, want = list.children[at[st] || 0] || null;
                if (want !== card) list.insertBefore(card, want);
                at[st] = (at[st] || 0) + 1;
            } else if (card.kopHome.nextSibling !== card) {
                card.kopHome.parentNode.insertBefore(card, card.kopHome.nextSibling);
            }
        });
        if (a && a !== document.activeElement && root.contains(a) && a.offsetParent !== null) {
            a.focus({ preventScroll: true });
            try { if (sel && sel[0] !== null) a.setSelectionRange(sel[0], sel[1]); } catch (e) {}
        }
        flat.hidden = !grouped;
    }

    function filter() {
        var shown = 0, counts = { mine: 0, claude: 0, all: 0, active: 0, waiting: 0, parked: 0, done: 0 };
        var grouped = state.view === 'mine';
        root.classList.toggle('is-view-flat', grouped);
        root.querySelectorAll('.kop-pb-card').forEach(function (card) {
            var match = (!state.group || card.dataset.group === state.group)
                && (!state.kind || card.dataset.kind === state.kind)
                && (!state.q || card.dataset.search.indexOf(state.q) !== -1);
            if (match) {
                VIEWS.forEach(function (vw) { if (inView(card, vw)) counts[vw]++; });
                if (counts[card.dataset.status] !== undefined) counts[card.dataset.status]++;
            }
            var ok = match && inView(card, state.view);
            card.hidden = !ok;
            if (ok && !(grouped && card.dataset.status === 'done' && !state.showDone)) shown++;
        });
        Object.keys(counts).forEach(function (k) {
            root.querySelectorAll('[data-count="' + k + '"]').forEach(function (n) { n.textContent = counts[k]; });
        });
        root.querySelectorAll('.kop-pb-group').forEach(function (g) {
            g.hidden = !g.querySelector('.kop-pb-card:not([hidden])');
        });
        root.querySelectorAll('.kop-pb-section').forEach(function (s) {
            s.hidden = grouped || !s.querySelector('.kop-pb-card:not([hidden])');
        });
        STATUSES.forEach(function (st) {
            var sec = flat && flat.querySelector('.kop-pb-status-group[data-status="' + st + '"]');
            if (sec) sec.hidden = !counts[st];
        });
        var doneList = listOf('done'), toggle = root.querySelector('.kop-pb-toggle-done');
        if (doneList) doneList.hidden = !state.showDone;
        if (toggle) { toggle.textContent = state.showDone ? 'Hide' : 'Show'; toggle.setAttribute('aria-expanded', state.showDone ? 'true' : 'false'); }
        var newItem = root.querySelector('.kop-pb-newitem');
        if (newItem) newItem.hidden = !grouped;
        var none = root.querySelector('.kop-pb-none');
        if (none) {
            var plain = !state.q && !state.group && !state.kind;
            none.hidden = shown > 0 || (grouped && counts.done > 0 && plain);
            none.textContent = grouped && plain ? 'Nothing on your list yet. Start an item below.'
                : (state.view === 'claude' && plain ? 'Nothing is waiting for Claude.' : 'Nothing matches.');
        }
    }

    // One request at a time, in the order the changes were made.
    var queue = Promise.resolve();
    function post(data) {
        var run = function () {
            var body = new FormData();
            body.append('action', 'kop_plan_board');
            body.append('nonce', cfg.nonce || '');
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
            return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
        };
        var p = queue.then(run, run);
        queue = p.catch(function () {});
        return p;
    }

    /** What a redraw must not lose: text changed and not saved, the focus and caret, open forms and step notes. */
    function isField(el) { return el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search')); }
    function dirty(el) {
        return el.dataset.auto ? el.value !== (el.dataset.saved || '') : el.value !== el.defaultValue;
    }
    function carry(oldEl, newEl) {
        var a = document.activeElement;
        var keep = a && a.id && oldEl.contains(a) ? { id: a.id, value: isField(a) ? a.value : null, s: null, e: null } : null;
        if (keep && isField(a)) { try { keep.s = a.selectionStart; keep.e = a.selectionEnd; } catch (e) {} }
        var values = {};
        oldEl.querySelectorAll('textarea[id], input[id]').forEach(function (el) { if (isField(el) && dirty(el)) values[el.id] = el.value; });
        var forms = [], notes = [];
        oldEl.querySelectorAll('.kop-pb-form:not([hidden])').forEach(function (f) { forms.push(f.dataset.form); });
        oldEl.querySelectorAll('.kop-pb-stepnote:not([hidden]) textarea[id]').forEach(function (t) { notes.push(t.id); });
        return function () {
            Object.keys(values).forEach(function (id) { var el = document.getElementById(id); if (el && newEl.contains(el)) el.value = values[id]; });
            forms.forEach(function (name) { var f = newEl.querySelector('.kop-pb-form[data-form="' + name + '"]'); if (f) f.hidden = false; });
            notes.forEach(function (id) { var t = document.getElementById(id); if (t && t.parentNode.classList.contains('kop-pb-stepnote')) t.parentNode.hidden = false; });
            growAll(newEl);
            if (!keep) return;
            var el = document.getElementById(keep.id);
            if (!el || !newEl.contains(el)) return;
            if (keep.value !== null) el.value = keep.value;
            el.focus({ preventScroll: true });
            try { if (keep.s !== null) el.setSelectionRange(keep.s, keep.e); } catch (e) {}
        };
    }

    function fromHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html || '';
        return box.firstElementChild;
    }

    function setOpen(card, on) {
        var head = card.querySelector('.kop-pb-head'), detail = card.querySelector('.kop-pb-detail');
        if (!head || !detail) return;
        if (on) openCards[card.dataset.id] = true; else delete openCards[card.dataset.id];
        head.setAttribute('aria-expanded', on ? 'true' : 'false');
        detail.hidden = !on;
        card.classList.toggle('is-open', !!on);
        if (on) growAll(card);
    }

    /** Puts the server's card in place of the one on the page (or in the Started group when it is new). */
    function putCard(id, html) {
        var card = cardOf(id), fresh = fromHtml(html);
        if (!fresh) { if (card) { if (card.kopHome) card.kopHome.remove(); card.remove(); } return null; }
        if (!card) {
            var home = root.querySelector('.kop-pb-started');
            if (!home) return null;
            home.appendChild(fresh);
            fresh.kopOrder = -1;
            return fresh;
        }
        var restore = carry(card, fresh);
        fresh.kopHome = card.kopHome; fresh.kopOrder = card.kopOrder;
        card.replaceWith(fresh);
        setOpen(fresh, !!openCards[id]);
        restore();
        return fresh;
    }

    function putPickup(html) {
        var wrap = root.querySelector('.kop-pb-pickwrap');
        if (!wrap || !html) return;
        var old = wrap.firstElementChild, fresh = fromHtml(html);
        if (!fresh) return;
        var restore = old ? carry(old, fresh) : function () {};
        wrap.replaceChildren(fresh);
        restore();
        growAll(fresh);
    }

    function say(scope, text, bad) {
        var msg = scope && scope.querySelector('.kop-pb-msg');
        if (!msg) return;
        msg.textContent = text || '';
        msg.classList.toggle('is-bad', !!bad);
    }

    function notice(text) {
        var n = root.querySelector('.kop-pb-notice');
        if (!n) return;
        n.hidden = !text;
        n.querySelector('span').textContent = text || '';
    }

    /** Takes a reply: the card, the Pick up here panel, the counts, the line about what moved. */
    function apply(d, opts) {
        opts = opts || {};
        var id = d.item, mine = ++seq;
        newest[id] = mine;
        var c = root.querySelector('.kop-pb-open-count');
        if (c && typeof d.open === 'number') c.textContent = d.open;
        var load = root.querySelector('.kop-pb-load');
        if (load && d.load) load.textContent = d.load;
        if (d.notice) notice(d.notice);
        var draw = function () {
            if (newest[id] !== mine) return;
            var fromPick = opts.fromPick && document.activeElement && document.activeElement.closest('.kop-pb-pick');
            var fresh = putCard(id, d.html || '');
            putPickup(d.pickup);
            layout();
            filter();
            if (fresh && !d.silent) say(opts.fromPick ? root.querySelector('.kop-pb-pick') : fresh, d.message || 'Saved.');
            if (opts.fromPick || fromPick) {
                var go = root.querySelector('.kop-pb-pick .kop-ak-btn--primary');
                if (go && !root.querySelector('.kop-pb-pick').contains(document.activeElement)) go.focus({ preventScroll: true });
            }
            if (opts.then) opts.then(fresh);
        };
        // A box saved on blur: redraw a moment later, so the click that took the focus away is not lost.
        if (d.silent) setTimeout(draw, 350); else draw();
    }

    function send(data, scope, opts) {
        say(scope, opts && opts.quiet ? '' : 'Saving...');
        return post(data).then(function (d) {
            if (!d || !d.success) { say(scope, (d && d.data && d.data.message) || 'Could not save.', true); if (opts && opts.fail) opts.fail(); return; }
            apply(d.data, opts);
        }).catch(function () { say(scope, 'Could not reach the site. Try again.', true); if (opts && opts.fail) opts.fail(); });
    }

    function showForm(card, name) {
        card.querySelectorAll('.kop-pb-form').forEach(function (f) { f.hidden = f.dataset.form !== name; });
        var f = name && card.querySelector('[data-form="' + name + '"]');
        var input = f && f.querySelector('textarea, input');
        if (input) input.focus();
    }

    /** Brings an item's card into sight, open. */
    function showCard(id) {
        var card = cardOf(id);
        if (!card) return;
        if (card.hidden || (state.view === 'mine' && card.dataset.status === 'done' && !state.showDone)) {
            state.q = ''; state.kind = ''; state.group = '';
            var q = root.querySelector('.kop-pb-search'); if (q) q.value = '';
            root.querySelectorAll('.kop-pb-kind-select, .kop-pb-group-select').forEach(function (s) { s.value = ''; });
            if (card.dataset.status === 'done') state.showDone = true;
            setView(STATUSES.indexOf(card.dataset.status) !== -1 ? 'mine' : 'all');
        }
        setOpen(card, true);
        card.scrollIntoView({ block: 'start' });
        var head = card.querySelector('.kop-pb-head'); if (head) head.focus({ preventScroll: true });
    }

    function addItem() {
        var box = root.querySelector('.kop-pb-new-name'), scope = root.querySelector('.kop-pb-newitem');
        var name = box ? box.value.trim() : '';
        if (!name) { if (box) box.focus(); return; }
        box.value = '';
        send({ act: 'new', text: name }, scope, { quiet: true, fail: function () { box.value = name; }, then: function (fresh) {
            if (!fresh) return;
            setOpen(fresh, true);
            var first = fresh.querySelector('.kop-pb-add-text');
            if (first) { fresh.scrollIntoView({ block: 'center' }); first.focus({ preventScroll: true }); }
        } });
    }

    root.addEventListener('click', function (e) {
        var tab = e.target.closest('.kop-pb-tab');
        if (tab) { setView(tab.dataset.view); return; }
        if (e.target.closest('.kop-pb-copy')) { copyOpen(e.target.closest('.kop-pb-copy')); return; }
        if (e.target.closest('.kop-pb-dismiss')) { notice(''); return; }
        if (e.target.closest('.kop-pb-toggle-done')) { state.showDone = !state.showDone; filter(); return; }
        if (e.target.closest('.kop-pb-new-add')) { addItem(); return; }
        var head = e.target.closest('.kop-pb-head');
        if (head) { var hc = head.closest('.kop-pb-card'); setOpen(hc, head.getAttribute('aria-expanded') !== 'true'); return; }
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var scope = btn.closest('.kop-pb-card, .kop-pb-pick');
        if (!scope) return;
        var act = btn.dataset.act, id = scope.dataset.id, fromPick = scope.classList.contains('kop-pb-pick');
        if (act === 'show-card') { showCard(id); return; }
        if (act === 'show-note') {
            var li = btn.closest('li'), nb = li && li.querySelector('.kop-pb-stepnote');
            if (nb) { nb.hidden = false; btn.hidden = true; var nt = nb.querySelector('textarea'); grow(nt); nt.focus(); }
            return;
        }
        if (act === 'open' || act === 'edit-answer') { showForm(scope, 'answer'); return; }
        if (act === 'open-edit') { showForm(scope, 'edit'); return; }
        if (act === 'open-retire') { showForm(scope, 'retire'); return; }
        if (act === 'open-research') { showForm(scope, 'research'); return; }
        if (act === 'open-claude') { showForm(scope, 'claude'); return; }
        if (act === 'cancel') { showForm(scope, ''); return; }

        var data = { act: act, item: id }, opts = { fromPick: fromPick };
        if (btn.dataset.step) data.step = btn.dataset.step;
        if (act === 'choice') { data.act = 'answer'; data.text = btn.dataset.choice || ''; }
        if (act === 'answer') {
            var ta = scope.querySelector('[data-form="answer"] textarea');
            data.text = ta ? ta.value.trim() : '';
            if (!data.text) { say(scope, 'Type something first, or use one of the buttons.', true); if (ta) ta.focus(); return; }
        }
        if (act === 'retire') {
            var why = scope.querySelector('.kop-pb-retire-why');
            data.text = why ? why.value.trim() : '';
        }
        if (act === 'research' || act === 'claude') {
            var hb = scope.querySelector('[data-form="' + act + '"] .kop-pb-handoff-text');
            data.text = hb ? hb.value.trim() : '';
        }
        if (act === 'edit') {
            var f = scope.querySelector('[data-form="edit"]');
            data.text = JSON.stringify({
                ask: f.querySelector('.kop-pb-edit-ask').value.trim(),
                why: f.querySelector('.kop-pb-edit-why').value.trim(),
                text: f.querySelector('.kop-pb-edit-text').value.trim()
            });
        }
        if (act === 'stepadd') {
            var add = scope.querySelector('.kop-pb-add-text');
            data.text = add ? add.value.trim() : '';
            if (!data.text) { if (add) add.focus(); return; }
            add.value = '';
            opts.fail = function () { add.value = data.text; };
            opts.then = function (fresh) { var again = fresh && fresh.querySelector('.kop-pb-add-text'); if (again) again.focus({ preventScroll: true }); };
        }
        if (act === 'step' && fromPick) {
            // The answer typed for the step goes with it: save the box first when it has not been saved yet.
            var note = scope.querySelector('textarea[data-auto="note"]');
            if (note && note.value.trim() !== (note.dataset.saved || '')) saveBox(note);
        }
        // Forms that were answered close with the redraw; nothing else typed in the card is touched.
        if (['answer', 'retire', 'research', 'claude', 'edit'].indexOf(data.act) !== -1) {
            opts.then = function () { var c2 = cardOf(id); if (c2) c2.querySelectorAll('.kop-pb-form').forEach(function (x) { x.hidden = true; }); };
            scope.querySelectorAll('.kop-pb-form:not([hidden]) textarea, .kop-pb-form:not([hidden]) input').forEach(function (x) { x.defaultValue = x.value; });
        }
        btn.disabled = true;
        opts.fail = opts.fail || function () {};
        var failed = opts.fail;
        opts.fail = function () { btn.disabled = false; failed(); };
        if (data.act === 'answer') setDraft(id, '');
        send(data, scope, opts);
    });

    /** Saves a box that saves by itself (the left-off note, a step's note, a step's wording). */
    function saveBox(el) {
        var scope = el.closest('.kop-pb-card, .kop-pb-pick');
        if (!scope || el.readOnly) return;
        var val = el.value.trim();
        if (val === (el.dataset.saved || '')) return;
        if (el.dataset.auto === 'reword' && !val) { el.value = el.dataset.saved || ''; grow(el); return; }
        var was = el.dataset.saved || '';
        el.dataset.saved = val;
        var data = { act: el.dataset.auto, item: scope.dataset.id, text: val };
        if (el.dataset.step) data.step = el.dataset.step;
        send(data, scope, { quiet: true, fail: function () { var now = document.getElementById(el.id); if (now) now.dataset.saved = was; } });
    }

    root.addEventListener('focusout', function (e) {
        if (e.target.dataset && e.target.dataset.auto) saveBox(e.target);
    });

    function setView(view) {
        if (VIEWS.indexOf(view) === -1) return;
        state.view = view;
        try { localStorage.setItem('kop-pb-view3', view); } catch (e) {}
        root.querySelectorAll('.kop-pb-tab').forEach(function (t) { t.setAttribute('aria-pressed', t.dataset.view === view ? 'true' : 'false'); });
        layout();
        filter();
        growAll(root);
    }

    root.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        var t = e.target;
        // Enter ends a step's wording, adds the step being typed, starts the item being named.
        if (t.classList.contains('kop-pb-step-box')) { e.preventDefault(); t.blur(); return; }
        if (t.classList.contains('kop-pb-add-text')) { e.preventDefault(); var b = t.parentNode.querySelector('[data-act="stepadd"]'); if (b) b.click(); return; }
        if (t.classList.contains('kop-pb-new-name')) { e.preventDefault(); addItem(); return; }
        // Ctrl+Enter (Cmd+Enter) in a form's box saves it.
        if (!(e.ctrlKey || e.metaKey)) return;
        var form = t.closest('.kop-pb-form');
        var save = form && form.querySelector('button.kop-ak-btn--primary, button.kop-ak-btn--danger');
        if (save) { e.preventDefault(); save.click(); }
    });

    root.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList.contains('kop-pb-grow')) grow(t);
        if (t.classList.contains('kop-pb-search')) { state.q = t.value.trim().toLowerCase(); filter(); return; }
        if (t.classList.contains('kop-pb-ta')) {
            var card = t.closest('.kop-pb-card');
            if (card && card.dataset.state === '') setDraft(card.dataset.id, t.value);
        }
    });
    root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList.contains('kop-pb-group-select')) { state.group = t.value; filter(); return; }
        if (t.classList.contains('kop-pb-kind-select')) { state.kind = t.value; filter(); return; }
        var card = t.closest('.kop-pb-card');
        if (!card) return;
        if (t.classList.contains('kop-pb-tick')) {
            t.disabled = true;
            send({ act: t.checked ? 'step' : 'unstep', item: card.dataset.id, step: t.dataset.step }, card, { fail: function () { t.disabled = false; t.checked = !t.checked; } });
        }
        if (t.classList.contains('kop-pb-status') && t.value) {
            send({ act: 'status', item: card.dataset.id, text: t.value }, card, {});
        }
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
    growAll(root);
})();
