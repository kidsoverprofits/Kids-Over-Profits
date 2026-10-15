<?php
/**
 * Dropdown Lists: the data forms' choices (group "Data form choices").
 *
 * Plain lists (stored as written): facility types, staff roles, accreditations,
 * certifications. The form's autocomplete (js/autocomplete.js) suggests them,
 * and the facility types are also what the news scan may give a new record
 * (kop_facdisc_types()).
 *
 * Checkbox lists (keyed; the value is the key the facility record stores, e.g.
 * treatmentTypes.hasABA = true): treatment types, philosophies, critical
 * incidents. Both data forms draw the boxes from them
 * (kop_df_render_checklist()), the facility page and the form's report print
 * their labels. A choice added on the screen gets a key like
 * "breathing_restraint" (stored as treatmentTypes.breathing_restraint = true;
 * the record maps keep any key). A key starting with "custom" or "_" is never
 * drawn: those hold the forms' free-text entries.
 *
 * Marketer services and trade organization activities: the same, for the
 * Marketers and Trade Organizations tabs (api/lib-marketers.php,
 * api/lib-tradeorgs.php).
 *
 * No WordPress needed here.
 */

kop_choice_register('facility-types', array(
    'label' => 'Facility types',
    'group' => 'Data form choices',
    'plain' => true,
    'js' => true,
    'source' => 'kop_facdisc_types',
    'where' => 'Suggestions under "Facility type" on the data forms, the types the news scan may give a facility it creates, and the Type dropdowns on Facilities from News and other review cards.',
    'note' => 'Write a type the way records already spell it (for example "Residential Treatment Center", not "RTC"): the forms turn a few short forms into these spellings.',
    'defaults' => array(
        'Residential Treatment Center', 'Psychiatric Residential Treatment Facility', 'Therapeutic Boarding School',
        'Wilderness Therapy', 'Long-term Wilderness Program', 'Adventure Therapy Program', 'Boot Camp',
        'Behavior Modification Program', 'Therapeutic Group Home', 'Group Home', 'Transitional Living Program',
        'Substance Abuse Treatment', 'Eating Disorder Treatment Center', 'Psychiatric Hospital', 'Maternity Home',
        'Fundamentalist Religious Home', 'Christian Boarding School', 'Specialty Boarding School',
        'Emotional Growth Boarding School', 'Ranch Program', 'Military-Style Academy',
        'Qualified Residential Treatment Program (QRTP)', 'Juvenile Detention Facility', 'Juvenile Correctional Facility',
        'Juvenile Justice RTC', 'Other',
    ),
));

kop_choice_register('staff-roles', array(
    'label' => 'Staff roles',
    'group' => 'Data form choices',
    'plain' => true,
    'js' => true,
    'where' => 'Suggestions offered when typing a staff member\'s role on the data forms. People can still type any role.',
    'defaults' => array(
        'Administrator', 'Director', 'CEO', 'President', 'Counselor', 'Therapist', 'Teacher', 'Nurse',
        'Medical Director', 'Case Manager', 'Supervisor', 'Staff', 'Founder', 'Key Executive', 'Board Member',
        'Program Director', 'Clinical Director', 'Admissions Director', 'Other',
    ),
));

kop_choice_register('accreditations', array(
    'label' => 'Accreditations',
    'group' => 'Data form choices',
    'plain' => true,
    'js' => true,
    'where' => 'Suggestions offered when typing an accreditation on the data forms. People can still type any name.',
    'defaults' => array(
        'Joint Commission (JCAHO)', 'CARF International', 'Council on Accreditation (COA)',
        'National Association of Therapeutic Schools and Programs (NATSAP)', 'Association of Experiential Education (AEE)',
        'Outdoor Behavioral Healthcare Council (OBH)', 'NATSAP', 'NIPDB', 'Middle States Association', 'AdvancED',
        'Cognia', 'WASC', 'NAEYC', 'State Licensed', 'Medicare Certified', 'Medicaid Certified',
    ),
));

