<?php
/**
 * Facility finder for admin screens: nobody should have to know a facility id.
 *
 * Any admin form that needs a facilities_v2 id prints its box with
 * kop_facility_finder_field(). The box stays (a known id can still be typed),
 * and a "Find by name" search sits next to it: type part of a name, a past
 * name or an id, pick the facility from the list, and its id fills the box
 * (firing "change", so screens that react to the box keep working).
 *
 * Search: kop_facility_finder_search() (plain PDO, so
 * scripts/test-facility-finder.php runs it against tmp/prod.sqlite).
 * Endpoint: admin-ajax.php?action=kop_facility_finder (manage_options + nonce).
 *
 * Any record, not only facilities: a box with data-kop-record-kinds="all" (or
 * a list, "company" or "company,consultant") lists those kinds and fills the
 * box with "<kind>:<id>" (a facility stays its bare id). With
 * data-kop-record-create="company" the list ends with "New company named
 * <what was typed>", which fills the box with "new:company:<name>" for
 * kop_record_field_resolve() (inc/record-field.php) to make when the action runs.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facilities matching $q: by id (digits), current name, unique name, or a
 * past/other name from json_data identification. Best matches first.
 * Each row: id, name, city, state, country, status, start_year, end_year,
 * matched (the past/other/current name that matched, or ''), matched_kind
 * ('past', 'other', 'current' or '').
 */
function kop_facility_finder_search(PDO $pdo, $q, $limit = 12) {
    $q = trim((string) $q);
    if ($q === '') {
        return array();
    }
    $limit = max(1, min(30, (int) $limit));
    $cols = 'id, name, unique_name, city, state, country, status, start_year, end_year';
    $found = array();

    if (preg_match('/^#?(\d+)$/', $q, $m)) {
        $stmt = $pdo->prepare("SELECT $cols FROM facilities_v2 WHERE id = ?");
        $stmt->execute(array((int) $m[1]));
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['matched'] = '';
            $row['matched_kind'] = '';
            $row['rank'] = -1;
            $found[(int) $row['id']] = $row;
        }
    }

    $like = '%' . str_replace(array('!', '%', '_'), array('!!', '!%', '!_'), $q) . '%';
    $stmt = $pdo->prepare("SELECT $cols FROM facilities_v2
                            WHERE name LIKE ? ESCAPE '!' OR unique_name LIKE ? ESCAPE '!'
                            LIMIT 300");
    $stmt->execute(array($like, $like));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($found[(int) $row['id']])) {
            continue;
        }
        $name = (string) $row['name'];
        $row['matched'] = '';
        $row['matched_kind'] = '';
        $row['rank'] = strcasecmp($name, $q) === 0 ? 0 : (mb_stripos($name, $q) === 0 ? 1 : 2);
        $found[(int) $row['id']] = $row;
    }

    // Past and other names only live in json_data; LIKE narrows the pool,
    // then only a hit on an actual name field counts (not wiki text). Only
    // records holding such a field are read (a few hundred), so a common word
    // in other text cannot push a renamed program out of the pool.
    $stmt = $pdo->prepare("SELECT $cols, json_data FROM facilities_v2
                            WHERE json_data LIKE ? ESCAPE '!'
                              AND (json_data LIKE '%pastNames%' OR json_data LIKE '%otherNames%' OR json_data LIKE '%currentName%')
                            LIMIT 2000");
    $stmt->execute(array($like));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (isset($found[$id])) {
            continue;
        }
        $data = json_decode((string) $row['json_data'], true);
        $ident = is_array($data) && isset($data['identification']) && is_array($data['identification']) ? $data['identification'] : array();
        $names = array();
        foreach (array('pastNames' => 'past', 'otherNames' => 'other') as $key => $kind) {
            $list = function_exists('kop_v2_search_name_list') ? kop_v2_search_name_list($ident[$key] ?? null)
                : (isset($ident[$key]) && is_array($ident[$key]) ? $ident[$key] : array());
            foreach ($list as $n) {
                $names[] = array($n, $kind);
            }
        }
        if (!empty($ident['currentName']) && is_string($ident['currentName'])) {
            $names[] = array($ident['currentName'], 'current');
        }
        foreach ($names as $pair) {
            list($alias, $kind) = $pair;
            if (is_string($alias) && mb_stripos($alias, $q) !== false) {
                unset($row['json_data']);
                $row['matched'] = $alias;
                $row['matched_kind'] = $kind;
                $row['rank'] = mb_stripos($alias, $q) === 0 ? 3 : 4;
                $found[$id] = $row;
                break;
            }
        }
    }

    usort($found, static function ($a, $b) {
        if ($a['rank'] !== $b['rank']) {
            return $a['rank'] - $b['rank'];
        }
        $la = mb_strlen((string) $a['name']);
        $lb = mb_strlen((string) $b['name']);
        return $la !== $lb ? $la - $lb : strcasecmp((string) $a['name'], (string) $b['name']);
    });

    $out = array();
    foreach (array_slice($found, 0, $limit) as $row) {
        $out[] = array(
            'id'         => (int) $row['id'],
            'name'       => (string) ($row['name'] !== '' && $row['name'] !== null ? $row['name'] : $row['unique_name']),
            'city'       => (string) $row['city'],
            'state'      => (string) $row['state'],
            'country'    => (string) $row['country'],
            'status'     => (string) $row['status'],
            'start_year' => $row['start_year'] ? (int) $row['start_year'] : null,
            'end_year'   => $row['end_year'] ? (int) $row['end_year'] : null,
            'matched'    => (string) $row['matched'],
            'matched_kind' => (string) $row['matched_kind'],
        );
    }
    return $out;
}

