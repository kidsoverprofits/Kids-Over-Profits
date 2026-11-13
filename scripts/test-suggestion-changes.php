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
$check('other kinds show what was sent, blanks left out', count($c['other']) === 1 && $c['other'][0]['label'] === 'Referrer consultants' && $c['other'][0]['after'] === 'Ann, Lee', json_encode($c['other']));

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
$check('v2 row: referrers, transporters, marketers and trade orgs keep the shape their tables read',
    kop_suggestion_normalize_facilities(array('facilities' => array(array('a' => 1))), 'referrers') === array('facilities' => array(array('a' => 1)))
    && kop_suggestion_normalize_facilities(array('facilities' => array(array('a' => 1))), 'transporters') === array('facilities' => array(array('a' => 1))));
$kept_ids = kop_suggestion_normalize_facilities(array('facilities' => array(array('identification' => array('name' => 'X', 'pastOwners' => array('P'), 'websites' => array('https://example.org'), 'currentOwner' => 'C', 'nickname' => 'N')))), 'companies');
$f0 = $kept_ids['facilities'][0];
$check('v2 row: past owners and websites land in the record (pastOwners, profileLinks)',
    $f0['identification']['pastOwners'] === array('P') && in_array('https://example.org', $f0['profileLinks'], true), json_encode(array($f0['identification']['pastOwners'], $f0['profileLinks'])));
$check('v2 row: identification fields the schema lacks are kept under legacy, not dropped',
    ($f0['legacy']['identification'] ?? null) === array('nickname' => 'N'), json_encode($f0['legacy'] ?? null));
$stash = kop_facility_normalize(array('schema_version' => KOP_FACILITY_SCHEMA_VERSION, 'identification' => array('name' => 'X'),
    'legacy' => array('identification' => array('pastOwners' => array('Q'), 'websites' => array('https://example.net')))));
$check('a row stashed before the field existed moves into the record on approval',
    $stash['identification']['pastOwners'] === array('Q') && in_array('https://example.net', $stash['profileLinks'], true) && !isset($stash['legacy']['identification']),
    json_encode(array($stash['identification']['pastOwners'], $stash['legacy'])));
// Providers are facility documents too (providers_master holds v2 since 2026-10-25): approval's naming and merge key read either shape.
$prov_legacy = array('category' => 'providers', 'facilities' => array(array('identification' => array('name' => 'Zzq Clinic'),
    'locationDetails' => array('city' => 'Billings', 'state' => 'MT'), 'location' => 'Billings, MT', 'address' => '1 Main St, Billings, MT',
    'providerDetails' => array('careTypes' => array('hasInpatient' => true), 'ttiReferrals' => array('Zzq Ranch')))));
$prov_v2 = kop_suggestion_normalize_facilities($prov_legacy, 'providers');
$check('providers: stored as v2, provider details at the top', array_keys($prov_v2['facilities'][0]) === kop_facility_top_level_keys()
    && ($prov_v2['facilities'][0]['providerDetails']['ttiReferrals'] ?? null) === array('Zzq Ranch'), json_encode(array_keys($prov_v2['facilities'][0])));
$check('providers: filed under the same state from either shape', kop_provider_project_name($prov_legacy) === kop_provider_project_name($prov_v2)
    && kop_provider_project_name($prov_v2) !== '', kop_provider_project_name($prov_v2));
$merged = kop_merge_location_facilities(array($prov_legacy['facilities'][0]), $prov_v2['facilities']);
$check('providers: a v2 entry for the same provider is keyed by its own place', count(kop_merge_location_facilities($prov_v2['facilities'], $prov_v2['facilities'])) === 1
    && kop_build_location_facility_key($prov_v2['facilities'][0]) === 'zzq clinic|1 main st, billings, mt|billings, mt|billings|mt', kop_build_location_facility_key($prov_v2['facilities'][0]));
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

// An app correction names its record (legacy.facilityId): the record is found by it, and the entry is merged, not written over it.
$app = kop_suggestion_normalize_facilities(array('facilities' => array(array('identification' => array('name' => 'A different name entirely'),
    'facilityId' => (int) $row['id'], 'notes' => array('From the app.')))), 'companies');
$res = kop_suggestion_resolve_entries($pdo, $app['facilities'], 'Correction, sent from the app.', 'wpdl_');
$check('app correction: found by the id it names, merged as a partial', (int) ($res['facilities'][0]['facility_id'] ?? 0) === (int) $row['id']
    && $res['facilities'][0]['identification']['name'] === $after['identification']['name'] && in_array('From the app.', $res['facilities'][0]['notes'], true));

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

