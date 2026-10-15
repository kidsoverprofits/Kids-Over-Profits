<?php
/**
 * Lawsuit and bill statuses, our position on a bill, volunteer project types
 * and the memorial's causes of death (KOP Tools > Dropdown Lists). No
 * WordPress needed: the api/ save endpoints read them without it.
 *
 * Statuses carry two settings a new choice needs:
 *   finished  the case or bill is over, so the nightly status check
 *             (inc/status-checks.php) stops looking at it;
 *   badge     the colour of its badge on /lawsuits/, /legislation/ and the
 *             state and country pages: the value of a built-in choice whose
 *             colour it borrows (css/public-records.css .kop-badge-<badge>,
 *             css/state-page.css .status-pill.status-<badge>).
 * A vetoed bill is also watched 120 days for an override; that rule stays in
 * the code (kop_sc_due()).
 *
 * Left as code on purpose: chamber, level, volunteer project status and the
 * publication statuses (draft/pending/approved/published/rejected) are
 * workflow states the code acts on, not categories.
 *
 * The lawsuits, legislation and volunteer_projects columns these fill were
 * MySQL ENUMs, which refuse a value they do not list. kop_status_columns_to_varchar()
 * turns them into VARCHAR(40) once (admin_init, option kop_status_columns_varchar),
 * keeping every stored value and the same default.
 */

if (!function_exists('kop_status_badge_options')) {
    /** "Same colour as <label>" for each built-in choice of a list (the badge field's choices). */
    function kop_status_badge_options($key) {
        $def = kop_choice_def($key);
        $out = array();
        foreach ((array) ($def['defaults'] ?? array()) as $value => $c) {
            $out[(string) $value] = 'Same colour as ' . kop_choice_label($c);
        }
        return $out;
    }
}

kop_choice_register('lawsuit-statuses', array(
    'label'    => 'Lawsuit statuses',
    'group'    => 'Lawsuits, bills and volunteers',
    'where'    => 'The "Status" of a court case: the lawsuit editor, the public "Submit a Lawsuit" form, the case editors in Submissions Review and the review inbox, the pencil on a case, the statuses the AI may give when it reads a court paper or a docket, and the status badges and filters on /lawsuits/, facility, company, state and country pages.',
    'note'     => 'Tick "Case is over" for a status that means the case has ended: the nightly check of court dockets stops looking at cases with that status. Dismissed is left unticked because a dismissal can be appealed.',
    'js'       => true,
    'last'     => array('unknown'),
    'fields'   => array(
        'finished' => array('label' => 'Case is over', 'type' => 'check', 'check_label' => 'Over',
            'help' => 'The nightly status check stops looking at a case with this status.'),
        'badge'    => array('label' => 'Badge colour', 'type' => 'select', 'required' => true,
            'options' => function () { return kop_status_badge_options('lawsuit-statuses'); },
            'help' => 'The colour of the status badge on the public pages, borrowed from a built-in status.'),
    ),
    'defaults' => array(
        'filed'       => array('label' => 'Filed', 'finished' => 0, 'badge' => 'filed'),
        'in_progress' => array('label' => 'In progress', 'finished' => 0, 'badge' => 'in_progress'),
        'settled'     => array('label' => 'Settled', 'finished' => 1, 'badge' => 'settled'),
        'dismissed'   => array('label' => 'Dismissed', 'finished' => 0, 'badge' => 'dismissed'),
        'ruling'      => array('label' => 'Ruling issued', 'finished' => 0, 'badge' => 'ruling'),
        'appeal'      => array('label' => 'On appeal', 'finished' => 0, 'badge' => 'appeal'),
        'closed'      => array('label' => 'Closed', 'finished' => 1, 'badge' => 'closed'),
        'unknown'     => array('label' => 'Unknown', 'finished' => 0, 'badge' => 'unknown'),
    ),
));

