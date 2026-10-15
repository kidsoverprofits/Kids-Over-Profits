<?php
/**
 * Dropdown lists: sorting kinds (website kinds, "Materials and links" kinds,
 * Drive Docs kinds, Fornits categories, closure stages, document types, bug
 * report categories). Built-in choices here; inc/choice-lists.php has the
 * rules. No WordPress needed in this file.
 */

if (!function_exists('kop_choice_register')) return;

kop_choice_register('website-kinds', array(
    'label' => 'Kinds of website',
    'group' => 'Sorting kinds',
    'where' => 'The "Kind of website" of a link in Websites Sent In (its edit box, the list\'s Kind column, the Review inbox card and "Add a website"). The browser extension and the app may send one of these too.',
    'slug_sep' => '_',
    'defaults' => array(
        'forum'      => 'Forum post or thread',
        'reddit'     => 'Reddit post or thread',
        'blog'       => 'Survivor blog or personal site',
        'social'     => 'Social media post',
        'program'    => 'Program\'s own page',
        'directory'  => 'Directory or listing',
        'marketing'  => 'Marketing group or referral service',
        'business'   => 'Business or property record',
        'staff'      => 'Staff profile or resume',
        'report'     => 'Report or study',
        'government' => 'Government page or record',
        'media'      => 'Video or podcast',
        'archive'    => 'Archived page',
        'other'      => 'Other',
    ),
));

kop_choice_register('resource-link-kinds', array(
    'label' => '"Materials and links" kinds',
    'group' => 'Sorting kinds',
    'where' => 'The headings a facility page groups its "Materials and links" under, in this order, and the Kind picked when a link is put on a record (Submissions Review, Review inbox "Move to", Drive Docs).',
    'defaults' => array(
        'licensing'   => 'Licensing and inspection reports',
        'court'       => 'Court records',
        'government'  => 'Government records',
        'news'        => 'News coverage',
        'social'      => 'Survivor posts and discussion',
        'people'      => 'Staff and owners',
        'advertising' => 'Advertising and marketing listings',
        'reference'   => 'Reference and research',
        'archive'     => 'Archived pages',
        'other'       => 'Other links',
    ),
));

kop_choice_register('drive-docs-kinds', array(
    'label' => 'Drive Docs link kinds',
    'group' => 'Sorting kinds',
    'where' => 'The Kind of each link at Drive Docs and its Review inbox queue (links from your Google Docs, HEAL, the wiki, SCIAD NET and the data audit). A kind decides where Add sends the link.',
    'note' => 'The build that finds these links gives them the built-in kinds. A kind added here is for sorting a link by hand on its card.',
    'fields' => array(
        'target' => array('label' => 'Add sends it to', 'type' => 'select', 'required' => true, 'default' => 'resource',
            'options' => function () {
                return function_exists('kop_gdl_targets') ? kop_gdl_targets() : array('resource' => 'Resource link on the facility page');
            }),
        'resource_kind' => array('label' => 'Listed under', 'type' => 'select', 'default' => 'other',
            'help' => 'The "Materials and links" heading it goes under when it is put on a facility page.',
            'options' => function () {
                return kop_choices('resource-link-kinds', null, 'offered');
            }),
    ),
    'defaults' => array(
        'news'         => array('label' => 'News article', 'target' => 'news', 'resource_kind' => 'news'),
        'court'        => array('label' => 'Court record', 'target' => 'lawsuit', 'resource_kind' => 'court'),
        'legislation'  => array('label' => 'Legislation', 'target' => 'legislation', 'resource_kind' => 'other'),
        'inspection'   => array('label' => 'Licensing/inspection report', 'target' => 'resource', 'resource_kind' => 'licensing'),
        'government'   => array('label' => 'Government page', 'target' => 'resource', 'resource_kind' => 'government'),
        'social'       => array('label' => 'Survivor/social post', 'target' => 'resource', 'resource_kind' => 'social'),
        'people'       => array('label' => 'Person (profile, obituary, company)', 'target' => 'resource', 'resource_kind' => 'people'),
        'advertising'  => array('label' => 'Advertising/marketing listing', 'target' => 'resource', 'resource_kind' => 'advertising'),
        'reference'    => array('label' => 'Reference', 'target' => 'resource', 'resource_kind' => 'reference'),
        'archive'      => array('label' => 'Archive copy', 'target' => 'resource', 'resource_kind' => 'archive'),
        'program_site' => array('label' => "Program's own site", 'target' => 'website', 'resource_kind' => 'other'),
        'other'        => array('label' => 'Other', 'target' => 'resource', 'resource_kind' => 'other'),
    ),
));

