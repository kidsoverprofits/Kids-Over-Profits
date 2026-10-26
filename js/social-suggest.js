/**
 * "Suggest as a post" in the admin bar (inc/volunteer-social-suggest.php): select the words a post should be about,
 * press it, add a note if you like, Send. The card goes to the designer's "Suggested posts" project.
 * The selection is read on mousedown, before the click on the admin bar clears it.
 */
(function () {
    'use strict';
    var cfg = window.KOP_SOCIAL_SUGGEST;
    if (!cfg) return;

    var picked = null;   // {text, anchor} read as the button is pressed
    var panel = null;

    // The nearest anchor a reader can land on (a finding, a lawsuit, a section), never a page-wide wrapper.
    function anchorOf(node) {
        var el = node && (node.nodeType === 1 ? node : node.parentElement);
        while (el && el !== document.body) {
            if (el.id && !/^(post-\d+|main|primary|content|inner-wrap|wrapper|page|masthead|colophon)$/.test(el.id)
                && (el.textContent || '').length < 6000) return el.id;
            el = el.parentElement;
        }
        return '';
    }

    function readSelection() {
        var sel = window.getSelection();
        if (!sel || sel.isCollapsed || !sel.rangeCount) return null;
        var range = sel.getRangeAt(0);
        if (panel && panel.contains(range.commonAncestorContainer)) return null;
        var text = sel.toString().replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
        return text ? { text: text, anchor: anchorOf(range.startContainer) } : null;
    }

    function pageUrl(anchor) {
        var url = location.href.replace(/#.*$/, '');
        return anchor ? url + '#' + anchor : url;
    }

    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (text) e.textContent = text;
        return e;
    }

    function close() {
        if (panel) panel.remove();
        panel = null;
    }

    function open(sel) {
        close();
        panel = el('div', { 'class': 'kop-sugg', role: 'dialog', 'aria-labelledby': 'kop-sugg-h' });
        panel.appendChild(el('h2', { 'class': 'kop-sugg-h', id: 'kop-sugg-h' }, 'Suggest as a post'));
        panel.appendChild(el('p', { 'class': 'kop-sugg-help' },
            'It goes to the person who makes our posts, as an idea with this page as its link.'));

        var textLabel = el('label', { 'for': 'kop-sugg-text' }, 'The words it is about');
        var text = el('textarea', { id: 'kop-sugg-text', rows: '4' });
        text.value = sel ? sel.text : '';
        var noteLabel = el('label', { 'for': 'kop-sugg-note' }, 'What the post should say');
        var note = el('textarea', { id: 'kop-sugg-note', rows: '3', maxlength: '1000',
            placeholder: 'Optional when you selected words: the point, or why it matters' });
        var linkLabel = el('label', { 'for': 'kop-sugg-url' }, 'Link');
        var url = el('input', { id: 'kop-sugg-url', type: 'url' });
        url.value = pageUrl(sel ? sel.anchor : '');
        var status = el('p', { 'class': 'kop-sugg-status', role: 'status' });
        var send = el('button', { type: 'button', 'class': 'kop-sugg-send' }, 'Send');
        var cancel = el('button', { type: 'button', 'class': 'kop-sugg-cancel' }, 'Cancel');
        var row = el('div', { 'class': 'kop-sugg-row' });
        row.appendChild(send);
        row.appendChild(cancel);
        [textLabel, text, noteLabel, note, linkLabel, url, row, status].forEach(function (n) { panel.appendChild(n); });
        document.body.appendChild(panel);
        (text.value ? note : text).focus();

        cancel.addEventListener('click', close);
        panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        send.addEventListener('click', function () {
            if (!text.value.trim() && !note.value.trim()) {
                status.textContent = 'Select or paste the words the post is about, or write what it should say.';
                return;
            }
            send.disabled = true;
            status.textContent = 'Sending...';
            fetch(cfg.rest, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
                body: JSON.stringify({ url: url.value.trim(), title: document.title, text: text.value, note: note.value })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.error) throw new Error(d.error);
                status.textContent = (d && d.message) || 'Sent.';
                if (d && d.admin) {
                    status.appendChild(document.createTextNode(' '));
                    status.appendChild(el('a', { href: d.admin }, 'See the project'));
                }
                send.remove();
                cancel.textContent = 'Close';
            }).catch(function (e) {
                status.textContent = e.message || 'That did not go through. Try again.';
                send.disabled = false;
            });
        });
    }

    function isButton(e) {
        return e.target.closest && e.target.closest('#wp-admin-bar-kop-suggest-post');
    }
    document.addEventListener('mousedown', function (e) { if (isButton(e)) picked = readSelection(); }, true);
    document.addEventListener('click', function (e) {
        if (!isButton(e)) return;
        e.preventDefault();
        var sel = picked || readSelection();
        picked = null;
        open(sel);
    }, true);
})();
