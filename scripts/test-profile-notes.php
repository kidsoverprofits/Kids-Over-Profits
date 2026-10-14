<?php
/**
 * Research notes as prose (inc/profile-notes.php): the sentence rules on real lines, then every
 * facility and company record of the mirror (tmp/prod.sqlite): every note lands somewhere (a sentence,
 * "Also drawn on", the forum list, the rail, or dropped as housekeeping), no web address is printed as
 * words, every sentence ends, every sourced note keeps its source.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-profile-notes.php [--db=...] [--id=10347] [--list]
 *
 * --list prints the prose of every record that has some (to read through); --id one record's.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$args = getopt('', array('db::', 'id::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) { fwrite(STDERR, "No mirror at $db_path\n"); exit(2); }
require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/citations.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? "  ($detail)" : '') . "\n";
};
$one = function ($line) {
    $b = kop_pnotes_build(array($line));
    return $b['paragraphs'] ? $b['paragraphs'][0][0] : null;
};

echo "-- Sentence rules --\n";
$cases = array(
    'Cost: about $7,100 per month (r/troubledteens wiki, page "Aspen Ranch" (as of 2025-12-18))' => 'It cost about $7,100 per month.',
    'License: Licensed by the Idaho Department of Health and Welfare (Woodbury Reports, March 2007, p. 3)' => 'It was licensed by the Idaho Department of Health and Welfare.',
    'License: Licensed Residential Child Care Facility in Tennessee (Woodbury Reports, March 2007, p. 3)' => 'It was a licensed Residential Child Care Facility in Tennessee.',
    'License: State licensed (Woodbury Reports, March 2007, p. 3)' => 'It was licensed by the state.',
    'Accreditation: Northwest Association of Accredited Schools (HEAL, Aspen Ranch Exposed! (archived 2015-08-25))' => 'It was accredited by Northwest Association of Accredited Schools.',
    'Accreditation: accredited by CARF for three years (Woodbury Reports, September 2010, p. 10)' => 'It was accredited by CARF for three years.',
    'Accreditation: approved Idaho Special Education Facility (Woodbury Reports, May 2008, p. 2)' => 'It was an approved Idaho Special Education Facility.',
    'Operator: a division of CRC Health Group and member of Aspen Education Group (Woodbury Reports, December 2008, p. 19)' => 'It was a division of CRC Health Group and member of Aspen Education Group.',
    'Operator: parent company: Universal Health Services (Woodbury Reports, December 2008, p. 19)' => 'Its parent company was Universal Health Services.',
    'Operator: CEDU (HEAL, staff list for X (archived 2025-08-29))' => 'It was run by CEDU.',
    'Membership: NATSAP 1999-2011 (r/troubledteens wiki, page "Aspen Ranch" (as of 2025-12-18))' => 'It was a member of NATSAP from 1999 to 2011.',
    'Membership: NATSAP member since 2005 (Woodbury Reports, May 2008, p. 2)' => 'It joined NATSAP in 2005.',
    'Length of stay: minimum of nine months (HEAL, Aspen Ranch Exposed! (archived 2015-08-25))' => 'The minimum stay was nine months.',
    'Length of stay: 9 to 12 months (Woodbury Reports, May 2008, p. 2)' => 'Stays lasted 9 to 12 months.',
    'Cost: $7,982.50 pre-paid fee for transitional services, forfeited if the child leaves early (HEAL, staff list for Elevations (archived 2025-07-09))' => 'It charged a $7,982.50 pre-paid fee for transitional services, forfeited if the child leaves early.',
    'Founded by: Lester Roloff (Woodbury Reports, May 2008, p. 2)' => 'It was founded by Lester Roloff.',
    'Name change: renamed Viewpoint Center (Woodbury Reports, May 2008, p. 2)' => 'It was renamed Viewpoint Center.',
    'Name change: previously the Academy at Canyon Creek (Woodbury Reports, May 2008, p. 2)' => 'It was previously the Academy at Canyon Creek.',
    'Acquired: sold to Family Help & Wellness (Woodbury Reports, May 2008, p. 2)' => 'It was sold to Family Help & Wellness.',
    'Merger: merged with Outback Therapeutic Expeditions (Woodbury Reports, May 2008, p. 2)' => 'It merged with Outback Therapeutic Expeditions.',
    'Move: moved academic program to the Emerson building (Woodbury Reports, May 2008, p. 2)' => 'It moved academic program to the Emerson building.',
    'Note: employs 86 faculty and staff members (Woodbury Reports, May 2008, p. 2)' => 'It employs 86 faculty and staff members.',
    'Note: uses a rolling admissions process (Woodbury Reports, May 2008, p. 2)' => 'It uses a rolling admissions process.',
    'Note: five students graduated December 21, 2007 (Woodbury Reports, January 2008, p. 2)' => 'Five students graduated December 21, 2007.',
    'Campus: Nevada campus near Ely (Hidden Canyon Ranch) (Woodbury Reports, May 2008, p. 2)' => 'It had a Nevada campus near Ely (Hidden Canyon Ranch).',
    'Campus: two campuses: girls Mountain View and boys Lakeview (Woodbury Reports, May 2008, p. 2)' => 'It had two campuses: girls Mountain View and boys Lakeview.',
    'Described as: Private school with therapy (Woodbury Reports, May 2008, p. 2)' => 'It was described as a private school with therapy.',
    'Described as: outdoor adolescent therapy program (Woodbury Reports, May 2008, p. 2)' => 'It was described as an outdoor adolescent therapy program.',
    'Opened: 2006 per Woodbury Reports, February 2006, p. 22 (the record says 2005)' => 'One account dates its opening to 2006; other records give 2005.',
    'Closed in 2008 (sets status Closed, end year 2008) (Woodbury Reports, November 2008 (#171), p. 1: https://kidsoverprofits.org/wp-content/uploads/2024/12/woodbury-1108.pdf#page=1)' => 'It closed in 2008.',
    'Owned by Alabama Department of Youth Services' => 'It was owned by Alabama Department of Youth Services.',
    'April 2003: bought six inpatient behavioral health facilities from The Brown Schools, Inc. (Form 10-K for 2003: https://www.sec.gov/x.htm)' => 'In April 2003 it bought six inpatient behavioral health facilities from The Brown Schools, Inc.',
    'November 2010: acquired by Universal Health Services (Universal Health Services, Form 10-K for 2010, filed February 2011: https://www.sec.gov/y.htm)' => 'In November 2010 it was acquired by Universal Health Services.',
    'July 1, 2005: bought Ardent Behavioral Health (Form 10-K for 2005: https://www.sec.gov/z.htm)' => 'On July 1, 2005 it bought Ardent Behavioral Health.',
    'Incorporated in Delaware in 1988; headquartered at 113 Seaboard Lane, Franklin, Tennessee (Psychiatric Solutions, Inc., Form 10-K for 2003, filed March 2004: https://www.sec.gov/a.htm)' => 'It was incorporated in Delaware in 1988; headquartered at 113 Seaboard Lane, Franklin, Tennessee.',
    'In Emmett since 2015 and closed March 18, 2022 (Idaho Press): https://www.idahopress.com/x' => 'In Emmett since 2015 and closed March 18, 2022.',
    'Note: incorporated a gender-specific curriculum called Voices (Woodbury Reports, May 2008, p. 2)' => 'It incorporated a gender-specific curriculum called Voices.',
    'Note: Accepts adjudicated and court-ordered youth (HEAL, Aspen Ranch Exposed! (archived 2015-08-25))' => 'It accepts adjudicated and court-ordered youth.',
    'Merger: program integrated into Outback Therapeutic Expeditions (r/troubledteens wiki, page "Aspen Ranch" (as of 2025-12-18))' => 'The program was integrated into Outback Therapeutic Expeditions.',
    'Operator: WWASP-affiliated (Woodbury Reports, May 2008, p. 2)' => 'It was affiliated with WWASP.',
    'Cheryl Sudweeks, Owner/operator, Owner (2005-2026, HEAL) (HEAL, HEAL TEEN LIBERTY NEWS (archived 2025-07-09): https://web.archive.org/web/20250709153320/https://heal-online.org/x.html)' => 'Cheryl Sudweeks was its owner/operator from 2005 to 2026.',
    'Closed (2025-02-14) per news report: KUTV, "Utah treatment center closes abruptly", 2025-02-14 https://kutv.com/x' => 'A news report says it closed in February 2025.',
    'Neil Wallace is Executive Director (Spark Academy website).' => 'Neil Wallace is Executive Director.',
    'Brent R. Hall, Board of Directors, Ran the facility (per survivors) (2015-2025, HEAL) (HEAL, staff list for X (archived 2025-07-12): https://web.archive.org/web/1/x)' => 'Brent R. Hall was on its board of directors; ran the facility (per survivors), from 2015 to 2025.',
    'Trina Packard, CEO (2005-2011, HEAL) (HEAL, staff list for X (archived 2025-07-12))' => 'Trina Packard was its CEO from 2005 to 2011.',
    'Membership: Institutional Member of the Association of Recovery Schools (ARS) (Woodbury Reports, May 2008, p. 2)' => 'It was an institutional member of the Association of Recovery Schools (ARS).',
    'Note: unlicensed as of 2008 (Woodbury Reports, May 2008, p. 2)' => 'It was unlicensed as of 2008.',
    'Note: modeled on KIDS of North Jersey (Woodbury Reports, May 2008, p. 2)' => 'It was modeled on KIDS of North Jersey.',
    'Note: considered a branch of Island View RTC; later re-branded (HEAL, staff list for ViewPoint Center (archived 2025-07-12))' => 'It was considered a branch of Island View RTC; later re-branded.',
    'License: licensed and accredited specialty psychiatric hospital for adolescents (Woodbury Reports, May 2008, p. 2)' => 'It was a licensed and accredited specialty psychiatric hospital for adolescents.',
    'Temporarily closed in 2025 (Woodbury Reports, May 2008, p. 2)' => 'It was temporarily closed in 2025.',
    'Address: 82 acres in central North Carolina near Raleigh (Woodbury Reports, May 2008, p. 2)' => 'It sat on 82 acres in central North Carolina near Raleigh.',
    'Note: spring training camp held at a campground in Oklahoma City (Woodbury Reports, May 2008, p. 2)' => 'Spring training camp held at a campground in Oklahoma City.',
);
foreach ($cases as $line => $want) {
    $s = $one($line);
    $got = $s ? $s['text'] : '(none)';
    $check('"' . mb_substr($line, 0, 60) . '" -> ' . $want, $got === $want, 'got: ' . $got);
}

$s = $one('Cost: about $7,100 per month (r/troubledteens wiki, page "Aspen Ranch" (as of 2025-12-18))');
$check('a citation stays with its sentence', $s && count($s['sources']) === 1 && $s['sources'][0]['cite'] === 'r/troubledteens wiki, page "Aspen Ranch" (as of 2025-12-18)');
$s = $one('In Emmett since 2015 and closed March 18, 2022 (Idaho Press): https://www.idahopress.com/x');
$check('"(cite): url" keeps both', $s && $s['sources'][0]['url'] === 'https://www.idahopress.com/x' && $s['sources'][0]['cite'] === 'Idaho Press');
$s = $one('Campus: Nevada campus near Ely (Hidden Canyon Ranch)');
$check('a closing "(...)" that is part of the fact is not taken for a source', $s && !$s['sources'] && strpos($s['text'], 'Hidden Canyon Ranch') !== false);

$b = kop_pnotes_build(array(
    'Note: Uses Positive Control Systems (PCS) physical restraints (HEAL, Aspen Ranch Exposed! (archived 2015-08-25))',
    'Note: uses Positive Control Systems (PCS) physical restraints (HEAL, staff list for Aspen Ranch (archived 2025-08-29))',
));
$check('one fact from two sources is one sentence citing both', $b['sentences'] === 1 && count($b['paragraphs'][0][0]['sources']) === 2);
$b = kop_pnotes_build(array(
    'Added 2026-10-02 by the news scan from: HuffPost Canada, "Canadians Faced Shocking Abuse (2020-11-22)", 2020-11-22 https://www.huffingtonpost.ca/entry/x',
    'Corrected from outside sources (data audit): Psychiatric Solutions 10-K FY2009, properties table, 2010: https://www.sec.gov/b.htm',
    'Added 2026-09-30 from Woodbury Reports, February 2009 (#174), p. 20: https://kidsoverprofits.org/wp-content/uploads/2024/12/woodbury-0209.pdf',
    'Fornits lead (investigation): Posters mention a police investigation. (Fornits forum, post by Anonymous, December 2004: https://www.fornits.com/x)',
    'Reported closed on Fornits: A poster states that Genesis has been shut down. (Fornits forum, post by X, May 2005: https://www.fornits.com/y)',
    'Type: Residential Treatment Center (HEAL, Aspen Ranch Exposed! (archived 2015-08-25))',
    'Ages 12-17 (Woodbury Reports, July 2007 (#155), p. 21)',
    'migration: original status "Adults Only"',
    'Two facilities_master rows exist for this campus; this is the record with the license data.',
));
$check('bookkeeping lines become "Also drawn on", nothing in the prose', $b['sentences'] === 0 && count($b['also']) === 3, json_encode($b));
$check('the Woodbury wording never labels a link', $b['also'][2]['label'] === 'source');
$check('news-scan label is the article', $b['also'][0]['label'] === 'HuffPost Canada, "Canadians Faced Shocking Abuse"', $b['also'][0]['label']);
$check('forum claims go to the forum list', count($b['forum']) === 2);

$b = kop_pnotes_build(array('Sequel owned Falcon Ridge Ranch from August 2016. (Falcon Ridge Ranch homepage, Wayback capture (2021-09-27); KOP copy: https://kidsoverprofits.org/wp-content/uploads/frr.pdf, 2021-09-27: https://web.archive.org/web/20210927030443/https://falconridgeranch.com/)'));
$srcs = $b['paragraphs'][0][0]['sources'] ?? array();
$check('a Wayback citation with "KOP copy" cites the archive and our copy', count($srcs) === 2
    && strpos($srcs[0]['url'], 'web.archive.org') !== false && strpos($srcs[1]['url'], '/wp-content/uploads/frr.pdf') !== false, json_encode($srcs));
$b = kop_pnotes_build(array('Mountain Home Academy was founded in 2001 (SEC proxy filing, January 2017; APM Reports, 2022-04-26). (APM Reports: https://www.apmreports.org/x)'));
$s = $b['paragraphs'][0][0] ?? null;
$check('a second citation written in the sentence is a source, not words', $s && $s['text'] === 'Mountain Home Academy was founded in 2001.' && count($s['sources']) === 3, json_encode($s));

$b = kop_pnotes_build(array("Memberships: NATSAP (Woodbury Reports, August 2010, p. 9)\nNational Association of Addiction Treatment Providers"));
$texts = array_map(function ($s) { return $s['text']; }, $b['paragraphs'][0] ?? array());
$check('a note over two lines is two sentences, the second citing the first\'s source',
    $texts === array('It was a member of NATSAP.', 'It was a member of National Association of Addiction Treatment Providers.')
    && ($b['paragraphs'][0][1]['sources'][0]['cite'] ?? '') === 'Woodbury Reports, August 2010, p. 9', json_encode($texts));

$b = kop_pnotes_build(array(
    'Note: uses a rolling admissions process (Woodbury Reports, May 2008, p. 2)',
    'Closed in 2013 (Woodbury Reports, August 2013, p. 10)',
    'License: Licensed by the Idaho Department of Health and Welfare (Woodbury Reports, March 2007, p. 3)',
    'Founded by: Lester Roloff (Woodbury Reports, May 2008, p. 2)',
    'Opened: 1998 per Woodbury Reports, May 2008, p. 2',
));
$order = array_map(function ($p) { return $p[0]['topic']; }, $b['paragraphs']);
$check('paragraphs: history, then the program, then oversight', $order === array('history', 'program', 'oversight'), implode(',', $order));
$check('history oldest first', $b['paragraphs'][0][0]['text'] === 'It opened in 1998.' || $b['paragraphs'][0][0]['text'] === 'It was founded by Lester Roloff.', $b['paragraphs'][0][0]['text']);
$check('history ends with the closing', end($b['paragraphs'][0])['text'] === 'It closed in 2013.');

echo "\n-- Woodbury issue links --\n";
$u = kop_facility_pages_woodbury_url('Woodbury Reports, March 2007, p. 3');
$check('a Woodbury citation with no address links our copy, at its page', (bool) preg_match('#/woodbury-0307\.pdf\#page=3$#', $u), $u);
$check('an issue we do not hold links nothing', kop_facility_pages_woodbury_url('Woodbury Reports, March 1975, p. 3') === '');
$html = kop_facility_pages_sources_html(array(array('source' => 'Woodbury Reports, Mar 2007', 'cite' => 'Woodbury Reports, March 2007, p. 3', 'url' => '')), 'span');
$check('the source link never previews the Woodbury wording', strpos($html, 'woodbury-0307.pdf') !== false && stripos($html, 'March 2007') === false, $html);

echo "\n-- Every record --\n";
$records = array();
foreach ($wpdb->get_results('SELECT id, name, json_data FROM facilities_v2', ARRAY_A) as $r) {
    $doc = kop_v2_decode($r['json_data']);
    if (!$doc) continue;
    $notes = array_merge(kop_facility_pages_clean_notes($doc['notes'] ?? null), kop_facility_pages_clean_notes($doc['operatingPeriod']['notes'] ?? null));
    if ($notes) $records['f' . $r['id']] = array($r['name'], $notes);
}
foreach ($wpdb->get_results('SELECT id, name, json_data FROM wpdl_kop_operators', ARRAY_A) as $r) {
    $doc = json_decode((string) $r['json_data'], true);
    $op = is_array($doc) ? ($doc['operator'] ?? $doc) : array();
    $notes = kop_facility_pages_text_items($op['notes'] ?? null);
    if ($notes) $records['o' . $r['id']] = array($r['name'], $notes);
}
$want = isset($args['id']) ? array_map('trim', explode(',', (string) $args['id'])) : array();
$kinds = array();
$bad_url = $bad_end = $lost = $nosrc = 0;
$examples = array();
$sentences = 0;
foreach ($records as $key => $rec) {
    list($name, $notes) = $rec;
    $b = kop_pnotes_build($notes);
    foreach ($notes as $n) $kinds[kop_pnotes_kind($n)] = ($kinds[kop_pnotes_kind($n)] ?? 0) + 1;
    $sentences += $b['sentences'];
    foreach ($b['paragraphs'] as $p) foreach ($p as $s) {
        $plain = strip_tags(kop_facility_pages_cited_html($s['text']));
        if (preg_match('#https?://|www\.#', $plain)) { $bad_url++; $examples['url'][] = $plain; }
        if (!preg_match('/[.!?]["\')]?$/', $s['text'])) { $bad_end++; $examples['end'][] = $s['text']; }
    }
    // A sourced fact keeps a source.
    foreach ($notes as $n) {
        if (kop_pnotes_kind($n) !== 'fact') continue;
        $c = kop_pnotes_split_cite(preg_replace('/^[A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}:\s+/', '', $n));
        if ($c['cite'] === '' && $c['url'] === '') continue;
        // A citation of one of our own pages is left out on purpose (kop_facility_pages_is_own_source()).
        $plain_cite = trim((string) preg_replace('/;?\s*KOP copy:\s*https?:\/\/[^\s,]+,?/i', '', $c['cite']), ' ,;');
        if (kop_facility_pages_is_own_source(array('source' => '', 'cite' => $plain_cite, 'url' => $c['url']))) continue;
        $found = false;
        foreach ($b['paragraphs'] as $p) foreach ($p as $s) foreach ($s['sources'] as $src) {
            if (($plain_cite !== '' && strpos($src['cite'], $plain_cite) === 0) || ($c['url'] !== '' && $src['url'] === $c['url'])) $found = true;
        }
        if (!$found) { $nosrc++; $examples['nosrc'][] = $n; }
    }
    if (isset($args['list']) || in_array(ltrim($key, 'fo'), $want, true) || in_array($key, $want, true)) {
        echo "\n## $name ($key)\n";
        foreach ($b['paragraphs'] as $p) {
            echo '   ' . implode(' ', array_map(function ($s) { return $s['text'] . ' [' . count($s['sources']) . ']'; }, $p)) . "\n\n";
        }
        foreach ($b['also'] as $a) echo '   also: ' . $a['label'] . ' <' . $a['url'] . ">\n";
    }
}
ksort($kinds);
printf("  %d records with notes; %d sentences; notes by kind: %s\n", count($records), $sentences, json_encode($kinds));
$check('no web address is printed as words', $bad_url === 0, implode(' | ', array_slice($examples['url'] ?? array(), 0, 3)));
$check('every sentence ends', $bad_end === 0, implode(' | ', array_slice($examples['end'] ?? array(), 0, 5)));
$check('every sourced fact keeps its source', $nosrc === 0, $nosrc . ': ' . implode(' | ', array_slice($examples['nosrc'] ?? array(), 0, 3)));

echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
