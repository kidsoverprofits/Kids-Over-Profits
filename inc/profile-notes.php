<?php
/**
 * Research notes as prose (facility and company pages).
 *
 * The notes on a record ("Cost: about $7,100 per month (r/troubledteens wiki, page "Aspen Ranch" (as of
 * 2025-12-18))", "License: Licensed by the Idaho Department of Health and Welfare (Woodbury Reports, ...)",
 * "April 2003: bought six inpatient facilities ...") are read into sentences, each keeping its own source,
 * and grouped into paragraphs: what happened to the program and when (oldest first), what it was, then
 * its licences, accreditation and memberships. kop_pnotes_build() does the reading, kop_pnotes_html()
 * prints it; the pages call both (templates/facility-page.php, templates/operator-page.php), the mobile
 * app gets the built paragraphs as 'about' (inc/mobile-api.php).
 *
 * Nothing is reworded beyond the subject a fragment lacks ("It was", "It cost", "It was a member of").
 * Left out of the prose: the record's bookkeeping (where a record was added or corrected from: listed as
 * "Also drawn on" with links), lines the facts rail already prints with their source (type, ages,
 * capacity, start year, past names, former locations), and forum claims (Fornits), which the page lists
 * with the survivor testimony.
 *
 * Tested by scripts/test-profile-notes.php (rules on the owner's real lines, every record of the mirror).
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!function_exists('kop_pnotes_split_cite')) {
    /**
     * A note's words and the citation at its end: {text, cite, url}. Reads "(cite)", "(cite: url)",
     * "(cite) url", "(cite): url" and a bare trailing address; a closing parenthesis that is part of the
     * fact ("(Hidden Canyon Ranch)", "(as of 2010)", "(the record says 2005)") stays in the text.
     */
    function kop_pnotes_split_cite($line) {
        $text = trim((string) $line);
        // The import's bookkeeping, never part of the fact: "(sets status Closed, end year 2008)".
        $text = trim((string) preg_replace('/\s*\(sets (?:status|end year|start year)[^()]*\)/i', '', $text));
        $cite = '';
        $url = '';
        if (preg_match('/\s*\(((?:[^()]|\([^()]*\))*)\)\s*(?:(?::\s*|\s+)(https?:\/\/\S+))?\s*\.?\s*$/u', $text, $m)) {
            $inner = trim($m[1]);
            $after = isset($m[2]) ? $m[2] : '';
            $inner_url = '';
            if (preg_match('/:?\s*(https?:\/\/\S+)\s*$/', $inner, $u)) {
                $inner_url = $u[1];
                $inner = trim(substr($inner, 0, -strlen($u[0])));
            }
            if (kop_pnotes_is_cite($inner, $after !== '' || $inner_url !== '')) {
                $cite = $inner;
                $url = $after !== '' ? $after : $inner_url;
                $text = trim(substr($text, 0, -strlen($m[0])));
            }
        }
        if ($cite === '' && $url === '' && preg_match('/[\s:]+(https?:\/\/\S+)\s*$/', $text, $m)) {
            $url = $m[1];
            $text = trim(substr($text, 0, -strlen($m[0])), " \t,;:");
        }
        $url = rtrim($url, '.,;)');
        // A second citation written into the sentence before the linked one: "... (SEC proxy filing, January
        // 2017; APM Reports, 2022-04-26). (Outlet: https://...)". It is a source too, not words of the fact.
        $extra = array();
        if ($cite !== '' || $url !== '') {
            for ($n = 0; $n < 2 && preg_match('/\s*\(((?:[^()]|\([^()]*\))*)\)\s*\.?\s*$/u', $text, $m) && kop_pnotes_is_cite(trim($m[1]), false); $n++) {
                array_unshift($extra, trim($m[1]));
                $text = trim(substr($text, 0, -strlen($m[0])));
            }
        }
        return array('text' => $text, 'cite' => $cite, 'url' => preg_match('#^https?://#i', $url) ? $url : '', 'extra' => $extra);
    }
}

if (!function_exists('kop_pnotes_is_cite')) {
    /** Whether a closing "(...)" names where the note came from, not more of the fact. */
    function kop_pnotes_is_cite($inner, $has_url) {
        $inner = trim((string) $inner);
        if ($inner === '') return $has_url;
        if (preg_match('/^(?:the record says|as of|since|until|from|formerly|now|then|e\.g\.|i\.e\.|see|about|approx)/i', $inner)) return false;
        if ($has_url) return true;
        if (preg_match('/^(?:Woodbury Reports|HEAL\b|r\/troubledteens wiki|Fornits|Kids Over Profits|Form \d|Universal Health Services|Psychiatric Solutions)/i', $inner)) return true;
        // "Spark Academy website", "YSI 10-K FY1996", "PREA audit", "FL DJJ residential services report 2006-07".
        if (preg_match('/\b(?:10-K|10-Q|website|web site|Facebook(?: post)?|Instagram|LinkedIn|press release|report|audit|filing|newsletter|brochure|interview|obituary|SEC EDGAR)\b/i', $inner)) return true;
        // "Outlet, 2022-04-26" / "Idaho Press, March 2022": a name and a date.
        return (bool) preg_match('/,.*\b(?:1[89]|20)\d{2}\b/', $inner);
    }
}