kop_choice_register('bill-statuses', array(
    'label'    => 'Bill statuses',
    'group'    => 'Lawsuits, bills and volunteers',
    'where'    => 'The "Status" of a bill: the legislation editor, the public "Submit Legislation" form, the bill editors in Submissions Review and the review inbox, the pencil on a bill, the statuses the AI may give when it reads a bill\'s page, and the status badges and filters on /legislation/, state and country pages.',
    'note'     => 'Tick "Bill is finished" for a status that means nothing more will happen to the bill: the nightly check of legislature pages stops looking at bills with that status. A vetoed bill is still checked for 120 days in case the veto is overridden.',
    'js'       => true,
    'last'     => array('unknown'),
    'fields'   => array(
        'finished' => array('label' => 'Bill is finished', 'type' => 'check', 'check_label' => 'Finished',
            'help' => 'The nightly status check stops looking at a bill with this status.'),
        'badge'    => array('label' => 'Badge colour', 'type' => 'select', 'required' => true,
            'options' => function () { return kop_status_badge_options('bill-statuses'); },
            'help' => 'The colour of the status badge on the public pages, borrowed from a built-in status.'),
    ),
    'defaults' => array(
        'proposed'      => array('label' => 'Proposed (discussion draft)', 'finished' => 0, 'badge' => 'proposed'),
        'introduced'    => array('label' => 'Introduced', 'finished' => 0, 'badge' => 'introduced'),
        'in_committee'  => array('label' => 'In committee', 'finished' => 0, 'badge' => 'in_committee'),
        'passed_house'  => array('label' => 'Passed House', 'finished' => 0, 'badge' => 'passed_house'),
        'passed_senate' => array('label' => 'Passed Senate', 'finished' => 0, 'badge' => 'passed_senate'),
        'signed'        => array('label' => 'Signed', 'finished' => 0, 'badge' => 'signed'),
        'vetoed'        => array('label' => 'Vetoed', 'finished' => 0, 'badge' => 'vetoed'),
        'dead'          => array('label' => 'Dead', 'finished' => 1, 'badge' => 'dead'),
        'enacted'       => array('label' => 'Enacted', 'finished' => 1, 'badge' => 'enacted'),
        'unknown'       => array('label' => 'Unknown', 'finished' => 0, 'badge' => 'unknown'),
    ),
));

kop_choice_register('bill-positions', array(
    'label'    => 'Our position on a bill',
    'group'    => 'Lawsuits, bills and volunteers',
    'where'    => 'Kids Over Profits\' stance on a bill ("KOP position"): the legislation editor, the bill editors in Submissions Review and the review inbox, the pencil on a bill, and the "KOP:" badge and the position filter on /legislation/, state and country pages.',
    'js'       => true,
    'last'     => array('unknown'),
    'fields'   => array(
        'badge' => array('label' => 'Badge colour', 'type' => 'select', 'required' => true,
            'options' => function () { return kop_status_badge_options('bill-positions'); },
            'help' => 'The colour of the "KOP:" badge, borrowed from a built-in position.'),
    ),
    'defaults' => array(
        'support' => array('label' => 'Support', 'badge' => 'support'),
        'oppose'  => array('label' => 'Oppose', 'badge' => 'oppose'),
        'neutral' => array('label' => 'Neutral', 'badge' => 'neutral'),
        'watch'   => array('label' => 'Watch', 'badge' => 'watch'),
        'unknown' => array('label' => 'Unknown', 'badge' => 'unknown'),
    ),
));

kop_choice_register('volunteer-project-types', array(
    'label'    => 'Volunteer project types',
    'group'    => 'Lawsuits, bills and volunteers',
    'where'    => 'The "Project type" of a volunteer project: the volunteer projects editor (its form and its type filter) and the type badge on each project on the public volunteer page.',
    'last'     => array('other'),
    'defaults' => array(
        'research'   => 'Research',
        'data_entry' => 'Data Entry',
        'advocacy'   => 'Advocacy',
        'tech'       => 'Tech',
        'outreach'   => 'Outreach',
        'other'      => 'Other',
    ),
));

kop_choice_register('memorial-causes', array(
    'label'    => 'Memorial causes of death',
    'group'    => 'Lawsuits, bills and volunteers',
    'where'    => 'The cause groups on the In Loving Memory page: the "All causes of death" filter there, the "Cause, for the filter" choice in the pencil on a memorial entry, and the causes a data audit correction may set. The cause as reported is written out in each entry and does not come from this list.',
    'last'     => array('other', 'unknown'),
    'defaults' => array(
        'suicide'         => 'Suicide',
        'restraint'       => 'Restraint',
        'medical_neglect' => 'Medical neglect',
        'overdose'        => 'Overdose / medication',
        'escape_attempt'  => 'During escape attempt',
        'drowning'        => 'Drowning',
        'exposure'        => 'Exposure / heat',
        'violence'        => 'Violence',
        'accident'        => 'Accident',
        'other'           => 'Other',
        'unknown'         => 'Unknown',
    ),
));

// ---------------------------------------------------------------------------
// Readers.

if (!function_exists('kop_status_choice_labels')) {
    /** value => label of any list in this file. */
    function kop_status_choice_labels($key, $mode = 'all') {
        $out = array();
        foreach (kop_choices($key, null, $mode) as $value => $c) {
            $out[(string) $value] = kop_choice_label($c);
        }
        return $out;
    }
}

