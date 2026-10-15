<?php
/**
 * The KOP Tools review screens on the admin kit (inc/admin-kit.php), drawn
 * offline from made-up rows: Drive Docs, Fornits and Woodbury Facts cards with
 * the kit's header, tabs, filters, status strip, bar and pager, into
 * tmp/admin-kit-preview/index.html (open it, or screenshot it at phone width).
 * Nothing is read from or written to a database beyond the mirror's record
 * names; the buttons post nowhere.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/preview-admin-kit.php [--db=tmp/prod.sqlite] [--out=tmp/admin-kit-preview]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out = $args['out'] ?? (dirname(__DIR__) . '/tmp/admin-kit-preview');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(1);
}
require __DIR__ . '/kop-test-harness.php';
foreach (array(
    'sanitize_key' => function ($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); },
    'selected' => function ($a, $b = true, $echo = true) { $s = (string) $a === (string) $b ? ' selected="selected"' : ''; if ($echo) echo $s; return $s; },
    'esc_textarea' => function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); },
    // Record names in card titles come from the mirror.
    'kop_closure_pdo' => function () use ($db_path) { static $pdo; return $pdo ?: ($pdo = new PDO('sqlite:' . $db_path)); },
) as $name => $fn) {
    if (!function_exists($name)) {
        $GLOBALS['kop_preview_stub'][$name] = $fn;
        eval('function ' . $name . '(...$a) { return $GLOBALS["kop_preview_stub"]["' . $name . '"](...$a); }');
    }
}
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/drive-docs.php';
require_once dirname(__DIR__) . '/inc/fornits.php';

$base = 'admin.php?page=kop-preview';
$url = function ($k) use ($base) { return $base . '&tab=' . rawurlencode((string) $k); };
$capture = function (callable $fn) {
    ob_start();
    $fn();
    return ob_get_clean();
};

/* ---- Drive Docs ---------------------------------------------------------- */

$gdl_row = function (array $o) {
    return $o + array(
        'pkey' => md5(json_encode($o)), 'url' => 'https://example.org/a', 'label' => '', 'kind' => 'news', 'domain' => 'example.org',
        'status' => 'pending', 'facility_id' => 10371, 'facility_how' => 'exact name', 'applied' => '', 'applied_fid' => 0,
        'source' => 'gdocs', 'also_named' => '', 'operator_name' => '', 'source_doc' => 'TTI research notes',
        'seen' => json_encode(array(array('doc' => 'TTI research notes', 'tab' => 'Utah', 'heading' => 'Lawsuits',
            'text' => 'Former students describe being restrained face-down for hours; the state cited the program twice in 2019 (https://example.org/a).'))),
    );
};
$gdl_rows = array(
    $gdl_row(array('label' => 'Teens describe restraint at Utah program', 'url' => 'https://www.sltrib.com/news/2019/teens-restraint/', 'domain' => 'sltrib.com')),
    $gdl_row(array('label' => 'Complaint, Doe v. Program LLC (D. Utah 2020)', 'kind' => 'court', 'url' => 'https://www.courtlistener.com/docket/1/doe/', 'domain' => 'courtlistener.com')),
    $gdl_row(array('label' => 'Licensing inspection, March 2019', 'kind' => 'licensing', 'url' => 'https://dhs.utah.gov/report.pdf', 'domain' => 'dhs.utah.gov',
        'facility_how' => 'close name (one word apart)')),
    $gdl_row(array('label' => 'A very long survivor blog post title that goes on and on to see how the column wraps on a narrow phone screen',
        'kind' => 'other', 'url' => 'https://someone.blogspot.com/2014/05/my-time-at-the-program-and-what-happened-after.html', 'domain' => 'someone.blogspot.com',
        'source' => 'sciad', 'also_named' => json_encode(array(array('name' => 'Another Academy', 'state' => 'UT'))))),
);
$gdl = '<div class="wrap kop-ak kop-gdl">'
    . kop_ak_header('Drive Docs', array(
        'lede' => 'Links the database does not have yet, one card per facility: from your Google Docs and Sheets, HEAL\'s old site, the r/troubledteens wiki and SCIAD NET.',
        'steps' => array('<strong>Read down a card.</strong> Sure matches start ticked.', '<strong>Click <em>Add checked</em>.</strong>'),
    ))
    . kop_ak_tabs(array('facility' => array('label' => 'For a facility', 'count' => 1204), 'company' => array('label' => 'Company only', 'count' => 87),
        'none' => array('label' => 'No facility', 'count' => 412), 'applied' => array('label' => 'Added', 'count' => 3310), 'rejected' => array('label' => 'Skipped', 'count' => 95)),
        'facility', $url)
    . kop_ak_filters(array('page' => 'kop-drive-docs', 'gdl_tab' => 'facility'), array(
        array('type' => 'search', 'name' => 'gdl_q', 'value' => '', 'placeholder' => 'Words, address or doc', 'label' => 'Search'),
        array('type' => 'select', 'name' => 'gdl_src', 'value' => 'sciad', 'label' => 'Source', 'all' => 'Every source',
            'options' => array('gdocs' => 'Google Docs (3)', 'sciad' => 'SCIAD NET (587)', 'heal' => 'HEAL (40)')),
        array('type' => 'select', 'name' => 'gdl_kind', 'value' => '', 'label' => 'Kind', 'all' => 'Every kind', 'options' => kop_gdl_kinds()),
    ), $base)
    . kop_ak_bar(kop_ak_button('Add everything ticked on this page', 'primary', 'data-kop-ak-all="apply"'))
    . $capture(function () use ($gdl_rows) {
        kop_gdl_render_card($gdl_rows, 'facility', array('total' => 57, 'shown' => 4, 'offset' => 0, 'all' => 41, 'more' => '#',
            'filters' => array('kind' => '', 'src' => '', 'q' => '', 'card' => '')));
        $none = array_map(function ($r) { return array_merge($r, array('facility_id' => 0, 'facility_how' => '')); }, array_slice($gdl_rows, 0, 2));
        kop_gdl_render_card($none, 'none', array('filters' => array()));
    })
    . kop_ak_pager(3, 40, 600, 'cards', function ($p) use ($base) { return $base . '&p=' . $p; })
    . '</div>';