if (!function_exists('kop_pnotes_kind')) {
    /**
     * What a note is: 'fact' (goes in the prose), 'also' (bookkeeping naming a source: listed as "Also
     * drawn on"), 'forum' (a forum claim, shown with the testimony), 'rail' (a fact the facts rail prints
     * with this source), 'skip' (the record's own housekeeping).
     */
    function kop_pnotes_kind($line) {
        $l = trim((string) $line);
        if ($l === '') return 'skip';
        if (preg_match('/^(?:Fornits lead|Reported closed on Fornits)\b/i', $l)) return 'forum';
        if (preg_match('/^(?:Added \d{4}-\d{2}-\d{2}\b.*?\bfrom\b|Corrected from outside sources)/i', $l)) return 'also';
        if (preg_match('/^migration:|facilities_master|__facility_ref|unique_name|\bthis is the record with\b|\brecord id\b/i', $l)) return 'skip';
        if (preg_match('/^(?:Type|Start year|Capacity|Serves|Ages|Gender|Gender as recorded|Past name|Former location|Past names):\s/i', $l)) return 'rail';
        if (preg_match('/^Ages \d/i', $l)) return 'rail';
        // "also known as X": the header prints every other name.
        if (preg_match('/^Note:\s*also known as\b/i', $l)) return 'rail';
        return 'fact';
    }
}

if (!function_exists('kop_pnotes_also')) {
    /**
     * A bookkeeping line's source as a link: {label, cite, url}. "Added 2026-10-02 by the news scan from:
     * HuffPost Canada, "Headline", 2020-11-22 https://..." -> "HuffPost Canada, "Headline""; a Woodbury Reports
     * issue reads "source" (its name, date and page are never printed, kop_facility_pages_woodbury_clean()).
     */
    function kop_pnotes_also($line) {
        $l = trim((string) $line);
        $l = (string) preg_replace('/^(?:Added \d{4}-\d{2}-\d{2}\b.*?\bfrom\b:?|Corrected from outside sources(?:\s*\([^()]*\))?:?)\s*/i', '', $l);
        $url = '';
        if (preg_match('/[\s:]+(https?:\/\/\S+)\s*$/', $l, $m)) {
            $url = rtrim($m[1], '.,;)');
            $l = trim(substr($l, 0, -strlen($m[0])), " \t,;:");
        }
        $cite = $l;
        $label = trim((string) preg_replace('/,?\s*\(?\d{4}-\d{2}(?:-\d{2})?\)?\s*$/', '', $l), " \t,;:");
        $label = trim((string) preg_replace('/\s*\((?:\d{4}-\d{2}(?:-\d{2})?)\)\s*"?$/', '"', $label));
        if (stripos($cite, 'Woodbury') !== false) {
            $label = 'source';
            $cite = '';
        }
        if ($label === '') $label = 'source';
        if ($url === '' && function_exists('kop_facility_pages_cite_url')) $url = kop_facility_pages_cite_url($l);
        return array('label' => $label, 'cite' => $cite, 'url' => $url);
    }
}

if (!function_exists('kop_pnotes_ucfirst')) {
    function kop_pnotes_ucfirst($s) {
        $s = (string) $s;
        return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1) : ucfirst($s);
    }
}

if (!function_exists('kop_pnotes_end')) {
    /** A sentence ends with a full stop (a trailing comma, semicolon or colon goes). */
    function kop_pnotes_end($s) {
        $s = rtrim(trim((string) $s), " \t,;:");
        if ($s === '') return '';
        $last = substr($s, -1);
        if (in_array($last, array('.', '!', '?'), true)) return $s;
        if (in_array($last, array('"', "'", ')'), true) && in_array(substr($s, -2, 1), array('.', '!', '?'), true)) return $s;
        return $s . '.';
    }
}

if (!function_exists('kop_pnotes_has_verb')) {
    /** Whether the words already make a sentence (a finite verb), so they are printed as they are. */
    function kop_pnotes_has_verb($s) {
        $plain = (string) preg_replace('/\([^()]*\)/', ' ', (string) $s);
        if (preg_match('/\b(?:is|was|were|are|has|had|have|will|would|did|does|became|becomes|began|took|left|ran|runs|sold|sells|bought|buys|grew|went|made|makes|said|says|told|found|kept|held|holds|paid|lost|won|met|led|leads|opened|closed|moved|revoked|ordered|appealed|filed|reported|graduated|owned|owns|lists|offers|employs|uses|serves|accepts|operates|describes|claims|states|renamed|rebranded|signed|agreed|joined|merged|acquired|purchased|completed|announced|hired|fired|replaced|assumed|expanded|consolidated|relocated|converted|reopened|shut|stopped|started|continued|continues|remains|remained|provides|provided|requires|required|charges|charged|admits|admitted|teaches|taught|houses|housed|includes|included)\b/i', $plain)) {
            return true;
        }
        return (bool) preg_match('/\b[a-z]{3,}ed\s+(?:by|to|in|on|at|into|with|from|for|the|a|an|its|their|his|her|\$|\d)/', $plain);
    }
}

if (!function_exists('kop_pnotes_article')) {
    /** "a" or "an" before a word, by its sound as far as the first letters show it. */
    function kop_pnotes_article($word) {
        $w = strtolower(ltrim((string) $word, '"\'('));
        if ($w === '') return 'a';
        if (preg_match('/^(?:hour|honest|honor|heir)/', $w)) return 'an';
        if (preg_match('/^(?:uni|use|usu|uti|eu|one|once|u\.s|us\b)/', $w)) return 'a';
        // Acronyms read letter by letter: "an NATSAP member", "a CARF".
        if (preg_match('/^[A-Z]{2,}/', ltrim((string) $word, '"\'('))) return preg_match('/^[aefhilmnorsx]/i', $w) ? 'an' : 'a';
        return preg_match('/^[aeiou]/', $w) ? 'an' : 'a';
    }
}

