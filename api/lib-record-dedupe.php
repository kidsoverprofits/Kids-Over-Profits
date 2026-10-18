<?php
/**
 * One person, one name, one event, wherever two copies meet on a facility record (docs/PLAN.md 3.36).
 *
 * Every path that puts something onto a record asks these rules before it adds a second copy:
 * Woodbury Facts / HEAL / wiki / Fornits items (kop_wbf_doc_apply()), wiki approve
 * (kop_wiki_merge_submission()) and Merge Duplicates (kop_mfd_merge_docs()), which joins two
 * records that each got their own copy of the same facts.
 *
 *   kop_dd_same_person($a, $b)       "Jim Omer" = "James Omer", "Angela Agundez (Smith)" = "Angela Agundez",
 *                                    "Marc Clark" = "Marc Clarke"; "Robert W. Lichfield" != "Robert B. Lichfield"
 *   kop_dd_role_tidy($role)          each title once, no source name: "Founder, Owner, Founder (2006, Woodbury Reports)"
 *                                    -> "Founder, Owner (2006)"
 *   kop_dd_fold_staff($doc)          one entry per person across administrator and notableStaff
 *   kop_dd_name_key($name)           "Red River Academy (2006-2015)" = "Red River Academy"; "Sequel TSI of Tuskegee" =
 *                                    "Sequel TSI Tuskegee"
 *   kop_dd_tidy_names($doc)          no past/other name that is the record's own name or another listed name
 *   kop_dd_same_event($a, $b)        two incident lines about one event (same kind, same month, the same telling)
 *
 * Plain PHP: also loaded outside WordPress (the wiki sync's dry run, the offline tests).
 */

if (!function_exists('kop_dd_ascii')) {
    function kop_dd_ascii($s) {
        $s = (string) $s;
        if (function_exists('remove_accents')) return remove_accents($s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $t === false ? $s : $t;
    }
}

if (!function_exists('kop_dd_person_parts')) {
    /**
     * A name as {first, last, middles[], others[]}: first name with its nickname folded ("Jim" -> "james", by
     * kop_facility_pages_person_key()'s list), last name, middle names/initials, and the other surnames the
     * name itself gives (a maiden name in brackets, either half of a hyphenated name). null below two words.
     */
    function kop_dd_person_parts($name) {
        $name = (string) $name;
        $others = array();
        if (preg_match_all('/\(([^)]*)\)/', $name, $m)) {
            foreach ($m[1] as $in) {
                $in = strtolower(trim(kop_dd_ascii($in)));
                if (preg_match('/^[a-z\'-]{3,}$/', $in)) $others[] = $in;
            }
        }
        $name = preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]|\([^)]*\)/u', ' ', $name);
        $name = preg_replace('/,.*$/', '', $name);
        $name = strtolower(kop_dd_ascii($name));
        $drop = array('dr', 'mr', 'mrs', 'ms', 'rev', 'jr', 'sr', 'ii', 'iii', 'iv', 'phd', 'md', 'psyd', 'lcsw', 'lpc', 'lmft', 'rn', 'ma',
            'msw', 'edd', 'med', 'lpcc', 'np', 'do');
        $tokens = array();
        foreach (preg_split('/[^a-z\'-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = trim($t, "'-");
            if ($t === '' || in_array($t, $drop, true)) continue;
            $tokens[] = $t;
        }
        if (count($tokens) < 2) return null;
        $first = $tokens[0];
        if (function_exists('kop_facility_pages_person_key')) {
            $k = kop_facility_pages_person_key(ucfirst($first) . ' Xx');
            if ($k !== '') $first = explode(' ', $k)[0];
        }
        $last = $tokens[count($tokens) - 1];
        if (strpos($last, '-') !== false) {
            foreach (explode('-', $last) as $half) if (strlen($half) >= 3) $others[] = $half;
        }
        return array('first' => $first, 'last' => $last, 'middles' => array_slice($tokens, 1, -1), 'others' => array_values(array_unique($others)));
    }
}

