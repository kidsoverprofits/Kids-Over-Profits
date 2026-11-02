<?php
/**
 * Research projects by others that go deeper than KOP on one company, shown
 * on that company's page and on the pages of the programs they cover: a
 * boxed note under the summary and a line in the "Also see" rail.
 *
 * Each project: name, url, by (how it is credited), about (the company page's
 * note), program_note (a program page's note), operators (company names as
 * their page names them) and pages ({facility page slug => the project's own
 * page for it, relative to url}). A slug of a related record (Christian Rehabs
 * for Adults...) works the same, since its page is the facility page.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_outside_research_projects() {
    return array(
        array(
            // Built partly on KOP open data (CC BY-SA); sent in by a reader 2026-11. The site says it is published anonymously.
            'name'         => 'Teen Challenge Research Map',
            'url'          => 'https://teenchallenge-network.netlify.app/',
            'by'           => 'an independent researcher',
            'about'        => 'An independent map of the corporations, programs, leaders, court cases and money behind Teen Challenge. It lists 247 programs and campuses for teenagers and adults under every name they have used, 76 lawsuits and agency actions, and the boards of the separate nonprofits that share the name. Each fact links to its source and is labelled as documented, reported, alleged or still a lead. Part of it is built on Kids Over Profits\' open data.',
            'program_note' => 'The Teen Challenge Research Map, an independent project, traces this program\'s corporation, past names, leaders and court records, each fact linked to its source.',
            'operators'    => array('Teen Challenge'),
            'pages'        => array(
                '180-ministries-for-girls-ut'           => '#/campus/SL-857',
                'bloom-home-for-girls-ma'               => '#/campus/SL-872',
                'boise-girls-academy-id'                => '#/campus/SL-643',
                'brush-creek-academy-ok'                => '#/campus/SL-674',
                'cape-fear-teen-challenge-nc'           => '#/campus/SL-633',
                'central-indiana-teen-challenge-in'     => '#/profile/refuge-girls-academy',
                'columbus-girls-academy-al'             => '#/profile/columbus-girls-academy',
                'columbus-teen-challenge-ga'            => '#/campus/SL-879',
                'eagle-springs-teen-boys-center-nv'     => '#/campus/SL-690',
                'eastern-appalachian-teen-challenge-va' => '#/campus/SL-692',
                'evangelhouse-christian-academy-la'     => '#/campus/SL-888',
                'gateway-boys-academy-fl'               => '#/profile/heartland-boys-academy-florida',
                'grace-house-teen-challenge-in'         => '#/campus/SL-705',
                'heart-of-america-teen-challenge-wy'    => '#/campus/SL-884',
                'heartland-boys-academy-ky'             => '#/profile/heartland-boys-academy-kentucky',
                'kansas-city-girls-academy-mo'          => '#/profile/kansas-city-girls-academy',
                'lakeland-girls-academy-fl'             => '#/profile/lakeland-girls-academy',
                'new-hope-boys-home-tx'                 => '#/campus/SL-734',
                'new-lifehouse-academy-ok'              => '#/profile/new-lifehouse-girls-academy',
                'north-carolina-boys-academy-nc'        => '#/campus/SL-736',
                'ozarks-teen-challenge-mo'              => '#/profile/ozarks-teen-challenge',
                'pine-mountain-boys-academy-ga'         => '#/profile/heartland-boys-academy-georgia',
                'ruths-haven-teen-girls-center-nv'      => '#/campus/SL-876',
                'teen-challenge-adventure-ranch-ar'     => '#/profile/teen-challenge-adventure-ranch',
                'teen-challenge-girls-nv'               => '#/campus/SL-893',
                'teen-challenge-of-south-carolina-sc'   => '#/campus/SL-782',
                'the-refuge-girls-academy-in'           => '#/profile/refuge-girls-academy',
                'thrive-girls-academy-tx'               => '#/campus/SL-629',
                'treasure-coast-boys-academy-fl'        => '#/campus/SL-864',
                'victory-house-teen-challenge-ks'       => '#/campus/SL-880',
            ),
        ),
    );
}

/** The projects about a company: [{name, url, by, note}]. */
function kop_outside_research_for_operator($name) {
    $out = array();
    foreach (kop_outside_research_projects() as $p) {
        if (in_array((string) $name, $p['operators'], true)) {
            $out[] = array('name' => $p['name'], 'url' => $p['url'], 'by' => $p['by'], 'note' => $p['about']);
        }
    }
    return $out;
}

/** The projects with a page on a program, by its page slug: [{name, url (its page there), by, note}]. */
function kop_outside_research_for_facility($slug) {
    $out = array();
    foreach (kop_outside_research_projects() as $p) {
        if (isset($p['pages'][(string) $slug])) {
            $out[] = array('name' => $p['name'], 'url' => $p['url'] . $p['pages'][(string) $slug], 'by' => $p['by'], 'note' => $p['program_note']);
        }
    }
    return $out;
}

/** The boxed note under a page's summary. */
function kop_outside_research_render(array $projects) {
    $html = '';
    foreach ($projects as $p) {
        $html .= '<aside class="kop-fp-outside-research" aria-label="Research by others">'
            . '<p class="kop-fp-outside-research-head">Further research: <a href="' . esc_url($p['url']) . '" target="_blank" rel="noopener">' . esc_html($p['name']) . '</a></p>'
            . '<p>' . esc_html($p['note']) . '</p>'
            . '</aside>';
    }
    return $html;
}
