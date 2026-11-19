<?php
/**
 * The contact form's "What is this about?" choices (inc/contact-messages.php, /contact/).
 * The type is the subject line of the email and the Type filter at KOP Tools > Contact Messages.
 * No WordPress needed: scripts/test-contact-messages.php reads it.
 */

kop_choice_register('contact-types', array(
    'label'    => 'Contact message types',
    'group'    => 'Site forms',
    'source'   => 'kop_contact_types',
    'ordered'  => true,
    'where'    => 'The "What is this about?" choices on the /contact/ form, the subject line of the email each message sends, and the Type filter at KOP Tools > Contact Messages.',
    'defaults' => array(
        'press'         => 'Press or researcher',
        'survivor'      => 'Survivor or family',
        'records'       => 'Records request',
        'collaboration' => 'Collaboration',
        'other'         => 'Other',
    ),
));