if (!function_exists('kop_pnotes_clause')) {
    /**
     * A fragment with no subject as a sentence about the program ("It ..."): "sold to X" -> "It was sold to
     * X", "employs 86 staff" -> "It employs 86 staff", "a division of UHS" -> "It was a division of UHS",
     * "member of NATSAP" -> "It was a member of NATSAP", "program integrated into X" -> "The program
     * integrated into X". Words that already make a sentence come back capitalised; '' when the fragment
     * is none of these (the caller decides).
     */
    function kop_pnotes_clause($s) {
        $s = trim((string) $s);
        if ($s === '') return '';
        $first = strtok($s, ' ');
        $lower = strtolower($first);
        $starts_lower = (bool) preg_match('/^[a-z]/', $s);
        $passive = 'known|renamed|called|named|licensed|accredited|approved|owned|operated|run|founded|acquired|purchased|sold|bought|constructed|designated|certified|registered|located|based|built|listed|described|marketed|managed|taken|transferred|integrated|absorbed|incorporated|established|staffed|investigated|cited|affiliated|associated|contracted|funded|endorsed|recognized|headquartered|opened|closed|reopened|shut|converted|placed|sued|fined|raided|ordered|modeled|modelled|patterned|unlicensed|unaccredited|suspended|temporarily closed|considered|regarded|seen|billed|branded|rebranded';
        $status = 'licensed|accredited|approved|designated|certified|registered|recognized|endorsed';
        $adverb = '(?:regionally|fully|nationally|provisionally|formerly|later|previously|originally|also|once|briefly|jointly|temporarily|permanently)\s+';
        // "Accepts court-ordered youth", "Received SEVIS certification", "Will offer ...": a sentence missing its subject.
        if (!$starts_lower && preg_match('/^(?:Accepts|Admits|Left|Uses|Offers|Employs|Serves|Operates|Provides|Requires|Charges|Received|Featured|Operating|Will|Launched|Includes|Runs|Holds|Describes|Markets|Specializes|Focuses|Treats|Enrolls|Houses|Partnered|Became|Added|Hired|Began|Expanded|Plans|Planned|Moved|Merged|Joined)\b/', $s)) {
            $s = strtolower(substr($s, 0, 1)) . substr($s, 1);
            $starts_lower = true;
        }
        // "Licensed by ..." / "Owned by ..." / "Incorporated in Delaware ...": the same, lower case.
        if (!$starts_lower && preg_match('/^(?:' . $adverb . ')?(?:' . $passive . ')\b/i', $s)) {
            $s = strtolower(substr($s, 0, 1)) . substr($s, 1);
            $starts_lower = true;
        }
        if (!$starts_lower) return kop_pnotes_has_verb($s) ? kop_pnotes_end($s) : '';
        $first = strtok($s, ' ');
        $lower = strtolower($first);
        // "approved Idaho Special Education Facility" -> "It was an approved Idaho Special Education Facility".
        // "licensed and accredited specialty psychiatric hospital" -> "It was a licensed and accredited specialty ...".
        $before = '(?!by\b|as\b|in\b|for\b|to\b|under\b|with\b|through\b|until\b|since\b|from\b|at\b|on\b|of\b|and\b|or\b|but\b|until\b|only\b)';
        if (preg_match('/^(' . $adverb . ')?(' . $status . ')\s+(?:and|&)\s+(?:' . $status . ')\s+' . $before . '[A-Za-z0-9"]/', $s)
            || preg_match('/^(' . $adverb . ')?(' . $status . ')\s+' . $before . '([A-Z0-9"])/', $s)) {
            return kop_pnotes_end('It was ' . kop_pnotes_article($s) . ' ' . $s);
        }
        // "closed in 2008", "opened in 1998": the program did it.
        // "closed in 2008" (it did) vs "closed January 2014 by Youth Services International" (it was).
        if (preg_match('/^(?:closed|opened|reopened|shut down|moved)\b(?!\s+by\b)/', $s) && !preg_match('/^\w+(?:\s+down)?\s+[^.;]{0,30}\bby\s+[A-Z]/', $s)) return kop_pnotes_end('It ' . $s);
        if (preg_match('/^(' . $adverb . ')?(?:' . $passive . ')\b/', $s)) {
            // "incorporated a curriculum" (it did) vs "incorporated in Delaware" (it was).
            if (preg_match('/^(?:' . $adverb . ')?(?:' . $passive . ')\s+(?:a|an|the|its|their|his|her|several|two|three|four|five|six|new|more)\b/', $s)
                && !preg_match('/^(?:' . $adverb . ')?(?:renamed|named|called|known|designated|considered|regarded|seen|billed|branded)\b/', $s)) {
                return kop_pnotes_end('It ' . $s);
            }
            // "bought Ardent Behavioral Health": a verb that takes a company or place as its object.
            if (preg_match('/^(?:' . $adverb . ')?(?:bought|purchased|acquired|sold|built|constructed|founded|opened|closed|operated|managed|absorbed|converted)\s+[A-Z]/', $s)) {
                return kop_pnotes_end('It ' . $s);
            }
            return kop_pnotes_end('It was ' . $s);
        }
        if (preg_match('/^(?:previously|formerly|once|originally|later)\s+(?:the|a|an|known|called|named|[A-Z])/', $s)) return kop_pnotes_end('It was ' . $s);
        if (preg_match('/^now\s+[a-z]+ing\b/', $s)) return kop_pnotes_end('It is ' . $s);
        if (preg_match('/^(?:now|also|still|later|then|soon|recently|currently)\s+[a-z]+/', $s)) return kop_pnotes_end('It ' . $s);
        if (preg_match('/^[a-z]+ing\b/', $s) && !preg_match('/^(?:boarding|housing|training|building|clothing|funding|nothing|something|evening|morning|ceiling|wedding|bedding|spring|string|thing|king|ring|wing|sibling|offspring|during|remaining|existing|incoming|outgoing|ongoing|following|upcoming)\b/', $s)) {
            return kop_pnotes_end('It was ' . $s);
        }
        if (preg_match('/^to\s+[a-z]+/', $s)) return kop_pnotes_end('It was set ' . $s);
        if (preg_match('/^(?:a|an)\s/', $s)) return kop_pnotes_has_verb(substr($s, 2)) ? kop_pnotes_end(kop_pnotes_ucfirst($s)) : kop_pnotes_end('It was ' . $s);
        if (preg_match('/^(?:member|division|subsidiary|branch|affiliate|rebrand|spin-off|offshoot|unit|sister program|sister school|sister facility|chapter)\b/', $s)) {
            return kop_pnotes_end('It was ' . kop_pnotes_article($s) . ' ' . $s);
        }
        if (preg_match('/^part of\b/', $s)) return kop_pnotes_end('It was ' . $s);
        if (preg_match('/^(?:program|school|campus|facility|building|ranch|academy|center|centre|home|girls\'|boys\'|minimum|maximum|average|typical|organization|company|owner|founder|director|curriculum)\b/', $s)) {
            // "program integrated into X" -> "The program was integrated into X".
            if (preg_match('/^(\S+)\s+(?:' . $passive . ')\b/', $s, $pm) && !preg_match('/^\S+\s+\S+\s+(?:a|an|the|its)\b/', $s)) {
                return kop_pnotes_end('The ' . $pm[1] . ' was ' . substr($s, strlen($pm[1]) + 1));
            }
            return kop_pnotes_end('The ' . $s);
        }
        // An active verb of its own ("employs 86 faculty", "partnered with", "uses a rolling admissions process").
        if (preg_match('/^[a-z]+(?:ed|es|s)\b/', $first) && !preg_match('/^(?:students|staff|owners|parents|girls|boys|youths|kids|teens|cottages|campuses|programs|schools|homes|levels|months|weeks|days|years|classes|rules|beds|acres|sessions|services|fees|costs)$/', $lower)) {
            return kop_pnotes_end('It ' . $s);
        }
        if (in_array($lower, array('has', 'had', 'is', 'was', 'serves', 'offers', 'uses', 'runs', 'holds', 'keeps', 'sits'), true)) return kop_pnotes_end('It ' . $s);
        return kop_pnotes_has_verb($s) ? kop_pnotes_end(kop_pnotes_ucfirst($s)) : '';
    }
}