kop_choice_register('fornits-categories', array(
    'label' => 'Fornits thread categories',
    'group' => 'Sorting kinds',
    'where' => 'What a Fornits forum thread is about, as the AI reading tags it: the Category filter at Fornits and in the Review inbox, and the tags it suggests.',
    'note' => 'The AI is given these values (the grey names under each label) and tags new threads with them. A category added here is used for threads read from now on.',
    'defaults' => array(
        'survivor_account'      => 'Survivor account',
        'parent_account'        => 'Parent account',
        'staff_account'         => 'Former staff account',
        'abuse_allegation'      => 'Abuse',
        'sexual_abuse'          => 'Sexual abuse',
        'death'                 => 'Death',
        'restraint_seclusion'   => 'Restraint or seclusion',
        'medical_neglect'       => 'Medical neglect',
        'staff_names'           => 'Staff named',
        'ownership_business'    => 'Owners and business',
        'legal'                 => 'Lawsuit or criminal case',
        'investigation'         => 'Investigation or licensing',
        'closure_rename'        => 'Closure or new name',
        'news_coverage'         => 'News coverage',
        'program_methods'       => 'Program methods',
        'referral_marketing'    => 'Referrals and marketing',
        'parent_seeking_advice' => 'Parent asking for advice',
        'defending_program'     => 'Defending the program',
        'chatter'               => 'Chatter',
    ),
    'last' => array('chatter'),
));

kop_choice_register('closure-stages', array(
    'label' => 'Closure stages',
    'group' => 'Sorting kinds',
    'where' => 'What a news article says happened to a program, in the hourly closure scan (Review inbox > Closure reports). Confirming a report sets the facility\'s status to the stage\'s status.',
    'note' => 'The AI is told each stage\'s "Tell the AI" line and picks one per closure it finds.',
    'fields' => array(
        'status' => array('label' => 'Sets the status to', 'type' => 'select', 'required' => true, 'default' => 'Closed',
            'options' => array('Closed' => 'Closed', 'Suspended' => 'Suspended')),
        'ai_hint' => array('label' => 'Tell the AI', 'type' => 'textarea', 'required' => true,
            'help' => 'One sentence: when an article counts as this stage ("the article says ...").'),
    ),
    'defaults' => array(
        'closed' => array('label' => 'Closed', 'status' => 'Closed',
            'ai_hint' => 'the article says the program has closed, shut down, stopped operating or no longer houses youth. Includes closures in the past that the article states as fact.'),
        'closing' => array('label' => 'Announced closing', 'status' => 'Closed',
            'ai_hint' => 'the article says the program will close (announced, date set, winding down).'),
        'ordered_closed' => array('label' => 'Ordered closed', 'status' => 'Closed',
            'ai_hint' => 'a regulator revoked its license or ordered it to close, and the article does not say it has stopped operating (for example it is appealing).'),
        'suspended' => array('label' => 'Suspended', 'status' => 'Suspended',
            'ai_hint' => 'its license or admissions were suspended, or it paused operating.'),
    ),
));

