<?php
/**
 * Offline checks for the news facts reader (inc/news-facts.php) against the
 * production mirror in tmp/prod.sqlite. No AI calls: the model's reply is
 * made up. The Woodbury Facts rows it makes are applied to real facility
 * documents in memory and must pass the facility validator.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-news-facts.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/news-facts.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
echo "-- Which articles are read, in which order --\n";
$list = kop_nf_articles_to_read($pdo, 30);
$dates = array_column($list, 'publication_date');
$sorted = $dates;
rsort($sorted);
$check('newest first', $dates === $sorted, ($dates[0] ?? '') . ' .. ' . (end($dates) ?: ''));
$check('only approved or published articles', !array_filter($list, function ($n) { return !in_array($n['status'], array('approved', 'published'), true); }));
$check('the hash moves with the reader version', kop_nf_news_hash(array('article_title' => 'a')) === md5(implode("\x1f", array('a', '', '', KOP_NEWS_FACTS_READER))));

// ---------------------------------------------------------------------------
echo "-- The prompt --\n";
$news = $list[0];
$linked = array(array('id' => 11, 'unique_name' => 'Alpha Academy', 'city' => 'Provo', 'state' => 'UT', 'status' => 'Open'));
$prompt = kop_nf_build_prompt($news, $linked, 'Some text.');
$check('names every kind', !array_filter(array_keys(kop_nf_categories()), function ($k) use ($prompt) { return strpos($prompt, "- {$k}: ") === false; }));
$check('gives the linked ids', strpos($prompt, 'id 11: Alpha Academy (Provo, UT)') !== false);
$check('forbids survivors\' names', stripos($prompt, 'NEVER give the name of a young person') !== false);
$check('says restraint is restraint', stripos($prompt, 'Restraint is restraint') !== false);
$check('leaves closures to the closure scan', stripos($prompt, 'Leave out closures') !== false);

// ---------------------------------------------------------------------------
echo "-- Reading the model's reply --\n";
$reply = json_encode(array('facts' => array(
    array('category' => 'staff', 'subject' => 'Alpha Academy', 'facilityId' => 11, 'person' => 'Jane Doe-Smith', 'role' => 'Executive Director',
        'summary' => 'Jane Doe-Smith is the executive director.', 'quote' => 'said Jane Doe-Smith, the academy\'s executive director'),
    array('category' => 'staff', 'subject' => 'Alpha Academy', 'facilityId' => 11, 'person' => 'Jane Doe-Smith', 'role' => 'director',
        'summary' => 'Repeat.', 'quote' => 'x'),
    array('category' => 'restraint', 'subject' => 'Alpha Academy', 'facilityId' => 11, 'date' => 'March 2024', 'standing' => 'ALLEGED',
        'summary' => 'Staff held Maria Lopez face down for 40 minutes.', 'quote' => 'staff held Maria Lopez face down for 40 minutes'),
    array('category' => 'death', 'subject' => 'Alpha Academy', 'facilityId' => 11, 'date' => '2024-03-02', 'standing' => 'reported',
        'summary' => 'Cornelius Smith, 16, died after a restraint.', 'quote' => 'Cornelius Smith, 16, died'),
    array('category' => 'opened', 'subject' => 'Alpha Academy', 'facilityId' => 11, 'value' => '1998', 'summary' => 'It opened in 1998.', 'quote' => 'opened in 1998'),
    array('category' => 'capacity', 'subject' => 'Alpha Academy', 'facilityId' => 99, 'value' => '48', 'summary' => 'It holds 48 boys.', 'quote' => 'holds 48 boys'),
    array('category' => 'acquired', 'subject' => 'Acadia Healthcare', 'subjectKind' => 'company', 'value' => 'bought by X for $10 million',
        'summary' => 'Acadia sold the program.', 'quote' => 'sold'),
    array('category' => 'gossip', 'subject' => 'Alpha Academy', 'summary' => 'Not a kind.'),
    array('category' => 'fine', 'subject' => '', 'summary' => 'No subject.'),
)));
$facts = kop_nf_parse_reply("Here:\n```json\n" . $reply . "\n```", $linked);
$check('reply parses through a code fence', is_array($facts));
$check('unknown kinds, empty subjects and repeats dropped', count($facts) === 6, count((array) $facts) . ' facts');
$by = array();
foreach ($facts as $f) $by[$f['category']] = $f;
$check('standing lowercased and kept', $by['restraint']['standing'] === 'alleged');
$check('"March 2024" reads as 2024', $by['restraint']['date'] === '2024');
$check('an id the article is not linked to is no id', $by['capacity']['facility_id'] === 0);
$check('an unreadable reply is null', kop_nf_parse_reply('Sorry, I cannot.', $linked) === null);
$check('an empty list is fine', kop_nf_parse_reply('{"facts": []}', $linked) === array());

// ---------------------------------------------------------------------------
echo "-- Survivors' names --\n";
$names = kop_nf_survivor_names(json_encode(array('Maria Lopez', 'J.D.', 'Jane Doe', 'Cornelius Smith')));
$check('initials and pseudonyms name nobody', $names === array('Maria Lopez', 'Cornelius Smith'), implode(', ', $names));
$f = $by['restraint'];
$removed = kop_nf_scrub_survivors($f, $names);
$check('a survivor\'s name comes out of the summary', $removed && stripos($f['summary'], 'Lopez') === false && strpos($f['summary'], 'a former resident') !== false, $f['summary']);
$check('and out of the quote', stripos($f['quote'], 'Maria') === false && strpos($f['quote'], '[name removed]') !== false, $f['quote']);
$f = $by['death'];
$check('a young person who died keeps their name', !kop_nf_scrub_survivors($f, $names) && strpos($f['summary'], 'Cornelius Smith') !== false);
$f = array('category' => 'abuse', 'summary' => 'Lopez said staff hit her.', 'value' => '', 'quote' => '', 'subject' => 'Alpha', 'person' => '');
kop_nf_scrub_survivors($f, $names);
$check('the surname alone comes out too', stripos($f['summary'], 'Lopez') === false, $f['summary']);
$f = array('category' => 'abuse', 'summary' => 'Director Jane Doe-Smith and Ann Smith denied it; Ms. Smith said...', 'value' => '', 'quote' => '', 'subject' => 'Alpha', 'person' => 'Ann Smith');
kop_nf_scrub_survivors($f, $names);
$check('another person sharing the surname keeps it', $f['summary'] === 'Director Jane Doe-Smith and Ann Smith denied it; [name removed] said...' && $f['person'] === 'Ann Smith', $f['summary']);

// ---------------------------------------------------------------------------
echo "-- Where each fact goes --\n";
$news = array('id' => 9001, 'article_title' => 'Academy under fire', 'publication_name' => 'Salt Lake Tribune',
    'publication_date' => '2025-04-01', 'article_url' => 'https://example.test/a');
$route = function ($cat) use ($by, $news, $names) {
    $f = $by[$cat];
    $f['names_removed'] = kop_nf_scrub_survivors($f, $names);
    $f['linked_name'] = 'Alpha Academy';
    return kop_nf_wbf_row($f, $news, true);
};
$staff = $route('staff');
$check('staff: add_staff on the administrators', $staff['op'] === 'add_staff' && $staff['path'] === 'staff.administrator', $staff['path']);
$check('staff: tagged as news, found, never the exact-name pass', strpos($staff['extra'], '"via":"news"') !== false && $staff['found'] === 1 && $staff['match_kind'] === 'id');
$check('staff: not automatic while the owner has it off', $staff['auto'] === 0);
$GLOBALS['kop_test_options']['kop_news_facts_auto'] = 1;
$check('staff: automatic once it is on', $route('staff')['auto'] === 1);
$check('restraint: never automatic', $route('restraint')['auto'] === 0);
$check('opened: automatic once it is on', $route('opened')['auto'] === 1);
unset($GLOBALS['kop_test_options']['kop_news_facts_auto']);
$inc = $route('restraint');
$line = json_decode($inc['value'], true);
$check('restraint: a critical incident line with date, kind, standing and the article', $inc['path'] === 'criticalIncidents.customIncidents'
    && $line === '2024: Restraint: Staff held a former resident face down for 40 minutes (alleged). (Salt Lake Tribune, "Academy under fire", 2025-04-01)', $line);
$check('restraint: says a name was taken out', strpos($inc['extra'], '"names_removed":true') !== false);
$open = $route('opened');
$check('opened: the start year when the record has none', $open['op'] === 'set_if_empty' && $open['path'] === 'operatingPeriod.startYear' && json_decode($open['value']) === 1998);
$check('a program the article is not linked to waits at Facts from news', kop_nf_wbf_row($by['capacity'] + array('linked_name' => ''), $news, true) === null);
$check('a company waits at Facts from news', kop_nf_wbf_row($by['acquired'] + array('linked_name' => ''), $news, true) === null);
$check('the same fact from another article is the same row', $staff['pkey'] === kop_nf_wbf_row($by['staff'] + array('linked_name' => 'Alpha Academy'),
    array('id' => 2, 'article_url' => 'https://example.test/b') + $news, false)['pkey']);
$check('Woodbury Facts reads its origin as the news', kop_wbf_origin($staff) === 'news' && kop_wbf_origins()['news'] === 'News articles');
$check('the newsletter filter leaves news rows out', strpos(kop_wbf_origin_where('woodbury'), 'NOT LIKE \'%"via":"news"%\'') !== false, kop_wbf_origin_where('woodbury'));
$check('the news filter keeps only news rows', kop_wbf_origin_where('news') === " AND extra LIKE '%\"via\":\"news\"%'");

// ---------------------------------------------------------------------------
echo "-- The rows apply to real records --\n";
$normalize = function (array $doc) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    return $out;
};
$fids = $pdo->query("SELECT l.facility_id FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id
                      GROUP BY l.facility_id ORDER BY MAX(l.news_id) DESC LIMIT 25")->fetchAll(PDO::FETCH_COLUMN);
$stmt = $pdo->prepare('SELECT unique_name, json_data FROM facilities_v2 WHERE id = ?');
$applied = $refused = $invalid = 0;
$why = array();
foreach ($fids as $fid) {
    $stmt->execute(array($fid));
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    $doc = $normalize(json_decode((string) $rec['json_data'], true));
    foreach (array('staff', 'restraint', 'death', 'opened') as $cat) {
        $f = $by[$cat];
        $f['facility_id'] = (int) $fid;
        $f['linked_name'] = $rec['unique_name'];
        $f['names_removed'] = false;
        $row = kop_nf_wbf_row($f, $news, true);
        try {
            kop_wbf_doc_apply($doc, $row);
            $applied++;
        } catch (RuntimeException $e) {
            $refused++;
            $why[$e->getMessage()] = true;
        }
    }
    $errors = array_filter(kop_facility_validate($normalize($doc)), function ($v) { return $v['severity'] === 'error'; });
    if ($errors) {
        $invalid++;
        echo '  invalid #' . $fid . ': ' . json_encode(array_values($errors)) . "\n";
    }
}
printf("  %d applied, %d refused (%s) on %d records\n", $applied, $refused, implode('; ', array_keys($why)), count($fids));
$check('every record still validates', $invalid === 0);
$check('most rows apply', $applied > $refused);

echo $failures ? "\n{$failures} FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
