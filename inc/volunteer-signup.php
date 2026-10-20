<?php
/**
 * Sign up to review (2026-10-20): a form on /volunteer/ makes the person's
 * own volunteer link at once, with no wait for the owner. Same link as
 * KOP Tools > Volunteer Reviewers > "Invite someone" (kop_vol_create()), so
 * the person can only recommend, sees only the queues ticked there, and
 * shows up in the Volunteers table marked "Signed up on the Volunteer page".
 *
 * The form asks a name and, optionally, an email (kept on the volunteer row,
 * shown to admins only, used to send a lost link again). The answer shows the
 * link once and sets the kop_vol cookie, so "Start reviewing" opens their
 * page on this device straight away.
 *
 * Guardrails: a hidden field bots fill in, KOP_VOLS_PER_DAY_EACH sign-ups a
 * day per address + browser, KOP_VOLS_PER_DAY in all, and a device that is
 * already signed in is sent to its page instead of getting a second name.
 *
 * Page: the 'volunteer' hub module (kop_hub_modules, templates/page-hub.php),
 * js/volunteer-signup.js, css/volunteer-signup.css.
 * REST (public, POST): kop/v1/volunteer/signup.
 * Tested by the "Sign-up" section of scripts/test-review-volunteers.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOLS_PER_DAY_EACH = 3;
const KOP_VOLS_PER_DAY = 30;
const KOP_VOLS_NOTE = 'Signed up on the Volunteer page';
const KOP_VOLS_BY = 'sign-up';

/** Who is signing up: a keyed hash of their address and browser (never stored with the row). */
function kop_vols_who() {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash_hmac('sha256', 'kop-vols|' . $ip . '|' . $ua, wp_salt('nonce')), 0, 24);
}

/**
 * Make a volunteer from the public form. Returns ['id', 'name', 'url', 'token'].
 * Throws RuntimeException with a message for the person.
 */
function kop_vol_signup($name, $email, $who) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $email = trim((string) $email);
    if (mb_strlen($name) < 2) throw new RuntimeException('Give a name or a nickname, so we know who made each call.');
    if (mb_strlen($name) > 100) throw new RuntimeException('That name is too long: 100 letters at most.');
    if (preg_match('~https?://|www\.|[<>]~i', $name)) throw new RuntimeException('A name cannot hold a web address.');
    if ($email !== '' && (mb_strlen($email) > 190 || !is_email($email))) {
        throw new RuntimeException('That email address does not look right. You can leave it empty.');
    }

    $day = gmdate('Ymd');
    $mine_key = 'kop_vols_' . $day . '_' . $who;
    $all_key = 'kop_vols_' . $day;
    $mine = (int) get_transient($mine_key);
    $all = (int) get_transient($all_key);
    if ($mine >= (int) apply_filters('kop_vols_per_day_each', KOP_VOLS_PER_DAY_EACH)) {
        throw new RuntimeException('This device has made several links today. If you lost yours, write to us on the Contact page.');
    }
    if ($all >= (int) apply_filters('kop_vols_per_day', KOP_VOLS_PER_DAY)) {
        throw new RuntimeException('A lot of people signed up today. Please try again tomorrow.');
    }

    list($id, $url, $token) = kop_vol_create($name, KOP_VOLS_NOTE, array('email' => $email, 'created_by' => KOP_VOLS_BY));
    set_transient($mine_key, $mine + 1, DAY_IN_SECONDS);
    set_transient($all_key, $all + 1, DAY_IN_SECONDS);
    return array('id' => $id, 'name' => mb_substr($name, 0, 100), 'url' => $url, 'token' => $token);
}

/* ---- REST ------------------------------------------------------------------- */

