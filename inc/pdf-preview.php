<?php
/**
 * First-page cover images for PDFs, drawn by the Ghostscript program.
 *
 * WordPress draws PDF covers with Imagick, which runs Ghostscript inside the
 * PHP process. On the host some ordinary PDFs (most of the 2010-2013 Woodbury
 * extracts) crash that library, which kills the whole request: a batch of
 * five stopped at the first such file and the rest were never tried. The
 * /usr/bin/gs program renders the same files without trouble, and as a
 * separate process with a time limit it can only fail itself.
 *
 * kop_pdf_preview_make() writes the cover where WordPress would
 * (<name>-pdf.jpg beside the PDF, metadata sizes 'full' + the fallback
 * sizes), so every reader of kop_get_attachment_preview_url() sees it as an
 * upload's own preview. Without the gs program (Local on Windows) it falls
 * back to wp_generate_attachment_metadata().
 *
 * Failures carry a reason, stored in kop_pdf_preview_failed as
 * "<reason> <date>" so the tools can say why:
 *   missing   the PDF is not on disk
 *   readonly  its folder cannot be written (and is not ours to open up)
 *   damaged   Ghostscript finds no page (a cut-off upload: no %%EOF)
 *   timeout   Ghostscript took longer than the limit
 *   failed    WordPress's own route (no gs program) drew nothing
 *   killed    marked before rendering; still there = the process died
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_PDF_PREVIEW_FAILED_META')) {
    define('KOP_PDF_PREVIEW_FAILED_META', 'kop_pdf_preview_failed');
}

/** Words for each failure reason, for the tools' reports. */
function kop_pdf_preview_reason_label($reason) {
    $labels = array(
        'missing'  => 'PDF file is not on the server',
        'readonly' => 'its folder on the server is read-only',
        'damaged'  => 'the PDF is damaged or cut off (Ghostscript finds no page)',
        'timeout'  => 'Ghostscript took too long',
        'killed'   => 'the server stopped the request while drawing it',
        'failed'   => 'WordPress could not draw it',
    );
    return isset($labels[$reason]) ? $labels[$reason] : $labels['failed'];
}

/** The reason stored on an attachment, or '' when it has none. */
function kop_pdf_preview_failed_reason($attachment_id) {
    $v = (string) get_post_meta($attachment_id, KOP_PDF_PREVIEW_FAILED_META, true);
    if ($v === '') {
        return '';
    }
    // Marks from before reasons were stored are a bare date.
    return preg_match('/^([a-z]+) /', $v, $m) ? $m[1] : 'failed';
}

function kop_pdf_preview_gs_path() {
    static $gs = null;
    if ($gs === null) {
        $gs = '';
        if (function_exists('exec') && DIRECTORY_SEPARATOR === '/') {
            foreach (array('/usr/bin/gs', '/usr/local/bin/gs') as $p) {
                if (@is_executable($p)) {
                    $gs = $p;
                    break;
                }
            }
        }
    }
    return $gs;
}

/** True when the PDF's last bytes hold the end-of-file marker. */
function kop_pdf_preview_has_eof($path) {
    $size = @filesize($path);
    $fh = @fopen($path, 'rb');
    if (!$fh || !$size) {
        return false;
    }
    fseek($fh, max(0, $size - 4096));
    $tail = (string) fread($fh, 4096);
    fclose($fh);
    return strpos($tail, '%%EOF') !== false;
}

/**
 * Make sure the PDF's folder can take the cover. Folders the site's own user
 * owns but that were left read-only (the "Sequel TSI" tree came back 0555
 * from the restore) are opened up to 0755, the uploads default.
 */
function kop_pdf_preview_folder_ok($dir) {
    if (is_writable($dir)) {
        return true;
    }
    if (function_exists('posix_geteuid') ? @fileowner($dir) === posix_geteuid() : @fileowner($dir) === getmyuid()) {
        @chmod($dir, 0755);
        clearstatcache(true, $dir);
    }
    return is_writable($dir);
}

/**
 * Draw the cover of one PDF attachment and save its metadata.
 *
 * @return array ok (bool), reason ('' or one of the reasons above), detail
 *               (Ghostscript's first error line, when there is one), sizes.
 */
