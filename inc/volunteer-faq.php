<?php
/**
 * Brief FAQ: the questions volunteers keep asking about a queue, answered once
 * and shown on every project brief of that queue ("Questions others asked" on
 * /volunteer-review/), so the same Not sure stops coming back.
 *
 * One list per queue in the option kop_volf ({source: [entry]}); an entry is
 * {id, q, a, from, live, by, at}. 'from' is for the admin only: the volunteers'
 * notes and the decided items the answer rests on, and any that go the other
 * way. Volunteers see live entries only (kop_volf_live(), carried by
 * kop_volp_public()).
 *
 * kop_volf_seed() holds drafts written from the Not sure notes of the
 * 2026-10-21 audit (docs/plan 4.4 item 8k). A seed is added once, as a draft:
 * nothing reaches a volunteer until an admin publishes it at KOP Tools >
 * Volunteer Reviewers > Brief FAQ (?faq=<source>). An edited or deleted seed
 * stays that way.
 *
 * Tested by scripts/test-volunteer-projects.php ("Brief FAQ").
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The drafts, by queue. 'from' names the items (inspection_highlights ids) so
 * the admin can see what each answer rests on before publishing it.
 */
function kop_volf_seed() {
    return array(
        'inspection-highlights' => array(
            array(
                'id' => 'failed-to-investigate',
                'q'  => 'The finding is that the facility did not report or investigate abuse, or got in the way of an investigation. Is that paperwork?',
                'a'  => 'No: recommend approve when the report or investigation was about harm to a young person (abuse, sexual misconduct, an injury). Leaving it '
                    . 'uninvestigated left them at risk. Daybreak Youth Services was shut down for obstructing an investigation into sexual abuse: approve. '
                    . 'Recommend reject when the late or incomplete report was about something else, such as a fight between two clients.',
                'from' => 'Asked by greenfionn42 (10 Not sure) and mission_eris. Approved: #2967 (allegations never investigated), #3257-3263 (Daybreak, '
                    . 'obstructed an investigation), #1927 (late report of a sexual assault). Rejected: #2484, #2485 (a fight between clients, not fully reported). '
                    . 'Goes the other way: #2830 (a staff member slammed a child into a seat; the finding is about not calling Childline) was rejected.',
            ),
            array(
                'id' => 'allegations',
                'q'  => 'The finding says "allegations" of abuse, and does not say they were proven. Approve?',
                'a'  => 'Recommend approve when the inspector writes that staff did it, or cites the facility over the allegation. The state does not have to have '
                    . 'proven it: the site shows what the inspector wrote, in the report\'s words. Recommend reject when the finding only mentions an allegation in passing '
                    . 'and is really about a form.',
                'from' => 'Asked by mission_eris on #2913 ("allegations of suspected abuse"); #2913 ("Staff Member B sexually abused Child #3 and #4") is approved. '
                    . 'Prestigious_Dot134 on #3294: "Allegations were reported as substantiated" approved. Goes the other way: #2830 rejected.',
            ),
            array(
                'id' => 'client-background',
                'q'  => 'The excerpt is the young person\'s history from before they came (an admission assessment, abuse at home). Approve?',
                'a'  => 'Recommend reject and say "background" in the note: it is not something the program did. If the same finding also describes harm at the '
                    . 'program, recommend approve and say which part.',
                'from' => 'Asked by awfullysweetpotate on #1865 and #1868 (Fresh Start Home for Children); both still wait for you.',
            ),
            array(
                'id' => 'medical-care',
                'q'  => 'Staff would not call an ambulance or take a young person for medical care, and dealt with it in-house. Approve?',
                'a'  => 'Recommend approve: keeping a young person from medical care they needed is neglect. Say in the note what care they did not get.',
                'from' => 'Prestigious_Dot134 asked this on project 13 (HUMAN CARE SERVICES, message 1, 2026-10-21, not answered yet). Publishing this answers it on '
                    . 'the brief; still reply to the message. Related: #2480 (a cut not stitched in time) and #2834 (self-harm, maybe not taken to hospital), both waiting.',
            ),
            array(
                'id' => 'which-rule',
                'q'  => 'The finding says a policy was not followed, but not which part, or how bad it was. How do I decide?',
                'a'  => 'Look at what happened, not at the rule: if a young person was hurt or put at risk (pills left where a child took them, an injury in a '
                    . 'restraint), recommend approve. If only a form or a written policy is missing, recommend reject.',
                'from' => 'Asked by Seelie on #620 and Prestigious_Dot134 on #2505; both approved.',
            ),
            array(
                'id' => 'hard-to-read',
                'q'  => 'The text is a bad scan, full of spelling errors, or I cannot follow what happened.',
                'a'  => 'Open "Read full report" if the card has it. If it is still unclear, choose Not sure and say what you could make out. Do not guess.',
                'from' => 'mission_eris on #2872 (rejected) and #2834 (waiting); ThislsAllTheoretical on #1857 (the summary was unreadable on a phone).',
            ),
        ),
        'scraper-finds' => array(
            array(
                'id' => 'paywall',
                'q'  => 'The article is behind a paywall. What do I say?',
                'a'  => 'Choose Not sure, write "paywall" in the note, and say whether the headline is about a program or the industry. An admin can read it another way.',
                'from' => 'mission_eris on two Star-Herald and Daily Freeman articles (waiting).',
            ),
            array(
                'id' => 'fiction',
                'q'  => 'It is about a film, show or book set in a program, not a real one.',
                'a'  => 'Recommend reject unless it names a real program or real people from one; say "fiction" in the note.',
                'from' => 'mission_eris on an IMDb page about a film with transporters (waiting).',
            ),
        ),
    );
}