if (!function_exists('kop_status_choice_label')) {
    /** The label of a stored value; one the list lacks reads "In progress" from "in_progress". */
    function kop_status_choice_label($key, $value) {
        $value = trim((string) $value);
        $labels = kop_status_choice_labels($key, 'all');
        return $labels[$value] ?? ucfirst(str_replace('_', ' ', $value));
    }
}

if (!function_exists('kop_status_choice_badge')) {
    /** The badge class suffix of a value: its badge setting, else the value itself. */
    function kop_status_choice_badge($key, $value) {
        $value = trim((string) $value);
        $list = kop_choices($key, null, 'all');
        $badge = is_array($list[$value] ?? null) ? trim((string) ($list[$value]['badge'] ?? '')) : '';
        $badge = $badge !== '' ? $badge : $value;
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($badge));
    }
}

if (!function_exists('kop_status_choice_finished')) {
    /** Values ticked finished ('all': a hidden finished status still counts). */
    function kop_status_choice_finished($key) {
        $out = array();
        foreach (kop_choices($key, null, 'all') as $value => $c) {
            if (is_array($c) && !empty($c['finished'])) $out[] = (string) $value;
        }
        return $out;
    }
}

if (!function_exists('kop_status_choice_options_html')) {
    /**
     * <option>s of a list for a form: 'unknown' (when listed) first, then the
     * rest in list order. Hidden choices come last, marked "(hidden)", so a
     * record that holds one still opens with it selected. $labels overrides
     * a label ('unknown' => 'Not sure' on the public forms); $with_hidden
     * false leaves them out (public forms).
     */
    function kop_status_choice_options_html($key, $selected = null, array $labels = array(), $with_hidden = true) {
        $all = kop_status_choice_labels($key, 'all');
        $offered = kop_status_choice_labels($key, 'offered');
        $order = array();
        if (isset($all['unknown'])) $order[] = 'unknown';
        foreach ($offered as $v => $_) if ($v !== 'unknown') $order[] = $v;
        if ($with_hidden) {
            foreach ($all as $v => $_) if (!isset($offered[$v]) && $v !== 'unknown') $order[] = $v;
        }
        $h = '';
        foreach (array_unique($order) as $v) {
            $label = $labels[$v] ?? $all[$v];
            if (!isset($offered[$v])) $label .= ' (hidden)';
            $h .= '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '"' . ((string) $selected === (string) $v ? ' selected' : '') . '>'
                . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }
        return $h;
    }
}

if (!function_exists('kop_lawsuit_statuses')) {
    /** value => label. 'all' (checking a stored or saved status) or 'offered' (dropdowns, the AI). */
    function kop_lawsuit_statuses($mode = 'all') { return kop_status_choice_labels('lawsuit-statuses', $mode); }
}
if (!function_exists('kop_lawsuit_status_values')) {
    function kop_lawsuit_status_values($mode = 'all') { return array_keys(kop_lawsuit_statuses($mode)); }
}
if (!function_exists('kop_lawsuit_status_label')) {
    function kop_lawsuit_status_label($value) { return kop_status_choice_label('lawsuit-statuses', $value); }
}
if (!function_exists('kop_lawsuit_status_badge')) {
    function kop_lawsuit_status_badge($value) { return kop_status_choice_badge('lawsuit-statuses', $value); }
}
if (!function_exists('kop_lawsuit_finished_statuses')) {
    /** Statuses the nightly check skips (built in: settled, closed). */
    function kop_lawsuit_finished_statuses() { return kop_status_choice_finished('lawsuit-statuses'); }
}

if (!function_exists('kop_bill_statuses')) {
    function kop_bill_statuses($mode = 'all') { return kop_status_choice_labels('bill-statuses', $mode); }
}
if (!function_exists('kop_bill_status_values')) {
    function kop_bill_status_values($mode = 'all') { return array_keys(kop_bill_statuses($mode)); }
}
if (!function_exists('kop_bill_status_label')) {
    function kop_bill_status_label($value) { return kop_status_choice_label('bill-statuses', $value); }
}
if (!function_exists('kop_bill_status_badge')) {
    function kop_bill_status_badge($value) { return kop_status_choice_badge('bill-statuses', $value); }
}
if (!function_exists('kop_bill_finished_statuses')) {
    /** Statuses the nightly check skips (built in: enacted, dead). */
    function kop_bill_finished_statuses() { return kop_status_choice_finished('bill-statuses'); }
}

if (!function_exists('kop_bill_positions')) {
    function kop_bill_positions($mode = 'all') { return kop_status_choice_labels('bill-positions', $mode); }
}
if (!function_exists('kop_bill_position_values')) {
    function kop_bill_position_values($mode = 'all') { return array_keys(kop_bill_positions($mode)); }
}
if (!function_exists('kop_bill_position_label')) {
    function kop_bill_position_label($value) { return kop_status_choice_label('bill-positions', $value); }
}
if (!function_exists('kop_bill_position_badge')) {
    function kop_bill_position_badge($value) { return kop_status_choice_badge('bill-positions', $value); }
}

