<?php
/**
 * scripts/test-review-inbox.php checks for the 'podcasts' source
 * (inc/review-inbox/podcasts.php over inc/podcast-statements.php), on the
 * pipeline's real export (kop-pipeline tmp/podcasts/<show>/proposals.json, from
 * scripts/podcast-facts.py check) and the mirror's people and records: the
 * import adds every item once and a second import adds nothing, a statement's
 * card has its words, the audio at its second, who said it and how sure, and
 * suggests the speaker's person id and the records named exactly; Approve
 * stores the corrected words, the person and the records (a program and a
 * company), the approved item is listed for that person and on both pages
 * (administrators only), Undo puts it back exactly, a new name gets a person
 * id that Undo removes again, Reject and its Undo change nothing else.
 */

if (!defined('KOP_PODCAST_IMPORT_DIR')) {
    define('KOP_PODCAST_IMPORT_DIR', getenv('KOP_PODCAST_DIR') ?: dirname(__DIR__, 3) . '/kop-pipeline/tmp/podcasts');
}
require_once dirname(__DIR__, 2) . '/inc/people.php';
require_once dirname(__DIR__, 2) . '/inc/people-admin.php';
require_once dirname(__DIR__, 2) . '/inc/podcast-statements.php';
$GLOBALS['kop_test_options']['kop_people_db'] = KOP_PEOPLE_DB_VERSION;

if (kop_rinbox_test_wants('podcasts')) {
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_podcast_items (id INTEGER PRIMARY KEY AUTOINCREMENT, ikey TEXT NOT NULL UNIQUE,
        show_key TEXT NOT NULL DEFAULT '', episode TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT 'statement', seconds INTEGER NOT NULL DEFAULT 0,
        quote TEXT NOT NULL, quote_orig TEXT NOT NULL, speaker TEXT NOT NULL DEFAULT '', person_id INTEGER NOT NULL DEFAULT 0,
        records TEXT NOT NULL DEFAULT '', data TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', decided_by TEXT NOT NULL DEFAULT '',
        decided_at TEXT NULL, undo TEXT NULL, created_at TEXT NOT NULL)");
    kop_rinbox_test_copy_tables(array('memorial_victims', 'referrers_master'));
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
        aliases TEXT, merged_into INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)");
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_person_roles (record_kind TEXT NOT NULL, record_id INTEGER NOT NULL, list TEXT NOT NULL,
        position INTEGER NOT NULL, person_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', role TEXT, ref TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (record_kind, record_id, list, position))");
    if (!$GLOBALS['pdo']->query('SELECT COUNT(*) FROM wpdl_kop_people')->fetchColumn()) {
        kop_people_sync(array('pdo' => $GLOBALS['pdo'], 'prefix' => 'wpdl_',
            'graph' => json_decode(file_get_contents(dirname(__DIR__, 2) . '/js/data/network/graph.json'), true)));
    }
}

