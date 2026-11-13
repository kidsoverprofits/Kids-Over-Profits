/**
 * "Tell Claude" (admins only; inc/claude-feedback.php sends this script to nobody else).
 *
 * - Beside every feature's "Report a problem" flag (js/bug-reporter.js) a small sparkles button opens the
 *   panel already about that feature; the flag and the button share a .kop-feature-actions row.
 * - A floating button on every page (wp-admin and KOP Tools too) opens it about the page.
 * - "Point at it" lets the owner click the exact thing (the panel steps aside, Esc cancels).
 *
 * The note goes to kop/v1/claude/feedback with the page, the feature, the element pointed at, the text selected
 * when the panel opened and the page's recent errors. /claude-feedback in a Claude Code session works the queue;
 * its answer shows under "Your notes".
 */
(function () {
    'use strict';

    var cfg = window.KOP_CLAUDE_FEEDBACK;
    if (!cfg || !cfg.rest) return;

    var STATUS_LABELS = { waiting: 'Waiting for Claude', done: 'Done', returned: 'Claude has a question', withdrawn: 'Taken back' };

    var panel = null;
    var fields = {};
    var about = null;      // { feature, label } or null for the whole page
    var target = null;     // what "Point at it" picked
    var selection = '';    // text selected when the panel opened
    var picking = false;

    function icon(name) {
        return typeof window.kopIcon === 'function' ? window.kopIcon(name) : '';
    }

    function el(tag, className, text) {
        var n = document.createElement(tag);
        if (className) n.className = className;
        if (text) n.textContent = text;
        return n;
    }

    function api(path, method, body) {
        return fetch(cfg.rest + path, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok || !data.success) throw new Error((data && data.message) || ('HTTP ' + r.status));
                return data;
            });
        });
    }

    function grabSelection() {
        try {
            var s = String(window.getSelection ? window.getSelection() : '').trim();
            if (s) selection = s.slice(0, 4000);
        } catch (e) { /* no selection */ }
    }

    // ------------------------------------------------------------------
    // Buttons
    // ------------------------------------------------------------------

    /** The small button beside a feature's flag. An <a> with Kadence's outline classes, like the flag. */
    function featureButton(feature, label) {
        var a = el('a', 'kop-cfb-link button button-style-outline button-size-small');
        a.href = '#';
        a.setAttribute('role', 'button');
        a.title = 'Tell Claude about ' + label;
        a.setAttribute('aria-label', 'Tell Claude about ' + label);
        a.innerHTML = '<span class="kop-cfb-link__icon" aria-hidden="true">' + icon('sparkles') + '</span>';
        a.addEventListener('mousedown', grabSelection);
        a.addEventListener('click', function (e) {
            e.preventDefault();
            open({ feature: feature, label: label });
        });
        return a;
    }

    /** Puts a Claude button beside every flag that has none yet. */
    function upgradeFlags() {
        var flags = document.querySelectorAll('.kop-bug-report-link[data-kop-feature]');
        Array.prototype.forEach.call(flags, function (flag) {
            var parent = flag.parentElement;
            if (!parent) return;
            var next = flag.nextElementSibling;
            if (next && next.classList.contains('kop-cfb-link')) return;
            var btn = featureButton(flag.getAttribute('data-kop-feature'), flag.getAttribute('data-kop-feature-label') || flag.getAttribute('data-kop-feature'));
            if (flag.classList.contains('kop-bug-report-link--on-dark')) btn.classList.add('kop-cfb-link--on-dark');
            if (parent.classList.contains('kop-submit-info-row') || parent.classList.contains('kop-feature-actions')) {
                parent.insertBefore(btn, flag.nextSibling);
                return;
            }
            var row = el('div', 'kop-feature-actions');
            parent.insertBefore(row, flag);
            row.appendChild(flag);
            row.appendChild(btn);
        });
    }

    function watchFlags() {
        upgradeFlags();
        if (!window.MutationObserver) return;
        var pending = null;
        new MutationObserver(function () {
            if (pending) return;
            pending = setTimeout(function () { pending = null; upgradeFlags(); }, 300);
        }).observe(document.body, { childList: true, subtree: true });
    }

    function floatingButton() {
        var b = el('button', 'kop-cfb-fab');
        b.type = 'button';
        b.title = 'Tell Claude about this page';
        b.setAttribute('aria-label', 'Tell Claude about this page');
        b.innerHTML = '<span aria-hidden="true">' + icon('sparkles') + '</span><span class="kop-cfb-fab__text">Claude</span>';
        b.addEventListener('mousedown', grabSelection);
        b.addEventListener('click', function () {
            if (panel && !panel.hidden) { close(); return; }
            open(null);
        });
        document.body.appendChild(b);
    }

    // ------------------------------------------------------------------
    // Pointing at a thing
    // ------------------------------------------------------------------

    function cssPath(node) {
        var parts = [];
        for (var n = node; n && n.nodeType === 1 && n !== document.body && parts.length < 7; n = n.parentElement) {
            if (n.id && !/^\d/.test(n.id)) { parts.unshift('#' + n.id); break; }
            var part = n.tagName.toLowerCase();
            var cls = Array.prototype.filter.call(n.classList, function (c) { return !/^kop-cfb-/.test(c); }).slice(0, 2);
            if (cls.length) part += '.' + cls.join('.');
            var p = n.parentElement;
            if (p) {
                var same = Array.prototype.filter.call(p.children, function (c) { return c.tagName === n.tagName; });
                if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(n) + 1) + ')';
            }
            parts.unshift(part);
        }
        return parts.join(' > ');
    }

    /** The feature whose flag sits in this element or the nearest container around it. */
    function featureOf(node) {
        for (var n = node; n && n !== document.body; n = n.parentElement) {
            if (n.getAttribute && n.getAttribute('data-kop-bug-feature')) {
                return { feature: n.getAttribute('data-kop-bug-feature'), label: n.getAttribute('data-kop-bug-label') || n.getAttribute('data-kop-bug-feature') };
            }
            var flag = n.querySelector && n.querySelector(':scope > .kop-bug-report-link[data-kop-feature], :scope > .kop-feature-actions > .kop-bug-report-link[data-kop-feature], :scope > .kop-submit-info-row > .kop-bug-report-link[data-kop-feature]');
            if (flag) return { feature: flag.getAttribute('data-kop-feature'), label: flag.getAttribute('data-kop-feature-label') };
        }
        return null;
    }

    function describe(node) {
        var text = (node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
        var edit = node.closest('[data-kop-edit]');
        var r = node.getBoundingClientRect();
        return {
            selector: cssPath(node),
            tag: node.tagName.toLowerCase(),
            text: text.slice(0, 400),
            edit: edit ? edit.getAttribute('data-kop-edit') : '',
            href: node.closest('a') ? node.closest('a').getAttribute('href') : '',
            box: [Math.round(r.left), Math.round(r.top + window.scrollY), Math.round(r.width), Math.round(r.height)]
        };
    }

    var hoverBox = null;

    function onPickMove(e) {
        var t = e.target;
        if (!t || t === hoverBox || (t.closest && t.closest('.kop-cfb-panel, .kop-cfb-fab, .kop-cfb-pickbar'))) return;
        var r = t.getBoundingClientRect();
        hoverBox.style.cssText = 'top:' + r.top + 'px;left:' + r.left + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
        hoverBox.hidden = false;
    }

    function onPickClick(e) {
        if (e.target.closest && e.target.closest('.kop-cfb-pickbar')) return;
        e.preventDefault();
        e.stopPropagation();
        target = describe(e.target);
        if (!about) about = featureOf(e.target);
        stopPicking();
    }

    function onPickKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); stopPicking(); }
    }

    var pickBar = null;

    function startPicking() {
        picking = true;
        panel.hidden = true;
        if (!hoverBox) {
            hoverBox = el('div', 'kop-cfb-hover');
            hoverBox.hidden = true;
            document.body.appendChild(hoverBox);
            pickBar = el('div', 'kop-cfb-pickbar');
            pickBar.appendChild(el('span', null, 'Click the thing you mean.'));
            var cancel = el('button', 'kop-cfb-btn kop-cfb-btn--quiet', 'Cancel');
            cancel.type = 'button';
            cancel.addEventListener('click', stopPicking);
            pickBar.appendChild(cancel);
            document.body.appendChild(pickBar);
        }
        pickBar.hidden = false;
        document.addEventListener('mousemove', onPickMove, true);
        document.addEventListener('click', onPickClick, true);
        document.addEventListener('keydown', onPickKey, true);
    }

    function stopPicking() {
        picking = false;
        document.removeEventListener('mousemove', onPickMove, true);
        document.removeEventListener('click', onPickClick, true);
        document.removeEventListener('keydown', onPickKey, true);
        if (hoverBox) hoverBox.hidden = true;
        if (pickBar) pickBar.hidden = true;
        panel.hidden = false;
        renderAbout();
        fields.message.focus();
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    function build() {
        panel = el('div', 'kop-cfb-panel');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Tell Claude');
        panel.hidden = true;

        var head = el('div', 'kop-cfb-head');
        var title = el('div', 'kop-cfb-title');
        title.innerHTML = '<span aria-hidden="true">' + icon('sparkles') + '</span> Tell Claude';
        head.appendChild(title);
        var x = el('button', 'kop-cfb-btn kop-cfb-btn--quiet kop-cfb-close');
        x.type = 'button';
        x.setAttribute('aria-label', 'Close');
        x.innerHTML = icon('x') || '&times;';
        x.addEventListener('click', close);
        head.appendChild(x);
        panel.appendChild(head);

        fields.about = el('div', 'kop-cfb-about');
        panel.appendChild(fields.about);

        var label = el('label', 'kop-cfb-label', 'What is bothering you?');
        label.setAttribute('for', 'kop-cfb-message');
        panel.appendChild(label);
        fields.message = el('textarea', 'kop-cfb-input');
        fields.message.id = 'kop-cfb-message';
        fields.message.rows = 4;
        fields.message.placeholder = 'Anything: a bug, a wrong fact, wording, layout, an idea. Ctrl+Enter sends.';
        fields.message.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); send(); }
            if (e.key === 'Escape') { e.preventDefault(); close(); }
        });
        panel.appendChild(fields.message);

        var actions = el('div', 'kop-cfb-actions');
        var point = el('button', 'kop-cfb-btn kop-cfb-btn--outline', 'Point at it');
        point.type = 'button';
        point.title = 'Click the exact thing on the page';
        point.addEventListener('click', startPicking);
        actions.appendChild(point);
        fields.send = el('button', 'kop-cfb-btn kop-cfb-btn--primary', 'Send to Claude');
        fields.send.type = 'button';
        fields.send.addEventListener('click', send);
        actions.appendChild(fields.send);
        panel.appendChild(actions);

        fields.status = el('p', 'kop-cfb-status');
        fields.status.setAttribute('role', 'status');
        panel.appendChild(fields.status);

        var listHead = el('div', 'kop-cfb-list-head', 'Your notes');
        panel.appendChild(listHead);
        fields.list = el('ul', 'kop-cfb-list');
        panel.appendChild(fields.list);

        document.body.appendChild(panel);
    }

    function chip(text, onClear) {
        var c = el('span', 'kop-cfb-chip');
        c.appendChild(el('span', null, text));
        if (onClear) {
            var b = el('button', 'kop-cfb-btn kop-cfb-btn--quiet kop-cfb-chip__x');
            b.type = 'button';
            b.setAttribute('aria-label', 'Remove');
            b.innerHTML = icon('x') || '&times;';
            b.addEventListener('click', onClear);
            c.appendChild(b);
        }
        return c;
    }

    function renderAbout() {
        var box = fields.about;
        box.textContent = '';
        box.appendChild(chip(about ? 'About: ' + about.label : 'About: this page', about ? function () { about = null; renderAbout(); } : null));
        if (target) {
            var t = target.text ? '“' + target.text.slice(0, 60) + (target.text.length > 60 ? '…' : '') + '”' : '<' + target.tag + '>';
            box.appendChild(chip('Pointing at: ' + t, function () { target = null; renderAbout(); }));
        }
        if (selection) {
            box.appendChild(chip('Selected: “' + selection.slice(0, 60) + (selection.length > 60 ? '…' : '') + '”', function () { selection = ''; renderAbout(); }));
        }
    }

    function renderList(notes) {
        var ul = fields.list;
        ul.textContent = '';
        if (!notes || !notes.length) {
            ul.appendChild(el('li', 'kop-cfb-empty', 'Nothing sent yet.'));
            return;
        }
        notes.forEach(function (n) {
            var li = el('li', 'kop-cfb-item kop-cfb-item--' + n.status);
            var top = el('div', 'kop-cfb-item__top');
            top.appendChild(el('span', 'kop-cfb-pill kop-cfb-pill--' + n.status, STATUS_LABELS[n.status] || n.status));
            var where = el('a', 'kop-cfb-item__where', n.feature_label || n.page_title || 'page');
            where.href = n.page_url || '#';
            top.appendChild(where);
            li.appendChild(top);
            li.appendChild(el('p', 'kop-cfb-item__msg', n.message.length > 220 ? n.message.slice(0, 220) + '…' : n.message));
            if (n.claude_note) {
                var reply = el('p', 'kop-cfb-item__reply');
                reply.appendChild(el('strong', null, 'Claude: '));
                reply.appendChild(document.createTextNode(n.claude_note + ' '));
                if (n.commit && cfg.commits) {
                    var c = el('a', null, 'See the change');
                    c.href = cfg.commits + n.commit;
                    c.target = '_blank';
                    c.rel = 'noopener';
                    reply.appendChild(c);
                }
                li.appendChild(reply);
            }
            if (n.status === 'waiting') {
                var back = el('button', 'kop-cfb-btn kop-cfb-btn--quiet kop-cfb-item__back', 'Take back');
                back.type = 'button';
                back.addEventListener('click', function () {
                    back.disabled = true;
                    api('/' + n.id, 'POST', { outcome: 'withdrawn' }).then(function (d) { renderList(d.recent); })
                        .catch(function (err) { back.disabled = false; setStatus(err.message, true); });
                });
                li.appendChild(back);
            }
            ul.appendChild(li);
        });
    }

    function setStatus(text, isError) {
        fields.status.textContent = text || '';
        fields.status.className = 'kop-cfb-status' + (isError ? ' kop-cfb-status--error' : text ? ' kop-cfb-status--ok' : '');
    }

    function loadList() {
        api('/mine').then(function (d) { renderList(d.recent); }).catch(function () { /* the list is only a convenience */ });
    }

    function open(feature) {
        if (!panel) build();
        about = feature;
        target = null;
        renderAbout();
        setStatus('');
        panel.hidden = false;
        fields.message.focus();
        loadList();
    }

    function close() {
        if (picking) stopPicking();
        if (panel) panel.hidden = true;
        selection = '';
    }

    function send() {
        var message = fields.message.value.trim();
        if (!message) { setStatus('Write what is bothering you first.', true); fields.message.focus(); return; }
        var errors = window.KOPBugReporter && typeof window.KOPBugReporter.recentErrors === 'function' ? window.KOPBugReporter.recentErrors() : [];
        fields.send.disabled = true;
        setStatus('Sending…');
        api('', 'POST', {
            message: message,
            pageUrl: window.location.href,
            pageTitle: document.title,
            feature: about ? about.feature : '',
            featureLabel: about ? about.label : '',
            target: target,
            selection: selection,
            context: {
                viewport: window.innerWidth + 'x' + window.innerHeight,
                scrollY: Math.round(window.scrollY),
                userAgent: navigator.userAgent,
                adminScreen: cfg.screen || '',
                errors: errors
            }
        }).then(function (d) {
            fields.send.disabled = false;
            fields.message.value = '';
            target = null;
            selection = '';
            renderAbout();
            setStatus('Sent. Run /claude-feedback in a Claude Code session to have it worked.');
            renderList(d.recent);
        }).catch(function (err) {
            fields.send.disabled = false;
            setStatus('Not sent: ' + err.message, true);
        });
    }

    window.KOPClaudeFeedback = { open: open };

    function init() {
        floatingButton();
        watchFlags();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
