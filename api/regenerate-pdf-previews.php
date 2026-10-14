<?php
/**
 * Regenerate first-page preview images for PDFs that have none.
 *
 * The May 2026 media restore registered about 150 PDFs with metadata holding
 * only a filesize, so WordPress never rendered their first page, and a handful
 * of older PDFs point at preview files that are no longer on disk. Every
 * document list on the site (state and country hubs, location index, program
 * index, research library) reads the preview through
 * kop_get_attachment_preview_url(), which has nothing to return for them, so
 * they show a file-type badge instead of a cover.
 *
 * A PDF qualifies when its metadata has no sizes, or when none of the size
 * files it names exists. PDFs with a working preview are never touched.
 * Covers are drawn by kop_pdf_preview_make() (inc/pdf-preview.php): the gs
 * program in its own process, written as "<name>-pdf.jpg" beside the PDF like
 * an upload's own preview. The PDF itself is never changed.
 *
 * Open in a browser: the page lists what is waiting and why earlier ones
 * failed; "Start" keeps calling ?apply=1 until nothing is left.
 *
 * GET ?format=json               - dry run as JSON
 * GET ?apply=1                   - one batch (JSON): as many as fit in ~20 s
 * GET ?apply=1&limit=25          - at most this many, 1 to 50
 * GET ?retry=1[&reason=damaged]  - clear the failure marks (of one reason)
 *
 * Requires manage_options.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}
if (!function_exists('kop_pdf_preview_make')) {
    require_once dirname(__DIR__) . '/inc/pdf-preview.php';
}

/** True when the PDF has at least one preview file on disk. */
function kop_rpp_has_preview($attachment_id, $pdf_path) {
    $meta = wp_get_attachment_metadata($attachment_id, true);
    if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
        return false;
    }
    $dir = trailingslashit(dirname($pdf_path));
    foreach ($meta['sizes'] as $info) {
        if (!empty($info['file']) && file_exists($dir . $info['file'])) {
            return true;
        }
    }
    return false;
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$json  = $apply || (isset($_GET['format']) && $_GET['format'] === 'json');
$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 50;

// Marks left by the old in-process Imagick route (most were its crashes and
// the read-only Sequel TSI folders) get one try with the gs program.
if (get_option('kop_pdf_preview_marks_version') !== '2') {
    delete_post_meta_by_key(KOP_PDF_PREVIEW_FAILED_META);
    update_option('kop_pdf_preview_marks_version', '2', false);
}

if (isset($_GET['retry']) && $_GET['retry'] === '1') {
    $only = isset($_GET['reason']) ? sanitize_key($_GET['reason']) : '';
    if ($only === '') {
        delete_post_meta_by_key(KOP_PDF_PREVIEW_FAILED_META);
    } else {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            KOP_PDF_PREVIEW_FAILED_META
        ));
        foreach ($ids as $id) {
            if (kop_pdf_preview_failed_reason($id) === $only) {
                delete_post_meta($id, KOP_PDF_PREVIEW_FAILED_META);
            }
        }
    }
    if (!$json) {
        wp_safe_redirect(remove_query_arg(array('retry', 'reason')));
        exit;
    }
}

$pdf_ids = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_mime_type' => 'application/pdf',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'orderby'        => 'ID',
    'order'          => 'ASC',
]);

$queue = [];
$missing_file = [];
$failed_before = [];
foreach ($pdf_ids as $id) {
    $path = get_attached_file($id);
    if (!$path || !file_exists($path)) {
        $missing_file[] = ['id' => (int) $id, 'file' => (string) get_post_meta($id, '_wp_attached_file', true)];
        continue;
    }
    if (kop_rpp_has_preview($id, $path)) {
        continue;
    }
    $reason = kop_pdf_preview_failed_reason($id);
    if ($reason !== '') {
        $failed_before[] = ['id' => (int) $id, 'file' => (string) get_post_meta($id, '_wp_attached_file', true), 'reason' => $reason];
        continue;
    }
    $queue[] = ['id' => (int) $id, 'path' => $path];
}

$report = [
    'success'        => true,
    'mode'           => $apply ? 'apply' : 'dry-run',
    'pdfs'           => count($pdf_ids),
    'need_preview'   => count($queue),
    'pdf_missing'    => $missing_file,
    'failed_earlier' => $failed_before,
];

