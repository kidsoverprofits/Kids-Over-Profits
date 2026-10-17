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
 * Every request is a POST to kop/v1/volunteer/* with the X-KOP-Vol header
 * the page was given (window.kopVolunteer.page).
 */
(function () {
    'use strict';

    var cfg = window.kopVolunteer || {};
    var app = document.getElementById('kop-vol-app');
    if (!cfg.rest || !app) return;

    var state = { queues: [], queue: null, offset: 0, mine: false, projects: [], project: null, skip: [] };

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
        }, ['Your recommendations']));
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
        var note = el('textarea', { id: id, rows: '2', maxlength: '1000', placeholder: 'Why? (optional for approve or reject, needed for not sure)' });
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
            api('recommend', { source: it.source, key: it.key, verdict: verdict, note: note.value }).then(function (res) {
                if (onDone) onDone(it, verdict, note.value);
                else done(node, it, verdict, note.value, res.message);
            }).catch(function (e) {
                buttons.forEach(function (b) { b.disabled = false; });
                msg.hidden = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
                btn.focus();
            });
        }
        if (onDone) {
            buttons.push(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Skip for now', onclick: function () {
                state.skip.push(it.key);
                nextProjectItem();
            } }));
        }
        node.appendChild(el('div', { class: 'kop-vol-decide' }, [
            el('label', { for: id, text: 'Your note' }), note,
            el('div', { class: 'kop-vol-buttons' }, buttons), msg
        ]));
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
        state.mine = true;
        renderTabs();
        remember();
        body.innerHTML = '';
        var panel = el('section', { class: 'kop-vol-panel' }, [
            el('h2', { text: 'Your recommendations' }),
            el('p', { text: 'Everything you recommended and what the admin decided. You can take back a recommendation until an admin acts on it.' })
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
        projectsPanel.appendChild(el('h2', { text: state.projects.length === 1 ? 'Your project' : 'Your projects' }));
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
        projectsPanel.appendChild(ul);
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
        projBrief.appendChild(el('p', { class: 'kop-vol-question' }, [el('strong', { text: 'The question: ' }), p.question]));
        if (p.yes || p.no) {
            var dl = el('dl', { class: 'kop-vol-examples' });
            if (p.yes) { dl.appendChild(el('dt', { text: 'A yes looks like' })); dl.appendChild(el('dd', { text: p.yes })); }
            if (p.no) { dl.appendChild(el('dt', { text: 'A no looks like' })); dl.appendChild(el('dd', { text: p.no })); }
            projBrief.appendChild(dl);
        }
        projBrief.appendChild(el('p', { class: 'kop-vol-meta', text: 'Not sure? Say so and why: an admin checks every answer before anything changes.' }));
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
                        : 'Every item in this project has an answer. An admin will check them; "Your recommendations" shows what they decide.' }),
                    skipped ? el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Go through the skipped ones', onclick: function () { state.skip = []; nextProjectItem(); } }) : null
                ]));
                bringToTop(projSlot);
                return;
            }
            projSlot.appendChild(el('p', { class: 'kop-vol-position', text: 'Item ' + Math.min(p.total, p.done + 1) + ' of ' + p.total }));
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
        app.innerHTML = '';
        if (!state.queues.length && !state.projects.length) {
            app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: 'There is nothing to review right now. Thank you for checking!' })]));
            return;
        }
        if (state.projects.length) {
            renderProjects();
            app.appendChild(projectsPanel);
        }
        // With a project, the open queues are a fold: the project is the thing to do.
        app.appendChild(state.projects.length
            ? el('details', { class: 'kop-vol-panel kop-vol-browse' }, [el('summary', { text: state.queues.length ? 'Your past answers, or a whole queue' : 'Your past answers' }), tabs])
            : el('section', { class: 'kop-vol-panel kop-vol-tabs-panel' }, [tabs]));
        app.appendChild(body);
        renderTabs();
        var wantedProject = parseInt(param('project'), 10);
        var wanted = param('q');
        var unfinished = state.projects.filter(function (p) { return p.done < p.total; });
        if (wantedProject && state.projects.some(function (p) { return p.id === wantedProject; })) openProject(wantedProject);
        else if (wanted === '_mine') openMine();
        else if (state.queues.some(function (q) { return q.key === wanted; })) openQueue(wanted);
        else if (wantedProject) {
            body.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: 'That project is finished or closed. Thank you!' + (state.projects.length ? ' Your other projects are above.' : '') })]));
        } else if (state.projects.length) {
            // Straight to work: their own projects come first.
            if (unfinished.length) openProject(unfinished[0].id);
        } else openQueue((state.queues.filter(function (q) { return q.count; })[0] || state.queues[0]).key);
    }).catch(function (e) {
        app.innerHTML = '';
        app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: e.message })]));
    });
})();
