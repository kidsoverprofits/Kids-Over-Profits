<?php
/**
 * News clippings read by OCR (docs/PLAN.md 3.35).
 *
 * The media library's news clippings (documents typed 'news-clipping', inc/doc-types.php) are read on
 * the owner's computer by kop-pipeline `py -3 scripts/clippings-ocr.py run`: ocrmypdf gives each scan an
 * invisible text layer (the picture is unchanged) and the text is kept beside it. `upload` copies the
 * folder to ~/kop-import/clippings/; `php api/import-clippings.php ~/kop-import/clippings [apply]` then,
 * per clipping:
 *
 *   1. the searchable PDF replaces the attachment's file (the original kept in
 *      uploads/kop-clipping-originals/<id>/), only while the file on the site is still the one read;
 *   2. the text goes in {prefix}kop_doc_text, which the site's search reads (inc/global-search.php);
 *   3. the News Processor's AI read (buildPrompt) fills an article that waits in Submissions Review >
 *      News like any other: article_url is the address printed on the page (a saved web page) or our
 *      copy of the scan (a print clipping), json_data.clippingAttachmentId names the document.
 *      A clipping whose article is already held (same link, same headline and outlet) makes no row.
 *
 * Every clipping is logged in {prefix}kop_clippings; `undo apply [--ids=..]` puts the original file back,
 * takes the text off and deletes the article while it still waits unreviewed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_CLIP_DB_VERSION = 2;

function kop_clip_tables() {
    global $wpdb;
    return array('text' => $wpdb->prefix . 'kop_doc_text', 'log' => $wpdb->prefix . 'kop_clippings');
}

function kop_clip_install() {
    if ((int) get_option('kop_clippings_db_version', 0) >= KOP_CLIP_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_clip_tables();
    $cs = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$t['text']} (
        attachment_id BIGINT UNSIGNED NOT NULL,
        text LONGTEXT NOT NULL,
        pages INT NOT NULL DEFAULT 0,
        source VARCHAR(20) NOT NULL DEFAULT 'ocr',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (attachment_id)
    ) $cs;");
    dbDelta("CREATE TABLE {$t['log']} (
        attachment_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(12) NOT NULL DEFAULT 'applied',
        original_md5 CHAR(32) NOT NULL DEFAULT '',
        backup_file VARCHAR(255) NOT NULL DEFAULT '',
        file_replaced TINYINT(1) NOT NULL DEFAULT 0,
        original_file VARCHAR(255) NOT NULL DEFAULT '',
        original_mime VARCHAR(100) NOT NULL DEFAULT '',
        original_meta LONGTEXT NULL,
        prior_title TEXT NULL,
        new_title TEXT NULL,
        prior_text LONGTEXT NULL,
        news_id BIGINT UNSIGNED NULL,
        news_result VARCHAR(20) NOT NULL DEFAULT '',
        note TEXT NULL,
        applied_at DATETIME NULL,
        undone_at DATETIME NULL,
        PRIMARY KEY  (attachment_id)
    ) $cs;");
    update_option('kop_clippings_db_version', KOP_CLIP_DB_VERSION, false);
}

/** The OCR text of a document ('' when none was stored). */
function kop_clip_doc_text($attachment_id) {
    global $wpdb;
    $t = kop_clip_tables();
    $suppress = $wpdb->suppress_errors(true);
    $text = $wpdb->get_var($wpdb->prepare("SELECT text FROM {$t['text']} WHERE attachment_id = %d", (int) $attachment_id));
    $wpdb->suppress_errors($suppress);
    return (string) $text;
}

/** Attachment ids whose stored text holds $words (a phrase), newest first. */
function kop_clip_search_ids($words, $limit = 5) {
    global $wpdb;
    $words = trim((string) $words);
    if (mb_strlen($words) < 3) return array();
    $t = kop_clip_tables();
    $suppress = $wpdb->suppress_errors(true);
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT attachment_id FROM {$t['text']} WHERE text LIKE %s ORDER BY updated_at DESC LIMIT %d",
        '%' . $wpdb->esc_like($words) . '%', (int) $limit
    ));
    $wpdb->suppress_errors($suppress);
    return array_map('intval', (array) $ids);
}

