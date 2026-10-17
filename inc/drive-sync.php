<?php
/**
 * KOP Tools > Drive Sync: "Sync now" for the documents in the Drive FileBird
 * folder, and what the last run brought in.
 *
 * The import itself is api/sync-drive-documents.php, which the server's cron
 * runs once a night. A run takes minutes (it lists the whole Drive folder, then
 * downloads each new document and makes its preview), longer than a web request
 * may last, so "Sync now" starts that same script in the background and the
 * screen reads its progress from the kop_drive_sync_status option, which the
 * script keeps while it runs. Its lock file (api/.kop-sync-drive-documents.lock)
 * says whether a run is going, whoever started it.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-kit.php';

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-drive-sync';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Drive Sync', 'Drive Sync', 'manage_options', 'kop-drive-sync', 'kop_drive_sync_page');
}, 30);

/** Documents a "Sync now" run imports at most; the rest wait for the next run. */
const KOP_DRIVE_SYNC_LIMIT = 500;
/** Minutes a "Sync now" run may spend importing. */
const KOP_DRIVE_SYNC_MINUTES = 30;

function kop_drive_sync_lock_path() {
    return get_stylesheet_directory() . '/api/.kop-sync-drive-documents.lock';
}

/** True while a sync holds the lock (started here or by the nightly cron). */
function kop_drive_sync_running() {
    $path = kop_drive_sync_lock_path();
    if (!file_exists($path)) return false;
    $h = @fopen($path, 'c');
    if (!$h) return false;
    $free = flock($h, LOCK_EX | LOCK_NB);
    if ($free) flock($h, LOCK_UN);
    fclose($h);
    return !$free;
}

function kop_drive_sync_status() {
    $s = get_option('kop_drive_sync_status', array());
    return is_array($s) ? $s : array();
}

/**
 * Where the run stands: 'idle' (nothing yet), 'starting', 'running', 'done',
 * 'failed', 'died' (said running but holds no lock: the process was stopped)
 * or 'nostart' (the background start never got going).
 */
function kop_drive_sync_phase(array $s, $running, $now = null) {
    $now = $now ?? time();
    $state = $s['state'] ?? '';
    if ($state === '') return $running ? 'running' : 'idle';
    if ($state === 'starting') {
        if ($running) return 'running';
        return $now - (int) ($s['started'] ?? 0) > 90 ? 'nostart' : 'starting';
    }
    if ($state === 'listing' || $state === 'running') return $running ? 'running' : 'died';
    return $state === 'failed' ? 'failed' : 'done';
}

/** The PHP that runs scripts from the command line (the web server's own PHP is not one). */
function kop_drive_sync_php() {
    foreach (array('/opt/cpanel/ea-php82/root/usr/bin/php', PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php') as $bin) {
        if (@is_executable($bin)) return $bin;
    }
    return '';
}

function kop_drive_sync_can_start() {
    if (!function_exists('exec')) return false;
    $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $off, true) && DIRECTORY_SEPARATOR === '/' && kop_drive_sync_php() !== '';
}

/** Starts the sync in the background: [ok, message]. */
function kop_drive_sync_start($only = '') {
    if (kop_drive_sync_running()) {
        return array(false, 'A sync is already running. Its progress is below.');
    }
    if (!kop_drive_sync_can_start()) {
        return array(false, 'This server does not let the site start the sync itself. Run it over SSH: php api/sync-drive-documents.php apply');
    }
    $theme = get_stylesheet_directory();
    $args = array('apply', '--limit=' . KOP_DRIVE_SYNC_LIMIT, '--minutes=' . KOP_DRIVE_SYNC_MINUTES, '--by=' . get_current_user_id());
    if ($only !== '') $args[] = '--only=' . $only;
    $logs = dirname(rtrim(ABSPATH, '/')) . '/logs';
    $log = is_dir($logs) && is_writable($logs) ? $logs . '/drive-sync.log' : '/dev/null';
    $cmd = 'cd ' . escapeshellarg($theme) . ' && '
        . (@is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '') . 'nohup '
        . escapeshellarg(kop_drive_sync_php()) . ' api/sync-drive-documents.php '
        . implode(' ', array_map('escapeshellarg', $args))
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
    update_option('kop_drive_sync_status', array(
        'state' => 'starting', 'started' => time(), 'by' => get_current_user_id(), 'only' => $only,
        'counts' => array(), 'items' => array(), 'current' => '', 'error' => '', 'stopped' => '',
    ), false);
    exec($cmd);
    return array(true, 'Started.');
}

