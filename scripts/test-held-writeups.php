<?php
/**
 * Offline check of the two write-up holds (inc/record-writeups.php):
 *  - a record made from a Woodbury card is unlisted (no public page) until
 *    its write-up is approved, and records already public are untouched;
 *  - a company's About on /operator/<slug>/ is its approved write-up when
 *    there is one, the notes-built About otherwise.
 * Runs on a scratch COPY of the SQLite mirror (the write-up tables are added
 * to the copy); the mirror itself is never written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-held-writeups.php [--db=tmp/prod.sqlite]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$args = getopt('', array('db::'));
$src = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($src)) { fwrite(STDERR, "No mirror at $src.\n"); exit(2); }
$copy = sys_get_temp_dir() . '/kop-held-writeups-' . getmypid() . '.sqlite';
copy($src, $copy);
register_shutdown_function(function () use ($copy) { @unlink($copy); });
$db_path = $copy;

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/operator-pages.php';
require_once dirname(__DIR__) . '/inc/profile-notes.php';
require_once dirname(__DIR__) . '/inc/record-writeups.php';
$GLOBALS['kop_test_options']['kop_record_writeups_db'] = KOP_RECORD_WRITEUPS_DB_VERSION;

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$pdo = $GLOBALS['pdo'] ?? null;
$wpdb->query("CREATE TABLE IF NOT EXISTS wpdl_kop_record_writeups (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0,
    draft TEXT NULL, published TEXT NULL, updated_at TEXT NULL)");

echo "-- New records held --\n";
$index = kop_facility_pages_index(true);
$ids = array_keys($index['ids']);
$check('the mirror has facility pages', count($ids) > 10);
$fid = $ids[0];
$other = $ids[1];
$slug = $index['ids'][$fid]['slug'];
$fp_before = kop_facility_pages_fingerprint();
kop_wu_hold_new_record($fid);
$check('the record is in the unlisted option', in_array($fid, kop_wu_unlisted_ids(), true));
$check('the fingerprint moves', kop_facility_pages_fingerprint() !== $fp_before);
$held = kop_facility_pages_index(true);
$check('a held record has no page', !isset($held['ids'][$fid]) && !isset($held['slugs'][$slug]));
$check('every other page is untouched', count($held['ids']) === count($index['ids']) - 1 && isset($held['ids'][$other]));
$check('the record is queued for a write-up', isset(kop_wu_changed()[$fid]));
kop_wu_release_record($fid);
$check('approving releases it', !in_array($fid, kop_wu_unlisted_ids(), true) && isset(kop_facility_pages_index(true)['ids'][$fid]));
$check('a record never held stays public', isset(kop_facility_pages_index(true)['ids'][$other]));

echo "\n-- Company About --\n";
$oindex = kop_operator_pages_index(true);
$oid = 0;
foreach ($oindex['ids'] as $id => $e) {
    $d = kop_operator_page_data($id);
    if ($d && !empty($d['notes'])) { $oid = (int) $id; $plain = $d; break; }
}
$check('a company with notes is found', $oid > 0);
$check('without a write-up the About comes from the notes', empty($plain['about']['writeup']));
$text = "Made-up write-up sentence one ([source](https://example.org/a)).\n\nA second paragraph.";
kop_wu_put(kop_wu_operator_key($oid), array(), array('paragraphs' => kop_wu_paragraphs_from_text($text), 'covered' => array_map('kop_wu_note_key', $plain['notes']), 'by' => 'test', 'at' => gmdate('Y-m-d H:i:s')));
$with = kop_operator_page_data($oid);
$check('an approved write-up is the About', !empty($with['about']['writeup']) && count($with['about']['paragraphs']) === 2);
$check('its text is printed', strpos(json_encode($with['about']['paragraphs']), 'Made-up write-up sentence one') !== false);
$check('notes it covers are not printed again', empty($with['about']['also']) || $with['about']['also'] === ($plain['about']['also'] ?? array()));
$fac = kop_wu_get($ids[0]);
$check('a facility id never reads a company write-up', empty($fac['published']));

echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
