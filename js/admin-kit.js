/**
 * One behaviour for the KOP Tools screens (inc/admin-kit.php, css/admin-kit.css).
 *
 *  - Filter toolbars apply themselves: a dropdown on change, search on Enter.
 *  - "More" menus close on a click elsewhere, on Escape and once an item is picked.
 *  - "How this works" remembers being opened, per screen, in this browser.
 *  - "Tick all" on a card follows its rows.
 *  - KOPAdminKit.cards(): the cards of ticked rows (Drive Docs, Fornits,
 *    Woodbury Facts): collect the ticked rows, post the action, grey out
 *    what was done, say so on the result line with Undo beside it, and run
 *    "Add everything ticked on this page" card by card.
 */
(function () {
    'use strict';

    var cfg = window.KOP_ADMIN_KIT || {};
    document.documentElement.classList.add('kop-ak-js');

    function store(key, value) {
        try {
            if (value === undefined) return window.localStorage.getItem(key);
            window.localStorage.setItem(key, value);
        } catch (e) { /* private window: forget it */ }
        return null;
    }

    function post(action, nonce, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', nonce);
        Object.keys(data || {}).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { body.append(k + '[]', x); });
            else if (v && typeof v === 'object') Object.keys(v).forEach(function (kk) { body.append(k + '[' + kk + ']', v[kk]); });
            else if (v !== undefined && v !== null) body.append(k, v);
        });
        return fetch(cfg.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    /* ---- Filters, menus, help, tick all ------------------------------------ */

    document.addEventListener('change', function (e) {
        var form = e.target.closest && e.target.closest('form[data-kop-ak-filters]');
        if (form && e.target.tagName === 'SELECT') {
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
            return;
        }
        if (e.target.classList.contains('kop-ak-tickall__box')) {
            var card = e.target.closest('.kop-ak-card');
            card.querySelectorAll('tr[data-key]:not(.is-gone) .kop-ak-pick').forEach(function (p) { p.checked = e.target.checked; });
            return;
        }
        if (e.target.classList.contains('kop-ak-pick')) syncTickAll(e.target.closest('.kop-ak-card'));
    });

    // The search box's clear (x) empties it: show the list again.
    document.addEventListener('search', function (e) {
        var form = e.target.closest && e.target.closest('form[data-kop-ak-filters]');
        if (form && e.target.value === '') form.submit();
    }, true);

    function syncTickAll(card) {
        if (!card) return;
        var box = card.querySelector('.kop-ak-tickall__box');
        if (!box) return;
        var picks = card.querySelectorAll('tr[data-key]:not(.is-gone) .kop-ak-pick');
        var on = card.querySelectorAll('tr[data-key]:not(.is-gone) .kop-ak-pick:checked').length;
        box.checked = picks.length > 0 && on === picks.length;
        box.indeterminate = on > 0 && on < picks.length;
        box.disabled = picks.length === 0;
    }

    function closeMenus(except) {
        document.querySelectorAll('details.kop-ak-more[open]').forEach(function (d) { if (d !== except) d.open = false; });
    }

    document.addEventListener('click', function (e) {
        var more = e.target.closest('details.kop-ak-more');
        closeMenus(more);
        if (more && e.target.closest('.kop-ak-menu button, .kop-ak-menu a')) more.open = false;
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var open = document.querySelector('details.kop-ak-more[open]');
        if (open) { open.open = false; open.querySelector('summary').focus(); }
    });

    function init(root) {
        (root || document).querySelectorAll('details.kop-ak-help[data-kop-ak-help]').forEach(function (d) {
            var key = 'kopAkHelp:' + d.getAttribute('data-kop-ak-help');
            if (store(key) === 'open') d.open = true;
            d.addEventListener('toggle', function () { store(key, d.open ? 'open' : 'closed'); });
        });
        (root || document).querySelectorAll('.kop-ak-card').forEach(syncTickAll);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); });
    else init();

    /* ---- Cards of ticked rows --------------------------------------------- */

    var defaultWords = {
        apply: function (n, res) { return 'Added ' + n + (res.label ? ' to ' + res.label : '') + '.'; },
        reject: function (n) { return 'Skipped ' + n + '.'; },
        undo: function (n) { return 'Undone: ' + n + ', back to review.'; }
    };

    function say(out, cls, text) {
        out.className = 'kop-ak-result' + (cls ? ' ' + cls : '');
        out.textContent = text;
    }

    function link(out, url, text) {
        var a = document.createElement('a');
        a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = text;
        out.appendChild(a);
    }

    /**
     * opts: action + nonce (the screen's wp_ajax action), words {act: fn(n, data) -> text},
     * whole [acts that take every row, ticked or not], undoable [acts the result line
     * offers Undo for; default apply and reject], before fn(card, act, btn, data) -> an
     * error to show, or nothing (it may add to data), after fn(card, act, data).
     * A button with data-other takes the record from the card's .kop-ak-fid box.
     */
    function cards(opts) {
        var words = Object.assign({}, defaultWords, opts.words || {});
        var whole = opts.whole || [];
        var undoable = opts.undoable || ['apply', 'reject'];

        function rows(card, all) {
            return Array.prototype.filter.call(card.querySelectorAll('tr[data-key]'), function (tr) {
                if (tr.classList.contains('is-gone')) return false;
                var pick = tr.querySelector('.kop-ak-pick');
                return all || (pick && pick.checked);
            });
        }

        function done(card) {
            card.classList.toggle('is-done', !card.querySelector('tr[data-key]:not(.is-gone)'));
            syncTickAll(card);
        }

        function undo(card, keys, out) {
            say(out, '', 'Undoing...');
            return post(opts.action, opts.nonce, { act: 'undo', keys: keys }).then(function (res) {
                if (!res.success) { say(out, 'is-err', res.data || 'Undo failed.'); return; }
                var n = 0;
                Object.keys(res.data.results || {}).forEach(function (k) {
                    if (!res.data.results[k].ok) return;
                    n++;
                    var tr = card.querySelector('tr[data-key="' + k + '"]');
                    if (tr) tr.classList.remove('is-gone');
                });
                done(card);
                say(out, 'is-ok', words.undo(n, res.data));
            }).catch(function () { say(out, 'is-err', 'Network error; try Undo again.'); });
        }

        function run(card, act, btn) {
            var out = card.querySelector('.kop-ak-result');
            var picked = rows(card, whole.indexOf(act) >= 0);
            if (!picked.length) { say(out, 'is-err', 'Tick at least one row.'); return Promise.resolve(); }
            var data = { act: act, keys: [], targets: {}, fid: card.dataset.fid || '' };
            picked.forEach(function (tr) {
                data.keys.push(tr.dataset.key);
                var sel = tr.querySelector('.kop-ak-target');
                if (sel) data.targets[tr.dataset.key] = sel.value;
            });
            if (btn && btn.hasAttribute('data-other')) {
                var box = card.querySelector('.kop-ak-fid');
                data.fid = box && box.value ? box.value : '';
                if (!(+data.fid)) { say(out, 'is-err', 'Pick the record first.'); return Promise.resolve(); }
            }
            var err = opts.before ? opts.before(card, act, btn, data) : '';
            if (err) { say(out, 'is-err', err); return Promise.resolve(); }
            if (btn) btn.disabled = true;
            say(out, '', 'Working...');
            return post(opts.action, opts.nonce, data).then(function (res) {
                if (btn) btn.disabled = false;
                if (!res.success) { say(out, 'is-err', res.data || 'Failed.'); return; }
                var okKeys = [], problems = [];
                Object.keys(res.data.results || {}).forEach(function (k) {
                    var r = res.data.results[k];
                    var tr = card.querySelector('tr[data-key="' + k + '"]');
                    if (r.ok) okKeys.push(k); else problems.push(r.error);
                    if (tr && (r.ok || !r.keep)) tr.classList.add('is-gone');
                });
                var uniq = problems.filter(function (p, i) { return p && problems.indexOf(p) === i; });
                var text = (words[act] ? words[act](okKeys.length, res.data) : 'Done: ' + okKeys.length + '.')
                    + (problems.length ? ' Not done (' + problems.length + ')' + (uniq.length ? ': ' + uniq.join(' ') : '.') : '');
                say(out, problems.length && !okKeys.length ? 'is-err' : 'is-ok', text);
                if (res.data.url && okKeys.length && act !== 'undo') link(out, res.data.url, 'Open its page');
                if (okKeys.length && undoable.indexOf(act) >= 0) {
                    var u = document.createElement('button');
                    u.type = 'button';
                    u.className = 'button kop-ak-btn kop-ak-btn--quiet';
                    u.textContent = 'Undo';
                    u.addEventListener('click', function () { undo(card, okKeys, out); });
                    out.appendChild(u);
                }
                done(card);
                if (opts.after) opts.after(card, act, res.data);
            }).catch(function () {
                if (btn) btn.disabled = false;
                say(out, 'is-err', 'Network error; try again.');
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.kop-ak-card [data-act]');
            if (btn && !btn.hasAttribute('data-kop-ak-own')) {
                e.preventDefault();
                run(btn.closest('.kop-ak-card'), btn.dataset.act, btn);
                return;
            }
            var all = e.target.closest('[data-kop-ak-all]');
            if (!all) return;
            var act = all.getAttribute('data-kop-ak-all') || 'apply';
            var bar = all.closest('.kop-ak-bar');
            var progress = bar && bar.querySelector('.kop-ak-progress');
            var list = Array.prototype.filter.call(document.querySelectorAll('.kop-ak-card:not(.is-done)'), function (c) {
                return c.querySelector('tr[data-key]:not(.is-gone) .kop-ak-pick:checked');
            });
            if (!list.length) { if (progress) progress.textContent = 'Nothing is ticked on this page.'; return; }
            all.disabled = true;
            var i = 0;
            (function next() {
                if (i >= list.length) {
                    all.disabled = false;
                    if (progress) progress.textContent = 'Done: ' + list.length + ' card' + (list.length === 1 ? '' : 's') + '. Each card has its own Undo.';
                    return;
                }
                if (progress) progress.textContent = 'Card ' + (i + 1) + ' of ' + list.length + '...';
                run(list[i++], act, null).then(next);
            })();
        });

        return { run: run, post: function (data) { return post(opts.action, opts.nonce, data); }, say: say };
    }

    window.KOPAdminKit = { post: post, cards: cards, say: say, init: init };
})();
