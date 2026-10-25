<?php
/**
 * "What changed" on data suggestion cards (api/lib-suggestion-changes.php):
 * the record is found, empty and unticked fields never show, a whole-record
 * edit that matches the record is "nothing new", a name with no record is new.
 * And Approve on a mini-form suggestion: its filled fields merge into the
 * record it is about, no second facility and no company named after it.
 * Runs on a scratch copy of the tables it needs from tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-suggestion-changes.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) { fwrite(STDERR, "No mirror at $mirror\n"); exit(2); }
$db_path = sys_get_temp_dir() . '/kop-suggestion-changes-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach ($copy->query("SELECT name, sql FROM src.sqlite_master WHERE type = 'table'
    AND (name LIKE 'wpdl_kop_operator%' OR name LIKE 'wpdl_kop_facility%' OR name IN ('facilities_v2', 'suggested_edits'))")->fetchAll() as $t) {
    $copy->exec($t['sql']);
    $copy->exec("INSERT INTO main.`{$t['name']}` SELECT * FROM src.`{$t['name']}`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });
$GLOBALS['kop_test_wpdb_writes'] = true;
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
$pdo->sqliteCreateFunction('JSON_EXTRACT', function ($j, $p) {
    $d = json_decode((string) $j, true);
    foreach (explode('.', substr($p, 2)) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) return null; $d = $d[$k]; }
    return is_array($d) ? json_encode($d) : $d;
}, 2);
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
require_once dirname(__DIR__) . '/api/lib-suggested-edits.php';
require_once dirname(__DIR__) . '/api/lib-suggestion-changes.php';

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
};

// A facility with a page, a state, a type and a status to compare against.
$row = $pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_extract(json_data, '$.location.state') IS NOT NULL
    AND json_extract(json_data, '$.facilityDetails.type') <> '' AND json_extract(json_data, '$.operatingPeriod.status') = 'Open' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$doc = json_decode($row['json_data'], true);
$name = $doc['identification']['name'];
$state_name = strtolower(str_replace(' ', '-', kop_facility_states()[$doc['location']['state']] ?? ''));

// The mini-form's shape: every field it shows, blank unless typed in, every flag false.
$mini = function (array $over) use ($name) {
    $f = array(
        'identification' => array('name' => $name, 'currentName' => '', 'currentOperator' => '', 'currentOwners' => array(), 'otherNames' => array(), 'knownReferrers' => array()),
        'operatingPeriod' => array('status' => '', 'yearsOfOperation' => ''),
        'facilityDetails' => array('type' => '', 'capacity' => null, 'gender' => '', 'ageRange' => array('min' => null, 'max' => null)),
        'address' => '', 'profileLinks' => array(), 'staff' => array('notableStaff' => array()), 'notes' => array(),
    );
    return array('facilities' => array(array_replace_recursive($f, $over)), 'name' => $name);
};

$c = kop_suggestion_changes($pdo, $mini(array('notes' => array('A new note from a reader.'))), 'Public mini-form submission (facility) for "' . $name . '" from /' . $state_name . '/', 'wpdl_');
$e = $c['entries'][0] ?? null;
$check('mini-form: finds the record by name and the state page it came from', $e && $e['facility_id'] === (int) $row['id'], json_encode($e['facility_id'] ?? null));
$check('mini-form: only the note shows (blank fields and unticked flags are no information)',
    $e && count($e['changes']) === 1 && $e['changes'][0]['label'] === 'Notes' && $e['changes'][0]['added'] === array('A new note from a reader.'),
    json_encode($e['changes'] ?? null));

$c = kop_suggestion_changes($pdo, $mini(array('facilityDetails' => array('type' => 'Wilderness program'))), 'from /' . $state_name . '/', 'wpdl_');
$ch = $c['entries'][0]['changes'][0] ?? null;
$check('a changed value shows as on file -> suggested', $ch && $ch['kind'] === 'changed' && $ch['label'] === 'Program type' && $ch['before'] === $doc['facilityDetails']['type'] && $ch['after'] !== '', json_encode($ch));

$c = kop_suggestion_changes($pdo, $mini(array('facilityDetails' => array('type' => mb_strtoupper($doc['facilityDetails']['type'])))), 'from /' . $state_name . '/', 'wpdl_');
$check('the same value in other case is nothing new', !$c['entries'] && $c['unchanged'] === array($name), json_encode($c));

$whole = $doc;
$whole['facility_id'] = (int) $row['id'];
$c = kop_suggestion_changes($pdo, array('facilities' => array($whole)), 'Fixed it', 'wpdl_');
$check('a whole-record edit equal to the record is "nothing new"', !$c['entries'] && $c['unchanged'] === array($name), json_encode($c['entries']));

$whole['operatingPeriod']['status'] = '';
$whole['facilityDetails']['type'] = '';
$c = kop_suggestion_changes($pdo, array('facilities' => array($whole)), 'Fixed it', 'wpdl_');
$labels = array_column($c['entries'][0]['changes'] ?? array(), 'kind', 'label');
$check('a whole-record edit shows a field it empties', ($labels['Program type'] ?? '') === 'removed', json_encode($labels));
$check('...and only those (status emptied = Unknown, which Approve would write)', array_keys($labels) == array('Status', 'Program type') || array_keys($labels) == array('Program type', 'Status'), json_encode($labels));

$c = kop_suggestion_changes($pdo, array('facilities' => array(array('identification' => array('name' => 'Zzq No Such Program Anywhere'), 'facilityDetails' => array('type' => 'RTC')))), '', 'wpdl_');
$e = $c['entries'][0] ?? null;
$check('a name with no record is marked new, with what was sent (not its name again)', $e && $e['is_new'] && count($e['changes']) === 1 && $e['changes'][0]['label'] === 'Program type', json_encode($e));

$c = kop_suggestion_changes($pdo, array('referrerConsultants' => array(array('firstName' => 'Ann', 'lastName' => 'Lee', 'role' => '', 'affiliations' => array()))), '', 'wpdl_');
$check('other kinds show what was sent, blanks left out', $c['other'] === array(array('label' => 'Referrer consultants', 'after' => 'Ann, Lee')), json_encode($c['other']));

// Form notes (#56): a note on a list item is kept under a random key that names no field.
$note = array('id' => 'field-1791173405434-9s6b0bg7v_note_1791173405434', 'text' => 'A field note from a reader.', 'timestamp' => '2026-10-05T04:12:42.215Z');
$c = kop_suggestion_changes($pdo, $mini(array('fieldNotes' => array('field-1791173405434-9s6b0bg7v' => array($note), 'ttiPractices.hasRestraint' => array($note)))), 'from /' . $state_name . '/', 'wpdl_');
$labels = array_column($c['entries'][0]['changes'] ?? array(), 'added', 'label');
$check('a note under a random key reads "Field notes" with only its text', ($labels['Field notes'] ?? null) === array('A field note from a reader.'), json_encode($labels));
$check('a note under a field\'s path names the field', isset($labels['Field notes: has restraint']), json_encode(array_keys($labels)));

// Rows saved since 2026-10-25 hold the v2 shape (save-suggestion.php, kop_suggestion_normalize_facilities()); older rows are legacy.
// The card and Approve give the same answer for either.
$v2 = function (array $payload) { return kop_suggestion_normalize_facilities($payload, 'companies'); };
$stored = $v2($mini(array('notes' => array('A new note from a reader.'))));
$check('v2 row: every facility stored in the canonical shape', array_keys($stored['facilities'][0]) === kop_facility_top_level_keys(), json_encode(array_keys($stored['facilities'][0])));
$check('v2 row: providers and other kinds keep the shape their tables read',
    kop_suggestion_normalize_facilities(array('facilities' => array(array('providerDetails' => array('x' => 1)))), 'providers') === array('facilities' => array(array('providerDetails' => array('x' => 1))))
    && kop_suggestion_normalize_facilities(array('facilities' => array(array('a' => 1))), 'referrers') === array('facilities' => array(array('a' => 1))));
$same = true;
$cases = array(
    array('notes' => array('A new note from a reader.')),
    array('facilityDetails' => array('type' => 'Wilderness program')),
    array('facilityDetails' => array('type' => mb_strtoupper($doc['facilityDetails']['type']))),
    array('fieldNotes' => array('field-1791173405434-9s6b0bg7v' => array($note), 'ttiPractices.hasRestraint' => array($note))),
);
foreach ($cases as $over) {
    $reason = 'Public mini-form submission (facility) for "' . $name . '" from /' . $state_name . '/';
    $a = kop_suggestion_changes($pdo, $mini($over), $reason, 'wpdl_');
    $b = kop_suggestion_changes($pdo, $v2($mini($over)), $reason, 'wpdl_');
    if ($a !== $b) { $same = false; echo '  legacy ' . json_encode($a) . "
  v2     " . json_encode($b) . "
"; }
}
$check('v2 row: the card shows the same changes as for the legacy row', $same);
$c = kop_suggestion_changes($pdo, $v2(array('facilities' => array(array('identification' => array('name' => 'Zzq No Such Program Anywhere'), 'facilityDetails' => array('type' => 'RTC'))))), '', 'wpdl_');
$e = $c['entries'][0] ?? null;
$check('v2 row: a name with no record is new, with only what was sent', $e && $e['is_new'] && count($e['changes']) === 1 && $e['changes'][0]['label'] === 'Program type', json_encode($e));
$sent_l = $mini(array('notes' => array('Same either way.'), 'facilityDetails' => array('gender' => 'Girls')));
$reason = 'Public mini-form submission (facility) for "' . $name . '" from /' . $state_name . '/';
$ra = kop_suggestion_resolve_entries($pdo, $sent_l['facilities'], $reason, 'wpdl_');
$rb = kop_suggestion_resolve_entries($pdo, $v2($sent_l)['facilities'], $reason, 'wpdl_');
$check('v2 row: Approve merges the same document onto the record', $ra === $rb && $rb['all_on_file'] && (int) $rb['facilities'][0]['facility_id'] === (int) $row['id']);
$hidden = array(array('id' => 'h1', 'text' => 'Hidden account.', 'source' => '', 'date' => '', 'movedFrom' => '', 'publish' => false));
$kept = kop_preserve_hidden_testimony($v2(array('facilities' => array(array('facility_id' => (int) $row['id'], 'identification' => array('name' => $name)))))['facilities'],
    array(array('facility_id' => (int) $row['id'], 'identification' => array('name' => $name), 'survivorTestimony' => $hidden)));
$check('v2 row: Approve still puts back testimony the submitter never saw', ($kept[0]['survivorTestimony'][0]['id'] ?? '') === 'h1', json_encode($kept[0]['survivorTestimony'] ?? null));

// Approve: a note and a gender, sent with Submit info (no state, no facility_id).
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_');
$sent = $mini(array('notes' => array('A note sent with Submit info.'), 'facilityDetails' => array('gender' => 'Girls')));
$res = kop_suggestion_resolve_entries($pdo, $sent['facilities'], 'Public mini-form submission (facility) for "' . $name . '" from /' . $state_name . '/', 'wpdl_');
$m = $res['facilities'][0];
$girls = kop_facility_normalize(array('identification' => array('name' => 'x'), 'facilityDetails' => array('gender' => 'Girls')))['facilityDetails']['gender'];
$check('approve: the entry becomes its record (facility_id), every facility on file', (int) $m['facility_id'] === (int) $row['id'] && $res['all_on_file']);
$check('approve: what the entry left empty keeps the record\'s value',
    $m['facilityDetails']['type'] === $doc['facilityDetails']['type'] && $m['operatingPeriod']['status'] === $doc['operatingPeriod']['status']
    && $m['location'] == $doc['location'] && $m['staff'] == $doc['staff'], json_encode(array($m['facilityDetails'], $m['operatingPeriod']['status'])));
$check('approve: a filled value replaces, a list item is added to what was there',
    $m['facilityDetails']['gender'] === $girls && end($m['notes']) === 'A note sent with Submit info.' && count($m['notes']) === count($doc['notes']) + 1);

$count = function () use ($pdo) { return (int) $pdo->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn(); };
$ops = function () use ($pdo) { return (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_operators')->fetchColumn(); };
$n = $count();
$o = $ops();
kop_v2_save_facility_entries($pdo, 'wpdl_', $res['facilities'], array());
$after = kop_facility_load((int) $row['id'], $opts)['doc'];
$check('approve: saved onto the record, no second facility and no new company', $count() === $n && $ops() === $o, "$n -> " . $count() . ", companies $o -> " . $ops());
$check('approve: the record keeps its type and staff and has the note',
    $after['facilityDetails']['type'] === $doc['facilityDetails']['type'] && count($after['staff']['notableStaff']) === count($doc['staff']['notableStaff'])
    && in_array('A note sent with Submit info.', $after['notes'], true) && $after['facilityDetails']['gender'] === $girls);

// A name with no record still makes a new facility, as the card says.
$res = kop_suggestion_resolve_entries($pdo, array(array('identification' => array('name' => 'Zzq No Such Program Anywhere'))), '', 'wpdl_');
$check('approve: a name with no record is left to become a new facility', empty($res['facilities'][0]['facility_id']) && !$res['all_on_file']);

// Undo (api/lib-suggestion-undo.php), on the same scratch copy. The company save uses MySQL's INSERT IGNORE and GET_LOCK.
class KOP_Sugg_Test_PDO extends PDO {
    // A new facility's upserts never meet an existing row here, so "ON DUPLICATE KEY UPDATE ..." is an insert-or-ignore.
    private function fix($sql) {
        $sql = (string) $sql;
        if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $sql)) {
            $sql = preg_replace('/^\s*INSERT\s+INTO\b/i', 'INSERT OR IGNORE INTO', preg_replace('/\s*ON\s+DUPLICATE\s+KEY\s+UPDATE.*$/is', '', $sql));
        }
        return preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
    }
    public function prepare(string $query, array $options = array()): PDOStatement|false { return parent::prepare($this->fix($query), $options); }
    public function exec(string $statement): int|false { return parent::exec($this->fix($statement)); }
}
$mp = new KOP_Sugg_Test_PDO('sqlite:' . $db_path);
$mp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mp->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$mp->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$mp->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
$mp->sqliteCreateFunction('JSON_EXTRACT', function ($j, $p) {
    $d = json_decode((string) $j, true);
    foreach (explode('.', substr($p, 2)) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) return null; $d = $d[$k]; }
    return is_array($d) ? json_encode($d) : $d;
}, 2);
$mp->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$sid = (int) $mp->query('SELECT MAX(id) FROM suggested_edits')->fetchColumn() + 1;
$mp->prepare("INSERT INTO suggested_edits (id, master_id, edited_json_data, status, reason) VALUES (?, ?, '{}', 'approved', '')")->execute(array($sid, $name));
$raw = function ($fid) use ($mp) { $st = $mp->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?'); $st->execute(array($fid)); return $st->fetchColumn(); };
$links = function ($fid) use ($mp) {
    $a = $mp->prepare('SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?'); $a->execute(array($fid));
    $b = $mp->prepare('SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = ?'); $b->execute(array($fid));
    return (int) $a->fetchColumn() . '/' . (int) $b->fetchColumn();
};

// 1. A mini-form note merged onto the record, then undone.
$fid = (int) $row['id'];
$before_raw = $raw($fid);
$before_links = $links($fid);
$res = kop_suggestion_resolve_entries($mp, $mini(array('notes' => array('Undo me.')))['facilities'], 'from /' . $state_name . '/', 'wpdl_');
$snap = kop_sugg_undo_snapshot($mp, 'wpdl_', $res['facilities'], null, null);
kop_v2_save_facility_entries($mp, 'wpdl_', $res['facilities'], array());
kop_sugg_undo_store($sid, kop_sugg_undo_finish($mp, 'wpdl_', $snap));
$check('undo: the approval changed the record', in_array('Undo me.', json_decode($raw($fid), true)['notes'], true));
$u = kop_undo_suggested_edit($mp, $sid, 'wpdl_');
$st = $mp->prepare('SELECT status FROM suggested_edits WHERE id = ?'); $st->execute(array($sid));
$check('undo: the record is exactly as before, its links too, and the suggestion waits again',
    $u['success'] && $raw($fid) === $before_raw && $links($fid) === $before_links && $st->fetchColumn() === 'pending', $u['message'] . $u['error']);
$check('undo: twice is refused (no copy left)', !kop_undo_suggested_edit($mp, $sid, 'wpdl_')['success']);

// 2. An approval that makes a new facility under a new company: Undo removes both.
$mp->prepare("UPDATE suggested_edits SET status = 'approved' WHERE id = ?")->execute(array($sid));
$n = (int) $mp->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn();
$o = (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_operators')->fetchColumn();
$j = (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_operator_facilities')->fetchColumn();
$l = (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_facility_locations')->fetchColumn();
$new = array(array('identification' => array('name' => 'Zzq Undo Test Program'), 'location' => array('city' => 'Erda', 'state' => 'UT'), 'facilityDetails' => array('type' => 'RTC')));
$snap = kop_sugg_undo_snapshot($mp, 'wpdl_', $new, 'Zzq Undo Test Company', null);
kop_v2_save_form_project($mp, 'wpdl_', 'Zzq Undo Test Company', array('facilities' => $new), 'companies', array('partial' => true, 'timestamp' => gmdate('c')));
$snap = kop_sugg_undo_finish($mp, 'wpdl_', $snap);
kop_sugg_undo_store($sid, $snap);
$check('undo: the approval made one facility and one company', count($snap['created']) === 1 && $snap['operator_created']
    && (int) $mp->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn() === $n + 1, json_encode(array($snap['created'], $snap['operator_created'])));
$u = kop_undo_suggested_edit($mp, $sid, 'wpdl_');
$check('undo: the new facility, its company and their links are gone',
    $u['success'] && (int) $mp->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn() === $n
    && (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_operators')->fetchColumn() === $o
    && (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_operator_facilities')->fetchColumn() === $j
    && (int) $mp->query('SELECT COUNT(*) FROM wpdl_kop_facility_locations')->fetchColumn() === $l, $u['message'] . $u['error']);

// 3. A record changed after the approval is never written over.
$mp->prepare("UPDATE suggested_edits SET status = 'approved' WHERE id = ?")->execute(array($sid));
$res = kop_suggestion_resolve_entries($mp, $mini(array('notes' => array('Second note.')))['facilities'], 'from /' . $state_name . '/', 'wpdl_');
$snap = kop_sugg_undo_snapshot($mp, 'wpdl_', $res['facilities'], null, null);
kop_v2_save_facility_entries($mp, 'wpdl_', $res['facilities'], array());
kop_sugg_undo_store($sid, kop_sugg_undo_finish($mp, 'wpdl_', $snap));
$later = json_decode($raw($fid), true);
$later['notes'][] = 'Someone edited it afterwards.';
$mp->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($later), $fid));
$u = kop_undo_suggested_edit($mp, $sid, 'wpdl_');
$check('undo: refused when the record changed since, naming it, and nothing is written',
    !$u['success'] && strpos($u['error'], $name) !== false && in_array('Someone edited it afterwards.', json_decode($raw($fid), true)['notes'], true), $u['error']);
$mp = null;

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