kop_choice_register('bug-report-categories', array(
    'label' => 'Bug report categories',
    'group' => 'Sorting kinds',
    'where' => 'The "What kind of problem is it?" choice in the Report a problem button on every page, and the Category on Review inbox > Bug reports.',
    'slug_sep' => '-',
    'fields' => array(
        'public_label' => array('label' => 'What readers see', 'type' => 'text', 'required' => true,
            'help' => 'The wording in the Report a problem form. The label is what the Review inbox shows.'),
    ),
    'js' => true,
    'defaults' => array(
        'save-failed' => array('label' => 'Saving/submitting failed', 'public_label' => 'Saving or submitting didn’t work'),
        'load-failed' => array('label' => 'Something didn’t load', 'public_label' => 'Something didn’t load'),
        'broken-ui'   => array('label' => 'Broken UI', 'public_label' => 'Something looks broken or misbehaves'),
        'wrong-data'  => array('label' => 'Wrong/missing data', 'public_label' => 'Information is wrong or missing'),
        'other'       => array('label' => 'Other', 'public_label' => 'Something else'),
    ),
));

$kop_dt_g_prog = 'The programs\' own papers';
$kop_dt_g_over = 'Oversight';
$kop_dt_g_court = 'Courts';
$kop_dt_g_press = 'Press, research and survivors';
$kop_dt_g_biz = 'Business and industry';
$kop_dt_rows = array(
    'student-handbook' => array('Student or resident handbook', 'Student and resident handbooks', $kop_dt_g_prog,
        'Rulebooks, level systems and phase charts given to the young people in a program.'),
    'parent-handbook'  => array('Parent or family handbook', 'Parent and family handbooks', $kop_dt_g_prog,
        'Manuals, guides and booklets telling parents how the program works and what is expected of them.'),
    'staff-manual'     => array('Staff manual, training or policy', 'Staff manuals, training and policies', $kop_dt_g_prog,
        'Employee handbooks, training material and written policies for the people who worked there.'),
    'enrollment'       => array('Enrollment document', 'Enrollment documents', $kop_dt_g_prog,
        'Enrollment agreements, applications, intake forms, tuition contracts and consent forms.'),
    'marketing'        => array('Brochure or marketing', 'Brochures and marketing', $kop_dt_g_prog,
        'Brochures, viewbooks, flyers and other material written to sell a program to parents and referrers.'),
    'newsletter'       => array('Newsletter or press release', 'Newsletters and press releases', $kop_dt_g_prog,
        'A program\'s or company\'s own newsletters, statements and press releases.'),
    'website-capture'  => array('Website capture', 'Website captures', $kop_dt_g_prog,
        'Saved copies of program, company and directory web pages.'),
    'staff-ideology'   => array('Staff ideology', 'Staff ideology', $kop_dt_g_prog,
        'What a program\'s founders and staff believed and taught: philosophy and core values statements, treatment models, founders\' books, sermons and speeches.'),
    'inspection-report' => array('Inspection or licensing report', 'Inspection and licensing reports', $kop_dt_g_over,
        'State inspection, licensing, monitoring and complaint investigation reports.'),
    'incident-report'  => array('Police or incident report', 'Police and incident reports', $kop_dt_g_over,
        'Police reports, incident reports and serious occurrence reports.'),
    'government-report' => array('Government report or investigation', 'Government reports and investigations', $kop_dt_g_over,
        'Congressional, GAO, Inspector General and state agency reports, audits and investigations.'),
    'ngo-report'       => array('NGO or advocacy report', 'NGO and advocacy reports', $kop_dt_g_over,
        'Reports and investigations by nonprofits and watchdogs: disability rights (protection and advocacy) groups, human rights groups, youth law centers and survivor-led organizations.'),
    'legislation'      => array('Legislation or testimony', 'Legislation and testimony', $kop_dt_g_over,
        'Bills, statutes, regulations and testimony given to lawmakers.'),
    'legal-filing'     => array('Legal filing', 'Legal filings', $kop_dt_g_court,
        'Complaints, answers, motions, orders, rulings and settlements filed in court.'),
    'deposition'       => array('Deposition or discovery', 'Depositions and discovery', $kop_dt_g_court,
        'Deposition transcripts, declarations and documents produced in discovery.'),
    'news-clipping'    => array('News clipping', 'News clippings', $kop_dt_g_press,
        'Newspaper and magazine articles, saved as they were printed or published.'),
    'woodbury'         => array('Woodbury Reports page', 'Woodbury Reports pages', $kop_dt_g_press,
        'Pages of Woodbury Reports, the educational consultants\' trade newsletter.'),
    'research'         => array('Academic research', 'Academic research', $kop_dt_g_press,
        'Studies, theses, books and journal articles.'),
    'survivor-account' => array('Survivor account or testimony', 'Survivor accounts and testimony', $kop_dt_g_press,
        'Accounts written or given by people who were sent to programs, and their families.'),
    'business-record'  => array('Business or property record', 'Business and property records', $kop_dt_g_biz,
        'Corporate filings, business registrations, deeds, liens and property records.'),
    'tax-filing'       => array('Tax filing (Form 990)', 'Tax filings (Form 990)', $kop_dt_g_biz,
        'Nonprofit tax returns and other financial filings.'),
    'natsap-profile'   => array('NATSAP profile', 'NATSAP profiles', $kop_dt_g_biz,
        'A program\'s entry in the NATSAP directory, in the program\'s own words.'),
    'directory'        => array('Directory or member list', 'Directories and member lists', $kop_dt_g_biz,
        'Trade group member lists, referral directories and conference programs.'),
    'staff-profile'    => array('Staff profile or résumé', 'Staff profiles and résumés', $kop_dt_g_biz,
        'LinkedIn pages, résumés and staff biographies.'),
    'editor-note'      => array('Editor\'s note', 'Editor\'s notes', 'Kids Over Profits',
        'Kids Over Profits\' own notes: context, corrections and what our research found about a program, a person or a document.'),
    'other'            => array('Other', 'Other documents', 'Other',
        'Documents that fit none of the types above.'),
);
$kop_dt_defaults = array();
foreach ($kop_dt_rows as $kop_dt_slug => $kop_dt_t) {
    $kop_dt_defaults[$kop_dt_slug] = array('label' => $kop_dt_t[0], 'plural' => $kop_dt_t[1], 'group' => $kop_dt_t[2], 'desc' => $kop_dt_t[3]);
}