/**
 * "<a>Wellspring Academies</a> (Brattleboro, VT)" for success messages, so a
 * screen says which facility it changed by name. Escaped HTML.
 */
function kop_facility_finder_label(PDO $pdo, $fid) {
    $stmt = $pdo->prepare('SELECT name, unique_name, city, state, country, status FROM facilities_v2 WHERE id = ?');
    $stmt->execute(array((int) $fid));
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) {
        return 'facility #' . (int) $fid;
    }
    $name = esc_html($f['name'] !== '' && $f['name'] !== null ? $f['name'] : $f['unique_name']);
    $url = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $fid) : '';
    $place = trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country']))));
    return ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . $name . '</strong></a>' : '<strong>' . $name . '</strong>')
        . ($place !== '' ? ' (' . esc_html($place) . ')' : '');
}

add_action('wp_ajax_kop_facility_finder', function () {
    if (!current_user_can('manage_options') || !check_ajax_referer('kop_facility_finder', 'nonce', false)) {
        wp_send_json_error('Not authorized', 403);
    }
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        wp_send_json_error('The records database is not reachable.', 500);
    }
    $q = wp_unslash($_GET['q'] ?? '');
    // 'all', or the kinds a field asks for ("company", "company,consultant"); none: facilities only.
    $kinds_param = sanitize_text_field(wp_unslash($_GET['kinds'] ?? ''));
    $kinds = $kinds_param === '' ? array('facility') : ($kinds_param === 'all' ? array('all') : array_map('kop_record_field_kind', explode(',', $kinds_param)));
    $results = in_array('facility', $kinds, true) || $kinds === array('all') ? kop_facility_finder_search($pdo, $q) : array();
    foreach ($results as &$r) {
        $r['url'] = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($r['id']) : '';
        $r['kind'] = 'facility';
        $r['token'] = (string) $r['id'];
    }
    unset($r);
    // "Any record": companies, consultants, providers and transporters too, each as "<kind>:<id>".
    $others = array_values(array_diff($kinds, array('facility')));
    // Young adult programs (18+): their own table in the records DB, "ya:<id>".
    if (($kinds === array('all') || in_array('ya', $kinds, true)) && function_exists('kop_ya_pdo') && mb_strlen($q) >= 2) {
        try {
            $ypdo = kop_ya_pdo();
            if ($ypdo && kop_ya_ready($ypdo)) {
                $like = '%' . addcslashes($q, '\\%_') . '%';
                $st = $ypdo->prepare('SELECT id, name, city, state FROM young_adult_programs WHERE name LIKE ? OR other_names LIKE ? ORDER BY name LIMIT 8');
                $st->execute(array($like, $like));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $y) {
                    $results[] = array('id' => (int) $y['id'], 'token' => 'ya:' . (int) $y['id'], 'kind' => 'ya', 'name' => (string) $y['name'],
                        'label' => 'Young adult program (18+)', 'detail' => implode(', ', array_filter(array((string) $y['city'], (string) $y['state']))),
                        'url' => home_url('/young-adult-programs/#kop-ya-program-' . (int) $y['id']));
                }
            }
        } catch (Throwable $e) {
            // The other kinds still come back.
        }
    }
    $others = array_values(array_diff($others, array('ya')));
    if ($others && function_exists('kop_wbc_find_records')) {
        try {
            // One kind: its own search, ranked for it (kop_wbc_find_records() ranks companies alone that way).
            $only = count($others) === 1 && $others !== array('all') ? $others[0] : '';
            foreach (kop_wbc_find_records(kop_wbc_pdo(), $q, $only !== '' ? 12 : 10, $only) as $o) {
                if ($others !== array('all') && !in_array($o['kind'], $others, true)) continue;
                $results[] = array('id' => (int) $o['id'], 'token' => $o['kind'] . ':' . (int) $o['id'], 'kind' => $o['kind'], 'name' => $o['name'],
                    'label' => $o['label'], 'detail' => $o['detail'], 'url' => (string) ($o['url'] ?? ''));
            }
        } catch (Throwable $e) {
            // Facilities alone, as before.
        }
    }
    wp_send_json_success($results);
});

