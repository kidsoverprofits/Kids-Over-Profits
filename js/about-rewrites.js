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

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('.kop-ar-own input')) return;
        e.preventDefault();
        var ask = e.target.closest('.kop-ar-ask');
        var current = ask.querySelector('.kop-ar-choice.is-on');
        answer(ask.closest('.kop-ar-card'), ask, current ? current.getAttribute('data-choice') : '');
    });
})();
