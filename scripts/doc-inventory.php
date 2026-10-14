<?php
/**
 * Every document filed under each facility record, offline against
 * tmp/prod.sqlite (docs/PLAN.md 3.21). Read-only.
 *
 * The same library the facility page prints ("Documents on file": the
 * record's FileBird folder from kop_facility_pages_index(), subfolders merged
 * by name, kop_get_facility_doc_tree()), plus the current wiki entries linked
 * to each record (kop_wiki_upd_facility_id()), so kop-pipeline's
 * scripts/doc-text.py can fetch and read what we hold for a program before
 * anyone writes that nothing is known about it.
 *
 * Writes tmp/doc-read/inventory.json:
 *   {built, records: [{facility_id, name, slug, state, folder, wiki: [entry ids],
 *     docs: [{id, title, mime, folder, file}]}]}
 * file = the attachment's path under wp-content/uploads/.
 *
 * Usage: php -d extension=pdo_sqlite -d extension=mbstring scripts/doc-inventory.php [--db=tmp/prod.sqlite] [--out=FILE] [--id=12293,...]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::', 'id::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_file = $args['out'] ?? (dirname(__DIR__) . '/tmp/doc-read/inventory.json');
$want = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
ini_set('memory_limit', '4G');

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';

$t = microtime(true);
$index = kop_facility_pages_index();

// Current wiki entries by the record they are linked to.
$wiki = array();
foreach (kop_wiki_upd_entries($pdo) as $entry_id => $e) {
    $fid = kop_wiki_upd_facility_id($e, $pdo);
    if ($fid) $wiki[(int) $fid][] = (int) $entry_id;
}

$files = array();
foreach ($pdo->query("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'") as $r) {
    $files[(int) $r['post_id']] = (string) $r['meta_value'];
}

$states = array();
foreach ($pdo->query('SELECT id, state FROM facilities_v2') as $r) $states[(int) $r['id']] = (string) $r['state'];

$records = array();
$doc_total = 0;
foreach ($index['ids'] as $fid => $e) {
    $fid = (int) $fid;
    if ($want && !in_array($fid, $want, true)) continue;
    $folder = (int) ($e['folder'] ?? 0);
    if ($folder <= 0 && empty($wiki[$fid])) continue;
    $docs = array();
    if ($folder > 0) {
        $tree = kop_get_facility_doc_tree($folder, true);
        $walk = function ($list, $subs, $path) use (&$walk, &$docs, $files) {
            foreach ((array) $list as $f) {
                $id = (int) $f->ID;
                if (isset($docs[$id])) continue;
                $docs[$id] = array(
                    'id'     => $id,
                    'title'  => (string) $f->post_title,
                    'mime'   => (string) $f->post_mime_type,
                    'folder' => $path,
                    'file'   => $files[$id] ?? '',
                );
            }
            foreach ((array) $subs as $node) {
                $walk($node['attachments'] ?? array(), $node['children'] ?? array(), $path . '/' . $node['name']);
            }
        };
        $walk($tree['files'], $tree['subfolders'], '');
    }
    if (!$docs && empty($wiki[$fid])) continue;
    $doc_total += count($docs);
    $records[] = array(
        'facility_id' => $fid,
        'name'        => (string) ($e['name'] ?? ''),
        'slug'        => (string) ($e['slug'] ?? ''),
        'state'       => $states[$fid] ?? '',
        'folder'      => $folder,
        'wiki'        => $wiki[$fid] ?? array(),
        'docs'        => array_values($docs),
    );
}

if (!is_dir(dirname($out_file))) mkdir(dirname($out_file), 0777, true);
file_put_contents($out_file, json_encode(array('built' => gmdate('c'), 'records' => $records), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
printf("%d records, %d documents, %d with a wiki entry -> %s (%.0fs)\n", count($records), $doc_total,
    count(array_filter($records, function ($r) { return $r['wiki']; })), $out_file, microtime(true) - $t);
