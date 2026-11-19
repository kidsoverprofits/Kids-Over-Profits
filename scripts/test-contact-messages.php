<?php
/**
 * The contact form's checks (inc/contact-messages.php kop_contact_clean(), the form token, the email's
 * subject), without WordPress, and that the page, the script and the registered types agree.
 *
 *   php -d extension=mbstring scripts/test-contact-messages.php
 */

require dirname(__DIR__) . '/inc/contact-messages.php';

$fail = 0;
$pass = 0;
function check($name, $ok, $info = '') {
    global $fail, $pass;
    if ($ok) { $pass++; return; }
    $fail++;
    echo "FAIL $name" . ($info !== '' ? ": $info" : '') . "\n";
}
function refused(array $in, array $types) {
    try { kop_contact_clean($in, $types); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
}

// The registered types.
$registered = array();
function kop_choice_register($key, array $def) { global $registered; $registered[$key] = $def; }
require dirname(__DIR__) . '/inc/choice-lists/contact.php';
$types = $registered['contact-types']['defaults'] ?? array();
check('the types list is registered', count($types) === 5, (string) count($types));
check('the types are the five asked for', array_values($types) === array('Press or researcher', 'Survivor or family', 'Records request', 'Collaboration', 'Other'));
check('the types keep their order', !empty($registered['contact-types']['ordered']));

$ok = array('type' => 'survivor', 'email' => 'someone@example.org', 'message' => 'I was at this program in 2004.');

$row = kop_contact_clean($ok, $types);
check('a message with no name passes', $row['name'] === '' && $row['type'] === 'survivor');
check('the row has exactly the form fields', array_keys($row) === array('type', 'name', 'email', 'facility', 'message', 'contact_method', 'contact_detail'));
check('an unknown type is refused', strpos(refused(array('type' => 'nope') + $ok, $types), 'about') !== false);
check('no type is refused', refused(array('type' => '') + $ok, $types) !== '');
check('no email is refused', strpos(refused(array('email' => '') + $ok, $types), 'email') !== false);
check('a bad email is refused', refused(array('email' => 'not an email') + $ok, $types) !== '');
check('no message is refused', strpos(refused(array('message' => '  ') + $ok, $types), 'message') !== false);
check('a web address as a name is refused', refused(array('name' => 'buy at https://spam.example') + $ok, $types) !== '');
check('tags are stripped from the message', strpos(kop_contact_clean(array('message' => 'Hello <script>alert(1)</script> there') + $ok, $types)['message'], '<script') === false);
check('tags are stripped from the facility', strpos(kop_contact_clean(array('facility' => '<b>Ranch</b>') + $ok, $types)['facility'], '<') === false);
check('long messages are cut', mb_strlen(kop_contact_clean(array('message' => str_repeat('a', KOP_CONTACT_MAX + 50)) + $ok, $types)['message']) === KOP_CONTACT_MAX);
check('runs of blank lines are squeezed', kop_contact_clean(array('message' => "first line\r\n\r\n\r\n\r\nsecond") + $ok, $types)['message'] === "first line\n\nsecond");
check('an unknown contact method is dropped', kop_contact_clean(array('contact_method' => 'fax') + $ok, $types)['contact_method'] === '');
check('Signal needs a username or number', strpos(refused(array('contact_method' => 'signal') + $ok, $types), 'Signal') !== false);
check('phone needs a number', strpos(refused(array('contact_method' => 'phone') + $ok, $types), 'phone') !== false);
check('Signal with a username passes', kop_contact_clean(array('contact_method' => 'signal', 'contact_detail' => 'kop.01') + $ok, $types)['contact_detail'] === 'kop.01');
check('email as the method keeps no detail', kop_contact_clean(array('contact_method' => 'email', 'contact_detail' => '555') + $ok, $types)['contact_detail'] === '');
check('statuses are the four asked for, plus deleted', array_values(kop_contact_statuses()) === array('New', 'In progress', 'Waiting on them', 'Done', 'Deleted'));

// The form token.
$now = 1800000000;
$tok = kop_contact_started_token($now);
check('a token just made is 0 seconds old', kop_contact_started_age($tok, $now) === 0);
check('a token is read back', kop_contact_started_age($tok, $now + 30) === 30);
check('a forged token is refused', kop_contact_started_age(preg_replace('/\.\w+$/', '.' . str_repeat('0', 20), $tok), $now + 30) === -1);
check('a changed time is refused', kop_contact_started_age(($now - 100) . substr($tok, strpos($tok, '.')), $now) === -1);
check('a token older than a day is refused', kop_contact_started_age($tok, $now + 86401) === -1);
check('a token from the future is refused', kop_contact_started_age($tok, $now - 10) === -1);
check('junk is refused', kop_contact_started_age('hello', $now) === -1 && kop_contact_started_age('', $now) === -1);

// The email: the type is in the subject; the message and the admin link are in the body.
function admin_url($p = '') { return 'https://example.org/wp-admin/' . $p; }
list($subject, $body) = kop_contact_mail_parts(7, kop_contact_clean(array('facility' => 'Some Ranch') + $ok, $types), $types);
check('the subject names the type', strpos($subject, 'Survivor or family') !== false, $subject);
check('the subject says no name was given', strpos($subject, 'No name given') !== false, $subject);
check('the body carries the message', strpos($body, 'I was at this program in 2004.') !== false);
check('the body carries the facility', strpos($body, 'Facility or program: Some Ranch') !== false);
check('the body links the admin screen', strpos($body, 'page=kop-contact-messages') !== false);
check('the owner is the recipient', KOP_CONTACT_TO === 'dani@kidsoverprofits.org');

// No address is stored: the row and the table hold only the form's fields.
$src = file_get_contents(dirname(__DIR__) . '/inc/contact-messages.php');
preg_match('/CREATE TABLE.*?\(\s*(.*?)\)\s*"\s*\./s', $src, $m);
check('the table has no address or browser column', isset($m[1]) && !preg_match('/\b(ip|remote_addr|user_agent|referr?er)\b/i', $m[1]));

// The page and the script agree on the fields.
$js = file_get_contents(dirname(__DIR__) . '/js/contact-form.js');
ob_start();
function esc_url($u) { return $u; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function home_url($p = '') { return 'https://example.org' . $p; }
function rest_url($p = '') { return 'https://example.org/wp-json/' . $p; }
function kop_choices($key, $d = null, $mode = 'all') { global $registered; return $registered[$key]['defaults']; }
kop_contact_form_render();
$html = ob_get_clean();
foreach (array('type', 'name', 'email', 'facility', 'message', 'contact_method', 'contact_detail', 'website') as $f) {
    check("the page has a $f field", strpos($html, 'name="' . $f . '"') !== false);
    check("the script sends $f", preg_match('/\b' . $f . ':/', $js) === 1);
}
foreach ($types as $v => $l) {
    check("the page offers $v", strpos($html, 'value="' . $v . '"') !== false);
}
check('the page has the privacy note', strpos($html, 'Who reads this') !== false && strpos($html, 'without your consent') !== false);
check('the honeypot is hidden from people', strpos($html, 'kop-vols-trap') !== false);
check('the script sends the nonce and start time', strpos($js, 'body.nonce') !== false && strpos($js, 'body.started') !== false);

$tpl = file_get_contents(dirname(__DIR__) . '/templates/page-utility.php');
$cfg = file_get_contents(dirname(__DIR__) . '/inc/utility-pages.php');
check('the utility template prints a module', strpos($tpl, "\$kop_utility['module']") !== false);
check('the contact page uses the form', strpos($cfg, "'module'     => 'kop_contact_form_render'") !== false);
$footer = file_get_contents(dirname(__DIR__) . '/inc/site-footer.php');
check('the footer links the contact page', strpos($footer, "'contact'           => 'Contact'") !== false);

echo $fail ? "$fail failed, $pass passed\n" : "All $pass passed\n";
exit($fail ? 1 : 0);