if (!function_exists('kop_pnotes_sentence')) {
    /**
     * One note's words as a sentence: {text, topic}, topic 'history' (when and what happened, with the
     * year to order it by), 'program' (what it was like) or 'oversight' (licences, accreditation,
     * memberships). $label is the note's "Label:" ('' for none), $body the words after it.
     */
    function kop_pnotes_sentence($label, $body) {
        $l = strtolower(trim((string) $label));
        $b = trim((string) $body);
        if ($b === '') return null;
        $topic_of = array(
            'opened' => 'history', 'founded' => 'history', 'founded by' => 'history', 'closed' => 'history', 'acquired' => 'history',
            'merger' => 'history', 'name change' => 'history', 'move' => 'history', 'owner' => 'history', 'owners' => 'history',
            'ownership' => 'history', 'operator' => 'history', 'operator/owner' => 'history', 'sister facility' => 'history',
            'operations suspended' => 'history', 'closed per news report' => 'history', 'sale' => 'history', 'history' => 'history',
            'license' => 'oversight', 'licence' => 'oversight', 'licensing' => 'oversight', 'accreditation' => 'oversight',
            'membership' => 'oversight', 'memberships' => 'oversight', 'certification' => 'oversight', 'certifications' => 'oversight',
        );
        $topic = $topic_of[$l] ?? '';
        $text = '';
        switch ($l) {
            case 'opened':
                // "2006 per Woodbury Reports, February 2006, p. 22 (the record says 2005)": the sources disagree.
                if (preg_match('/^((?:1[89]|20)\d{2})\b(?:\s+per\s+[^(]*)?(?:\(the record says ((?:1[89]|20)\d{2})\))?/i', $b, $m)) {
                    $text = !empty($m[2]) && $m[2] !== $m[1]
                        ? 'One account dates its opening to ' . $m[1] . '; other records give ' . $m[2] . '.'
                        : 'It opened in ' . $m[1] . '.';
                } else {
                    $text = kop_pnotes_clause('opened ' . $b);
                }
                break;
            case 'founded by':
                $text = kop_pnotes_end('It was founded by ' . $b);
                break;
            case 'owner':
            case 'owners':
                $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end('It was owned by ' . $b);
                break;
            case 'operator':
            case 'operator/owner':
                if (preg_match('/^parent company:\s*(.+)$/i', $b, $m)) {
                    $text = kop_pnotes_end('Its parent company was ' . $m[1]);
                } elseif (preg_match('/^(.+?)-affiliated$/i', $b, $m)) {
                    $text = kop_pnotes_end('It was affiliated with ' . $m[1]);
                } else {
                    $text = kop_pnotes_clause($b);
                    if ($text === '') $text = kop_pnotes_end('It was run by ' . $b);
                }
                break;
            case 'sister facility':
                $text = kop_pnotes_end('Its sister facility was ' . rtrim($b, '.'));
                break;
            case 'move':
                $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end('It was located in ' . $b);
                break;
            case 'name change':
                $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end('It was renamed ' . $b);
                break;
            case 'closed per news report':
                // The body is the article: the sentence is the closing, the article its source (the caller).
                $text = 'A news report says it closed.';
                break;
            case 'license':
            case 'licence':
            case 'licensing':
                if (preg_match('/^state[- ]licensed$/i', $b)) {
                    $text = 'It was licensed by the state.';
                } else {
                    $text = kop_pnotes_clause($b);
                    if ($text === '') $text = kop_pnotes_end('Licensing: ' . $b);
                }
                break;
            case 'accreditation':
                $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end('It was accredited by ' . $b);
                break;
            case 'membership':
            case 'memberships':
                if (preg_match('/^(.+?)\s+member\s+since\s+((?:1[89]|20)\d{2})$/i', $b, $m)) {
                    $text = 'It joined ' . $m[1] . ' in ' . $m[2] . '.';
                } elseif (preg_match('/^(.+?)\s+(?:member\s+)?((?:1[89]|20)\d{2})\s*(?:-|to|\x{2013})\s*((?:1[89]|20)\d{2})$/iu', $b, $m)) {
                    $text = 'It was a member of ' . $m[1] . ' from ' . $m[2] . ' to ' . $m[3] . '.';
                } elseif (preg_match('/^((?:[A-Z][a-z]+\s+)?)member of\s+(.+)$/i', $b, $m)) {
                    // "Institutional Member of the Association of Recovery Schools" -> "It was an institutional member of ...".
                    $kind = strtolower(trim($m[1]));
                    $text = kop_pnotes_end('It was ' . kop_pnotes_article($kind !== '' ? $kind : 'member') . ' ' . ($kind !== '' ? $kind . ' ' : '') . 'member of ' . $m[2]);
                } else {
                    $text = kop_pnotes_clause($b);
                    if ($text === '') $text = kop_pnotes_end('It was a member of ' . preg_replace('/\s+members?$/i', '', $b));
                }
                break;
            case 'described as':
                if (preg_match('/^(?:describes|described|calls|called|markets|marketed)\b/i', $b)) {
                    $text = kop_pnotes_clause(strtolower(substr($b, 0, 1)) . substr($b, 1));
                } elseif (kop_pnotes_has_verb($b) && !preg_match('/^[a-z]/', $b)) {
                    $text = kop_pnotes_end($b);
                } else {
                    // "Private school with therapy" -> "It was described as a private school with therapy".
                    $w = strtok($b, ' ');
                    $proper = preg_match('/^(?:Christian|Catholic|Baptist|Mormon|LDS|Native|American|English|Spanish|Jewish|Mexican|Canadian|Emotional Growth)\b/', $b);
                    $phrase = (!$proper && preg_match('/^[A-Z][a-z]+$/', $w)) ? strtolower(substr($b, 0, 1)) . substr($b, 1) : $b;
                    $text = preg_match('/^(?:a|an|the)\s/i', $phrase) || preg_match('/s\b/', $w) && !preg_match('/ss$/', $w)
                        ? kop_pnotes_end('It was described as ' . $phrase)
                        : kop_pnotes_end('It was described as ' . kop_pnotes_article($phrase) . ' ' . $phrase);
                }
                $topic = 'program';
                break;
            case 'length of stay':
                if (preg_match('/^typical length of stay\s+(?:is|was)?\s*/i', $b, $m)) {
                    $text = kop_pnotes_end('The typical stay was ' . substr($b, strlen($m[0])));
                } elseif (preg_match('/^(average|minimum|maximum|typical)\s+(?:of\s+)?(?=[\d$a-z])/i', $b, $m) && !kop_pnotes_has_verb($b)) {
                    $text = kop_pnotes_end('The ' . strtolower($m[1]) . ' stay was ' . substr($b, strlen($m[0])));
                } elseif (kop_pnotes_has_verb($b)) {
                    $text = kop_pnotes_clause($b);
                } elseif (preg_match('/\bprogram\b/i', $b)) {
                    $text = kop_pnotes_end('It was ' . kop_pnotes_article($b) . ' ' . $b);
                } else {
                    $text = kop_pnotes_end('Stays lasted ' . $b);
                }
                $topic = 'program';
                break;
            case 'cost':
                if (preg_match('/^\$[\d,.]+\s+(?:[a-z-]+\s+){0,3}(?:fee|deposit|payment|charge|surcharge)\b/i', $b)) {
                    $text = kop_pnotes_end('It charged a ' . $b);
                } elseif (preg_match('/^(?:initial|first|enrollment|total)\s+(?:payment|cost|fee)s?\s+(?:of|was|is)?\s*/i', $b, $m)) {
                    $text = kop_pnotes_end('The ' . strtolower(trim(preg_replace('/\s+(?:of|was|is)\s*$/i', '', $m[0]))) . ' was ' . substr($b, strlen($m[0])));
                } elseif (preg_match('/^(?:\$|about|around|roughly|approximately|upwards|over|more than|nearly|almost|up to|between|from)\b|^\$/i', $b)) {
                    $text = kop_pnotes_end('It cost ' . $b);
                } else {
                    $text = kop_pnotes_clause($b);
                    if ($text === '') $text = kop_pnotes_end('Cost: ' . $b);
                }
                $topic = 'program';
                break;
            case 'campus':
                $text = kop_pnotes_clause($b);
                if ($text === '') {
                    $text = preg_match('/^(?:\d|two|three|four|five|six|several|multiple)\b/i', $b)
                        ? kop_pnotes_end('It had ' . $b)
                        : kop_pnotes_end('It had ' . kop_pnotes_article($b) . ' ' . $b);
                }
                $topic = 'program';
                break;
            case 'address':
                if (preg_match('/\bacres?\b/i', $b)) {
                    $text = kop_pnotes_end('It sat on ' . $b);
                } else {
                    $text = preg_match('/^(?:\d|P\.?\s?O\.?\s+Box)/i', $b) ? kop_pnotes_end('Its address was ' . $b) : (kop_pnotes_clause($b) ?: kop_pnotes_end('It was located in ' . $b));
                }
                $topic = 'program';
                break;
            case 'news':
                $text = $b;
                $topic = 'history';
                break;
            case 'note':
            case '':
                // HEAL's staff lines: "Cheryl Sudweeks, Owner/operator, Owner (2005-2026, HEAL)" -> "Cheryl Sudweeks
                // was its owner/operator from 2005 to 2026."; further roles follow ("; ran the facility (per survivors)").
                if (preg_match('/^([A-Z][A-Za-z.\'-]+(?:\s[A-Z][A-Za-z.\'-]+){1,3}),\s+(.+?)\s*\(((?:19|20)\d{2})(?:\s*-\s*((?:19|20)\d{2}))?,\s*HEAL\)$/', $b, $pm)) {
                    $case = static function ($r) {
                        // "Owner/operator" -> "owner/operator", "CEO" stays.
                        return preg_replace_callback('/\b([A-Z])([a-z]+)\b/', static function ($w) { return strtolower($w[1]) . $w[2]; }, trim($r));
                    };
                    $roles = array();
                    foreach (preg_split('/,\s+(?![^()]*\))/', $pm[2]) as $r) {
                        $r = $case($r);
                        if ($r !== '' && !in_array(strtolower($r), array_map('strtolower', $roles), true) && !preg_match('#^' . preg_quote(strtolower($r), '#') . '\b#', strtolower(implode(' ', $roles)))) $roles[] = $r;
                    }
                    $first = (string) array_shift($roles);
                    $years = !empty($pm[4]) && $pm[4] !== $pm[3] ? ' from ' . $pm[3] . ' to ' . $pm[4] : ' in ' . $pm[3];
                    $lead = preg_match('/^board\b/i', $first) ? 'was on its ' . $first : 'was its ' . $first;
                    $text = $pm[1] . ' ' . $lead . ($roles ? '; ' . implode('; ', $roles) . ',' : '') . $years . '.';
                    $topic = 'history';
                    break;
                }
                $text = kop_pnotes_dated($b);
                if ($text === '') $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end(kop_pnotes_ucfirst($b));
                break;
            default:
                $text = kop_pnotes_dated($b);
                if ($text === '') $text = kop_pnotes_clause($b);
                if ($text === '') $text = kop_pnotes_end(kop_pnotes_ucfirst($label) . ': ' . $b);
        }
        if ($text === '') return null;
        if ($topic === '') $topic = kop_pnotes_topic_of_text($text);
        return array('text' => $text, 'topic' => $topic);
    }
}

