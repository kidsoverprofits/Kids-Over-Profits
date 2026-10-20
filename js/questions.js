/**
 * /questions/: one question at a time, phone first (templates/questions.php,
 * inc/questions.php).
 *
 * Start: find a program or company (kop/v1/questions/find) to answer every
 * open question about it, or pick a state for a deck of questions about many
 * (kop/v1/questions/deck, more fetched as it runs low). Yes/no cards swipe:
 * right is yes, left is no (arrow keys too); every card also has buttons.
 * "How do you know?" is optional and remembered for the next card.
 * Answers go to kop/v1/questions/answer. After the first answer the page
 * offers, once and closed by default, an optional email for when the answers
 * have been checked (kop/v1/questions/notify); answering never needs one. #f<id> / #o<id> open a record's
 * questions; #q=<key> opens the record that question is about.
 */
(function () {
    'use strict';

    var cfg = window.KOP_QUESTIONS;
    var start = document.getElementById('kop-q-start');
    var deckEl = document.getElementById('kop-q-deck');
    if (!cfg || !start || !deckEl) return;

    var state = {
        mode: 'deck',          // deck | record
        stateCode: '',
        record: null,          // {kind, id, name, url} in record mode
        queue: [],
        seen: [],
        answered: 0,
        loading: false,
        exhausted: false,
        how: '',
        batch: 0,              // the reader's "email me" set of answers, once they left an address
        emailNo: false,        // they said no thanks: the offer stays closed
        emailOpen: false
    };
    try {
        state.how = sessionStorage.getItem('kopQHow') || '';
        state.batch = parseInt(sessionStorage.getItem('kopQBatch') || '0', 10) || 0;
        state.emailNo = sessionStorage.getItem('kopQEmailNo') === '1';
    } catch (e) { /* no storage */ }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function h(tag, attrs, kids) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') el.textContent = v;
            else if (k === 'on') Object.keys(v).forEach(function (ev) { el.addEventListener(ev, v[ev]); });
            else if (k === 'className') el.className = v;
            else el.setAttribute(k, v === true ? '' : v);
        });
        (kids || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return el;
    }

    function btn(label, cls, onClick, extra) {
        return h('button', Object.assign({ type: 'button', className: 'kop-q-btn ' + (cls || ''), text: label, on: { click: onClick } }, extra || {}));
    }

    function getJSON(path, params) {
        var url = cfg.rest + path + '?' + Object.keys(params || {}).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return fetch(url, { credentials: 'same-origin' }).then(readJSON);
    }

    function readJSON(r) {
        return r.json().catch(function () { return {}; }).then(function (data) {
            if (!r.ok) throw new Error((data && data.message) || 'Something went wrong. Please try again.');
            return data;
        });
    }

    function post(path, body) {
        return fetch(cfg.rest + path, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(readJSON);
    }

    function reduceMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    // ------------------------------------------------------------------
    // Start: search and state
    // ------------------------------------------------------------------

    var find = document.getElementById('kop-q-find');
    var results = document.getElementById('kop-q-results');
    var stateSel = document.getElementById('kop-q-state');
    Object.keys(cfg.states || {}).forEach(function (code) {
        stateSel.appendChild(h('option', { value: code, text: cfg.states[code] }));
    });

    var findTimer = null;
    find.addEventListener('input', function () {
        clearTimeout(findTimer);
        var q = find.value.trim();
        if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
        findTimer = setTimeout(function () {
            getJSON('find', { q: q }).then(function (data) {
                results.innerHTML = '';
                var list = data.results || [];
                if (!list.length) {
                    results.appendChild(h('li', { className: 'kop-q-noresult', text: 'Nothing by that name yet. You can add it with the data form.' }));
                } else {
                    list.forEach(function (r) {
                        var li = h('li', { role: 'option' }, [
                            h('button', { type: 'button', className: 'kop-q-result', on: { click: function () { openRecord(r.kind, r.id, r.name, r.url); } } }, [
                                h('span', { className: 'kop-q-result-name', text: r.name }),
                                h('span', { className: 'kop-q-result-meta', text: [r.kind === 'operator' ? 'Company' : '', r.hint, r.place].filter(Boolean).join(' · ') })
                            ])
                        ]);
                        results.appendChild(li);
                    });
                }
                results.hidden = false;
            }).catch(function () { results.hidden = true; });
        }, 250);
    });

    document.getElementById('kop-q-go').addEventListener('click', function () {
        state.mode = 'deck';
        state.record = null;
        state.stateCode = stateSel.value;
        state.queue = [];
        state.exhausted = false;
        showDeck();
        loadMore();
    });

    function openRecord(kind, id, name, url, focusKey) {
        state.mode = 'record';
        state.record = { kind: kind, id: id, name: name || '', url: url || '' };
        state.queue = [];
        state.exhausted = false;
        results.hidden = true;
        showDeck();
        renderLoading();
        getJSON('record', { kind: kind, id: id }).then(function (data) {
            var qs = (data.questions || []).filter(function (q) { return state.seen.indexOf(q.key) === -1; });
            if (focusKey) {
                qs.sort(function (a, b) { return (b.key === focusKey) - (a.key === focusKey); });
            }
            if (qs[0] && !state.record.name) state.record.name = qs[0].record.name;
            state.queue = qs;
            state.exhausted = true;
            render();
        }).catch(showError);
    }

    function showDeck() {
        start.hidden = true;
        deckEl.hidden = false;
        window.scrollTo({ top: deckEl.getBoundingClientRect().top + window.pageYOffset - 80, behavior: reduceMotion() ? 'auto' : 'smooth' });
    }

    function backToStart() {
        deckEl.hidden = true;
        start.hidden = false;
        try { history.replaceState(null, '', location.pathname); } catch (e) { /* old browser */ }
        find.focus();
    }

    function loadMore() {
        if (state.loading || state.exhausted || state.mode !== 'deck') return;
        state.loading = true;
        if (!state.queue.length) renderLoading();
        getJSON('deck', { state: state.stateCode, seen: state.seen.slice(-300).join(',') }).then(function (data) {
            state.loading = false;
            var fresh = (data.questions || []).filter(function (q) {
                return state.seen.indexOf(q.key) === -1 && !state.queue.some(function (x) { return x.key === q.key; });
            });
            if (!fresh.length) state.exhausted = true;
            var wasEmpty = !state.queue.length;
            state.queue = state.queue.concat(fresh);
            if (wasEmpty) render();
        }).catch(function (e) { state.loading = false; showError(e); });
    }

    // ------------------------------------------------------------------
    // The deck
    // ------------------------------------------------------------------

    function topBar() {
        var title = state.mode === 'record' && state.record
            ? h('p', { className: 'kop-q-context' }, ['Questions about ', state.record.url ? h('a', { href: state.record.url, text: state.record.name }) : state.record.name])
            : h('p', { className: 'kop-q-context', text: state.stateCode ? 'Programs in ' + (cfg.states[state.stateCode] || state.stateCode) : 'Programs everywhere' });
        return h('div', { className: 'kop-q-top' }, [
            btn('Back', 'kop-q-btn-plain', backToStart),
            title,
            h('span', { className: 'kop-q-count', text: state.answered ? state.answered + ' answered' : '' })
        ]);
    }

    function renderLoading() {
        deckEl.innerHTML = '';
        deckEl.appendChild(topBar());
        deckEl.appendChild(h('p', { className: 'kop-q-wait', text: 'Finding questions...' }));
    }

    function showError(e) {
        deckEl.innerHTML = '';
        deckEl.appendChild(topBar());
        deckEl.appendChild(h('p', { className: 'kop-q-error', text: (e && e.message) || 'Something went wrong. Please try again.' }));
    }

    function render() {
        deckEl.innerHTML = '';
        deckEl.appendChild(topBar());
        var q = state.queue[0];
        if (!q) {
            deckEl.appendChild(doneCard());
            if (state.answered && !state.batch) deckEl.appendChild(emailPanel(true));
            return;
        }
        if (state.answered && !state.batch && !state.emailNo) deckEl.appendChild(emailPanel(state.emailOpen));
        var stack = h('div', { className: 'kop-q-stack' });
        if (state.queue[1]) stack.appendChild(h('div', { className: 'kop-q-card kop-q-under', 'aria-hidden': 'true' }));
        stack.appendChild(card(q));
        deckEl.appendChild(stack);
        if (state.mode === 'deck' && state.queue.length < 4) loadMore();
        var first = stack.querySelector('.kop-q-card:not(.kop-q-under) input, .kop-q-card:not(.kop-q-under) textarea, .kop-q-card:not(.kop-q-under) .kop-q-choice');
        if (first && window.matchMedia && window.matchMedia('(pointer: fine)').matches) first.focus({ preventScroll: true });
    }

    function doneCard() {
        var name = state.record ? state.record.name : '';
        var kids = [
            h('h2', { className: 'kop-q-ask', text: state.mode === 'record' ? 'That is every question we have about ' + name + '.' : 'That is all for now.' }),
            h('p', { text: state.answered ? 'Thank you for your answers.' : 'Nothing is waiting here right now.' }),
            h('p', {}, ['Know something we did not ask about? The ',
                h('a', { href: cfg.form + (name ? '?find=' + encodeURIComponent(name) : ''), text: 'data form' }),
                ' takes anything: staff, incidents, documents, news.'])
        ];
        kids.push(h('div', { className: 'kop-q-actions' }, [btn('Answer questions about other programs', 'kop-q-btn-primary', backToStart)]));
        return h('div', { className: 'kop-q-card kop-q-done' }, kids);
    }

    /**
     * "Email me when my answers have been checked": optional, never needed to
     * answer, and used for that one email only (kop/v1/questions/notify).
     */
    function emailPanel(open) {
        var wrap = h('section', { className: 'kop-q-email', 'aria-label': 'Optional email when your answers are checked' });
        if (!open) {
            wrap.appendChild(h('p', { className: 'kop-q-email-line' }, [
                btn('Want an email when your answers have been checked? (optional)', 'kop-q-btn-plain kop-q-email-open', function () {
                    state.emailOpen = true;
                    wrap.replaceWith(emailPanel(true));
                })
            ]));
            return wrap;
        }
        var input = h('input', { type: 'email', className: 'kop-q-input', id: 'kop-q-email', maxlength: '190', autocomplete: 'email', placeholder: 'you@example.com' });
        var msg = h('p', { className: 'kop-q-error', role: 'alert', hidden: true });
        function send() {
            var email = input.value.trim();
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                msg.textContent = 'That email address does not look right.';
                msg.hidden = false;
                input.focus();
                return;
            }
            msg.hidden = true;
            post('notify', { notify_email: email }).then(function (res) {
                state.batch = res.batch || 0;
                try { sessionStorage.setItem('kopQBatch', String(state.batch)); } catch (e) { /* no storage */ }
                wrap.replaceWith(h('p', { className: 'kop-q-email kop-q-email-done', role: 'status', text: res.message || 'Thank you. We will email you once, when your answers have been checked.' }));
            }).catch(function (e) {
                msg.textContent = e.message;
                msg.hidden = false;
            });
        }
        enter(input, send);
        wrap.appendChild(h('h2', { className: 'kop-q-email-title', text: 'Want to know when your answers have been checked?' }));
        wrap.appendChild(h('p', { text: 'This is completely optional. You do not need an email to answer anything here.' }));
        wrap.appendChild(h('p', { text: 'If you leave one, we will send you a single email once a person has checked your answers. That is the only thing we use it for: it is never published, never added to a mailing list, and we delete it after that email.' }));
        wrap.appendChild(h('label', { className: 'kop-q-label', for: 'kop-q-email', text: 'Your email (optional)' }));
        wrap.appendChild(h('div', { className: 'kop-q-row' }, [input, btn('Email me', 'kop-q-btn-primary', send)]));
        wrap.appendChild(msg);
        wrap.appendChild(h('div', { className: 'kop-q-foot' }, [btn('No thanks', 'kop-q-btn-plain', function () {
            state.emailNo = true;
            state.emailOpen = false;
            try { sessionStorage.setItem('kopQEmailNo', '1'); } catch (e) { /* no storage */ }
            wrap.remove();
        })]));
        return wrap;
    }

    function next() {
        state.queue.shift();
        render();
    }

    function toast(text) {
        var t = h('p', { className: 'kop-q-toast', role: 'status', text: text });
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('is-gone'); }, 2400);
        setTimeout(function () { t.remove(); }, 3000);
    }

    // ------------------------------------------------------------------
    // One card
    // ------------------------------------------------------------------

    function card(q) {
        var el = h('div', { className: 'kop-q-card kop-q-type-' + q.type, tabindex: '-1', 'data-key': q.key });
        var err = h('p', { className: 'kop-q-error', hidden: true });
        var rec = q.record || {};
        el.appendChild(h('p', { className: 'kop-q-rec' }, [
            rec.url ? h('a', { href: rec.url, text: rec.name, target: '_blank', rel: 'noopener' }) : h('span', { text: rec.name }),
            rec.place ? h('span', { className: 'kop-q-place', text: rec.place }) : null
        ]));
        if (q.conflict) el.appendChild(h('p', { className: 'kop-q-flag', text: 'Sources disagree' }));
        el.appendChild(h('h2', { className: 'kop-q-ask', text: q.ask }));
        if (q.sub && !q.conflict) el.appendChild(h('p', { className: 'kop-q-sub', text: q.sub }));

        var how = howBlock(q);
        function send(answer, label) {
            err.hidden = true;
            el.classList.add('is-sending');
            var body = { key: q.key, answer: answer, how: how.value(), source: how.source(), note: how.note(), batch: state.batch };
            return post('answer', body).then(function (res) {
                state.seen.push(q.key);
                if (!res.gone) state.answered++;
                toast(res.message || 'Thank you.');
                return res;
            }).catch(function (e) {
                el.classList.remove('is-sending');
                el.style.transform = '';
                err.textContent = e.message;
                err.hidden = false;
                throw e;
            });
        }
        function skip() {
            state.seen.push(q.key);
            leave(el, 'up', next);
        }

        var controls;
        if (q.type === 'swipe') controls = swipeControls(q, el, send);
        else if (q.type === 'choice') controls = choiceControls(q, el, send);
        else if (q.type === 'ages') controls = agesControls(q, el, send);
        else controls = inputControls(q, el, send);
        el.appendChild(controls);
        el.appendChild(err);
        el.appendChild(how.el);
        el.appendChild(h('div', { className: 'kop-q-foot' }, [btn('I don\'t know, skip', 'kop-q-btn-plain', skip)]));
        return el;
    }

    /** Move the card off the deck, then go on. */
    function leave(el, dir, then) {
        if (reduceMotion()) { then(); return; }
        el.classList.add('is-leaving', 'to-' + dir);
        setTimeout(then, 220);
    }

    function done(el, dir) {
        return function () { leave(el, dir, next); };
    }

    // Yes / no: buttons, swiping and the arrow keys.
    function swipeControls(q, el, send) {
        var yes = q.choices[0], no = q.choices[1];
        var wrap = h('div', { className: 'kop-q-swipe' });
        var follow = null;

        function answerYes() {
            if (q.followup === 'year' && !follow) {
                follow = h('div', { className: 'kop-q-follow' }, []);
                var yr = h('input', { type: 'text', inputmode: 'numeric', maxlength: '4', className: 'kop-q-input kop-q-year', placeholder: 'Year', 'aria-label': 'Which year?' });
                follow.appendChild(h('label', { className: 'kop-q-label', text: 'Which year were they there? (optional)' }));
                follow.appendChild(h('div', { className: 'kop-q-row' }, [yr, btn('Save', 'kop-q-btn-primary', function () {
                    send({ value: 'yes', year: yr.value }).then(done(el, 'right'));
                })]));
                wrap.innerHTML = '';
                wrap.appendChild(follow);
                yr.focus();
                return;
            }
            send({ value: yes.value }).then(done(el, 'right'));
        }
        function answerNo() { send({ value: no.value }).then(done(el, 'left')); }

        wrap.appendChild(h('p', { className: 'kop-q-hint', text: 'Swipe right for yes, left for no' }));
        wrap.appendChild(h('div', { className: 'kop-q-yesno' }, [
            btn(no.label, 'kop-q-btn-no', answerNo),
            btn(yes.label, 'kop-q-btn-yes', answerYes)
        ]));

        el.appendChild(h('span', { className: 'kop-q-stamp kop-q-stamp-yes', 'aria-hidden': 'true', text: 'YES' }));
        el.appendChild(h('span', { className: 'kop-q-stamp kop-q-stamp-no', 'aria-hidden': 'true', text: 'NO' }));
        drag(el, function (dir) { if (follow) return false; if (dir === 'right') answerYes(); else answerNo(); return q.followup !== 'year' || dir === 'left'; });
        el.addEventListener('keydown', function (e) {
            if (follow || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return;
            if (e.key === 'ArrowRight') { e.preventDefault(); answerYes(); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); answerNo(); }
        });
        return wrap;
    }

    /**
     * Drag the card sideways; past the line it answers ($commit(dir) returns
     * true when the card should fly off), else it springs back.
     */
    function drag(el, commit) {
        var x0 = 0, y0 = 0, dx = 0, active = false, horizontal = null, id = null;
        el.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || e.target.closest('button, a, input, textarea, select, summary, label')) return;
            active = true; horizontal = null; dx = 0; id = e.pointerId;
            x0 = e.clientX; y0 = e.clientY;
        });
        el.addEventListener('pointermove', function (e) {
            if (!active || e.pointerId !== id) return;
            var mx = e.clientX - x0, my = e.clientY - y0;
            if (horizontal === null && (Math.abs(mx) > 8 || Math.abs(my) > 8)) {
                horizontal = Math.abs(mx) > Math.abs(my);
                if (horizontal) { el.setPointerCapture(id); el.classList.add('is-dragging'); }
            }
            if (!horizontal) return;
            dx = mx;
            el.style.transform = 'translateX(' + dx + 'px) rotate(' + (dx / 18) + 'deg)';
            el.style.setProperty('--kop-q-yes', Math.max(0, Math.min(1, dx / 100)));
            el.style.setProperty('--kop-q-no', Math.max(0, Math.min(1, -dx / 100)));
        });
        function end() {
            if (!active) return;
            active = false;
            el.classList.remove('is-dragging');
            var limit = Math.min(110, el.offsetWidth * 0.3);
            if (horizontal && Math.abs(dx) > limit) {
                var dir = dx > 0 ? 'right' : 'left';
                var fly = commit(dir);
                if (fly === false) { reset(); return; }
                el.style.transform = 'translateX(' + (dx > 0 ? 1 : -1) * window.innerWidth + 'px) rotate(' + (dx > 0 ? 20 : -20) + 'deg)';
                return;
            }
            reset();
        }
        function reset() {
            el.style.transform = '';
            el.style.setProperty('--kop-q-yes', 0);
            el.style.setProperty('--kop-q-no', 0);
        }
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', function () { active = false; el.classList.remove('is-dragging'); reset(); });
    }

    // Several answers, each a big button; "a different year" opens a box.
    function choiceControls(q, el, send) {
        var wrap = h('div', { className: 'kop-q-choices' });
        q.choices.forEach(function (c) {
            wrap.appendChild(btn(c.label, 'kop-q-choice', function () { send({ value: c.value }).then(done(el, 'right')); }));
        });
        if (q.other) {
            var box = h('div', { className: 'kop-q-other', hidden: true });
            var inp = h('input', { type: 'text', className: 'kop-q-input', 'aria-label': q.other,
                inputmode: /year/i.test(q.other) ? 'numeric' : null, maxlength: /year/i.test(q.other) ? '4' : '200' });
            box.appendChild(h('div', { className: 'kop-q-row' }, [inp, btn('Save', 'kop-q-btn-primary', function () {
                send({ value: 'other', other: inp.value }).then(done(el, 'right'));
            })]));
            wrap.appendChild(btn(q.other, 'kop-q-choice kop-q-choice-other', function () { box.hidden = false; inp.focus(); }));
            wrap.appendChild(box);
            enter(inp, function () { send({ value: 'other', other: inp.value }).then(done(el, 'right')); });
        }
        return wrap;
    }

    // A year, a town, a company, or remembered rules.
    function inputControls(q, el, send) {
        var long = !!q.long;
        var isYear = q.type === 'year';
        var inp = long
            ? h('textarea', { className: 'kop-q-input kop-q-long', rows: '5', placeholder: q.placeholder || '', 'aria-label': q.ask })
            : h('input', { type: 'text', className: 'kop-q-input' + (isYear ? ' kop-q-year' : ''), placeholder: isYear ? 'Year, like 1998' : (q.placeholder || ''),
                inputmode: isYear ? 'numeric' : null, maxlength: isYear ? '4' : '200', autocomplete: 'off', 'aria-label': q.ask });
        var save = function () { send({ value: inp.value }).then(done(el, 'right')); };
        var kids = [h('div', { className: long ? 'kop-q-stackcol' : 'kop-q-row' }, [inp, btn(long ? 'Send' : 'Save', 'kop-q-btn-primary', save)])];
        if (q.other) kids.push(btn(q.other, 'kop-q-choice', function () { send({ value: 'other' }).then(done(el, 'right')); }));
        if (!long) enter(inp, save);
        return h('div', { className: 'kop-q-inputs' }, kids);
    }

    function agesControls(q, el, send) {
        var lo = h('input', { type: 'text', inputmode: 'numeric', maxlength: '2', className: 'kop-q-input kop-q-age', 'aria-label': 'Youngest age' });
        var hi = h('input', { type: 'text', inputmode: 'numeric', maxlength: '2', className: 'kop-q-input kop-q-age', 'aria-label': 'Oldest age' });
        var save = function () { send({ value: (lo.value || '').trim() + '-' + (hi.value || '').trim() }).then(done(el, 'right')); };
        enter(hi, save);
        return h('div', { className: 'kop-q-inputs' }, [h('div', { className: 'kop-q-row kop-q-ages' }, [
            h('span', { text: 'From' }), lo, h('span', { text: 'to' }), hi, btn('Save', 'kop-q-btn-primary', save)
        ])]);
    }

    function enter(inp, fn) {
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fn(); } });
    }

    // "How do you know?": optional, remembered for the next card.
    function howBlock(q) {
        var src = h('input', { type: 'url', className: 'kop-q-input', placeholder: 'https://...', 'aria-label': 'Link to where you read it' });
        var note = h('textarea', { className: 'kop-q-input', rows: '2', placeholder: 'Anything to add (optional)', 'aria-label': 'Note' });
        var srcWrap = h('div', { className: 'kop-q-src', hidden: state.how !== 'source' }, [src]);
        var chips = h('div', { className: 'kop-q-chips', role: 'radiogroup', 'aria-label': 'How do you know?' });
        Object.keys(cfg.hows).forEach(function (k) {
            var b = btn(cfg.hows[k], 'kop-q-chip' + (state.how === k ? ' is-on' : ''), function () {
                state.how = state.how === k ? '' : k;
                try { sessionStorage.setItem('kopQHow', state.how); } catch (e) { /* no storage */ }
                Array.prototype.forEach.call(chips.children, function (c) { c.classList.remove('is-on'); c.setAttribute('aria-checked', 'false'); });
                if (state.how === k) { b.classList.add('is-on'); b.setAttribute('aria-checked', 'true'); }
                srcWrap.hidden = state.how !== 'source';
            }, { role: 'radio', 'aria-checked': state.how === k ? 'true' : 'false' });
            chips.appendChild(b);
        });
        var details = h('details', { className: 'kop-q-how' }, [
            h('summary', { text: state.how ? 'How you know: ' + (cfg.hows[state.how] || '') : 'How do you know? (optional)' }),
            chips, srcWrap, note
        ]);
        if (q.conflict) details.open = true;
        return {
            el: details,
            value: function () { return state.how; },
            source: function () { return state.how === 'source' ? src.value.trim() : ''; },
            note: function () { return note.value.trim(); }
        };
    }

    // ------------------------------------------------------------------
    // Links into the page
    // ------------------------------------------------------------------

    function fromHash() {
        var hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
        var m = hash.match(/^(?:q=)?([fo])(\d+)(?:\.|$)/);
        if (m) openRecord(m[1] === 'o' ? 'operator' : 'facility', parseInt(m[2], 10), '', '', hash.indexOf('q=') === 0 ? hash.slice(2) : '');
    }
    fromHash();
})();
