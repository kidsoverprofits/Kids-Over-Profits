/**
 * "We have questions about <name>" on facility and company pages
 * (kop_q_box_html() in inc/questions.php prints the empty, hidden section).
 * The pages are cached whole, so the questions are read when the page opens
 * (kop/v1/questions/record): up to four, sources-disagree first, then plain
 * facts, staff, practices, rules; each links to its card on /questions/
 * (#f<id>:<key>). Nothing to ask: the section stays hidden.
 */
(function () {
    'use strict';

    var box = document.querySelector('[data-kop-q-box]');
    var cfg = window.KOP_QUESTIONS_BOX;
    if (!box || !cfg || !window.fetch) return;

    var kind = box.getAttribute('data-kop-q-box');
    var id = box.getAttribute('data-kop-q-id');
    var page = box.getAttribute('data-kop-q-page');
    var list = box.querySelector('.kop-q-box-list');
    var all = box.querySelector('.kop-q-box-all a');

    function rank(q) {
        if (q.conflict) return 0;
        if (/\.rules$/.test(q.key)) return 4;
        if (/\.practice\./.test(q.key)) return 3;
        if (/\.staff\./.test(q.key)) return 2;
        return 1;
    }

    fetch(cfg.rest + '?kind=' + encodeURIComponent(kind) + '&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { questions: [] }; })
        .then(function (data) {
            var qs = (data.questions || []).slice();
            if (!qs.length) return;
            qs.sort(function (a, b) { return rank(a) - rank(b); });
            var prefix = page + '#' + (kind === 'operator' ? 'o' : 'f') + id;
            qs.slice(0, 4).forEach(function (q) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = prefix + ':' + encodeURIComponent(q.key);
                a.textContent = q.ask;
                li.appendChild(a);
                if (q.conflict) {
                    var tag = document.createElement('span');
                    tag.className = 'kop-q-box-flag';
                    tag.textContent = 'Sources disagree';
                    li.appendChild(document.createTextNode(' '));
                    li.appendChild(tag);
                }
                list.appendChild(li);
            });
            if (all) {
                all.href = prefix;
                if (qs.length > 4) all.textContent = 'Answer all ' + qs.length + ' questions';
            }
            box.hidden = false;
        })
        .catch(function () { /* the section stays hidden */ });
})();