if (!function_exists('kop_pnotes_dated')) {
    /**
     * "April 2003: bought six inpatient facilities" -> "In April 2003 it bought six inpatient facilities.";
     * "July 1, 2005: bought ..." -> "On July 1, 2005 it bought ..."; "November 2010: acquired by UHS" ->
     * "In November 2010 it was acquired by UHS." '' when the words do not start with a date and a colon.
     */
    function kop_pnotes_dated($s) {
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';
        if (!preg_match('/^((?:(?:' . $months . ')\s+(?:\d{1,2},\s+)?)?(?:1[89]|20)\d{2})\s*:\s*(\S.*)$/u', trim((string) $s), $m)) return '';
        $when = $m[1];
        $rest = $m[2];
        $lead = preg_match('/\d{1,2},/', $when) ? 'On ' . $when : 'In ' . $when;
        if (preg_match('/^[a-z]/', $rest)) {
            $clause = kop_pnotes_clause($rest);
            if ($clause !== '' && preg_match('/^It\s/', $clause)) return $lead . ' it' . substr($clause, 2);
            if ($clause !== '' && preg_match('/^The\s/', $clause)) return $lead . ' the' . substr($clause, 3);
            return kop_pnotes_end($lead . ', ' . $rest);
        }
        return kop_pnotes_end($lead . ', ' . $rest);
    }
}