if (!function_exists('kop_dd_same_person')) {
    /**
     * Whether two names on one record are one person. Same first name (nicknames folded) and the same last name,
     * or a surname the other name gives ("Sturges-Ullrich" / "Sturges", "Agundez (Smith)" / "Smith"), or a last
     * name one letter off in 5+ letters ("Clark" / "Clarke", "McLain" / "McClain"). Two different middle initials
     * are two people (Robert W. and Robert B. Lichfield, father and son); one missing is no difference. An entry
     * naming two people ("Mark and Jan Gregston") is never folded.
     */
    function kop_dd_same_person($a, $b) {
        $x = kop_dd_person_parts($a);
        $y = kop_dd_person_parts($b);
        if (!$x || !$y || $x['first'] !== $y['first']) return false;
        // "Mark and Jan Gregston" is two people in one entry, never one of them.
        if (preg_match('/\s(?:and|&)\s/i', (string) $a) || preg_match('/\s(?:and|&)\s/i', (string) $b)) return false;
        $xm = $x['middles'] ? $x['middles'][0][0] : '';
        $ym = $y['middles'] ? $y['middles'][0][0] : '';
        if ($xm !== '' && $ym !== '' && $xm !== $ym) return false;
        $xl = array_merge(array($x['last']), $x['others']);
        $yl = array_merge(array($y['last']), $y['others']);
        if (array_intersect($xl, $yl)) return true;
        $p = str_replace(array('-', "'"), '', $x['last']);
        $q = str_replace(array('-', "'"), '', $y['last']);
        return min(strlen($p), strlen($q)) >= 5 && levenshtein($p, $q) <= 1;
    }
}

if (!function_exists('kop_dd_role_tidy')) {
    /**
     * A role with each title once and no source name: "Founder/Clinical Director, Owner, Founder, Clinical
     * Director (2006-2011, Woodbury Reports)" -> "Founder/Clinical Director, Owner (2006-2011)". "Former" and
     * a year range stay; the source is the entry's own source field.
     */
    function kop_dd_role_tidy($role) {
        $role = trim((string) $role);
        if ($role === '') return '';
        $when = '';
        // Trailing "(years, Source)" or "(Source)": keep the years only.
        while (preg_match('/\s*\(([^()]*)\)\s*$/', $role, $m)) {
            $bits = array_map('trim', explode(',', $m[1]));
            $years = array_values(array_filter($bits, function ($b) { return (bool) preg_match('/^(?:before |after |since |c\. ?)?(?:19|20)\d\d(?:\s*[-–]\s*(?:(?:19|20)\d\d|present))?$/i', $b); }));
            $source = array_filter($bits, function ($b) { return (bool) preg_match('/woodbury|heal\b|r\/troubledteens|wiki|fornits|reddit/i', $b); });
            if (!$years && !$source) break;
            if ($years && $when === '') $when = $years[0];
            $role = trim(substr($role, 0, -strlen($m[0])));
        }
        $lead = '';
        if (preg_match('/^(former|current)\s+/i', $role, $m)) {
            $lead = ucfirst(strtolower($m[1])) . ' ';
            $role = substr($role, strlen($m[0]));
        }
        $parts = array();
        $seen = array();
        foreach (preg_split('/\s*[,;]\s*/', $role) as $p) {
            $p = trim($p);
            $k = strtolower(preg_replace('/\s+/', ' ', str_replace('&', 'and', preg_replace('/^(?:former|current|the|a|an)\s+/i', '', $p))));
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $parts[] = $p;
        }
        // A title another part already holds ("Founder" next to "Founder and President") says nothing more.
        $keys = array_keys($seen);
        $parts = array_values(array_filter($parts, function ($p) use ($keys) {
            $k = strtolower(preg_replace('/\s+/', ' ', str_replace('&', 'and', preg_replace('/^(?:former|current|the|a|an)\s+/i', '', $p))));
            foreach ($keys as $o) {
                if ($o !== $k && strlen($o) > strlen($k) && preg_match('/(?:^|[\s\/&])' . preg_quote($k, '/') . '(?:$|[\s\/&])/', $o)) return false;
            }
            return true;
        }));
        $out = $lead . implode(', ', $parts);
        return trim($out . ($when !== '' ? ' (' . $when . ')' : ''));
    }
}