function kop_vols_rest_signup(WP_REST_Request $req) {
    nocache_headers();
    if (!headers_sent()) header('X-LiteSpeed-Cache-Control: no-cache');
    // Bots fill in every field; people never see this one.
    if (trim((string) $req->get_param('website')) !== '') {
        return new WP_REST_Response(array('error' => 'Something went wrong. Please try again.'), 400);
    }
    if (kop_vol_current()) {
        return new WP_REST_Response(array('error' => 'This device already has a volunteer link.', 'signed_in' => true, 'page' => kop_vol_page_url()), 409);
    }
    try {
        $made = kop_vol_signup(wp_unslash((string) $req->get_param('name')), wp_unslash((string) $req->get_param('email')), kop_vols_who());
    } catch (RuntimeException $e) {
        return new WP_REST_Response(array('error' => $e->getMessage()), 400);
    }
    kop_vol_set_cookie($made['token']);
    return new WP_REST_Response(array('name' => $made['name'], 'url' => $made['url'], 'page' => kop_vol_page_url()), 200);
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/volunteer/signup', array(
        'methods' => 'POST', 'callback' => 'kop_vols_rest_signup',
        'permission_callback' => '__return_true',
    ));
});

/* ---- The form on /volunteer/ ---------------------------------------------------- */

/** The /volunteer/ hub page shows the form (templates/page-hub.php, kop_hub_module_for()). */
function kop_vols_hub_modules($modules) {
    $modules['volunteer'] = 'kop_vols_module';
    return $modules;
}
add_filter('kop_hub_modules', 'kop_vols_hub_modules');

/** The sign-up form (a hub module: echoes its own markup). */
function kop_vols_module() {
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    if (file_exists($dir . '/css/volunteer-signup.css')) {
        wp_enqueue_style('kop-volunteer-signup', $uri . '/css/volunteer-signup.css', array(), filemtime($dir . '/css/volunteer-signup.css'));
    }
    if (file_exists($dir . '/js/volunteer-signup.js')) {
        wp_enqueue_script('kop-volunteer-signup', $uri . '/js/volunteer-signup.js', array(), filemtime($dir . '/js/volunteer-signup.js'), true);
    }
    ?>
    <section class="kop-hub-module kop-vols" id="kop-vol-signup" aria-labelledby="kop-vols-h">
        <h2 class="kop-hub-h" id="kop-vols-h">Review what people send in</h2>
        <p class="kop-vols-lead">Volunteers read what comes in (news stories, court cases, bills, findings from state reports) and
            say whether it belongs on the site. You only recommend: nothing changes until we check it. Each queue says what
            to look for, and most items take a minute or two.</p>
        <form class="kop-vols-form" data-rest="<?php echo esc_url(rest_url('kop/v1/volunteer/signup')); ?>" novalidate>
            <div class="kop-vols-field">
                <label for="kop-vols-name">Your name or a nickname</label>
                <input type="text" id="kop-vols-name" name="name" required maxlength="100" autocomplete="nickname">
                <p class="kop-vols-help">Shown to you on your page, and to us next to your calls.</p>
            </div>
            <div class="kop-vols-field">
                <label for="kop-vols-email">Email <span class="kop-vols-opt">(optional)</span></label>
                <input type="email" id="kop-vols-email" name="email" maxlength="190" autocomplete="email">
                <p class="kop-vols-help">Only so we can send your link again if you lose it. Never shown to anyone else.</p>
            </div>
            <div class="kop-vols-trap" aria-hidden="true">
                <label for="kop-vols-website">Leave this empty</label>
                <input type="text" id="kop-vols-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <p class="kop-vols-error" role="alert" hidden></p>
            <button type="submit" class="kop-vols-btn">Get my link</button>
        </form>
        <div class="kop-vols-done" hidden tabindex="-1">
            <p class="kop-vols-done-h"></p>
            <p>This link is how you sign in. Keep it somewhere safe, like a bookmark or a note to yourself: it is shown only
                this once. Anyone who has it can work under your name, so do not share it.</p>
            <div class="kop-vols-link">
                <input type="text" readonly aria-label="Your volunteer link">
                <button type="button" class="kop-vols-btn kop-vols-copy">Copy</button>
            </div>
            <p>This device is signed in already, so you can start now.</p>
            <a class="kop-vols-btn kop-vols-start" href="<?php echo esc_url(kop_vol_page_url()); ?>">Start reviewing</a>
        </div>
    </section>
    <?php
}
