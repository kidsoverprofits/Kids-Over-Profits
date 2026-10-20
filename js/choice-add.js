/**
 * "+ Add new..." as the last option of an admin dropdown fed by a registered list
 * (inc/choice-lists.php, KOP Tools > Dropdown Lists).
 *
 * A <select data-kop-choice="<list key>"> gets the option. Picking it opens a text box
 * (plus the list's extra fields) under the select; Add saves the choice permanently
 * (admin-ajax action kop_choice_add, logged and undoable on the Dropdown Lists screen),
 * puts it in the dropdown and selects it. The same choice is added to every other
 * dropdown of that list on the page. A select built by script is picked up when it
 * enters the page. data-kop-choice-by="label": the options' values are the labels.
 */
(function () {
    'use strict';
    var CFG = window.KOP_CHOICE_ADD;
    if (!CFG || !CFG.lists) return;
    var ADD = '__kop_add_new__';

    function el(tag, attrs, text) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
        if (text) n.textContent = text;
        return n;
    }

    function injectStyle() {
        if (document.getElementById('kop-choice-add-style')) return;
        var s = el('style', { id: 'kop-choice-add-style' });
        s.textContent = '.kop-ca-box{display:block;margin:6px 0;padding:8px 10px;background:#FFF5CB;border:1px solid #EF9034;border-radius:4px;color:#000435;max-width:420px;box-sizing:border-box}' +
            '.kop-ca-box label{display:block;margin:0 0 6px;font-size:13px;color:#000435}' +
            '.kop-ca-box input[type=text],.kop-ca-box textarea,.kop-ca-box select{display:block;width:100%;box-sizing:border-box;margin-top:2px}' +
            '.kop-ca-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}' +
            '.kop-ca-box button{cursor:pointer}' +
            '.kop-ca-err{color:#8a1c1c;font-size:13px;margin:4px 0 0}' +
            '.kop-ca-ok{color:#14532d;font-size:13px;margin:4px 0 0}';
        document.head.appendChild(s);
    }

    function listOf(sel) { return CFG.lists[sel.getAttribute('data-kop-choice')]; }

    function optionFor(sel, value, label) {
        var o = el('option', { value: value }, label);
        return o;
    }

    function insertChoice(sel, key, value, label, select) {
        var add = sel.querySelector('option[value="' + ADD + '"]');
        var exists = Array.prototype.some.call(sel.options, function (o) { return o.value === value; });
        if (!exists) {
            var o = optionFor(sel, value, label);
            sel.insertBefore(o, add);
        }
        if (select) {
            if (sel.multiple) {
                Array.prototype.forEach.call(sel.options, function (o) { if (o.value === value) o.selected = true; });
            } else {
                sel.value = value;
            }
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function remember(key, r) {
        // Scripts that read window.KOP_CHOICE_LISTS later see the new choice too.
        var all = window.KOP_CHOICE_LISTS;
        if (!all || !Array.isArray(all[key])) return;
        var plain = CFG.lists[key].plain;
        if (plain) { if (all[key].indexOf(r.label) < 0) all[key].push(r.label); return; }
        if (!all[key].some(function (x) { return x.value === r.value; })) {
            all[key].push(Object.assign({}, r.extra || {}, { value: r.value, label: r.label }));
        }
    }

    function closeBox(sel) {
        if (sel._kopCaBox && sel._kopCaBox.parentNode) sel._kopCaBox.parentNode.removeChild(sel._kopCaBox);
        sel._kopCaBox = null;
    }

    function openBox(sel) {
        var key = sel.getAttribute('data-kop-choice');
        var meta = listOf(sel);
        closeBox(sel);
        var box = el('div', { 'class': 'kop-ca-box', role: 'group', 'aria-label': 'Add a choice to ' + meta.label });
        var lab = el('label', {}, 'New choice for "' + meta.label + '"');
        var input = el('input', { type: 'text', maxlength: String(meta.max || 80), autocomplete: 'off' });
        lab.appendChild(input);
        box.appendChild(lab);

        var extra = {};
        meta.fields.forEach(function (f) {
            var l = el('label', {}, f.label + (f.required ? ' (required)' : ''));
            var c;
            if (f.type === 'textarea') { c = el('textarea', { rows: '2' }); }
            else if (f.type === 'select') {
                c = el('select');
                c.appendChild(el('option', { value: '' }, ''));
                f.options.forEach(function (o) { c.appendChild(el('option', { value: o.value }, o.label)); });
            } else if (f.type === 'check') { c = el('input', { type: 'checkbox', value: '1' }); }
            else { c = el('input', { type: 'text' }); }
            extra[f.name] = c;
            l.appendChild(c);
            if (f.help) l.appendChild(el('span', { style: 'display:block;font-size:12px;color:#4A5568' }, f.help));
            box.appendChild(l);
        });

        var row = el('div', { 'class': 'kop-ca-row' });
        var save = el('button', { type: 'button', 'class': 'button button-primary' }, 'Add');
        var cancel = el('button', { type: 'button', 'class': 'button' }, 'Cancel');
        row.appendChild(save);
        row.appendChild(cancel);
        box.appendChild(row);
        var msg = el('p', { 'class': 'kop-ca-err', role: 'status', hidden: 'hidden' });
        box.appendChild(msg);

        function say(text, ok) {
            msg.textContent = text;
            msg.className = ok ? 'kop-ca-ok' : 'kop-ca-err';
            msg.hidden = !text;
        }

        function submit() {
            var label = input.value.replace(/\s+/g, ' ').trim();
            if (!label) { say('Type the new choice first.', false); input.focus(); return; }
            var body = new URLSearchParams();
            body.set('action', 'kop_choice_add');
            body.set('nonce', CFG.nonce);
            body.set('list', key);
            body.set('label', label);
            meta.fields.forEach(function (f) {
                var c = extra[f.name];
                body.set('fields[' + f.name + ']', f.type === 'check' ? (c.checked ? '1' : '0') : c.value);
            });
            save.disabled = true;
            say('Saving...', true);
            fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    save.disabled = false;
                    if (!j || !j.success) { say((j && j.data && j.data.error) || 'Could not save that choice.', false); return; }
                    var r = j.data;
                    remember(key, r);
                    document.querySelectorAll('select[data-kop-choice="' + key + '"]').forEach(function (s) {
                        var sv = s.getAttribute('data-kop-choice-by') === 'label' || meta.plain ? r.label : r.value;
                        insertChoice(s, key, sv, r.label, s === sel);
                    });
                    closeBox(sel);
                    sel._kopPrev = sel.multiple ? '' : sel.value;
                })
                .catch(function () { save.disabled = false; say('Could not reach the server; nothing was saved.', false); });
        }

        save.addEventListener('click', submit);
        cancel.addEventListener('click', function () { closeBox(sel); sel.focus(); });
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') { e.preventDefault(); e.stopPropagation(); submit(); }
            else if (e.key === 'Escape') { e.stopPropagation(); closeBox(sel); sel.focus(); }
        });
        sel.parentNode.insertBefore(box, sel.nextSibling);
        sel._kopCaBox = box;
        input.focus();
    }

    function enhance(sel) {
        if (sel._kopCa || !listOf(sel)) return;
        sel._kopCa = true;
        var add = el('option', { value: ADD }, '+ Add new...');
        sel.appendChild(add);
        sel._kopPrev = sel.multiple ? '' : sel.value;
        // A script that redraws the options drops ours; put it back before the list opens.
        function keep() {
            if (!sel.querySelector('option[value="' + ADD + '"]')) { add = el('option', { value: ADD }, '+ Add new...'); sel.appendChild(add); }
        }
        sel.addEventListener('mousedown', keep);
        sel.addEventListener('focus', keep);
        sel.addEventListener('change', function (e) {
            keep();
            if (sel.value === ADD || (sel.multiple && add.selected)) {
                // The box is the answer to this pick; the select goes back to what it held.
                e.stopImmediatePropagation();
                if (sel.multiple) add.selected = false; else sel.value = sel._kopPrev;
                openBox(sel);
            } else if (!sel.multiple) {
                sel._kopPrev = sel.value;
            }
        }, true);
    }

    function scan(root) {
        (root || document).querySelectorAll('select[data-kop-choice]').forEach(enhance);
    }

    function start() {
        injectStyle();
        scan(document);
        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    m.addedNodes.forEach(function (n) {
                        if (n.nodeType !== 1) return;
                        if (n.matches && n.matches('select[data-kop-choice]')) enhance(n);
                        if (n.querySelectorAll) scan(n);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}());