kop_choice_register('certifications', array(
    'label' => 'Staff certifications',
    'group' => 'Data form choices',
    'plain' => true,
    'js' => true,
    'where' => 'Suggestions offered when typing a certification on the data forms. People can still type any name.',
    'defaults' => array(
        'Licensed Clinical Social Worker (LCSW)', 'Licensed Professional Counselor (LPC)',
        'Licensed Marriage and Family Therapist (LMFT)', 'Certified Addiction Counselor (CAC)',
        'Board Certified Behavior Analyst (BCBA)', 'Registered Nurse (RN)', 'Licensed Practical Nurse (LPN)',
        'Certified Nursing Assistant (CNA)', 'CPR Certified', 'First Aid Certified', 'Trauma-Informed Care Certified',
        'Crisis Intervention Certified',
    ),
));

$kop_df_checklist_note = 'A new choice becomes a tick box on both data forms; a ticked box is saved on the facility record and printed on its page. Hiding a choice takes the box off the forms; records that already have it ticked keep it. Do not start a label with the word "Custom".';

kop_choice_register('treatment-types', array(
    'label' => 'Treatment types',
    'group' => 'Data form choices',
    'js' => true,
    'last' => array(),
    'where' => 'The tick boxes under "Standard Treatment Types" on both data forms, and the treatment list on facility pages.',
    'note' => $kop_df_checklist_note,
    'defaults' => array(
        'hasABA'                    => 'Applied Behavior Analysis',
        'hasEquineTherapy'          => 'Equine Therapy',
        'hasWorkTherapy'            => 'Work Therapy',
        'hasWildernessTherapy'      => 'Wilderness Therapy',
        'hasRealityTherapy'         => 'Reality Therapy',
        'hasLGATSeminars'           => 'Large Group Awareness Training Seminars',
        'hasFeedbackHotseatGroups'  => 'Feedback/Hotseat Groups (aka The Game)',
        'hasPrimalScreamTherapy'    => 'Primal Scream Therapy',
        'hasRepressedMemoryTherapy' => 'Repressed Memory Therapy',
        'hasBehaviorModification'   => 'Behavior Modification',
        'hasKetamineTherapy'        => 'Ketamine Therapy',
        'hasExposureTherapy'        => 'Exposure Therapy',
        'hasUnlicensedProvider'     => 'Therapy with an Unlicensed Provider',
        'hasConversionTherapy'      => 'Sexual Orientation Gender Identity Change Efforts/Conversion/Reparative Therapy (SOGICE)',
        'hasAttachmentTherapy'      => 'Attachment Therapy',
        'hasRebirthingTherapy'      => 'Rebirthing Therapy',
        'hasTappingTherapy'         => 'Tapping/Thought Field Therapy (TFT)',
        'hasPsychoanalysis'         => 'Psychoanalysis',
        'hasEMDR'                   => 'Eye Movement Desensitization and Reprocessing (EMDR)',
        'hasHypnosis'               => 'Hypnosis',
    ),
    'max_len' => 120,
));

kop_choice_register('philosophies', array(
    'label' => 'Philosophies',
    'group' => 'Data form choices',
    'js' => true,
    'last' => array(),
    'where' => 'The tick boxes under "Philosophy" on both data forms, and the philosophy list on facility pages.',
    'note' => $kop_df_checklist_note,
    'defaults' => array(
        'hasPositivePeerCulture'    => 'Positive Peer Culture',
        'has12Steps'                => '12 Steps',
        'hasFundamentalistBaptist'  => 'Fundamentalist Baptist',
        'hasPentecostal'            => 'Pentecostal',
        'hasScientology'            => 'Scientology',
        'hasTherapeuticCommunity'   => 'Therapeutic Community',
        'hasWildernessRoad'         => 'Wilderness Road',
        'hasPsychoanalytic'         => 'Psychoanalytic',
        'hasLawOfAttraction'        => 'Law of Attraction',
        'hasHumanPotentialMovement' => 'Human Potential Movement',
    ),
));

kop_choice_register('critical-incidents', array(
    'label' => 'Critical incidents',
    'group' => 'Data form choices',
    'js' => true,
    'last' => array(),
    'where' => 'The tick boxes under "Critical Incidents" on both data forms, and the incident list on facility pages.',
    'note' => $kop_df_checklist_note,
    'defaults' => array(
        'hasDeaths'                  => 'Deaths',
        'hasStaffArrests'            => 'Staff Arrests',
        'hasStudentHospitalizations' => 'Student Hospitalizations',
        'hasRiots'                   => 'Riots',
    ),
));
unset($kop_df_checklist_note);