if (!function_exists('kop_dd_join_jobs')) {
    /** Past jobs joined, each once ("A; B" + "B; C" -> "A; B; C"), compared without years or brackets. */
    function kop_dd_join_jobs($a, $b) {
        $out = array();
        $seen = array();
        foreach (array_merge(explode(';', (string) $a), explode(';', (string) $b)) as $j) {
            $j = trim($j);
            $k = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)|\[[^\]]*\]|^(?:later|then|previously|formerly)\s+/i', ' ', $j))));
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $j;
        }
        return implode('; ', $out);
    }
}

if (!function_exists('kop_dd_join_role')) {
    /** Two roles of one person as one: the titles of both, each once (kop_dd_role_tidy()), the first one's "Former"/years. */
    function kop_dd_join_role($a, $b) {
        $a = trim((string) $a);
        $b = trim((string) $b);
        if ($b === '') return kop_dd_role_tidy($a);
        if ($a === '') return kop_dd_role_tidy($b);
        $ta = kop_dd_role_tidy($a);
        $tb = kop_dd_role_tidy($b);
        $when = preg_match('/\(([^()]*)\)\s*$/', $ta, $m) ? $m[1] : (preg_match('/\(([^()]*)\)\s*$/', $tb, $m) ? $m[1] : '');
        $bare = function ($r) { return trim(preg_replace('/^(?:Former|Current)\s+/i', '', preg_replace('/\s*\([^()]*\)\s*$/', '', $r))); };
        $lead = preg_match('/^(Former|Current)\s+/', $ta, $m) ? $m[1] . ' ' : '';
        $out = kop_dd_role_tidy($lead . $bare($ta) . ', ' . $bare($tb));
        return $out . ($when !== '' ? ' (' . $when . ')' : '');
    }
}

if (!function_exists('kop_dd_allowed')) {
    /** Whether a step may run: every step when $allow is null, else the steps it names (keys) or approves (callable). */
    function kop_dd_allowed($allow, $key) {
        if ($allow === null) return true;
        if (is_callable($allow)) return (bool) $allow($key);
        return in_array($key, (array) $allow, true);
    }
}

if (!function_exists('kop_dd_fold_staff')) {
    /**
     * One entry per person across administrator and notableStaff. The first entry stays where it is
     * (administrator before notableStaff), the copies' titles join its role, their past jobs its past jobs,
     * and any field it lacks (source, sourceUrl, personId) comes from them. Two entries the people table holds
     * as two people (different personId) are never folded. $allow: kop_dd_allowed().
     * Returns [doc, steps [{key, kind 'staff', text}]].
     */
    function kop_dd_fold_staff(array $doc, $allow = null) {
        $steps = array();
        $entries = array();
        foreach (array('administrator', 'notableStaff') as $list) {
            foreach ((array) ($doc['staff'][$list] ?? array()) as $i => $e) {
                if (is_array($e) && trim((string) ($e['name'] ?? '')) !== '') $entries[] = array($list, $i);
            }
        }
        $gone = array();
        foreach ($entries as $n => $at) {
            if (isset($gone[$at[0]][$at[1]])) continue;
            $keep = &$doc['staff'][$at[0]][$at[1]];
            for ($m = $n + 1; $m < count($entries); $m++) {
                $o = $entries[$m];
                if (isset($gone[$o[0]][$o[1]])) continue;
                $copy = $doc['staff'][$o[0]][$o[1]];
                $pa = (int) ($keep['personId'] ?? 0);
                $pb = (int) ($copy['personId'] ?? 0);
                if ($pa && $pb && $pa !== $pb) continue;
                if (!($pa && $pa === $pb) && !kop_dd_same_person($keep['name'], $copy['name'])) continue;
                $key = 'staff:' . md5(mb_strtolower(trim($keep['name'])) . '|' . mb_strtolower(trim($copy['name'])) . '|' . mb_strtolower(trim((string) ($copy['role'] ?? ''))));
                if (!kop_dd_allowed($allow, $key)) continue;
                $was = trim((string) ($keep['role'] ?? ''));
                $keep['role'] = kop_dd_join_role($keep['role'] ?? '', $copy['role'] ?? '');
                $jobs = kop_dd_join_jobs($keep['pastJobs'] ?? '', $copy['pastJobs'] ?? '');
                if ($jobs !== '' || isset($keep['pastJobs'])) $keep['pastJobs'] = $jobs;
                foreach ($copy as $k => $v) {
                    if ((!isset($keep[$k]) || $keep[$k] === '' || $keep[$k] === null || $keep[$k] === 0) && $v !== '' && $v !== null && $v !== 0) $keep[$k] = $v;
                }
                $gone[$o[0]][$o[1]] = true;
                $same = mb_strtolower(trim($copy['name'])) === mb_strtolower(trim($keep['name']));
                $steps[] = array('key' => $key, 'kind' => 'staff', 'text' => ($same ? $keep['name'] . ' is listed twice' : '"' . $copy['name'] . '" is ' . $keep['name'])
                    . ' ("' . $was . '" and "' . trim((string) ($copy['role'] ?? '')) . '"): one entry, ' . $keep['role']);
            }
            unset($keep);
        }
        foreach ($gone as $list => $idx) {
            $doc['staff'][$list] = array_values(array_diff_key($doc['staff'][$list], $idx));
        }
        return array($doc, $steps);
    }
}