/** Top-level folders of the Drive FileBird folder, for the "Only this folder" box (cached 10 minutes). */
function kop_drive_sync_top_folders() {
    $cached = get_transient('kop_drive_sync_top');
    if (is_array($cached)) return $cached;
    $names = array();
    try {
        if (!function_exists('njfb_cloud_get_main_folder') || !class_exists('NjFbCloud\\Src\\Classes\\GoogleDriveApi')) return array();
        require_once get_stylesheet_directory() . '/api/lib-filebird-drive.php';
        $root = njfb_cloud_get_main_folder('google_drive');
        if (!$root) return array();
        $page = null;
        do {
            $q = array('q' => "'" . $root . "' in parents and trashed = false and mimeType = 'application/vnd.google-apps.folder'",
                       'fields' => 'nextPageToken, files(name)', 'pageSize' => '1000');
            if ($page) $q['pageToken'] = $page;
            $res = kop_sd_drive_get('files', $q);
            foreach ((array) ($res['files'] ?? array()) as $f) {
                if (!in_array(strtolower($f['name']), kop_sd_skip_top(), true)) $names[] = $f['name'];
            }
            $page = $res['nextPageToken'] ?? null;
        } while ($page);
    } catch (Throwable $e) {
        return array();
    }
    natcasesort($names);
    $names = array_values(array_unique($names));
    set_transient('kop_drive_sync_top', $names, 10 * MINUTE_IN_SECONDS);
    return $names;
}

/** "3:04 pm" today, "Oct 16, 3:04 pm" another day. */
function kop_drive_sync_when($t) {
    if (!$t) return '';
    return wp_date(wp_date('Y-m-d', $t) === wp_date('Y-m-d') ? 'g:i a' : 'M j, g:i a', $t);
}

function kop_drive_sync_minutes($secs) {
    $secs = max(0, (int) $secs);
    return $secs < 90 ? $secs . ' seconds' : round($secs / 60) . ' minutes';
}

/** What the run says each result means, for the groups under the counts. */
function kop_drive_sync_groups() {
    return array(
        'imported'      => 'Imported',
        'failed'        => 'Failed (tried again next run)',
        'not placed'    => 'Not placed (not imported)',
        'too big'       => 'Too big to import (over 60 MB)',
        'already in the library' => 'Already in the library, now also shown in this folder',
    );
}

