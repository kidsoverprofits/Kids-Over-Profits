/**
 * /researcher-upload/ (templates/researcher-upload.php): sends every file in
 * pieces through kop/v1/researcher-upload/* (inc/researcher-documents.php),
 * so a file of any size goes up within the host's per-request limit. One
 * file at a time; a piece that fails is sent again (waiting longer each
 * time), and when the server holds a different amount than the page thinks
 * (409) the page picks up from there. The data form still posts plainly
 * without this script; the documents form needs it.
 */
(function () {
    'use strict';
    var cfg = window.KOP_RUP;
    if (!cfg || !window.fetch || !window.FormData) return;

    var busy = false;
    var JUNK = /^(\.ds_store|thumbs\.db|desktop\.ini|\._.*)$/i;

    function ext(name) {
        var m = /\.([^.\/]+)$/.exec(name || '');
        return m ? m[1].toLowerCase() : '';
    }

    function size(n) {
        if (n < 1024) return n + ' bytes';
        var u = ['KB', 'MB', 'GB', 'TB'];
        var i = -1;
        do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
        return (n >= 100 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
    }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }

    function wait(ms) {
        return new Promise(function (r) { setTimeout(r, ms); });
    }

    /** POST to a route; resolves {status, j} (j = parsed JSON or {}); rejects only when the network fails. */
    function api(route, fields, blob) {
        var fd = new FormData();
        fd.append('kop_rup_key', cfg.key);
        fd.append('kop_rup_check', cfg.check);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : String(fields[k])); });
        if (blob) fd.append('chunk', blob, 'piece.bin');
        return fetch(cfg.rest + route, { method: 'POST', body: fd, credentials: 'omit', cache: 'no-store' }).then(function (r) {
            return r.text().then(function (t) {
                var j = {};
                try { j = JSON.parse(t) || {}; } catch (e) { /* the host's own error page */ }
                return { status: r.status, j: j };
            });
        });
    }

    /** api() that tries again when the network or the server stumbles (not when the server says no). */
    function apiRetry(route, fields, blob, note) {
        var tries = 0;
        function go() {
            return api(route, fields, blob).then(function (res) {
                if (res.status >= 500 || res.status === 429 || res.status === 0) throw res;
                return res;
            }).catch(function (err) {
                tries++;
                if (tries > 8) throw new Error((err && err.j && err.j.message) || 'The connection keeps failing. Check your internet and send the file again.');
                var ms = Math.min(60000, 1000 * Math.pow(2, tries - 1));
                if (note) note('Connection trouble, trying again in ' + Math.round(ms / 1000) + ' s...');
                return wait(ms).then(go);
            });
        }
        return go();
    }

    function fail(res, fallback) {
        return new Error((res.j && res.j.message) || fallback || 'The server refused this file.');
    }

    /** Send one file; calls progress(sentBytes, message). Resolves {kind, id, message}. */
    function sendFile(item, meta, progress) {
        var file = item.file;
        var fields = { kind: meta.kind, name: file.name, size: file.size, note: meta.note || '', about: meta.about || '', source_url: item.source || meta.source_url || '', rel_path: item.path || '' };
        return apiRetry('start', fields, null).then(function (res) {
            if (res.status !== 200 || !res.j.upload) throw fail(res, 'The server would not start this file.');
            var upload = res.j.upload;
            var piece = Math.max(65536, parseInt(res.j.chunk, 10) || 1048576);
            var offset = 0;
            function next() {
                if (offset >= file.size) {
                    progress(file.size, 'Checking...');
                    return apiRetry('finish', { upload: upload }, null).then(function (r) {
                        if (r.status !== 200) throw fail(r);
                        return r.j;
                    });
                }
                var blob = file.slice(offset, Math.min(file.size, offset + piece));
                return apiRetry('chunk', { upload: upload, offset: offset }, blob, function (msg) { progress(offset, msg); }).then(function (r) {
                    if (r.status === 200 || r.status === 409) {
                        offset = parseInt(r.j.received, 10);
                        if (isNaN(offset)) throw fail(r);
                        progress(offset, '');
                        return next();
                    }
                    throw fail(r);
                });
            }
            return next().catch(function (e) {
                api('cancel', { upload: upload }).catch(function () {});
                throw e;
            });
        });
    }

    /** One list row per file: name, size, a bar and a status line; documents also get their own source box. */
    function row(list, item, withSource) {
        var li = el('li', 'kop-rup-item');
        var head = el('div', 'kop-rup-item-head');
        head.appendChild(el('strong', 'kop-rup-item-name', item.path || item.file.name));
        head.appendChild(el('span', 'kop-rup-item-size', size(item.file.size)));
        li.appendChild(head);
        if (withSource) {
            var lab = el('label', 'kop-rup-item-source');
            lab.appendChild(document.createTextNode('Source for this file (optional) '));
            var inp = el('input');
            inp.type = 'text';
            inp.maxLength = 1000;
            inp.setAttribute('inputmode', 'url');
            inp.addEventListener('input', function () { item.source = inp.value.trim(); });
            lab.appendChild(inp);
            li.appendChild(lab);
            var rm = el('button', 'kop-rup-item-remove', 'Remove');
            rm.type = 'button';
            rm.addEventListener('click', function () {
                if (busy) return;
                item.removed = true;
                li.parentNode.removeChild(li);
                list.dispatchEvent(new Event('kop-rup-change'));
            });
            head.appendChild(rm);
            item.controls = [inp, rm];
        }
        var bar = el('progress', 'kop-rup-bar');
        bar.max = item.file.size || 1;
        bar.value = 0;
        bar.hidden = true;
        li.appendChild(bar);
        var status = el('p', 'kop-rup-item-status');
        li.appendChild(status);
        list.appendChild(li);
        list.hidden = false;
        item.li = li;
        item.bar = bar;
        item.status = status;
        return li;
    }

    /** Send the items one after another, then one email to the editors. */
    function run(items, meta, button, list) {
        busy = true;
        button.disabled = true;
        var ids = { data: [], doc: [] };
        var good = 0;
        var bad = 0;
        var chain = Promise.resolve();
        items.forEach(function (item) {
            chain = chain.then(function () {
                if (item.removed || item.done) return;
                (item.controls || []).forEach(function (c) { c.disabled = true; });
                item.bar.hidden = false;
                item.status.textContent = 'Sending...';
                item.li.className = 'kop-rup-item kop-rup-sending';
                return sendFile(item, meta, function (sent, msg) {
                    item.bar.value = sent;
                    item.status.textContent = msg || (size(sent) + ' of ' + size(item.file.size));
                }).then(function (r) {
                    item.done = true;
                    good++;
                    if (r.id) ids[r.kind === 'doc' ? 'doc' : 'data'].push(r.id);
                    item.bar.value = item.bar.max;
                    item.status.textContent = r.message ? r.message.charAt(0).toUpperCase() + r.message.slice(1) + '.' : 'Received.';
                    item.li.className = 'kop-rup-item kop-rup-ok';
                }).catch(function (e) {
                    bad++;
                    item.status.textContent = 'Not sent. ' + (e && e.message ? e.message : '');
                    item.li.className = 'kop-rup-item kop-rup-bad';
                    (item.controls || []).forEach(function (c) { c.disabled = false; });
                });
            });
        });
        return chain.then(function () {
            busy = false;
            if (ids.data.length || ids.doc.length) {
                api('sent', { data: ids.data.join(','), docs: ids.doc.join(',') }).catch(function () {});
            }
            var done = el('li', 'kop-rup-item kop-rup-summary');
            done.textContent = good + ' sent' + (bad ? ', ' + bad + ' not sent (see above; press Send to try those again)' : '. Thank you.');
            if (good) {
                var a = el('a', '', 'Refresh the list of what you have sent');
                a.href = window.location.href.split('#')[0];
                done.appendChild(document.createTextNode(' '));
                done.appendChild(a);
            }
            list.appendChild(done);
            button.disabled = !bad;
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (!busy) return;
        e.preventDefault();
        e.returnValue = '';
    });

    /* ---- Data files: the plain form, sent in pieces ---- */
    var dataForm = document.getElementById('kop-rup-data-form');
    if (dataForm) {
        dataForm.addEventListener('submit', function (e) {
            var input = dataForm.querySelector('input[type="file"]');
            if (!input || !input.files || !input.files.length) return; // the browser says "choose a file"
            e.preventDefault();
            if (busy) return;
            var list = dataForm.querySelector('.kop-rup-queue');
            list.innerHTML = '';
            var items = [];
            Array.prototype.forEach.call(input.files, function (f) {
                var item = { file: f };
                row(list, item, false);
                if (cfg.dataTypes.indexOf(ext(f.name)) < 0) {
                    item.done = true;
                    item.status.textContent = 'Not sent: only .json, .csv, .tsv, .xlsx and .zip files go here. Documents go under "Send documents".';
                    item.li.className = 'kop-rup-item kop-rup-bad';
                    return;
                }
                items.push(item);
            });
            var note = dataForm.querySelector('textarea');
            run(items, { kind: 'data', note: note ? note.value.trim() : '' }, dataForm.querySelector('.kop-rup-send'), list).then(function () {
                input.value = '';
                dataForm.querySelector('.kop-rup-send').disabled = false;
            });
        });
    }

    /* ---- Documents: files, folders, drag and drop ---- */
    var docForm = document.getElementById('kop-rup-docs-form');
    if (!docForm) return;
    docForm.hidden = false;
    var docList = docForm.querySelector('.kop-rup-queue');
    var docButton = docForm.querySelector('.kop-rup-send');
    var docItems = [];
    var seen = {};

    function refresh() {
        var waiting = docItems.filter(function (i) { return !i.removed && !i.done; }).length;
        docButton.disabled = busy || !waiting;
        docButton.textContent = waiting ? 'Send ' + waiting + ' document' + (waiting === 1 ? '' : 's') : 'Send documents';
    }
    docList.addEventListener('kop-rup-change', refresh);

    function addFile(file, path) {
        var name = file.name || '';
        if (JUNK.test(name) || !file.size && !ext(name)) return;
        path = (path || '').replace(/^\/+/, '');
        var key = (path || name) + '|' + file.size + '|' + file.lastModified;
        if (seen[key]) return;
        seen[key] = true;
        var item = { file: file, path: path && path !== name ? path : '' };
        if (cfg.docTypes.indexOf(ext(name)) < 0) {
            row(docList, item, false);
            item.done = true;
            item.status.textContent = 'Not taken as a document (.' + (ext(name) || '?') + '). PDFs, Word files, spreadsheets, pictures, audio and video are.';
            item.li.className = 'kop-rup-item kop-rup-bad';
            return;
        }
        docItems.push(item);
        row(docList, item, true);
    }

    function addList(files) {
        Array.prototype.forEach.call(files || [], function (f) { addFile(f, f.webkitRelativePath || ''); });
        refresh();
    }

    ['kop-rup-doc-files', 'kop-rup-doc-folder'].forEach(function (id) {
        var input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', function () {
            addList(input.files);
            input.value = '';
        });
    });

    /** A dropped folder: read its entries, all the way down. */
    function walk(entry, prefix) {
        return new Promise(function (resolve) {
            if (entry.isFile) {
                entry.file(function (f) { addFile(f, prefix + f.name); resolve(); }, function () { resolve(); });
                return;
            }
            if (!entry.isDirectory) { resolve(); return; }
            var reader = entry.createReader();
            var all = [];
            (function more() {
                reader.readEntries(function (batch) {
                    if (!batch.length) {
                        Promise.all(all.map(function (e) { return walk(e, prefix + entry.name + '/'); })).then(resolve);
                        return;
                    }
                    all = all.concat(Array.prototype.slice.call(batch));
                    more();
                }, function () { resolve(); });
            })();
        });
    }

    var drop = docForm.querySelector('.kop-rup-drop');
    ['dragenter', 'dragover'].forEach(function (t) {
        drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('kop-rup-over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
        drop.addEventListener(t, function () { drop.classList.remove('kop-rup-over'); });
    });
    drop.addEventListener('drop', function (e) {
        e.preventDefault();
        if (busy) return;
        var dt = e.dataTransfer;
        var entries = [];
        if (dt.items && dt.items.length && dt.items[0].webkitGetAsEntry) {
            Array.prototype.forEach.call(dt.items, function (it) {
                var en = it.webkitGetAsEntry && it.webkitGetAsEntry();
                if (en) entries.push(en);
            });
        }
        if (!entries.length) { addList(dt.files); return; }
        Promise.all(entries.map(function (en) { return walk(en, ''); })).then(refresh);
    });

    docForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        var items = docItems.filter(function (i) { return !i.removed && !i.done; });
        if (!items.length) return;
        var meta = {
            kind: 'doc',
            about: docForm.querySelector('[name="about"]').value.trim(),
            source_url: docForm.querySelector('[name="source_url"]').value.trim(),
            note: docForm.querySelector('[name="note"]').value.trim()
        };
        run(items, meta, docButton, docList).then(refresh);
    });
})();