if (!function_exists('kop_volunteer_project_types')) {
    function kop_volunteer_project_types($mode = 'all') { return kop_status_choice_labels('volunteer-project-types', $mode); }
}

if (!function_exists('kop_memorial_causes')) {
    function kop_memorial_causes($mode = 'all') { return kop_status_choice_labels('memorial-causes', $mode); }
}

// ---------------------------------------------------------------------------
// ENUM columns -> VARCHAR(40), once.

if (!defined('KOP_STATUS_COLUMNS_VERSION')) {
    define('KOP_STATUS_COLUMNS_VERSION', '1');
}

if (!function_exists('kop_status_columns')) {
    /** table => column => default. All in the records DB (api/config.php). */
    function kop_status_columns() {
        return array(
            'lawsuits'           => array('status' => 'unknown'),
            'legislation'        => array('status' => 'unknown', 'position' => 'unknown'),
            'volunteer_projects' => array('project_type' => 'other'),
            // News article types (inc/choice-lists/news.php); kop_news_article_type_column_sync() calls
            // kop_status_column_to_varchar() for it before a save too.
            'news_submissions'   => array('article_type' => 'general'),
        );
    }
}

if (!function_exists('kop_status_column_to_varchar')) {
    /** One column of kop_status_columns_to_varchar(): 'altered', 'already', 'no table', 'skipped' or 'error: ...'. */
    function kop_status_column_to_varchar(PDO $pdo, $table, $col, $default) {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return 'skipped';
            $st = $pdo->prepare('SELECT DATA_TYPE, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute(array($table, $col));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if (!$row) return 'no table';
            if (strtolower((string) $row['DATA_TYPE']) !== 'enum') return 'already';
            $null = strtoupper((string) $row['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
            $comment = (string) $row['COLUMN_COMMENT'] !== '' ? ' COMMENT ' . $pdo->quote((string) $row['COLUMN_COMMENT']) : '';
            $pdo->exec("ALTER TABLE `{$table}` MODIFY `{$col}` VARCHAR(40) {$null} DEFAULT " . $pdo->quote($default) . $comment);
            return 'altered';
        } catch (Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}

if (!function_exists('kop_status_columns_to_varchar')) {
    /**
     * Turns each ENUM column of kop_status_columns() into VARCHAR(40) with the
     * same default, NULLability and comment; stored values are kept as they
     * are. A column already VARCHAR, or a table not made yet, is left alone.
     * MySQL only (SQLite test copies have no ENUM). Returns
     * {table.column: 'altered'|'already'|'no table'|'skipped'|error}.
     */
    function kop_status_columns_to_varchar(PDO $pdo) {
        $out = array();
        foreach (kop_status_columns() as $table => $cols) {
            foreach ($cols as $col => $default) {
                $out["$table.$col"] = kop_status_column_to_varchar($pdo, $table, $col, $default);
            }
        }
        return $out;
    }
}

if (!function_exists('kop_maybe_status_columns_to_varchar')) {
    /** Runs kop_status_columns_to_varchar() once per KOP_STATUS_COLUMNS_VERSION; $force on a list save. */
    function kop_maybe_status_columns_to_varchar($force = false) {
        if (!function_exists('get_option') || !function_exists('kop_seed_pdo')) return;
        if (!$force && get_option('kop_status_columns_varchar') === KOP_STATUS_COLUMNS_VERSION) return;
        if (get_transient('kop_status_columns_lock')) return;
        set_transient('kop_status_columns_lock', 1, 300);
        try {
            $pdo = kop_seed_pdo();
            if (!$pdo) return;
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $res = kop_status_columns_to_varchar($pdo);
            $errors = array_filter($res, function ($r) { return strpos($r, 'error') === 0; });
            if ($errors) {
                error_log('kop_status_columns_to_varchar: ' . json_encode($errors));
            } else {
                update_option('kop_status_columns_varchar', KOP_STATUS_COLUMNS_VERSION, false);
            }
        } catch (Throwable $e) {
            error_log('kop_status_columns_to_varchar: ' . $e->getMessage());
        } finally {
            delete_transient('kop_status_columns_lock');
        }
    }
}

if (function_exists('add_action')) {
    add_action('admin_init', 'kop_maybe_status_columns_to_varchar');
    // A status added on the Dropdown Lists screen must fit the column at once.
    add_action('kop_choice_lists_saved', function () { kop_maybe_status_columns_to_varchar(false); });
}