kop_choice_register('marketer-services', array(
    'label' => 'Marketer services',
    'group' => 'Data form choices',
    'js' => true,
    'last' => array(),
    'source' => 'kop_marketer_service_labels',
    'where' => 'The tick boxes under "What they do for programs" on the data forms\' Marketers tab, and the same list on /marketing-companies/.',
    'note' => 'Hiding a service takes its box off the form; companies that already have it ticked keep it.',
    'defaults' => array(
        'websiteDesign'        => 'Builds program websites',
        'seo'                  => 'Search engine optimization',
        'cityPages'            => 'Pages for many towns a program is not in',
        'directories'          => 'Runs "independent" directories or ranking sites',
        'helpLine'             => 'Help lines, admissions or placement services',
        'leadGeneration'       => 'Sells or trades parents\' inquiries',
        'aiInstructions'       => 'Instructions for AI chatbots (llms.txt, hidden page text)',
        'reputationManagement' => 'Reputation management (reviews, answering critics)',
        'advertising'          => 'Paid advertising (search, social media)',
        'publicRelations'      => 'Public relations and press',
    ),
));

kop_choice_register('tradeorg-activities', array(
    'label' => 'Trade organization activities',
    'group' => 'Data form choices',
    'js' => true,
    'last' => array(),
    'source' => 'kop_tradeorg_activity_labels',
    'where' => 'The tick boxes under "What it does" on the data forms\' Trade Organizations tab, and the same list on /trade-organizations/.',
    'note' => 'Hiding an activity takes its box off the form; organizations that already have it ticked keep it.',
    'defaults' => array(
        'standards'       => 'Sets standards or ethical principles for members',
        'accreditation'   => 'Accredits, certifies or inspects members',
        'lobbying'        => 'Lobbies lawmakers or works against regulation',
        'publicRelations' => 'Public relations for its members or the industry',
        'research'        => 'Funds or publishes research',
        'conferences'     => 'Conferences, training and networking',
        'referralNetwork' => 'Connects programs with consultants or other referral sources',
        'memberSeal'      => 'A logo or listing members show parents',
    ),
));

// ---------------------------------------------------------------------------
// The facility checklists (treatment types, philosophies, critical incidents).

if (!function_exists('kop_df_checklist_lists')) {
    /** Facility record section => its list. */
    function kop_df_checklist_lists() {
        return array(
            'treatmentTypes'    => 'treatment-types',
            'philosophy'        => 'philosophies',
            'criticalIncidents' => 'critical-incidents',
        );
    }
}

if (!function_exists('kop_df_checklist_reserved')) {
    /** Keys the record maps use for other things (free-text entries): never a tick box. */
    function kop_df_checklist_reserved($value) {
        $value = (string) $value;
        return $value === '' || $value[0] === '_' || stripos($value, 'custom') === 0;
    }
}

if (!function_exists('kop_df_checklist_choices')) {
    /** A section's tick boxes: key => label ('offered' for the forms, 'all' for labels). */
    function kop_df_checklist_choices($section, $mode = 'offered') {
        $lists = kop_df_checklist_lists();
        if (!isset($lists[$section])) return array();
        $out = array();
        foreach (kop_choices($lists[$section], null, $mode) as $value => $c) {
            if (kop_df_checklist_reserved($value)) continue;
            $out[(string) $value] = kop_choice_label($c);
        }
        return $out;
    }
}

