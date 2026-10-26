<?php
/**
 * Template Name: Researcher Data Kit
 * Description: The record shapes KOP stores (facilities, companies, people,
 * lawsuits, bills, news, inspection reports, source links), what goes where,
 * and blank templates for outside researchers to scrape or collect into.
 *
 * The blank templates, filled examples and the full stored facility shape are
 * static files in researcher-kit/ (shipped by .cpanel.yml); ?download=zip
 * bundles them. The allowed values (facility types, statuses, article types,
 * content warnings, link kinds...) are read from the Dropdown Lists, so the
 * page never lists a value the records would refuse. When a facility field
 * changes (docs/FACILITY-SCHEMA.md), update researcher-kit/templates/ too.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_rk_dir = get_stylesheet_directory() . '/researcher-kit';
$kop_rk_url = get_stylesheet_directory_uri() . '/researcher-kit';

// The whole kit as one zip, built from the folder on request.
if (isset($_GET['download']) && $_GET['download'] === 'zip' && class_exists('ZipArchive')) {
    $kop_rk_tmp = tempnam(get_temp_dir(), 'kopkit');
    $kop_rk_zip = new ZipArchive();
    if ($kop_rk_tmp && $kop_rk_zip->open($kop_rk_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach (array('templates', 'examples', 'reference') as $kop_rk_sub) {
            foreach ((array) glob("$kop_rk_dir/$kop_rk_sub/*.json") as $kop_rk_file) {
                $kop_rk_zip->addFile($kop_rk_file, "kop-researcher-kit/$kop_rk_sub/" . basename($kop_rk_file));
            }
        }
        $kop_rk_zip->addFromString('kop-researcher-kit/README.txt', implode("\r\n", array(
            'Kids Over Profits Researcher Data Kit',
            '',
            'The guide to every field, the allowed values and the rules is at',
            get_permalink(),
            '',
            'templates/   blank files to copy and fill, one per kind of record',
            'examples/    the same shapes filled with records KOP already publishes',
            'reference/   the full facility record as KOP stores it, fields KOP fills itself included',
            '',
            'Nothing you send is published until a Kids Over Profits editor has checked it.',
        )) . "\r\n");
        $kop_rk_zip->close();
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="kop-researcher-kit.zip"');
        header('Content-Length: ' . filesize($kop_rk_tmp));
        readfile($kop_rk_tmp);
        @unlink($kop_rk_tmp);
        exit;
    }
}

foreach (array('open-data', 'researcher-kit') as $kop_rk_css) {
    $kop_rk_path = get_stylesheet_directory() . "/css/$kop_rk_css.css";
    if (file_exists($kop_rk_path)) {
        wp_enqueue_style('kop-' . $kop_rk_css, get_stylesheet_directory_uri() . "/css/$kop_rk_css.css", array('kop-colors'), filemtime($kop_rk_path));
    }
}

get_header();

$kop_rk_icon = static function ($name) {
    return function_exists('kop_icon') ? kop_icon($name) : '';
};

// value => label from a Dropdown List (labels may be arrays with 'label').
$kop_rk_list = static function ($list) {
    $out = array();
    foreach ((array) $list as $value => $item) {
        $label = is_array($item) ? (string) ($item['label'] ?? $value) : (string) $item;
        $out[is_int($value) ? $label : (string) $value] = $label;
    }
    return $out;
};

// The allowed values as chips: the stored value, with its label when it differs.
$kop_rk_values = static function ($title, array $values) {
    if (!$values) return;
    echo '<p class="kop-rk-values-title">' . esc_html($title) . '</p><ul class="kop-rk-values">';
    foreach ($values as $value => $label) {
        echo '<li><code>' . esc_html($value) . '</code>';
        if (strcasecmp($label, $value) !== 0) echo ' <span>' . esc_html($label) . '</span>';
        echo '</li>';
    }
    echo '</ul>';
};

// A field table: rows of [field, type, what goes here], a field marked required with a trailing '*'.
$kop_rk_table = static function (array $rows) {
    echo '<div class="kop-od-table-wrap"><table class="kop-od-table kop-rk-fields"><thead><tr>'
        . '<th scope="col">Field</th><th scope="col">Type</th><th scope="col">What goes here</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $required = substr($row[0], -1) === '*';
        $field = $required ? substr($row[0], 0, -1) : $row[0];
        echo '<tr><th scope="row"><code>' . esc_html($field) . '</code>'
            . ($required ? ' <span class="kop-rk-required">required</span>' : '')
            . '</th><td>' . esc_html($row[1]) . '</td><td>' . wp_kses($row[2], array('code' => array())) . '</td></tr>';
    }
    echo '</tbody></table></div>';
};

$kop_rk_file = static function ($path, $label) use ($kop_rk_dir, $kop_rk_url, $kop_rk_icon) {
    if (!file_exists("$kop_rk_dir/$path")) return;
    printf(
        '<a class="kop-od-file" href="%s" download>%s<span>%s</span><span class="kop-od-file-size">%s</span></a>',
        esc_url("$kop_rk_url/$path"),
        $kop_rk_icon('download'),
        esc_html($label),
        esc_html(size_format(filesize("$kop_rk_dir/$path")))
    );
};

$kop_rk_kinds = array(
    'facilities'   => array('Facilities', 'Programs for minors: names, addresses, years, operators, staff, licensing, practices, links.'),
    'companies'    => array('Companies', 'Companies that own or run programs, their executives, investors and parent companies.'),
    'people'       => array('People', 'People who turn up across programs: their jobs and the people they are tied to.'),
    'lawsuits'     => array('Lawsuits', 'Civil and criminal cases.'),
    'bills'        => array('Bills', 'State and federal bills and laws.'),
    'news'         => array('News', 'Articles, op-eds, press releases, broadcast segments.'),
    'inspections'  => array('Inspections', 'State licensing inspections, complaint investigations and citations, the shape KOP\'s own scrapers write.'),
    'source-links' => array('Source links', 'Leads you have not filed into a record: a link and a note.'),
);

$kop_rk_contact = get_page_by_path('contact');
?>

<div class="kop-od-page kop-rk-page">

    <header class="kop-od-header">
        <h1 class="kop-od-title"><?php the_title(); ?></h1>
        <p class="kop-od-summary">
            <?php if (has_excerpt()) : ?>
                <?php echo esc_html(get_the_excerpt()); ?>
            <?php else : ?>
                The record shapes Kids Over Profits stores, where each fact you collect belongs, and blank templates to scrape or type into.
            <?php endif; ?>
        </p>
    </header>

    <section class="kop-od-intro" aria-labelledby="kop-rk-intro-heading">
        <h2 id="kop-rk-intro-heading" class="kop-od-intro-title">For researchers collecting data with us</h2>
        <p>
            The templates use KOP's own field names and formats, so what you send can be loaded into our review queues without retyping.
            Nothing is published until a KOP editor has checked it.
        </p>
        <p>
            To read what KOP already holds, use the <a href="<?php echo esc_url(home_url('/open-data/')); ?>">open data downloads</a>: they use the same shapes.
        </p>
    </section>

    <?php
    while (have_posts()) :
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="kop-od-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;
    ?>

    <nav class="kop-rk-toc" aria-label="On this page">
        <a href="#downloads">Downloads</a>
        <a href="#how">How to send</a>
        <a href="#rules">Rules</a>
        <a href="#where">What goes where</a>
        <a href="#scope">What counts as a facility</a>
        <?php foreach ($kop_rk_kinds as $kop_rk_key => $kop_rk_kind) : ?>
            <a href="#<?php echo esc_attr($kop_rk_key); ?>"><?php echo esc_html($kop_rk_kind[0]); ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="kop-od-bulk" id="downloads" aria-labelledby="kop-rk-downloads-heading">
        <h2 id="kop-rk-downloads-heading" class="kop-od-section-title">Downloads</h2>
        <div class="kop-od-bulk-grid">
            <a class="kop-od-bulk-card" href="<?php echo esc_url(add_query_arg('download', 'zip', get_permalink())); ?>" download>
                <?php echo $kop_rk_icon('download'); ?>
                <span class="kop-od-bulk-name">The whole kit</span>
                <span class="kop-od-bulk-meta">ZIP. Every blank template, the filled examples and the full stored facility record.</span>
            </a>
        </div>
        <ul class="kop-od-list kop-rk-downloads">
            <?php foreach ($kop_rk_kinds as $kop_rk_key => $kop_rk_kind) : ?>
                <li class="kop-od-item">
                    <div class="kop-od-item-text">
                        <h3 class="kop-od-item-title"><?php echo esc_html($kop_rk_kind[0]); ?></h3>
                        <p class="kop-od-item-desc"><?php echo esc_html($kop_rk_kind[1]); ?> <a href="#<?php echo esc_attr($kop_rk_key); ?>">Fields</a></p>
                    </div>
                    <div class="kop-od-item-files">
                        <?php $kop_rk_file("templates/$kop_rk_key.json", 'Template'); ?>
                        <?php $kop_rk_file("examples/$kop_rk_key.example.json", 'Example'); ?>
                    </div>
                </li>
            <?php endforeach; ?>
            <li class="kop-od-item">
                <div class="kop-od-item-text">
                    <h3 class="kop-od-item-title">Stored facility record</h3>
                    <p class="kop-od-item-desc">Every key of a facility as KOP stores it, the ones KOP fills itself included. For reading the open data files.</p>
                </div>
                <div class="kop-od-item-files"><?php $kop_rk_file('reference/facility-stored-shape.json', 'Reference'); ?></div>
            </li>
        </ul>
    </section>

    <section id="how" aria-labelledby="kop-rk-how-heading">
        <h2 id="kop-rk-how-heading" class="kop-od-section-title">How to send</h2>
        <p>
            Use one JSON file per kind of record, copied from a template above. A file can hold as many records as you like: add entries to its list.
            Every file starts with the same fields:
        </p>
        <?php $kop_rk_table(array(
            array('submitted_by', 'text', 'Your name or your group\'s name, as you want it credited.'),
            array('collected_on', 'date', 'When you collected the data, <code>YYYY-MM-DD</code>.'),
            array('method', 'text', 'How you got it: "scraped the Texas HHS search page with Python", "read the 1998-2004 NATSAP directories by hand".'),
            array('kit_version, kind', 'fixed', 'Leave as they are. They tell the importer which shape it is reading.'),
        )); ?>
        <p>
            Name the file after what it covers (<code>facilities-utah-licensing-2026-10.json</code>). If KOP gave you a personal upload link,
            send your files there, as many at a time and as large as you like (a .zip is fine), along with the documents themselves
            (court records, reports, handbooks, scans) under "Send documents". Otherwise send them to
            <?php if ($kop_rk_contact) : ?>
                us through the <a href="<?php echo esc_url(get_permalink($kop_rk_contact)); ?>">contact page</a>, or to whoever sent you here.
            <?php else : ?>
                whoever sent you here.
            <?php endif; ?>
            A spreadsheet is fine too, as long as its column headings are the field paths on this page (<code>location.city</code>, <code>operatingPeriod.startYear</code>);
            a list goes in one cell, separated by <code>|</code>.
        </p>
    </section>

    <section id="rules" aria-labelledby="kop-rk-rules-heading">
        <h2 id="kop-rk-rules-heading" class="kop-od-section-title">Rules for every file</h2>
        <ol class="kop-rk-rules">
            <li><strong>Every fact carries its source.</strong> A record with a place for one (<code>source</code>, <code>sourceUrl</code>, <code>source_urls</code>, <code>resourceLinks</code>) takes it there; a free-text note ends with its link: <code>"Closed in March 2019 after the state revoked its license (source: https://...)"</code>. A primary source beats a report about it: the court filing over the article, the inspection report over a summary, the program's own website or handbook, tax returns and business filings.</li>
            <li><strong>Leave a field empty when you don't know.</strong> An empty string, an empty list or <code>null</code>. Never write "Unknown", "N/A", "none" or "?" into a field, because those read as facts. Status fields are the one exception: they have their own <code>Unknown</code> value.</li>
            <li><strong>Copy names exactly as the source spells them.</strong> Don't tidy, expand or guess a program's, company's or person's name. Another spelling from another source goes in <code>otherNames</code>. A different middle initial means a different person until a source says otherwise.</li>
            <li><strong>Use the formats KOP stores.</strong> Years are whole numbers (<code>1998</code>, never <code>"1998"</code> or "late 90s"; put the words in a note). Dates are <code>YYYY-MM-DD</code>. US states are two-letter codes in capitals (<code>UT</code>). Countries are full English names (<code>United States</code>, <code>Mexico</code>). ZIP codes stay text, so <code>"04530"</code> keeps its zero.</li>
            <li><strong>A renamed program keeps its old names with their years.</strong> Earlier names go in <code>pastNames</code>, and <code>operatingPeriod.notes</code> says when each name changed and who ran it under that name. A place that reopened under a different operator is its own record.</li>
            <li><strong>Archived copies.</strong> If you read something on the Wayback Machine or another archive, give both the original address and the archive link. KOP keeps its own copy.</li>
            <li><strong>Protect private people.</strong> Name staff, owners, officials and anyone who has spoken publicly under their own name. Never send the names or details of children, or of survivors who have not gone public. Survivor accounts come only from public posts, and stay hidden until KOP checks them.</li>
        </ol>
    </section>

    <section id="where" aria-labelledby="kop-rk-where-heading">
        <h2 id="kop-rk-where-heading" class="kop-od-section-title">What goes where</h2>
        <p>Records link up by name: when a lawsuit names a facility, write the facility's name as the source spells it, with its city and state, and KOP connects them.</p>
        <div class="kop-od-table-wrap">
            <table class="kop-od-table">
                <thead><tr><th scope="col">You found</th><th scope="col">File</th><th scope="col">Field</th></tr></thead>
                <tbody>
                    <?php
                    $kop_rk_where = array(
                        array('A program for minors: its name, address, years, type, ages', 'facilities', 'identification, location, operatingPeriod, facilityDetails'),
                        array('A program\'s state business filing (legal name, DBAs, agent, officers)', 'facilities', 'identification.legalName, dbaNames, registrants'),
                        array('A program\'s license number or licensing agency', 'facilities', 'licensing'),
                        array('Trade group membership; accreditation', 'facilities', 'memberships; accreditations'),
                        array('Medicaid, foster care, ORR, TRICARE, PPP or other federal money', 'facilities', 'federalFunding'),
                        array('What a program says it treats or uses (diagnoses, behaviors, level systems, restraint)', 'facilities', 'targetedDiagnoses, targetedBehaviors, treatmentTypes, ttiPractices, philosophy'),
                        array('Deaths, staff arrests, hospitalizations, riots at a program', 'facilities', 'criticalIncidents (tick) + notes (what happened, with source)'),
                        array('A page about a program: licensing record, court page, forum thread, staff profile, ad', 'facilities', 'resourceLinks, with a kind'),
                        array('A public survivor account of a program', 'facilities', 'survivorTestimony'),
                        array('A staff member or director of one program', 'facilities', 'staff.administrator, staff.notableStaff'),
                        array('A company that owns or runs programs, its executives, investors, parent companies', 'companies', 'operator, facilities'),
                        array('A person\'s jobs across programs, or two people\'s relationship', 'people', 'jobs, relations'),
                        array('A court case', 'lawsuits', 'the whole record'),
                        array('A bill or law', 'bills', 'the whole record'),
                        array('A news article, op-ed or press release', 'news', 'the whole record'),
                        array('State licensing inspections, complaints or citations', 'inspections', 'facility_info, reports'),
                        array('Anything else relevant you have no time to file', 'source-links', 'the whole record'),
                    );
                    foreach ($kop_rk_where as $kop_rk_row) :
                        ?>
                        <tr>
                            <td><?php echo esc_html($kop_rk_row[0]); ?></td>
                            <td><a href="#<?php echo esc_attr($kop_rk_row[1]); ?>"><?php echo esc_html($kop_rk_kinds[$kop_rk_row[1]][0]); ?></a></td>
                            <td><code><?php echo esc_html($kop_rk_row[2]); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section id="scope" aria-labelledby="kop-rk-scope-heading">
        <h2 id="kop-rk-scope-heading" class="kop-od-section-title">What counts as a facility</h2>
        <p>
            A facility is a residential program that holds people under 18: residential treatment centers, psychiatric residential treatment facilities,
            therapeutic and religious boarding schools, wilderness and outdoor programs, boot camps, ranches, group homes, maternity homes,
            shelters for migrant children (Office of Refugee Resettlement), and juvenile detention and correctional facilities.
        </p>
        <p>
            Leave out foster family homes, adult group homes, nursing homes and programs only for adults. A program for people 18 and older that is tied
            to the industry (a "young adult" program run by a youth program's company) is still worth sending: file it under facilities with
            <code>ageRange.min</code> at 18 or more, and KOP keeps it on its own list. The same goes for Indian boarding and residential schools.
        </p>
    </section>

    <section id="facilities" aria-labelledby="kop-rk-facilities-heading">
        <h2 id="kop-rk-facilities-heading" class="kop-od-section-title">Facilities</h2>
        <p>
            One entry per program at one place. Two campuses run as one program are one record (the second goes in <code>additionalLocations</code>);
            two programs on one campus are two records.
        </p>

        <h3 class="kop-rk-sub">identification</h3>
        <?php $kop_rk_table(array(
            array('name*', 'text', 'The program\'s name as its source gives it.'),
            array('currentName', 'text', 'The name it trades under now, only when that differs from <code>name</code>.'),
            array('otherNames', 'list of text', 'Other spellings and names in use at the same time.'),
            array('pastNames', 'list of text', 'Earlier names. The years of each change go in <code>operatingPeriod.notes</code>.'),
            array('legalName', 'text', 'The name on its state business filing ("CT Clinical Services, LLC").'),
            array('dbaNames', 'list of text', 'Trade names registered on that filing (doing business as).'),
            array('registrants', 'list of {name, role, source, sourceUrl}', 'Who the filing names: registered agent, organizer, members, managers, officers. A name can be a person or a company.'),
            array('currentOperator', 'text', 'The company that runs it now.'),
            array('currentOwners', 'list of text', 'Who owns it now, if not the operator.'),
            array('pastOwners, pastOperators', 'list of text', 'Earlier owners and operators, with years in brackets when known: "Charter Behavioral Health Systems (1986-2000)".'),
            array('otherOperators', 'list of text', 'Other companies running it at the same time (a management contract).'),
            array('knownReferrers', 'list of text', 'Educational consultants, transport companies and others known to send children there.'),
            array('investors', 'list of text', 'Private equity firms, lenders and other backers.'),
        )); ?>

        <h3 class="kop-rk-sub">location</h3>
        <?php $kop_rk_table(array(
            array('raw', 'text', 'The address on one line exactly as the source gives it.'),
            array('text', 'text', 'A looser place description when there is no address ("Southern Utah").'),
            array('street, city, zip', 'text', 'The address in parts, when you can split it.'),
            array('state', 'two-letter code or null', '<code>UT</code>; <code>null</code> outside the US.'),
            array('country', 'text or null', '<code>United States</code> whenever a state is set.'),
            array('additionalLocations', 'list of {raw, street, city, state, zip, country}', 'Other campuses of the same program, open now.'),
            array('formerLocations', 'list of {raw, city, state, country, fromYear, toYear}', 'Places it moved away from, with the years it was there.'),
        )); ?>

        <h3 class="kop-rk-sub">operatingPeriod</h3>
        <?php $kop_rk_table(array(
            array('startYear, endYear', 'whole number or null', 'The year it opened and the year it closed; <code>endYear</code> stays <code>null</code> while it is open.'),
            array('status', 'one value', '<code>Open</code>, <code>Closed</code>, <code>Suspended</code> (license suspended or revoked, under appeal), <code>Transferred</code> (handed to another operator under a new record), <code>Unknown</code>.'),
            array('yearsOfOperation', 'text', 'The years as the source words them ("1971-present").'),
            array('notes', 'list of text', 'Dated events with sources: openings, renames, closures, license actions.'),
        )); ?>

        <h3 class="kop-rk-sub">facilityDetails</h3>
        <?php $kop_rk_table(array(
            array('type', 'text', 'One of the facility types below; several are joined with ", ".'),
            array('capacity', 'whole number or null', 'Licensed beds.'),
            array('currentCensus', 'whole number or null', 'Children there on the date of the source; give the date in a note.'),
            array('ageRange', '{min, max}', 'Whole numbers or <code>null</code>.'),
            array('gender', 'one value', '<code>Male</code>, <code>Female</code>, <code>Co-ed</code>, or empty.'),
            array('isPrivatelyOwned', 'true, false or null', ''),
        )); ?>
        <?php if (function_exists('kop_facdisc_types')) $kop_rk_values('Facility types', $kop_rk_list(kop_facdisc_types('offered'))); ?>

        <h3 class="kop-rk-sub">staff</h3>
        <?php $kop_rk_table(array(
            array('staff.administrator', 'list of {name, role, pastJobs, source, sourceUrl}', 'Executive directors, CEOs, founders and owners on site.'),
            array('staff.notableStaff', 'same', 'Therapists, teachers, medical directors and other staff a source names. <code>pastJobs</code> is a line of text ("Clinical director at Island View, 2004-2008").'),
            array('staff.pastTTIJobs', 'list of {role, organization, employer}', 'Other industry programs where this program\'s staff worked; <code>employer</code> repeats <code>organization</code>.'),
        )); ?>
        <p>
            Roles take "a" or "an" for jobs many people hold (therapist, teacher, staff member) and "the" for one-holder titles (director, CEO, founder).
            No "former" and no abbreviations inside a role, and a year only when it is a start or an end ("from 2010 to 2012").
        </p>

        <h3 class="kop-rk-sub">Memberships, licensing and links</h3>
        <?php $kop_rk_table(array(
            array('accreditations', '{current: [], past: []}', 'Joint Commission, CARF, COA, Cognia and others, by name.'),
            array('memberships', 'list of text', 'NATSAP, OBH Council, IECA and other trade groups; "former member" in the text when it has left.'),
            array('certifications', 'list of text', 'Staff certifications the program lists.'),
            array('licensing', 'list of text', 'License type, number and agency: "Utah DHHS Office of Licensing, residential treatment, license 93696".'),
            array('profileLinks', 'list of URLs', 'The program\'s own websites.'),
            array('resourceLinks', 'list of {url, label, kind, source}', 'Outside pages about it. <code>label</code> is a short title; <code>source</code> says where you found the link.'),
            array('federalFunding', 'list of {stream, detail, years, sources, source}', 'Federal money it received. <code>detail</code> says what (a rate, a contract, a loan amount); <code>years</code> is text; <code>sources</code> is a list of {url, label}.'),
        )); ?>
        <?php if (function_exists('kop_facility_resource_link_kinds')) $kop_rk_values('resourceLinks kind', $kop_rk_list(kop_facility_resource_link_kinds('offered', true))); ?>
        <?php if (function_exists('kop_facility_federal_funding_streams')) $kop_rk_values('federalFunding stream', kop_facility_federal_funding_streams()); ?>

        <h3 class="kop-rk-sub">Checklists</h3>
        <p>
            <code>treatmentTypes</code>, <code>targetedDiagnoses</code>, <code>targetedBehaviors</code>, <code>ttiPractices</code>, <code>philosophy</code>
            and <code>criticalIncidents</code> are tick boxes: set a box to <code>true</code> only when a source shows it, and put that source in
            <code>notes</code> or <code>resourceLinks</code>. Every box is in the facilities template. A practice with no box goes in
            <code>ttiPractices.other</code> as text. <code>resources</code> records which kinds of material exist about the program
            (<code>hasNews</code>, <code>hasLawsuits</code>, <code>hasInspections</code>...); its <code>...Details</code> fields take a line of text.
        </p>

        <h3 class="kop-rk-sub">Notes and survivor accounts</h3>
        <?php $kop_rk_table(array(
            array('notes', 'list of text', 'Facts that fit nowhere else, one per line, each ending with its source.'),
            array('fieldNotes', 'map', 'Leave empty; KOP editors use it.'),
            array('survivorTestimony', 'list of {text, source, date, publish}', 'A public account quoted or summarized, its link in <code>source</code>. <code>publish</code> stays <code>false</code>.'),
        )); ?>
        <p class="kop-rk-note">
            The stored record also has <code>schema_version</code>, <code>facility_id</code>, <code>identification.nameKey</code>, <code>providerDetails</code>,
            <code>documentFolderId</code>, <code>provenance</code> and <code>legacy</code>. KOP fills those itself; leave them out.
        </p>
    </section>

    <section id="companies" aria-labelledby="kop-rk-companies-heading">
        <h2 id="kop-rk-companies-heading" class="kop-od-section-title">Companies</h2>
        <p>A company that owns or runs programs, or a parent or holding company above one. <code>name</code> is its full name with its suffix ("Psychiatric Solutions, Inc.").</p>
        <?php $kop_rk_table(array(
            array('name*', 'text', 'The company\'s full name.'),
            array('operator.currentName', 'text', 'The name it uses now, when different.'),
            array('operator.otherNames', 'list of text', 'Other and earlier names, short forms.'),
            array('operator.status', 'text', '<code>Active</code>, <code>Acquired</code>, <code>Merged</code>, <code>Defunct</code>, or empty.'),
            array('operator.founded', 'text', 'Year, and how: "1988 (incorporated in Delaware)".'),
            array('operator.operatingPeriod', 'text', '"1988 to 2010".'),
            array('operator.headquarters', 'text', 'The full headquarters address, with <code>headquartersCity</code> and <code>headquartersState</code> split out.'),
            array('operator.location', 'text', '"Franklin, TN", with <code>locationCity</code> and <code>locationState</code> split out.'),
            array('operator.websites', 'list of URLs', ''),
            array('operator.parentCompanies, owners, investors', 'list of text', 'With years in brackets when known.'),
            array('operator.keyStaff', '{ceo, founders, keyExecutives}', '<code>ceo</code> is a name; the two lists take {name, role, pastJobs}.'),
            array('operator.notes', 'list of text', 'Dated events with sources: acquisitions, sales, bankruptcies, settlements.'),
            array('facilities', 'list of {name, city, state, relationship, years, source}', 'Programs it runs or ran; <code>relationship</code> is <code>current</code> or <code>past</code>.'),
        )); ?>
    </section>

    <section id="people" aria-labelledby="kop-rk-people-heading">
        <h2 id="kop-rk-people-heading" class="kop-od-section-title">People</h2>
        <p>For someone who turns up across several programs or companies. KOP gives every person one id and shows their jobs on each page that names them.</p>
        <?php $kop_rk_table(array(
            array('name*', 'text', 'Full name as a source gives it, middle initial included.'),
            array('otherNames', 'list of text', 'Maiden names, nicknames, other spellings a source shows are the same person.'),
            array('pool', 'one value', '<code>industry</code> (works or worked in or for the industry, including people who went through a program and now work in it), <code>press</code> (reporters, authors), <code>harmed</code> (survivors who have gone public).'),
            array('jobs', 'list of {place, placeCity, placeState, role, years, source, sourceUrl}', 'One line per job. <code>place</code> is a program or company; <code>years</code> is text ("2004-2008").'),
            array('relations', 'list of {otherName, kind, detail, source, sourceUrl}', 'Ties to another person: <code>kind</code> such as spouse, parent, sibling, business partner.'),
            array('notes', 'list of text', 'Arrests, licenses lost, statements, each with its source.'),
        )); ?>
    </section>

    <section id="lawsuits" aria-labelledby="kop-rk-lawsuits-heading">
        <h2 id="kop-rk-lawsuits-heading" class="kop-od-section-title">Lawsuits</h2>
        <?php $kop_rk_table(array(
            array('case_name*', 'text', 'The caption, with the program in brackets when the caption doesn\'t name it.'),
            array('case_number', 'text', 'Docket number or citation.'),
            array('court', 'text', '"U.S. District Court for the District of Utah".'),
            array('jurisdiction', 'text', 'The state\'s full name, or <code>Federal</code>.'),
            array('filing_date', 'date or null', '<code>YYYY-MM-DD</code>. With only the year, use <code>YYYY-01-01</code> and say so in <code>summary</code>.'),
            array('status', 'one value', 'Listed below.'),
            array('plaintiffs, defendants', 'list of text', 'As the caption names them. Minor plaintiffs by initials or "Doe" names only.'),
            array('facilities_mentioned', 'list of text', '"Program name (City, State)".'),
            array('staff_mentioned, organizations_mentioned', 'list of text', 'Staff and owners; companies.'),
            array('claims', 'list of text', 'Each claim in plain words ("Wrongful death", "Negligent supervision").'),
            array('outcome', 'text', 'Verdict, ruling, dismissal or settlement terms.'),
            array('settlement_amount', 'text', 'As stated: "$4.5 million".'),
            array('summary', 'text', 'What the case is about, in a few sentences.'),
            array('source_urls', 'list of URLs', 'Articles and docket pages.'),
            array('document_urls', 'list of URLs', 'The filings themselves: complaints, orders, opinions.'),
            array('tags', 'list of text', 'Free topics: restraint, transport, sexual abuse.'),
        )); ?>
        <?php if (function_exists('kop_lawsuit_statuses')) $kop_rk_values('status', $kop_rk_list(kop_lawsuit_statuses('offered'))); ?>
    </section>

    <section id="bills" aria-labelledby="kop-rk-bills-heading">
        <h2 id="kop-rk-bills-heading" class="kop-od-section-title">Bills</h2>
        <p>The file's list is called <code>legislation</code>.</p>
        <?php $kop_rk_table(array(
            array('bill_title*', 'text', 'Official or short title.'),
            array('bill_number', 'text', '"HB 123", "S. 1351".'),
            array('jurisdiction', 'text', 'The state\'s full name, or <code>Federal</code>.'),
            array('chamber', 'one value', '<code>house</code>, <code>senate</code>, <code>assembly</code>, <code>joint</code>, <code>federal_house</code>, <code>federal_senate</code>, <code>other</code>, <code>unknown</code>.'),
            array('session_year', 'text', '"2025" or "2025-2026".'),
            array('bill_type', 'text', 'HB, SB, AB, HR, SR...'),
            array('sponsors', 'list of text', '"Ron Wyden (D-OR)".'),
            array('status', 'one value', 'Listed below.'),
            array('introduced_date, last_action_date', 'date or null', '<code>YYYY-MM-DD</code>.'),
            array('last_action_text', 'text', 'The legislature\'s own wording of the last action.'),
            array('subject_tags', 'list of text', 'restraint, oversight, licensing, Medicaid...'),
            array('summary', 'text', 'What the bill does.'),
            array('full_text_url, official_url', 'URL', 'The bill text; the legislature\'s tracking page.'),
            array('facilities_affected', 'list of text', 'Programs it names or was written about.'),
            array('tags', 'list of text', 'Free topics.'),
        )); ?>
        <?php if (function_exists('kop_bill_statuses')) $kop_rk_values('status', $kop_rk_list(kop_bill_statuses('offered'))); ?>
    </section>

    <section id="news" aria-labelledby="kop-rk-news-heading">
        <h2 id="kop-rk-news-heading" class="kop-od-section-title">News</h2>
        <p>The file's list is called <code>articles</code>. If all you have is the link, use a source link instead.</p>
        <?php $kop_rk_table(array(
            array('article_title*', 'text', 'The headline as published.'),
            array('author', 'text', 'Byline.'),
            array('publication_name', 'text', '"The Salt Lake Tribune".'),
            array('publication_date', 'date or null', '<code>YYYY-MM-DD</code>.'),
            array('article_url*', 'URL', 'The original address.'),
            array('archive_url', 'URL', 'An archived copy, if you used one.'),
            array('article_type', 'one value', 'Listed below.'),
            array('facilities_mentioned, staff_mentioned', 'list of text', 'Programs ("Name (City, State)") and staff it names.'),
            array('content_warnings', 'list of text', 'From the list below.'),
            array('tags', 'list of text', 'Topics; KOP\'s usual ones are below.'),
            array('summary', 'text', 'Two or three sentences on what the article reports.'),
        )); ?>
        <?php if (function_exists('kop_news_article_types')) $kop_rk_values('article_type', kop_news_article_types('offered')); ?>
        <?php if (function_exists('kop_news_content_warnings')) $kop_rk_values('content_warnings', $kop_rk_list(kop_news_content_warnings('offered'))); ?>
        <?php if (function_exists('kop_choices')) $kop_rk_values('tags', $kop_rk_list(kop_choices('news-tags', null, 'offered'))); ?>
    </section>

    <section id="inspections" aria-labelledby="kop-rk-inspections-heading">
        <h2 id="kop-rk-inspections-heading" class="kop-od-section-title">Inspections</h2>
        <p>
            The shape KOP's own state scrapers write, so a scraper's output can be loaded as it is. One file per state, with <code>state</code>
            (two-letter code), <code>scraped_timestamp</code> (ISO 8601) and <code>source_site</code> (the search page you scraped).
            Keep the report text whole; KOP reads the findings out of it.
        </p>
        <?php $kop_rk_table(array(
            array('facility_info.facility_name*', 'text', 'The licensee or site name as the state lists it.'),
            array('facility_info.program_name', 'text', 'The program or building name, when the state lists one separately.'),
            array('facility_info.full_address, phone', 'text', ''),
            array('facility_info.program_category', 'text', 'The state\'s own license category ("Group Home").'),
            array('facility_info.executive_director', 'text', ''),
            array('facility_info.bed_capacity', 'text', 'As the state shows it.'),
            array('facility_info.license_number, license_exp_date, relicense_visit_date', 'text', 'As the state shows them.'),
            array('facility_info.action', 'text', 'Any license action shown (probation, revocation).'),
            array('reports[].report_id*', 'text', 'The state\'s id for the report, or another value unique within the facility.'),
            array('reports[].report_date', 'text', 'As the state shows it.'),
            array('reports[].report_url', 'URL', 'A link that opens this report, not the search page.'),
            array('reports[].raw_content', 'text', 'The full report text.'),
            array('reports[].content_length', 'whole number', 'Characters in <code>raw_content</code>.'),
            array('reports[].summary', 'text', 'Optional one-line summary.'),
        )); ?>
    </section>

    <section id="source-links" aria-labelledby="kop-rk-links-heading">
        <h2 id="kop-rk-links-heading" class="kop-od-section-title">Source links</h2>
        <p>A plain list. KOP checks each link against what it already holds and sends the new ones to its review inbox.</p>
        <?php $kop_rk_table(array(
            array('type', 'one value', '<code>article</code> (news, opinion, press release), <code>lawsuit</code> (court records, dockets), <code>legislation</code> (bills), <code>website</code> (everything else).'),
            array('url*', 'URL', ''),
            array('title', 'text', 'The page\'s title.'),
            array('notes', 'text', 'Which program, company or person it is about, why it matters, and the key quote.'),
            array('website_kind', 'one value', 'For <code>website</code> only; listed below.'),
        )); ?>
        <?php if (function_exists('kop_ext_website_kinds')) $kop_rk_values('website_kind', $kop_rk_list(kop_ext_website_kinds('offered'))); ?>
    </section>

</div>

<?php
get_footer();