if (!function_exists('kop_dd_tidy_roles')) {
    /**
     * Every staff role with each title once and no source name (kop_dd_role_tidy()), as one step 'roles'.
     * Returns [doc, steps].
     */
    function kop_dd_tidy_roles(array $doc, $allow = null) {
        if (!kop_dd_allowed($allow, 'roles')) return array($doc, array());
        $changed = array();
        foreach (array('administrator', 'notableStaff') as $list) {
            foreach ((array) ($doc['staff'][$list] ?? array()) as $i => $e) {
                if (!is_array($e) || !isset($e['role']) || !is_string($e['role'])) continue;
                $t = kop_dd_role_tidy($e['role']);
                if ($t === trim($e['role'])) continue;
                $changed[] = '"' . $e['role'] . '" becomes "' . $t . '"';
                $doc['staff'][$list][$i]['role'] = $t;
            }
        }
        if (!$changed) return array($doc, array());
        return array($doc, array(array('key' => 'roles', 'kind' => 'roles', 'text' => count($changed) . ' role' . (count($changed) === 1 ? '' : 's')
            . ' without the source\'s name or repeated titles (each person\'s source link stays): ' . implode('; ', array_slice($changed, 0, 4))
            . (count($changed) > 4 ? '; and ' . (count($changed) - 4) . ' more' : ''))));
    }
}

if (!function_exists('kop_dd_name_key')) {
    /** A program name as a reader compares it: no "(years)" or "(AYA)", no "The", "of", "Inc", "LLC", punctuation. */
    function kop_dd_name_key($name) {
        $s = strtolower(kop_dd_ascii((string) $name));
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/\([^)]*\)/', ' ', $s);
        $s = preg_replace('/[\x{2019}\'\x{FFFD}]s\b/u', 's', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $w = array_diff(preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY), array('the', 'inc', 'llc', 'of', 'and', 'a', 'at', 'for', 'in', 'corp'));
        return implode(' ', $w);
    }
}

