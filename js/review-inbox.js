/**
 * The review inbox on the Submissions Review page (inc/review-inbox.php).
 *
 * Adds a tab for every other queue (closure reports, Drive Docs, Fornits,
 * Woodbury, merges, ...) under the page's own five, and draws one kind of
 * card for all of them: change the category and tags right on the card,
 * "Edit details" for the rest of the fields with "Fill empty fields with AI",
 * "Move to" another queue where the source allows it, and the queue's own
 * buttons. After a button the card shows what happened and, where the queue
 * has one, its Undo.
 *
 * Every card says what Approve and Reject do (the action's 'help'), puts
 * them first, and has "Preview" beside each link: the page itself in a frame
 * where the site allows it, else a reading copy (review-inbox/preview).
 * "Later" snoozes an item or hands it to another admin (review-inbox/hold);
 * "Your inbox" lists what is assigned to you, what is snoozed, and Recently
 * done: everything anyone did here, each with its queue's Undo.
 *
 * window.kopReviewInbox.api(path, body) is used by js/admin-submissions.js
 * for the same tags / AI / move controls on its own five types.
 */
(function () {
    'use strict';

    var cfg = window.kopReviewInbox || {};
    if (!cfg.rest) return;

    var page = document.querySelector('.admin-submissions-page');
    if (!page) return;

    var state = { sources: [], byKey: {}, tags: [], admins: [], me: 0, held: { mine: 0, snoozed: 0 }, special: null,
        source: null, view: '', search: '', origin: '', filters: {}, offset: 0, pages: [], nextOffset: 0, limit: 25, total: 0 };

    function api(path, body, query) {
        var url = cfg.rest + path;
        if (query) {
            var parts = [];
            Object.keys(query).forEach(function (k) {
                if (query[k] !== undefined && query[k] !== null && query[k] !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(query[k]));
            });
            if (parts.length) url += (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
        }
        var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) throw new Error(data && (data.error || data.message) || ('Request failed (' + res.status + ')'));
                return data;
            });
        });
    }
    cfg.api = api;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'text') node.textContent = v;
                else if (k === 'class') node.className = v;
                else if (k.indexOf('on') === 0 && typeof v === 'function') node.addEventListener(k.slice(2), v);
                else node.setAttribute(k, v === true ? '' : v);
            });
        }
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    /** Options for a "Came from" select: Everywhere, then each origin with its count. */
    function fillOrigins(select, origins, keep) {
        select.innerHTML = '';
        select.appendChild(el('option', { value: '', text: 'Everywhere' }));
        var found = false;
        origins.forEach(function (o) {
            var opt = el('option', { value: o.key, text: o.label + ' (' + o.count + ')' });
            if (o.key === keep) { opt.selected = true; found = true; }
            select.appendChild(opt);
        });
        if (keep && !found) {
            // Still offer the current choice when nothing of it is left in this view.
            var opt = el('option', { value: keep, text: keep + ' (0)' });
            opt.selected = true;
            select.appendChild(opt);
        }
        select.disabled = origins.length === 0 && !keep;
    }

    function linkLabel(url) {
        return typeof window.kopUrlLabel === 'function' ? window.kopUrlLabel(url) : url;
    }

    function safeHref(url) {
        return /^https?:\/\//i.test(String(url || '')) ? url : null;
    }

    /* ---- Tabs -------------------------------------------------------------- */

    var tabsRow = el('nav', { class: 'rinbox-tabs rinbox-dd-row', 'aria-label': 'Review queues' });
    var panel = el('section', { class: 'rinbox-panel', hidden: true, 'aria-live': 'polite' });
    var controls = page.querySelector('.admin-controls');
    var typeTabs = page.querySelector('.type-tabs');
    if (typeTabs && typeTabs.parentNode) typeTabs.parentNode.insertBefore(tabsRow, typeTabs.nextSibling);
    var listContainer = page.querySelector('.submissions-list-container');
    if (listContainer) listContainer.parentNode.insertBefore(panel, listContainer);

    var SPECIAL = {
        _mine: { label: 'Assigned to you', count: function () { return state.held.mine; } },
        _research: { label: 'Needs research', count: function () { return state.held.research || 0; } },
        _snoozed: { label: 'Snoozed', count: function () { return state.held.snoozed; } },
        _volunteers: { label: 'Volunteers recommend', count: function () { return state.held.volunteers || 0; } },
        _done: { label: 'Recently done', count: function () { return 0; } }
    };

    /*
     * The queues as one dropdown per group ("Your inbox", "Submissions",
     * "Suggestions to check", ...): the button shows the group's waiting total
     * (and the open queue's name), its menu every queue with its count. The
     * page's own five type tabs are hidden; their queues sit in the Submissions
     * menu and open by clicking the hidden tab, so js/admin-submissions.js
     * still draws them.
     */
    var openMenu = null;

    function closeMenu() {
        if (!openMenu) return;
        openMenu.classList.remove('is-open');
        openMenu.querySelector('.rinbox-dd-btn').setAttribute('aria-expanded', 'false');
        openMenu = null;
    }
    document.addEventListener('click', function (e) {
        if (openMenu && !openMenu.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && openMenu) {
            var btn = openMenu.querySelector('.rinbox-dd-btn');
            closeMenu();
            btn.focus();
        }
    });

    /** A native queue's count: the page's own tab badge once it has one, else the saved count. */
    function nativeCount(s) {
        var badge = typeTabs && typeTabs.querySelector('[data-count-for="' + s.key + '"]');
        if (badge && badge.textContent.trim() !== '') return parseInt(badge.textContent, 10) || 0;
        return s.count;
    }

    function fmtCount(n) {
        return n ? Number(n).toLocaleString() : '';
    }

    /** [{key, label, count, active, open}] in a dropdown under the group's name. */
    function dropdown(name, entries, extraClass) {
        var total = 0, current = null, unknown = false;
        entries.forEach(function (e) {
            if (e.count === null || e.count === undefined) unknown = true;
            total += e.count || 0;
            if (e.active) current = e;
        });
        var wrap = el('div', { class: 'rinbox-dd' + (current ? ' is-current' : '') + (extraClass ? ' ' + extraClass : '') });
        var menu = el('div', { class: 'rinbox-dd-menu', role: 'menu' });
        entries.forEach(function (e) {
            var known = e.count !== null && e.count !== undefined;
            menu.appendChild(el('button', {
                type: 'button', role: 'menuitem', class: 'rinbox-dd-item' + (e.active ? ' is-active' : '') + (e.count ? '' : ' is-empty'),
                'aria-current': e.active ? 'true' : null, 'data-source': e.key,
                onclick: function () { closeMenu(); e.open(); }
            }, [
                el('span', { class: 'rinbox-dd-label', text: e.label }),
                el('span', { class: 'tab-count' + (e.count ? ' has-items' : ''), text: known ? fmtCount(e.count) : '…', title: known ? null : 'Counting' })
            ]));
        });
        var btn = el('button', {
            type: 'button', class: 'rinbox-dd-btn', 'aria-haspopup': 'true', 'aria-expanded': 'false',
            onclick: function (ev) {
                ev.stopPropagation();
                var was = openMenu === wrap;
                closeMenu();
                if (was) return;
                wrap.classList.add('is-open');
                btn.setAttribute('aria-expanded', 'true');
                openMenu = wrap;
                var first = menu.querySelector('.is-active') || menu.querySelector('.rinbox-dd-item');
                if (first) first.focus();
            }
        }, [
            el('span', { class: 'rinbox-dd-name', text: name }),
            current ? el('span', { class: 'rinbox-dd-current', text: current.label }) : null,
            el('span', { class: 'tab-count' + (total ? ' has-items' : ''), text: total ? fmtCount(total) + (unknown ? '+' : '') : '' }),
            el('span', { class: 'rinbox-dd-caret', 'aria-hidden': 'true' })
        ]);
        // Arrow keys move through the open menu.
        menu.addEventListener('keydown', function (ev) {
            if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
            ev.preventDefault();
            var items = Array.prototype.slice.call(menu.querySelectorAll('.rinbox-dd-item'));
            var i = items.indexOf(document.activeElement) + (ev.key === 'ArrowDown' ? 1 : -1);
            if (items.length) items[(i + items.length) % items.length].focus();
        });
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    }

    function renderTabs() {
        var wasOpen = openMenu ? openMenu.querySelector('.rinbox-dd-name').textContent : null;
        var focusKey = document.activeElement && tabsRow.contains(document.activeElement) ? document.activeElement.getAttribute('data-source') : null;
        openMenu = null;
        tabsRow.innerHTML = '';
        page.classList.add('rinbox-dd-ready');
        var nativeActive = !state.source && !state.special && typeInput ? typeInput.value : null;

        tabsRow.appendChild(dropdown('Your inbox', Object.keys(SPECIAL).map(function (k) {
            return { key: k, label: SPECIAL[k].label, count: SPECIAL[k].count(), active: state.special === k, open: function () { openSpecial(k); } };
        }), 'rinbox-dd-yours'));

        var groups = {};
        var order = [];
        state.sources.forEach(function (s) {
            if (!groups[s.group]) { groups[s.group] = []; order.push(s.group); }
            groups[s.group].push(s);
        });
        // Groups and the queues in each A to Z, so a queue is found by its name ("Your inbox" first, "All tools" last).
        var byLabel = function (a, b) { return String(a).localeCompare(String(b), undefined, { sensitivity: 'base', numeric: true }); };
        order.sort(byLabel);
        order.forEach(function (g) {
            groups[g].sort(function (a, b) { return byLabel(a.label, b.label); });
            tabsRow.appendChild(dropdown(g, groups[g].map(function (s) {
                if (s.native) {
                    return { key: s.key, label: s.label, count: nativeCount(s), active: nativeActive === s.key, open: function () {
                        var tab = typeTabs && typeTabs.querySelector('[data-type="' + s.key + '"]');
                        if (tab) tab.click();
                        leaveInbox();
                    } };
                }
                return { key: s.key, label: s.label, count: s.count, active: state.source === s.key, open: function () { openSource(s.key); } };
            })));
        });
        // Every KOP tool, the same list as the KOP Tools menu (kop_rinbox_all_tools()).
        tabsRow.appendChild(el('button', {
            type: 'button', class: 'rinbox-dd-btn rinbox-dd-tools' + (state.special === '_tools' ? ' is-current' : ''),
            'aria-pressed': state.special === '_tools' ? 'true' : 'false',
            onclick: function () { closeMenu(); openSpecial('_tools'); }
        }, [el('span', { class: 'rinbox-dd-name', text: 'All tools' })]));
        // A recount redraws the row: keep the menu that was open, and the item that had focus.
        if (wasOpen) {
            tabsRow.querySelectorAll('.rinbox-dd').forEach(function (d) {
                if (d.querySelector('.rinbox-dd-name').textContent !== wasOpen) return;
                d.classList.add('is-open');
                d.querySelector('.rinbox-dd-btn').setAttribute('aria-expanded', 'true');
                openMenu = d;
                var f = focusKey && d.querySelector('.rinbox-dd-item[data-source="' + focusKey + '"]');
                if (f) f.focus();
            });
        }
    }

    // The page's own type tabs fill their counts later (js/admin-submissions.js loadTabCounts()): redraw the menus then.
    if (typeTabs && window.MutationObserver) {
        var badgeTimer = null;
        new MutationObserver(function () {
            if (!state.sources.length) return;
            clearTimeout(badgeTimer);
            badgeTimer = setTimeout(renderTabs, 150);
        }).observe(typeTabs, { subtree: true, childList: true, characterData: true });
    }

    function loadSources(fresh) {
        return api('sources', null, fresh ? { fresh: 1 } : null).then(function (data) {
            state.sources = data.sources || [];
            state.tags = data.tags || [];
            state.admins = data.admins || [];
            state.me = data.me || 0;
            state.held = data.held || { mine: 0, snoozed: 0 };
            state.byKey = {};
            state.sources.forEach(function (s) { state.byKey[s.key] = s; });
            renderTabs();
            recountQueues(data.stale || []);
        }).catch(function (e) {
            tabsRow.textContent = 'Other queues could not be loaded: ' + e.message;
        });
    }

    /**
     * Recount the queues whose saved count is old (review-inbox/counts), after
     * the page is drawn: a few queues a request, three requests at a time, the
     * slow counters (Inspection links ~10 s, Duplicate facilities ~4 s) each on
     * their own so the quick ones land first.
     */
    var SLOW_COUNTS = ['inspection-links', 'facility-merge', 'wiki-links', 'news', 'fornits', 'record-cleanup'];
    function recountQueues(keys) {
        if (!keys.length) return;
        var slow = keys.filter(function (k) { return SLOW_COUNTS.indexOf(k) !== -1; });
        var quick = keys.filter(function (k) { return SLOW_COUNTS.indexOf(k) === -1; });
        var batches = [];
        for (var i = 0; i < quick.length; i += 8) batches.push(quick.slice(i, i + 8));
        slow.forEach(function (k) { batches.push([k]); });
        var running = 0;
        function next() {
            while (running < 3 && batches.length) {
                var batch = batches.shift();
                running++;
                api('counts', null, { sources: batch.join(',') }).then(function (data) {
                    var counts = data.counts || {};
                    Object.keys(counts).forEach(function (k) { if (state.byKey[k]) state.byKey[k].count = counts[k]; });
                    renderTabs();
                }).catch(function () { /* the old number stays */ }).then(function () {
                    running--;
                    next();
                });
            }
        }
        next();
    }

    function leaveInbox() {
        state.source = null;
        state.special = null;
        page.classList.remove('rinbox-active');
        panel.hidden = true;
        renderTabs();
    }

    // The page's own type tabs bring its own list back.
    if (typeTabs) {
        typeTabs.addEventListener('click', function (e) {
            if (e.target.closest('[data-type]')) leaveInbox();
        });
    }

    function setUrl() {
        try {
            var u = new URL(window.location.href);
            var t = state.special || state.source;
            if (t) u.searchParams.set('type', t); else u.searchParams.delete('type');
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* old browser: the tab just is not remembered */ }
    }

    function openSource(key) {
        var s = state.byKey[key];
        if (!s || s.native) return;
        state.source = key;
        state.special = null;
        state.view = Object.keys(s.views)[0];
        state.search = '';
        state.origin = '';
        state.filters = {};
        state.offset = 0;
        state.pages = [];
        page.classList.add('rinbox-active');
        page.querySelectorAll('.type-tabs .submission-tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        panel.hidden = false;
        renderTabs();
        setUrl();
        renderPanel();
        loadItems();
    }

    /* ---- Panel ------------------------------------------------------------- */

    var listEl, pagerEl, statusEl, bulkEl, viewButtons = {};

    function renderPanel() {
        var s = state.byKey[state.source];
        panel.innerHTML = '';
        var head = el('div', { class: 'rinbox-head' }, [
            el('h2', { text: s.label }),
            s.help ? el('p', { class: 'rinbox-help', text: s.help }) : null,
            s.tool_url ? el('a', { class: 'rinbox-tool-link', href: s.tool_url, text: 'Open the full ' + s.label + ' screen' }) : null
        ]);
        var views = el('div', { class: 'submission-tabs rinbox-views', role: 'tablist' });
        viewButtons = {};
        Object.keys(s.views).forEach(function (v) {
            views.appendChild(viewButtons[v] = el('button', {
                type: 'button', role: 'tab', class: 'submission-tab' + (state.view === v ? ' is-active' : ''),
                'aria-selected': state.view === v ? 'true' : 'false',
                text: s.views[v],
                onclick: function () { state.view = v; state.offset = 0; state.pages = []; renderPanel(); loadItems(); }
            }));
        });
        var origin = null;
        if (s.has_origins) {
            origin = el('select', { class: 'rinbox-origin', 'aria-label': 'Came from' });
            fillOrigins(origin, [], state.origin);
            origin.addEventListener('change', function () { state.origin = origin.value; state.offset = 0; state.pages = []; loadItems(); });
            api('origins', null, { source: state.source, view: state.view }).then(function (data) {
                fillOrigins(origin, data.origins || [], state.origin);
            }).catch(function () { /* no filter */ });
        }
        // The queue's own dropdowns (kind, category, importance...).
        var filters = (s.filters || []).map(function (f) {
            var sel = el('select', { class: 'rinbox-filter', 'aria-label': f.label }, [el('option', { value: '', text: f.label + ': any' })]);
            Object.keys(f.options || {}).forEach(function (k) {
                sel.appendChild(el('option', { value: k, text: f.options[k], selected: state.filters[f.name] === k }));
            });
            sel.addEventListener('change', function () { state.filters[f.name] = sel.value; state.offset = 0; state.pages = []; loadItems(); });
            return sel;
        });
        var search = el('input', { type: 'search', class: 'rinbox-search', placeholder: 'Search this queue…', value: state.search, 'aria-label': 'Search this queue' });
        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { state.search = search.value.trim(); state.offset = 0; state.pages = []; loadItems(); }, 350);
        });
        var tools = null;
        if (s.tools && s.tools.length) {
            tools = el('div', { class: 'rinbox-tools' });
            s.tools.forEach(function (t) {
                tools.appendChild(paramButton(t, 'rinbox-tool-', function (params, btn) {
                    btn.disabled = true;
                    statusEl.textContent = 'Working…';
                    api('tool', { source: state.source, tool: t.id, params: params }).then(function (res) {
                        btn.disabled = false;
                        loadItems(res.message);
                        refreshCounts();
                    }).catch(function (e) {
                        btn.disabled = false;
                        statusEl.textContent = e.message;
                    });
                }));
            });
        }
        statusEl = el('p', { class: 'rinbox-status', role: 'status' });
        bulkEl = el('div', { class: 'rinbox-bulk' });
        listEl = el('div', { class: 'rinbox-list' });
        pagerEl = el('div', { class: 'rinbox-pager' });
        panel.appendChild(head);
        panel.appendChild(el('div', { class: 'rinbox-toolbar' }, [views, el('span', { class: 'rinbox-filters' }, [
            origin ? el('label', { class: 'rinbox-quick-field' }, ['Came from ', origin]) : null
        ].concat(filters, [search]))]));
        if (tools) {
            panel.appendChild(tools);
            if (typeof window.kopFacilityFinderAttach === 'function') {
                tools.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        }
        panel.appendChild(statusEl);
        panel.appendChild(bulkEl);
        panel.appendChild(listEl);
        panel.appendChild(pagerEl);
    }

    function loadItems(note) {
        var src = state.source;
        statusEl.textContent = 'Loading…';
        bulkEl.innerHTML = '';
        // Keep the list's height while the page loads, so the page does not collapse and spring back.
        listEl.style.minHeight = listEl.offsetHeight + 'px';
        listEl.innerHTML = '';
        pagerEl.innerHTML = '';
        var query = { source: src, view: state.view, search: state.search, origin: state.origin, offset: state.offset, limit: state.limit };
        Object.keys(state.filters).forEach(function (k) { query['f_' + k] = state.filters[k]; });
        api('items', null, query).then(function (data) {
            if (state.source !== src) return;
            state.total = data.total || 0;
            state.nextOffset = data.next_offset || (state.offset + (data.items || []).length);
            if (data.view_counts) {
                var names = state.byKey[src].views;
                Object.keys(viewButtons).forEach(function (v) {
                    if (data.view_counts[v] !== undefined) viewButtons[v].textContent = names[v] + ' (' + data.view_counts[v] + ')';
                });
            }
            var items = data.items || [];
            statusEl.textContent = (note ? note + ' ' : '') + (state.total ? (state.total + (state.total === 1 ? ' item' : ' items')) : 'Nothing here.')
                + (data.held ? ' (' + data.held + ' set aside: needs research, snoozed or handed to someone else.)' : '');
            items.forEach(function (it) { listEl.appendChild(card(it)); });
            listEl.style.minHeight = '';
            renderBulk();
            renderPager();
        }).catch(function (e) {
            listEl.style.minHeight = '';
            statusEl.textContent = 'Could not load this queue: ' + e.message;
        });
    }

    /** "Select all" and the buttons every selected card has (those that need no answer, or have one filled in). */
    function renderBulk() {
        bulkEl.innerHTML = '';
        var cards = listEl.querySelectorAll('.rinbox-card');
        if (cards.length < 2) return;
        var all = el('input', { type: 'checkbox', 'aria-label': 'Select every item on this page' });
        var count = el('span', { class: 'rinbox-bulk-count', text: '0 selected' });
        var buttons = el('span', { class: 'rinbox-bulk-actions' });
        all.addEventListener('change', function () {
            listEl.querySelectorAll('.rinbox-select').forEach(function (c) { if (!c.disabled) c.checked = all.checked; });
            update();
        });
        listEl.onchange = function (e) { if (e.target.classList.contains('rinbox-select')) update(); };
        function selected() {
            return Array.prototype.filter.call(listEl.querySelectorAll('.rinbox-card'), function (c) {
                var box = c.querySelector('.rinbox-select');
                return box && box.checked && c.kopItem;
            });
        }
        function update() {
            var sel = selected();
            count.textContent = sel.length + ' selected';
            buttons.innerHTML = '';
            if (!sel.length) return;
            (sel[0].kopItem.actions || []).forEach(function (a) {
                var ready = function (c) {
                    var mine = (c.kopItem.actions || []).filter(function (b) { return b.id === a.id; })[0];
                    if (!mine) return false;
                    var now = paramsOf(c, mine);
                    return (mine.params || []).every(function (p) {
                        var v = now[p.name];
                        return p.optional || (v !== '' && v !== null && v !== undefined && v !== 0 && !(Array.isArray(v) && !v.length));
                    });
                };
                if (!sel.every(ready)) return;
                buttons.appendChild(el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral'), text: a.label + ' (' + sel.length + ')',
                    onclick: function () { runBulk(sel, a); }
                }));
            });
        }
        bulkEl.appendChild(el('label', { class: 'rinbox-quick-field' }, [all, ' Select all']));
        bulkEl.appendChild(count);
        bulkEl.appendChild(buttons);
        update(); // sure matches start ticked
    }

    function runBulk(cards, action) {
        if (action.confirm && !window.confirm(action.confirm + ' (' + cards.length + ' items)')) return;
        var s = state.byKey[state.source], src = state.source;
        var done = 0, failed = 0;
        statusEl.textContent = 'Working on ' + cards.length + '…';
        var chain = Promise.resolve();
        cards.forEach(function (node) {
            chain = chain.then(function () {
                var item = node.kopItem;
                var mine = (item.actions || []).filter(function (b) { return b.id === action.id; })[0] || action;
                var params = paramsOf(node, mine);
                return api('act', { source: src, key: item.key, action: action.id, params: params }).then(function (res) {
                    done++;
                    if (res.item) fillCard(node, res.item, s, src, res.message);
                    else { node.classList.add('rinbox-gone'); node.innerHTML = ''; node.appendChild(el('p', { class: 'rinbox-message', text: res.message || 'Done.' })); }
                }).catch(function (e) {
                    failed++;
                    fillCard(node, item, s, src, e.message, true);
                });
            });
        });
        chain.then(function () {
            statusEl.textContent = action.label + ': ' + done + ' done' + (failed ? ', ' + failed + ' could not be (their cards say why)' : '') + '.';
            renderBulk();
            refreshCounts();
        });
    }

    /** An action's params as the card's own inputs say now (its defaults when the card has none). */
    function paramsOf(node, action) {
        var inputs = node.kopParams && node.kopParams[action.id];
        var out = {};
        (action.params || []).forEach(function (p, i) { out[p.name] = inputs && inputs[i] ? readInput(inputs[i]) : p.value; });
        if (node.kopPicked) out.picked = node.kopPicked();
        return out;
    }

    /**
     * A card's list of links to tick (item.checklist: one card per facility).
     * Each entry is one link or several from one website folded together
     * ({keys, label, url?, sub, note?, checked, items?}). The card's actions
     * get the ticked links' keys as params.picked (paramsOf).
     */
    /** Draw a checklist card again after one of its rows changed, keeping what was ticked and which rows were open. */
    function refreshChecklistCard(node, item, src, message) {
        var picked = node.kopPicked ? node.kopPicked() : [];
        var open = [];
        node.querySelectorAll('.rinbox-check-more').forEach(function (d, i) { if (d.open) open.push(i); });
        return api('item', null, { source: src, key: item.key }).then(function (res) {
            var next = res.item;
            if (!next) { fillCard(node, item, state.byKey[src], src, message); return; }
            (next.checklist || []).forEach(function (e) {
                e.checked = (e.keys || []).some(function (k) { return picked.indexOf(k) !== -1; });
            });
            fillCard(node, next, state.byKey[src], src, message);
            node.querySelectorAll('.rinbox-check-more').forEach(function (d, i) { if (open.indexOf(i) !== -1) d.open = true; });
        });
    }

    function checklistBox(item, node, pane, prefix) {
        var boxes = [];
        var count = el('span', { class: 'rinbox-check-count' });
        function update() {
            var on = 0, all = 0;
            boxes.forEach(function (b) { all += b.kopKeys.length; if (b.checked) on += b.kopKeys.length; });
            count.textContent = on + ' of ' + all + ' ' + (item.checklist_noun || 'links') + ' ticked';
        }
        function setAll(v) { boxes.forEach(function (b) { b.checked = v; }); update(); }
        var head = el('div', { class: 'rinbox-check-head' }, [
            count,
            el('button', { type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: 'Tick all', onclick: function () { setAll(true); } }),
            el('button', { type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: 'Untick all', onclick: function () { setAll(false); } })
        ]);
        var list = el('ul', { class: 'rinbox-checklist' });
        function linkRow(e) {
            var bits = [];
            var labelEl;
            if (safeHref(e.url)) {
                labelEl = el('a', { href: e.url, target: '_blank', rel: 'noopener', text: e.label, title: e.url });
                bits.push(labelEl);
                bits.push(previewButton(e.url, pane));
            } else {
                labelEl = el('span', { text: e.label });
                bits.push(labelEl);
            }
            var rowKey = e.key || (e.keys && e.keys.length === 1 ? e.keys[0] : '');
            var src = item.source || state.source;
            var tools = item.row_tools || {};
            if (rowKey && !tools.off && (e.rename || (state.byKey[src] || {}).can_save)) {
                // Each link row has the card tools for that one link: Rename, Kind and "Name it with AI".
                // A change saves through the queue's own save/ai, then the card is drawn again with its ticks kept.
                var bar = el('div', { class: 'rinbox-row-tools' });
                var rowMsg = el('span', { class: 'rinbox-rename-msg', role: 'status' });
                var input = el('input', { type: 'text', class: 'rinbox-rename-name', 'aria-label': 'Name for this link', value: e.label || '' });
                var form = el('form', { class: 'rinbox-rename rinbox-rename-row', hidden: true });
                var btn = el('button', { type: 'button', class: 'rinbox-btn rinbox-btn-undo rinbox-rename-toggle', text: 'Rename', 'aria-expanded': 'false' });
                var busy = function (on, text) {
                    bar.querySelectorAll('button, select').forEach(function (b) { b.disabled = on; });
                    form.querySelectorAll('button, input').forEach(function (b) { b.disabled = on; });
                    rowMsg.className = 'rinbox-rename-msg';
                    rowMsg.textContent = text || '';
                };
                var change = function (kind, body, working) {
                    busy(true, working);
                    api(kind, Object.assign({ source: src, key: rowKey }, body)).then(function (res) {
                        return refreshChecklistCard(node, item, src, res.message || 'Saved.');
                    }).catch(function (err) {
                        busy(false, err.message);
                        rowMsg.className = 'rinbox-rename-msg is-error';
                    });
                };
                form.appendChild(input);
                form.appendChild(el('button', { type: 'submit', class: 'rinbox-btn rinbox-btn-neutral', text: 'Save name' }));
                form.appendChild(el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Cancel',
                    onclick: function () { form.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
                }));
                btn.addEventListener('click', function () {
                    form.hidden = !form.hidden;
                    btn.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
                    if (!form.hidden) { input.focus(); input.select(); }
                });
                form.addEventListener('submit', function (ev) {
                    ev.preventDefault();
                    var v = input.value.trim();
                    if (!v) { input.focus(); return; }
                    change('save', { fields: { label: v } }, 'Saving…');
                });
                bar.appendChild(btn);
                if (tools.kinds && e.kind) {
                    var kindSel = el('select', { class: 'rinbox-row-kind', 'aria-label': 'Kind of link' });
                    Object.keys(tools.kinds).forEach(function (k) {
                        kindSel.appendChild(el('option', { value: k, text: tools.kinds[k], selected: k === e.kind }));
                    });
                    kindSel.addEventListener('change', function () { change('save', { fields: { kind: kindSel.value } }, 'Saving…'); });
                    bar.appendChild(kindSel);
                }
                if (tools.ai && (state.byKey[src] || {}).can_ai) {
                    bar.appendChild(el('button', {
                        type: 'button', class: 'rinbox-btn rinbox-btn-neutral rinbox-ai', text: 'Name it with AI',
                        title: 'Reads the page this link points to and replaces the name with its own neutral title',
                        onclick: function () { change('ai', {}, 'Reading the link…'); }
                    }));
                }
                bar.appendChild(rowMsg);
                bits.push(bar);
                bits.push(form);
            }
            return bits;
        }
        item.checklist.forEach(function (e, i) {
            var id = prefix + 'chk-' + i;
            var box = el('input', { type: 'checkbox', id: id, checked: !!e.checked, 'aria-label': 'Tick ' + e.label });
            box.kopKeys = e.keys || [];
            box.addEventListener('change', update);
            boxes.push(box);
            var li = el('li', { class: 'rinbox-check' + (e.items ? ' rinbox-check-bundle' : '') });
            var main = el('div', { class: 'rinbox-check-main' }, [box]);
            if (e.items) {
                main.appendChild(el('label', { for: id, class: 'rinbox-check-label', text: e.label }));
            } else {
                linkRow(e).forEach(function (b) { main.appendChild(b); });
            }
            li.appendChild(main);
            // Why the line is worth a look (Rules from documents: the reader's reason), above where it comes from.
            if (e.why) li.appendChild(el('div', { class: 'rinbox-check-why', text: e.why }));
            if (e.sub) li.appendChild(el('div', { class: 'rinbox-check-sub', text: e.sub }));
            if (e.note) li.appendChild(el('div', { class: 'rinbox-check-note', text: e.note }));
            if (e.items) {
                var inner = el('ul', { class: 'rinbox-check-items' });
                e.items.forEach(function (x) {
                    var row = el('li', null, linkRow(x));
                    if (x.note) row.appendChild(el('div', { class: 'rinbox-check-note', text: x.note }));
                    inner.appendChild(row);
                });
                li.appendChild(el('details', { class: 'rinbox-check-more' }, [el('summary', { text: 'Show the ' + e.items.length + ' links' }), inner]));
            }
            list.appendChild(li);
        });
        node.kopPicked = function () {
            var keys = [];
            boxes.forEach(function (b) { if (b.checked) keys = keys.concat(b.kopKeys); });
            return keys;
        };
        update();
        return el('div', { class: 'rinbox-check-wrap' + (item.checklist_full ? ' is-full' : '') }, [head, list]);
    }

    /** Pages follow the queue's own order: set-aside items are skipped, so Next starts where the server says. */
    function renderPager() {
        pagerEl.innerHTML = '';
        if (state.total <= state.limit && !state.pages.length) return;
        var pageNo = state.pages.length + 1, pageCount = Math.max(pageNo, Math.ceil(state.total / state.limit));
        pagerEl.appendChild(el('button', {
            type: 'button', class: 'btn-secondary', text: 'Previous', disabled: !state.pages.length,
            onclick: function () { state.offset = state.pages.pop() || 0; loadItems(); scrollToPanel(); }
        }));
        pagerEl.appendChild(el('span', { text: ' Page ' + pageNo + ' of ' + pageCount + ' ' }));
        pagerEl.appendChild(el('button', {
            type: 'button', class: 'btn-secondary', text: 'Next', disabled: pageNo >= pageCount,
            onclick: function () {
                // Cards approved, rejected or moved on this page left the queue, so everything after
                // them moved up by that many: start the next page that much earlier, or it skips them.
                state.pages.push(state.offset);
                state.offset = Math.max(state.offset, state.nextOffset - leftThisPage());
                loadItems();
                scrollToPanel();
            }
        }));
    }

    /** Cards on this page that have left the view: gone, or refilled with a different status. */
    function leftThisPage() {
        var n = 0;
        listEl.querySelectorAll('.rinbox-card').forEach(function (node) {
            if (node.classList.contains('rinbox-gone')) n++;
            else if (node.kopItem && node.kopFirstStatus !== undefined && (node.kopItem.status || '') !== node.kopFirstStatus) n++;
        });
        return n;
    }

    /** Space the WordPress admin bar takes at the top of the window. */
    function topMargin() {
        var bar = document.getElementById('wpadminbar');
        return (bar ? bar.offsetHeight : 0) + 12;
    }

    /**
     * Run change() on a card. When the card's top was above the window (the reader scrolled down to its
     * buttons), the card shrinking would pull the cards below up past them: put its top at the top of the
     * window instead, at once (a smooth scroll races the layout). A card fully in view is left alone.
     */
    function keepInView(node, change) {
        var margin = topMargin();
        var above = node.getBoundingClientRect().top < margin;
        change();
        if (above) window.scrollBy(0, node.getBoundingClientRect().top - margin);
    }

    function scrollToPanel() {
        var top = panel.getBoundingClientRect().top - topMargin();
        if (top < 0) window.scrollBy(0, top);
    }

    /* ---- Cards ------------------------------------------------------------- */

    /**
     * The field that holds a card's name: one the queue marks 'title', else the
     * text field showing the card's title, else one called name/label/title.
     */
    var NAME_FIELDS = ['name', 'officialName', 'program_name', 'title', 'label', 'article_title', 'case_name', 'bill_title'];
    function titleFieldOf(item) {
        var text = (item.fields || []).filter(function (f) {
            return (!f.type || f.type === 'text') && !f.readonly && !f.category && !/url|link/i.test(f.name);
        });
        return text.filter(function (f) { return f.title; })[0]
            || text.filter(function (f) { return item.title && String(f.value || '') === item.title; })[0]
            || text.filter(function (f) { return NAME_FIELDS.indexOf(f.name) !== -1; })[0]
            || null;
    }

    function fieldInput(f, idPrefix) {
        var id = idPrefix + f.name;
        var input;
        var type = f.type || 'text';
        if (type === 'textarea') {
            input = el('textarea', { id: id, rows: 4 });
            input.value = f.value || '';
        } else if (type === 'list') {
            input = el('textarea', { id: id, rows: 3, placeholder: 'One per line' });
            input.value = Array.isArray(f.value) ? f.value.join('\n') : (f.value || '');
        } else if (type === 'select') {
            input = el('select', { id: id });
            var opts = f.options || {};
            if (!Object.prototype.hasOwnProperty.call(opts, '')) input.appendChild(el('option', { value: '', text: '—' }));
            Object.keys(opts).forEach(function (k) {
                var o = el('option', { value: k, text: opts[k] });
                if (String(f.value) === k) o.selected = true;
                input.appendChild(o);
            });
        } else if (type === 'types') {
            // Several choices at once (a program's types): a box per option, sent as "A, B" (kop_facility_types_split()).
            input = el('span', { id: id, class: 'rinbox-multi', role: 'group', 'aria-label': f.label || f.name });
            var mopts = f.options || {};
            var keys = Array.isArray(mopts) ? mopts : Object.keys(mopts).filter(function (k) { return k !== ''; });
            var have = (Array.isArray(f.value) ? f.value : String(f.value || '').split(',')).map(function (x) { return String(x).trim().toLowerCase(); });
            keys.forEach(function (k) {
                var box = el('input', { type: 'checkbox', value: k });
                box.checked = have.indexOf(String(k).toLowerCase()) !== -1;
                input.appendChild(el('label', { class: 'rinbox-multi-opt' }, [box, ' ' + (Array.isArray(mopts) ? k : mopts[k])]));
            });
        } else if (type === 'facility') {
            input = el('input', { id: id, type: 'number', min: '1', placeholder: 'id', 'data-kop-facility-finder': '1' });
            input.value = f.value ? String(f.value) : '';
        } else if (type === 'record') {
            // Any record: a program id, or "company:45" etc. from the finder's any-record mode; the finder shows what is picked.
            // kop_record_field_param() narrows the kinds (f.kinds) and may let the reviewer name a new one (f.create: "new:company:<name>").
            input = el('input', { id: id, type: 'hidden', 'data-kop-facility-finder': '1',
                'data-kop-record-kinds': f.kinds && f.kinds.length ? f.kinds.join(',') : 'all',
                'data-kop-record-create': f.create && f.create.length ? f.create.join(',') : null,
                'data-kop-picked-label': f.value_label || '' });
            input.value = f.value ? String(f.value) : '';
        } else if (type === 'checkbox') {
            input = el('input', { id: id, type: 'checkbox' });
            input.checked = !!f.value && f.value !== '0';
        } else if (type === 'facilities') {
            // Several records: chips, and the facility finder adds one per pick (f.kinds: companies too, as "company:45").
            input = facilitiesInput(id, f.value, f.kinds);
        } else {
            input = el('input', { id: id, type: type === 'number' ? 'number' : 'text' });
            input.value = f.value === null || f.value === undefined ? '' : String(f.value);
            if (f.lookup && type === 'text') lookupInput(input, f, id);
        }
        if (f.readonly) input.disabled = true;
        input.dataset.field = f.name;
        input.dataset.kind = type;
        return input;
    }

    /** A text input that suggests values from the source's 'lookup' as the reviewer types (a datalist). */
    function lookupInput(input, f, id) {
        var src = state.source;
        var list = el('datalist', { id: id + '-list' });
        input.setAttribute('list', list.id);
        input.setAttribute('autocomplete', 'off');
        if (f.placeholder) input.setAttribute('placeholder', f.placeholder);
        document.body.appendChild(list);
        var timer = null, seq = 0;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) return;
            timer = setTimeout(function () {
                var mine = ++seq;
                api('lookup', null, { source: src, name: f.lookup, q: q }).then(function (data) {
                    if (mine !== seq) return;
                    list.innerHTML = '';
                    (data.options || []).forEach(function (o) { list.appendChild(el('option', { value: o.value, label: o.label, text: o.label })); });
                }).catch(function () { /* no suggestions */ });
            }, 250);
        });
    }

    function facilitiesInput(id, value, kinds) {
        var box = el('span', { id: id, class: 'rinbox-facilities' });
        var chips = el('span', { class: 'rinbox-facility-chips' });
        var anyKind = kinds && kinds.length;
        var finder = el('input', { type: 'hidden', 'data-kop-facility-finder': 'multi', 'data-kop-record-kinds': anyKind ? kinds.join(',') : null });
        var picked = [];
        (Array.isArray(value) ? value : (value ? [value] : [])).forEach(function (v) {
            picked.push(typeof v === 'object' ? { id: String(v.id), name: v.name || ('#' + v.id) } : { id: String(v), name: '#' + v });
        });
        function draw() {
            box.dataset.ids = JSON.stringify(picked.map(function (p) { return p.id; }));
            chips.innerHTML = '';
            picked.forEach(function (p) {
                chips.appendChild(el('span', { class: 'rinbox-tag' }, [p.name, el('button', {
                    type: 'button', class: 'rinbox-tag-x', 'aria-label': 'Remove ' + p.name, text: '\u00d7',
                    onclick: function () { picked = picked.filter(function (x) { return x.id !== p.id; }); draw(); }
                })]));
            });
        }
        box.addEventListener('kop-facility-picked', function (e) {
            var f = e.detail || {};
            var pid = String(anyKind && f.token ? f.token : f.id);
            if (f.id && !picked.some(function (x) { return x.id === pid; })) picked.push({ id: pid, name: f.name || ('#' + f.id) });
            draw();
        });
        box.appendChild(chips);
        box.appendChild(finder);
        draw();
        return box;
    }

    function readInput(input) {
        var kind = input.dataset.kind;
        if (kind === 'checkbox') return input.checked ? '1' : '';
        if (kind === 'types') {
            return Array.prototype.map.call(input.querySelectorAll('input[type=checkbox]:checked'), function (b) { return b.value; }).join(', ');
        }
        if (kind === 'facilities') { try { return JSON.parse(input.dataset.ids || '[]'); } catch (e) { return []; } }
        if (kind === 'list') return input.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
        if (kind === 'facility' || kind === 'number' || kind === 'record') return input.value.trim() === '' ? '' : input.value.trim();
        return input.value;
    }

    /** A param's label and input; a group of boxes (types) gets a span, since each box has its own label. */
    function paramLabel(p, input) {
        return el(input.dataset.kind === 'types' ? 'span' : 'label', { class: 'rinbox-param' }, [p.label + ' ', input]);
    }

    var cardSeq = 0;

    /** A button that may first ask for a few values (a tool, or an action with params). go(params, button) runs it. */
    function paramButton(def, prefix, go) {
        var wrap = el('span', { class: 'rinbox-action-group' });
        var inputs = (def.params || []).map(function (p) {
            var input = fieldInput(p, prefix + def.id + '-');
            wrap.appendChild(paramLabel(p, input));
            return input;
        });
        var btn = el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-' + (def.style || 'neutral'), text: def.label, title: def.help || null,
            onclick: function () {
                if (def.confirm && !window.confirm(def.confirm)) return;
                var params = {};
                inputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                go(params, btn);
            }
        });
        wrap.appendChild(btn);
        return wrap;
    }

    /**
     * "Move to…": a destination with params (a facility website or resource
     * needs the facility; a resource also its kind) opens a small form first.
     * go(params) sends the move.
     */
    function moveControl(item, prefix, go) {
        var wrap = el('span', { class: 'rinbox-move' });
        var mv = el('select', { 'aria-label': 'Move to another queue' }, [el('option', { value: '', text: 'Move to…' })]);
        (item.moves || []).forEach(function (m) { mv.appendChild(el('option', { value: m.id, text: m.label })); });
        var form = el('span', { class: 'rinbox-move-form', hidden: true });
        wrap.appendChild(mv);
        wrap.appendChild(form);
        mv.addEventListener('change', function () {
            var m = (item.moves || []).filter(function (x) { return x.id === mv.value; })[0];
            fillMoveForm(form, m, prefix, go);
        });
        return wrap;
    }

    /** The move's own questions (facility, school, program) and its button; a move with none goes at once. */
    function fillMoveForm(form, m, prefix, go) {
        form.innerHTML = '';
        form.hidden = true;
        if (!m) return;
        if (!m.params || !m.params.length) { go({ to: m.id }); return; }
        if (m.help) form.appendChild(el('span', { class: 'rinbox-help', text: m.help }));
        var inputs = m.params.map(function (p) {
            var input = fieldInput(p, prefix + 'mv-' + m.id + '-');
            form.appendChild(paramLabel(p, input));
            return input;
        });
        form.appendChild(el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: m.label,
            onclick: function () {
                var params = { to: m.id };
                inputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                go(params);
            }
        }));
        form.hidden = false;
        if (typeof window.kopFacilityFinderAttach === 'function') {
            form.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
        }
    }

    /**
     * One move as a button of its own on the card (an article about an Indian
     * boarding school), so it is not hidden in the "Move to" list.
     */
    function moveButton(m, text, prefix, go) {
        var wrap = el('span', { class: 'rinbox-move' });
        var form = el('span', { class: 'rinbox-move-form', hidden: true });
        wrap.appendChild(el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: text, title: m.label,
            'aria-expanded': 'false',
            onclick: function (e) {
                var open = form.hidden;
                if (open) fillMoveForm(form, m, prefix, go);
                else { form.hidden = true; form.innerHTML = ''; }
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        }));
        wrap.appendChild(form);
        return wrap;
    }

    /** A check or a cross before Approve / Reject (inc/icons.php SVGs; drawn in the button's colour). */
    function actionIcon(style) {
        var name = style === 'approve' ? 'check' : (style === 'reject' ? 'x' : '');
        if (!name || typeof window.kopIcon !== 'function') return null;
        var span = el('span', { class: 'rinbox-btn-icon', 'aria-hidden': 'true' });
        span.innerHTML = window.kopIcon(name);
        return span;
    }

    /* ---- Preview ----------------------------------------------------------- */

    /**
     * The box a card's links preview into: the live page in a frame when the
     * site allows it (our own pages, Drive files), else a reading copy (title,
     * picture, text) from review-inbox/preview. One link at a time.
     */
    function previewPane() {
        var box = el('div', { class: 'rinbox-pv', hidden: true });
        box.kopShow = function (url, btn) {
            if (box.kopUrl === url && !box.hidden) { box.kopHide(); return; }
            if (box.kopBtn) box.kopBtn.textContent = 'Preview';
            box.kopUrl = url;
            box.kopBtn = btn;
            btn.textContent = 'Hide preview';
            box.hidden = false;
            box.innerHTML = '';
            box.appendChild(el('p', { class: 'rinbox-pv-note', text: 'Loading the page…' }));
            api('preview', null, { url: url }).then(function (pv) {
                if (box.kopUrl !== url) return;
                drawPreview(box, pv);
            }).catch(function (e) {
                if (box.kopUrl !== url) return;
                box.innerHTML = '';
                box.appendChild(el('p', { class: 'rinbox-pv-note', text: 'Could not preview this link: ' + e.message }));
                box.appendChild(el('a', { href: url, target: '_blank', rel: 'noopener', text: 'Open it in a new tab' }));
            });
        };
        box.kopHide = function () {
            box.hidden = true;
            box.innerHTML = '';
            box.kopUrl = null;
            if (box.kopBtn) box.kopBtn.textContent = 'Preview';
        };
        return box;
    }

    function drawPreview(box, pv) {
        box.innerHTML = '';
        var hasText = !!(pv.text || pv.title || pv.image);
        var mode = pv.frame_url ? 'page' : 'text';
        var body = el('div', { class: 'rinbox-pv-body' });
        var head = el('div', { class: 'rinbox-pv-head' }, [
            el('span', { class: 'rinbox-pv-site', text: (pv.site || pv.host || '') + (pv.kind === 'pdf' ? ' (PDF)' : '') })
        ]);
        var switcher = null;
        if (pv.frame_url && hasText) {
            switcher = el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: 'Show the text only',
                onclick: function () { mode = mode === 'page' ? 'text' : 'page'; draw(); }
            });
            head.appendChild(switcher);
        }
        head.appendChild(el('a', { href: pv.url, target: '_blank', rel: 'noopener', text: 'Open in a new tab' }));
        head.appendChild(el('button', { type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Close', onclick: function () { box.kopHide(); } }));
        box.appendChild(head);
        if (pv.note) box.appendChild(el('p', { class: 'rinbox-pv-note', text: pv.note }));
        box.appendChild(body);
        function draw() {
            body.innerHTML = '';
            if (switcher) switcher.textContent = mode === 'page' ? 'Show the text only' : 'Show the page';
            if (mode === 'page') {
                body.appendChild(el('iframe', { src: pv.frame_url, title: 'Preview of ' + (pv.title || pv.url), loading: 'lazy', referrerpolicy: 'no-referrer' }));
                return;
            }
            var reader = el('div', { class: 'rinbox-pv-reader' });
            if (pv.image) reader.appendChild(el('img', { src: pv.image, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }));
            if (pv.title) reader.appendChild(el('h4', { text: pv.title }));
            String(pv.text || '').split(/\n\s*\n|\n/).forEach(function (para) {
                if (para.trim()) reader.appendChild(el('p', { text: para.trim() }));
            });
            if (!reader.childNodes.length) reader.appendChild(el('p', { text: 'Nothing readable came back from this page. Open it in a new tab to check it.' }));
            body.appendChild(reader);
        }
        draw();
    }

    /**
     * "Text for the AI": a folded box to paste what the link holds when the
     * AI cannot open it (a Drive file, a paywall, a scan). Sent as 'text' to
     * review-inbox/ai, which reads it instead of the link.
     */
    function aiTextBox(prefix) {
        var area = el('textarea', { id: prefix + 'ai-text', rows: 6, class: 'rinbox-ai-text',
            placeholder: 'Paste the article, document or page text here. Leave it empty to read the link.' });
        var box = el('details', { class: 'rinbox-ai-paste' }, [
            el('summary', { text: 'Paste text for the AI' }),
            el('label', { for: prefix + 'ai-text', class: 'rinbox-ai-paste-help',
                text: 'When the AI cannot open the link, paste its text here and click "Fill empty fields with AI".' }),
            area
        ]);
        box.kopValue = function () { return area.value.trim(); };
        return box;
    }

    function previewButton(url, pane) {
        return el('button', {
            type: 'button', class: 'rinbox-pv-btn', text: 'Preview', 'aria-label': 'Preview ' + linkLabel(url) + ' here',
            onclick: function (e) { pane.kopShow(url, e.currentTarget); }
        });
    }

    /* ---- Later: snooze or hand to someone ----------------------------------- */

    function fmtDate(iso) {
        var d = new Date(iso);
        return isNaN(d) ? String(iso).slice(0, 10) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function fmtWhen(iso) {
        var d = new Date(iso);
        return isNaN(d) ? String(iso).slice(0, 16) : d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    /** "Snoozed until Oct 12" / "Handed to Pat by dani: note", with "Put back". */
    function holdNote(item, src, node) {
        var h = item.hold;
        var bits = [];
        if (h.research) bits.push('Needs research');
        if (h.assigned_to) bits.push((h.mine ? 'Assigned to you' : 'Handed to ' + h.assigned_name) + (h.by ? ' by ' + h.by : ''));
        if (h.snooze_until) bits.push('snoozed until ' + fmtDate(h.snooze_until));
        var text = bits.join(', ');
        text = text.charAt(0).toUpperCase() + text.slice(1) + (h.note ? ': ' + h.note : '');
        return el('p', { class: 'rinbox-hold' }, [text + ' ', el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Put back in the list',
            onclick: function () { setHold(item, src, node, 0, 0, ''); }
        })]);
    }

    /** What volunteer reviewers recommended: "Volunteers: Sam recommends approve: note". */
    function recNote(item) {
        var box = el('div', { class: 'rinbox-recs' }, [el('strong', { text: 'Volunteers: ' })]);
        item.recs.forEach(function (r, i) {
            box.appendChild(el('span', { class: 'rinbox-rec rinbox-rec-' + String(r.verdict).replace(/[^a-z]/g, '') }, [
                (i ? '; ' : '') + r.name + ' says ',
                el('em', { text: String(r.verdict_label).toLowerCase() }),
                r.note ? ' (' + r.note + ')' : ''
            ]));
        });
        return box;
    }

    function setHold(item, src, node, days, assign, note) {
        holdCall(item, src, node, 'hold', { source: src, key: item.key, title: item.title || '', days: days, assign: assign, note: note },
            ' It is off your list until then; find it under Snoozed or in Recently done.');
    }

    /** "Needs research" on or off: the item leaves every queue for Your inbox > Needs research, or goes back. */
    function setResearch(item, src, node, on) {
        holdCall(item, src, node, 'research', { source: src, key: item.key, title: item.title || '', on: on ? 1 : '' }, '');
    }

    /** The card's toggle: "Needs research" sends it there, "Back to the queue" brings it back. */
    function researchButton(item, src, node) {
        var on = !!(item.hold && item.hold.research);
        return el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-neutral rinbox-research' + (on ? ' is-on' : ''), 'aria-pressed': on ? 'true' : 'false',
            text: on ? 'Back to the queue' : 'Needs research',
            title: on ? 'Put it back in its queue to decide' : 'Take it off the queue until you have looked into it; it waits under Your inbox > Needs research',
            onclick: function (e) { e.currentTarget.disabled = true; setResearch(item, src, node, !on); }
        });
    }

    function holdCall(item, src, node, route, body, goneNote) {
        var m = node.querySelector('.rinbox-message');
        api(route, body).then(function (res) {
            return api('items', null, { source: src, keys: item.key }).then(function (data) {
                var next = (data.items || [])[0];
                if (node.kopAfterHold) { node.kopAfterHold(res, next); refreshCounts(); return; }
                var hidden = next && next.hold && next.hold.hidden;
                // In the Needs research list, a card sent back to its queue leaves the list.
                if (state.special === '_research' && !(next && next.hold && next.hold.research)) hidden = true;
                if (node.classList.contains('rinbox-card') && next && !hidden) {
                    if (item.source) { next.source = item.source; next.source_label = item.source_label; }
                    fillCard(node, next, state.byKey[src], src, res.message);
                } else if (node.classList.contains('rinbox-card')) {
                    keepInView(node, function () {
                        node.classList.add('rinbox-gone');
                        node.innerHTML = '';
                        node.appendChild(el('p', { class: 'rinbox-message', text: res.message + goneNote }));
                    });
                } else if (m) {
                    m.hidden = false; m.className = 'rinbox-message'; m.textContent = res.message;
                }
                refreshCounts();
            });
        }).catch(function (e) {
            if (m) { m.hidden = false; m.className = 'rinbox-message is-error'; m.textContent = e.message; }
        });
    }

    /** "Later…": snooze for a while and/or hand to another admin, with a note. */
    function laterControl(item, src, node) {
        var box = el('details', { class: 'rinbox-later' });
        box.appendChild(el('summary', { text: 'Later…' }));
        var days = el('select', { 'aria-label': 'Snooze for' }, [
            el('option', { value: '0', text: 'Do not snooze' }),
            el('option', { value: '1', text: '1 day' }),
            el('option', { value: '3', text: '3 days' }),
            el('option', { value: '7', text: '1 week', selected: true }),
            el('option', { value: '30', text: '1 month' })
        ]);
        var who = el('select', { 'aria-label': 'Hand to' }, [el('option', { value: '0', text: 'Nobody' })]);
        state.admins.forEach(function (a) {
            who.appendChild(el('option', { value: String(a.id), text: a.id === state.me ? a.name + ' (you)' : a.name, selected: item.hold && item.hold.assigned_to === a.id }));
        });
        var note = el('input', { type: 'text', placeholder: 'Note (why, what to check)', 'aria-label': 'Note', value: item.hold && item.hold.note || '' });
        box.appendChild(el('div', { class: 'rinbox-later-form' }, [
            el('label', { class: 'rinbox-param' }, ['Snooze ', days]),
            el('label', { class: 'rinbox-param' }, ['Hand to ', who]),
            note,
            el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: 'Set aside',
                onclick: function () { setHold(item, src, node, parseInt(days.value, 10) || 0, parseInt(who.value, 10) || 0, note.value.trim()); }
            })
        ]));
        return box;
    }

    /* ---- Your inbox: assigned to you, snoozed, recently done ----------------- */

    function openSpecial(k) {
        state.special = k;
        state.source = null;
        page.classList.add('rinbox-active');
        page.querySelectorAll('.type-tabs .submission-tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        panel.hidden = false;
        renderTabs();
        setUrl();
        if (k === '_tools') renderAllTools();
        else if (k === '_done') renderDone(); else renderHeld(k === '_mine' ? 'mine' : k === '_volunteers' ? 'volunteers' : k === '_research' ? 'research' : 'snoozed');
    }

    /* ---- All tools: every KOP tool, grouped as in the KOP Tools menu ---------- */

    var allTools = null;

    function renderAllTools() {
        panel.innerHTML = '';
        panel.appendChild(el('div', { class: 'rinbox-head' }, [
            el('h2', { text: 'All tools' }),
            el('p', { class: 'rinbox-help', text: 'Every KOP tool, the same list as the KOP Tools menu in wp-admin. Open goes to the tool; Run starts a batch job and shows here what it reported.' })
        ]));
        var search = el('input', { type: 'search', class: 'rinbox-search', placeholder: 'Find a tool…', 'aria-label': 'Find a tool' });
        var status = el('p', { class: 'rinbox-status', role: 'status', text: 'Loading…' });
        var out = el('pre', { class: 'rinbox-tools-output', hidden: true });
        var list = el('div', { class: 'rinbox-tools' });
        panel.appendChild(el('div', { class: 'rinbox-toolbar' }, [el('span', { class: 'rinbox-filters' }, [search])]));
        panel.appendChild(status);
        panel.appendChild(out);
        panel.appendChild(list);

        function run(t, btn) {
            if (!window.confirm('Run "' + t.title + '" now?')) return;
            btn.disabled = true;
            out.hidden = false;
            out.textContent = 'Running ' + t.title + '…';
            fetch(t.url, { method: 'POST', credentials: 'same-origin' })
                .then(function (res) { return res.text().then(function (text) { return { status: res.status, text: text }; }); })
                .then(function (r) {
                    var body = r.text;
                    try { body = JSON.stringify(JSON.parse(r.text), null, 2); } catch (e) { /* not JSON: show it as sent */ }
                    out.textContent = t.title + ': HTTP ' + r.status + '\n' + body;
                })
                .catch(function (e) { out.textContent = t.title + ': request failed: ' + e.message; })
                .then(function () { btn.disabled = false; });
        }

        function draw() {
            var q = search.value.trim().toLowerCase();
            list.innerHTML = '';
            var shown = 0;
            var current = null;
            (allTools || []).forEach(function (t) {
                if (q && (t.title + ' ' + t.desc + ' ' + t.category).toLowerCase().indexOf(q) === -1) return;
                if (!current || current.category !== t.category) {
                    current = { category: t.category, box: el('div', { class: 'rinbox-tools-group' }) };
                    list.appendChild(el('h3', { class: 'rinbox-tools-heading', text: t.category }));
                    list.appendChild(current.box);
                }
                var act;
                if (!t.available) act = el('em', { class: 'rinbox-tools-missing', text: t.missing || 'Not on this site' });
                else if (t.action) {
                    act = el('button', { type: 'button', class: 'rinbox-pv-btn', text: 'Run' });
                    act.addEventListener('click', function () { run(t, act); });
                } else act = el('a', { class: 'rinbox-tools-open', href: t.url, target: t.external ? '_blank' : null, rel: t.external ? 'noopener' : null, text: 'Open' });
                current.box.appendChild(el('div', { class: 'rinbox-tools-row' + (t.available ? '' : ' is-missing') }, [
                    el('div', { class: 'rinbox-tools-text' }, [
                        el('strong', { text: t.title }),
                        t.desc ? el('p', { class: 'rinbox-help', text: t.desc }) : null
                    ]),
                    act
                ]));
                shown++;
            });
            if (!allTools) return;
            status.textContent = !allTools.length ? 'The KOP Tools plugin is not active, so there is no list of tools.'
                : shown ? shown + (shown === 1 ? ' tool' : ' tools') : 'No tool matches.';
        }

        search.addEventListener('input', draw);
        (allTools ? Promise.resolve({ tools: allTools }) : api('all-tools')).then(function (data) {
            allTools = data.tools || [];
            draw();
        }).catch(function (e) { status.textContent = 'Could not load the tools: ' + e.message; });
    }

    function renderHeld(which) {
        panel.innerHTML = '';
        panel.appendChild(el('div', { class: 'rinbox-head' }, [
            el('h2', { text: which === 'mine' ? 'Assigned to you' : which === 'volunteers' ? 'Volunteers recommend' : which === 'research' ? 'Needs research' : 'Snoozed' }),
            el('p', { class: 'rinbox-help', text: which === 'mine'
                ? 'Items from any queue that someone handed to you. Approve or reject them here; that takes them off this list.'
                : which === 'volunteers'
                    ? 'Items volunteer reviewers have looked at, the most recommended first. Their advice is on each card; your Approve or Reject is the decision and takes the item off this list. Volunteers and their links: KOP Tools > Volunteer Reviewers.'
                    : which === 'research'
                        ? 'Items from every queue you sent here to look into before deciding, newest first. Approve or reject them here, or press "Back to the queue" to put one back where it came from.'
                        : 'Items set aside for later. Each comes back to its queue on the date shown, or now with "Put back in the list".' })
        ]));
        statusEl = el('p', { class: 'rinbox-status', role: 'status', text: 'Loading…' });
        listEl = el('div', { class: 'rinbox-list' });
        bulkEl = el('div', { class: 'rinbox-bulk' });
        pagerEl = el('div', { class: 'rinbox-pager' });
        panel.appendChild(statusEl);
        panel.appendChild(listEl);
        api('held', null, { which: which }).then(function (data) {
            var items = data.items || [];
            statusEl.textContent = items.length ? items.length + (items.length === 1 ? ' item' : ' items') : 'Nothing here.';
            items.forEach(function (it) {
                listEl.appendChild(card(it));
                if (it.native) listEl.lastChild.appendChild(el('p', { class: 'rinbox-help' }, [
                    which === 'snoozed' || which === 'research' ? 'Put it back in the list, then approve or reject it on the ' : 'Approve or reject this one on the ',
                    el('button', { type: 'button', class: 'rinbox-pv-btn', text: it.source_label + ' tab', onclick: function () {
                        var tab = page.querySelector('.type-tabs [data-type="' + it.source + '"]');
                        if (tab) tab.click();
                    } }),
                    '.'
                ]));
            });
        }).catch(function (e) { statusEl.textContent = 'Could not load: ' + e.message; });
    }

    function renderDone() {
        panel.innerHTML = '';
        var q = { mine: '', source: '', search: '', offset: 0, limit: 50 };
        panel.appendChild(el('div', { class: 'rinbox-head' }, [
            el('h2', { text: 'Recently done' }),
            el('p', { class: 'rinbox-help', text: 'Everything approved, rejected, moved or set aside in the review inbox, newest first. Undo runs the queue’s own undo; a row without it cannot be taken back here.' })
        ]));
        var mine = el('input', { type: 'checkbox' });
        var queue = el('select', { 'aria-label': 'Queue' }, [el('option', { value: '', text: 'Every queue' })]);
        state.sources.forEach(function (s) { queue.appendChild(el('option', { value: s.key, text: s.label })); });
        var search = el('input', { type: 'search', class: 'rinbox-search', placeholder: 'Search titles…', 'aria-label': 'Search titles' });
        var timer = null;
        mine.addEventListener('change', function () { q.mine = mine.checked ? '1' : ''; q.offset = 0; load(); });
        queue.addEventListener('change', function () { q.source = queue.value; q.offset = 0; load(); });
        search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { q.search = search.value.trim(); q.offset = 0; load(); }, 350); });
        panel.appendChild(el('div', { class: 'rinbox-toolbar' }, [el('span', { class: 'rinbox-filters' }, [
            el('label', { class: 'rinbox-quick-field' }, [mine, ' Only what I did']), queue, search
        ])]));
        var status = el('p', { class: 'rinbox-status', role: 'status' });
        var list = el('ol', { class: 'rinbox-done' });
        var pager = el('div', { class: 'rinbox-pager' });
        panel.appendChild(status);
        panel.appendChild(list);
        panel.appendChild(pager);
        function load() {
            status.textContent = 'Loading…';
            list.innerHTML = '';
            pager.innerHTML = '';
            api('log', null, q).then(function (data) {
                var rows = data.rows || [];
                status.textContent = data.total ? data.total + ' actions' : 'Nothing done yet.';
                rows.forEach(function (r) { list.appendChild(doneRow(r)); });
                if (data.total > q.limit) {
                    pager.appendChild(el('button', { type: 'button', class: 'btn-secondary', text: 'Newer', disabled: q.offset === 0,
                        onclick: function () { q.offset = Math.max(0, q.offset - q.limit); load(); } }));
                    pager.appendChild(el('span', { text: ' ' + (q.offset + 1) + '–' + Math.min(data.total, q.offset + q.limit) + ' of ' + data.total + ' ' }));
                    pager.appendChild(el('button', { type: 'button', class: 'btn-secondary', text: 'Older', disabled: q.offset + q.limit >= data.total,
                        onclick: function () { q.offset += q.limit; load(); } }));
                }
            }).catch(function (e) { status.textContent = 'Could not load: ' + e.message; });
        }
        load();
    }

    function doneRow(r) {
        var li = el('li', { class: 'rinbox-done-row' + (r.undone ? ' is-undone' : '') });
        var msg = el('span', { class: 'rinbox-done-msg', text: r.message || '' });
        li.appendChild(el('span', { class: 'rinbox-done-when', text: fmtWhen(r.created) + ' · ' + r.user }));
        li.appendChild(el('span', { class: 'rinbox-done-what rinbox-done-' + (r.style || 'neutral'), text: r.action_label }));
        li.appendChild(el('span', { class: 'rinbox-done-title' }, [el('strong', { text: r.title || r.key }), ' ', el('span', { class: 'rinbox-queue-badge', text: r.source_label })]));
        li.appendChild(msg);
        if (r.undone) {
            li.appendChild(el('span', { class: 'rinbox-done-undone', text: 'Undone ' + fmtWhen(r.undone.at) + ' by ' + r.undone.by }));
        } else if (r.can_undo) {
            var btn = el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Undo',
                onclick: function () {
                    btn.disabled = true;
                    msg.textContent = 'Undoing…';
                    api('undo', { id: r.id }).then(function (res) {
                        li.classList.add('is-undone');
                        btn.replaceWith(el('span', { class: 'rinbox-done-undone', text: res.message || 'Undone.' }));
                        msg.textContent = r.message || '';
                        refreshCounts();
                    }).catch(function (e) {
                        btn.disabled = false;
                        msg.textContent = e.message;
                        msg.classList.add('is-error');
                    });
                }
            });
            li.appendChild(btn);
        }
        return li;
    }

    function card(item) {
        // Cards in "Assigned to you" / "Snoozed" come from every queue and say which.
        var src = item.source || state.source;
        var s = state.byKey[src];
        var node = el('article', { class: 'rinbox-card', 'data-key': item.key });
        fillCard(node, item, s, src);
        node.kopFirstStatus = item.status || '';
        return node;
    }

    function fillCard(node, item, s, src, message, isError) {
        node.innerHTML = '';
        node.kopItem = item;
        var prefix = 'rinbox-' + (++cardSeq) + '-';
        var category = (item.fields || []).filter(function (f) { return f.category && !f.hidden_by_owner; })[0];

        var title = el('h3', { class: 'rinbox-title', text: item.title || '(no name)' });
        var meta = el('div', { class: 'rinbox-meta' }, [
            item.source_label ? el('span', { class: 'rinbox-queue-badge', text: item.source_label }) : null,
            item.status_label ? el('span', { class: 'status-badge rinbox-status-' + String(item.status || '').replace(/[^a-z_-]/gi, ''), text: item.status_label }) : null,
            item.subtitle ? el('span', { text: item.subtitle }) : null,
            item.created ? el('span', { class: 'rinbox-date', text: String(item.created).slice(0, 10) }) : null
        ]);
        // Every link gets "Preview": the page opens inside the card.
        var pane = previewPane();
        var links = el('div', { class: 'rinbox-links' });
        function addLink(url, text, extra) {
            links.appendChild(el('span', { class: 'rinbox-link' }, [
                el('a', Object.assign({ href: url, target: '_blank', rel: 'noopener', text: text }, extra || {})),
                previewButton(url, pane)
            ]));
        }
        if (safeHref(item.url)) addLink(item.url, linkLabel(item.url), { title: item.url });
        if (item.facility) {
            if (item.facility.url) addLink(item.facility.url, 'Facility page: ' + item.facility.name);
            else links.appendChild(el('span', { text: item.facility.name }));
        }
        (item.links || []).forEach(function (l) {
            if (safeHref(l.url)) addLink(l.url, l.label);
        });

        var pick = (item.actions || []).length
            ? el('input', { type: 'checkbox', class: 'rinbox-select', 'aria-label': 'Select ' + (item.title || 'this item'), checked: !!item.selected })
            : null;
        node.kopParams = {};
        // "Rename" beside the title saves the queue's name field on its own.
        var nameField = s.can_save ? titleFieldOf(item) : null;
        var renameForm = null, renameBtn = null;
        if (nameField) {
            var renameInput = el('input', { type: 'text', class: 'rinbox-rename-name', 'aria-label': nameField.label || 'Name', value: nameField.value || '' });
            renameForm = el('form', { class: 'rinbox-rename', hidden: true }, [
                renameInput,
                el('button', { type: 'submit', class: 'rinbox-btn rinbox-btn-neutral', text: 'Save name' }),
                el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Cancel',
                    onclick: function () { renameForm.hidden = true; renameBtn.setAttribute('aria-expanded', 'false'); }
                })
            ]);
            renameForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var v = renameInput.value.trim();
                if (!v) { renameInput.focus(); return; }
                var fields = {};
                fields[nameField.name] = v;
                run(node, item, s, src, 'save', { fields: fields }, e.submitter || null);
            });
            renameBtn = el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo rinbox-rename-toggle', text: 'Rename', 'aria-expanded': 'false',
                title: 'Change ' + String(nameField.label || 'the name').toLowerCase(),
                onclick: function () {
                    renameForm.hidden = !renameForm.hidden;
                    renameBtn.setAttribute('aria-expanded', renameForm.hidden ? 'false' : 'true');
                    if (!renameForm.hidden) { renameInput.focus(); renameInput.select(); }
                }
            });
        }
        var titleRow = pick || renameBtn ? el('span', { class: 'rinbox-title-row' }, [pick, title, renameBtn]) : title;
        var head = el('header', { class: 'rinbox-card-head' }, [titleRow, meta, renameForm]);
        node.appendChild(head);
        // A conflict with the record (or another source) is always marked, above everything else.
        var conflictIcon = function () {
            if (typeof window.kopIcon !== 'function') return null;
            var span = el('span', { class: 'rinbox-conflict-icon', 'aria-hidden': 'true' });
            span.innerHTML = window.kopIcon('alert-triangle');
            return span;
        };
        node.classList.toggle('has-conflict', !!item.conflict);
        if (item.conflict) {
            node.appendChild(el('p', { class: 'rinbox-conflict', role: 'note' }, [
                conflictIcon(), el('strong', { text: 'Conflict: ' }), document.createTextNode(item.conflict)
            ]));
        }
        if (item.hold) node.appendChild(holdNote(item, src, node));
        if (item.recs && item.recs.length) node.appendChild(recNote(item));
        if (links.childNodes.length) node.appendChild(links);
        node.appendChild(pane);
        if (item.text) node.appendChild(el('p', { class: 'rinbox-text', text: item.text }));
        // item.compare_first: the comparison comes right after the text (Data audit: KOP as it is now, before the steps).
        function drawCompare() {
            if (item.compare && item.compare.rows && item.compare.rows.length) {
                var table = el('table', { class: 'rinbox-compare' });
                var headRow = el('tr', null, [el('th', { scope: 'col', text: '' })]);
                (item.compare.heads || []).forEach(function (h) { headRow.appendChild(el('th', { scope: 'col', text: h })); });
                table.appendChild(el('thead', null, [headRow]));
                var body = el('tbody');
                item.compare.rows.forEach(function (r) {
                    var tr = el('tr', { class: r.differs ? 'rinbox-differs' : null }, [el('th', { scope: 'row', text: r.label })]);
                    (r.values || []).forEach(function (v) { tr.appendChild(el('td', { text: Array.isArray(v) ? v.join(', ') : String(v === null || v === undefined ? '' : v) })); });
                    body.appendChild(tr);
                });
                table.appendChild(body);
                var compareWrap = el('div', { class: 'rinbox-compare-wrap' }, [table]);
                node.appendChild(item.compare_fold ? fold(item.compare_fold, compareWrap) : compareWrap);
            }
        }
        if (item.compare_first) drawCompare();
        node.kopPicked = null;
        if (item.checklist && item.checklist.length) node.appendChild(checklistBox(item, node, pane, prefix));
        if (item.details && item.details.length) {
            var dl = el('dl', { class: 'rinbox-details' });
            item.details.forEach(function (d) {
                dl.appendChild(el('dt', { text: d.label }));
                var dd = el('dd');
                if (d.url && safeHref(d.url)) {
                    dd.appendChild(el('a', { href: d.url, target: '_blank', rel: 'noopener', text: d.value || linkLabel(d.url) }));
                    dd.appendChild(previewButton(d.url, pane));
                }
                else dd.textContent = Array.isArray(d.value) ? d.value.join(', ') : String(d.value === null || d.value === undefined ? '' : d.value);
                dl.appendChild(dd);
            });
            // item.details_fold: the list starts folded under that line (Wiki updates: 100+ lines)
            node.appendChild(item.details_fold ? fold(item.details_fold, dl) : dl);
        }
        if (item.sources && item.sources.length) node.appendChild(sourcesBox(item.sources, pane));
        if (!item.compare_first) drawCompare();
        if (item.preview && safeHref(item.preview.url)) {
            var frameBox = el('div', { class: 'rinbox-preview', hidden: true });
            var label = item.preview.label || 'Show pages';
            node.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo rinbox-preview-toggle', text: label, 'aria-expanded': 'false',
                onclick: function (e) {
                    if (!frameBox.firstChild) frameBox.appendChild(el('iframe', { src: item.preview.url, title: label, loading: 'lazy' }));
                    frameBox.hidden = !frameBox.hidden;
                    e.currentTarget.setAttribute('aria-expanded', frameBox.hidden ? 'false' : 'true');
                    e.currentTarget.textContent = frameBox.hidden ? label : 'Hide';
                }
            }));
            node.appendChild(frameBox);
        }

        // Quick edits: category and tags save as soon as they change.
        var quick = el('div', { class: 'rinbox-quick' });
        if (category && s.can_save) {
            var catInput = fieldInput(category, prefix + 'cat-');
            catInput.addEventListener('change', function () {
                var fields = {};
                fields[category.name] = readInput(catInput);
                run(node, item, s, src, 'save', { fields: fields });
            });
            quick.appendChild(el(catInput.dataset.kind === 'types' ? 'span' : 'label', { class: 'rinbox-quick-field' }, [category.label + ' ', catInput]));
        }
        quick.appendChild(tagEditor(item, src, node));
        if (item.moves && item.moves.length) {
            quick.appendChild(moveControl(item, prefix, function (params) {
                run(node, item, s, src, 'act', { action: 'move', params: params });
            }));
        }

        // Details editor.
        var editable = (item.fields || []).length && s.can_save;
        var editor = null;
        if (editable) {
            editor = el('form', { class: 'rinbox-editor', hidden: true });
            var grid = el('div', { class: 'rinbox-editor-grid' });
            item.fields.forEach(function (f) {
                var input = fieldInput(f, prefix);
                var wide = f.type === 'textarea' || f.type === 'list' || f.type === 'types';
                // A field the owner hid at KOP Tools > Card Fields stays in the form, unseen, so its value is still sent.
                grid.appendChild(el('div', { class: 'rinbox-field' + (wide ? ' rinbox-field-wide' : ''), hidden: f.hidden_by_owner ? true : null }, [
                    el('label', { for: prefix + f.name, text: f.label }), input
                ]));
            });
            editor.appendChild(grid);
            var saveBtn = el('button', { type: 'submit', class: 'btn-save-edits', text: 'Save' });
            var aiText = s.can_ai ? aiTextBox(prefix) : null;
            var aiBtn = s.can_ai ? el('button', {
                type: 'button', class: 'btn-secondary rinbox-ai', text: 'Fill empty fields with AI',
                title: 'Reads this item and the page it links to (or the text pasted below), and fills only the fields that are empty',
                onclick: function () { run(node, item, s, src, 'ai', { text: aiText.kopValue() }, aiBtn); }
            }) : null;
            editor.appendChild(el('div', { class: 'rinbox-editor-actions' }, [saveBtn, aiBtn]));
            if (aiText) editor.appendChild(aiText);
            editor.addEventListener('submit', function (e) {
                e.preventDefault();
                var fields = {};
                editor.querySelectorAll('[data-field]').forEach(function (input) {
                    if (!input.disabled) fields[input.dataset.field] = readInput(input);
                });
                run(node, item, s, src, 'save', { fields: fields }, saveBtn);
            });
            node.appendChild(editor);
            if (typeof window.kopFacilityFinderAttach === 'function') {
                editor.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        }

        // The queue's own buttons: Approve and Reject first, each saying what it does.
        var decide = el('div', { class: 'rinbox-decide' });
        var does = el('ul', { class: 'rinbox-does' });
        var bar = el('div', { class: 'rinbox-actions' });
        if (editor) {
            bar.appendChild(el('button', {
                type: 'button', class: 'btn-secondary rinbox-edit-toggle', text: 'Edit details', 'aria-expanded': 'false',
                onclick: function (e) {
                    editor.hidden = !editor.hidden;
                    e.currentTarget.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
                    e.currentTarget.textContent = editor.hidden ? 'Edit details' : 'Close editor';
                }
            }));
        }
        // item.copy {label, text}: a Copy button; item.go {label, url}: a link drawn as a button.
        // Both sit first in the decision row (Wiki updates: Copy, Edit it on Reddit, Pasted on Reddit).
        if (item.copy && item.copy.text) {
            var copied = el('span', { class: 'rinbox-copied', role: 'status' });
            decide.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral rinbox-btn-primary',
                onclick: function () { copyText(item.copy.text, copied); }
            }, [item.copy.label || 'Copy']));
            decide.appendChild(copied);
        }
        if (item.go && safeHref(item.go.url)) {
            decide.appendChild(el('a', { class: 'rinbox-btn rinbox-btn-go', href: item.go.url, target: '_blank', rel: 'noopener', text: item.go.label }));
        }
        var askForms = el('div', { class: 'rinbox-ask-forms' });
        (item.actions || []).forEach(function (a) {
            var paramInputs = (a.params || []).map(function (p) { return fieldInput(p, prefix + 'p-' + a.id + '-'); });
            node.kopParams[a.id] = paramInputs;
            var primary = a.style === 'approve' || a.style === 'reject';
            var go = function (b) {
                if (a.confirm && !window.confirm(a.confirm)) return;
                var params = {};
                paramInputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                if (node.kopPicked) params.picked = node.kopPicked();
                // 'with_fields': the action also gets what the card's editor holds now, saved or not (Write-ups: approve the text as edited).
                if (a.with_fields && editor) {
                    params.fields = {};
                    editor.querySelectorAll('[data-field]').forEach(function (input) {
                        if (!input.disabled) params.fields[input.dataset.field] = readInput(input);
                    });
                }
                run(node, item, s, src, 'act', { action: a.id, params: params }, b);
            };
            var target = primary ? decide : bar;
            if (a.ask && paramInputs.length) {
                // 'ask': the button only opens a small form; the form's own button (a.submit) runs the action.
                var askForm = el('div', { class: 'rinbox-ask-form', hidden: true });
                var opener = el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral') + (primary ? ' rinbox-btn-primary' : ''),
                    title: a.help || null, 'aria-expanded': 'false',
                    onclick: function () {
                        askForm.hidden = !askForm.hidden;
                        opener.setAttribute('aria-expanded', askForm.hidden ? 'false' : 'true');
                        var first = askForm.hidden ? null : askForm.querySelector('.kop-ff-q, input:not([type=number]), select');
                        if (first) first.focus();
                    }
                }, [actionIcon(a.style), a.label]);
                if (a.help) askForm.appendChild(el('p', { class: 'rinbox-help', text: a.help }));
                var askFields = el('div', { class: 'rinbox-action-group' });
                (a.params || []).forEach(function (p, i) {
                    askFields.appendChild(paramLabel(p, paramInputs[i]));
                });
                askForm.appendChild(askFields);
                var submit = el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style === 'reject' ? 'reject' : 'approve'),
                    onclick: function () { go(submit); }
                }, [a.submit || a.label]);
                askForm.appendChild(el('div', { class: 'rinbox-actions' }, [submit, el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Cancel',
                    onclick: function () { askForm.hidden = true; opener.setAttribute('aria-expanded', 'false'); }
                })]));
                target.appendChild(opener);
                askForms.appendChild(askForm);
                return;
            }
            var btn = el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral') + (primary ? ' rinbox-btn-primary' : ''),
                title: a.help || null,
                onclick: function () { go(btn); }
            }, [actionIcon(a.style), a.label]);
            if (paramInputs.length) {
                var group = el('span', { class: 'rinbox-action-group' });
                (a.params || []).forEach(function (p, i) {
                    group.appendChild(paramLabel(p, paramInputs[i]));
                });
                group.appendChild(btn);
                target.appendChild(group);
            } else {
                target.appendChild(btn);
            }
            if (a.help && a.style !== 'undo') does.appendChild(el('li', { class: 'rinbox-does-' + (a.style || 'neutral') }, [el('strong', { text: a.label + ': ' }), a.help]));
        });
        bar.appendChild(researchButton(item, src, node));
        bar.appendChild(laterControl(item, src, node));
        // A card with Copy, or one whose queue asks for it (item.decide_first: Wiki updates), has its
        // buttons right under the title, before the lines and the whole-entry frame.
        if (decide.childNodes.length && (item.copy || item.decide_first)) {
            decide.classList.add('rinbox-decide-top');
            node.insertBefore(decide, head.nextSibling);
        } else if (decide.childNodes.length) node.appendChild(decide);
        if (askForms.childNodes.length) node.appendChild(askForms);
        if (does.childNodes.length) node.appendChild(does);
        node.appendChild(bar);
        // Filing details (category, tags, Move to) come after the decision.
        node.appendChild(quick);
        [decide, bar, askForms].forEach(function (box) {
            if (typeof window.kopFacilityFinderAttach === 'function') {
                box.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        });

        var msg = el('p', { class: 'rinbox-message' + (isError ? ' is-error' : ''), role: 'status', text: message || '' });
        if (!message) msg.hidden = true;
        node.appendChild(msg);
    }

    function run(node, item, s, src, kind, body, btn) {
        var buttons = node.querySelectorAll('button, select, input, textarea');
        buttons.forEach(function (b) { b.disabled = true; });
        if (btn) btn.classList.add('is-busy');
        var payload = Object.assign({ source: src, key: item.key }, body);
        var busy = node.querySelector('.rinbox-message');
        if (busy) { busy.hidden = false; busy.className = 'rinbox-message'; busy.textContent = kind === 'ai' ? (body && body.text ? 'Reading the pasted text…' : 'Reading the item and its link…') : 'Working…'; }
        return api(kind, payload).then(function (res) {
            var next = res.item || null;
            // A refilled card is often much shorter (an approved wiki update), and a card that left
            // shrinks to its message: keep it where the reader is instead of the cards below shooting up.
            keepInView(node, function () {
                if (next) {
                    fillCard(node, next, s, src, res.message);
                } else {
                    node.classList.add('rinbox-gone');
                    node.innerHTML = '';
                    node.appendChild(el('p', { class: 'rinbox-message', text: res.message || 'Done.' }));
                }
            });
            refreshCounts();
        }).catch(function (e) {
            fillCard(node, item, s, src, e.message, true);
        });
    }

    /**
     * item.sources [{n, title, url, jump, host, date, quote, reach}]: each source
     * with its quote in full, how much of it was read, and links that open the
     * page at the quote (jump: a text fragment) or show it beside the card.
     */
    function sourcesBox(sources, pane) {
        var list = el('ol', { class: 'rinbox-sources' });
        sources.forEach(function (s) {
            var head = el('div', { class: 'rinbox-source-head' });
            if (safeHref(s.jump || s.url)) {
                head.appendChild(el('a', { href: s.jump || s.url, target: '_blank', rel: 'noopener', text: s.title,
                    title: s.jump && s.jump !== s.url ? 'Opens the page at this quote' : s.url }));
                head.appendChild(previewButton(s.url, pane));
            } else {
                head.appendChild(el('span', { text: s.title }));
            }
            var li = el('li', { class: 'rinbox-source', value: String(s.n) }, [head,
                el('div', { class: 'rinbox-source-meta', text: [s.host, s.date, s.reach].filter(Boolean).join(' · ') })]);
            if (s.quote) li.appendChild(el('blockquote', { class: 'rinbox-source-quote', text: s.quote }));
            list.appendChild(li);
        });
        return el('section', { class: 'rinbox-sources-wrap', 'aria-label': 'Sources' }, [el('h4', { class: 'rinbox-sources-title', text: 'Sources' }), list]);
    }

    function fold(label, body) {
        return el('details', { class: 'rinbox-fold' }, [el('summary', { text: label }), body]);
    }

    function copyText(text, status) {
        function done(ok) { status.textContent = ok ? 'Copied.' : 'Could not copy: open "Show the whole entry" and copy it by hand.'; }
        function fallback() {
            var t = el('textarea', { readonly: true, 'aria-hidden': 'true', tabindex: '-1', style: 'position:absolute;left:-9999px' });
            t.value = text;
            document.body.appendChild(t);
            t.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            t.remove();
            done(ok);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(function () { done(true); }, fallback);
        else fallback();
    }

    function tagEditor(item, src, node) {
        var tags = (item.tags || []).slice();
        var wrap = el('div', { class: 'rinbox-tags' });
        var listId = 'rinbox-tag-list';
        if (!document.getElementById(listId)) {
            var dl = el('datalist', { id: listId });
            state.tags.forEach(function (t) { dl.appendChild(el('option', { value: t })); });
            document.body.appendChild(dl);
        }
        function hasTag(t) {
            return tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); });
        }
        function save() {
            api('tags', { source: src, key: item.key, tags: tags }).then(function (res) {
                tags = res.tags || tags;
                item.tags = tags;
                res.tags.forEach(function (t) { if (state.tags.indexOf(t) === -1) state.tags.push(t); });
                draw();
            }).catch(function (e) {
                var m = node.querySelector('.rinbox-message');
                if (m) { m.hidden = false; m.className = 'rinbox-message is-error'; m.textContent = 'Tags not saved: ' + e.message; }
            });
        }
        var input = el('input', { type: 'text', class: 'rinbox-tag-input', list: listId, placeholder: 'Add tag', 'aria-label': 'Add a tag' });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var t = input.value.trim();
                if (t && !hasTag(t)) { tags.push(t); save(); }
                input.value = '';
            } else if (e.key === 'Backspace' && input.value === '' && tags.length) {
                tags.pop();
                save();
            }
        });
        input.addEventListener('change', function () {
            var t = input.value.trim();
            if (t && state.tags.indexOf(t) !== -1 && !hasTag(t)) { tags.push(t); input.value = ''; save(); }
        });
        function draw() {
            wrap.innerHTML = '';
            wrap.appendChild(el('span', { class: 'rinbox-tags-label', text: 'Tags' }));
            tags.forEach(function (t) {
                wrap.appendChild(el('span', { class: 'rinbox-tag' }, [t, el('button', {
                    type: 'button', class: 'rinbox-tag-x', 'aria-label': 'Remove tag ' + t, text: '×',
                    onclick: function () { tags = tags.filter(function (x) { return x !== t; }); save(); }
                })]));
            });
            wrap.appendChild(input);
        }
        draw();
        return wrap;
    }

    var countTimer = null;
    function refreshCounts() {
        clearTimeout(countTimer);
        countTimer = setTimeout(function () {
            // The saved counts and Your inbox (quick), then a recount of only the queue just worked in:
            // recounting every queue after each click took up to half a minute.
            api('sources').then(function (data) {
                (data.sources || []).forEach(function (s) {
                    if (state.byKey[s.key]) state.byKey[s.key].count = s.count;
                });
                if (data.held) state.held = data.held;
                renderTabs();
                var here = state.source || (!state.special && typeInput ? typeInput.value : null);
                if (here && state.byKey[here]) recountQueues([here]);
            }).catch(function () { /* counts stay as they were */ });
        }, 800);
    }

    /* ---- The page's own cards (news, data, wiki, lawsuits, legislation) ---- */

    var typeInput = document.getElementById('typeFilter');
    var ownList = document.getElementById('submissionsList');
    var statusInput = document.getElementById('statusFilter');
    var originSelect = document.getElementById('originFilter');
    var originFor = '';

    /** Fill "Came from" for the page's own type and status, keeping the choice. */
    function refreshOwnOrigins(force) {
        if (!originSelect || !typeInput) return;
        var type = typeInput.value, status = statusInput ? statusInput.value : '';
        var s = state.byKey[type];
        var wanted = type + '|' + status;
        if (!force && wanted === originFor) return;
        originFor = wanted;
        if (!s || !s.has_origins) { fillOrigins(originSelect, [], ''); return; }
        api('origins', null, { source: type, status: status }).then(function (data) {
            fillOrigins(originSelect, data.origins || [], originSelect.value);
        }).catch(function () { /* the filter stays as it was */ });
    }
    if (originSelect) {
        originSelect.addEventListener('change', function () {
            var refresh = document.getElementById('refreshBtn');
            if (refresh) refresh.click();
        });
    }
    // A new type starts from "Everywhere" (capture: before the page's own tab handler loads the list).
    if (typeTabs) {
        typeTabs.addEventListener('click', function (e) {
            if (e.target.closest('[data-type]') && originSelect) { originSelect.value = ''; originFor = ''; }
        }, true);
    }

    function nativeQuickRow(card, item, src, message, isError) {
        var old = card.querySelector(':scope > .rinbox-native');
        var row = el('div', { class: 'rinbox-native' });
        var s = state.byKey[src] || { can_save: false, can_ai: false };
        var titleField = (item.fields || [])[0];
        var category = (item.fields || []).filter(function (f) { return f.category; })[0];
        var heading = card.querySelector('.submission-header h3');

        function after(res) {
            var next = res.item || item;
            if (heading && next.title && titleField) {
                // Keep the duplicate badge and other markup after the title text.
                if (heading.firstChild && heading.firstChild.nodeType === 3) heading.firstChild.nodeValue = next.title;
                else heading.insertBefore(document.createTextNode(next.title), heading.firstChild);
            }
            nativeQuickRow(card, next, src, res.message);
            // An open detail panel shows the old values: open it again, or
            // close it after an approve or reject.
            var view = card.querySelector('.btn-view');
            if (view && /hide/i.test(view.textContent)) {
                view.click();
                if (!res.kopDecided) setTimeout(function () { view.click(); }, 50);
            }
            refreshCounts();
            refreshOwnOrigins(true);
        }
        function go(kind, body, btn, decided) {
            row.querySelectorAll('button, select, input').forEach(function (b) { b.disabled = true; });
            if (btn) btn.classList.add('is-busy');
            msg.hidden = false;
            msg.className = 'rinbox-message';
            msg.textContent = kind === 'ai' ? (body && body.text ? 'Reading the pasted text and filling empty fields…' : 'Reading the article and filling empty fields…') : 'Working…';
            api(kind, Object.assign({ source: src, key: item.key }, body)).then(function (res) {
                if (decided) res.kopDecided = true;
                after(res);
            }).catch(function (e) {
                nativeQuickRow(card, item, src, e.message, true);
            });
        }

        if (titleField && s.can_save) {
            var nameInput = el('input', { type: 'text', class: 'rinbox-native-name', 'aria-label': titleField.label, value: titleField.value || '' });
            var nameForm = el('form', { class: 'rinbox-native-rename', hidden: true }, [
                nameInput, el('button', { type: 'submit', class: 'rinbox-btn rinbox-btn-neutral', text: 'Save name' })
            ]);
            nameForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fields = {};
                fields[titleField.name] = nameInput.value;
                go('save', { fields: fields });
            });
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Rename',
                onclick: function () { nameForm.hidden = !nameForm.hidden; if (!nameForm.hidden) nameInput.focus(); }
            }));
            row.appendChild(nameForm);
        }
        if (category && s.can_save) {
            var cat = fieldInput(category, 'rinbox-n' + item.key + '-');
            cat.addEventListener('change', function () {
                var fields = {};
                fields[category.name] = readInput(cat);
                go('save', { fields: fields });
            });
            row.appendChild(el(cat.dataset.kind === 'types' ? 'span' : 'label', { class: 'rinbox-quick-field' }, [category.label + ' ', cat]));
        }
        row.appendChild(tagEditor(item, src, row));
        var moveGo = function (params) { go('act', { action: 'move', params: params }); };
        var school = (item.moves || []).filter(function (m) { return m.id === 'indigenous'; })[0];
        if (school) row.appendChild(moveButton(school, 'Indian boarding school', 'rinbox-n' + item.key + '-', moveGo));
        var others = (item.moves || []).filter(function (m) { return m !== school; });
        if (others.length) {
            row.appendChild(moveControl(Object.assign({}, item, { moves: others }), 'rinbox-n' + item.key + '-', moveGo));
        }
        (item.actions || []).forEach(function (a) {
            if (a.params && a.params.length) {
                // An action that asks first (News: "Connect a record" with the record finder): a small form, as a move's.
                row.appendChild(moveButton({ id: a.id, label: a.submit || a.label, help: a.help, params: a.params }, a.label, 'rinbox-n' + item.key + '-', function (params) {
                    delete params.to;
                    go('act', { action: a.id, params: params });
                }));
                return;
            }
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral'), text: a.label,
                onclick: function (e) { go('act', { action: a.id, params: {} }, e.currentTarget, a.style === 'approve' || a.style === 'reject'); }
            }));
        });
        if (s.can_ai) {
            var nativeText = aiTextBox('rinbox-n' + item.key + '-');
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral rinbox-ai', text: 'Fill empty fields with AI',
                title: 'Reads the item and the page it links to (or the text pasted below), and fills only the fields that are empty',
                onclick: function (e) { go('ai', { text: nativeText.kopValue() }, e.currentTarget); }
            }));
            row.appendChild(nativeText);
        }
        row.appendChild(researchButton(item, src, row));
        row.appendChild(laterControl(item, src, row));
        row.kopAfterHold = function (res, next) {
            if (next && next.hold && next.hold.hidden && card.classList.contains('is-pending')) {
                card.hidden = true;
                updateHiddenNote(1);
            } else {
                nativeQuickRow(card, next || item, src, res.message);
            }
        };
        var msg = el('p', { class: 'rinbox-message' + (isError ? ' is-error' : ''), role: 'status', text: message || '' });
        if (!message) msg.hidden = true;
        row.appendChild(msg);

        // What the card is about and what Approve / Reject do, above its buttons.
        var oldEv = card.querySelector(':scope > .rinbox-native-evidence');
        var ev = el('div', { class: 'rinbox-native-evidence' });
        if (item.hold) ev.appendChild(holdNote(item, src, row));
        if (item.recs && item.recs.length) ev.appendChild(recNote(item));
        var pane = previewPane();
        if (safeHref(item.url)) {
            ev.appendChild(el('div', { class: 'rinbox-links' }, [el('span', { class: 'rinbox-link' }, [
                el('a', { href: item.url, target: '_blank', rel: 'noopener', title: item.url, text: linkLabel(item.url) }),
                previewButton(item.url, pane)
            ])]));
        }
        ev.appendChild(pane);
        if (card.classList.contains('is-pending') && (item.approve_help || item.reject_help)) {
            ev.appendChild(el('ul', { class: 'rinbox-does' }, [
                item.approve_help ? el('li', { class: 'rinbox-does-approve' }, [el('strong', { text: 'Approve: ' }), item.approve_help]) : null,
                item.reject_help ? el('li', { class: 'rinbox-does-reject' }, [el('strong', { text: 'Reject: ' }), item.reject_help]) : null
            ]));
        }

        var footer = card.querySelector(':scope > .submission-footer');
        if (oldEv) oldEv.replaceWith(ev);
        else if (footer) card.insertBefore(ev, footer);
        else card.appendChild(ev);
        if (old) old.replaceWith(row);
        else if (footer) card.insertBefore(row, footer);
        else card.appendChild(row);
    }

    /** "N set aside are hidden. Show them" over the page's own list. */
    var hiddenCount = 0;
    function updateHiddenNote(add) {
        hiddenCount += add;
        var note = document.getElementById('rinbox-hidden-note');
        if (!hiddenCount) { if (note) note.remove(); return; }
        if (!note) {
            note = el('p', { id: 'rinbox-hidden-note', class: 'rinbox-status' });
            ownList.parentNode.insertBefore(note, ownList);
        }
        note.innerHTML = '';
        note.appendChild(document.createTextNode(hiddenCount + (hiddenCount === 1 ? ' item is' : ' items are') + ' set aside (needs research, snoozed or handed to someone else) and not shown. '));
        note.appendChild(el('button', {
            type: 'button', class: 'rinbox-pv-btn', text: 'Show them',
            onclick: function () {
                ownList.querySelectorAll('.submission-card[hidden]').forEach(function (c) { c.hidden = false; });
                hiddenCount = 0;
                updateHiddenNote(0);
            }
        }));
    }

    var decorateTimer = null;
    function decorateNative() {
        if (!ownList || !typeInput) return;
        refreshOwnOrigins(false);
        var src = typeInput.value;
        if (!state.byKey[src] || !state.byKey[src].native) return;
        var cards = Array.prototype.filter.call(ownList.querySelectorAll('.submission-card[data-id]'), function (c) {
            return c.dataset.rinbox !== src + ':' + c.dataset.id;
        });
        if (!cards.length) return;
        cards.forEach(function (c) { c.dataset.rinbox = src + ':' + c.dataset.id; });
        var keys = cards.map(function (c) { return c.dataset.id; });
        api('items', null, { source: src, keys: keys.join(',') }).then(function (data) {
            var byKey = {};
            (data.items || []).forEach(function (it) { byKey[it.key] = it; });
            if (!ownList.querySelector('.submission-card[hidden]')) { hiddenCount = 0; updateHiddenNote(0); }
            var hide = 0;
            cards.forEach(function (c) {
                var it = byKey[c.dataset.id];
                if (!it || !c.isConnected) return;
                nativeQuickRow(c, it, src);
                if (it.hold && it.hold.hidden && c.classList.contains('is-pending')) { c.hidden = true; hide++; }
            });
            if (hide) updateHiddenNote(hide);
        }).catch(function () {
            cards.forEach(function (c) { delete c.dataset.rinbox; });
        });
    }
    if (ownList && window.MutationObserver) {
        new MutationObserver(function () {
            clearTimeout(decorateTimer);
            decorateTimer = setTimeout(decorateNative, 120);
        }).observe(ownList, { childList: true });
    }

    /* ---- Start ------------------------------------------------------------- */

    loadSources().then(function () {
        decorateNative();
        var wanted = null;
        try { wanted = new URL(window.location.href).searchParams.get('type'); } catch (e) { wanted = null; }
        if (wanted && (SPECIAL[wanted] || wanted === '_tools')) openSpecial(wanted);
        else if (wanted && state.byKey[wanted] && !state.byKey[wanted].native) openSource(wanted);
    });
})();