/* ---- Fornits ------------------------------------------------------------- */

$fn_row = function (array $o) {
    return $o + array(
        'pkey' => md5(json_encode($o)), 'value' => '{}', 'also' => '', 'preselect' => 1, 'topic_id' => 4411, 'post_n' => 0, 'kind' => 'link',
        'label' => '', 'topic_url' => 'https://www.fornits.com/phpbb/index.php?topic=4411.0', 'categories' => 'abuse,restraint', 'importance' => 3,
        'board_name' => 'Troubled Teen Industry', 'how' => 'exact name', 'topic_headline' => '', 'topic_title' => 'Anyone else go here?', 'summary' => '',
        'topic_summary' => '', 'author' => 'formerstudent', 'post_date' => '2005-03-12T10:00:00', 'quote' => '', 'quote_found' => 1, 'context' => '',
        'applied' => '', 'facility_id' => 10371,
    );
};
$fn_rows = array(
    $fn_row(array('label' => 'Fornits: Former students on isolation rooms and restraint (2005)', 'topic_headline' => 'Former students on isolation rooms and restraint',
        'summary' => 'Several former students describe days in an isolation room and being restrained by staff for talking.',
        'topic_summary' => 'A long thread in which former students compare what happened to them across three programs.',
        'quote' => 'They kept me in the OP room for eleven days.')),
    $fn_row(array('kind' => 'staff', 'label' => 'John Smith, program director (2003-2006)', 'importance' => 2, 'post_n' => 4,
        'quote' => 'John Smith was the director when I was there and he ran the seminars.', 'quote_found' => 0,
        'context' => "I was there 2004 to 2005.\nJohn Smith was the director when I was there and he ran the seminars.\nNobody believed us.")),
    $fn_row(array('kind' => 'lead', 'label' => 'Closed after a state investigation in 2008', 'importance' => 2, 'post_n' => 9, 'categories' => 'closure',
        'value' => json_encode(array('summary' => 'Closed after a state investigation', 'year' => '2008', 'url' => 'https://www.deseret.com/2008/closure', 'type' => 'news')),
        'quote' => 'It shut down in 2008 after the state came in.', 'also' => json_encode(array(array('name' => 'Same Name Ranch', 'state' => 'MT', 'id' => 12001))))),
);
$calls = array('gemini' => 31, 'groq' => 12);
$fn = '<div class="wrap kop-ak kop-fn">'
    . kop_ak_header('Fornits', array('lede' => 'What the old Fornits survivor forum (2001 on) says about each facility, one card per facility.',
        'steps' => array('<strong>Pick a tab</strong> and read down a card.')))
    . kop_ak_status('Topics loaded: <strong>18,204</strong> &middot; read by the AI: <strong>6,551</strong> &middot; calls today: Gemini 31 of 200, Groq 12 of 400'
        . '<div class="kop-ak-result is-ok" id="kop-fn-read-out" aria-live="polite">Read 6 topics, 14 new proposals. Reload to see them.</div>',
        kop_ak_button('Read a few more now', 'secondary', '', 'kop-fn-read'))
    . kop_ak_tabs(array('link' => array('label' => 'Discussion links', 'count' => 2210), 'staff' => array('label' => 'Staff', 'count' => 340),
        'incident' => array('label' => 'Incidents', 'count' => 120), 'account' => array('label' => 'Survivor accounts', 'count' => 88),
        'lead' => array('label' => 'Leads', 'count' => 61)), 'link', $url, array('label' => 'Kinds'))
    . kop_ak_tabs(array('pending' => array('label' => 'Waiting', 'count' => 2210), 'applied' => array('label' => 'Added', 'count' => 912),
        'rejected' => array('label' => 'Skipped or already there', 'count' => 77)), 'pending', $url, array('kind' => 'segments', 'label' => 'Status'))
    . kop_ak_filters(array('page' => 'kop-fornits'), array(
        array('type' => 'search', 'name' => 'fn_q', 'value' => 'restraint', 'placeholder' => 'Words, person or poster', 'label' => 'Search'),
        array('type' => 'select', 'name' => 'fn_cat', 'value' => '', 'label' => 'Category', 'all' => 'Every category', 'options' => kop_fornits_categories()),
        array('type' => 'select', 'name' => 'fn_min', 'value' => '', 'label' => 'Importance', 'all' => 'Any importance',
            'options' => array(3 => 'Key evidence only', 2 => 'Useful or better', 1 => 'Hide chatter', 0 => 'Read by the AI')),
    ), $base)
    . $capture(function () use ($fn_rows) { kop_fornits_render_card($fn_rows, 10371, 'pending'); kop_fornits_render_card(array($fn_rows[0]), 10371, 'applied'); })
    . '</div>';