/** Every queue's list, with any seed not added before put in as a draft. */
function kop_volf_all() {
    $all = get_option('kop_volf', null);
    $all = is_array($all) ? $all : array();
    $seeded = (array) get_option('kop_volf_seeded', array());
    $added = false;
    foreach (kop_volf_seed() as $source => $entries) {
        foreach ($entries as $e) {
            $tag = $source . '/' . $e['id'];
            if (in_array($tag, $seeded, true)) continue;
            $all[$source][] = $e + array('live' => false, 'by' => 'seed', 'at' => current_time('mysql', true));
            $seeded[] = $tag;
            $added = true;
        }
    }
    if ($added) {
        update_option('kop_volf', $all, false);
        update_option('kop_volf_seeded', $seeded, false);
    }
    return $all;
}

function kop_volf_list($source) {
    return array_values((array) (kop_volf_all()[(string) $source] ?? array()));
}

/** What the volunteer page shows on a brief: [{q, a}], live entries only. */
function kop_volf_live($source) {
    $out = array();
    foreach (kop_volf_list($source) as $e) if (!empty($e['live'])) $out[] = array('q' => (string) $e['q'], 'a' => (string) $e['a']);
    return $out;
}

/** Add or change one entry. $in: q, a, live; $id '' = a new one. Returns the id. */
function kop_volf_save($source, $id, array $in) {
    $source = sanitize_key((string) $source);
    $q = trim(mb_substr((string) ($in['q'] ?? ''), 0, 500));
    $a = trim(mb_substr((string) ($in['a'] ?? ''), 0, 2000));
    if ($q === '' || $a === '') throw new RuntimeException('Give the question and its answer.');
    $all = kop_volf_all();
    $list = (array) ($all[$source] ?? array());
    $u = wp_get_current_user();
    $row = array('q' => $q, 'a' => $a, 'live' => !empty($in['live']), 'by' => $u && $u->exists() ? $u->user_login : '', 'at' => current_time('mysql', true));
    $found = false;
    foreach ($list as $i => $e) {
        if ((string) $e['id'] !== (string) $id || $id === '') continue;
        $list[$i] = $row + $e;
        $found = true;
    }
    if (!$found) {
        if ($id !== '') throw new RuntimeException('That question is gone; add it again.');
        $id = substr(md5($q . microtime()), 0, 10);
        $list[] = $row + array('id' => $id, 'from' => '');
    }
    $all[$source] = array_values($list);
    update_option('kop_volf', $all, false);
    return $id;
}

function kop_volf_delete($source, $id) {
    $all = kop_volf_all();
    $source = sanitize_key((string) $source);
    $all[$source] = array_values(array_filter((array) ($all[$source] ?? array()), function ($e) use ($id) { return (string) $e['id'] !== (string) $id; }));
    update_option('kop_volf', $all, false);
}

/* ---- KOP Tools > Volunteer Reviewers > Brief FAQ ---------------------------------------- */

function kop_volf_admin_url($source) {
    return add_query_arg('faq', rawurlencode((string) $source), admin_url('admin.php?page=kop-volunteer-reviewers'));
}

