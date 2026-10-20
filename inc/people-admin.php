<?php
/**
 * KOP Tools > People: the person ids inc/people.php keeps.
 *
 *   People      every person with an id, searchable by name, other name or id
 *   Person      one person: name, other names, notes and "Became an
 *               educational consultant" (Undo under Recent changes);
 *               relationships with other people (inc/person-relations.php);
 *               past jobs (inc/person-jobs.php); every record that names
 *               them; "Same person as" (merge into another id, for one
 *               person under two names) and "Separate" (give one entry its
 *               own id, for two people with one name)
 *
 * Only plain text is posted, so the host firewall lets it through.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PEOPLE_ADMIN_PAGE', 'kop-people');

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'People', 'People', 'manage_options', KOP_PEOPLE_ADMIN_PAGE, 'kop_people_admin_page');
}, 22);

function kop_people_admin_url($args = array()) {
    return add_query_arg(array_merge(array('page' => KOP_PEOPLE_ADMIN_PAGE), $args), admin_url('admin.php'));
}

/** Handles a posted action; returns a notice, or ''. */
function kop_people_admin_handle() {
    if (empty($_POST['kop_people_do'])) return '';
    check_admin_referer('kop_people');
    $do = sanitize_key(wp_unslash($_POST['kop_people_do']));
    $id = (int) ($_POST['person'] ?? 0);
    if ($do === 'sync') {
        $s = kop_people_sync();
        return sprintf('Synced: %d entries on %d facilities, %d names on other records, %d new ids, %d entries given an id.',
            $s['entries'], $s['facilities'], $s['named'], $s['created'], $s['stamped']);
    }
    if ($do === 'save' && $id > 0) {
        $err = kop_people_save_details($id, wp_unslash($_POST['name'] ?? ''), wp_unslash($_POST['aliases'] ?? ''), wp_unslash($_POST['notes'] ?? ''),
            !empty($_POST['ed_consultant']));
        return $err !== '' ? $err : 'Saved. Undo is under Recent changes in Details.';
    }
    if ($do === 'details_undo' && $id > 0) {
        try {
            kop_people_details_undo((int) ($_POST['n'] ?? 0));
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return 'Undone.';
    }
    // Relationships (inc/person-relations.php): the other person by id or exact name, else as written.
    if (in_array($do, array('rel_add', 'rel_save', 'rel_hide', 'rel_show'), true) && $id > 0 && function_exists('kop_prel_save')) {
        $fields = array();
        foreach (array('kind', 'detail', 'source', 'source_url') as $k) {
            if (isset($_POST[$k])) $fields[$k] = sanitize_text_field(wp_unslash($_POST[$k]));
        }
        if ($do === 'rel_add') {
            $other = trim(sanitize_text_field(wp_unslash($_POST['other'] ?? '')));
            if ($other === '') return 'Name the other person.';
            $found = kop_people_admin_find($other, $id);
            if (is_int($found)) {
                $fields['other_id'] = $found;
            } elseif (ctype_digit(ltrim($other, '#')) || strpos($found, 'More than one') === 0 || $found === 'That is the same person.') {
                return $found;
            } else {
                $fields['other_name'] = $other;
            }
        }
        if ($do === 'rel_hide') $fields = array('hidden' => 1);
        if ($do === 'rel_show') $fields = array('hidden' => 0);
        try {
            kop_prel_save($id, $do === 'rel_add' ? 0 : (int) ($_POST['rel'] ?? 0), $fields);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return array('rel_add' => 'Relationship added', 'rel_save' => 'Relationship saved', 'rel_hide' => 'Relationship taken off', 'rel_show' => 'Relationship put back')[$do]
            . '. Undo is under Relationships.';
    }
    if ($do === 'rel_undo' && $id > 0 && function_exists('kop_prel_undo')) {
        try {
            kop_prel_undo((int) ($_POST['n'] ?? 0));
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return 'Undone.';
    }
    if ($do === 'merge' && $id > 0) {
        $into = kop_people_admin_find(sanitize_text_field(wp_unslash($_POST['into'] ?? '')), $id);
        if (is_string($into)) return $into;
        $user = wp_get_current_user();
        try {
            kop_pmerge_do_merge($into, $id, $user ? $user->user_login : '');
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        wp_safe_redirect(kop_people_admin_url(array('person' => $into, 'merged' => $id)));
        exit;
    }
    // Past jobs, held once on the person (inc/person-jobs.php): every page that lists them shows the change.
    if (in_array($do, array('job_save', 'job_add', 'job_hide', 'job_show'), true) && $id > 0 && function_exists('kop_pjobs_save')) {
        $fields = array();
        foreach (array('place', 'role', 'years', 'source', 'source_url') as $k) {
            if (isset($_POST[$k])) $fields[$k] = sanitize_text_field(wp_unslash($_POST[$k]));
        }
        if ($do === 'job_hide') $fields = array('hidden' => 1);
        if ($do === 'job_show') $fields = array('hidden' => 0);
        try {
            kop_pjobs_save($id, $do === 'job_add' ? 0 : (int) ($_POST['job'] ?? 0), $fields);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return array('job_add' => 'Job added', 'job_save' => 'Job saved', 'job_hide' => 'Job taken off', 'job_show' => 'Job put back')[$do]
            . ': every page that lists this person shows it now. Undo is under Recent changes below.';
    }
    if ($do === 'job_undo' && $id > 0 && function_exists('kop_pjobs_undo')) {
        try {
            kop_pjobs_undo((int) ($_POST['n'] ?? 0));
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return 'Undone.';
    }
    if ($do === 'separate' && $id > 0) {
        $new = kop_people_separate((int) ($_POST['facility'] ?? 0), sanitize_text_field(wp_unslash($_POST['list'] ?? '')), (int) ($_POST['position'] ?? 0));
        if (!$new) return 'That entry is not there any more. Sync and try again.';
        kop_people_sync();
        wp_safe_redirect(kop_people_admin_url(array('person' => $new, 'separated' => $id)));
        exit;
    }
    return '';
}

/**
 * Saves one person's name, other names (one per line), notes and (when not
 * null) the educational consultant tick; '' when saved, else what is wrong.
 * Logged for Undo (kop_people_details_write()). Also used by the Data Manager.
 */
function kop_people_save_details($id, $name, $aliases, $notes, $ed_consultant = null) {
    $id = (int) $id;
    $state = kop_people_load();
    if (!isset($state['rows'][$id])) return 'No such person.';
    $row = $state['rows'][$id];
    $name = trim(sanitize_text_field((string) $name));
    $key = kop_people_key($name);
    if ($key === '') return 'A name needs a first and a last name.';
    $aliases = kop_people_alias_list(sanitize_textarea_field((string) $aliases));
    // The old name stays an other name, so the entries written that way keep this id.
    if ($key !== $row['name_key'] && !in_array($row['name'], $aliases, true)) $aliases[] = $row['name'];
    $aliases = array_values(array_filter($aliases, static function ($a) use ($name) { return strcasecmp($a, $name) !== 0; }));
    $fields = array('name' => $name, 'name_key' => $key, 'aliases' => implode("\n", $aliases), 'notes' => sanitize_textarea_field((string) $notes));
    if ($ed_consultant !== null) $fields['ed_consultant'] = $ed_consultant ? 1 : 0;
    try {
        kop_people_details_write($id, $fields);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

/** Others with the same last name as person $id, in the same pool: the likely "same person" picks. */
function kop_people_similar(array $state, $id) {
    $parts = explode(' ', $state['rows'][$id]['name_key']);
    $last = end($parts);
    $similar = array();
    foreach ($state['rows'] as $o) {
        if ($o['id'] === $id || $o['merged_into'] || $o['pool'] !== $state['rows'][$id]['pool']) continue;
        $op = explode(' ', $o['name_key']);
        if (end($op) === $last) $similar[] = $o;
    }
    return $similar;
}

/** "#12", "12" or an exact name/other name -> a person id, or an error message. */
function kop_people_admin_find($text, $not) {
    $text = trim(ltrim(trim($text), '#'));
    if ($text === '') return 'Type the other person\'s id or name.';
    $state = kop_people_load();
    if (ctype_digit($text)) {
        $id = kop_people_resolve($state, (int) $text);
        if ($id === 0) return 'No person #' . $text . '.';
    } else {
        // Any pool: joining a plaintiff to the staff member they are is done by hand.
        $ids = array();
        foreach (array_keys(kop_people_pools()) as $pool) {
            $ids = array_merge($ids, $state['by_key'][kop_people_pool_key($pool, kop_people_key($text))] ?? array());
        }
        $ids = array_values(array_diff($ids, array((int) $not)));
        if (count($ids) !== 1) return $ids ? 'More than one person has that name: use the id.' : 'Nobody else has that name.';
        $id = $ids[0];
    }
    return $id === kop_people_resolve($state, $not) ? 'That is the same person.' : $id;
}

/** Name and page URL of a record, cached per request. */
function kop_people_admin_record($kind, $id) {
    return kop_people_record_label($kind, $id);
}

/** "facility #12", "lawsuit #3", "node robert-w-lichfield": the record's kind and id. */
function kop_people_admin_what(array $r) {
    if ($r['record_kind'] === 'map') return 'node ' . $r['ref'];
    return (kop_people_kinds()[$r['record_kind']] ?? $r['record_kind']) . ' #' . (int) $r['record_id'];
}

/** Whether "Separate" works on this row: a facility staff entry, the only entries that carry their id. */
function kop_people_admin_can_separate(array $r, $count) {
    return $r['record_kind'] === 'facility' && in_array($r['list'], explode(',', KOP_PEOPLE_LISTS), true) && $count > 1;
}

function kop_people_admin_page() {
    if (!current_user_can('manage_options')) return;
    kop_people_install();
    $notice = kop_people_admin_handle();
    if (isset($_GET['merged'])) $notice = 'Person #' . (int) $_GET['merged'] . ' is now this person; their entries moved here.';
    if (isset($_GET['separated'])) $notice = 'This entry has its own id now, apart from person #' . (int) $_GET['separated'] . '.';
    echo '<div class="wrap kop-people">';
    kop_people_admin_styles();
    if ($notice !== '') echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
    $id = (int) ($_GET['person'] ?? 0);
    if ($id > 0) {
        kop_people_admin_person($id);
    } else {
        kop_people_admin_list();
    }
    echo '</div>';
}

function kop_people_admin_hidden($do, $id = 0) {
    wp_nonce_field('kop_people');
    echo '<input type="hidden" name="kop_people_do" value="' . esc_attr($do) . '">';
    if ($id) echo '<input type="hidden" name="person" value="' . (int) $id . '">';
}

function kop_people_admin_list() {
    $t = kop_people_table('people');
    $r = kop_people_table('roles');
    $q = trim(sanitize_text_field(wp_unslash($_GET['q'] ?? '')));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $per = 100;
    $where = 'p.merged_into IS NULL';
    $params = array();
    $pools = kop_people_pools();
    $pool = sanitize_key(wp_unslash($_GET['pool'] ?? ''));
    $has_pool = kop_people_has_pool();
    if ($has_pool && isset($pools[$pool])) {
        $where .= ' AND p.pool = ?';
        $params[] = $pool;
    }
    $has_cons = kop_people_has_consultant();
    $cons = $has_cons && !empty($_GET['consultant']);
    if ($cons) $where .= ' AND p.ed_consultant = 1';
    if ($q !== '') {
        if (ctype_digit(ltrim($q, '#'))) {
            $where .= ' AND p.id = ?';
            $params[] = (int) ltrim($q, '#');
        } else {
            $where .= ' AND (p.name LIKE ? OR p.aliases LIKE ?)';
            $like = '%' . $GLOBALS['wpdb']->esc_like($q) . '%';
            $params[] = $like;
            $params[] = $like;
        }
    }
    $total = (int) (kop_facility_db_rows("SELECT COUNT(*) AS n FROM {$t} p WHERE {$where}", $params)[0]['n'] ?? 0);
    $rows = kop_facility_db_rows("SELECT p.id, p.name, p.aliases, " . ($has_pool ? 'p.pool' : "'industry' AS pool") . ', ' . ($has_cons ? 'p.ed_consultant' : '0 AS ed_consultant') . ", COUNT(r.person_id) AS n, COUNT(DISTINCT CONCAT(r.record_kind, r.record_id)) AS records
        FROM {$t} p LEFT JOIN {$r} r ON r.person_id = p.id WHERE {$where}
        GROUP BY p.id ORDER BY records DESC, p.name LIMIT {$per} OFFSET " . (($paged - 1) * $per), $params);
    $last = get_option('kop_people_last_sync', array());
    ?>
    <h1>People</h1>
    <p class="kop-people__intro">Everyone the records name has a person id: facility and company staff, people on the network map, owners,
        consultants, transport companies' people, providers' staff, the staff and plaintiffs of published lawsuits, the memorial, young adult
        program staff, the staff of each r/troubledteens wiki entry, and journalists. One person keeps one id across every record, so their
        career can be followed from program to program. New names get an id within the hour. Victims and plaintiffs, and journalists, have
        their own ids: a name there is never joined to a staff member who shares it. A different middle initial is a different person
        (Robert W. and Robert B. Lichfield).
        Open a person to edit their name, other names and notes, tick whether they became an educational consultant, add their
        relationships (family, business partners) and past jobs, join two ids that are one person, or split one name that is two people.</p>
    <form method="post" class="kop-people__bar">
        <?php kop_people_admin_hidden('sync'); ?>
        <span><?php echo $last ? esc_html(sprintf('Last sync %s ago: %d people named %d times.', human_time_diff((int) $last['at']), $total, (int) $last['roles'])) : 'Not synced yet.'; ?></span>
        <button type="submit" class="button">Sync now</button>
    </form>
    <form method="get" class="kop-people__bar">
        <input type="hidden" name="page" value="<?php echo esc_attr(KOP_PEOPLE_ADMIN_PAGE); ?>">
        <label for="kop-people-q" class="screen-reader-text">Search people</label>
        <input type="search" id="kop-people-q" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Name, other name or id">
        <label for="kop-people-pool" class="screen-reader-text">Who</label>
        <select id="kop-people-pool" name="pool">
            <option value="">Everyone</option>
            <?php foreach ($pools as $k => $label) : ?>
                <option value="<?php echo esc_attr($k); ?>"<?php selected($pool, $k); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($has_cons) : ?>
            <label><input type="checkbox" name="consultant" value="1"<?php checked($cons); ?>> Became educational consultants</label>
        <?php endif; ?>
        <button type="submit" class="button">Search</button>
        <span><?php echo esc_html(number_format($total) . ($total === 1 ? ' person' : ' people')); ?></span>
    </form>
    <table class="widefat striped">
        <thead><tr><th>Id</th><th>Name</th><th>Also written</th><th>Who</th><th>Educational consultant</th><th>Records</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p) : ?>
            <tr>
                <td>#<?php echo (int) $p['id']; ?></td>
                <td><a href="<?php echo esc_url(kop_people_admin_url(array('person' => (int) $p['id']))); ?>"><?php echo esc_html($p['name']); ?></a></td>
                <td><?php echo esc_html(implode(', ', kop_people_alias_list($p['aliases']))); ?></td>
                <td><?php echo esc_html($pools[$p['pool']] ?? $pools['industry']); ?></td>
                <td><?php echo !empty($p['ed_consultant']) ? 'Yes' : ''; ?></td>
                <td><?php echo (int) $p['records'] ?: '<span class="kop-people__muted">none</span>'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    if ($total > $per) {
        echo '<p class="kop-people__bar">';
        for ($i = 1; $i <= (int) ceil($total / $per); $i++) {
            echo $i === $paged ? '<strong>' . $i . '</strong> ' : '<a href="' . esc_url(kop_people_admin_url(array('q' => $q, 'pool' => $pool, 'consultant' => $cons ? 1 : null, 'paged' => $i))) . '">' . $i . '</a> ';
        }
        echo '</p>';
    }
}

function kop_people_admin_person($id) {
    $state = kop_people_load();
    $resolved = kop_people_resolve($state, $id);
    if (!isset($state['rows'][$id])) {
        echo '<h1>People</h1><p>No person #' . (int) $id . '. <a href="' . esc_url(kop_people_admin_url()) . '">All people</a></p>';
        return;
    }
    if ($resolved !== $id) {
        echo '<h1>Person #' . (int) $id . '</h1><p>Joined into <a href="' . esc_url(kop_people_admin_url(array('person' => $resolved))) . '">person #' . (int) $resolved . '</a>.</p>';
        return;
    }
    $p = $state['rows'][$id];
    $roles = kop_people_roles_of($id);
    $labels = kop_people_list_labels();
    $similar = kop_people_similar($state, $id);
    ?>
    <p><a href="<?php echo esc_url(kop_people_admin_url()); ?>">All people</a></p>
    <h1><?php echo esc_html($p['name']); ?> <span class="kop-people__muted">person #<?php echo (int) $id; ?>,
        <?php echo esc_html(strtolower(kop_people_pools()[$p['pool']] ?? 'industry')); ?></span></h1>

    <?php kop_people_admin_details($p, $roles); ?>

    <?php kop_people_admin_relations($id); ?>

    <h2>Where they are named</h2>
    <?php if (!$roles) : ?>
        <p class="kop-people__muted">No record names this person now (the entry was renamed or removed).</p>
    <?php else : ?>
    <table class="widefat striped">
        <thead><tr><th>Record</th><th>List</th><th>Written as</th><th>Role</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r) :
            $rec = kop_people_admin_record($r['record_kind'], $r['record_id']);
            $is_map = $r['record_kind'] === 'map';
            if ($is_map && $rec['url'] !== '') $rec['url'] .= '#open=' . rawurlencode((string) $r['ref']);
            $what = kop_people_admin_what($r); ?>
            <tr>
                <td><?php echo $rec['url'] !== '' ? '<a href="' . esc_url($rec['url']) . '">' . esc_html($rec['name']) . '</a>' : esc_html($rec['name']); ?>
                    <span class="kop-people__muted"><?php echo esc_html($what); ?></span></td>
                <td><?php echo esc_html($labels[$r['list']] ?? $r['list']); ?></td>
                <td><?php echo esc_html($r['name']); ?></td>
                <td><?php echo esc_html($r['role']); ?></td>
                <td><?php if (kop_people_admin_can_separate($r, count($roles))) : ?>
                    <form method="post">
                        <?php kop_people_admin_hidden('separate', $id); ?>
                        <input type="hidden" name="facility" value="<?php echo (int) $r['record_id']; ?>">
                        <input type="hidden" name="list" value="<?php echo esc_attr($r['list']); ?>">
                        <input type="hidden" name="position" value="<?php echo (int) $r['position']; ?>">
                        <button type="submit" class="button" title="This entry is someone else with the same name">Separate</button>
                    </form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php kop_people_admin_jobs($id); ?>

    <?php $pod = function_exists('kop_pod_for_person') ? kop_pod_for_person($id) : array();
    if ($pod) : ?>
    <h2>On air</h2>
    <p class="kop-people__muted">What this person said, or what was said about them, on the industry's podcasts, approved at
        <a href="<?php echo esc_url(kop_pod_inbox_url()); ?>">Review inbox &gt; Podcast statements</a>. Machine transcripts: listen before quoting.</p>
    <ul class="kop-people__pod"><?php foreach ($pod as $r) echo kop_pod_line_html($r, false); // Escaped inside. ?></ul>
    <?php endif; ?>

    <h2>Same person as</h2>
    <p>If this person already has another id (a nickname, a maiden name, a misspelling), join this id into it. This id then forwards there.
        Every merge can be undone from <a href="<?php echo esc_url(admin_url('admin.php?page=kop-merge-people')); ?>">Merge People</a>, which also lists the likely pairs.</p>
    <?php foreach ($similar as $o) : ?>
        <form method="post" class="kop-people__bar">
            <?php kop_people_admin_hidden('merge', $id); ?>
            <input type="hidden" name="into" value="<?php echo (int) $o['id']; ?>">
            <button type="submit" class="button">Same person as <?php echo esc_html($o['name']); ?> (#<?php echo (int) $o['id']; ?>)</button>
        </form>
    <?php endforeach; ?>
    <form method="post" class="kop-people__bar">
        <?php kop_people_admin_hidden('merge', $id); ?>
        <label for="kop-people-into">Other person's name or id</label>
        <input type="text" id="kop-people-into" name="into" placeholder="Name or #id">
        <button type="submit" class="button">Same person</button>
    </form>

    <?php
}

function kop_people_admin_styles() {
    ?>
    <style>
        .kop-people__intro { max-width: 60rem; }
        .kop-people__bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 8px 0; }
        .kop-people__muted { color: var(--kop-text-muted, #4A5568); font-weight: normal; font-size: 0.9em; }
        .kop-people table form { margin: 0; }
        .kop-people__pod { max-width: 60rem; }
        .kop-people__pod blockquote { margin: 12px 0 4px; padding-left: 12px; border-left: 3px solid var(--kop-teal, #33A7B5); }
        .kop-people__pod p { margin: 0 0 8px; }
        .kop-people__rels input[type=text], .kop-people__rels input[type=url] { max-width: 100%; }
        .kop-people__log { margin: 8px 0 16px; }
        @media (max-width: 767px) { .kop-people input.regular-text, .kop-people select { width: 100%; } }
    </style>
    <?php
}

/**
 * A person's past jobs (inc/person-jobs.php): held once, shown on every facility and company page that lists
 * them. Each row saves on its own; "Take off" keeps it out (the staff entry's text never brings it back).
 */
function kop_people_admin_jobs($id) {
    if (!function_exists('kop_pjobs_for_person')) return;
    $jobs = kop_pjobs_for_person($id);
    echo '<h2>Past jobs</h2>';
    if ($jobs === null) {
        echo '<p class="kop-people__muted">The jobs table is made on the next admin page load.</p>';
        return;
    }
    echo '<p class="kop-people__muted">Held once for this person: every page that lists them shows these, so a job is added or fixed here, '
        . 'not on each profile. Jobs typed into a staff entry\'s "Past jobs" come in by themselves within the hour. Their job at each program '
        . 'that lists them is under "Where they are named" above.</p>';
    $t = kop_pjobs_table();
    $hidden = kop_facility_db_rows("SELECT * FROM {$t} WHERE person_id = ? AND hidden = 1 ORDER BY id", array((int) $id));
    echo '<table class="widefat striped kop-people__jobs"><thead><tr><th>Role</th><th>Place</th><th>Years</th><th>Source</th><th></th></tr></thead><tbody>';
    $field = function ($name, $value, $label, $size = 0) {
        return '<label class="screen-reader-text">' . esc_html($label) . '</label><input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"'
            . ($size ? ' size="' . (int) $size . '"' : ' class="regular-text"') . ' aria-label="' . esc_attr($label) . '">';
    };
    foreach ($jobs as $j) {
        $form = 'kop-job-' . (int) $j['id'];
        echo '<tr><td>' . $field('role', $j['role'], 'Role') . '</td><td>' . $field('place', $j['place'], 'Place') . '</td><td>' . $field('years', $j['years'], 'Years', 12) . '</td><td>'
            . $field('source', $j['source'], 'Source') . $field('source_url', $j['source_url'], 'Source link') . '</td><td>';
        // One form per row: the inputs above sit in its cells through the form attribute.
        echo '<form method="post" id="' . esc_attr($form) . '" class="kop-people__bar">';
        kop_people_admin_hidden('job_save', $id);
        echo '<input type="hidden" name="job" value="' . (int) $j['id'] . '"><button type="submit" class="button button-primary">Save</button></form>';
        echo '<form method="post" class="kop-people__bar">';
        kop_people_admin_hidden('job_hide', $id);
        echo '<input type="hidden" name="job" value="' . (int) $j['id'] . '"><button type="submit" class="button" title="Not shown on any page; Undo puts it back">Take off</button></form>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<script>document.querySelectorAll(".kop-people__jobs tbody tr").forEach(function (tr) { var f = tr.querySelector("form"); '
        . 'if (f) tr.querySelectorAll("input[type=text]").forEach(function (i) { i.setAttribute("form", f.id); }); });</script>';
    if (!$jobs) echo '<p class="kop-people__muted">No past jobs on file.</p>';

    echo '<form method="post" class="kop-people__form"><h3>Add a job</h3>';
    kop_people_admin_hidden('job_add', $id);
    echo '<p><label>Role<br><input type="text" name="role" class="regular-text" placeholder="Program Director"></label> '
        . '<label>Place<br><input type="text" name="place" class="regular-text" placeholder="A program, company or anything else" required></label> '
        . '<label>Years<br><input type="text" name="years" size="12" placeholder="2008-2011"></label></p>'
        . '<p><label>Source<br><input type="text" name="source" class="regular-text" placeholder="Woodbury Reports, May 2007"></label> '
        . '<label>Source link<br><input type="url" name="source_url" class="regular-text" placeholder="https://"></label></p>'
        . '<p><button type="submit" class="button">Add the job</button></p></form>';

    if ($hidden) {
        echo '<details><summary>Taken off (' . count($hidden) . ')</summary><ul>';
        foreach ($hidden as $j) {
            echo '<li>' . esc_html(trim($j['role'] . ' - ' . $j['place'] . ($j['years'] !== '' ? ' (' . $j['years'] . ')' : ''), ' -')) . ' <form method="post" style="display:inline">';
            kop_people_admin_hidden('job_show', $id);
            echo '<input type="hidden" name="job" value="' . (int) $j['id'] . '"><button type="submit" class="button button-small">Put back</button></form></li>';
        }
        echo '</ul></details>';
    }

    $log = array_slice(kop_pjobs_log_for($id), 0, 10);
    if ($log) {
        echo '<h3>Recent changes</h3><ul>';
        foreach ($log as $l) {
            $a = $l['after'] ?: $l['before'];
            $what = $l['before'] === null ? 'Added' : (!empty($l['after']['hidden']) && empty($l['before']['hidden']) ? 'Took off' : (empty($l['after']['hidden']) && !empty($l['before']['hidden']) ? 'Put back' : 'Changed'));
            echo '<li>' . esc_html($what . ': ' . trim(($a['role'] ?? '') . ' - ' . ($a['place'] ?? ''), ' -') . ' (' . $l['at'] . ' UTC' . ($l['by'] !== '' ? ', ' . $l['by'] : '') . ')') . ' ';
            if (empty($l['undone'])) {
                echo '<form method="post" style="display:inline">';
                kop_people_admin_hidden('job_undo', $id);
                echo '<input type="hidden" name="n" value="' . (int) $l['n'] . '"><button type="submit" class="button button-small">Undo</button></form>';
            } else {
                echo '<span class="kop-people__muted">undone</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

/** The editor: name, other names, the educational consultant tick, notes; and their recent changes with Undo. */
function kop_people_admin_details(array $p, array $roles) {
    $id = (int) $p['id'];
    $has_cons = kop_people_has_consultant();
    // A consultant record that names them already: the tick is still the owner's call.
    $on_cons = array();
    foreach ($roles as $r) {
        if ($r['record_kind'] !== 'consultant') continue;
        $rec = kop_people_admin_record('consultant', $r['record_id']);
        $on_cons[(int) $r['record_id']] = $rec['name'];
    }
    ?>
    <h2>Details</h2>
    <form method="post" class="kop-people__form">
        <?php kop_people_admin_hidden('save', $id); ?>
        <p><label for="kop-people-name">Name</label><br><input type="text" id="kop-people-name" name="name" value="<?php echo esc_attr($p['name']); ?>" class="regular-text"></p>
        <p><label for="kop-people-aliases">Also written as (one per line)</label><br><textarea id="kop-people-aliases" name="aliases" rows="3" class="large-text"><?php echo esc_textarea($p['aliases']); ?></textarea></p>
        <?php if ($has_cons) : ?>
            <p><label><input type="checkbox" name="ed_consultant" value="1"<?php checked(!empty($p['ed_consultant'])); ?>> Became an educational consultant</label><br>
                <span class="kop-people__muted">Ticked, every facility and company page that lists them ends their career with "Educational consultant".
                <?php if ($on_cons) echo esc_html('Named on ' . (count($on_cons) === 1 ? 'a consultant record' : 'consultant records') . ': ' . implode(', ', $on_cons) . '.'); ?></span></p>
        <?php endif; ?>
        <p><label for="kop-people-notes">Notes (admins only)</label><br><textarea id="kop-people-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea($p['notes']); ?></textarea></p>
        <p><button type="submit" class="button button-primary">Save</button></p>
    </form>
    <?php
    $log = array_slice(kop_people_details_log_for($id), 0, 10);
    if (!$log) return;
    $words = array('name' => 'name', 'aliases' => 'other names', 'notes' => 'notes', 'ed_consultant' => 'educational consultant');
    echo '<details class="kop-people__log"><summary>Recent changes (' . count($log) . ')</summary><ul>';
    foreach ($log as $l) {
        $what = array();
        foreach ((array) $l['after'] as $c => $v) {
            if ($c === 'name_key') continue;
            if ($c === 'ed_consultant') $what[] = $v ? 'ticked educational consultant' : 'unticked educational consultant';
            elseif ($c === 'name') $what[] = 'name ' . $l['before'][$c] . ' -> ' . $v;
            else $what[] = $words[$c] ?? $c;
        }
        echo '<li>' . esc_html(ucfirst(implode(', ', $what)) . ' (' . $l['at'] . ' UTC' . ($l['by'] !== '' ? ', ' . $l['by'] : '') . ')') . ' ';
        if (empty($l['undone'])) {
            echo '<form method="post" style="display:inline">';
            kop_people_admin_hidden('details_undo', $id);
            echo '<input type="hidden" name="n" value="' . (int) $l['n'] . '"><button type="submit" class="button button-small">Undo</button></form>';
        } else {
            echo '<span class="kop-people__muted">undone</span>';
        }
        echo '</li>';
    }
    echo '</ul></details>';
}

/**
 * Relationships (inc/person-relations.php): family, business partners and the rest. One written on the
 * other person shows here too, read the other way round, and is changed on their page.
 */
function kop_people_admin_relations($id) {
    if (!function_exists('kop_prel_for_person')) return;
    $rels = kop_prel_for_person($id);
    echo '<h2>Relationships</h2>';
    if ($rels === null) {
        echo '<p class="kop-people__muted">The relationships table is made on the next admin page load.</p>';
        return;
    }
    echo '<p class="kop-people__muted">Family, business partners and other ties. Give the other person\'s name or #id: someone with an id shows '
        . 'this on their page too ("Parent" here reads "Child" there). Admins only for now.</p>';
    $kinds = kop_prel_kinds();
    $select = function ($value, $form = '') use ($kinds) {
        $out = '<select name="kind" aria-label="Relationship"' . kop_choice_select_attr('person-relations') . ($form !== '' ? ' form="' . esc_attr($form) . '"' : '') . ' required><option value="">Pick one</option>';
        if ($value !== '' && !in_array($value, $kinds, true)) $kinds[] = $value;
        foreach ($kinds as $k) $out .= '<option value="' . esc_attr($k) . '"' . selected($value, $k, false) . '>' . esc_html($k) . '</option>';
        return $out . '</select>';
    };
    $shown = array_values(array_filter($rels, function ($r) { return empty($r['hidden']); }));
    $hidden = array_values(array_filter($rels, function ($r) { return !empty($r['hidden']) && $r['mine']; }));
    if ($shown) {
        echo '<table class="widefat striped kop-people__rels"><thead><tr><th>Person</th><th>Is their</th><th>Detail</th><th>Source</th><th></th></tr></thead><tbody>';
        foreach ($shown as $r) {
            $who = $r['other_resolved'] > 0
                ? '<a href="' . esc_url(kop_people_admin_url(array('person' => (int) $r['other_resolved']))) . '">' . esc_html($r['other_label']) . '</a> <span class="kop-people__muted">#' . (int) $r['other_resolved'] . '</span>'
                : esc_html($r['other_label']) . ' <span class="kop-people__muted">no id</span>';
            echo '<tr><td>' . $who . '</td>';
            if ($r['mine']) {
                $form = 'kop-rel-' . (int) $r['id'];
                echo '<td>' . $select($r['kind'], $form) . '</td>'
                    . '<td><input type="text" name="detail" form="' . esc_attr($form) . '" value="' . esc_attr($r['detail']) . '" class="regular-text" aria-label="Detail" placeholder="Co-founded Sunrise Academy in 1998"></td>'
                    . '<td><input type="text" name="source" form="' . esc_attr($form) . '" value="' . esc_attr($r['source']) . '" aria-label="Source" placeholder="Source">'
                    . '<input type="url" name="source_url" form="' . esc_attr($form) . '" value="' . esc_attr($r['source_url']) . '" aria-label="Source link" placeholder="https://"></td><td>';
                echo '<form method="post" id="' . esc_attr($form) . '" class="kop-people__bar">';
                kop_people_admin_hidden('rel_save', $id);
                echo '<input type="hidden" name="rel" value="' . (int) $r['id'] . '"><button type="submit" class="button button-primary">Save</button></form>';
                echo '<form method="post" class="kop-people__bar">';
                kop_people_admin_hidden('rel_hide', $id);
                echo '<input type="hidden" name="rel" value="' . (int) $r['id'] . '"><button type="submit" class="button">Take off</button></form></td>';
            } else {
                $src = $r['source_url'] !== '' ? '<a href="' . esc_url($r['source_url']) . '">' . esc_html($r['source'] !== '' ? $r['source'] : 'source') . '</a>' : esc_html($r['source']);
                echo '<td>' . esc_html($r['kind']) . '</td><td>' . esc_html($r['detail']) . '</td><td>' . $src . '</td>'
                    . '<td><span class="kop-people__muted">Written on their page</span></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="kop-people__muted">No relationships on file.</p>';
    }

    echo '<form method="post" class="kop-people__form"><h3>Add a relationship</h3>';
    kop_people_admin_hidden('rel_add', $id);
    echo '<p><label>Other person<br><input type="text" name="other" class="regular-text" placeholder="Name or #id" required></label> '
        . '<label>Is this person\'s<br>' . $select('') . '</label> '
        . '<label>Detail<br><input type="text" name="detail" class="regular-text" placeholder="Co-founded Sunrise Academy in 1998"></label></p>'
        . '<p><label>Source<br><input type="text" name="source" class="regular-text" placeholder="Woodbury Reports, May 2007"></label> '
        . '<label>Source link<br><input type="url" name="source_url" class="regular-text" placeholder="https://"></label></p>'
        . '<p class="kop-people__muted">A name that is exactly one person\'s links their id; a name nobody has is kept as written.</p>'
        . '<p><button type="submit" class="button">Add the relationship</button></p></form>';

    if ($hidden) {
        echo '<details><summary>Taken off (' . count($hidden) . ')</summary><ul>';
        foreach ($hidden as $r) {
            echo '<li>' . esc_html($r['other_label'] . ': ' . $r['kind']) . ' <form method="post" style="display:inline">';
            kop_people_admin_hidden('rel_show', $id);
            echo '<input type="hidden" name="rel" value="' . (int) $r['id'] . '"><button type="submit" class="button button-small">Put back</button></form></li>';
        }
        echo '</ul></details>';
    }

    $log = array_slice(kop_prel_log_for($id), 0, 10);
    if ($log) {
        echo '<details class="kop-people__log"><summary>Recent changes (' . count($log) . ')</summary><ul>';
        foreach ($log as $l) {
            $a = $l['after'] ?: $l['before'];
            $what = $l['before'] === null ? 'Added' : (!empty($l['after']['hidden']) && empty($l['before']['hidden']) ? 'Took off' : (empty($l['after']['hidden']) && !empty($l['before']['hidden']) ? 'Put back' : 'Changed'));
            echo '<li>' . esc_html($what . ': ' . ($a['other_name'] ?? '') . ', ' . ($a['kind'] ?? '') . ' (' . $l['at'] . ' UTC' . ($l['by'] !== '' ? ', ' . $l['by'] : '') . ')') . ' ';
            if (empty($l['undone'])) {
                echo '<form method="post" style="display:inline">';
                kop_people_admin_hidden('rel_undo', $id);
                echo '<input type="hidden" name="n" value="' . (int) $l['n'] . '"><button type="submit" class="button button-small">Undo</button></form>';
            } else {
                echo '<span class="kop-people__muted">undone</span>';
            }
            echo '</li>';
        }
        echo '</ul></details>';
    }
}
