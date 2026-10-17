<?php
/**
 * Dropdown Lists: people (group "People"; inc/person-relations.php).
 *
 * Plain list, stored as written on {prefix}kop_person_relations.kind: what the
 * other person is to this one ("Parent" = the other person is this one's
 * parent). The other person's page reads it back the other way round where
 * kop_prel_reverse() knows the pair (Parent / Child); any other kind reads the
 * same from both sides.
 *
 * No WordPress needed here.
 */

kop_choice_register('person-relations', array(
    'label' => 'Relationships between people',
    'group' => 'People',
    'plain' => true,
    'where' => 'The Relationship dropdown under "Relationships" on each person of KOP Tools > People.',
    'note' => 'Write what the other person is to this one: "Parent" means the other person is this one\'s parent.',
    'defaults' => array(
        'Spouse', 'Former spouse', 'Parent', 'Child', 'Sibling', 'Grandparent', 'Grandchild', 'In-law', 'Other relative',
        'Business partner', 'Co-founder', 'Co-owner', 'Employer', 'Employee', 'Mentor', 'Protege', 'Friend', 'Other',
    ),
));