/** Handle a FAQ form post (called from kop_volp_admin_post()): [notice, error, null] or null when not ours. */
function kop_volf_admin_post($do, array $in) {
    if (!in_array($do, array('faq_save', 'faq_delete'), true)) return null;
    $source = sanitize_key((string) ($in['kop_volf_source'] ?? ''));
    $id = sanitize_key((string) ($in['kop_volf_id'] ?? ''));
    if ($do === 'faq_delete') {
        kop_volf_delete($source, $id);
        return array('Deleted.', '', null);
    }
    $live = ($in['kop_volf_live'] ?? '') === '1';
    kop_volf_save($source, $id, array('q' => $in['kop_volf_q'] ?? '', 'a' => $in['kop_volf_a'] ?? '', 'live' => $live));
    return array($live ? 'Published: volunteers see it on every brief of this queue.' : 'Saved as a draft: volunteers do not see it.', '', null);
}

/** One line per queue with a FAQ, for the Projects section: drafts waiting first. */
function kop_volf_admin_summary() {
    $all = kop_volf_all();
    $labels = kop_rinbox_sources();
    $parts = array();
    foreach ($all as $source => $list) {
        if (!$list) continue;
        $live = count(array_filter($list, function ($e) { return !empty($e['live']); }));
        $draft = count($list) - $live;
        $title = (string) (kop_volp_kinds()[$source]['title'] ?? ($labels[$source]['label'] ?? $source));
        $parts[] = '<a href="' . esc_url(kop_volf_admin_url($source)) . '">' . esc_html($title) . '</a> (' . $live . ' live'
            . ($draft ? ', <strong>' . $draft . ' ' . ($draft === 1 ? 'draft waits' : 'drafts wait') . ' for you</strong>' : '') . ')';
    }
    if (!$parts) return;
    echo '<p style="max-width:900px"><strong>Brief FAQ</strong> (shown on every brief of the queue as "Questions others asked"): ' . implode(' · ', $parts) . '</p>';
}

/** The page for one queue's FAQ: each entry editable, Publish / Save as draft / Delete, and a blank one to add. */
function kop_volf_admin_page($source, $nonce) {
    $source = sanitize_key((string) $source);
    $base = admin_url('admin.php?page=kop-volunteer-reviewers');
    $title = (string) (kop_volp_kinds()[$source]['title'] ?? (kop_rinbox_sources()[$source]['label'] ?? $source));
    ?>
    <p><a href="<?php echo esc_url($base . '#projects'); ?>">&larr; All projects</a></p>
    <h2>Brief FAQ: <?php echo esc_html($title); ?></h2>
    <p style="max-width:760px">Live answers show on every project brief of this queue as <em>Questions others asked</em>. Drafts are for you only:
        each says what it rests on, so check it against your own calls before publishing.</p>
    <?php
    $list = kop_volf_list($source);
    usort($list, function ($a, $b) { return (int) !empty($a['live']) <=> (int) !empty($b['live']); });
    foreach (array_merge($list, array(array('id' => '', 'q' => '', 'a' => '', 'from' => '', 'live' => false))) as $e) {
        $new = $e['id'] === '';
        $live = !empty($e['live']);
        ?>
        <form method="post" style="max-width:900px;margin:0 0 18px;padding:12px 14px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $live ? '#00a32a' : ($new ? '#c3c4c7' : '#dba617'); ?>">
            <?php echo $nonce; ?><input type="hidden" name="kop_volp_do" value="faq_save">
            <input type="hidden" name="kop_volf_source" value="<?php echo esc_attr($source); ?>"><input type="hidden" name="kop_volf_id" value="<?php echo esc_attr($e['id']); ?>">
            <p style="margin-top:0"><strong><?php echo $new ? 'Add a question' : ($live ? 'Live' : 'Draft: volunteers do not see it yet'); ?></strong></p>
            <p><label>Question<br><textarea name="kop_volf_q" rows="2" style="width:100%"><?php echo esc_textarea((string) $e['q']); ?></textarea></label></p>
            <p><label>Answer<br><textarea name="kop_volf_a" rows="3" style="width:100%"><?php echo esc_textarea((string) $e['a']); ?></textarea></label></p>
            <?php if ((string) ($e['from'] ?? '') !== '') : ?>
                <p class="description" style="margin-top:0"><strong>Rests on:</strong> <?php echo esc_html((string) $e['from']); ?></p>
            <?php endif; ?>
            <p style="margin-bottom:0">
                <button type="submit" class="button button-primary" name="kop_volf_live" value="1"><?php echo $live ? 'Save' : 'Publish'; ?></button>
                <button type="submit" class="button" name="kop_volf_live" value="0"><?php echo $live ? 'Unpublish' : 'Save as draft'; ?></button>
                <?php if (!$new) : ?>
                    <button type="submit" class="button-link" name="kop_volp_do" value="faq_delete" style="margin-left:8px">Delete</button>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }
}