if ($apply) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    @set_time_limit(120);
    $started = microtime(true);
    $done = [];
    $failed = [];
    foreach ($queue as $row) {
        if (count($done) + count($failed) >= $limit || microtime(true) - $started > 20) {
            break;
        }
        $r = kop_pdf_preview_make($row['id'], $row['path']);
        $item = ['id' => $row['id'], 'file' => basename($row['path'])];
        if ($r['ok']) {
            $done[] = $item + ['sizes' => $r['sizes']];
        } else {
            $failed[] = $item + ['reason' => $r['reason'], 'why' => kop_pdf_preview_reason_label($r['reason']), 'detail' => $r['detail']];
        }
    }
    if ($done) {
        delete_transient('kop_hidden_preview_ids');
    }
    $report['regenerated'] = $done;
    $report['failed']      = $failed;
    $report['remaining']   = max(0, count($queue) - count($done) - count($failed));
    unset($report['failed_earlier'], $report['pdf_missing']);
}

if ($json) {
    header('Content-Type: application/json');
    if (!$apply) {
        $report['sample'] = array_map(function ($row) {
            return ['id' => $row['id'], 'file' => basename($row['path'])];
        }, array_slice($queue, 0, 25));
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------ page
header('Content-Type: text/html; charset=utf-8');
$self = remove_query_arg(array('retry', 'reason', 'apply', 'format'));
$by_reason = [];
foreach ($failed_before as $f) {
    $by_reason[$f['reason']][] = $f;
}
$edit = function ($id) {
    return '<a href="' . esc_url(admin_url('post.php?post=' . (int) $id . '&action=edit')) . '" target="_blank" rel="noopener">#' . (int) $id . '</a>';
};
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Regenerate PDF Previews</title>
<style>
body { font: 15px/1.5 system-ui, sans-serif; background: #F2EEDF; color: #000435; margin: 0; padding: 16px; }
main { max-width: 960px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 20px 24px; }
h1 { font-size: 1.5rem; margin: 0 0 .5rem; color: #000435; }
h2 { font-size: 1.1rem; margin: 1.5rem 0 .4rem; color: #000435; }
a { color: #000080; }
.muted { color: #4A5568; }
.stats { display: flex; flex-wrap: wrap; gap: 12px; margin: 12px 0; }
.stat { background: #F2EEDF; border-radius: 6px; padding: 8px 14px; }
.stat b { font-size: 1.3rem; display: block; }
button.kop-btn { font: inherit; border: 0; border-radius: 6px; padding: 8px 16px; cursor: pointer; background: #1b7e3c; color: #fff; }
button.kop-btn:hover, button.kop-btn:focus, button.kop-btn:active { background: #145f2d; color: #fff; }
button.kop-btn.stop, button.kop-btn.stop:hover, button.kop-btn.stop:focus, button.kop-btn.stop:active { background: #7a2b2b; color: #fff; }
button.kop-btn:disabled { background: #7a7a7a; color: #fff; cursor: default; }
progress { width: 100%; height: 14px; margin: 10px 0 4px; }
#log { list-style: none; padding: 0; margin: 8px 0; max-height: 360px; overflow: auto; font-size: 13px; }
#log li { padding: 2px 0; border-bottom: 1px solid #eee; overflow-wrap: anywhere; }
.ok { color: #1b6b35; }
.bad { color: #9b2c2c; }
details { margin: 6px 0; }
summary { cursor: pointer; }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
td { padding: 3px 6px; border-bottom: 1px solid #eee; overflow-wrap: anywhere; }
</style></head><body><main>
<h1>Regenerate PDF Previews</h1>
<p class="muted">Draws the first-page cover for PDFs in the media library that have none. Nothing else about the PDF changes. It keeps going in the background of this tab until every PDF is done; you can stop it any time.</p>

<div class="stats">
    <div class="stat"><b id="n-wait"><?php echo count($queue); ?></b>waiting</div>
    <div class="stat"><b id="n-done">0</b>drawn now</div>
    <div class="stat"><b id="n-fail"><?php echo count($failed_before); ?></b>could not be drawn</div>
    <div class="stat"><b><?php echo count($missing_file); ?></b>PDF file missing</div>
</div>

<?php if ($queue): ?>
<button type="button" class="kop-btn" id="go">Start</button>
<progress id="bar" max="<?php echo count($queue); ?>" value="0" hidden></progress>
<div id="status" class="muted"></div>
<ul id="log"></ul>
<?php else: ?>
<p class="ok">Nothing is waiting: every PDF either has a cover or is listed below.</p>
<?php endif; ?>

<?php if ($by_reason): ?>
<h2>Could not be drawn</h2>
<p class="muted">These are skipped until you try them again.</p>
<?php foreach ($by_reason as $reason => $rows): ?>
<details>
    <summary><b><?php echo count($rows); ?></b> &middot; <?php echo esc_html(kop_pdf_preview_reason_label($reason)); ?>
        &middot; <a href="<?php echo esc_url(add_query_arg(array('retry' => '1', 'reason' => $reason), $self)); ?>">Try these again</a></summary>
    <?php if ($reason === 'damaged'): ?>
    <p class="muted">The file on the server ends early (often at exactly 1 MB or 3 MB, an upload that stopped). Upload a good copy over it in the media library and try again.</p>
    <?php endif; ?>
    <table><?php foreach ($rows as $r): ?>
        <tr><td><?php echo $edit($r['id']); ?></td><td><?php echo esc_html($r['file']); ?></td></tr>
    <?php endforeach; ?></table>
</details>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($missing_file): ?>
<h2>PDF file not on the server</h2>
<details><summary><?php echo count($missing_file); ?> media items point at a PDF that is gone</summary>
<table><?php foreach ($missing_file as $r): ?>
    <tr><td><?php echo $edit($r['id']); ?></td><td><?php echo esc_html($r['file']); ?></td></tr>
<?php endforeach; ?></table></details>
<?php endif; ?>

<script>
(function () {
    var go = document.getElementById('go');
    if (!go) return;
    var url = <?php echo wp_json_encode(add_query_arg('apply', '1', $self)); ?>;
    var bar = document.getElementById('bar'), status = document.getElementById('status'), log = document.getElementById('log');
    var nWait = document.getElementById('n-wait'), nDone = document.getElementById('n-done'), nFail = document.getElementById('n-fail');
    var total = +bar.max, done = 0, failed = +nFail.textContent, running = false, errors = 0;

    function line(cls, text) {
        var li = document.createElement('li');
        li.className = cls;
        li.textContent = text;
        log.insertBefore(li, log.firstChild);
    }
    function stop(msg) {
        running = false;
        go.textContent = 'Start';
        go.classList.remove('stop');
        status.textContent = msg;
    }
    function step() {
        if (!running) return;
        status.textContent = 'Drawing covers...';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (d) {
                errors = 0;
                (d.regenerated || []).forEach(function (x) { done++; line('ok', 'Drawn: ' + x.file); });
                (d.failed || []).forEach(function (x) {
                    failed++;
                    line('bad', 'Could not draw ' + x.file + ': ' + x.why + (x.detail ? ' (' + x.detail + ')' : ''));
                });
                nDone.textContent = done;
                nFail.textContent = failed;
                nWait.textContent = d.remaining;
                bar.value = total - d.remaining;
                if (!d.remaining) {
                    stop('Finished. Reload the page to see the full list of ones that could not be drawn.');
                } else if (!(d.regenerated || []).length && !(d.failed || []).length) {
                    stop('Stopped: the last batch did nothing.');
                } else {
                    step();
                }
            })
            .catch(function (e) {
                // A request the host cut off still marked its PDF, so the next one moves on.
                if (++errors >= 3) {
                    stop('Stopped after 3 errors in a row (' + e.message + '). Press Start to carry on.');
                } else {
                    line('bad', 'A batch was cut off (' + e.message + '); carrying on.');
                    setTimeout(step, 2000);
                }
            });
    }
    go.addEventListener('click', function () {
        if (running) { stop('Stopped.'); return; }
        running = true;
        bar.hidden = false;
        go.textContent = 'Stop';
        go.classList.add('stop');
        step();
    });
})();
</script>
</main></body></html>