// Fixing a suggestion on its card before Approve (kop_suggestion_edit(), action 'edit_suggestion').
$sent = $mini(array(
    'identification' => array('currentOperator' => 'This is not a Charter program.', 'otherNames' => array('A sentence that is really a note about the place.')),
    'staff' => array('notableStaff' => array('Brittney-house manager')),
    'notes' => array('Teh staff yelled.'),
    'facilityDetails' => array('ageRange' => array('min' => 12, 'max' => 18)),
));
$reason = 'from /' . $state_name . '/';
$card = function ($payload) use ($pdo, $reason) { return kop_suggestion_changes($pdo, $payload, $reason, 'wpdl_'); };
$row_for = function ($c, $label) { foreach ($c['entries'][0]['changes'] ?? array() as $ch) if ($ch['label'] === $label) return $ch; return null; };
$c = $card($sent);
$op = $row_for($c, 'Operator');
$check('edit: rows carry their field and the entry its index; move targets are listed',
    $op && $op['path'] === 'identification.currentOperator' && $op['editable'] && $c['entries'][0]['index'] === 0 && count($c['targets']) > 10, json_encode($op));
$notes = $row_for($c, 'Notes');
$check('edit: a new list item carries its place and its text', $notes && $notes['items'][0]['text'] === 'Teh staff yelled.' && $notes['items'][0]['parts'][0]['key'] === '', json_encode($notes['items'] ?? null));

$e = kop_suggestion_edit($sent, array('entry' => 0, 'op' => 'item', 'path' => 'notes', 'index' => $notes['items'][0]['index'], 'parts' => array('' => 'The staff yelled.')));
$check('edit: a spelling fix in a note', ($row_for($card($e), 'Notes')['added'] ?? null) === array('The staff yelled.'), json_encode($row_for($card($e), 'Notes')));

$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'move', 'path' => 'identification.currentOperator', 'to' => 'notes'));
$n = $row_for($card($e), 'Notes');
$check('edit: Operator text moved to Notes leaves Operator empty',
    !$row_for($card($e), 'Operator') && $n && in_array('This is not a Charter program.', $n['added'], true), json_encode($card($e)['entries'][0]['changes']));

$on = $row_for($card($e), 'Other names');
$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'move', 'path' => 'identification.otherNames', 'index' => $on['items'][0]['index'], 'to' => 'notes'));
$check('edit: a list item moved to another list', !$row_for($card($e), 'Other names') && in_array('A sentence that is really a note about the place.', $row_for($card($e), 'Notes')['added'], true));

$st = $row_for($card($e), 'Staff');
$parts = array_column($st['items'][0]['parts'], 'value', 'key');
$check('edit: a person shows as name, role and past jobs', array_keys($parts) === array('name', 'role', 'pastJobs') && $parts['name'] === 'Brittney-house manager', json_encode($st['items'][0]['parts']));
$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'item', 'path' => 'staff.notableStaff', 'index' => $st['items'][0]['index'], 'parts' => array('name' => 'Brittney', 'role' => 'House manager')));
$doc = kop_extract_project_data($e)['facilities'][0];
$check('edit: "Name-role" split into its parts', ($doc['staff']['notableStaff'][0] ?? null) === array('name' => 'Brittney', 'role' => 'House manager', 'pastJobs' => ''), json_encode($doc['staff']['notableStaff']));

$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'set', 'path' => 'facilityDetails.ageRange', 'value' => '13-17'));
$check('edit: ages as "13-17"', kop_extract_project_data($e)['facilities'][0]['facilityDetails']['ageRange'] === array('min' => 13, 'max' => 17));
$threw = function ($fn) { try { $fn(); return ''; } catch (RuntimeException $x) { return $x->getMessage(); } };
$check('edit: words in a number field are refused', $threw(function () use ($e) { kop_suggestion_edit($e, array('entry' => 0, 'op' => 'set', 'path' => 'facilityDetails.capacity', 'value' => 'about 60')); }) !== '');
$e2 = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'set', 'path' => 'location.city', 'value' => 'Somewhere'));
$e2 = kop_suggestion_edit($e2, array('entry' => 0, 'op' => 'set', 'path' => 'identification.currentName', 'value' => 'Below the campground'));
$msg = $threw(function () use ($e2) { kop_suggestion_edit($e2, array('entry' => 0, 'op' => 'move', 'path' => 'identification.currentName', 'to' => 'location.city')); });
$check('edit: a move into a text field that already says something is refused', strpos($msg, 'Somewhere') !== false, $msg);
$n = $row_for($card($e), 'Notes');
$idx = null;
foreach ($n['items'] as $it) if ($it['text'] === 'The staff yelled.') $idx = $it['index'];
$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'remove', 'path' => 'notes', 'index' => $idx));
$check('edit: Remove takes one item out and keeps the rest', !in_array('The staff yelled.', $row_for($card($e), 'Notes')['added'], true) && count($row_for($card($e), 'Notes')['added']) === 2);
$e = kop_suggestion_edit($e, array('entry' => 0, 'op' => 'move', 'path' => 'notes', 'index' => 0, 'to' => 'survivorTestimony'));
$t = kop_extract_project_data($e)['facilities'][0]['survivorTestimony'][0] ?? null;
$check('edit: a note moved to survivor testimony keeps its words exactly and is not published', $t && $t['text'] !== '' && $t['publish'] === false, json_encode($t));
$check('edit: the record is still found after the edits (same entry, same record)', ($card($e)['entries'][0]['facility_id'] ?? null) === (int) $row['id']);