if (!function_exists('kop_pnotes_topic_of_text')) {
    /** A sentence with no label: its topic by its words. */
    function kop_pnotes_topic_of_text($text) {
        $t = (string) $text;
        // "In April 2003 it bought ... 895 licensed beds": dated, so history whatever it mentions.
        if (preg_match('/^(?:In|On)\s+(?:[A-Z][a-z]+\s+)?(?:\d{1,2},\s+)?(?:1[89]|20)\d{2}\b/', $t)) return 'history';
        if (preg_match('/\b(?:licen[sc]e[ds]?|licensure|accredit\w*|NATSAP|member of|joined|certified|certification|Joint Commission|CARF|Cognia|AdvancED)\b/i', $t)) return 'oversight';
        if (preg_match('/\b(?:opened|closed|founded|bought|sold|acquired|purchased|renamed|rebranded|moved|merged|became|incorporated|took over|revoked|ceased|shut|reopened|sale|owner|owned|operated by|run by|parent company)\b/i', $t)
            || preg_match('/^(?:In|On)\s+(?:[A-Z][a-z]+\s+)?(?:\d{1,2},\s+)?(?:1[89]|20)\d{2}\b/', $t)) return 'history';
        return 'program';
    }
}

if (!function_exists('kop_pnotes_year')) {
    /** The year a history sentence is about (its first year), else the year of its source, else 0. */
    function kop_pnotes_year($text, $cite) {
        if (preg_match('/\b(1[89]\d{2}|20\d{2})\b/', (string) $text, $m)) return (int) $m[1];
        if (preg_match('/\b(1[89]\d{2}|20\d{2})\b/', (string) $cite, $m)) return (int) $m[1];
        return 0;
    }
}

if (!function_exists('kop_pnotes_words')) {
    function kop_pnotes_words($t) {
        static $stop = null;
        if ($stop === null) $stop = array_flip(explode(' ', 'that this with from after into their were have been about which while there where when what they them also than then only more most some such over under its it was the and for are has had per'));
        preg_match_all('/[a-z0-9$]{3,}/', strtolower((string) $t), $m);
        // "restraints" and "restraint" are one word here.
        $w = array_map(static function ($x) { return strlen($x) > 4 ? (string) preg_replace('/(?<!s)s$/', '', $x) : $x; }, $m[0]);
        return array_diff_key(array_flip($w), $stop);
    }
}