/** The status line and the last run's report, as HTML. */
function kop_drive_sync_report_html(array $s, $running) {
    $phase = kop_drive_sync_phase($s, $running);
    $who = '';
    if (!empty($s['by'])) {
        $u = get_userdata((int) $s['by']);
        $who = $u ? $u->display_name : 'an admin';
    }
    $started = kop_drive_sync_when($s['started'] ?? 0);
    $by = $who !== '' ? 'started by ' . $who : 'the nightly run';
    $only = !empty($s['only']) ? ', only "' . $s['only'] . '"' : '';
    $counts = (array) ($s['counts'] ?? array());
    unset($counts['guids fixed']);

    switch ($phase) {
        case 'idle':
            return kop_ak_empty('No sync has been recorded yet. The first run from here or tonight\'s run will show up here.');
        case 'starting':
            $line = 'Starting the sync...';
            break;
        case 'running':
            $line = ($s['state'] ?? '') === 'listing' || ($s['state'] ?? '') === 'starting'
                ? 'Reading the Drive folder (' . $by . ' at ' . $started . $only . ')...'
                : 'Syncing (' . $by . ' at ' . $started . $only . '): ' . (int) ($counts['imported'] ?? 0) . ' imported so far of '
                    . number_format((int) ($s['listed'] ?? 0)) . ' files in Drive.';
            break;
        case 'nostart':
            $line = 'The sync did not start. Run it over SSH instead (php api/sync-drive-documents.php apply) and look in ~/logs/drive-sync.log for why.';
            break;
        case 'died':
            $line = 'The last sync (' . $by . ' at ' . $started . ') stopped before it finished. What it imported is kept; Sync now carries on from there.';
            break;
        case 'failed':
            $line = 'The last sync (' . $by . ' at ' . $started . ') could not read Drive: ' . ($s['error'] ?? '');
            break;
        default:
            $line = 'Last sync: ' . $by . ', ' . $started . $only . ', took ' . kop_drive_sync_minutes(($s['finished'] ?? 0) - ($s['started'] ?? 0)) . '. '
                . (!empty($s['stopped']) ? 'It stopped at its limit; the rest comes in on the next run.' : '');
    }

    $out = '<p class="kop-ds-line' . (in_array($phase, array('nostart', 'died', 'failed'), true) ? ' is-bad' : '') . '">' . esc_html($line) . '</p>';
    if ($phase === 'running' && !empty($s['current'])) {
        $out .= '<p class="kop-ds-current">Now: ' . esc_html($s['current']) . '</p>';
    }
    if ($counts) {
        $bits = array();
        foreach ($counts as $k => $n) $bits[] = '<strong>' . number_format((int) $n) . '</strong> ' . esc_html($k);
        $out .= '<p class="kop-ds-counts">' . implode(' &nbsp;&middot;&nbsp; ', $bits) . '</p>';
    }

    // The files by result, imported first.
    $groups = array();
    foreach ((array) ($s['items'] ?? array()) as $it) {
        $res = (string) ($it['result'] ?? '');
        $key = strtok($res, ':');
        $detail = trim((string) substr($res, strlen($key) + 1));
        $groups[$key][] = array((string) ($it['file'] ?? ''), $detail);
    }
    $names = kop_drive_sync_groups();
    uksort($groups, function ($a, $b) use ($names) {
        $ka = array_search($a, array_keys($names), true);
        $kb = array_search($b, array_keys($names), true);
        return ($ka === false ? 99 : $ka) <=> ($kb === false ? 99 : $kb);
    });
    foreach ($groups as $key => $rows) {
        $list = '<ul class="kop-ds-files">';
        foreach ($rows as list($file, $detail)) {
            $d = esc_html($detail);
            // "#1234 (same path)": link the new attachment.
            $d = preg_replace_callback('/#(\d+)/', function ($m) {
                return '<a href="' . esc_url(admin_url('post.php?post=' . $m[1] . '&action=edit')) . '">#' . $m[1] . '</a>';
            }, $d);
            $list .= '<li><span class="kop-ds-file">' . esc_html(ltrim($file, '/')) . '</span>' . ($d !== '' ? ' <span class="kop-ds-detail">' . $d . '</span>' : '') . '</li>';
        }
        $list .= '</ul>';
        $title = ($names[$key] ?? ucfirst($key)) . ' (' . count($rows) . ')';
        $out .= kop_ak_fold(esc_html($title), $list, $key === 'imported' || $key === 'failed');
    }
    if (!empty($s['more_items'])) {
        $out .= '<p class="kop-ds-current">' . (int) $s['more_items'] . ' more lines are in ~/logs/drive-sync.log on the server.</p>';
    }
    return $out;
}

function kop_drive_sync_payload() {
    $running = kop_drive_sync_running();
    $s = kop_drive_sync_status();
    $phase = kop_drive_sync_phase($s, $running);
    return array('html' => kop_drive_sync_report_html($s, $running), 'active' => in_array($phase, array('starting', 'running'), true));
}

add_action('wp_ajax_kop_drive_sync', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Not allowed.'), 403);
    check_ajax_referer('kop_drive_sync', 'nonce');
    $message = '';
    if (($_POST['act'] ?? '') === 'start') {
        list($ok, $message) = kop_drive_sync_start(sanitize_text_field(wp_unslash($_POST['only'] ?? '')));
        if (!$ok) wp_send_json_error(array('message' => $message) + kop_drive_sync_payload());
    }
    wp_send_json_success(array('message' => $message) + kop_drive_sync_payload());
});

