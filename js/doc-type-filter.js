/**
 * Document libraries on facility and company pages ([filebird_folder],
 * inc/features.php): when the tiles hold two or more document types
 * (data-doc-types, inc/doc-types.php), a row of buttons above the library
 * shows only one type at a time, opening the subfolders that hold it, and
 * links to every document of that type across programs (/documents/<type>/).
 * Labels come from KOP_DOC_TYPES (wp_localize_script).
 *
 * A library holding documents whose words are stored (data-doc-text: news
 * clippings read by OCR, inc/news-clippings.php) also gets a search box: it
 * asks kop/v1/doc-text-search which of them hold the words typed, shows only
 * those (and tiles whose title has them) with the words around the match.
 */
(function () {
    'use strict';

    var TYPES = (window.KOP_DOC_TYPES && window.KOP_DOC_TYPES.types) || {};
    var TEXT_SEARCH = (window.KOP_DOC_TYPES && window.KOP_DOC_TYPES.textSearch) || '';

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') n.textContent = attrs[k];
            else n.setAttribute(k, attrs[k]);
        });
        (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return n;
    }

    // Tiles drawn on the page and those still waiting in closed folds (js/defer-folds.js).
    function allTiles(lib) {
        var tiles = Array.prototype.slice.call(lib.querySelectorAll('li.doc-item'));
        Array.prototype.forEach.call(lib.querySelectorAll('template[data-kop-defer]'), function (tpl) {
            tiles = tiles.concat(Array.prototype.slice.call(tpl.content.querySelectorAll('li.doc-item')));
        });
        return tiles;
    }

    function setup(lib) {
        setupTypes(lib);
        setupSearch(lib);
    }

    function setupTypes(lib) {
        if (lib.querySelector('.kop-dtf')) return;
        var tiles = allTiles(lib);
        var counts = {};
        tiles.forEach(function (li) {
            (li.getAttribute('data-doc-types') || '').split(' ').filter(Boolean).forEach(function (t) {
                if (TYPES[t]) counts[t] = (counts[t] || 0) + 1;
            });
        });
        // Types in the order the site lists them.
        var present = Object.keys(TYPES).filter(function (t) { return counts[t]; });
        if (present.length < 2) return;

        var bar = el('div', { class: 'kop-dtf', role: 'group', 'aria-label': 'Show one kind of document' });
        var more = el('p', { class: 'kop-dtf__more' });
        more.hidden = true;
        var buttons = [];

        function pick(type, btn) {
            buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            if (type && window.KOPDeferFolds) window.KOPDeferFolds.stampAll(lib);
            tiles = Array.prototype.slice.call(lib.querySelectorAll('li.doc-item'));
            var details = Array.prototype.slice.call(lib.querySelectorAll('details'));
            details.forEach(function (d) {
                if (!d.hasAttribute('data-dtf-was-open')) d.setAttribute('data-dtf-was-open', d.open ? '1' : '0');
            });
            tiles.forEach(function (li) {
                var has = (' ' + (li.getAttribute('data-doc-types') || '') + ' ').indexOf(' ' + type + ' ') !== -1;
                li.hidden = !!type && !has;
            });
            details.forEach(function (d) {
                if (!type) { d.hidden = false; d.open = d.getAttribute('data-dtf-was-open') === '1'; return; }
                var any = !!d.querySelector('li.doc-item:not([hidden])');
                d.hidden = !any;
                d.open = any;
            });
            more.textContent = '';
            more.hidden = !type;
            if (type && TYPES[type].url) {
                more.appendChild(el('a', { href: TYPES[type].url, text: 'Compare with ' + TYPES[type].plural.toLowerCase() + ' from other programs' }));
            }
        }

        var all = el('button', { type: 'button', class: 'kop-dtf__btn', 'aria-pressed': 'true' }, ['All (' + tiles.length + ')']);
        all.addEventListener('click', function () { pick('', all); });
        buttons.push(all);
        bar.appendChild(all);
        present.forEach(function (t) {
            var b = el('button', { type: 'button', class: 'kop-dtf__btn', 'aria-pressed': 'false' }, [TYPES[t].plural + ' (' + counts[t] + ')']);
            b.addEventListener('click', function () { pick(t, b); });
            buttons.push(b);
            bar.appendChild(b);
        });

        var title = lib.querySelector('.doc-folder-title-single');
        var at = title ? title.nextSibling : lib.firstChild;
        lib.insertBefore(bar, at);
        lib.insertBefore(more, bar.nextSibling);
    }

    function setupSearch(lib) {
        if (!TEXT_SEARCH || !window.fetch || lib.querySelector('.kop-dts')) return;
        var ids = allTiles(lib).map(function (li) {
            return li.getAttribute('data-doc-text') ? li.getAttribute('data-doc-id') : '';
        }).filter(Boolean);
        if (!ids.length) return;

        var input = el('input', { type: 'search', class: 'kop-dts__input', autocomplete: 'off', placeholder: 'A name, a place, a phrase' });
        var label = el('label', { class: 'kop-dts__label' }, ['Search the words inside these documents', input]);
        var status = el('p', { class: 'kop-dts__status', 'aria-live': 'polite' });
        var box = el('div', { class: 'kop-dts', role: 'search' }, [label, status]);
        var timer = null;
        var asked = 0;

        function clear() {
            Array.prototype.forEach.call(lib.querySelectorAll('.kop-dts__snip'), function (n) { n.parentNode.removeChild(n); });
            Array.prototype.forEach.call(lib.querySelectorAll('li.doc-item[data-dts-hid]'), function (li) {
                li.hidden = false;
                li.removeAttribute('data-dts-hid');
            });
            Array.prototype.forEach.call(lib.querySelectorAll('details[data-dts-was-open]'), function (d) {
                d.hidden = false;
                d.open = d.getAttribute('data-dts-was-open') === '1';
                d.removeAttribute('data-dts-was-open');
            });
        }

        function show(q, matches) {
            clear();
            var words = q.toLowerCase();
            var n = 0;
            Array.prototype.forEach.call(lib.querySelectorAll('li.doc-item'), function (li) {
                var id = li.getAttribute('data-doc-id');
                var snip = id && matches[id];
                var hit = snip !== undefined || (li.getAttribute('data-title') || '').toLowerCase().indexOf(words) !== -1;
                if (!hit) {
                    if (!li.hidden) { li.hidden = true; li.setAttribute('data-dts-hid', '1'); }
                    return;
                }
                if (li.hidden) return; // left out by the type buttons
                n++;
                if (snip) li.appendChild(el('span', { class: 'kop-dts__snip', text: snip }));
            });
            Array.prototype.forEach.call(lib.querySelectorAll('details'), function (d) {
                if (d.hidden) return;
                d.setAttribute('data-dts-was-open', d.open ? '1' : '0');
                var any = !!d.querySelector('li.doc-item:not([hidden])');
                d.hidden = !any;
                d.open = any;
            });
            status.textContent = n ? (n === 1 ? '1 document holds these words.' : n + ' documents hold these words.')
                : 'No document here holds these words.';
        }

        function run() {
            var q = input.value.replace(/\s+/g, ' ').trim();
            var mine = ++asked;
            if (q.length < 3) {
                clear();
                status.textContent = '';
                return;
            }
            if (window.KOPDeferFolds) window.KOPDeferFolds.stampAll(lib);
            status.textContent = 'Searching...';
            fetch(TEXT_SEARCH + (TEXT_SEARCH.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&ids=' + ids.join(','))
                .then(function (r) { return r.ok ? r.json() : { matches: {} }; })
                .then(function (d) { if (mine === asked) show(q, (d && d.matches) || {}); })
                .catch(function () { if (mine === asked) status.textContent = 'The search did not answer. Try again.'; });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(run, 400);
        });
        var bar = lib.querySelector('.kop-dtf');
        // A type button starts from the whole library: the search is cleared before the button acts.
        if (bar) bar.addEventListener('click', function () {
            if (!input.value) return;
            input.value = '';
            asked++;
            clear();
            status.textContent = '';
        }, true);
        var title = lib.querySelector('.doc-folder-title-single');
        var at = bar ? bar : (title ? title.nextSibling : lib.firstChild);
        lib.insertBefore(box, at);
    }

    function init() {
        document.querySelectorAll('.kop-document-folder').forEach(setup);
    }

    // A library inside a fold drawn later.
    document.addEventListener('kop:stamped', function (e) {
        if (e.target && e.target.querySelectorAll) e.target.querySelectorAll('.kop-document-folder').forEach(setup);
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