/** A few words of $text around the first place $words is found, for a search row. */
function kop_clip_snippet($text, $words, $width = 70) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
    $pos = mb_stripos($text, (string) $words);
    if ($pos === false) return '';
    $start = max(0, $pos - $width);
    $out = mb_substr($text, $start, mb_strlen((string) $words) + 2 * $width);
    $more = $start + mb_strlen($out) < mb_strlen($text);
    // Whole words only at a cut end.
    if ($start > 0) $out = preg_replace('/^\S*\s/u', '', $out);
    if ($more) $out = preg_replace('/\s\S*$/u', '', $out);
    return ($start > 0 ? '... ' : '') . trim($out) . ($more ? ' ...' : '');
}

/**
 * The address the article is linked by: the one printed in a saved web page's header or footer
 * (the script sends it as page_url) when it is a real outside page, else our copy of the scan.
 */
function kop_clip_article_url($page_url, $attachment_url) {
    $page_url = trim((string) $page_url);
    if ($page_url !== '' && preg_match('#^https?://#i', $page_url)) {
        $host = strtolower((string) wp_parse_url($page_url, PHP_URL_HOST));
        $own = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host !== '' && preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $own)) {
            return esc_url_raw($page_url);
        }
    }
    return (string) $attachment_url;
}

/** What the AI is told beyond the processor's own prompt. */
function kop_clip_instructions($title) {
    return 'This text was read by OCR from a scanned or saved copy of a news article, so it may have misread letters, '
        . 'broken lines, page headers and parts of neighbouring articles. Describe only the article about the troubled '
        . 'teen industry. The newspaper name and the date are often printed in a page header or footer; the document '
        . 'was filed under the name "' . $title . '", which may also give them. Correct obvious OCR misreadings in names '
        . 'you return, but never guess at a word you cannot read.';
}

/**
 * The fields kop_ext_insert_news() takes, from the AI's answer (kop_ext_news_ai_fields() shape).
 * The headline falls back to the document's title.
 */
function kop_clip_news_params(array $fields, $title, $url) {
    $p = array(
        'type'      => 'article',
        'url'       => $url,
        'title'     => trim((string) ($fields['title'] ?? '')) !== '' ? $fields['title'] : $title,
        'site_name' => $fields['site_name'] ?? '',
        'author'    => $fields['author'] ?? '',
        'published' => $fields['published'] ?? '',
        'tags'      => $fields['tags'] ?? array(),
        'summary'   => $fields['summary'] ?? '',
        'full'      => 1,
    );
    foreach (array('alternate_title', 'article_type', 'location', 'staff', 'survivors', 'content_warnings', 'facilities', 'details') as $k) {
        if (isset($fields[$k])) $p[$k] = $fields[$k];
    }
    return $p;
}

/**
 * True for a title that is only a file name ("cedu crummel criminal", "CEDU_Experiment", "eagle092510",
 * "Hyde Times Record 06-05-2006pdf", "medium.com-Running My Anger"); a written title stays.
 */
function kop_clip_is_slug_title($title) {
    $t = trim((string) $title);
    if ($t === '') return true;
    if (strpos($t, '_') !== false || strpos($t, ' ') === false) return true;
    if (preg_match('/pdf$/i', $t) && !preg_match('/\.pdf$/i', $t)) return true;
    if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.(com|org|net|edu|gov|us|info)\b/i', $t)) return true;
    return !preg_match('/\p{Lu}/u', $t);
}