function kop_pdf_preview_make($attachment_id, $pdf_path = '') {
    $attachment_id = (int) $attachment_id;
    $pdf_path = $pdf_path ? $pdf_path : (string) get_attached_file($attachment_id);
    $fail = function ($reason, $detail = '') use ($attachment_id) {
        update_post_meta($attachment_id, KOP_PDF_PREVIEW_FAILED_META, $reason . ' ' . gmdate('c'));
        return array('ok' => false, 'reason' => $reason, 'detail' => $detail, 'sizes' => array());
    };

    if ($pdf_path === '' || !file_exists($pdf_path)) {
        return $fail('missing');
    }
    $dir = dirname($pdf_path);
    if (!kop_pdf_preview_folder_ok($dir)) {
        return $fail('readonly', $dir);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Marked first: if the process dies anyway, the next run skips this one.
    update_post_meta($attachment_id, KOP_PDF_PREVIEW_FAILED_META, 'killed ' . gmdate('c'));

    $gs = kop_pdf_preview_gs_path();
    if ($gs === '') {
        $meta = wp_generate_attachment_metadata($attachment_id, $pdf_path);
        if (is_array($meta) && !empty($meta['sizes'])) {
            wp_update_attachment_metadata($attachment_id, $meta);
            delete_post_meta($attachment_id, KOP_PDF_PREVIEW_FAILED_META);
            return array('ok' => true, 'reason' => '', 'detail' => '', 'sizes' => array_keys($meta['sizes']));
        }
        return $fail(kop_pdf_preview_has_eof($pdf_path) ? 'failed' : 'damaged');
    }

    // Same name and settings as WordPress: <name>-pdf.jpg, 128 dpi, crop box.
    $dir_slash = trailingslashit($dir);
    $preview = $dir_slash . wp_unique_filename($dir_slash, wp_basename($pdf_path, '.' . pathinfo($pdf_path, PATHINFO_EXTENSION)) . '-pdf.jpg');
    $cmd = (@is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 60 ' : '')
        . escapeshellarg($gs)
        . ' -q -dSAFER -dBATCH -dNOPAUSE -dNOPROMPT -sDEVICE=jpeg -dJPEGQ=90 -r128 -dUseCropBox'
        . ' -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -dFirstPage=1 -dLastPage=1'
        . ' -sOutputFile=' . escapeshellarg($preview) . ' ' . escapeshellarg($pdf_path) . ' 2>&1';
    $out = array();
    $rc = 0;
    @exec($cmd, $out, $rc);
    clearstatcache(true, $preview);
    $dims = file_exists($preview) && filesize($preview) > 0 ? @getimagesize($preview) : false;
    if (!$dims) {
        @unlink($preview);
        $detail = '';
        foreach ($out as $line) {
            if (stripos($line, 'error') !== false) {
                $detail = trim(preg_replace('/^\W+/', '', $line));
                break;
            }
        }
        return $fail($rc === 124 ? 'timeout' : 'damaged', $detail);
    }

    $meta = wp_get_attachment_metadata($attachment_id, true);
    $meta = is_array($meta) ? $meta : array();
    $meta['sizes'] = array(
        'full' => array(
            'file'      => wp_basename($preview),
            'width'     => (int) $dims[0],
            'height'    => (int) $dims[1],
            'mime-type' => 'image/jpeg',
            'filesize'  => (int) wp_filesize($preview),
        ),
    );
    if (!isset($meta['filesize'])) {
        $meta['filesize'] = (int) wp_filesize($pdf_path);
    }
    wp_update_attachment_metadata($attachment_id, $meta);
    // The cover is saved; from here a failure only loses the smaller sizes.
    delete_post_meta($attachment_id, KOP_PDF_PREVIEW_FAILED_META);

    $fallback = apply_filters('fallback_intermediate_image_sizes', array('thumbnail', 'medium', 'large'), $meta);
    $sizes = array_intersect_key(wp_get_registered_image_subsizes(), array_flip($fallback));
    if (isset($sizes['thumbnail']) && is_array($sizes['thumbnail'])) {
        $sizes['thumbnail']['crop'] = false;
    }
    if ($sizes && function_exists('_wp_make_subsizes')) {
        $made = _wp_make_subsizes($sizes, $preview, $meta, $attachment_id);
        if (is_array($made) && !empty($made['sizes'])) {
            $meta = $made;
            wp_update_attachment_metadata($attachment_id, $meta);
        }
    }
    return array('ok' => true, 'reason' => '', 'detail' => '', 'sizes' => array_keys($meta['sizes']));
}