// Wrapped payloads ({data: {...}}) are edited at their own depth.
$wrapped = array('data' => $sent, 'category' => 'locations');
$w = kop_suggestion_edit($wrapped, array('entry' => 0, 'op' => 'remove', 'path' => 'identification.currentOperator'));
$check('edit: a wrapped payload keeps its wrapper', isset($w['data']['facilities'][0]) && $w['category'] === 'locations' && $w['data']['facilities'][0]['identification']['currentOperator'] === '');

// The other kinds: a transporter's notes edited, a list edited as lines, a field moved.
$tp = array('transporterCompany' => array('name' => 'Zzq Transport', 'notes' => 'Excuse any grammer errors.', 'vehicleTypes' => array('SUV', 'Van'), 'pickupMethods' => array(), 'licensing' => ''));
$c = kop_suggestion_changes($pdo, $tp, '', 'wpdl_');
$nr = null; foreach ($c['other'] as $o) if (substr($o['label'], -5) === 'Notes') $nr = $o;
$check('other: a text field carries its keys and where it can move', $nr && $nr['path'] === array('transporterCompany', 'notes') && in_array('licensing', array_column($nr['moves'], 'path'), true), json_encode($nr));
$tp2 = kop_suggestion_edit($tp, array('entry' => 'other', 'op' => 'set', 'path' => array('transporterCompany', 'notes'), 'value' => 'Excuse any grammar errors.'));
$tp2 = kop_suggestion_edit($tp2, array('entry' => 'other', 'op' => 'set', 'path' => array('transporterCompany', 'vehicleTypes'), 'value' => "SUV\nVan\nSedan"));
$tp2 = kop_suggestion_edit($tp2, array('entry' => 'other', 'op' => 'move', 'path' => array('transporterCompany', 'notes'), 'to' => 'licensing'));
$check('other: edit, lines and move', $tp2['transporterCompany']['licensing'] === 'Excuse any grammar errors.' && $tp2['transporterCompany']['notes'] === ''
    && $tp2['transporterCompany']['vehicleTypes'] === array('SUV', 'Van', 'Sedan'), json_encode($tp2));
$check('other: bookkeeping keys are refused', $threw(function () use ($tp) { kop_suggestion_edit($tp, array('entry' => 'other', 'op' => 'set', 'path' => array('facilities'), 'value' => 'x')); }) !== '');

// A transporter's people (a list of entries) item by item, and a tick box.
$tp = array('transporterCompany' => array('name' => 'Zzq Transport', 'notes' => ''), 'isIndependentTransporter' => true,
    'transporters' => array(array('firstName' => 'Bobby', 'lastName' => 'Tredinnik', 'role' => 'CEO'), array('firstName' => 'Sam', 'lastName' => 'Lee', 'role' => 'Driver')));
$c = kop_suggestion_changes($pdo, $tp, '', 'wpdl_');
$pr = null; $tick = null;
foreach ($c['other'] as $o) { if ($o['label'] === 'Transporters') $pr = $o; if (!empty($o['bool'])) $tick = $o; }
$check('other: a list of people has an item per person with its parts', $pr && count($pr['items']) === 2
    && array_column($pr['items'][0]['parts'], 'key') === array('firstName', 'lastName', 'role'), json_encode($pr));
