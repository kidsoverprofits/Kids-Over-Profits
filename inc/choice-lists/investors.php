<?php
/**
 * Dropdown Lists: investors (group "Investors"; inc/investors.php).
 *
 * Plain lists, stored as written on {prefix}kop_investors.kind and
 * {prefix}kop_investor_links.role. A role other than "Investor" is printed
 * on the pages in lower case ("majority owner of Sequel TSI, from 2017").
 *
 * No WordPress needed here.
 */

kop_choice_register('investor-kinds', array(
    'label' => 'Investor kinds',
    'group' => 'Investors',
    'plain' => true,
    'where' => 'The Kind dropdown on KOP Tools > Investors.',
    'defaults' => array(
        'Private equity firm', 'Venture capital firm', 'Royalty or specialty finance', 'Lender', 'Family office',
        'Individual', 'Public company', 'Other',
    ),
));

kop_choice_register('investor-roles', array(
    'label' => 'Investor roles',
    'group' => 'Investors',
    'plain' => true,
    'where' => 'The Role dropdown of each stake on KOP Tools > Investors, printed in the Investors line of company and facility pages.',
    'note' => 'Write a role so it reads after the investor\'s name: "majority owner", "lender or financing".',
    'defaults' => array(
        'Majority owner', 'Minority investor', 'Preferred equity or royalty', 'Lender or financing', 'Investor',
    ),
));
