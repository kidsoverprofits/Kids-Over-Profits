/**
 * /volunteer-review/ (templates/volunteer-review.php, inc/review-volunteers.php).
 *
 * Projects first (inc/volunteer-projects.php): a small fixed batch with one
 * question, an example of a yes and of a no, shown one item at a time
 * ("Item 8 of 20") until it says Done; ?project=<id> opens one. Below them,
 * or alone when there are none, a tab per queue open to volunteers and "Your
 * recommendations". Each card
 * shows the item (title, link, facts, what approving or rejecting would do)
 * and three choices with a note: Recommend approve, Recommend reject, Not
 * sure. Saving files a recommendation; an admin makes the decision in the
 * review inbox. A recommendation can be taken back until the admin acts.
 *
 * A research project (inc/volunteer-research.php) shows a question card
 * instead: what they found, the links they found it at (each sent to the
 * review inbox), or "Could not find it" with where they looked.
 *
 * At the foot, "Questions? Ask us" (inc/volunteer-messages.php): one
 * conversation with the admins. "Ask a question" on a brief or a card names
 * the project or item the message is about. Replies show there with a "New"
 * mark and a line at the top of the page until they have been on screen.
 *
 * Every request is a POST to kop/v1/volunteer/* with the X-KOP-Vol header
 * the page was given (window.kopVolunteer.page).
 */