if (!function_exists('kop_dd_tidy_names')) {
    /**
     * Past and other names each once: a name that is the record's own (or its "now called") leaves both lists,
     * one listed twice in another form keeps the plainer copy (no "(2006-2015)"), and one in both lists stays
     * only a past name. Returns [doc, steps [{key, kind 'name', text}]].
     */
    function kop_dd_tidy_names(array $doc, $allow = null) {
        $id = (array) ($doc['identification'] ?? array());
        $seen = array();
        foreach (array($id['name'] ?? '', $id['currentName'] ?? '') as $n) {
            $k = kop_dd_name_key($n);
            if ($k !== '' && !isset($seen[$k])) $seen[$k] = array('own', (string) $n);
        }
        $steps = array();
        $words = array('pastNames' => 'past names', 'otherNames' => 'other names');
        foreach (array('pastNames', 'otherNames') as $list) {
            if (!is_array($id[$list] ?? null)) continue;
            $items = $id[$list];
            // The plainer copy first, so it is the one kept.
            $order = array_keys($items);
            usort($order, function ($p, $q) use ($items) {
                $a = is_string($items[$p]) && preg_match('/\(/', $items[$p]) ? 1 : 0;
                $b = is_string($items[$q]) && preg_match('/\(/', $items[$q]) ? 1 : 0;
                return $a === $b ? $p - $q : $a - $b;
            });
            $keep = array();
            foreach ($order as $i) {
                $n = $items[$i];
                if (!is_string($n)) { $keep[$i] = $n; continue; }
                $k = kop_dd_name_key($n);
                if ($k !== '' && isset($seen[$k])) {
                    $key = 'name:' . md5($list . '|' . $n);
                    if (kop_dd_allowed($allow, $key)) {
                        list($where, $other) = $seen[$k];
                        $steps[] = array('key' => $key, 'kind' => 'name', 'text' => '"' . $n . '" comes off the ' . $words[$list] . ': '
                            . ($where === 'own' ? 'it is the record\'s own name, "' . $other . '"' : 'it is listed already as "' . $other . '" (' . $words[$where] . ')'));
                        continue;
                    }
                }
                if ($k !== '' && !isset($seen[$k])) $seen[$k] = array($list, $n);
                $keep[$i] = $n;
            }
            ksort($keep);
            $id[$list] = array_values($keep);
        }
        $doc['identification'] = $id;
        return array($doc, $steps);
    }
}

if (!function_exists('kop_dd_event_parts')) {
    /** An incident line as {date: "1998-03"/"1998"/"", kind: "death", words: [...]}, citation dropped. */
    function kop_dd_event_parts($line) {
        $s = trim((string) $line);
        $s = preg_replace('/\s*\((?:[^()]|\([^()]*\))*\)\s*$/', '', $s);
        $date = '';
        $kind = '';
        if (preg_match('/^(?:Reported\s+)?((?:19|20)\d\d(?:-\d\d){0,2})(?:\s*-\s*(?:19|20)\d\d)?:\s*(?:([A-Z][\w \/-]{0,30}):\s*)?/', $s, $m)) {
            $date = $m[1];
            $kind = strtolower(trim($m[2] ?? ''));
            $s = substr($s, strlen($m[0]));
            if (stripos($line, 'Reported ') === 0) $date = '';
        }
        preg_match_all('/[a-z0-9]+/', strtolower($s), $w);
        $stop = array('the', 'a', 'an', 'of', 'and', 'to', 'in', 'at', 'for', 'on', 'with', 'by', 'was', 'were', 'is', 'his', 'her', 'their', 'after', 'as', 'that');
        return array('date' => $date, 'kind' => $kind, 'words' => array_values(array_unique(array_diff($w[0], $stop))));
    }
}

