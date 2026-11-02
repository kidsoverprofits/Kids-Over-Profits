(function () {
    'use strict';

    function addSourcePreview(link) {
        if (link.hasAttribute('data-kop-citation-preview')) return;

        var href = link.getAttribute('href') || '';
        if (!href || href.charAt(0) !== '#') return;

        var target;
        try {
            target = document.getElementById(decodeURIComponent(href.slice(1)));
        } catch (error) {
            return;
        }
        if (!target || !(target.matches('.kop-article-sources__item') || target.matches('li[id^="src-"]'))) return;

        var preview = (target.textContent || '').replace(/\s+/g, ' ').trim();
        if (!preview) return;
        if (preview.length > 500) preview = preview.slice(0, 497).trimEnd() + '...';

        link.setAttribute('data-kop-citation-preview', preview);
    }

    /*
     * Numbered citations, as on Wikipedia. Every in-text link reading "source"
     * (the writers' "([source](url))", kop_citation_link(..., 'source', ...),
     * the relationship and write-up "source" links, and pages drawn in JS), and
     * every link in a bracket holding only links (the company histories'
     * "([Form 10-K for 2011, SEC](url); [NBC News, 2024](url))", see bracketGroup()) is
     * shown as a superscript [n] that jumps to entry n of a "Sources" list at the
     * foot of the page; the entry is a proper citation (author, date, title,
     * publication: inc/citation-lookup.php, or the citation text the page gave)
     * with ^ (or ^ a b ...) links back to each place it is cited. The same address keeps its number on the
     * page, "(source; source)" loses its brackets and separators, and the number
     * moves after the sentence's full stop ("in 2009 (source)." -> "in 2009.[1]").
     * Hover or focus on a number shows the source. The stored text keeps
     * "source", so pages read the same without JS. A container with class
     * kop-no-cite-numbers opts out, and a table cell holding only the link (a
     * "Source" column) is left as it is.
     */
    var SOURCE_TEXT = /^source(?:\s+\d+)?$/i;
    var SKIP = 'form, textarea, [contenteditable], .kop-no-cite-numbers, .kop-cite-refs, #wpadminbar';
    var refs = Object.create(null);
    var count = 0;
    var list = null;

    function fallbackPreview(link) {
        var url;
        try {
            url = new URL(link.href);
        } catch (error) {
            return '';
        }
        var host = url.hostname.replace(/^www\./, '');
        var parts = url.pathname.split('/').filter(Boolean);
        var last = parts.length ? parts[parts.length - 1] : '';
        try {
            last = decodeURIComponent(last);
        } catch (error) { /* keep it encoded */ }
        last = last.replace(/\.(?:html?|php|aspx?|pdf)$/i, '').replace(/[-_+]+/g, ' ').trim();
        if (last.length > 90) last = last.slice(0, 87).trimEnd() + '...';
        if (url.hostname === window.location.hostname && /\/wp-content\/uploads\//.test(url.pathname)) {
            return 'Our copy' + (last ? ': ' + last : '');
        }
        return last && !/^\d+$/.test(last) ? host + ': ' + last : host;
    }

    function isCite(node) {
        return !!node && node.nodeType === 1 && node.classList.contains('kop-cite');
    }

    function isText(node) {
        return !!node && node.nodeType === 3;
    }

    /* The "Sources" list, made at the foot of the page's main column the first time it is needed. */
    function sourcesList() {
        if (list && document.contains(list)) return list;
        var main = document.querySelector('.kop-fp-generated-body') || document.querySelector('.entry-content')
            || document.querySelector('main') || document.body;
        var section = document.createElement('section');
        section.className = 'kop-cite-refs' + (main.classList.contains('kop-fp-generated-body') ? ' kop-fp-section' : '');
        section.id = 'kop-sources';
        section.setAttribute('aria-labelledby', 'kop-sources-heading');
        var h2 = document.createElement('h2');
        h2.id = 'kop-sources-heading';
        h2.textContent = 'Sources';
        list = document.createElement('ol');
        list.className = 'kop-cite-refs__list';
        section.appendChild(h2);
        section.appendChild(list);
        main.appendChild(section);
        return list;
    }

    function letters(i) {
        var s = '';
        for (i += 1; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(97 + (i - 1) % 26) + s;
        return s;
    }

    /* "^" back to the one place a source is cited, "^ a b c" when it is cited more than once. */
    function drawBacklinks(ref) {
        var box = ref.back;
        box.textContent = '';
        if (ref.backs.length === 1) {
            box.appendChild(backLink(ref.backs[0], '^', 'Back to the text'));
            return;
        }
        var caret = document.createElement('span');
        caret.setAttribute('aria-hidden', 'true');
        caret.textContent = '^';
        box.appendChild(caret);
        ref.backs.forEach(function (id, i) {
            box.appendChild(document.createTextNode(' '));
            var sup = document.createElement('sup');
            sup.appendChild(backLink(id, letters(i), 'Back to citation ' + letters(i)));
            box.appendChild(sup);
        });
    }

    function backLink(id, text, label) {
        var a = document.createElement('a');
        a.className = 'kop-cite-back';
        a.href = '#' + id;
        a.textContent = text;
        a.setAttribute('aria-label', label);
        return a;
    }

    /*
     * A citation from inc/citation-lookup.php as Wikipedia's reference list writes it:
     * 'Author (Date). "Title". Publication. Detail. Note.', or without an author
     * '"Title". Publication. Date. ...'. Returns pieces; the title piece becomes the link.
     */
    function citationPieces(c) {
        var out = [];
        if (c.author) out.push({ text: c.author + (c.date ? ' (' + c.date + ')' : '') });
        if (c.title) out.push({ text: c.title_style === 'quote' ? '"' + c.title + '"' : c.title, title: true, italic: c.title_style === 'italic' });
        if (c.publisher) out.push({ text: c.publisher, italic: true });
        if (!c.author && c.date) out.push({ text: c.date });
        if (c.detail) out.push({ text: c.detail });
        if (c.note) out.push({ text: c.note });
        return out;
    }

    function stop(text) {
        return /[.!?]["”]?$/.test(text) ? '' : '.';
    }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /*
     * The citation for a source KOP holds no record of, from what the page gave: its citation
     * text (or the page name in the address) as the title, then the website, or for an
     * archive.org capture "Archived from the original (site) on <date>".
     */
    function pageCitation(ref) {
        var url;
        try {
            url = new URL(ref.url);
        } catch (error) {
            return { title: ref.label };
        }
        var host = url.hostname.replace(/^www\./, '');
        var title = ref.label;
        var quoted = false;
        if (!ref.fromCite) {
            // fallbackPreview() gave "host: page name" or "Our copy: file name".
            title = ref.label.replace(/^[^:]+:\s*/, '');
            quoted = title !== ref.label;
            if (!quoted) title = host;
        }
        var c = { title: title, title_style: quoted ? 'quote' : 'none' };
        var wb = (url.hostname + url.pathname).match(/^web\.archive\.org\/web\/(\d{4})(\d{2})(\d{2})\d*[a-z_]*\/(?:https?:\/\/)?(?:www\.)?([^\/]+)/i);
        if (wb) {
            c.note = 'Archived from the original (' + wb[4] + ') on ' + MONTHS[+wb[2] - 1] + ' ' + (+wb[3]) + ', ' + wb[1];
        } else if (url.hostname === window.location.hostname && /\/wp-content\/uploads\//.test(url.pathname)) {
            c.note = 'Our copy';
        } else if (title.toLowerCase().indexOf(host.toLowerCase()) === -1) {
            c.publisher = host;
        }
        return c;
    }

    function citationText(ref) {
        return citationPieces(ref.cite || pageCitation(ref)).map(function (p) { return p.text + stop(p.text); }).join(' ');
    }

    /*
     * Entry n of the list: the backlinks, then the citation, its title (or the text the page
     * gave) being the original link, which keeps its classes so PDFs still open in the viewer.
     */
    function drawEntry(ref) {
        var li = ref.li;
        while (li.lastChild && li.lastChild !== ref.back) li.removeChild(li.lastChild);
        li.appendChild(document.createTextNode(' '));
        var link = ref.link;

        citationPieces(ref.cite || pageCitation(ref)).forEach(function (p, i) {
            if (i) li.appendChild(document.createTextNode(' '));
            var node;
            if (p.title) {
                link.textContent = p.text;
                node = link;
            } else {
                node = document.createTextNode(p.text);
            }
            if (p.italic) {
                var em = document.createElement('i');
                em.appendChild(node);
                node = em;
            }
            li.appendChild(node);
            var end = stop(p.text);
            if (end) li.appendChild(document.createTextNode(end));
        });
    }

    function addEntry(ref, link) {
        ref.li = document.createElement('li');
        ref.li.id = 'kop-source-' + ref.n;
        ref.back = document.createElement('span');
        ref.back.className = 'kop-cite-refs__back';
        ref.li.appendChild(ref.back);

        link.removeAttribute('data-kop-citation-preview');
        link.removeAttribute('aria-label');
        link.classList.remove('kop-citation-link');
        link.classList.add('kop-cite-refs__link');
        ref.link = link;
        drawEntry(ref);
        sourcesList().appendChild(ref.li);
    }

    /* The hover text and spoken label of every [n] for a source. */
    function labelNumbers(ref) {
        var text = citationText(ref);
        ref.anchors.forEach(function (a) {
            a.setAttribute('data-kop-citation-preview', text);
            a.setAttribute('aria-label', 'Source ' + ref.n + ': ' + text);
        });
    }

    /*
     * Ask the server for the proper citation of every source not asked about yet, in one
     * request per batch. Sources it holds no record for keep the page's own text.
     */
    var asking = [];
    var askTimer = 0;
    function askForCitations(ref) {
        var lookup = window.kopCitations && window.kopCitations.lookupUrl;
        if (!lookup || !window.fetch || !/^https?:/i.test(ref.url)) return;
        asking.push(ref);
        if (askTimer) return;
        askTimer = window.setTimeout(function () {
            var batch = asking.splice(0, asking.length);
            askTimer = 0;
            var byUrl = Object.create(null);
            batch.forEach(function (r) { byUrl[r.url] = r; });
            window.fetch(lookup, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'omit',
                body: JSON.stringify({ urls: Object.keys(byUrl) })
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                var found = (data && data.citations) || {};
                Object.keys(found).forEach(function (url) {
                    var r = byUrl[url];
                    if (!r || !found[url] || !found[url].title) return;
                    r.cite = found[url];
                    drawEntry(r);
                    labelNumbers(r);
                });
            }).catch(function () { /* the page's own citation text stays */ });
        }, 50);
    }

    function numberLink(link) {
        // One source per address, except that pages of one PDF ("#page=20") are cited apart.
        var key = link.href.replace(/#(?!page=\d+$).*$/, '');
        var ref = refs[key];
        var isNew = !ref;
        if (isNew) {
            var text = (link.textContent || '').trim();
            var label = (link.getAttribute('data-kop-citation-preview') || link.getAttribute('title') || (SOURCE_TEXT.test(text) ? '' : text)).trim();
            var fromCite = label !== '' && !SOURCE_TEXT.test(label);
            if (!fromCite) label = fallbackPreview(link) || link.href;
            ref = refs[key] = { n: ++count, url: link.href, label: label, fromCite: fromCite, cite: null, backs: [], anchors: [], back: null, li: null, link: null };
        }

        var sup = document.createElement('sup');
        sup.className = 'kop-cite';
        sup.id = 'kop-cite-' + ref.n + '-' + (ref.backs.length + 1);
        var a = document.createElement('a');
        a.className = 'kop-cite__link';
        a.href = '#kop-source-' + ref.n;
        a.textContent = '[' + ref.n + ']';
        sup.appendChild(a);
        ref.anchors.push(a);
        link.parentNode.insertBefore(sup, link);

        if (isNew) addEntry(ref, link);
        else link.parentNode.removeChild(link);
        ref.backs.push(sup.id);
        drawBacklinks(ref);
        labelNumbers(ref);
        if (isNew) askForCitations(ref);
        return sup;
    }

    /* Tidy the text around a run of numbers: "(" [1] "; " [2] ")" "." -> "." [1][2]. */
    function tidyRun(first) {
        var last = first;
        while (isText(last.nextSibling) && /^[\s;,]*$/.test(last.nextSibling.data) && isCite(last.nextSibling.nextSibling)) {
            var gap = last.nextSibling;
            last = gap.nextSibling;
            gap.parentNode.removeChild(gap);
        }
        while (isCite(last.nextSibling)) last = last.nextSibling;

        var before = first.previousSibling;
        var after = last.nextSibling;
        if (!isText(before)) return last;
        if (!isText(after)) {
            before.data = before.data.replace(/\s+$/, '');
            return last;
        }

        if (/\(\s*$/.test(before.data) && /^\s*\)/.test(after.data)) {
            // "(source)" on its own: drop the brackets and the space before them.
            before.data = before.data.replace(/\s*\(\s*$/, '');
            after.data = after.data.replace(/^\s*\)/, '');
        } else if (/,\s*$/.test(before.data) && /\([^()]*,\s*$/.test(before.data) && /^\s*\)/.test(after.data)) {
            // "(Form 10-K for 2003, source)" -> "(Form 10-K for 2003)[1]".
            before.data = before.data.replace(/\s*,\s*$/, ')');
            after.data = after.data.replace(/^\s*\)/, '');
        }

        var punct = after.data.match(/^[.,;:!?]+["”’]?/);
        if (punct) {
            before.data = before.data.replace(/\s+$/, '') + punct[0];
            after.data = after.data.slice(punct[0].length);
        } else if (/\s$/.test(before.data) && !/^\s/.test(after.data) && after.data !== '') {
            // Keep the words apart once the number moves up: "text [1]word" -> "text[1] word".
            before.data = before.data.replace(/\s+$/, '');
            after.data = ' ' + after.data;
        } else {
            before.data = before.data.replace(/\s+$/, '');
        }
        return last;
    }

    /*
     * A wrapper holding only citations (the facility pages' .kop-fp-src): a span goes
     * inline after the text it cites; a <dd> of its own in the fact rail is folded
     * into the value's <dd> before it, so the numbers sit right after the value.
     */
    function settleWrapper(sup) {
        var wrap = sup.parentElement;
        if (!wrap || wrap.classList.contains('kop-cite-group')) return;
        if (!/^(?:SPAN|SMALL|DD)$/.test(wrap.tagName)) return;
        var only = Array.prototype.every.call(wrap.childNodes, function (node) {
            return isCite(node) || (isText(node) && /^[\s;,]*$/.test(node.data));
        });
        if (!only) return;
        Array.prototype.slice.call(wrap.childNodes).forEach(function (node) {
            if (isText(node)) wrap.removeChild(node);
        });
        var value = wrap.previousElementSibling;
        if (wrap.tagName === 'DD') {
            if (!value || value.tagName !== 'DD' || value.classList.contains('kop-fp-src')) return;
            if (isText(value.lastChild)) value.lastChild.data = value.lastChild.data.replace(/\s+$/, '');
            while (wrap.firstChild) value.appendChild(wrap.firstChild);
            wrap.parentNode.removeChild(wrap);
            return;
        }
        wrap.classList.add('kop-cite-group');
        if (isText(wrap.previousSibling)) wrap.previousSibling.data = wrap.previousSibling.data.replace(/\s+$/, '');
    }

    /* A link that can stand as a citation: a plain-text link to a page, not "(PDF)" or "(here)". */
    function citeLink(node) {
        if (!node || node.nodeType !== 1 || node.tagName !== 'A' || node.children.length) return false;
        var href = node.getAttribute('href') || '';
        if (!/^(?:https?:)?\/\//i.test(href) && href.charAt(0) !== '/') return false;
        var text = (node.textContent || '').trim();
        return text.length >= 3 && !/^(?:pdf|docx?|video|audio|map|photo|image|here|link|archived?)$/i.test(text);
    }

    /*
     * The links of a bracket that holds nothing but links: "([Form 10-K for 2011, SEC](url))" or
     * "([A](a); [B](b))", the way the company histories cite. Null for a link that is part of a
     * sentence ("according to the [r/troubledteens wiki](url)").
     */
    function bracketGroup(link) {
        var first = link;
        while (isText(first.previousSibling) && /^\s*;\s*$/.test(first.previousSibling.data) && citeLink(first.previousSibling.previousSibling)) {
            first = first.previousSibling.previousSibling;
        }
        if (!isText(first.previousSibling) || !/\(\s*$/.test(first.previousSibling.data)) return null;
        var group = [first];
        var last = first;
        while (isText(last.nextSibling) && /^\s*;\s*$/.test(last.nextSibling.data) && citeLink(last.nextSibling.nextSibling)) {
            last = last.nextSibling.nextSibling;
            group.push(last);
        }
        if (!isText(last.nextSibling) || !/^\s*\)/.test(last.nextSibling.data)) return null;
        return group.every(citeLink) ? group : null;
    }

    function numberSources(root) {
        var links = (root || document).querySelectorAll('a[href]:not(.kop-cite__link):not(.kop-cite-refs__link)');
        var made = [];
        var named = [];
        Array.prototype.forEach.call(links, function (link) {
            if (named.indexOf(link) !== -1 || SOURCE_TEXT.test((link.textContent || '').trim())) return;
            var group = bracketGroup(link);
            if (group) named.push.apply(named, group);
        });
        Array.prototype.forEach.call(links, function (link) {
            var text = (link.textContent || '').trim();
            if (!SOURCE_TEXT.test(text) && named.indexOf(link) === -1) return;
            if (link.children.length || link.closest(SKIP)) return;
            var cell = link.closest('td');
            if (cell && cell.textContent.trim() === text) return;
            var href = link.getAttribute('href') || '';
            if (!/^(?:https?:)?\/\//i.test(href) && href.charAt(0) !== '/') return;
            made.push(numberLink(link));
        });
        made.forEach(function (sup) {
            if (!sup.parentNode || isCite(sup.previousSibling)) return;
            if (isText(sup.previousSibling) && /^[\s;,]*$/.test(sup.previousSibling.data) && isCite(sup.previousSibling.previousSibling)) return;
            tidyRun(sup);
        });
        made.forEach(settleWrapper);
    }

    /* Open whatever hides an element: a <details>, or a folded section (its toggle, so the button's state follows). */
    function reveal(el) {
        for (var node = el; node && node !== document.body; node = node.parentElement) {
            if (node.tagName === 'DETAILS' && !node.open) node.open = true;
            if (node.hidden) {
                var toggle = node.id ? document.querySelector('[aria-controls="' + node.id + '"]') : null;
                if (toggle && toggle.getAttribute('aria-expanded') === 'false') toggle.click();
                if (node.hidden) node.hidden = false;
            }
        }
    }

    function followCitation(event) {
        var a = event.target.closest && event.target.closest('a.kop-cite__link, a.kop-cite-back');
        if (!a || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        var id = (a.getAttribute('href') || '').slice(1);
        var target = id && document.getElementById(id);
        if (!target) return;
        event.preventDefault();
        reveal(target);
        if (window.location.hash === '#' + id) {
            target.scrollIntoView({ block: 'center' });
        } else {
            window.location.hash = id;
        }
    }

    function initialize() {
        document.querySelectorAll('a[href^="#"]').forEach(addSourcePreview);
        numberSources(document);
        // Capture phase: the theme's smooth scroll for "#" links would otherwise scroll to a folded section first.
        document.addEventListener('click', followCitation, true);

        // Pages that draw their content in JS (state, country, wiki pages) add links later.
        if (!window.MutationObserver) return;
        var pending = false;
        new MutationObserver(function (mutations) {
            if (pending) return;
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].addedNodes.length) {
                    pending = true;
                    window.requestAnimationFrame(function () {
                        pending = false;
                        numberSources(document);
                    });
                    return;
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
