(function() {
    'use strict';

    const isLawsuitsPage = !!document.getElementById('lawsuitForm');
    const isLegislationPage = !!document.getElementById('legislationForm');

    if (!isLawsuitsPage && !isLegislationPage) return;

    const config = isLawsuitsPage
        ? { ...window.adminLawsuitsConfig, kind: 'lawsuit' }
        : { ...window.adminLegislationConfig, kind: 'legislation' };

    let folderCache = null;
    const loadFolders = async () => {
        if (folderCache) return folderCache;
        if (!config.foldersUrl) return [];
        try {
            const res = await fetch(config.foldersUrl, { credentials: 'same-origin' });
            const data = await res.json();
            folderCache = Array.isArray(data) ? data : [];
        } catch (err) {
            console.warn('Failed to load FileBird folders', err);
            folderCache = [];
        }
        return folderCache;
    };

    const buildFolderHierarchy = folders => {
        const byId = new Map(folders.map(f => [String(f.id), { ...f, children: [] }]));
        const roots = [];
        byId.forEach(f => {
            const parentId = String(f.parent || 0);
            if (parentId === '0' || !byId.has(parentId)) {
                roots.push(f);
            } else {
                byId.get(parentId).children.push(f);
            }
        });
        const flat = [];
        const walk = (nodes, depth) => {
            nodes
                .slice()
                .sort((a, b) => String(a.name).localeCompare(String(b.name)))
                .forEach(node => {
                    flat.push({ id: node.id, name: node.name, depth });
                    if (node.children.length) walk(node.children, depth + 1);
                });
        };
        walk(roots, 0);
        return flat;
    };

    const populateFolderSelect = async selectId => {
        const select = document.getElementById(selectId);
        if (!select) return;
        const folders = await loadFolders();
        const hierarchy = buildFolderHierarchy(folders);
        const currentValue = select.value;
        select.innerHTML = '<option value="">— No folder —</option>' +
            hierarchy.map(f => {
                const indent = '— '.repeat(f.depth);
                return `<option value="${f.id}">${indent}${escapeHtml(f.name)}</option>`;
            }).join('');
        if (currentValue) select.value = currentValue;
    };

    const linesToArray = value => {
        if (!value) return [];
        return String(value)
            .split(/\r?\n/)
            .map(s => s.trim())
            .filter(Boolean);
    };

    const arrayToLines = value => {
        if (!Array.isArray(value)) return '';
        return value.filter(Boolean).join('\n');
    };

    const setFieldValue = (id, value) => {
        const el = document.getElementById(id);
        if (!el) return;
        if (Array.isArray(value)) {
            el.value = arrayToLines(value);
        } else {
            el.value = value == null ? '' : value;
        }
    };

    const getFieldValue = id => {
        const el = document.getElementById(id);
        return el ? el.value : '';
    };

    // ---- Duplicate-URL guard ----------------------------------------------
    // Before an AI auto-populate or a save, ask the server whether an entry
    // already exists for the record's URL(s). The check endpoint lives next to
    // the save endpoint in /api. Fails open (never blocks) if it errors.
    const dupeCheckUrl = (config.apiUrl || '').replace(/[^/]*$/, 'check-duplicate-url.php');

    // `fields` maps a DB url column to its value(s), e.g.
    // { full_text_url: '…', official_url: '…' } or { source_urls: [...], document_urls: [...] }.
    // Each field is matched only against the same column server-side, so distinct
    // records that share a tracker/landing page aren't flagged as duplicates.
    async function findUrlDuplicates(fields, excludeId) {
        const clean = {};
        Object.entries(fields || {}).forEach(([col, val]) => {
            if (Array.isArray(val)) {
                const arr = val.map(u => (u || '').trim()).filter(Boolean);
                if (arr.length) clean[col] = arr;
            } else {
                const s = (val || '').trim();
                if (s) clean[col] = s;
            }
        });
        if (!Object.keys(clean).length || !dupeCheckUrl) return [];
        try {
            const res = await fetch(dupeCheckUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ type: config.kind, fields: clean, exclude_id: excludeId || 0 })
            });
            const data = await res.json();
            if (data && data.success) return data.duplicates || [];
        } catch (e) {
            console.warn('Duplicate URL check failed:', e);
        }
        return [];
    }

    function duplicateMessage(dupes) {
        const d = dupes[0] || {};
        const title = d.title ? `\n\nExisting entry: "${d.title}"` : '';
        return 'This already appears to be in our records, so it was not added again.'
            + title
            + '\n\nThank you for helping! There is nothing more you need to do.';
    }

    /** Returns true and shows a popup if any field's URL already exists. */
    async function blockedByDuplicate(fields, excludeId) {
        const dupes = await findUrlDuplicates(fields, excludeId);
        if (dupes.length) {
            window.alert(duplicateMessage(dupes));
            return true;
        }
        return false;
    }

    // ---- Lawsuits ----
    if (isLawsuitsPage) {
        const form = document.getElementById('lawsuitForm');
        const list = document.getElementById('lawsuitList');
        const status = document.getElementById('lawsuitFormStatus');
        const resetBtn = document.getElementById('lawsuitResetBtn');
        const newBtn = document.getElementById('newLawsuitBtn');
        const reloadBtn = document.getElementById('reloadList');
        const filterJurisdiction = document.getElementById('filterJurisdiction');
        const filterPubStatus = document.getElementById('filterPublicationStatus');
        const fetchLawsuitBtn = document.getElementById('fetchLawsuitBtn');
        const extractLawsuitBtn = document.getElementById('extractLawsuitBtn');
        const complaintFileInput = document.getElementById('lawsuit-complaint-file');

        const fieldMap = {
            id: 'lawsuit-id',
            case_name: 'lawsuit-case-name',
            case_number: 'lawsuit-case-number',
            court: 'lawsuit-court',
            jurisdiction: 'lawsuit-jurisdiction',
            filing_date: 'lawsuit-filing-date',
            status: 'lawsuit-status',
            publication_status: 'lawsuit-pub-status',
            outcome: 'lawsuit-outcome',
            settlement_amount: 'lawsuit-settlement',
            summary: 'lawsuit-summary',
            filebird_folder_id: 'lawsuit-filebird-folder',
            reviewer_notes: 'lawsuit-reviewer-notes'
        };
        const arrayFieldMap = {
            plaintiffs: 'lawsuit-plaintiffs',
            defendants: 'lawsuit-defendants',
            facilities_mentioned: 'lawsuit-facilities',
            staff_mentioned: 'lawsuit-staff',
            organizations_mentioned: 'lawsuit-orgs',
            claims: 'lawsuit-claims',
            source_urls: 'lawsuit-sources',
            document_urls: 'lawsuit-docs',
            tags: 'lawsuit-tags'
        };

        const resetForm = () => {
            form.reset();
            document.getElementById('lawsuit-id').value = '';
            status.textContent = '';
        };

        const populateForm = record => {
            Object.entries(fieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            Object.entries(arrayFieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
        };

        const collectPayload = () => {
            const payload = {};
            Object.entries(fieldMap).forEach(([key, id]) => {
                payload[key] = getFieldValue(id);
            });
            if (payload.id === '') delete payload.id;
            else payload.id = parseInt(payload.id, 10);
            Object.entries(arrayFieldMap).forEach(([key, id]) => {
                payload[key] = linesToArray(getFieldValue(id));
            });
            return payload;
        };

        const renderList = records => {
            if (!records || records.length === 0) {
                list.innerHTML = '<li class="empty">No records found.</li>';
                return;
            }
            list.innerHTML = records.map(rec => `
                <li class="record-row" data-id="${rec.id}">
                    <div class="record-row-main">
                        <strong>${escapeHtml(rec.case_name || '(no name)')}</strong>
                        <span class="record-meta">${escapeHtml(rec.jurisdiction || '—')} · ${escapeHtml(rec.publication_status)} · ${escapeHtml(rec.filing_date || '')}</span>
                    </div>
                    <button type="button" class="edit-btn" data-id="${rec.id}">Edit</button>
                </li>
            `).join('');
            list.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => loadRecord(parseInt(btn.dataset.id, 10)));
            });
        };

        const loadList = async () => {
            const params = new URLSearchParams();
            if (filterJurisdiction.value) params.set('jurisdiction', filterJurisdiction.value);
            if (filterPubStatus.value) params.set('publication_status', filterPubStatus.value);
            params.set('limit', '100');
            const res = await fetch(`${config.apiUrl}?${params.toString()}`, { credentials: 'same-origin' });
            const data = await res.json();
            renderList(data.success ? data.data : []);
        };

        const loadRecord = async id => {
            const res = await fetch(`${config.apiUrl}?id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                status.textContent = 'Failed to load record.';
                return;
            }
            populateForm(data.data);
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        };

        // ----- Paste raw JSON from external AI --------------------------------
        const pasteJsonBtn    = document.getElementById('pasteJsonBtn');
        const pasteJsonPanel  = document.getElementById('pasteJsonPanel');
        const pasteJsonInput  = document.getElementById('pasteJsonInput');
        const pasteJsonApply  = document.getElementById('pasteJsonApplyBtn');
        const pasteJsonCancel = document.getElementById('pasteJsonCancelBtn');
        const pasteJsonStatus = document.getElementById('pasteJsonStatus');

        const EMPTY_LAWSUIT_JSON = JSON.stringify({
            case_name: "",
            case_number: "",
            court: "",
            jurisdiction: "",
            filing_date: "",
            status: "",
            outcome: "",
            settlement_amount: "",
            summary: "",
            plaintiffs: [],
            defendants: [],
            facilities_mentioned: [],
            staff_mentioned: [],
            organizations_mentioned: [],
            claims: [],
            source_urls: [],
            document_urls: [],
            tags: []
        }, null, 2);

        if (pasteJsonBtn && pasteJsonPanel) {
            pasteJsonBtn.addEventListener('click', () => {
                pasteJsonPanel.hidden = !pasteJsonPanel.hidden;
                if (!pasteJsonPanel.hidden) {
                    if (!pasteJsonInput.value.trim()) pasteJsonInput.value = EMPTY_LAWSUIT_JSON;
                    pasteJsonInput.focus();
                    pasteJsonInput.select();
                }
            });

            pasteJsonCancel.addEventListener('click', () => {
                pasteJsonPanel.hidden = true;
                pasteJsonInput.value = '';
                pasteJsonStatus.textContent = '';
            });

            pasteJsonApply.addEventListener('click', () => {
                pasteJsonStatus.textContent = '';
                pasteJsonStatus.style.color = '';
                let raw = pasteJsonInput.value.trim();

                // Strip markdown fences in case the AI wrapped it in ```json … ```
                raw = raw.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');

                let parsed;
                try {
                    parsed = JSON.parse(raw);
                } catch (e) {
                    pasteJsonStatus.textContent = 'Invalid JSON: ' + e.message;
                    pasteJsonStatus.style.color = '#dc2626';
                    return;
                }

                // Coerce array fields that the AI might have returned as strings
                const arrayFields = ['plaintiffs','defendants','facilities_mentioned',
                    'staff_mentioned','organizations_mentioned','claims',
                    'source_urls','document_urls','tags'];
                arrayFields.forEach(k => {
                    if (parsed[k] && typeof parsed[k] === 'string') {
                        parsed[k] = parsed[k].split(/\n|,\s*/).map(s => s.trim()).filter(Boolean);
                    }
                });

                // Merge document_urls additively
                const existingDocs = linesToArray(getFieldValue('lawsuit-docs'));
                const newDocs = Array.isArray(parsed.document_urls) ? parsed.document_urls : [];
                parsed.document_urls = Array.from(new Set([...existingDocs, ...newDocs]));

                populateForm(parsed);
                pasteJsonStatus.textContent = 'Applied. Review fields and save.';
                pasteJsonPanel.hidden = true;
                pasteJsonInput.value = '';
            });
        }

        if (fetchLawsuitBtn) {
            fetchLawsuitBtn.addEventListener('click', async () => {
                const caseNumber = getFieldValue('lawsuit-case-number');
                const jurisdiction = getFieldValue('lawsuit-jurisdiction');
                const court = getFieldValue('lawsuit-court');

                if (!caseNumber || !jurisdiction) {
                    status.textContent = 'Please enter a case number and jurisdiction first.';
                    status.style.color = '#dc2626';
                    return;
                }

                if (await blockedByDuplicate(
                    {
                        source_urls:   linesToArray(getFieldValue('lawsuit-sources')),
                        document_urls: linesToArray(getFieldValue('lawsuit-docs')),
                    },
                    parseInt(getFieldValue('lawsuit-id'), 10) || 0
                )) return;

                fetchLawsuitBtn.disabled = true;
                const originalHTML = fetchLawsuitBtn.innerHTML;
                fetchLawsuitBtn.innerHTML = kopIcon('sparkles') + ' Fetching...';
                status.textContent = 'Fetching lawsuit details...';
                status.style.color = '';

                try {
                    const params = new URLSearchParams({
                        case_number: caseNumber,
                        jurisdiction: jurisdiction,
                        court: court
                    });
                    const res = await fetch(`${config.fetchApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                    const data = await res.json();

                    if (data.success) {
                        populateForm(data.data);
                        status.textContent = 'Lawsuit details populated.';
                    } else {
                        status.textContent = 'Fetch failed: ' + (data.error || 'Check case number and jurisdiction.');
                        status.style.color = '#dc2626';
                    }
                } catch (err) {
                    console.error('Fetch error:', err);
                    status.textContent = 'Error connecting to fetch API.';
                    status.style.color = '#dc2626';
                } finally {
                    fetchLawsuitBtn.disabled = false;
                    fetchLawsuitBtn.innerHTML = originalHTML;
                }
            });
        }

        // ----- Court document (upload or pasted text) + AI extraction ---------
        // Any paper from the case: complaint, motion, order, settlement, docket.
        // The button opens the hidden file picker; "Paste text" sends the text
        // instead (nothing goes to the media library). Results fill only empty
        // fields and add to the lists, so reading a later order into a saved
        // case never wipes what is there (or its id).
        const pasteTextBtn    = document.getElementById('pasteLawsuitTextBtn');
        const pasteTextPanel  = document.getElementById('pasteLawsuitTextPanel');
        const pasteTextInput  = document.getElementById('pasteLawsuitTextInput');
        const pasteTextApply  = document.getElementById('pasteLawsuitTextApplyBtn');
        const pasteTextCancel = document.getElementById('pasteLawsuitTextCancelBtn');

        const applyExtraction = data => {
            const filled = [];
            Object.entries(fieldMap).forEach(([key, id]) => {
                if (key === 'id' || key === 'publication_status') return;
                const v = data[key] == null ? '' : String(data[key]).trim();
                const cur = getFieldValue(id).trim();
                // A case status beyond 'filed' (the form's default) is never written over.
                if (v === '' || (cur !== '' && !(key === 'status' && (cur === 'unknown' || cur === 'filed')))) return;
                setFieldValue(id, v);
                filled.push(key);
            });
            Object.entries(arrayFieldMap).forEach(([key, id]) => {
                const extra = Array.isArray(data[key]) ? data[key].map(x => String(x).trim()).filter(Boolean) : [];
                const cur = linesToArray(getFieldValue(id));
                const merged = Array.from(new Set([...cur, ...extra]));
                if (merged.length === cur.length) return;
                setFieldValue(id, merged);
                filled.push(key);
            });
            return filled;
        };

        const runExtraction = async ({ file = null, text = '' }, btn) => {
            const folderId = getFieldValue('lawsuit-filebird-folder');
            btn.disabled = true;
            if (extractLawsuitBtn) extractLawsuitBtn.disabled = true;
            const originalHTML = btn.innerHTML;
            status.style.color = '';

            const postJson = body => fetch(config.extractApiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
                signal: AbortSignal.timeout(90 * 1000)
            });

            try {
                // Step 1: send the file or the text, get job_id + total_chunks
                btn.innerHTML = kopIcon('file-text') + (file ? ' Uploading…' : ' Sending…');
                status.textContent = file ? `Uploading "${file.name}"…` : 'Sending the pasted text…';

                const uploadForm = new FormData();
                uploadForm.append('action', 'upload');
                if (file) {
                    uploadForm.append('complaint', file);
                    if (folderId) uploadForm.append('filebird_folder_id', folderId);
                } else {
                    uploadForm.append('text', text);
                }

                const uploadRes = await fetch(config.extractApiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: uploadForm,
                    signal: AbortSignal.timeout(90 * 1000)
                });
                const uploadData = await uploadRes.json();
                if (!uploadData.success) {
                    throw new Error(uploadData.error || 'Upload failed');
                }

                const { job_id, total_chunks } = uploadData;
                const waitMs = uploadData.wait_ms || 65000;

                // Step 2: read each part, waiting between requests to stay within
                // the free tiers' per-minute limits (the server halves the wait
                // when Groq and Gemini take turns).
                for (let i = 0; i < total_chunks; i++) {
                    if (i > 0) {
                        btn.textContent = `Waiting… (${i}/${total_chunks})`;
                        btn.insertAdjacentHTML('afterbegin', kopIcon('file-text') + ' ');
                        status.textContent = `Part ${i} of ${total_chunks} read. Waiting ${Math.round(waitMs / 1000)}s before the next…`;
                        await new Promise(resolve => setTimeout(resolve, waitMs));
                    }

                    btn.textContent = `Part ${i + 1}/${total_chunks}…`;
                    btn.insertAdjacentHTML('afterbegin', kopIcon('file-text') + ' ');
                    status.textContent = `Reading part ${i + 1} of ${total_chunks}…`;

                    const chunkData = await postJson({ action: 'chunk', job_id, chunk_index: i });
                    const chunk = await chunkData.json();
                    if (!chunk.success) {
                        throw new Error(chunk.error || `Part ${i + 1} failed`);
                    }
                }

                // Step 3: merge the parts (and file an uploaded document)
                btn.innerHTML = kopIcon('file-text') + ' Finishing…';
                status.textContent = 'Merging what was read…';

                const finalRes = await postJson({ action: 'finalize', job_id });
                const finalData = await finalRes.json();
                if (!finalData.success) {
                    throw new Error(finalData.error || 'Finalize failed');
                }

                const filled = applyExtraction(finalData.data || {});
                status.textContent = filled.length
                    ? `Read. Filled: ${filled.map(k => k.replace(/_/g, ' ')).join(', ')}. Fields that already had something were left as they were. Review and save.`
                    : 'Read. Nothing new came back; the fields are unchanged.';
                return true;
            } catch (err) {
                console.error('Extract error:', err);
                status.textContent = 'Reading the document failed: ' + err.message;
                status.style.color = '#dc2626';
                return false;
            } finally {
                btn.disabled = false;
                if (extractLawsuitBtn) extractLawsuitBtn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        };

        if (extractLawsuitBtn && complaintFileInput && config.extractApiUrl) {
            extractLawsuitBtn.addEventListener('click', () => {
                if (extractLawsuitBtn.disabled) return;
                complaintFileInput.value = '';  // reset so re-uploading the same file fires `change`
                complaintFileInput.click();
            });

            complaintFileInput.addEventListener('change', () => {
                const file = complaintFileInput.files && complaintFileInput.files[0];
                if (file) runExtraction({ file }, extractLawsuitBtn);
            });
        }

        if (pasteTextBtn && pasteTextPanel && config.extractApiUrl) {
            pasteTextBtn.addEventListener('click', () => {
                pasteTextPanel.hidden = !pasteTextPanel.hidden;
                if (!pasteTextPanel.hidden) pasteTextInput.focus();
            });
            pasteTextCancel.addEventListener('click', () => {
                pasteTextPanel.hidden = true;
                pasteTextInput.value = '';
            });
            pasteTextApply.addEventListener('click', async () => {
                const text = pasteTextInput.value.trim();
                if (!text) { pasteTextInput.focus(); return; }
                if (await runExtraction({ text }, pasteTextApply)) {
                    pasteTextPanel.hidden = true;
                    pasteTextInput.value = '';
                }
            });
        }

        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (await blockedByDuplicate(
                {
                    source_urls:   linesToArray(getFieldValue('lawsuit-sources')),
                    document_urls: linesToArray(getFieldValue('lawsuit-docs')),
                },
                parseInt(getFieldValue('lawsuit-id'), 10) || 0
            )) { status.textContent = ''; return; }
            status.textContent = 'Saving...';
            const payload = collectPayload();
            const res = await fetch(config.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                status.textContent = data.created ? `Saved (id ${data.id})` : 'Updated.';
                document.getElementById('lawsuit-id').value = data.id;
                loadList();
            } else {
                status.textContent = 'Save failed: ' + (data.error || 'unknown');
            }
        });

        resetBtn.addEventListener('click', resetForm);
        newBtn.addEventListener('click', resetForm);
        reloadBtn.addEventListener('click', loadList);
        filterJurisdiction.addEventListener('change', loadList);
        filterPubStatus.addEventListener('change', loadList);
        populateFolderSelect('lawsuit-filebird-folder');
        loadList();
    }

    // ---- Legislation ----
    if (isLegislationPage) {
        const form = document.getElementById('legislationForm');
        const list = document.getElementById('legislationList');
        const status = document.getElementById('legislationFormStatus');
        const resetBtn = document.getElementById('legislationResetBtn');
        const newBtn = document.getElementById('newLegislationBtn');
        const reloadBtn = document.getElementById('reloadList');
        const filterLevel = document.getElementById('filterLevel');
        const filterJurisdiction = document.getElementById('filterJurisdiction');
        const filterPubStatus = document.getElementById('filterPublicationStatus');
        const fetchBtn = document.getElementById('fetchLegislationBtn');

        const fieldMap = {
            id: 'leg-id',
            bill_number: 'leg-bill-number',
            bill_type: 'leg-bill-type',
            bill_title: 'leg-bill-title',
            jurisdiction: 'leg-jurisdiction',
            chamber: 'leg-chamber',
            session_year: 'leg-session-year',
            status: 'leg-status',
            introduced_date: 'leg-introduced-date',
            last_action_date: 'leg-last-action-date',
            last_action_text: 'leg-last-action-text',
            summary: 'leg-summary',
            full_text_url: 'leg-full-text-url',
            official_url: 'leg-official-url',
            position: 'leg-position',
            publication_status: 'leg-pub-status',
            filebird_folder_id: 'leg-filebird-folder',
            reviewer_notes: 'leg-reviewer-notes'
        };
        const arrayFieldMap = {
            sponsors: 'leg-sponsors',
            subject_tags: 'leg-subject-tags',
            facilities_affected: 'leg-facilities-affected',
            tags: 'leg-tags'
        };

        const resetForm = () => {
            form.reset();
            document.getElementById('leg-id').value = '';
            status.textContent = '';
        };

        const populateSelectOptions = (selectId, options, currentValue) => {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const desired = currentValue !== undefined && currentValue !== null && currentValue !== ''
                ? String(currentValue)
                : sel.value;
            sel.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '—';
            sel.appendChild(placeholder);
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.label;
                sel.appendChild(o);
            });
            // If the desired value isn't in the list, append it so existing records still display.
            if (desired && !Array.from(sel.options).some(o => o.value === desired)) {
                const extra = document.createElement('option');
                extra.value = desired;
                extra.textContent = desired + ' (custom)';
                sel.appendChild(extra);
            }
            sel.value = desired || '';
        };

        const loadJurisdictionMeta = async (jurisdiction, level, preserveValues = {}) => {
            const billTypeSel = document.getElementById('leg-bill-type');
            const sessionSel  = document.getElementById('leg-session-year');
            if (!jurisdiction) {
                if (billTypeSel) billTypeSel.innerHTML = '<option value="">— pick jurisdiction first —</option>';
                if (sessionSel)  sessionSel.innerHTML  = '<option value="">— pick jurisdiction first —</option>';
                return;
            }
            try {
                const params = new URLSearchParams({ jurisdiction, level: level || 'state' });
                const res = await fetch(`${config.metaApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                const data = await res.json();
                const billTypes = (data.bill_types || []).map(bt => ({ value: bt, label: bt }));
                const sessions  = (data.sessions  || []).map(s  => ({ value: s.identifier, label: s.name || s.identifier }));
                populateSelectOptions('leg-bill-type', billTypes, preserveValues.bill_type);
                populateSelectOptions('leg-session-year', sessions, preserveValues.session_year);
            } catch (err) {
                console.error('Meta fetch failed:', err);
            }
        };

        const populateForm = record => {
            Object.entries(fieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            Object.entries(arrayFieldMap).forEach(([key, id]) => setFieldValue(id, record[key]));
            // Refresh per-jurisdiction dropdowns so saved session/bill_type render correctly.
            if (record.jurisdiction) {
                const level = record.jurisdiction === 'Federal' ? 'federal' : 'state';
                loadJurisdictionMeta(record.jurisdiction, level, {
                    bill_type:    record.bill_type,
                    session_year: record.session_year,
                });
            }
        };

        const collectPayload = () => {
            const payload = {};
            Object.entries(fieldMap).forEach(([key, id]) => {
                payload[key] = getFieldValue(id);
            });
            if (payload.id === '') delete payload.id;
            else payload.id = parseInt(payload.id, 10);
            Object.entries(arrayFieldMap).forEach(([key, id]) => {
                payload[key] = linesToArray(getFieldValue(id));
            });
            return payload;
        };

        const renderList = records => {
            if (!records || records.length === 0) {
                list.innerHTML = '<li class="empty">No records found.</li>';
                return;
            }
            list.innerHTML = records.map(rec => `
                <li class="record-row" data-id="${rec.id}">
                    <div class="record-row-main">
                        <strong>${escapeHtml(rec.bill_number || '')} ${escapeHtml(rec.bill_title || '')}</strong>
                        <span class="record-meta">${escapeHtml(rec.jurisdiction || '—')} · ${escapeHtml(rec.publication_status)} · ${escapeHtml(rec.status)}</span>
                    </div>
                    <button type="button" class="edit-btn" data-id="${rec.id}">Edit</button>
                </li>
            `).join('');
            list.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => loadRecord(parseInt(btn.dataset.id, 10)));
            });
        };

        const loadList = async () => {
            const params = new URLSearchParams();
            if (filterLevel && filterLevel.value) params.set('level', filterLevel.value);
            if (filterJurisdiction.value) params.set('jurisdiction', filterJurisdiction.value);
            if (filterPubStatus.value) params.set('publication_status', filterPubStatus.value);
            params.set('limit', '100');
            const res = await fetch(`${config.apiUrl}?${params.toString()}`, { credentials: 'same-origin' });
            const data = await res.json();
            renderList(data.success ? data.data : []);
        };

        const loadRecord = async id => {
            const res = await fetch(`${config.apiUrl}?id=${id}`, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success) {
                status.textContent = 'Failed to load record.';
                return;
            }
            populateForm(data.data);
            window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
        };

        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (await blockedByDuplicate(
                { full_text_url: getFieldValue('leg-full-text-url') },
                parseInt(getFieldValue('leg-id'), 10) || 0
            )) { status.textContent = ''; return; }
            status.textContent = 'Saving...';
            const payload = collectPayload();
            const res = await fetch(config.apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                status.textContent = data.created ? `Saved (id ${data.id})` : 'Updated.';
                document.getElementById('leg-id').value = data.id;
                loadList();
            } else {
                status.textContent = 'Save failed: ' + (data.error || 'unknown');
            }
        });

        // Refresh per-jurisdiction dropdowns whenever the user changes jurisdiction or level.
        const jurisdictionSel = document.getElementById('leg-jurisdiction');
        const levelSel = document.getElementById('leg-level');
        const refreshMeta = () => {
            const j = getFieldValue('leg-jurisdiction');
            let lv = getFieldValue('leg-level');
            if (j === 'Federal') lv = 'federal';
            loadJurisdictionMeta(j, lv);
        };
        if (jurisdictionSel) jurisdictionSel.addEventListener('change', refreshMeta);
        if (levelSel) levelSel.addEventListener('change', refreshMeta);

        if (fetchBtn) {
            fetchBtn.addEventListener('click', async () => {
                const rawNumber  = getFieldValue('leg-bill-number').trim();
                const billType   = getFieldValue('leg-bill-type').trim();
                const jurisdiction = getFieldValue('leg-jurisdiction');
                const session    = getFieldValue('leg-session-year');
                let level        = getFieldValue('leg-level');
                if (jurisdiction === 'Federal') level = 'federal';

                if (!rawNumber || !jurisdiction) {
                    status.textContent = 'Please enter a bill number and jurisdiction first.';
                    status.style.color = '#dc2626';
                    return;
                }

                if (await blockedByDuplicate(
                    { full_text_url: getFieldValue('leg-full-text-url') },
                    parseInt(getFieldValue('leg-id'), 10) || 0
                )) return;

                // Combine bill type + number if the user gave them separately
                // (e.g. type "SB" + number "1190" -> "SB 1190"). If they already
                // typed a prefix into the number field, leave it alone.
                const hasPrefix = /^[a-zA-Z]/.test(rawNumber);
                const combinedNumber = (billType && !hasPrefix)
                    ? `${billType} ${rawNumber}`
                    : rawNumber;

                fetchBtn.disabled = true;
                const originalHTML = fetchBtn.innerHTML;
                fetchBtn.innerHTML = kopIcon('sparkles') + ' Fetching...';
                status.textContent = 'Fetching bill details...';
                status.style.color = '';

                try {
                    const params = new URLSearchParams({
                        bill_number: combinedNumber,
                        jurisdiction: jurisdiction,
                        level: level
                    });
                    if (session) params.set('session', session);
                    const res = await fetch(`${config.fetchApiUrl}?${params.toString()}`, { credentials: 'same-origin' });
                    const data = await res.json();

                    if (data.success) {
                        populateForm(data.data);
                        status.textContent = 'Bill details populated.';
                    } else {
                        status.textContent = 'Fetch failed: ' + (data.error || 'Check bill number and jurisdiction.');
                        status.style.color = '#dc2626';
                    }
                } catch (err) {
                    console.error('Fetch error:', err);
                    status.textContent = 'Error connecting to fetch API.';
                    status.style.color = '#dc2626';
                } finally {
                    fetchBtn.disabled = false;
                    fetchBtn.innerHTML = originalHTML;
                }
            });
        }

        resetBtn.addEventListener('click', resetForm);
        newBtn.addEventListener('click', resetForm);
        reloadBtn.addEventListener('click', loadList);
        if (filterLevel) filterLevel.addEventListener('change', loadList);
        filterJurisdiction.addEventListener('change', loadList);
        filterPubStatus.addEventListener('change', loadList);
        populateFolderSelect('leg-filebird-folder');
        loadList();
    }

    function escapeHtml(value) {
        if (value == null) return '';
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    }
})();
