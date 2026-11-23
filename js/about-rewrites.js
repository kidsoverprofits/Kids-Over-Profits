/**
 * KOP Tools > About rewrites (inc/about-rewrites-screen.php): the card buttons. Answer a "Needs you" question (a
 * choice, or your own words), Publish, Send back to Claude, Undo; each posts to wp_ajax_kop_about_rewrites through
 * KOPAdminKit.post (js/admin-kit.js) and says what happened on the card's result line.
 */
(function () {
    var cfg = window.KOP_ABOUT_REWRITES || {};
    var kit = window.KOPAdminKit;
    if (!kit) return;

    function post(card, data) {
        data.key = card.getAttribute('data-key');
        return kit.post('kop_about_rewrites', cfg.nonce, data).then(function (r) {
            if (!r || !r.success) throw new Error((r && r.data) || 'Something went wrong. Reload the page and try again.');
            return r.data;
        });
    }

    function say(card, text, isError) {
        var line = card.querySelector('.kop-ak-result');
        if (!line) return;
        line.textContent = text;
        line.classList.toggle('is-error', !!isError);
    }

    function answer(card, ask, choice) {
        var note = ask.querySelector('.kop-ar-own input').value.trim();
        var saved = ask.querySelector('.kop-ar-saved');
        saved.textContent = 'Saving...';
        post(card, { act: 'answer', i: ask.getAttribute('data-i'), choice: choice, note: note }).then(function (d) {
            ask.querySelectorAll('.kop-ar-choice').forEach(function (b) {
                var on = !!d.answer && b.getAttribute('data-choice') === d.answer.choice;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            ask.classList.toggle('is-answered', !!d.answer);
            saved.textContent = d.answer ? 'Saved' : 'Cleared';
            var count = card.querySelector('.kop-ar-count');
            if (count) {
                var asks = card.querySelectorAll('.kop-ar-ask');
                count.textContent = card.querySelectorAll('.kop-ar-ask.is-answered').length + ' of ' + asks.length + ' answered';
            }
        }).catch(function (e) { saved.textContent = 'Not saved: ' + e.message; });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.kop-ar-card [data-act]');
        if (!btn) return;
        var card = btn.closest('.kop-ar-card');
        var act = btn.getAttribute('data-act');
        if (act === 'answer' || act === 'answer-note') {
            var ask = btn.closest('.kop-ar-ask');
            var current = ask.querySelector('.kop-ar-choice.is-on');
            var choice = act === 'answer'
                ? (btn.classList.contains('is-on') ? '' : btn.getAttribute('data-choice'))
                : (current ? current.getAttribute('data-choice') : '');
            answer(card, ask, choice);
            return;
        }
        var data = { act: act };
        if (act === 'send-back') {
            var box = card.querySelector('.kop-ar-folds textarea');
            data.note = box ? box.value : '';
        }
        btn.disabled = true;
        say(card, act === 'publish' ? 'Publishing...' : act === 'undo' ? 'Taking it back...' : 'Sending...');
        post(card, data).then(function (d) {
            card.classList.add('is-done');
            card.querySelectorAll('.kop-ak-card__buttons button, .kop-ar-folds button').forEach(function (b) { b.disabled = true; });
            say(card, d.message);
            if (act === 'publish') {
                var undo = document.createElement('button');
                undo.type = 'button';
                undo.className = 'button kop-ak-btn kop-ak-btn--quiet';
                undo.setAttribute('data-act', 'undo');
                undo.textContent = 'Undo';
                card.querySelector('.kop-ak-result').appendChild(document.createTextNode(' '));
                card.querySelector('.kop-ak-result').appendChild(undo);
            }
        }).catch(function (err) {
            btn.disabled = false;
            say(card, err.message, true);
        });
    });

    /* ---- Pick next tab: search every record, flag the ones Claude writes next, order the flags ---- */
    var find = document.querySelector('[data-find]');
    if (find) pickNext(find);

    function pickNext(form) {
        var tbody = document.querySelector('[data-results]');
        var status = document.querySelector('[data-find-status]');
        var flaggedList = document.querySelector('[data-flagged]');
        var flaggedEmpty = document.querySelector('[data-flagged-empty]');
        var flagCount = document.querySelectorAll('[data-flag-count], .kop-ak-tab[href*="tab=pick"] .kop-ak-count');
        var flagged = [];
        var timer = null;
        var seq = 0;
        var STAGE = { none: 'No rewrite yet', review: 'Rewrite in To review', claude: 'Sent back to Claude', published: 'Rewrite published' };

        function call(data) {
            return kit.post('kop_about_rewrites', cfg.nonce, data).then(function (r) {
                if (!r || !r.success) throw new Error((r && r.data) || 'Something went wrong. Reload the page and try again.');
                return r.data;
            });
        }
        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text != null) n.textContent = text;
            return n;
        }
        function button(label, act, variant, key) {
            var b = el('button', 'button kop-ak-btn kop-ak-btn--' + (variant || 'secondary'), label);
            b.type = 'button';
            b.setAttribute('data-pick', act);
            if (key != null) b.setAttribute('data-key', key);
            return b;
        }
        function link(name, url) {
            if (!url) return el('strong', '', name);
            var a = el('a', '', name);
            a.href = url;
            a.target = '_blank';
            a.rel = 'noopener';
            return a;
        }
        function isFlagged(key) {
            for (var i = 0; i < flagged.length; i++) if (String(flagged[i].key) === String(key)) return i + 1;
            return 0;
        }

        function drawFlagged(list) {
            flagged = list || [];
            flaggedList.textContent = '';
            flaggedEmpty.hidden = flagged.length > 0;
            flagCount.forEach(function (c) { c.textContent = String(flagged.length); });
            flagged.forEach(function (f, i) {
                var li = el('li', 'kop-ar-flag');
                li.setAttribute('data-key', f.key);
                var head = el('div', 'kop-ar-flag__head');
                head.appendChild(link(f.name, f.url));
                head.appendChild(el('span', 'kop-ar-meta', (f.kind === 'operator' ? 'Company' : 'Program') + (f.published ? ' · has a published rewrite (Claude redoes it)' : '')));
                li.appendChild(head);
                var row = el('div', 'kop-ar-flag__row');
                var noteId = 'kop-ar-fnote-' + f.key;
                var lab = el('label', 'screen-reader-text', 'Note for Claude on ' + f.name);
                lab.setAttribute('for', noteId);
                var input = el('input', 'kop-ar-flag__note');
                input.type = 'text';
                input.id = noteId;
                input.value = f.note || '';
                input.placeholder = 'Note for Claude (optional): what to focus on';
                input.setAttribute('data-key', f.key);
                row.appendChild(lab);
                row.appendChild(input);
                row.appendChild(button('Save note', 'note', 'quiet', f.key));
                var up = button('↑', 'up', 'quiet', f.key);
                up.setAttribute('aria-label', 'Move ' + f.name + ' up');
                up.disabled = i === 0;
                var down = button('↓', 'down', 'quiet', f.key);
                down.setAttribute('aria-label', 'Move ' + f.name + ' down');
                down.disabled = i === flagged.length - 1;
                var top = button('To top', 'top', 'quiet', f.key);
                top.disabled = i === 0;
                row.appendChild(up);
                row.appendChild(down);
                row.appendChild(top);
                row.appendChild(button('Unflag', 'unflag', 'danger', f.key));
                li.appendChild(row);
                flaggedList.appendChild(li);
            });
            // Keep the search results' buttons in step.
            tbody.querySelectorAll('tr[data-key]').forEach(function (tr) { drawFlagCell(tr); });
        }

        function drawFlagCell(tr) {
            var cell = tr.querySelector('.kop-ar-flagcell');
            var key = tr.getAttribute('data-key');
            var n = isFlagged(key);
            cell.textContent = '';
            if (tr.getAttribute('data-stage') === 'review') {
                cell.appendChild(el('span', 'kop-ar-meta', 'In To review'));
            } else if (n) {
                cell.appendChild(el('span', 'kop-ar-flagged-n', '#' + n));
                cell.appendChild(button('Unflag', 'unflag', 'quiet', key));
            } else {
                cell.appendChild(button('Flag', 'flag', 'primary', key));
            }
        }

        function drawResults(d) {
            tbody.textContent = '';
            (d.rows || []).forEach(function (r) {
                var tr = el('tr');
                tr.setAttribute('data-key', r.key);
                tr.setAttribute('data-stage', r.stage);
                var td = el('td');
                td.appendChild(link(r.name, r.url));
                var bits = [r.kind === 'operator' ? 'Company' + (r.programs ? ', ' + r.programs + ' program' + (r.programs === 1 ? '' : 's') : '') : 'Program'];
                if (r.place) bits.push(r.place);
                if (r.years) bits.push(r.years);
                if (r.status && r.status !== 'Open' && r.status !== 'Unknown') bits.push(r.status);
                if (r.company) bits.push(r.company);
                td.appendChild(el('div', 'kop-ar-meta', bits.join(' · ')));
                tr.appendChild(td);
                var news = el('td', 'num', String(r.news));
                news.setAttribute('data-label', 'News: ');
                var suits = el('td', 'num', String(r.lawsuits));
                suits.setAttribute('data-label', 'Lawsuits: ');
                tr.appendChild(news);
                tr.appendChild(suits);
                tr.appendChild(el('td', 'kop-ar-stage', STAGE[r.stage] || ''));
                tr.appendChild(el('td', 'kop-ar-flagcell'));
                tbody.appendChild(tr);
                drawFlagCell(tr);
            });
            var shown = (d.rows || []).length;
            status.textContent = !d.total ? 'Nothing matches.' : d.total > shown ? 'Showing the first ' + shown + ' of ' + d.total + '. Narrow the search to see more.' : d.total + ' found.';
        }

        function search() {
            var mine = ++seq;
            status.textContent = 'Searching...';
            call({ act: 'pick-search', q: form.q.value, kind: form.kind.value, show: form.show.value, sort: form.sort.value })
                .then(function (d) { if (mine === seq) drawResults(d); })
                .catch(function (e) { if (mine === seq) status.textContent = e.message; });
        }

        form.addEventListener('input', function (e) {
            clearTimeout(timer);
            timer = setTimeout(search, e.target.name === 'q' ? 300 : 0);
        });
        form.addEventListener('change', function (e) { if (e.target.tagName === 'SELECT') { clearTimeout(timer); search(); } });

        function note(key) {
            var input = flaggedList.querySelector('input[data-key="' + key + '"]');
            return input ? input.value : '';
        }

        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('[data-pick]');
            if (!b) return;
            var act = b.getAttribute('data-pick');
            var key = b.getAttribute('data-key');
            var data = { key: key };
            if (act === 'flag') data.act = 'flag';
            else if (act === 'note') { data.act = 'flag'; data.note = note(key); }
            else if (act === 'unflag') data.act = 'unflag';
            else { data.act = 'flag-move'; data.to = act; }
            if (act === 'flag') data.note = '';
            b.disabled = true;
            call(data).then(function (d) {
                drawFlagged(d.flagged);
                if (act === 'note') {
                    var saved = flaggedList.querySelector('li[data-key="' + key + '"] .kop-ar-flag__row');
                    if (saved) saved.appendChild(el('span', 'kop-ar-saved', 'Saved'));
                } else if (d.message) {
                    status.textContent = d.message;
                }
            }).catch(function (err) {
                b.disabled = false;
                status.textContent = err.message;
            });
        });

        flaggedList.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches('.kop-ar-flag__note')) return;
            e.preventDefault();
            var b = e.target.parentNode.querySelector('[data-pick="note"]');
            if (b) b.click();
        });

        call({ act: 'pick-list' }).then(function (d) { drawFlagged(d.flagged); search(); })
            .catch(function (e) { status.textContent = e.message; });
        form.q.focus();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('.kop-ar-own input')) return;
        e.preventDefault();
        var ask = e.target.closest('.kop-ar-ask');
        var current = ask.querySelector('.kop-ar-choice.is-on');
        answer(ask.closest('.kop-ar-card'), ask, current ? current.getAttribute('data-choice') : '');
    });
})();