if (!function_exists('kop_df_checkbox_ids')) {
    /** The element ids the built-in boxes have always had (notes and help text point at some). */
    function kop_df_checkbox_ids() {
        return array(
            'treatmentTypes.hasABA' => 'has-aba', 'treatmentTypes.hasEquineTherapy' => 'has-equine-therapy',
            'treatmentTypes.hasWorkTherapy' => 'has-work-therapy', 'treatmentTypes.hasWildernessTherapy' => 'has-wilderness-therapy',
            'treatmentTypes.hasRealityTherapy' => 'has-reality-therapy', 'treatmentTypes.hasLGATSeminars' => 'has-lgat',
            'treatmentTypes.hasFeedbackHotseatGroups' => 'has-feedback-hotseat', 'treatmentTypes.hasPrimalScreamTherapy' => 'has-primal-scream',
            'treatmentTypes.hasRepressedMemoryTherapy' => 'has-repressed-memory', 'treatmentTypes.hasBehaviorModification' => 'has-behavior-mod',
            'treatmentTypes.hasKetamineTherapy' => 'has-ketamine', 'treatmentTypes.hasExposureTherapy' => 'has-exposure',
            'treatmentTypes.hasUnlicensedProvider' => 'has-unlicensed', 'treatmentTypes.hasConversionTherapy' => 'has-conversion',
            'treatmentTypes.hasAttachmentTherapy' => 'has-attachment', 'treatmentTypes.hasRebirthingTherapy' => 'has-rebirthing',
            'treatmentTypes.hasTappingTherapy' => 'has-tapping', 'treatmentTypes.hasPsychoanalysis' => 'has-psychoanalysis',
            'treatmentTypes.hasEMDR' => 'has-emdr-treatment', 'treatmentTypes.hasHypnosis' => 'has-hypnosis',
            'philosophy.hasPositivePeerCulture' => 'has-ppc', 'philosophy.has12Steps' => 'has-12-steps',
            'philosophy.hasFundamentalistBaptist' => 'has-baptist', 'philosophy.hasPentecostal' => 'has-pentecostal',
            'philosophy.hasScientology' => 'has-scientology', 'philosophy.hasTherapeuticCommunity' => 'has-tc',
            'philosophy.hasWildernessRoad' => 'has-wilderness-road', 'philosophy.hasPsychoanalytic' => 'has-psychoanalytic',
            'philosophy.hasLawOfAttraction' => 'has-loa', 'philosophy.hasHumanPotentialMovement' => 'has-hpm',
            'criticalIncidents.hasDeaths' => 'has-deaths', 'criticalIncidents.hasStaffArrests' => 'has-staff-arrests',
            'criticalIncidents.hasStudentHospitalizations' => 'has-hospitalizations', 'criticalIncidents.hasRiots' => 'has-riots',
        );
    }
}

if (!function_exists('kop_df_render_checklist')) {
    /**
     * The tick boxes of one section, in the markup the forms have always had
     * (class facility-checkbox, data-field = the record path, a note key).
     */
    function kop_df_render_checklist($section, $indent = '') {
        $ids = kop_df_checkbox_ids();
        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $html = '';
        foreach (kop_df_checklist_choices($section, 'offered') as $value => $label) {
            $path = $section . '.' . $value;
            $id = $ids[$path] ?? ('kop-check-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $section . '-' . $value)));
            $html .= $indent . '<div class="checkbox-group">' . "\n"
                . $indent . '    <input type="checkbox" class="facility-checkbox" data-field="' . $e($path) . '" data-note-scope="facility" data-note-key="' . $e($path) . '" id="' . $e($id) . '">' . "\n"
                . $indent . '    <label for="' . $e($id) . '">' . $e($label) . '</label>' . "\n"
                . $indent . '</div>' . "\n";
        }
        echo $html;
    }
}

if (!function_exists('kop_df_checklist_label_overrides')) {
    /**
     * "section.key" => label for boxes added or renamed on the Dropdown Lists
     * screen (hidden ones too, so records that have them still read). Pages
     * with their own wording for the built-in boxes keep it otherwise.
     */
    function kop_df_checklist_label_overrides() {
        $out = array();
        foreach (kop_df_checklist_lists() as $section => $key) {
            $def = kop_choice_def($key);
            $defaults = is_array($def['defaults'] ?? null) ? $def['defaults'] : array();
            foreach (kop_df_checklist_choices($section, 'all') as $value => $label) {
                if (!isset($defaults[$value]) || kop_choice_label($defaults[$value]) !== $label) {
                    $out[$section . '.' . $value] = $label;
                }
            }
        }
        return $out;
    }
}