$tp2 = kop_suggestion_edit($tp, array('entry' => 'other', 'op' => 'item', 'path' => array('transporters'), 'index' => 0, 'parts' => array('lastName' => 'Tredinnick')));
$check('other: a person\'s spelling fixed', $tp2['transporters'][0] === array('firstName' => 'Bobby', 'lastName' => 'Tredinnick', 'role' => 'CEO'), json_encode($tp2['transporters']));
$check('other: a person can not be moved onto a tick box',
    $threw(function () use ($tp2) { kop_suggestion_edit($tp2, array('entry' => 'other', 'op' => 'move', 'path' => array('transporters'), 'index' => 1, 'to' => 'isIndependentTransporter')); }) !== '');
$check('other: Move never offers the project\'s own keys', !array_intersect(array_column($pr['moves'], 'path'), kop_suggestion_other_skip()), json_encode($pr['moves']));
$check('other: a move onto a project key is refused',
    $threw(function () use ($tp2) { kop_suggestion_edit($tp2 + array('name' => ''), array('entry' => 'other', 'op' => 'move', 'path' => array('transporters'), 'index' => 1, 'to' => 'name')); }) !== '');
$tp3 = kop_suggestion_edit($tp2, array('entry' => 'other', 'op' => 'remove', 'path' => array('transporters'), 'index' => 1));
$check('other: one person removed, the other kept', count($tp3['transporters']) === 1 && $tp3['transporters'][0]['firstName'] === 'Bobby');
$check('other: a tick box shows with only Remove', $tick && $tick['path'] === array('isIndependentTransporter'), json_encode($tick));
$tp3 = kop_suggestion_edit($tp3, array('entry' => 'other', 'op' => 'remove', 'path' => array('isIndependentTransporter')));
$check('other: a tick box removed is unticked, not deleted', $tp3['isIndependentTransporter'] === false);
$check('other: a tick box can not be typed into', $threw(function () use ($tp) { kop_suggestion_edit($tp, array('entry' => 'other', 'op' => 'set', 'path' => array('isIndependentTransporter'), 'value' => 'x')); }) !== '');

// A whole-record edit (sent from the full data form with facility_id) that drops a note and empties the gender: "Keep it" puts them back.
// A record with notes and a gender (the first test record may have neither).
$krow = $pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_extract(json_data, '$.facilityDetails.gender') <> ''
    AND json_array_length(json_extract(json_data, '$.notes')) > 1 AND json_extract(json_data, '$.facilityDetails.type') <> '' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$rec = $krow ? kop_facility_normalize(json_decode($krow['json_data'], true), array('facility_id' => (int) $krow['id'])) : array('notes' => array(), 'facilityDetails' => array('gender' => ''));
if ($rec['notes'] && $rec['facilityDetails']['gender'] !== '') {
    $whole_entry = $rec;
    array_shift($whole_entry['notes']);
    $whole_entry['facilityDetails']['gender'] = '';
    $whole_entry['facilityDetails']['type'] = $rec['facilityDetails']['type'] . ' (edited)';
    $wp = array('facilities' => array($whole_entry));
    $c = $card($wp);
    $nr = $row_for($c, 'Notes');
    $cr = $row_for($c, 'Gender');
    $check('keep: a dropped note shows with its place on the record', $nr && ($nr['dropped_items'][0]['index'] ?? null) === 0, json_encode($nr));
    $check('keep: an emptied field can be kept or typed into', $cr && $cr['kind'] === 'removed' && $cr['keepable'] && $cr['editable'], json_encode($cr));
    $check('keep: refused without the record', $threw(function () use ($wp) { kop_suggestion_edit($wp, array('entry' => 0, 'op' => 'keep', 'path' => 'notes', 'index' => 0)); }) !== '');
    $recdoc = kop_suggestion_record_doc($pdo, $wp, 0, '', 'wpdl_');
    $wp2 = kop_suggestion_edit($wp, array('entry' => 0, 'op' => 'keep', 'path' => 'notes', 'index' => 0), $recdoc);
    $wp2 = kop_suggestion_edit($wp2, array('entry' => 0, 'op' => 'keep', 'path' => 'facilityDetails.gender'), $recdoc);
    $c2 = $card($wp2);
    $check('keep: the note and the gender are back, the real change stays', !$row_for($c2, 'Notes') && !$row_for($c2, 'Gender') && $row_for($c2, 'Program type'),
        json_encode($c2['entries'][0]['changes'] ?? null));
    $wp3 = kop_suggestion_edit($wp2, array('entry' => 0, 'op' => 'keep', 'path' => 'notes', 'index' => 0), $recdoc);
    $check('keep: twice adds nothing', count(kop_extract_project_data($wp3)['facilities'][0]['notes']) === count($rec['notes']));
} else {
    $check('keep: a test record has notes and a gender', false);
}

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