/** "Headline (Outlet, 2006-06-05)", the library's way of naming a clipping; '' without a headline. */
function kop_clip_doc_title($headline, $outlet = '', $date = '') {
    $h = trim(preg_replace('/\s+/u', ' ', (string) $headline));
    if ($h === '') return '';
    // A headline set in capitals reads as a sentence-cased title.
    if (!preg_match('/\p{Ll}/u', $h) && function_exists('kop_title_case')) $h = kop_title_case(mb_strtolower($h));
    $bits = array_filter(array(trim((string) $outlet), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? (string) $date : ''));
    return mb_substr($h . ($bits ? ' (' . implode(', ', $bits) . ')' : ''), 0, 250);
}

/**
 * Names a clipping with a file-name title after its article (news row $news_id), logged for Undo.
 * Returns the new title, or '' when the title is kept.
 */
function kop_clip_retitle($id, $news_id, $apply = true) {
    global $wpdb;
    $title = get_the_title($id);
    if (!$news_id || !kop_clip_is_slug_title($title)) return '';
    $pdo = kop_seed_pdo();
    if (!$pdo) return '';
    $st = $pdo->prepare('SELECT article_title, publication_name, publication_date FROM news_submissions WHERE id = ?');
    $st->execute(array((int) $news_id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    $new = kop_clip_doc_title($row['article_title'], $row['publication_name'], substr((string) $row['publication_date'], 0, 10));
    if ($new === '' || $new === $title || kop_clip_is_slug_title($new)) return '';
    if ($apply) {
        wp_update_post(array('ID' => (int) $id, 'post_title' => $new));
        $t = kop_clip_tables();
        $wpdb->update($t['log'], array('prior_title' => $title, 'new_title' => $new), array('attachment_id' => (int) $id));
    }
    return $new;
}

/** The titles pass: every applied clipping with a file-name title and an article, renamed after it. */
function kop_clip_retitle_all($apply, array $ids = array()) {
    global $wpdb;
    kop_clip_install();
    $t = kop_clip_tables();
    $where = "state = 'applied' AND news_id IS NOT NULL AND (new_title IS NULL OR new_title = '')"
        . ($ids ? ' AND attachment_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '');
    $out = array();
    foreach ((array) $wpdb->get_results("SELECT attachment_id, news_id FROM {$t['log']} WHERE {$where}", ARRAY_A) as $r) {
        $old = get_the_title((int) $r['attachment_id']);
        $new = kop_clip_retitle((int) $r['attachment_id'], (int) $r['news_id'], $apply);
        $out[] = array('id' => (int) $r['attachment_id'], 'title' => $old, 'result' => $new === '' ? 'kept' : ($apply ? 'renamed' : 'would rename'), 'new_title' => $new);
    }
    return $out;
}

/** The manifest the script wrote: [{id, src_md5, pdf, txt, pages, page_url, title}, ...]. */
function kop_clip_read_manifest($dir) {
    $m = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
    if (!is_array($m) || !is_array($m['clippings'] ?? null)) {
        throw new RuntimeException('manifest.json missing or unreadable in ' . $dir);
    }
    return $m['clippings'];
}

function kop_clip_log_row($id) {
    global $wpdb;
    $t = kop_clip_tables();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['log']} WHERE attachment_id = %d", (int) $id), ARRAY_A);
}

/** The AI's read of the text, as kop_ext_news_ai_fields() fields; throws on failure. */
function kop_clip_ai_fields($text, $url, $title) {
    $dir = get_stylesheet_directory() . '/api/';
    require_once $dir . 'ai-providers.php';
    require_once $dir . 'news-tags.php';
    require_once $dir . 'lib-news-ai.php';
    if (strlen($text) > 20000) $text = mb_strcut($text, 0, 20000, 'UTF-8') . '... [truncated]';
    $raw = kop_ai_generate_alternating(buildPrompt($text, $url, kop_clip_instructions($title)), array('maxTokens' => 3000));
    return kop_ext_news_ai_fields(parseAIResponse($raw, 'auto'));
}

/** Files the article for one clipping: [result, news id or null, note]. */
function kop_clip_make_news($id, $text, $page_url, $title) {
    $att_url = (string) wp_get_attachment_url($id);
    $url = kop_clip_article_url($page_url, $att_url);
    if (mb_strlen(trim($text)) < 300) return array('short', null, 'only ' . mb_strlen(trim($text)) . ' characters of text');

    kop_ext_load_record_libs();
    $pdo = kop_seed_pdo();
    if (!$pdo) return array('error', null, 'records database not reachable');

    // Already held by link (before spending an AI call): our scan or the printed address.
    $dupes = kop_ext_find_duplicates($pdo, array('type' => 'article', 'url' => $url));
    if (!$dupes && $url !== $att_url) $dupes = kop_ext_find_duplicates($pdo, array('type' => 'article', 'url' => $att_url));
    if ($dupes) return array('duplicate', (int) $dupes[0]['id'], 'already held as ' . $dupes[0]['type'] . ' #' . $dupes[0]['id']);

    $GLOBALS['kop_ai_job'] = 'News clippings';
    try {
        $fields = kop_clip_ai_fields($text, $url, $title);
    } catch (Throwable $e) {
        return array('ai_failed', null, $e->getMessage());
    } finally {
        unset($GLOBALS['kop_ai_job']);
    }
    $p = kop_clip_news_params($fields, $title, $url);
    $dupes = kop_ext_find_duplicates($pdo, $p);
    if ($dupes) return array('duplicate', (int) $dupes[0]['id'], 'already held as ' . $dupes[0]['type'] . ' #' . $dupes[0]['id'] . ' (same headline)');

    $full = kop_ext_news_full_fields($p);
    $full['status'] = 'submitted';
    $note = 'Read by OCR from the news clipping in our media library (document #' . $id . '): ' . $att_url;
    $GLOBALS['kop_ext_quiet_notify'] = true; // one email per clipping would flood the inbox
    try {
        $news_id = kop_ext_insert_news($pdo, $p, 'News clipping OCR', $note, $full);
    } finally {
        unset($GLOBALS['kop_ext_quiet_notify']);
    }
    $row = $pdo->prepare('SELECT json_data FROM news_submissions WHERE id = ?');
    $row->execute(array($news_id));
    $json = json_decode((string) $row->fetchColumn(), true);
    if (!is_array($json)) $json = array();
    $json['source'] = 'news-clipping';
    $json['clippingAttachmentId'] = (int) $id;
    $json['clippingUrl'] = $att_url;
    $pdo->prepare('UPDATE news_submissions SET json_data = ? WHERE id = ?')
        ->execute(array(wp_json_encode($json, JSON_UNESCAPED_UNICODE), $news_id));
    if (function_exists('kop_record_changed')) kop_record_changed('news', array($news_id));
    return array('made', $news_id, '');
}

/**
 * Applies the folder's clippings. $opts: ids (int[]), limit, no_news, news_only (retry the article for
 * clippings already applied whose AI read failed). Returns one report line per clipping.
 */
function kop_clip_import($dir, $apply, array $opts = array()) {
    global $wpdb;
    kop_clip_install();
    $t = kop_clip_tables();
    $only = array_map('intval', $opts['ids'] ?? array());
    $limit = (int) ($opts['limit'] ?? 0);
    $upload = wp_upload_dir(null, false);
    $report = array();
    $done = 0;
    foreach (kop_clip_read_manifest($dir) as $e) {
        $id = (int) ($e['id'] ?? 0);
        if ($id <= 0 || ($only && !in_array($id, $only, true))) continue;
        if ($limit && $done >= $limit) break;
        $line = array('id' => $id, 'title' => (string) ($e['title'] ?? ''));
        $log = kop_clip_log_row($id);
        $retry_news = $log && $log['state'] === 'applied' && in_array($log['news_result'], array('ai_failed', 'error'), true);
        if (!empty($opts['news_only']) && !$retry_news) continue;
        if ($log && $log['state'] === 'applied' && !$retry_news) {
            $report[] = $line + array('result' => 'already applied', 'news' => $log['news_result'], 'news_id' => $log['news_id']);
            continue;
        }
        $file = get_attached_file($id);
        $types = function_exists('kop_dt_types_for') ? kop_dt_types_for($id) : array('news-clipping');
        if (!$file || get_post_type($id) !== 'attachment') {
            $report[] = $line + array('result' => 'skipped: no such document');
            continue;
        }
        if (!in_array('news-clipping', (array) $types, true)) {
            $report[] = $line + array('result' => 'skipped: no longer typed as a news clipping');
            continue;
        }
        $text = (string) @file_get_contents($dir . '/' . basename((string) $e['txt']));
        $pdf = $dir . '/' . basename((string) ($e['pdf'] ?? ''));
        $line['chars'] = mb_strlen(trim($text));
        $done++;

        if ($retry_news) {
            $line['result'] = 'article retry';
        } else {
            $current_md5 = is_readable($file) ? md5_file($file) : '';
            $mime = (string) get_post_mime_type($id);
            $is_pdf = $mime === 'application/pdf';
            $is_image = strpos($mime, 'image/') === 0;
            $swap = ($is_pdf || $is_image) && !empty($e['pdf']) && is_readable($pdf);
            if ($swap && $current_md5 !== (string) ($e['src_md5'] ?? '')) {
                $report[] = $line + array('result' => 'skipped: the file on the site changed since it was read (run the script again)');
                continue;
            }
            $line['file'] = !$swap ? 'kept (no searchable copy)'
                : ($is_pdf ? 'replaced with the searchable copy' : 'switched to a searchable PDF (the picture stays at its address)');
            if (!$apply) {
                $line['result'] = 'would apply';
                $line['article'] = $line['chars'] >= 300 ? 'would be read by the AI' : 'too little text for an article';
                $report[] = $line;
                continue;
            }
            $backup_rel = '';
            $was = array('original_file' => '', 'original_mime' => '', 'original_meta' => null);
            if ($swap && $is_image) {
                // A picture: the document becomes a searchable PDF beside it; the picture file stays, so links to it still open.
                $name = wp_unique_filename(dirname($file), pathinfo($file, PATHINFO_FILENAME) . '.pdf');
                $dest = dirname($file) . '/' . $name;
                if (!copy($pdf, $dest)) {
                    $report[] = $line + array('result' => 'error: could not write the searchable copy');
                    continue;
                }
                $was = array(
                    'original_file' => (string) get_post_meta($id, '_wp_attached_file', true),
                    'original_mime' => $mime,
                    'original_meta' => wp_json_encode(wp_get_attachment_metadata($id)),
                );
                update_attached_file($id, $dest);
                wp_update_post(array('ID' => $id, 'post_mime_type' => 'application/pdf'));
                wp_update_attachment_metadata($id, array('filesize' => filesize($dest)));
            } elseif ($swap) {
                $backup_rel = 'kop-clipping-originals/' . $id . '/' . basename($file);
                wp_mkdir_p(dirname($upload['basedir'] . '/' . $backup_rel));
                if (!copy($file, $upload['basedir'] . '/' . $backup_rel) || md5_file($upload['basedir'] . '/' . $backup_rel) !== $current_md5) {
                    $report[] = $line + array('result' => 'error: could not keep the original');
                    continue;
                }
                if (!copy($pdf, $file)) {
                    $report[] = $line + array('result' => 'error: could not write the searchable copy');
                    continue;
                }
                $meta = wp_get_attachment_metadata($id);
                if (is_array($meta)) {
                    $meta['filesize'] = filesize($file);
                    wp_update_attachment_metadata($id, $meta);
                }
            }
            $prior = $wpdb->get_var($wpdb->prepare("SELECT text FROM {$t['text']} WHERE attachment_id = %d", $id));
            $wpdb->replace($t['text'], array(
                'attachment_id' => $id, 'text' => $text, 'pages' => (int) ($e['pages'] ?? 0),
                'source' => 'ocr', 'updated_at' => current_time('mysql'),
            ));
            $wpdb->replace($t['log'], array(
                'attachment_id' => $id, 'state' => 'applied', 'original_md5' => $current_md5,
                'backup_file' => $backup_rel, 'file_replaced' => $swap ? 1 : 0,
                'original_file' => $was['original_file'], 'original_mime' => $was['original_mime'], 'original_meta' => $was['original_meta'],
                'prior_title' => null, 'new_title' => null,
                'prior_text' => $prior, 'news_id' => null, 'news_result' => '', 'note' => '',
                'applied_at' => current_time('mysql'), 'undone_at' => null,
            ));
            $line['result'] = 'applied';
        }

        if (!empty($opts['no_news'])) {
            $line['news'] = 'not asked';
        } elseif (!$apply) {
            $line['news'] = 'would retry the AI read';
        } else {
            list($res, $news_id, $note) = kop_clip_make_news($id, $text, (string) ($e['page_url'] ?? ''), (string) ($e['title'] ?? get_the_title($id)));
            $wpdb->update($t['log'], array('news_id' => $news_id, 'news_result' => $res, 'note' => $note), array('attachment_id' => $id));
            $line['news'] = $res;
            $line['news_id'] = $news_id;
            if (in_array($res, array('made', 'duplicate'), true)) {
                $renamed = kop_clip_retitle($id, $news_id, true);
                if ($renamed !== '') $line['new_title'] = $renamed;
            }
            if ($note !== '') $line['note'] = $note;
        }
        $report[] = $line;
    }
    if ($apply && function_exists('wp_cache_flush')) wp_cache_flush();
    return $report;
}

/** Puts clippings back as they were (all applied ones, or $ids). */
function kop_clip_undo($apply, array $ids = array()) {
    global $wpdb;
    kop_clip_install();
    $t = kop_clip_tables();
    $upload = wp_upload_dir(null, false);
    $where = "state = 'applied'" . ($ids ? ' AND attachment_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '');
    $report = array();
    foreach ((array) $wpdb->get_results("SELECT * FROM {$t['log']} WHERE {$where}", ARRAY_A) as $log) {
        $id = (int) $log['attachment_id'];
        $line = array('id' => $id, 'title' => get_the_title($id));
        $file = get_attached_file($id);
        $backup = $log['backup_file'] !== '' ? $upload['basedir'] . '/' . $log['backup_file'] : '';
        $switched = $log['file_replaced'] && $log['original_file'] !== '';
        if ($log['file_replaced'] && !$switched && (!$backup || !is_readable($backup))) {
            $report[] = $line + array('result' => 'error: the original copy is missing (' . $log['backup_file'] . ')');
            continue;
        }
        $news = 'none';
        $pdo = null;
        if ($log['news_result'] === 'made' && $log['news_id']) {
            $pdo = kop_seed_pdo();
            $st = $pdo->prepare('SELECT status, json_data FROM news_submissions WHERE id = ?');
            $st->execute(array((int) $log['news_id']));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $json = $row ? json_decode((string) $row['json_data'], true) : null;
            if (!$row) $news = 'already gone';
            elseif ($row['status'] !== 'submitted' || (int) ($json['clippingAttachmentId'] ?? 0) !== $id) $news = 'kept: it was reviewed (#' . $log['news_id'] . ', ' . $row['status'] . ')';
            else $news = 'deleted #' . $log['news_id'];
        }
        $line += array('file' => $log['file_replaced'] ? 'original put back' : 'unchanged', 'news' => $news);
        if (!empty($log['new_title'])) $line['title_back'] = (string) $log['prior_title'];
        if (!$apply) {
            $line['result'] = 'would undo';
            $report[] = $line;
            continue;
        }
        if ($switched) {
            // Back to the picture; the searchable PDF beside it goes.
            update_attached_file($id, $upload['basedir'] . '/' . $log['original_file']);
            wp_update_post(array('ID' => $id, 'post_mime_type' => $log['original_mime']));
            $meta = json_decode((string) $log['original_meta'], true);
            if (is_array($meta)) wp_update_attachment_metadata($id, $meta);
            if ($file && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') @unlink($file);
        } elseif ($log['file_replaced']) {
            copy($backup, $file);
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta)) {
                $meta['filesize'] = filesize($file);
                wp_update_attachment_metadata($id, $meta);
            }
        }
        if ($log['prior_text'] !== null) {
            $wpdb->update($t['text'], array('text' => $log['prior_text'], 'updated_at' => current_time('mysql')), array('attachment_id' => $id));
        } else {
            $wpdb->delete($t['text'], array('attachment_id' => $id));
        }
        if (strpos($news, 'deleted') === 0) {
            $nid = (int) $log['news_id'];
            // The facility pages that listed it are refreshed by id: the links are gone by the time the purge runs.
            $fst = $pdo->prepare('SELECT facility_id FROM news_facility_links WHERE news_id = ?');
            $fst->execute(array($nid));
            $fids = array_map('intval', $fst->fetchAll(PDO::FETCH_COLUMN));
            if ($fids && function_exists('kop_record_changed')) kop_record_changed('facility', $fids);
            $pdo->prepare('DELETE FROM news_submissions WHERE id = ?')->execute(array($nid));
            foreach (array('news_facility_links', 'lawsuit_news_links') as $tbl) {
                try {
                    $pdo->prepare("DELETE FROM {$tbl} WHERE news_id = ?")->execute(array($nid));
                } catch (Throwable $ex) {
                    // table missing on this install
                }
            }
            if (function_exists('kop_record_changed')) kop_record_changed('news', array($nid));
        }
        if (!empty($log['new_title']) && get_the_title($id) === $log['new_title']) {
            wp_update_post(array('ID' => $id, 'post_title' => (string) $log['prior_title']));
        }
        $wpdb->update($t['log'], array('state' => 'undone', 'undone_at' => current_time('mysql')), array('attachment_id' => $id));
        $line['result'] = 'undone';
        $report[] = $line;
    }
    if ($apply && function_exists('wp_cache_flush')) wp_cache_flush();
    return $report;
}

/** Ids of every document whose text is stored, once per request (a small set: the tiles' data-doc-text). */
function kop_clip_text_ids() {
    static $ids = null;
    if ($ids !== null) return $ids;
    global $wpdb;
    $t = kop_clip_tables();
    $suppress = $wpdb->suppress_errors(true);
    $ids = array_flip(array_map('intval', (array) $wpdb->get_col("SELECT attachment_id FROM {$t['text']}")));
    $wpdb->suppress_errors($suppress);
    return $ids;
}

/**
 * GET kop/v1/doc-text-search?q=<words>&ids=1,2,3: which of these documents hold the words, with the words around
 * them ({matches: {id: snippet}}). Public: the documents themselves are; the library's search box (js/doc-type-filter.js).
 */
function kop_clip_rest_search(WP_REST_Request $req) {
    global $wpdb;
    $q = trim(preg_replace('/\s+/u', ' ', (string) $req['q']));
    if (mb_strlen($q) < 3 || mb_strlen($q) > 100) return array('matches' => (object) array());
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $req['ids']))))), 0, 1000);
    if (!$ids) return array('matches' => (object) array());
    $t = kop_clip_tables();
    $suppress = $wpdb->suppress_errors(true);
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT d.attachment_id, d.text FROM {$t['text']} d JOIN {$wpdb->posts} p ON p.ID = d.attachment_id AND p.post_type = 'attachment'
          WHERE d.attachment_id IN (" . implode(',', $ids) . ") AND d.text LIKE %s",
        '%' . $wpdb->esc_like($q) . '%'
    ), ARRAY_A);
    $wpdb->suppress_errors($suppress);
    $out = array();
    foreach ((array) $rows as $r) $out[(string) $r['attachment_id']] = kop_clip_snippet($r['text'], $q, 60);
    return array('matches' => (object) $out);
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/doc-text-search', array(
        'methods'             => 'GET',
        'callback'            => 'kop_clip_rest_search',
        'permission_callback' => '__return_true',
    ));
});
