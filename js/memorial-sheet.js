/**
 * KOP Tools > Memorial sheet (inc/memorial-sheet.php): Save this person (each line with a pick, one after the
 * other), Add to the page, Skip, and Undo, through KOPAdminKit.post (js/admin-kit.js).
 */
(function () {
    'use strict';

    var kit = window.KOPAdminKit;
    var cfg = window.KOP_MEMORIAL_SHEET || {};
    if (!kit) return;

    function post(data) {
        return kit.post('kop_memorial_sheet', cfg.nonce, data);
    }

    function resultOf(el) {
        var card = el.closest('.kop-ak-card');
        return (el.closest('td') && el.closest('td').querySelector('.kop-ak-result')) || card.querySelector('.kop-ak-card__actions .kop-ak-result');
    }

    function undoButton(keys, out, after) {
        var u = document.createElement('button');
        u.type = 'button';
        u.className = 'button kop-ak-btn kop-ak-btn--quiet';
        u.textContent = 'Undo';
        u.addEventListener('click', function () {
            u.disabled = true;
            kit.say(out, '', 'Undoing...');
            var errors = [];
            keys.reduce(function (p, key) {
                return p.then(function () {
                    return post({ act: 'undo', key: key }).then(function (res) {
                        if (!res.success) errors.push(res.data || 'Undo failed.');
                    });
                });
            }, Promise.resolve()).then(function () {
                if (errors.length) { kit.say(out, 'is-err', errors.join(' ')); return; }
                kit.say(out, 'is-ok', 'Undone.');
                if (after) after();
            }).catch(function () { kit.say(out, 'is-err', 'Network error; try Undo again.'); });
        });
        out.appendChild(u);
    }

    // "Type" opens the line's box.
    document.addEventListener('change', function (e) {
        if (!e.target.matches('.kop-ms-pick input[type=radio]')) return;
        var typed = e.target.closest('td').querySelector('.kop-ms-typed');
        if (typed) typed.hidden = e.target.value !== 'typed';
    });

    function settle(card, btn) {
        var out = resultOf(btn);
        var picks = [];
        card.querySelectorAll('tr[data-key]:not(.is-gone)').forEach(function (tr) {
            var r = tr.querySelector('.kop-ms-pick input[type=radio]:checked');
            if (!r) return;
            var v = tr.querySelector('.kop-ms-value');
            var p = tr.querySelector('.kop-ms-precision');
            picks.push({ tr: tr, data: { act: 'settle', key: tr.dataset.key, choice: r.value, value: r.value === 'typed' && v ? v.value : '', precision: p ? p.value : '' } });
        });
        if (!picks.length) { kit.say(out, 'is-err', 'Pick Sheet, Page or Type on at least one line.'); return; }
        btn.disabled = true;
        kit.say(out, '', 'Saving...');
        var done = [], errors = [];
        picks.reduce(function (p, x) {
            return p.then(function () {
                return post(x.data).then(function (res) {
                    if (res.success) { done.push(x.tr.dataset.key); x.tr.classList.add('is-gone'); }
                    else errors.push(x.tr.querySelector('th').firstChild.textContent + ': ' + (res.data || 'failed.'));
                });
            });
        }, Promise.resolve()).then(function () {
            btn.disabled = false;
            kit.say(out, errors.length && !done.length ? 'is-err' : 'is-ok',
                (done.length ? 'Saved ' + done.length + ' line' + (done.length === 1 ? '' : 's') + '.' : '') + (errors.length ? ' Not saved: ' + errors.join(' ') : ''));
            if (done.length) {
                undoButton(done, out, function () {
                    done.forEach(function (k) {
                        var tr = card.querySelector('tr[data-key="' + k + '"]');
                        if (tr) tr.classList.remove('is-gone');
                    });
                    card.classList.remove('is-done');
                });
            }
            card.classList.toggle('is-done', !card.querySelector('tr[data-key]:not(.is-gone)'));
        }).catch(function () { btn.disabled = false; kit.say(out, 'is-err', 'Network error; try again.'); });
    }

    function addOrSkip(card, btn, act) {
        var out = resultOf(btn);
        var data = { act: act, key: card.dataset.key };
        if (act === 'add') {
            data.fields = {};
            card.querySelectorAll('.kop-ms-form [name]').forEach(function (f) { data.fields[f.name] = f.value; });
        }
        card.querySelectorAll('[data-ms-act]').forEach(function (b) { b.disabled = true; });
        kit.say(out, '', act === 'add' ? 'Adding...' : 'Skipping...');
        post(data).then(function (res) {
            if (!res.success) {
                card.querySelectorAll('[data-ms-act]').forEach(function (b) { b.disabled = false; });
                kit.say(out, 'is-err', res.data || 'Failed.');
                return;
            }
            card.classList.add('is-done');
            kit.say(out, 'is-ok', res.data.message);
            if (res.data.url) {
                var a = document.createElement('a');
                a.href = res.data.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Open the page';
                out.appendChild(a);
            }
            undoButton([card.dataset.key], out, function () {
                card.classList.remove('is-done');
                card.querySelectorAll('[data-ms-act]').forEach(function (b) { b.disabled = false; });
            });
        }).catch(function () {
            card.querySelectorAll('[data-ms-act]').forEach(function (b) { b.disabled = false; });
            kit.say(out, 'is-err', 'Network error; try again.');
        });
    }

    // Undo on the Decided / Added / Skipped lists.
    function undoListed(btn) {
        var out = resultOf(btn);
        btn.disabled = true;
        kit.say(out, '', 'Undoing...');
        post({ act: 'undo', key: btn.dataset.key }).then(function (res) {
            if (!res.success) { btn.disabled = false; kit.say(out, 'is-err', res.data || 'Undo failed.'); return; }
            var gone = btn.closest('tr[data-key]') || btn.closest('.kop-ak-card');
            gone.classList.add(gone.tagName === 'TR' ? 'is-gone' : 'is-done');
            kit.say(out, 'is-ok', 'Undone: back to Waiting.');
        }).catch(function () { btn.disabled = false; kit.say(out, 'is-err', 'Network error; try again.'); });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ms-act]');
        if (!btn) return;
        e.preventDefault();
        var card = btn.closest('.kop-ak-card');
        var act = btn.dataset.msAct;
        if (act === 'settle') settle(card, btn);
        else if (act === 'add' || act === 'skip') addOrSkip(card, btn, act);
        else if (act === 'undo') undoListed(btn);
    });
})();