(function () {
    'use strict';

    var cfg = window.kopVolunteer || {};
    var app = document.getElementById('kop-vol-app');
    if (!cfg.rest || !app) return;

    var state = { queues: [], queue: null, offset: 0, mine: false, projects: [], project: null, practice: false, skip: [], msgCtx: null };

    // A multipart post (a picture upload): the same sign-in headers, the browser sets the content type.
    function apiForm(path, form) {
        var headers = {};
        if (cfg.page) headers['X-KOP-Vol'] = cfg.page;
        if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
        return fetch(cfg.rest + path, { method: 'POST', credentials: 'same-origin', headers: headers, body: form })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (!res.ok) throw new Error(data && (data.error || data.message) || ('Something went wrong (' + res.status + ').'));
                    return data;
                });
            });
    }

    function api(path, body) {
        var headers = { 'Content-Type': 'application/json' };
        if (cfg.page) headers['X-KOP-Vol'] = cfg.page;
        if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
        return fetch(cfg.rest + path, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body || {}) })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (!res.ok) throw new Error(data && (data.error || data.message) || ('Something went wrong (' + res.status + ').'));
                    return data;
                });
            });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === '') return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function safe(url) {
        return /^https?:\/\//i.test(String(url || '')) ? url : null;
    }

    function host(url) {
        try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return 'the source'; }
    }

    function link(url, text) {
        return el('a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: text });
    }

    function fmtDate(iso) {
        // A bare date is the day itself, not midnight UTC (which reads as the day before in the US).
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        var d = m ? new Date(+m[1], m[2] - 1, +m[3]) : new Date(iso);
        return isNaN(d) ? String(iso || '').slice(0, 10) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    /* ---- Tabs ----------------------------------------------------------------- */

    var tabs = el('nav', { class: 'kop-vol-tabs', 'aria-label': 'What to review' });
    var body = el('div', { class: 'kop-vol-body', 'aria-live': 'polite' });

    function renderTabs() {
        tabs.innerHTML = '';
        state.queues.forEach(function (q) {
            tabs.appendChild(el('button', {
                type: 'button', class: 'kop-vol-tab' + (!state.mine && state.queue === q.key ? ' is-active' : ''),
                'aria-pressed': !state.mine && state.queue === q.key ? 'true' : 'false',
                onclick: function () { openQueue(q.key); }
            }, [q.label, q.count ? el('span', { class: 'kop-vol-count', text: String(q.count) }) : null]));
        });
        tabs.appendChild(el('button', {
            type: 'button', class: 'kop-vol-tab kop-vol-tab-mine' + (state.mine ? ' is-active' : ''), 'aria-pressed': state.mine ? 'true' : 'false',
            onclick: openMine
        }, ['Your past answers']));
    }

    function remember() {
        try {
            var u = new URL(window.location.href);
            u.searchParams.delete('project');
            u.searchParams.set('q', state.mine ? '_mine' : state.queue);
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* the tab just is not remembered */ }
    }

    /* ---- A queue ---------------------------------------------------------------- */

    var list;
    var more;
    var status;

    function openQueue(key) {
        state.project = null;
        state.practice = false;
        state.queue = key;
        state.mine = false;
        state.offset = 0;
        renderTabs();
        remember();
        var q = state.queues.filter(function (x) { return x.key === key; })[0] || {};
        body.innerHTML = '';
        var head = el('section', { class: 'kop-vol-panel kop-vol-guide' }, [
            el('h2', { text: q.label }),
            q.guidance ? el('p', { text: q.guidance }) : null
        ]);
        status = el('p', { class: 'kop-vol-status', role: 'status', text: 'Loading…' });
        head.appendChild(status);
        list = el('div', { class: 'kop-vol-list' });
        more = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Show more', hidden: true, onclick: loadMore });
        body.appendChild(head);
        body.appendChild(list);
        body.appendChild(el('div', { class: 'kop-vol-more' }, [more]));
        loadMore();
    }

    function loadMore() {
        more.hidden = true;
        var key = state.queue;
        api('items', { source: key, offset: state.offset, limit: 10 }).then(function (data) {
            if (key !== state.queue || state.mine) return;
            (data.items || []).forEach(function (it) { list.appendChild(card(it)); });
            state.offset = data.next_offset;
            var shown = list.querySelectorAll('.kop-vol-card').length;
            status.textContent = shown ? '' : 'Nothing waiting for you here right now. Thank you! Try another tab.';
            status.hidden = !!shown;
            more.hidden = !(data.next_offset >= 0 && (data.items || []).length);
        }).catch(function (e) {
            status.hidden = false;
            status.textContent = 'Could not load: ' + e.message;
        });
    }

    function card(it, onDone) {
        var node = el('article', { class: 'kop-vol-card kop-vol-panel' });
        fillCard(node, it, onDone);
        return node;
    }

    // The whole scraped report, read when opened (kop/v1/volunteer/fulltext).
    function fullText(it) {
        var box = el('div', { class: 'kop-vol-fulltext-body', tabindex: '0', text: 'Loading...' });
        var fold = el('details', { class: 'kop-vol-fulltext' }, [el('summary', { text: 'Read the full report' }), box]);
        var asked = false;
        fold.addEventListener('toggle', function () {
            if (!fold.open || asked) return;
            asked = true;
            api('fulltext', { source: it.source, key: it.key }).then(function (data) {
                box.textContent = data.text || 'The report has no text on file.';
            }).catch(function (err) {
                asked = false;
                box.textContent = err.message;
            });
        });
        return fold;
    }

    function fillCard(node, it, onDone) {
        node.innerHTML = '';
        node.appendChild(el('h3', { class: 'kop-vol-title', text: it.title || '(no title)' }));
        var meta = [it.subtitle, it.created ? 'Added ' + fmtDate(it.created) : ''].filter(Boolean).join(' · ');
        if (meta) node.appendChild(el('p', { class: 'kop-vol-meta', text: meta }));

        var links = el('ul', { class: 'kop-vol-links' });
        if (safe(it.url)) links.appendChild(el('li', null, [link(it.url, 'Open it on ' + host(it.url))]));
        if (it.facility && it.facility.name) {
            links.appendChild(el('li', null, [safe(it.facility.url) ? link(it.facility.url, 'Our page for ' + it.facility.name) : 'Program: ' + it.facility.name]));
        }
        (it.links || []).forEach(function (l) { if (safe(l.url)) links.appendChild(el('li', null, [link(l.url, l.label || host(l.url))])); });
        if (links.childNodes.length) node.appendChild(links);

        if (it.text) node.appendChild(el('p', { class: 'kop-vol-text', text: it.text }));
        if (it.full_text) node.appendChild(fullText(it));
        if (it.facts && it.facts.length) {
            var dl = el('dl', { class: 'kop-vol-facts' });
            it.facts.forEach(function (f) {
                dl.appendChild(el('dt', { text: f.label }));
                dl.appendChild(el('dd', null, [safe(f.url) ? link(f.url, f.value) : f.value]));
            });
            node.appendChild(dl);
        }
        if (it.compare && it.compare.rows && it.compare.rows.length) {
            var table = el('table', { class: 'kop-vol-compare' });
            table.appendChild(el('thead', null, [el('tr', null, [el('th', { text: '' })].concat((it.compare.heads || []).map(function (h) { return el('th', { scope: 'col', text: h }); })))]));
            var tb = el('tbody');
            it.compare.rows.forEach(function (r) {
                tb.appendChild(el('tr', null, [el('th', { scope: 'row', text: r.label })].concat((r.values || []).map(function (v) { return el('td', { text: v }); }))));
            });
            table.appendChild(tb);
            node.appendChild(el('div', { class: 'kop-vol-compare-wrap' }, [table]));
        }

        if ((it.approve && it.approve.length) || (it.reject && it.reject.length)) {
            var what = el('div', { class: 'kop-vol-what' }, [el('p', { class: 'kop-vol-what-head', text: 'What the admin would do' })]);
            if (it.approve && it.approve.length) what.appendChild(el('p', null, [el('strong', { text: 'Approve: ' }), it.approve.join(' Or: ')]));
            if (it.reject && it.reject.length) what.appendChild(el('p', null, [el('strong', { text: 'Reject: ' }), it.reject.join(' Or: ')]));
            node.appendChild(what);
        }

        var id = 'kop-vol-note-' + Math.random().toString(36).slice(2);
        var note = el('textarea', { id: id, rows: '2', maxlength: '1000', placeholder: it.practice ? 'Why? (just for you: practice notes are not kept)' : 'Why? (optional for approve or reject, needed for not sure)' });
        var msg = el('p', { class: 'kop-vol-msg', role: 'status', hidden: true });
        var buttons = [
            ['approve', 'Recommend approve', 'kop-vol-btn kop-vol-btn-approve'],
            ['reject', 'Recommend reject', 'kop-vol-btn kop-vol-btn-reject'],
            ['unsure', 'Not sure', 'kop-vol-btn kop-vol-btn-plain']
        ].map(function (b) {
            return el('button', { type: 'button', class: b[2], text: b[1], disabled: cfg.preview ? true : null, onclick: function () { send(b[0], this); } });
        });
        function send(verdict, btn) {
            buttons.forEach(function (b) { b.disabled = true; });
            msg.hidden = true;
            // A practice card's call goes to the practice round only, never in as a recommendation.
            (it.practice ? api('practice', { do: 'answer', key: it.key, verdict: verdict })
                : api('recommend', { source: it.source, key: it.key, verdict: verdict, note: note.value })).then(function (res) {
                if (onDone) onDone(it, verdict, note.value, res);
                else done(node, it, verdict, note.value, res.message);
            }).catch(function (e) {
                buttons.forEach(function (b) { b.disabled = false; });
                msg.hidden = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
                btn.focus();
            });
        }
        if (onDone && !it.practice) {
            buttons.push(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Skip for now', onclick: function () {
                state.skip.push(it.key);
                nextProjectItem();
            } }));
        }
        node.appendChild(el('div', { class: 'kop-vol-decide' }, [
            el('label', { for: id, text: 'Your note' }), note,
            el('div', { class: 'kop-vol-buttons' }, buttons), msg
        ]));
        node.appendChild(askButton({ project_id: state.project || 0, item_key: it.source + ':' + it.key, item_title: it.title, label: it.title || 'this item' }));
    }

    /* ---- A research question --------------------------------------------------------- */

    var SENT = { 'news review': 'sent to the news queue', 'lawsuit review': 'sent to the court cases queue', 'legislation review': 'sent to the bills queue',
        'website review': 'sent to the websites queue', 'already held': 'we already have it', 'not sent': 'saved with your answer' };

    function linkRow(kinds, l) {
        var url = el('input', { type: 'url', class: 'kop-vol-link-url', placeholder: 'https://', value: l.url || '', 'aria-label': 'Link' });
        var kind = el('select', { class: 'kop-vol-link-kind', 'aria-label': 'What kind of page' });
        Object.keys(kinds).forEach(function (k) {
            var o = el('option', { value: k, text: kinds[k] });
            if (k === l.kind) o.selected = true;
            kind.appendChild(o);
        });
        var row = el('div', { class: 'kop-vol-link-row' }, [url, kind]);
        row.read = function () { return { url: url.value.trim(), kind: kind.value }; };
        return row;
    }

    function researchCard(it, p, onDone) {
        var node = el('article', { class: 'kop-vol-card kop-vol-panel kop-vol-research' });
        var kinds = p.link_kinds || { article: 'News story', website: 'Other page' };
        node.appendChild(el('h3', { class: 'kop-vol-title', text: it.title || '(no title)' }));
        if (it.question && it.question !== it.title) node.appendChild(el('p', { class: 'kop-vol-question' }, [el('strong', { text: 'Find out: ' }), it.question]));
        if (it.record && it.record.name) {
            node.appendChild(el('ul', { class: 'kop-vol-links' }, [el('li', null, [safe(it.record.url) ? link(it.record.url, 'What our page says about ' + it.record.name) : it.record.name])]));
        }
        if (it.facts && it.facts.length) {
            var dl = el('dl', { class: 'kop-vol-facts' });
            it.facts.forEach(function (f) { dl.appendChild(el('dt', { text: f.label })); dl.appendChild(el('dd', { text: f.value })); });
            node.appendChild(dl);
        }
        var id = 'kop-vol-answer-' + Math.random().toString(36).slice(2);
        var prev = it.answer || {};
        var answer = el('textarea', { id: id, rows: '4', maxlength: '4000', placeholder: 'What did you find? Quote the sentence that says it if you can.' });
        answer.value = prev.answer || '';
        var links = el('div', { class: 'kop-vol-link-rows' });
        (prev.links && prev.links.length ? prev.links : [{ kind: it.link_kind }]).forEach(function (l) { links.appendChild(linkRow(kinds, l)); });
        var add = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Add another link', onclick: function () {
            if (links.childNodes.length >= 6) return;
            var r = linkRow(kinds, { kind: it.link_kind });
            links.appendChild(r);
            r.firstChild.focus();
        } });
        var msg = el('p', { class: 'kop-vol-msg', role: 'status', hidden: true });
        var buttons = [
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Save my answer', disabled: cfg.preview ? true : null, onclick: function () { send(false, this); } }),
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Could not find it', disabled: cfg.preview ? true : null, onclick: function () { send(true, this); } }),
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Skip for now', onclick: function () { state.skip.push(it.key); nextProjectItem(); } })
        ];
        function send(notFound, btn) {
            var list = Array.prototype.map.call(links.childNodes, function (r) { return r.read(); }).filter(function (l) { return l.url; });
            if (notFound && !answer.value.trim()) {
                msg.hidden = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = 'Say where you looked first, so nobody searches the same places again.';
                answer.focus();
                return;
            }
            buttons.forEach(function (b) { b.disabled = true; });
            msg.hidden = true;
            api('answer', { project_id: it.project_id, key: it.key, answer: answer.value, links: list, not_found: notFound ? 1 : 0 }).then(function (res) {
                onDone(it, res.answer || {});
            }).catch(function (e) {
                buttons.forEach(function (b) { b.disabled = false; });
                msg.hidden = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
                btn.focus();
            });
        }
        node.appendChild(el('div', { class: 'kop-vol-decide' }, [
            el('label', { for: id, text: 'Your answer' }), answer,
            el('p', { class: 'kop-vol-label', text: 'Where you found it (each link goes to our review queue too)' }), links, add,
            el('div', { class: 'kop-vol-buttons' }, buttons), msg
        ]));
        node.appendChild(askButton({ project_id: it.project_id, item_key: 'research:' + it.project_id + ':' + it.key, item_title: it.title, label: it.title }));
        return node;
    }

    // A post idea (social media project): the card's facts, then Taking this -> caption + picture link -> Send for review.
    function socialCard(it, p, onDone) {
        var node = el('article', { class: 'kop-vol-card kop-vol-panel kop-vol-social' });
        var post = it.post || null;
        if (it.angle_label) node.appendChild(el('p', { class: 'kop-vol-angle', text: it.angle_label + (it.when ? ' · ' + it.when : '') }));
        node.appendChild(el('h3', { class: 'kop-vol-title', text: it.fact || it.title || '(no title)' }));
        if (safe(it.url)) node.appendChild(el('ul', { class: 'kop-vol-links' }, [el('li', null, [link(it.url, it.url_label || 'Our page about it')])]));
        if (it.summary) node.appendChild(el('p', { class: 'kop-vol-text' }, [el('strong', { text: 'In plain words: ' }), it.summary]));
        if (it.quote) node.appendChild(el('blockquote', { class: 'kop-vol-quote', text: it.quote }));
        if (safe(it.image)) node.appendChild(el('p', null, [link(it.image, 'A picture we hold (use it or make your own)')]));
        if (post && post.status === 'sent_back' && post.owner_note) {
            node.appendChild(el('p', { class: 'kop-vol-sentback' }, [el('strong', { text: 'Sent back: ' }), post.owner_note]));
        }
        var msg = el('p', { class: 'kop-vol-msg', role: 'status', hidden: true });
        function fail(e, buttons) {
            buttons.forEach(function (b) { b.disabled = false; });
            msg.hidden = false;
            msg.className = 'kop-vol-msg is-error';
            msg.textContent = e.message;
        }
        if (!post || post.status === 'passed') {
            var start = [
                el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Taking this', disabled: cfg.preview ? true : null, onclick: function () { step('take'); } }),
                el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Pass on this one', disabled: cfg.preview ? true : null, onclick: function () { step('pass'); } }),
                el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Skip for now', onclick: function () { state.skip.push(it.key); nextProjectItem(); } })
            ];
            var step = function (doing) {
                start.forEach(function (b) { b.disabled = true; });
                api('post', { project_id: it.project_id, key: it.key, do: doing }).then(function (res) {
                    if (doing === 'pass') { onDone(it, 'pass'); return; }
                    it.post = res.post;
                    node.replaceWith(socialCard(it, p, onDone));
                }).catch(function (e) { fail(e, start); });
            };
            node.appendChild(el('div', { class: 'kop-vol-buttons' }, start));
            node.appendChild(msg);
            node.appendChild(askButton({ project_id: it.project_id, item_key: 'social:' + it.project_id + ':' + it.key, item_title: it.fact || it.title, label: it.fact || it.title }));
            return node;
        }
        var capId = 'kop-vol-caption-' + Math.random().toString(36).slice(2);
        var imgId = capId + '-img';
        var noteId = capId + '-note';
        var caption = el('textarea', { id: capId, rows: '6', maxlength: String(p.caption_max || 2200), placeholder: 'The caption, as it will be posted.' });
        caption.value = post.caption || '';
        var count = el('p', { class: 'kop-vol-meta', 'aria-live': 'polite' });
        function counted() { count.textContent = caption.value.length + ' of ' + (p.caption_max || 2200) + ' characters'; }
        caption.addEventListener('input', function () { counted(); if (msg.className.indexOf('is-error') >= 0) msg.hidden = true; });
        counted();
        var image = el('input', { id: imgId, type: 'url', maxlength: '500', placeholder: 'https://… (Canva, Google Drive, anywhere we can open it)' });
        image.value = post.image_url || '';
        var note = el('input', { id: noteId, type: 'text', maxlength: '1000', placeholder: 'Anything for the reviewer (optional)' });
        note.value = post.note || '';
        var fileId = capId + '-file';
        var file = el('input', { id: fileId, type: 'file', accept: 'image/png,image/jpeg,image/webp,image/gif', disabled: cfg.preview ? true : null });
        var upState = el('p', { class: 'kop-vol-meta', 'aria-live': 'polite', text: post.uploaded ? 'Your uploaded picture is saved. Upload another to replace it.' : 'PNG, JPEG, WebP or GIF, up to 10 MB.' });
        var preview = el('img', { class: 'kop-vol-upload-preview', alt: 'Your picture', hidden: true });
        file.addEventListener('change', function () {
            var f = file.files && file.files[0];
            if (!f) return;
            var form = new FormData();
            form.append('project_id', it.project_id);
            form.append('key', it.key);
            form.append('image', f);
            upState.textContent = 'Uploading…';
            file.disabled = true;
            apiForm('upload', form).then(function (res) {
                post.uploaded = true;
                upState.textContent = res.message || 'Picture saved.';
                try { preview.src = URL.createObjectURL(f); preview.hidden = false; } catch (e) { /* no preview */ }
            }).catch(function (e) {
                upState.textContent = e.message;
            }).then(function () { file.disabled = false; file.value = ''; });
        });
        var buttons = [
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Send for review', disabled: cfg.preview ? true : null, onclick: function () { save('ready'); } }),
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Save draft', disabled: cfg.preview ? true : null, onclick: function () { save('draft'); } }),
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Pass on this one', disabled: cfg.preview ? true : null, onclick: function () { save('pass'); } }),
            el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Skip for now', onclick: function () { state.skip.push(it.key); nextProjectItem(); } })
        ];
        function save(doing) {
            buttons.forEach(function (b) { b.disabled = true; });
            msg.hidden = true;
            api('post', { project_id: it.project_id, key: it.key, do: doing, caption: caption.value, image_url: image.value.trim(), note: note.value }).then(function (res) {
                if (doing === 'draft') {
                    buttons.forEach(function (b) { b.disabled = false; });
                    msg.hidden = false;
                    msg.className = 'kop-vol-msg';
                    msg.textContent = res.message || 'Draft saved.';
                    return;
                }
                onDone(it, doing);
            }).catch(function (e) { fail(e, buttons); });
        }
        node.appendChild(el('div', { class: 'kop-vol-decide' }, [
            el('label', { for: capId, text: 'Caption' }), caption, count,
            el('label', { for: fileId, text: 'Upload your picture' }), file, upState, preview,
            el('label', { for: imgId, text: 'Or a link to it' + (safe(it.image) ? ' (leave both empty to use ours)' : '') }), image,
            el('label', { for: noteId, text: 'Note' }), note,
            el('div', { class: 'kop-vol-buttons' }, buttons), msg
        ]));
        node.appendChild(askButton({ project_id: it.project_id, item_key: 'social:' + it.project_id + ':' + it.key, item_title: it.fact || it.title, label: it.fact || it.title }));
        return node;
    }

    var SAID = { approve: 'approve', reject: 'reject', unsure: 'not sure' };

    // After a card shrinks or is swapped for the next one, the reader is left far below it: bring its top back to
    // the top of the screen (only when it is above the screen, so nothing moves otherwise) and put focus there.
    function bringToTop(node) {
        if (!node || !node.getBoundingClientRect) return;
        var top = node.getBoundingClientRect().top;
        if (top < 0) window.scrollBy(0, top - 16);
        if (!node.hasAttribute('tabindex')) node.setAttribute('tabindex', '-1');
        try { node.focus({ preventScroll: true }); } catch (e) { /* old browsers */ }
    }

    function done(node, it, verdict, note, message) {
        node.innerHTML = '';
        node.classList.add('is-done');
        node.appendChild(el('p', { class: 'kop-vol-done' }, [
            el('strong', { text: it.title || '(no title)' }), ': you said ', el('em', { text: SAID[verdict] }), note ? ' (' + note + ')' : '', '. ',
            message || ''
        ]));
        node.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Take it back', onclick: function () {
            this.disabled = true;
            api('recommend', { source: it.source, key: it.key, withdraw: 1 }).then(function () {
                node.classList.remove('is-done');
                fillCard(node, it);
                bringToTop(node);
            }).catch(function (e) { node.appendChild(el('p', { class: 'kop-vol-msg is-error', text: e.message })); });
        } }));
        bringToTop(node);
    }

    /* ---- Your recommendations ---------------------------------------------------- */

    var OUTCOME = { '': 'Waiting for an admin', approve: 'An admin approved it', reject: 'An admin rejected it', gone: 'Handled elsewhere', dismissed: 'Closed by an admin' };

    function openMine() {
        state.project = null;
        state.practice = false;
        state.mine = true;
        renderTabs();
        remember();
        body.innerHTML = '';
        var panel = el('section', { class: 'kop-vol-panel' }, [
            el('h2', { text: 'Your past answers' }),
            el('p', { text: 'Everything you recommended or answered, and what the admin decided. You can take one back until an admin acts on it.' })
        ]);
        var st = el('p', { class: 'kop-vol-status', role: 'status', text: 'Loading…' });
        panel.appendChild(st);
        body.appendChild(panel);
        api('mine').then(function (data) {
            var rows = data.rows || [];
            st.textContent = rows.length ? '' : (cfg.preview ? 'Admins have no recommendations of their own.' : 'None yet.');
            st.hidden = !!rows.length;
            if (!rows.length) return;
            var ul = el('ul', { class: 'kop-vol-mine' });
            rows.forEach(function (r) {
                if (r.kind === 'answer') { ul.appendChild(mineAnswer(r)); return; }
                var same = (r.outcome === 'approve' || r.outcome === 'reject') && r.verdict !== 'unsure'
                    ? (r.outcome === r.verdict ? ' (same as you)' : ' (differently from you)') : '';
                var li = el('li', null, [
                    el('strong', { text: r.title || r.key }), el('span', { class: 'kop-vol-meta', text: ' ' + r.source_label + ', ' + fmtDate(r.at) }), el('br'),
                    'You said ', el('em', { text: String(r.verdict_label).toLowerCase() }), r.note ? ' (' + r.note + ')' : '', '. ',
                    el('span', { class: 'kop-vol-outcome', text: (OUTCOME[r.outcome] || r.outcome) + same + '.' })
                ]);
                if (r.outcome === '') {
                    li.appendChild(document.createTextNode(' '));
                    li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                        var b = this;
                        b.disabled = true;
                        api('recommend', { source: r.source, key: r.key, withdraw: 1 }).then(function () {
                            li.remove();
                        }).catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
                    } }));
                }
                ul.appendChild(li);
            });
            panel.appendChild(ul);
        }).catch(function (e) { st.textContent = 'Could not load: ' + e.message; });
    }

    var ANSWERED = { '': 'Waiting for an admin to read it', used: 'An admin used it. Thank you!', dismissed: 'An admin read it; it was not needed this time' };

    function mineAnswer(r) {
        var li = el('li', null, [
            el('strong', { text: r.title || r.key }), el('span', { class: 'kop-vol-meta', text: ' ' + r.source_label + ', ' + fmtDate(r.at) }), el('br'),
            r.found ? 'You answered' : 'You could not find it', r.answer ? ': ' + (r.answer.length > 160 ? r.answer.slice(0, 157) + '...' : r.answer) : '',
            r.links ? ' (' + r.links + (r.links === 1 ? ' link' : ' links') + ')' : '', '. ',
            el('span', { class: 'kop-vol-outcome', text: (ANSWERED[r.outcome] || r.outcome) + '.' })
        ]);
        if (r.outcome === '') {
            li.appendChild(document.createTextNode(' '));
            li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                var b = this;
                b.disabled = true;
                api('answer', { project_id: r.project_id, key: r.key, withdraw: 1 }).then(function () { li.remove(); })
                    .catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
            } }));
        }
        return li;
    }

    /* ---- Projects ----------------------------------------------------------------- */

    var projectsPanel = el('section', { class: 'kop-vol-panel kop-vol-projects' });

    function progressBar(done, total) {
        var pct = total ? Math.round(100 * done / total) : 0;
        return el('div', { class: 'kop-vol-progress' }, [
            el('div', { class: 'kop-vol-progress-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(total), 'aria-valuenow': String(done),
                'aria-label': done + ' of ' + total + ' done' }, [el('span', { style: 'width:' + pct + '%' })]),
            el('span', { class: 'kop-vol-progress-text', text: done + ' of ' + total + ' done' })
        ]);
    }

    function renderProjects() {
        projectsPanel.innerHTML = '';
        projectsPanel.appendChild(el('h2', { text: state.projects.length === 1 ? 'Your project' : (state.projects.length ? 'Your projects' : (canPractice() ? 'Start here' : 'Your next batch')) }));
        var ul = el('ul', { class: 'kop-vol-project-list' });
        state.projects.forEach(function (p) {
            var finished = p.done >= p.total;
            ul.appendChild(el('li', { class: 'kop-vol-project' + (finished ? ' is-finished' : '') }, [
                el('h3', { text: p.title }),
                el('p', { class: 'kop-vol-meta', text: p.total + ' items, about ' + p.minutes + ' minutes' + (p.mine ? '' : ' · open to every volunteer') }),
                progressBar(p.done, p.total),
                finished ? el('p', { text: 'Finished. Thank you!' })
                    : el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: state.project === p.id ? 'Working on it below' : (p.done ? 'Carry on' : 'Start'),
                        disabled: state.project === p.id ? true : null, onclick: function () { openProject(p.id); } })
            ]));
        });
        if (state.projects.length) projectsPanel.appendChild(ul);
        var self = state.self;
        if (self && self.eligible && !self.open && self.queues.length) projectsPanel.appendChild(selfAssignForm(self));
        if (canPractice()) projectsPanel.appendChild(practiceForm(self.practice));
    }

    /* ---- Practice round (inc/volunteer-practice.php) ------------------------------------ */

    // Not able to pick a batch yet, and a practice round to start or carry on.
    function canPractice() {
        var self = state.self;
        return !!(self && !self.eligible && self.practice && (self.practice.open || self.practice.queues.length)) && !cfg.preview;
    }

    function practiceForm(pr) {
        var box = el('div', { class: 'kop-vol-self' }, [
            el('h3', { text: 'Practice round' }),
            el('p', { class: 'kop-vol-meta', text: pr.size + ' items the admins have already decided. After each one you see what they decided. Get ' + pr.pass + ' of ' + pr.size
                + ' right and you can pick your own batches from that queue.' })
        ]);
        if (pr.last && !pr.last.passed && !pr.open) {
            box.appendChild(el('p', { class: 'kop-vol-meta', text: 'Last round: ' + pr.last.right + ' of ' + pr.last.total + ' right in ' + pr.last.label + '.' }));
        }
        if (pr.open) {
            box.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: state.practice ? 'Working on it below' : 'Carry on (' + pr.open.done + ' of ' + pr.open.total + ')',
                disabled: state.practice ? true : null, onclick: openPractice }));
            return box;
        }
        var pick = el('select', { id: 'kop-vol-practice-queue' }, pr.queues.map(function (q) { return el('option', { value: q.key, text: q.label }); }));
        var msg = el('p', { class: 'kop-vol-msg', role: 'status' });
        var go = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Start practice', onclick: function () {
            go.disabled = true;
            msg.className = 'kop-vol-msg';
            msg.textContent = 'Setting it up…';
            startPractice(pick.value).catch(function (e) {
                go.disabled = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
            });
        } });
        box.appendChild(el('label', { for: 'kop-vol-practice-queue', text: 'Queue ' }));
        box.appendChild(pick);
        box.appendChild(document.createTextNode(' '));
        box.appendChild(go);
        box.appendChild(msg);
        return box;
    }

    function startPractice(source) {
        return api('practice', { do: 'start', source: source }).then(function (pr) {
            state.self.practice = pr;
            openPractice();
        });
    }

    function openPractice() {
        state.project = null;
        state.practice = true;
        state.mine = false;
        state.queue = null;
        renderTabs();
        renderProjects();
        body.innerHTML = '';
        projBrief = el('section', { class: 'kop-vol-panel kop-vol-guide kop-vol-brief' }, [el('p', { role: 'status', text: 'Loading…' })]);
        projSlot = el('div', { class: 'kop-vol-list' });
        body.appendChild(projBrief);
        body.appendChild(projSlot);
        nextPracticeItem();
    }

    function drawPracticeBrief(brief, round) {
        projBrief.innerHTML = '';
        projBrief.appendChild(el('h2', { text: brief.title }));
        projBrief.appendChild(progressBar(round.done, round.total));
        projBrief.appendChild(el('p', { class: 'kop-vol-note', text: 'These are already decided: nothing you say here changes anything or counts as a recommendation.' }));
        projBrief.appendChild(el('p', { class: 'kop-vol-question' }, [el('strong', { text: 'The question: ' }), brief.question]));
        if (brief.yes || brief.no) {
            var dl = el('dl', { class: 'kop-vol-examples' });
            if (brief.yes) { dl.appendChild(el('dt', { text: 'A yes looks like' })); dl.appendChild(el('dd', { text: brief.yes })); }
            if (brief.no) { dl.appendChild(el('dt', { text: 'A no looks like' })); dl.appendChild(el('dd', { text: brief.no })); }
            projBrief.appendChild(dl);
        }
        projBrief.appendChild(askButton({ project_id: 0, label: 'the practice round' }, 'Ask a question about the practice round'));
    }

    function nextPracticeItem() {
        projSlot.style.minHeight = projSlot.offsetHeight ? projSlot.offsetHeight + 'px' : '';
        projSlot.innerHTML = '';
        projSlot.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { role: 'status', text: 'Loading…' })]));
        api('practice', { do: 'next' }).then(function (data) {
            if (!state.practice) return;
            var round = data.round || {};
            drawPracticeBrief(data.brief || {}, round);
            projSlot.innerHTML = '';
            projSlot.style.minHeight = '';
            if (!data.item) { practiceResult(round); return; }
            projSlot.appendChild(el('p', { class: 'kop-vol-position', text: 'Item ' + Math.min(round.total, round.done + 1) + ' of ' + round.total }));
            var node = card(data.item, function (item, verdict, note, res) {
                node.innerHTML = '';
                node.classList.add('is-done');
                var said = verdict === 'unsure' ? 'You said not sure. ' : '';
                node.appendChild(el('p', { class: 'kop-vol-done' }, [
                    el('strong', { text: res.right ? 'Same as the admin. ' : 'Not this time. ' }), said + res.said
                ]));
                if (res.round) drawPracticeBrief(data.brief || {}, res.round);
                var next = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: res.round && res.round.finished ? 'See how you did' : 'Next',
                    onclick: function () { if (res.round && res.round.finished) practiceResult(res.round); else nextPracticeItem(); } });
                node.appendChild(next);
                bringToTop(node);
                try { next.focus({ preventScroll: true }); } catch (e) { next.focus(); }
            });
            projSlot.appendChild(node);
            bringToTop(projSlot);
        }).catch(function (e) {
            projSlot.innerHTML = '';
            projSlot.style.minHeight = '';
            projSlot.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: e.message })]));
        });
    }

    function practiceResult(round) {
        projSlot.innerHTML = '';
        var panel = el('section', { class: 'kop-vol-panel kop-vol-finished' }, [
            el('h3', { text: 'You got ' + round.right + ' of ' + round.total + ' the same as the admins.' }),
            el('p', { text: round.passed ? 'You passed. You can pick your own batches from ' + round.label + ' now.'
                : 'Read the question and the examples again, then try another round: it has different items.' })
        ]);
        var msg = el('p', { class: 'kop-vol-msg', role: 'status' });
        var btn = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: round.passed ? 'Pick a batch' : 'Try another round', onclick: function () {
            btn.disabled = true;
            (round.passed ? Promise.resolve() : api('practice', { do: 'start', source: round.source })).then(function () {
                return api('projects');
            }).then(function (data) {
                state.projects = data.projects || [];
                state.self = data.self;
                if (round.passed) {
                    state.practice = false;
                    renderProjects();
                    if (!projectsPanel.parentNode) app.insertBefore(projectsPanel, app.firstChild);
                    body.innerHTML = '';
                    bringToTop(projectsPanel);
                } else openPractice();
            }).catch(function (e) {
                btn.disabled = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
            });
        } });
        panel.appendChild(btn);
        panel.appendChild(msg);
        projSlot.appendChild(panel);
        bringToTop(projSlot);
    }

    // Volunteers whose calls an admin has checked and agreed with can pick their next batch.
    function selfAssignForm(self) {
        var pick = el('select', { id: 'kop-vol-self-queue' }, self.queues.map(function (q) {
            return el('option', { value: q.key, text: q.label + (q.count ? ' (' + q.count + ' waiting)' : '') });
        }));
        var msg = el('p', { class: 'kop-vol-msg', role: 'status' });
        var go = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Give me ' + self.size, disabled: cfg.preview ? true : null, onclick: function () {
            go.disabled = true;
            msg.className = 'kop-vol-msg';
            msg.textContent = 'Setting it up…';
            api('selfassign', { source: pick.value }).then(function (res) {
                return api('projects').then(function (data) {
                    state.projects = data.projects || [];
                    state.self = data.self;
                    openProject(res.id);
                });
            }).catch(function (e) {
                go.disabled = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
            });
        } });
        return el('div', { class: 'kop-vol-self' }, [
            el('h3', { text: 'Pick your next batch' }),
            el('p', { class: 'kop-vol-meta', text: self.via === 'practice' ? 'You passed the practice round, so you can choose what to review next.'
                : 'Your calls have matched the admins’ ' + self.agreed + ' of ' + self.decided + ' times, so you can choose what to review next.' }),
            el('label', { for: 'kop-vol-self-queue', text: 'Queue ' }),
            pick, ' ', go, msg
        ]);
    }

    function rememberProject(id) {
        try {
            var u = new URL(window.location.href);
            u.searchParams.delete('q');
            if (id) u.searchParams.set('project', id); else u.searchParams.delete('project');
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* not remembered */ }
    }

    var projBrief;
    var projSlot;
    var projSaved;

    function openProject(id) {
        state.project = id;
        state.practice = false;
        state.skip = [];
        state.mine = false;
        state.queue = null;
        renderTabs();
        renderProjects();
        rememberProject(id);
        body.innerHTML = '';
        projBrief = el('section', { class: 'kop-vol-panel kop-vol-guide kop-vol-brief' }, [el('p', { role: 'status', text: 'Loading…' })]);
        projSlot = el('div', { class: 'kop-vol-list' });
        projSaved = el('ul', { class: 'kop-vol-saved', 'aria-live': 'polite' });
        body.appendChild(projBrief);
        body.appendChild(projSlot);
        body.appendChild(projSaved);
        nextProjectItem();
    }

    function drawBrief(p) {
        projBrief.innerHTML = '';
        projBrief.appendChild(el('h2', { text: p.title }));
        projBrief.appendChild(progressBar(p.done, p.total));
        if (p.note) projBrief.appendChild(el('p', { class: 'kop-vol-note', text: p.note }));
        var research = p.kind === 'research';
        var social = p.kind === 'social';
        projBrief.appendChild(el('p', { class: 'kop-vol-question' }, [el('strong', { text: social ? 'What to do: ' : 'The question: ' }), p.question]));
        if (p.yes || p.no) {
            var dl = el('dl', { class: 'kop-vol-examples' });
            if (p.yes) { dl.appendChild(el('dt', { text: research ? 'A good answer looks like' : social ? 'A good post' : 'A yes looks like' })); dl.appendChild(el('dd', { text: p.yes })); }
            if (p.no) { dl.appendChild(el('dt', { text: research ? 'Not enough' : social ? 'Keep out of it' : 'A no looks like' })); dl.appendChild(el('dd', { text: p.no })); }
            projBrief.appendChild(dl);
        }
        if (p.faq && p.faq.length) {
            var faq = el('dl', { class: 'kop-vol-examples' });
            p.faq.forEach(function (f) { faq.appendChild(el('dt', { text: f.q })); faq.appendChild(el('dd', { text: f.a })); });
            projBrief.appendChild(el('details', { class: 'kop-vol-faq' }, [el('summary', { text: 'Questions others asked (' + p.faq.length + ')' }), faq]));
        }
        projBrief.appendChild(el('p', { class: 'kop-vol-meta', text: research
            ? 'Could not find it? Say where you looked: that helps too. An admin reads every answer before anything changes.'
            : p.kind === 'social' ? 'Use only what the card says. Nothing is posted until an admin approves it; one sent back says what to change.'
            : 'Not sure? Say so and why: an admin checks every answer before anything changes.' }));
        projBrief.appendChild(askButton({ project_id: p.id, label: 'this project' }, 'Ask a question about this project'));
        state.projects.forEach(function (x) { if (x.id === p.id) { x.done = p.done; x.total = p.total; } });
    }

    function nextProjectItem() {
        var id = state.project;
        // Hold the slot's height while the next item loads, so the page does not collapse and snap the reader's place.
        projSlot.style.minHeight = projSlot.offsetHeight ? projSlot.offsetHeight + 'px' : '';
        projSlot.innerHTML = '';
        projSlot.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { role: 'status', text: 'Loading…' })]));
        api('project', { id: id, limit: 1, skip: state.skip }).then(function (data) {
            if (id !== state.project) return;
            var p = data.project || {};
            drawBrief(p);
            renderProjects();
            projSlot.innerHTML = '';
            projSlot.style.minHeight = '';
            var it = (data.items || [])[0];
            if (!it) {
                var skipped = state.skip.length;
                projSlot.appendChild(el('section', { class: 'kop-vol-panel kop-vol-finished' }, [
                    el('h3', { text: skipped ? 'That is all but the ones you skipped.' : 'Done. Thank you!' }),
                    el('p', { text: skipped ? 'You skipped ' + skipped + '. Go through them again, or leave them: that is fine too.'
                        : 'Every item in this project has an answer. An admin will check them; "Your past answers" shows what they decide.' }),
                    skipped ? el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Go through the skipped ones', onclick: function () { state.skip = []; nextProjectItem(); } }) : null
                ]));
                bringToTop(projSlot);
                return;
            }
            projSlot.appendChild(el('p', { class: 'kop-vol-position', text: (p.kind === 'research' ? 'Question ' : p.kind === 'social' ? 'Idea ' : 'Item ') + Math.min(p.total, p.done + 1) + ' of ' + p.total }));
            if (p.kind === 'social') {
                projSlot.appendChild(socialCard(it, p, function (item, doing) {
                    var li = el('li', null, [doing === 'pass' ? 'Passed on: ' : 'Sent for review: ', el('strong', { text: item.fact || item.title || '(no title)' }), '. ']);
                    if (doing === 'ready') {
                        li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                            var b = this;
                            b.disabled = true;
                            api('post', { project_id: item.project_id, key: item.key, do: 'back' }).then(function () {
                                li.remove();
                                nextProjectItem();
                            }).catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
                        } }));
                    }
                    projSaved.insertBefore(li, projSaved.firstChild);
                    while (projSaved.childNodes.length > 5) projSaved.removeChild(projSaved.lastChild);
                    nextProjectItem();
                }));
                bringToTop(projSlot);
                return;
            }
            if (p.kind === 'research') {
                projSlot.appendChild(researchCard(it, p, function (item, ans) {
                    var sent = (ans.links || []).map(function (l) { return SENT[l.sent] || ''; }).filter(function (w, i, all) { return w && all.indexOf(w) === i; });
                    var li = el('li', null, ['Saved: ', el('strong', { text: item.title || '(no title)' }), ans.found ? '' : ', could not find it',
                        (ans.links || []).length ? ', ' + ans.links.length + ((ans.links.length === 1) ? ' link' : ' links') + (sent.length ? ' (' + sent.join(', ') + ')' : '') : '', '. ']);
                    li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                        var b = this;
                        b.disabled = true;
                        api('answer', { project_id: item.project_id, key: item.key, withdraw: 1 }).then(function () {
                            li.remove();
                            nextProjectItem();
                        }).catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
                    } }));
                    projSaved.insertBefore(li, projSaved.firstChild);
                    while (projSaved.childNodes.length > 5) projSaved.removeChild(projSaved.lastChild);
                    nextProjectItem();
                }));
                bringToTop(projSlot);
                return;
            }
            projSlot.appendChild(card(it, function (item, verdict, note) {
                var li = el('li', null, ['Saved: ', el('strong', { text: item.title || '(no title)' }), ', you said ', el('em', { text: SAID[verdict] }), note ? ' (' + note + ')' : '', '. ']);
                li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                    var b = this;
                    b.disabled = true;
                    api('recommend', { source: item.source, key: item.key, withdraw: 1 }).then(function () {
                        li.remove();
                        nextProjectItem();
                    }).catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
                } }));
                projSaved.insertBefore(li, projSaved.firstChild);
                while (projSaved.childNodes.length > 5) projSaved.removeChild(projSaved.lastChild);
                nextProjectItem();
            }));
            bringToTop(projSlot);
        }).catch(function (e) {
            projSlot.innerHTML = '';
            projSlot.style.minHeight = '';
            projSlot.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: e.message })]));
        });
    }

    /* ---- Questions? Ask us ---------------------------------------------------------------- */

    var msgPanel = el('section', { class: 'kop-vol-panel kop-vol-messages', id: 'kop-vol-messages', 'aria-labelledby': 'kop-vol-messages-h' });
    var msgList = el('ol', { class: 'kop-vol-thread', 'aria-live': 'polite' });
    var msgCtx = el('p', { class: 'kop-vol-msg-about', hidden: true });
    var msgBox = el('textarea', { id: 'kop-vol-msg-body', rows: '3', maxlength: '2000', placeholder: 'Your question' });
    var msgStatus = el('p', { class: 'kop-vol-msg', role: 'status', hidden: true });
    var msgSend = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-approve', text: 'Send', disabled: cfg.preview ? true : null, onclick: sendMessage });
    var newLine = el('p', { class: 'kop-vol-panel kop-vol-newreply', hidden: true }, [
        el('strong', { text: 'You have a reply. ' }),
        el('a', { href: '#kop-vol-messages', text: 'Read it', onclick: function (e) { e.preventDefault(); msgPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }); markSeen(); } })
    ]);
    var msgs = [];
    var unreadShown = false;

    function buildMessages() {
        msgPanel.appendChild(el('h2', { id: 'kop-vol-messages-h', text: 'Questions? Ask us' }));
        msgPanel.appendChild(el('p', { text: cfg.preview
            ? 'Admin preview: volunteers write to you here, and you answer at KOP Tools > Volunteer Reviewers > Messages.'
            : 'Ask about a project, one item, or anything else. We answer here: open this page again later to see the reply.' }));
        msgPanel.appendChild(msgList);
        msgPanel.appendChild(el('div', { class: 'kop-vol-decide' }, [
            msgCtx, el('label', { for: 'kop-vol-msg-body', text: 'Your message' }), msgBox,
            el('div', { class: 'kop-vol-buttons' }, [msgSend]), msgStatus
        ]));
    }

    function drawContext() {
        msgCtx.innerHTML = '';
        msgCtx.hidden = !state.msgCtx;
        if (!state.msgCtx) return;
        msgCtx.appendChild(document.createTextNode('About ' + state.msgCtx.label + ' '));
        msgCtx.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Not about that', onclick: function () {
            state.msgCtx = null;
            drawContext();
        } }));
    }

    // "Ask a question" on a brief or a card: the message names what it is about.
    function askButton(ctx, text) {
        return el('p', { class: 'kop-vol-ask' }, [el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: text || 'Ask a question about this one', onclick: function () {
            state.msgCtx = ctx;
            drawContext();
            msgPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try { msgBox.focus({ preventScroll: true }); } catch (e) { msgBox.focus(); }
        } })]);
    }

    function drawMessages() {
        msgList.innerHTML = '';
        if (!msgs.length) {
            msgList.appendChild(el('li', { class: 'kop-vol-meta', text: 'No messages yet.' }));
        }
        msgs.forEach(function (m) {
            var about = [m.project, m.item].filter(Boolean).join(' · ');
            msgList.appendChild(el('li', { class: 'kop-vol-msg-row ' + (m.from === 'vol' ? 'is-mine' : 'is-theirs') }, [
                el('p', { class: 'kop-vol-meta' }, [el('strong', { text: m.author }), ' · ' + fmtDate(m.at), m.unread ? el('span', { class: 'kop-vol-new', text: 'New' }) : null]),
                about ? el('p', { class: 'kop-vol-meta', text: 'About ' + about }) : null,
                el('p', { class: 'kop-vol-msg-body', text: m.body })
            ]));
        });
        var unread = msgs.filter(function (m) { return m.unread; }).length;
        newLine.hidden = !unread;
        // Replies the server still counts unread (new since the last look) are watched for again.
        if (unread) { unreadShown = false; watching = false; watchSeen(); }
    }

    function loadMessages(read) {
        return api('messages', { read: read ? 1 : 0 }).then(function (data) {
            msgs = data.messages || [];
            drawMessages();
        }).catch(function () { /* the panel keeps what it had */ });
    }

    // Replies count as read once the panel has been on screen.
    function markSeen() {
        if (unreadShown) return;
        unreadShown = true;
        api('messages', { read: 1 }).then(function () { newLine.hidden = true; }).catch(function () { unreadShown = false; watching = false; });
    }

    var watching = false;

    function watchSeen() {
        if (watching) return;
        watching = true;
        if (!('IntersectionObserver' in window)) { markSeen(); return; }
        var io = new IntersectionObserver(function (entries) {
            if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); markSeen(); }
        });
        io.observe(msgList);
    }

    function sendMessage() {
        var body = msgBox.value.trim();
        if (!body) { msgBox.focus(); return; }
        msgSend.disabled = true;
        msgStatus.hidden = true;
        var c = state.msgCtx || {};
        api('message', { body: body, project_id: c.project_id || 0, item_key: c.item_key || '', item_title: c.item_title || '' }).then(function (res) {
            msgs = res.messages || msgs;
            drawMessages();
            msgBox.value = '';
            state.msgCtx = null;
            drawContext();
            msgStatus.hidden = false;
            msgStatus.className = 'kop-vol-msg';
            msgStatus.textContent = res.message || 'Sent.';
        }).catch(function (e) {
            msgStatus.hidden = false;
            msgStatus.className = 'kop-vol-msg is-error';
            msgStatus.textContent = e.message;
        }).then(function () { msgSend.disabled = !!cfg.preview; });
    }

    /* ---- Start -------------------------------------------------------------------- */

    function param(name) {
        try { return new URL(window.location.href).searchParams.get(name); } catch (e) { return null; }
    }

    Promise.all([
        api('queues').catch(function () { return { queues: [] }; }),
        api('projects').catch(function () { return { projects: [] }; })
    ]).then(function (res) {
        state.queues = res[0].queues || [];
        state.projects = res[1].projects || [];
        state.self = res[1].self || null;
        app.innerHTML = '';
        buildMessages();
        app.appendChild(newLine);
        // The conversation is always last on the page, under whatever they are working on.
        var startMessages = function () {
            app.appendChild(msgPanel);
            loadMessages(false);
            // A reply may come while the page is open: look again every two minutes while it is in view.
            window.setInterval(function () { if (!document.hidden) loadMessages(false); }, 120000);
        };
        if (!state.queues.length && !state.projects.length) {
            app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: 'There is nothing to review right now. Thank you for checking!' })]));
            startMessages();
            return;
        }
        var canSelf = state.self && state.self.eligible && !state.self.open && state.self.queues.length;
        if (state.projects.length || canSelf || canPractice()) {
            renderProjects();
            app.appendChild(projectsPanel);
        }
        // With a project, the open queues are a fold: the project is the thing to do.
        app.appendChild(state.projects.length
            ? el('details', { class: 'kop-vol-panel kop-vol-browse' }, [el('summary', { text: state.queues.length ? 'Your past answers, or a whole queue' : 'Your past answers' }), tabs])
            : el('section', { class: 'kop-vol-panel kop-vol-tabs-panel' }, [tabs]));
        app.appendChild(body);
        startMessages();
        renderTabs();
        var wantedProject = parseInt(param('project'), 10);
        var wanted = param('q');
        var unfinished = state.projects.filter(function (p) { return p.done < p.total; });
        if (wantedProject && state.projects.some(function (p) { return p.id === wantedProject; })) openProject(wantedProject);
        else if (wanted === '_mine') openMine();
        else if (state.queues.some(function (q) { return q.key === wanted; })) openQueue(wanted);
        else if (wantedProject) {
            var handedBack = (res[1].set_aside || []).some(function (p) { return p.id === wantedProject; });
            body.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: (handedBack ? res[1].set_aside_words : 'That project is finished or closed. Thank you!')
                + (state.projects.length ? ' Your other projects are above.' : '') })]));
        } else if (state.projects.length) {
            // Straight to work: their own projects come first.
            if (unfinished.length) openProject(unfinished[0].id);
        } else if (canPractice() && state.self.practice.open) openPractice();
        else openQueue((state.queues.filter(function (q) { return q.count; })[0] || state.queues[0]).key);
    }).catch(function (e) {
        app.innerHTML = '';
        app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: e.message })]));
    });
})();