if (!function_exists('kop_pnotes_build')) {
    /**
     * Notes (strings) as {paragraphs: [[{text, topic, sources: [{source, cite, url}]}]], also: [{label,
     * cite, url}], forum: [lines], sentences: n}. The same fact from two sources is one sentence citing
     * both; history is ordered by year. $opts['max'] = sentences per paragraph before it is cut (6).
     */
    function kop_pnotes_build(array $notes, array $opts = array()) {
        $max = max(3, (int) ($opts['max'] ?? 6));
        $sentences = array();
        $also = array();
        $forum = array();
        $seen_also = array();
        // A note written over several lines ("Memberships: NATSAP (cite)\nNational Association of ...") is one
        // line each; a line with no label or source of its own takes the line before's.
        $lines = array();
        foreach (array_values($notes) as $line) {
            if (!is_string($line)) continue;
            $parts = array_values(array_filter(array_map('trim', preg_split('/\R/', $line)), 'strlen'));
            $label0 = '';
            $cite0 = '';
            foreach ($parts as $k => $p) {
                if ($k === 0) {
                    if (preg_match('/^([A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}):\s+/', $p, $lm)) $label0 = $lm[1];
                    $c = kop_pnotes_split_cite($p);
                    if ($c['cite'] !== '') $cite0 = ' (' . $c['cite'] . ($c['url'] !== '' ? ': ' . $c['url'] : '') . ')';
                    $lines[] = $p;
                    continue;
                }
                $c = kop_pnotes_split_cite($p);
                $has_label = (bool) preg_match('/^[A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}:\s+/', $p);
                $lines[] = ($has_label || $label0 === '' ? '' : $label0 . ': ') . $p . ($c['cite'] === '' && $c['url'] === '' ? $cite0 : '');
            }
        }
        // A citation written once with its address and elsewhere without ("(HEAL, Aspen Ranch Exposed! (archived
        // 2015-08-25))" on one line, the same with ": https://web.archive.org/..." on another) links everywhere.
        $known = array();
        foreach ($lines as $line) {
            $c = kop_pnotes_split_cite(preg_replace('/^[A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}:\s+/', '', $line));
            if ($c['cite'] !== '' && $c['url'] !== '' && !isset($known[$c['cite']])) $known[$c['cite']] = $c['url'];
        }
        foreach ((array) ($opts['known'] ?? array()) as $kc => $ku) if (!isset($known[$kc]) && is_string($ku) && $ku !== '') $known[$kc] = $ku;
        foreach ($lines as $i => $line) {
            $kind = kop_pnotes_kind($line);
            if ($kind === 'forum') { $forum[] = $line; continue; }
            if ($kind === 'skip' || $kind === 'rail') continue;
            if ($kind === 'also') {
                $a = kop_pnotes_also($line);
                $key = $a['url'] !== '' ? strtolower($a['url']) : strtolower($a['label']);
                if ($a['url'] === '') continue;
                if (!isset($seen_also[$key])) { $seen_also[$key] = true; $also[] = $a; }
                continue;
            }
            $label = '';
            $body = $line;
            if (preg_match('/^([A-Z][A-Za-z\/]*(?:\s[A-Za-z\/]+){0,3}):\s+(?=\S)/', $line, $m) && !preg_match('/^(?:' . 'January|February|March|April|May|June|July|August|September|October|November|December' . ')\b/', $m[1])) {
                $label = $m[1];
                $body = substr($line, strlen($m[0]));
            }
            $cut = kop_pnotes_split_cite($body);
            $cite = $cut['cite'];
            $url = $cut['url'];
            // "Woodbury Reports (Feb. 2008) reports Chris Ankney appointed ...": the newsletter is the source link,
            // never words on the page (kop_facility_pages_woodbury_clean()).
            $words = (string) preg_replace('/^Woodbury Reports(?:\s*\([^()]*\))?,?\s+(?:reports|reported|says|said|notes|noted|wrote|writes|announced|announces)(?:\s+that)?\s+/i', '', $cut['text']);
            if ($words !== $cut['text']) $words = kop_pnotes_ucfirst($words);
            if (strtolower($label) === 'closed per news report') {
                // The note is the article itself: "The Imprint, "Headline", 2021-10-25 https://...".
                $cite = trim((string) preg_replace('/,?\s*\d{4}-\d{2}-\d{2}\s*$/', '', $words));
                $words = 'closed';
            }
            // "Closed (2025-02-14) per news report: KUTV, "Headline", 2025-02-14": the article is the source.
            if ($label === '' && preg_match('/^(Closed|Suspended|Reopened|Opened)\s*\(((?:19|20)\d{2})-(\d{2})-\d{2}\)\s*per news report:?\s*(.*)$/i', $line, $nm)) {
                $months = array('', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
                $art = kop_pnotes_split_cite($nm[4]);
                $cite = trim((string) preg_replace('/,?\s*\d{4}-\d{2}-\d{2}\s*$/', '', $art['text'] !== '' ? $art['text'] : $nm[4]));
                $url = $art['url'];
                $what = array('closed' => 'it closed', 'suspended' => 'its licence was suspended', 'reopened' => 'it reopened', 'opened' => 'it opened')[strtolower($nm[1])];
                $words = 'A news report says ' . $what . ' in ' . ($months[(int) $nm[3]] ?? '') . ' ' . $nm[2] . '.';
                $label = 'News';
            }
            if (strtolower($label) === 'opened' && $cite === '' && preg_match('/\bper\s+([^(]+?)\s*(?:\(the record says|$)/i', $words, $pm)) {
                $cite = trim($pm[1], " ,");
            }
            $s = kop_pnotes_sentence($label, $words);
            if (!$s) continue;
            // "(Homepage, Wayback capture (2021-09-27); KOP copy: <our PDF>, 2021-09-27: <archive>)": the archive and
            // our copy of it are both sources (CLAUDE.md: cite our copy beside the archive link).
            $copy = '';
            if (preg_match('/;?\s*KOP copy:\s*(https?:\/\/[^\s,]+),?/i', $cite, $cm)) {
                $copy = rtrim($cm[1], '.;)');
                $cite = trim((string) preg_replace('/\s+/', ' ', str_replace($cm[0], '', $cite)), " ,;");
            }
            $make = static function ($cite, $url) use ($known) {
                if ($url === '' && isset($known[$cite])) $url = $known[$cite];
                if ($url === '' && $cite !== '' && function_exists('kop_facility_pages_cite_url')) $url = kop_facility_pages_cite_url($cite);
                if ($cite === '' && $url === '') return null;
                $src = array('source' => function_exists('kop_facility_pages_source_label') ? kop_facility_pages_source_label($cite !== '' ? $cite : (string) wp_parse_url($url, PHP_URL_HOST)) : $cite, 'cite' => $cite, 'url' => $url);
                return function_exists('kop_facility_pages_is_own_source') && kop_facility_pages_is_own_source($src) ? null : $src;
            };
            $srcs = array();
            foreach (array(array($cite, $url), $copy !== '' ? array($cite . ' (our copy)', $copy) : null) as $pair) {
                if ($pair && ($one = $make($pair[0], $pair[1]))) $srcs[] = $one;
            }
            foreach ((array) ($cut['extra'] ?? array()) as $x) {
                foreach (preg_split('/;\s+/', $x) as $xc) if (($one = $make(trim($xc), ''))) $srcs[] = $one;
            }
            $sentences[] = array(
                'text' => $s['text'], 'topic' => $s['topic'], 'sources' => $srcs,
                'year' => $s['topic'] === 'history' ? kop_pnotes_year($s['text'], $cite) : 0, 'i' => $i,
                'words' => kop_pnotes_words($s['text']),
            );
        }

        // One fact, two sources: the same words, or nearly (the shorter's words all but one in the longer).
        $kept = array();
        foreach ($sentences as $s) {
            $dupe = null;
            foreach ($kept as $k => $o) {
                if (strtolower($o['text']) === strtolower($s['text'])) { $dupe = $k; break; }
                $a = count($s['words']);
                $b = count($o['words']);
                $shared = count(array_intersect_key($s['words'], $o['words']));
                if (min($a, $b) >= 4 && $shared >= min($a, $b) - (min($a, $b) >= 5 ? 1 : 0)) { $dupe = $k; break; }
            }
            if ($dupe === null) { $kept[] = $s; continue; }
            $o = $kept[$dupe];
            if (mb_strlen($s['text']) > mb_strlen($o['text'])) { $o['text'] = $s['text']; $o['words'] = $s['words']; }
            foreach ($s['sources'] as $src) {
                $have = false;
                foreach ($o['sources'] as $os) if ($os['cite'] === $src['cite'] && $os['url'] === $src['url']) $have = true;
                if (!$have) $o['sources'][] = $src;
            }
            $kept[$dupe] = $o;
        }

        $by = array('history' => array(), 'program' => array(), 'oversight' => array());
        foreach ($kept as $s) $by[$s['topic']][] = $s;
        // Oldest first; a sentence with no year keeps its place among those around it.
        usort($by['history'], static function ($a, $b) {
            if ($a['year'] && $b['year'] && $a['year'] !== $b['year']) return $a['year'] <=> $b['year'];
            return $a['i'] <=> $b['i'];
        });
        $paragraphs = array();
        foreach ($by as $topic => $list) {
            if (!$list) continue;
            $list = array_map(static function ($s) { unset($s['words'], $s['i']); return $s; }, $list);
            $n = (int) ceil(count($list) / $max);
            $size = (int) ceil(count($list) / $n);
            foreach (array_chunk($list, $size) as $chunk) $paragraphs[] = $chunk;
        }
        return array('paragraphs' => $paragraphs, 'also' => $also, 'forum' => $forum, 'sentences' => count($kept));
    }
}

if (!function_exists('kop_pnotes_html')) {
    /**
     * The built notes as HTML: one <p> per paragraph, each sentence followed by its "source" link(s)
     * (kop_facility_pages_sources_html()), then "Also drawn on: ..." when the record names sources it was
     * added or corrected from. Everything is escaped here.
     */
    function kop_pnotes_html(array $about, $class = 'kop-fp-about-text') {
        $out = '';
        foreach ((array) ($about['paragraphs'] ?? array()) as $para) {
            $bits = array();
            foreach ($para as $s) {
                $src = function_exists('kop_facility_pages_sources_html') ? kop_facility_pages_sources_html($s['sources'], 'span') : '';
                $bits[] = (function_exists('kop_facility_pages_cited_html') ? kop_facility_pages_cited_html($s['text']) : esc_html($s['text'])) . $src;
            }
            if ($bits) $out .= '<p class="' . esc_attr($class) . '">' . implode(' ', $bits) . '</p>';
        }
        $also = array();
        foreach ((array) ($about['also'] ?? array()) as $a) {
            if ($a['url'] === '') continue;
            $also[] = function_exists('kop_citation_link')
                ? kop_citation_link($a['url'], $a['label'], $a['cite'], true, '', true)
                : '<a href="' . esc_url($a['url']) . '" target="_blank" rel="noopener nofollow">' . esc_html($a['label']) . '</a>';
        }
        if ($also) $out .= '<p class="kop-fp-count kop-fp-about-also">Also drawn on: ' . implode('; ', $also) . '.</p>';
        return $out;
    }
}