/* ---- Woodbury Facts ------------------------------------------------------ */

$ev = function ($quote, $found = 1) {
    return array('label' => 'May 2007', 'number' => '#153', 'page' => 20, 'url' => 'https://kidsoverprofits.org/wp-content/uploads/woodbury-2007-05.pdf#page=20',
        'quote' => $quote, 'found' => $found);
};
$wbf_row = function (array $o) {
    return $o + array(
        'pkey' => md5(json_encode($o)), 'facility_id' => 10371, 'applied_fid' => 0, 'program' => 'Example Ranch', 'program_as_written' => 'Example Ranch School',
        'place' => 'Kanab, Utah', 'match_kind' => 'close', 'match_note' => 'One word more than the record\'s name.', 'grp' => 'staff', 'op' => 'add_staff',
        'path' => 'staff.notableStaff', 'value' => json_encode(array('name' => 'Jane Doe', 'role' => 'Clinical director')), 'label' => 'Jane Doe, Clinical director',
        'conflict' => '', 'current_val' => '', 'extra' => '{}', 'evidence' => '[]', 'found' => 1, 'preselect' => 1, 'issue_date' => '2007-05-01',
        'status' => 'pending', 'ya_why' => '', 'applied' => '',
        'alternatives' => json_encode(array(array('id' => 10371, 'name' => 'Example Ranch', 'state' => 'UT'), array('id' => 12002, 'name' => 'Example Ranch Academy', 'state' => 'UT'))),
    );
};
$wbf_rows = array(
    $wbf_row(array('evidence' => json_encode(array($ev('Jane Doe, formerly clinical director at Example Ranch, has joined...'))),
        'extra' => json_encode(array('person' => 'Jane Doe', 'career' => array('Example Ranch, clinical director (2005)', 'Second Program, therapist (2009)'))))),
    $wbf_row(array('grp' => 'history', 'op' => 'set_if_empty', 'path' => 'operatingPeriod.startYear', 'value' => '1998', 'label' => 'Opened in 1998',
        'current_val' => '2001', 'conflict' => 'The record says 2001.', 'evidence' => json_encode(array($ev('Founded in 1998 by...', 0))))),
);
$wbf = '<div class="wrap kop-ak kop-wbf">'
    . kop_ak_header('Woodbury Facts', array('lede' => 'Everything the Woodbury Reports newsletter (2006-2014) says about a program that its record does not have yet.',
        'steps' => array('<strong>Read down a card.</strong>')))
    . kop_ak_status('<strong>Exact-name items are added for you</strong>: 1,980 so far. Close names, conflicts and programs with no record wait for you.')
    . kop_ak_tabs(array_map(function ($t) { return array('label' => $t['label'], 'count' => 120); }, kop_wbf_tabs()), 'records', $url)
    . kop_ak_filters(array('page' => 'kop-woodbury-facts'), array(
        array('type' => 'search', 'name' => 'wbf_q', 'value' => '', 'placeholder' => 'Program or person', 'label' => 'Search'),
        array('type' => 'select', 'name' => 'wbf_grp', 'value' => '', 'label' => 'Kind', 'all' => 'Every kind', 'options' => kop_wbf_groups()),
    ), $base)
    . $capture(function () use ($wbf_rows, $wbf_row, $ev) {
        kop_wbf_render_card($wbf_rows, 'records');
        $nr = array($wbf_row(array('facility_id' => 0, 'program' => 'Unlisted Wilderness', 'match_kind' => '', 'match_note' => '', 'alternatives' => '[]',
            'evidence' => json_encode(array($ev('Unlisted Wilderness, a new program in Kanab...'))))));
        kop_wbf_render_card($nr, 'norecord');
        $ya = array($wbf_row(array('facility_id' => 0, 'program' => 'Next Step Living', 'ya_why' => 'Ages: 18-25', 'alternatives' => '[]',
            'evidence' => json_encode(array($ev('Next Step Living, for young adults 18 to 25...'))))));
        kop_wbf_render_card($ya, 'youngadult');
    })
    . '</div>';