function kop_rinbox_test_podcasts(array $src, array $item, callable $check) {
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    $table = kop_pod_table();
    $files = kop_pod_import_files();
    if (!$files) {
        $check('podcasts: the pipeline export to test with (' . kop_pod_import_dir() . ')', false);
        return;
    }
    $n = 0;
    foreach ($files as $f) $n += count((array) (json_decode((string) file_get_contents($f), true)['items'] ?? array()));
    $count = function ($where = '') use ($wpdb, $table) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}" . ($where ? " WHERE {$where}" : '')); };
    $check('podcasts: the import holds every exported item once', $count() === $n, $count() . ' rows, ' . $n . ' in the files');
    $check('podcasts: a second import adds nothing', kop_pod_import(true) === 0);

    // A statement by a sure speaker, with an audio link at its second.
    $sid = (int) $wpdb->get_var("SELECT id FROM {$table} WHERE kind = 'statement' AND status = 'pending' AND speaker = 'Will White' ORDER BY id LIMIT 1");
    $card = $src['get']((string) $sid);
    $check('podcasts: a statement card has its words, the audio at its second, who said it and how sure',
        $card && $card['text'] !== '' && strpos($card['links'][0]['url'] ?? '', '#t=') !== false
        && in_array('Who said it', array_column($card['details'], 'label'), true) && in_array('How sure', array_column($card['details'], 'label'), true),
        json_encode($card['links'] ?? null));
    $check('podcasts: a waiting card offers Approve and link and Reject, each with its help',
        array_column($card['actions'], 'id') === array('approve', 'reject') && !array_filter($card['actions'], function ($a) { return empty($a['help']); }));
    $params = array();
    foreach ($card['actions'][0]['params'] as $p) $params[$p['name']] = $p;
    $check('podcasts: Approve asks for the words, the person (with a lookup) and programs and companies',
        isset($params['quote'], $params['person'], $params['records']) && $params['person']['lookup'] === 'person'
        && $params['records']['type'] === 'facilities' && $params['records']['kinds'] === array('facility', 'company'));
    $look = kop_rinbox_pod_lookup('person', 'Will Whi');
    $check('podcasts: the person lookup lists "Name #id" values', !$look || preg_match('/ #\d+$/', $look[0]['value']), json_encode(array_slice($look, 0, 2)));

    // Approve with corrected words, a person on file, a program and a company.
    $person = (int) $wpdb->get_var("SELECT id FROM wpdl_kop_people WHERE merged_into IS NULL ORDER BY id LIMIT 1");
    $fid = (int) $pdo->query('SELECT id FROM facilities_v2 ORDER BY id LIMIT 1')->fetchColumn();
    $oid = (int) $wpdb->get_var('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1');
    $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $sid), ARRAY_A);
    $msg = kop_rinbox_pod_act((string) $sid, 'approve', array('quote' => 'Corrected words.', 'person' => 'Anyone #' . $person,
        'records' => array((string) $fid, 'company:' . $oid, 'junk')))['message'];
    $after = kop_pod_row($sid);
    $check('podcasts: Approve stores the words, the person and the records (and nothing that is not a record)',
        $after['status'] === 'approved' && $after['quote'] === 'Corrected words.' && $after['person_id'] === $person
        && $after['records'] === $fid . ',company:' . $oid, $msg . ' | ' . $after['records']);
    $check('podcasts: the approved item is listed for that person and on both records',
        in_array($sid, array_column(kop_pod_for_person($person), 'id'), true) && in_array($sid, array_column(kop_pod_for_record((string) $fid), 'id'), true)
        && in_array($sid, array_column(kop_pod_for_record('company:' . $oid), 'id'), true));
    $check('podcasts: an id that only starts the same is not linked', !in_array($sid, array_column(kop_pod_for_record((string) $fid . '0'), 'id'), true)
        && !in_array($sid, array_column(kop_pod_for_record('1' . $fid), 'id'), true));
    $check('podcasts: the page section is for administrators only (nobody is signed in here)', kop_pod_section_html((string) $fid) === ''
        && strpos(kop_pod_section_body(kop_pod_for_record((string) $fid)), 'Corrected words.') !== false);
    $done = $src['get']((string) $sid);
    $check('podcasts: a decided card offers only Undo', array_column($done['actions'], 'id') === array('undo'));
    kop_rinbox_pod_act((string) $sid, 'undo', array());
    $back = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $sid), ARRAY_A);
    $same = true;
    foreach (array('quote', 'speaker', 'person_id', 'records', 'status') as $k) $same = $same && (string) $back[$k] === (string) $before[$k];
    $check('podcasts: Undo puts the item back exactly', $same, json_encode(array_intersect_key($back, array_flip(array('quote', 'speaker', 'person_id', 'records', 'status')))));

    // A new name gets a person id; Undo removes it again.
    $people = (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_people');
    $msg = kop_rinbox_pod_act((string) $sid, 'approve', array('person' => 'Zebulon Testperson Quill', 'records' => array()))['message'];
    $made = kop_pod_row($sid)['person_id'];
    $check('podcasts: a new full name gets a new person id', $made > 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_people') === $people + 1, $msg);
    kop_rinbox_pod_act((string) $sid, 'undo', array());
    $check('podcasts: Undo removes the person id it made', (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_people') === $people);
    $refused = '';
    try { kop_rinbox_pod_act((string) $sid, 'approve', array('person' => 'Cher')); } catch (Throwable $e) { $refused = $e->getMessage(); }
    $check('podcasts: a one-word name is refused, not guessed', $refused !== '' && kop_pod_row($sid)['status'] === 'pending', $refused);

    // Reject and its Undo.
    kop_rinbox_pod_act((string) $sid, 'reject', array());
    $check('podcasts: Reject moves it to Rejected', kop_pod_row($sid)['status'] === 'rejected' && kop_rinbox_pod_view_counts(array())['rejected'] >= 1);
    kop_rinbox_pod_act((string) $sid, 'undo', array());
    $check('podcasts: Undo of a reject puts it back to waiting', kop_pod_row($sid)['status'] === 'pending');

    // Filters and search.
    $only = kop_rinbox_pod_list(array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 500, 'filters' => array('kind' => 'program')));
    $check('podcasts: the kind filter lists only that kind', $only['total'] > 0 && !array_filter($only['items'], function ($i) {
        return $i['details'][0]['value'] !== 'Program fact';
    }), (string) $only['total']);
    $ser = kop_rinbox_pod_list(array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 500, 'filters' => array('severity' => 'serious')));
    $check('podcasts: the severity filter narrows the list', $ser['total'] > 0 && $ser['total'] < $count("status = 'pending'"), (string) $ser['total']);
}