kop_choice_register('doc-types', array(
    'label' => 'Document types',
    'group' => 'Sorting kinds',
    'where' => 'What kind each media library document is: the chip on document tiles, the type filter on facility and company libraries, the /documents/ pages (one per type, grouped under its heading), Review inbox > Document types and the pencil on a tile.',
    'note' => 'The AI that types documents the rules cannot is given every type with its description, so a new type needs a clear one. A new type gets its own /documents/&lt;type&gt;/ page.',
    'slug_sep' => '-',
    'max_len' => 60,
    'fields' => array(
        'plural' => array('label' => 'Plural (page title)', 'type' => 'text', 'required' => true),
        'group' => array('label' => 'Heading on /documents/', 'type' => 'select', 'required' => true,
            'options' => function () {
                $out = array();
                foreach (kop_choices('doc-types') as $t) {
                    $g = (string) ($t['group'] ?? '');
                    if ($g !== '') $out[$g] = $g;
                }
                return $out;
            }),
        'desc' => array('label' => 'Description', 'type' => 'textarea', 'required' => true,
            'help' => 'One sentence on what belongs in this type. Shown on its /documents/ page and given to the AI.'),
    ),
    'defaults' => $kop_dt_defaults,
));
unset($kop_dt_g_prog, $kop_dt_g_over, $kop_dt_g_court, $kop_dt_g_press, $kop_dt_g_biz, $kop_dt_rows, $kop_dt_defaults, $kop_dt_slug, $kop_dt_t);