$page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Admin kit preview</title>'
    // Inline: tmp/ is a junction into kop-pipeline, so a relative path to css/ would not reach the theme.
    . '<style>' . file_get_contents(dirname(__DIR__) . '/css/colors.css') . "
" . file_get_contents(dirname(__DIR__) . '/css/admin-kit.css') . '</style>'
    . '<style>body{margin:0;padding:10px 20px 40px;background:#f0f0f1;color:#3c434a;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
    . '.wrap{margin:10px 0 40px}h1{font-size:23px;font-weight:400;margin:0 0 6px}.widefat{width:100%;border-collapse:collapse;background:#fff;border:1px solid #c3c4c7}'
    . '.widefat td,.widefat th{padding:8px 10px;text-align:left}.button{display:inline-block;font-size:13px;line-height:2.15;min-height:30px;padding:0 10px;cursor:pointer;border-width:1px;border-style:solid;border-radius:3px;white-space:nowrap;box-sizing:border-box}'
    . 'input[type=text],input[type=search],select{min-height:30px;border:1px solid #8c8f94;border-radius:4px;padding:0 8px;font-size:14px}.notice{background:#fff;border-left:4px solid #72aee6;padding:1px 12px;margin:5px 0 15px}'
    . '@media (max-width:782px){body{padding:10px}}</style>'
    . '<script>window.KOP_ADMIN_KIT={ajax:"#"};</script></head><body>'
    . $gdl . $fn . $wbf
    // Each screen's own styles (its script is there too; in this one page the three would all answer a click).
    . $capture(function () { kop_gdl_render_assets(); kop_fornits_render_assets(); kop_wbf_render_assets(); })
    . '<script>' . file_get_contents(dirname(__DIR__) . '/js/admin-kit.js') . '</script></body></html>';

if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
file_put_contents("$out/index.html", $page);
echo "Wrote $out/index.html\n";