function kop_drive_sync_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $p = kop_drive_sync_payload();
    $folders = kop_drive_sync_top_folders();

    echo '<div class="wrap kop-ak kop-ds">';
    echo kop_ak_header('Drive Sync', array(
        'lede' => 'Brings the PDFs and Word documents in the Google Drive folder "FileBird Cloud - kidsoverprofits.org" into the media library, each in the folder that matches where it sits in Drive. It runs by itself once a night; Sync now runs it at once.',
        'steps' => array(
            'Put the document in its program\'s folder in Drive (a new folder is fine: the site makes it).',
            'Press <em>Sync now</em>. Reading the whole Drive folder takes a minute or two before anything comes in; then each new document is downloaded and gets its preview. You can leave the page; the sync carries on.',
            'To bring in one folder faster, type its name in <em>Only this folder</em> first (a folder at the top of the Drive folder).',
        ),
        'help' => '<p>Pictures come in through FileBird Cloud\'s own sync, not this one. A document the library already holds (same file, wherever it is filed) is not imported twice: it is also shown in the new folder. '
            . 'The inspection scrapers\' folders (Inspections, nc_pdfs, ...) and Doc Finder Inbox are left out.</p>'
            . '<p><strong>Not placed</strong> means the site could not tell which folder a document belongs in: it sits loose at the top of the Drive folder (move it into a folder), '
            . 'or its folder\'s name is used by several folders on the site (rename it in Drive to the program\'s full name). '
            . 'A run started here imports up to ' . KOP_DRIVE_SYNC_LIMIT . ' documents in ' . KOP_DRIVE_SYNC_MINUTES . ' minutes; anything left comes in on the next run.</p>',
    ));

    $tools = '<label class="kop-ds-only">Only this folder <input type="text" list="kop-ds-folders" class="regular-text" placeholder="Every folder" autocomplete="off"></label>';
    if ($folders) {
        $tools .= '<datalist id="kop-ds-folders">';
        foreach ($folders as $name) $tools .= '<option value="' . esc_attr($name) . '"></option>';
        $tools .= '</datalist>';
    }
    $tools .= kop_ak_button('Sync now', 'primary', 'data-nonce="' . esc_attr(wp_create_nonce('kop_drive_sync')) . '"' . ($p['active'] ? ' disabled' : ''), 'kop-ds-start');
    echo kop_ak_status('<div class="kop-ds-msg" role="status" aria-live="polite"></div>', $tools);
    echo '<div class="kop-ds-report" aria-live="polite">' . $p['html'] . '</div>';
    ?>
    <style>
        .kop-ds .kop-ds-only { display:inline-flex; align-items:center; gap:6px; margin-right:8px; flex-wrap:wrap; }
        .kop-ds .kop-ds-only input { width:240px; max-width:100%; }
        .kop-ds-msg:empty { display:none; }
        .kop-ds-msg.is-bad, .kop-ds-line.is-bad { font-weight:600; }
        .kop-ds-report { max-width:1100px; }
        .kop-ds-line { font-size:14px; margin:12px 0 4px; }
        .kop-ds-current { color:var(--kop-text-muted, #4A5568); margin:2px 0; overflow-wrap:anywhere; }
        .kop-ds-counts { margin:6px 0 10px; }
        .kop-ds-files { margin:4px 0 4px 18px; list-style:disc; }
        .kop-ds-file { overflow-wrap:anywhere; }
        .kop-ds-detail { color:var(--kop-text-muted, #4A5568); }
        @media (max-width: 767px) { .kop-ds .kop-ds-only input { width:100%; } }
    </style>
    <script>
    (function () {
        var btn = document.querySelector('.kop-ds-start'), msg = document.querySelector('.kop-ds-msg'),
            report = document.querySelector('.kop-ds-report'), only = document.querySelector('.kop-ds-only input');
        if (!btn || !report) return;
        var url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, timer = null;
        function post(act) {
            var body = new FormData();
            body.append('action', 'kop_drive_sync');
            body.append('nonce', btn.getAttribute('data-nonce'));
            body.append('act', act);
            if (act === 'start' && only) body.append('only', only.value.trim());
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
        }
        function show(d) {
            var data = (d && d.data) || {};
            if (data.html !== undefined) report.innerHTML = data.html;
            btn.disabled = !!data.active;
            clearTimeout(timer);
            if (data.active) timer = setTimeout(poll, 4000);
            return data;
        }
        function poll() { post('status').then(show).catch(function () { timer = setTimeout(poll, 10000); }); }
        btn.addEventListener('click', function () {
            btn.disabled = true;
            msg.className = 'kop-ds-msg';
            msg.textContent = 'Starting the sync...';
            post('start').then(function (d) {
                var data = show(d);
                msg.textContent = d && d.success ? '' : (data.message || 'The sync did not start.');
                if (!(d && d.success)) msg.className = 'kop-ds-msg is-bad';
            }).catch(function () {
                msg.className = 'kop-ds-msg is-bad';
                msg.textContent = 'The sync did not start.';
                btn.disabled = false;
            });
        });
        if (btn.disabled) timer = setTimeout(poll, 4000);
    })();
    </script>
    <?php
    echo '</div>';
}
