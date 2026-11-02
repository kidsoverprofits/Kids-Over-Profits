<?php
/**
 * "Use as a practice question" on every decided card of a queue volunteers
 * practise in (the inspection findings first: kop_vol_earnable_sources(), and
 * the queues ticked for volunteers, kop_vol_sources()).
 *
 * The card must carry an admin's own Approve or Reject in Recently done
 * (kop_volpr_answer_of()): that decision is the practice answer. Picking puts
 * the card on option kop_volpr_picked (inc/volunteer-practice.php), whose cards
 * a practice round draws before the ones chosen on their own, even ones the
 * rules would leave out as tricky; "Take out of practice questions" unpicks it.
 * Both log the other as their Undo. The picked cards are listed, with Unpick,
 * at Volunteer Reviewers > Practice cards (?practice=<source>).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Queues whose cards can be practice questions: [key => true]. */
function kop_rinbox_practice_queues() {
    static $memo = null;
    if ($memo !== null) return $memo;
    if (!function_exists('kop_vol_earnable_sources') || !function_exists('kop_volpr_picked')) return $memo = array();
    $memo = array();
    foreach (array_keys(kop_vol_earnable_sources() + kop_vol_sources()) as $k) $memo[(string) $k] = true;
    return $memo;
}

kop_rinbox_addon('practice', array(
    'label'   => 'Use as a practice question',
    'help'    => 'On a card you approved or rejected, in a queue volunteers practise in: new volunteers get it in their practice round, with your decision as the right answer.',
    'actions' => array('practice_pick', 'practice_unpick'),
    'card'    => 'kop_rinbox_practice_action',
    'act'     => 'kop_rinbox_practice_act',
    'never'   => function () {
        return array_values(array_diff(array_keys(kop_rinbox_sources()), array_keys(kop_rinbox_practice_queues())));
    },
    'default' => true,
));

/** The action for a decided card, or null (waiting, not decided by a person, a queue volunteers do not practise in). */
function kop_rinbox_practice_action($source, array $item) {
    if (!isset(kop_rinbox_practice_queues()[$source]) || kop_volpr_waiting($source, $item)) return null;
    $answer = kop_volpr_answer_of($source, (string) $item['key']);
    if ($answer === '') return null;
    if (isset(kop_volpr_picked($source)[(string) $item['key']])) {
        return array('id' => 'practice_unpick', 'label' => 'Take out of practice questions', 'style' => 'neutral',
            'help' => 'This card is one of your practice questions (right answer: ' . ($answer === 'approve' ? 'Approve' : 'Reject') . '). This takes it back out; the rules may still choose it on their own.');
    }
    return array('id' => 'practice_pick', 'label' => 'Use as a practice question', 'style' => 'neutral',
        'help' => 'New volunteers get this card in their practice round, with your ' . ($answer === 'approve' ? 'Approve' : 'Reject') . ' as the right answer. Nothing on the site changes. Undo is in Recently done.');
}

function kop_rinbox_practice_act($source, $key, $action, array $params) {
    if (!in_array($action, array('practice_pick', 'practice_unpick'), true)) return null;
    if (!function_exists('kop_volpr_set_picked')) throw new RuntimeException('Practice rounds are not installed here.');
    if ($action === 'practice_pick' && kop_volpr_answer_of($source, $key) === '') {
        throw new RuntimeException('Approve or reject it first: your decision is the practice answer.');
    }
    kop_volpr_set_picked($source, $key, $action === 'practice_pick');
    return array(
        'message' => $action === 'practice_pick' ? 'Picked as a practice question.' : 'No longer a practice question.',
        'undo'    => array('action' => $action === 'practice_pick' ? 'practice_unpick' : 'practice_pick', 'params' => array()),
    );
}
