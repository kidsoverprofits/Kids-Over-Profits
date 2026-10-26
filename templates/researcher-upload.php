<?php
/**
 * /researcher-upload/?key=<token>: a trusted researcher sends files of
 * records through their personal link (inc/researcher-uploads.php). No
 * JavaScript: a plain multipart form, answered with what each file held or
 * why it was refused. Without a working link it says how to get one.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_rup = kop_rup_page_state();
$kop_rup_link = $kop_rup['link'];
$kop_rup_on = $kop_rup_link && kop_rup_link_state($kop_rup_link) === 'on';
$kop_rup_kit = home_url('/researcher-kit/');
$kop_rup_contact = get_page_by_path('contact') ? (string) get_permalink(get_page_by_path('contact')) : '';

get_header();
?>
<div class="kop-od-page kop-rk-page kop-rup-page">

    <header class="kop-od-header">
        <h1 class="kop-od-title">Send research files</h1>
        <p class="kop-od-summary">A private upload page for researchers collecting data with Kids Over Profits.</p>
    </header>

    <?php if (!$kop_rup_link) : ?>
        <section class="kop-od-intro">
            <h2 class="kop-od-intro-title">This page needs your personal link</h2>
            <p>Uploads work only through the link Kids Over Profits sent you. Open it again from that message, or ask whoever sent it for a new one<?php
                if ($kop_rup_contact) echo ' through the <a href="' . esc_url($kop_rup_contact) . '">contact page</a>'; ?>.</p>
            <p>The <a href="<?php echo esc_url($kop_rup_kit); ?>">Researcher Data Kit</a> shows the shapes we take and has blank templates to fill.</p>
        </section>
    <?php elseif (!$kop_rup_on) : ?>
        <section class="kop-od-intro">
            <h2 class="kop-od-intro-title">This link has stopped working</h2>
            <p>It was turned off or has run out. Ask whoever sent it to you for more time or a new link; the files you already sent are safe with us.</p>
        </section>
    <?php else : ?>
        <section class="kop-od-intro">
            <h2 class="kop-od-intro-title">Hi <?php echo esc_html($kop_rup_link['name']); ?>, thank you for collecting with us</h2>
            <p>Send as many files as you like, several at a time: the JSON templates from the
                <a href="<?php echo esc_url($kop_rup_kit); ?>">Researcher Data Kit</a>, a spreadsheet (.csv, .tsv or .xlsx) with the kit's field names as column headings, or a .zip of them.
                Each file can be up to <?php echo esc_html(size_format(kop_rup_file_max())); ?>.</p>
            <p>Nothing you send is published until a Kids Over Profits editor has checked it.
                <?php if (!empty($kop_rup_link['expires_at'])) : ?>
                    This link works until <?php echo esc_html(get_date_from_gmt($kop_rup_link['expires_at'], 'F j, Y')); ?>.
                <?php endif; ?></p>
        </section>
    <?php endif; ?>

    <?php if ($kop_rup['error']) : ?>
        <p class="kop-rup-msg kop-rup-msg-bad" role="alert"><?php echo esc_html($kop_rup['error']); ?></p>
    <?php endif; ?>

    <?php if ($kop_rup['results']) : ?>
        <section aria-labelledby="kop-rup-results-heading">
            <h2 id="kop-rup-results-heading" class="kop-od-section-title">What arrived</h2>
            <ul class="kop-rup-results" role="status">
                <?php foreach ($kop_rup['results'] as $r) : ?>
                    <li class="<?php echo $r['ok'] ? 'kop-rup-ok' : 'kop-rup-bad'; ?>">
                        <strong><?php echo esc_html($r['name']); ?></strong>:
                        <?php echo $r['ok'] ? 'received (' . esc_html($r['message']) . ')' : 'not taken. ' . esc_html($r['message']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($kop_rup_on) : ?>
        <section aria-labelledby="kop-rup-form-heading">
            <h2 id="kop-rup-form-heading" class="kop-od-section-title">Send files</h2>
            <form class="kop-rup-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(kop_rup_page_url()); ?>">
                <input type="hidden" name="kop_rup_key" value="<?php echo esc_attr($kop_rup['token']); ?>">
                <input type="hidden" name="kop_rup_check" value="<?php echo esc_attr(kop_rup_csrf($kop_rup['token'])); ?>">
                <label for="kop-rup-files">Files</label>
                <input type="file" id="kop-rup-files" name="kop_rup_files[]" multiple required accept=".json,.csv,.tsv,.xlsx,.zip">
                <label for="kop-rup-note">A note for the editors (optional)</label>
                <textarea id="kop-rup-note" name="kop_rup_note" rows="3" maxlength="2000" placeholder="What these files cover, how you collected them, anything we should check first."></textarea>
                <button type="submit" class="kop-rup-send">Send</button>
            </form>
        </section>

        <?php $kop_rup_mine = kop_rup_uploads_for((int) $kop_rup_link['id'], 100); ?>
        <?php if ($kop_rup_mine) : ?>
            <section aria-labelledby="kop-rup-mine-heading">
                <h2 id="kop-rup-mine-heading" class="kop-od-section-title">Files you have sent</h2>
                <div class="kop-rup-table-wrap">
                    <table class="kop-rup-table">
                        <thead><tr><th scope="col">Sent</th><th scope="col">File</th><th scope="col">What it holds</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($kop_rup_mine as $kop_rup_row) : ?>
                            <tr>
                                <td><?php echo esc_html(get_date_from_gmt($kop_rup_row['uploaded_at'], 'M j, Y')); ?></td>
                                <td><?php echo esc_html($kop_rup_row['file_name']); ?></td>
                                <td><?php echo esc_html($kop_rup_row['summary']); ?></td>
                                <td><?php echo esc_html(array('new' => 'Waiting for an editor', 'loaded' => 'Loaded for review', 'aside' => 'Set aside')[$kop_rup_row['status']] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

</div>
<?php
get_footer();