/**
 * A facility id box with the finder beside it. $name is the form field name
 * ('' for a box read by script only); $attrs is extra raw attribute text
 * (e.g. ' class="kop-wb-fid"'). With $multi the box is hidden and each pick
 * fires a "kop-facility-picked" event (detail: the facility) for the screen
 * to collect, so one search can add any number of facilities.
 */
function kop_facility_finder_field($name, $value = '', $attrs = '', $multi = false) {
    if (!has_action('admin_footer', 'kop_facility_finder_print_assets')) {
        add_action('admin_footer', 'kop_facility_finder_print_assets');
    }
    if ($multi) {
        return '<input type="hidden" data-kop-facility-finder="multi"' . ($name !== '' ? ' name="' . esc_attr($name) . '"' : '') . $attrs . '>';
    }
    return '<input type="number" min="1" data-kop-facility-finder="1"'
        . ($name !== '' ? ' name="' . esc_attr($name) . '"' : '')
        . ' value="' . esc_attr($value ? (int) $value : '') . '" placeholder="id" style="width:80px"' . $attrs . '>';
}

function kop_facility_finder_print_assets() {
    ?>
    <style>
        .kop-ff { position: relative; display: inline-block; vertical-align: top; margin-left: 4px; }
        .kop-ff-q { width: 220px; }
        .kop-ff-list { position: absolute; z-index: 1000; left: 0; top: 100%; width: 360px; max-height: 320px; overflow-y: auto;
            margin: 2px 0 0; padding: 0; list-style: none; background: #fff; border: 1px solid #8c8f94; box-shadow: 0 3px 8px rgba(0,0,0,.15); }
        .kop-ff-list li { margin: 0; padding: 6px 8px; cursor: pointer; border-bottom: 1px solid #f0f0f1; line-height: 1.35; }
        .kop-ff-list li.on, .kop-ff-list li:hover { background: #e8f4f6; }
        .kop-ff-list li.kop-ff-note { cursor: default; color: #666; background: #fff; }
        .kop-ff-meta { display: block; color: #666; font-size: 12px; }
        .kop-ff-picked { display: block; font-size: 12px; margin-top: 2px; }
        .kop-ff-list li.kop-ff-new { border-top: 1px solid #8c8f94; }
    </style>
    <script>
    (function () {
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode(wp_create_nonce('kop_facility_finder')); ?>;

        // A company and a program can share a name (Embark Behavioral Health), so a
        // finder that lists any record pills them (inc/kind-pill.php) and the
        // meta line drops the "Company (operator)" words the pill already says.
        function pill(f) {
            return window.kopKindPillNode ? window.kopKindPillNode(f.kind || 'facility') : null;
        }

        function meta(f, pilled) {
            if (f.kind && f.kind !== 'facility') return [pilled && pill(f) ? '' : f.label, f.detail, '#' + f.id].filter(Boolean).join(' · ');
            var place = [f.city, f.state || f.country].filter(Boolean).join(', ');
            var years = f.start_year || f.end_year ? (f.start_year || '?') + '–' + (f.end_year || '') : '';
            var bits = [place, f.status && f.status !== 'Unknown' ? f.status : '', years, '#' + f.id].filter(Boolean);
            var said = !f.matched ? '' : (f.matched_kind === 'past' ? 'Formerly ' : f.matched_kind === 'current' ? 'Now known as ' : 'Also known as ') + f.matched;
            return (said ? said + ' · ' : '') + bits.join(' · ');
        }

        function attach(box) {
            if (box.dataset.kopFfReady) return;
            box.dataset.kopFfReady = '1';
            var wrap = document.createElement('span');
            wrap.className = 'kop-ff';
            var q = document.createElement('input');
            q.type = 'search';
            q.className = 'kop-ff-q';
            // 'all', or the kinds this box takes ("company"); empty: facilities only.
            var kinds = (box.getAttribute('data-kop-record-kinds') || '').trim();
            var anyKind = kinds !== '';
            var create = (box.getAttribute('data-kop-record-create') || '').split(',').map(function (k) { return k.trim(); }).filter(Boolean);
            var words = { facility: 'program', company: 'company', consultant: 'educational consultant', provider: 'mental health provider',
                transporter: 'transporter', marketer: 'marketing company', tradeorg: 'trade organization', ya: 'young adult program' };
            var named = kinds === '' || kinds === 'all' ? '' : kinds.split(',').map(function (k) { return words[k.trim()] || k.trim(); }).join(' or ');
            q.placeholder = named ? 'Find a ' + named + (create.length ? ', or type a new name' : '') + '…'
                : anyKind ? 'Find a program, company, consultant…' : 'Find by name…';
            q.setAttribute('autocomplete', 'off');
            q.setAttribute('aria-label', named ? 'Find a ' + named + ' by name' : anyKind ? 'Find a record by name' : 'Find a facility by name');
            var list = document.createElement('ul');
            list.className = 'kop-ff-list';
            list.hidden = true;
            var picked = document.createElement('span');
            picked.className = 'kop-ff-picked';
            wrap.appendChild(q);
            wrap.appendChild(list);
            box.insertAdjacentElement('afterend', wrap);
            wrap.insertAdjacentElement('afterend', picked);
            if (box.getAttribute('data-kop-picked-label')) picked.textContent = 'Picked: ' + box.getAttribute('data-kop-picked-label');

            var results = [], active = -1, timer = null, seq = 0;

            function close() { list.hidden = true; active = -1; }
            function choose(f) {
                if (box.getAttribute('data-kop-facility-finder') === 'multi') {
                    box.dispatchEvent(new CustomEvent('kop-facility-picked', { bubbles: true, detail: f }));
                    q.value = '';
                    close();
                    return;
                }
                box.value = f.token || f.id;
                box.dispatchEvent(new Event('input', { bubbles: true }));
                box.dispatchEvent(new Event('change', { bubbles: true }));
                picked.textContent = '';
                if (f.isNew) {
                    // Made only when the action runs (kop_record_field_resolve()).
                    picked.appendChild(document.createTextNode('New ' + (words[f.kind] || f.kind) + ': '));
                    var nn = document.createElement('strong');
                    nn.textContent = f.name;
                    picked.appendChild(nn);
                    picked.appendChild(document.createTextNode(' (made when you click the button)'));
                    q.value = '';
                    close();
                    return;
                }
                var name = document.createElement(f.url ? 'a' : 'strong');
                name.textContent = f.name;
                if (f.url) { name.href = f.url; name.target = '_blank'; name.rel = 'noopener'; }
                picked.appendChild(document.createTextNode('Picked: '));
                var pp = anyKind ? pill(f) : null;
                if (pp) picked.appendChild(pp);
                picked.appendChild(name);
                picked.appendChild(document.createTextNode(' (' + meta(f, !!pp) + ')'));
                q.value = '';
                close();
            }
            function note(text) {
                list.innerHTML = '';
                var li = document.createElement('li');
                li.className = 'kop-ff-note';
                li.textContent = text;
                list.appendChild(li);
                list.hidden = false;
            }
            // "New company named ..." under the matches, when the box may make one.
            function withNew(found) {
                var term = q.value.trim().replace(/\s+/g, ' ');
                if (!create.length || term.length < 2 || /^\d+$/.test(term)) return found;
                var same = found.some(function (f) { return (f.name || '').toLowerCase() === term.toLowerCase(); });
                return found.concat(same ? [] : create.map(function (k) {
                    return { isNew: true, kind: k, name: term, token: 'new:' + k + ':' + term, id: 0 };
                }));
            }
            function render() {
                list.innerHTML = '';
                if (!results.length) { note((anyKind ? 'No record' : 'No facility') + ' matches. Try part of the name.'); return; }
                results.forEach(function (f, i) {
                    if (f.isNew) {
                        var nl = document.createElement('li');
                        nl.className = 'kop-ff-new' + (i === active ? ' on' : '');
                        var nb = document.createElement('strong');
                        nb.textContent = 'New ' + (words[f.kind] || f.kind) + ' named \u201c' + f.name + '\u201d';
                        var nm = document.createElement('span');
                        nm.className = 'kop-ff-meta';
                        nm.textContent = 'Not on file yet: made when you click the button, citing where this card came from.';
                        nl.appendChild(nb);
                        nl.appendChild(nm);
                        nl.addEventListener('mousedown', function (e) { e.preventDefault(); choose(f); });
                        list.appendChild(nl);
                        return;
                    }
                    var li = document.createElement('li');
                    if (i === active) li.className = 'on';
                    var b = document.createElement('strong');
                    b.textContent = f.name;
                    var p = anyKind ? pill(f) : null;
                    var m = document.createElement('span');
                    m.className = 'kop-ff-meta';
                    m.textContent = meta(f, !!p);
                    if (p) li.appendChild(p);
                    li.appendChild(b);
                    li.appendChild(m);
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(f); });
                    list.appendChild(li);
                });
                list.hidden = false;
            }
            function search() {
                var term = q.value.trim();
                if (term.length < 2 && !/^\d+$/.test(term)) { close(); return; }
                var mine = ++seq;
                note('Searching…');
                fetch(ajax + '?action=kop_facility_finder&nonce=' + encodeURIComponent(nonce) + '&q=' + encodeURIComponent(term) + (anyKind ? '&kinds=' + encodeURIComponent(kinds) : ''), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (mine !== seq) return;
                        if (!json || !json.success) { note('Search failed: ' + (json && json.data ? json.data : 'try reloading the page')); return; }
                        results = withNew(json.data); active = results.length ? 0 : -1; render();
                    })
                    .catch(function () { if (mine === seq) note('Search failed; try reloading the page.'); });
            }
            q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
            q.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    // Never submit the surrounding form from the search box.
                    e.preventDefault();
                    if (!list.hidden && results[active]) choose(results[active]);
                } else if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !list.hidden && results.length) {
                    e.preventDefault();
                    active = (active + (e.key === 'ArrowDown' ? 1 : results.length - 1)) % results.length;
                    render();
                } else if (e.key === 'Escape') {
                    close();
                }
            });
            q.addEventListener('blur', function () { setTimeout(close, 150); });
            box.addEventListener('input', function (e) { if (e.isTrusted) picked.textContent = ''; });
        }

        document.querySelectorAll('input[data-kop-facility-finder]').forEach(attach);
        // For boxes a screen builds later (the submissions queue's cards).
        window.kopFacilityFinderAttach = attach;
    })();
    </script>
    <?php
}

// "Pick a record, or make a new one" for any action (kop_record_field_param()).
require_once __DIR__ . '/record-field.php';