if (!function_exists('kop_dd_same_event')) {
    /**
     * Whether two incident lines tell one event: the same kind on the same day, the same kind in the same
     * month told in mostly the same words (half of the shorter telling's words), or, when one line gives only
     * the year, three quarters of them. A death a year apart, or two deaths in one month told differently, stay two.
     */
    function kop_dd_same_event($a, $b) {
        $x = kop_dd_event_parts($a);
        $y = kop_dd_event_parts($b);
        if ($x['date'] === '' || $y['date'] === '' || $x['kind'] === '' || $x['kind'] !== $y['kind']) return false;
        // Two exact days that differ are two events (two girls died at Asheville Academy in May 2025, on the 3rd and the 29th).
        if (strlen($x['date']) === 10 && strlen($y['date']) === 10 && $x['date'] !== $y['date']) return false;
        // So are two tellings that give different ages ("a 13-year-old", "a 12-year-old").
        if (preg_match_all('/\b(\d{1,2})-year-old/', $a, $ax) && preg_match_all('/\b(\d{1,2})-year-old/', $b, $bx) && !array_intersect($ax[1], $bx[1])) return false;
        // ...or different numbers of people ("17 former students", "nine former students": two lawsuits at Mount Bachelor).
        $people = function ($s) {
            static $words = array('one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
                'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
                'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20);
            preg_match_all('/\b(\d+|' . implode('|', array_keys($words)) . ')\s+(?:former\s+|other\s+)?(?:students|plaintiffs|residents|girls|boys|youths|teens|children|kids|staff|employees|people|women|men)\b/i', $s, $m);
            return array_map(function ($n) use ($words) { return is_numeric($n) ? (int) $n : $words[strtolower($n)]; }, $m[1]);
        };
        $pa = $people($a);
        $pb = $people($b);
        if ($pa && $pb && !array_intersect($pa, $pb)) return false;
        if (strlen($x['date']) === 10 && $x['date'] === $y['date']) return true;
        if (substr($x['date'], 0, 4) !== substr($y['date'], 0, 4)) return false;
        $small = min(count($x['words']), count($y['words']));
        $share = $small > 0 ? count(array_intersect($x['words'], $y['words'])) / $small : 0;
        // Both give the month: it must be the same one. Only one gives the year alone: nearly every word must agree.
        if (strlen($x['date']) >= 7 && strlen($y['date']) >= 7) return substr($x['date'], 0, 7) === substr($y['date'], 0, 7) && $share >= 0.5;
        return $share >= 0.75;
    }
}

if (!function_exists('kop_dd_fold_incidents')) {
    /**
     * One line per event in criticalIncidents.customIncidents: a later telling of the same event
     * (kop_dd_same_event()) leaves, the fuller telling stays in the first one's place.
     * Returns [doc, steps [{key, kind 'incident', text}]].
     */
    function kop_dd_fold_incidents(array $doc, $allow = null) {
        $list = $doc['criticalIncidents']['customIncidents'] ?? null;
        if (!is_array($list)) return array($doc, array());
        $out = array();
        $steps = array();
        $short = function ($s) { return mb_strlen($s) > 140 ? mb_substr($s, 0, 137) . '...' : $s; };
        foreach ($list as $line) {
            if (!is_string($line)) { $out[] = $line; continue; }
            foreach ($out as $i => $have) {
                if (!is_string($have) || !kop_dd_same_event($have, $line)) continue;
                $longer = mb_strlen($line) > mb_strlen($have);
                $drop = $longer ? $have : $line;
                $key = 'inc:' . md5($drop);
                if (!kop_dd_allowed($allow, $key)) continue;
                $kept = $longer ? $line : $have;
                // The fuller telling keeps the more exact date of the two ("2006: ..." takes "2006-04" from the other).
                $dk = kop_dd_event_parts($kept)['date'];
                $dd = kop_dd_event_parts($drop)['date'];
                if (strlen($dd) > strlen($dk) && strpos($dd, $dk) === 0) $kept = preg_replace('/^' . preg_quote($dk, '/') . '\b/', $dd, $kept, 1);
                $steps[] = array('key' => $key, 'kind' => 'incident', 'text' => 'One event told twice. Keeps "' . $short($kept) . '"; takes off "' . $short($drop) . '"');
                $out[$i] = $kept;
                continue 2;
            }
            $out[] = $line;
        }
        $doc['criticalIncidents']['customIncidents'] = $out;
        return array($doc, $steps);
    }
}

if (!function_exists('kop_dd_tidy_doc')) {
    /**
     * Every rule above on one record: one entry per person, roles tidied, each name once, one line per event.
     * $allow: null for every step, or the step keys to run (kop_dd_allowed()). Returns [doc, steps]; no steps = nothing to tidy.
     */
    function kop_dd_tidy_doc(array $doc, $allow = null) {
        list($doc, $a) = kop_dd_fold_staff($doc, $allow);
        list($doc, $b) = kop_dd_tidy_roles($doc, $allow);
        list($doc, $c) = kop_dd_tidy_names($doc, $allow);
        list($doc, $d) = kop_dd_fold_incidents($doc, $allow);
        return array($doc, array_merge($a, $b, $c, $d));
    }
}
