<?php
/**
 * Lighter facility and company pages (docs/PLAN.md 3.24, the search plan's
 * "lighter pages"): the contents of a closed <details> fold (older news,
 * celebrity and viral stories, document subfolders, Unsilenced groups) are
 * sent inside a <template> and only drawn when the fold is opened
 * (js/defer-folds.js). The words still come in the same response, but the
 * browser builds none of it until a reader asks: Provo Canyon School's page
 * goes from about 12,000 elements to about 6,000.
 *
 * Only closed folds at the top level of <main>, with enough inside to matter,
 * and never one holding a script. Signed-in editors get the page whole, so
 * the pencils and admin tools see everything. A fold opts out with
 * data-kop-nodefer.
 *
 * js/defer-folds.js draws a fold when it opens, when the address points at
 * something inside it, and before printing; it fires 'kop:stamped' on the fold.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_defer_folds_min')) {
    /** Folds with fewer elements than this are left as they are. */
    function kop_defer_folds_min() {
        return 40;
    }
}

if (!function_exists('kop_defer_folds_wanted')) {
    function kop_defer_folds_wanted() {
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('current_user_can') && current_user_can('edit_posts')) return false;
        return !isset($_GET['kop_full']);
    }
}

if (!function_exists('kop_defer_folds')) {
    /**
     * $html with each closed top-level fold's contents (everything after its
     * <summary>) moved into <template data-kop-defer>. Only the part between
     * the first <main and the last </main> is touched.
     */
    function kop_defer_folds($html, $min = null) {
        $html = (string) $html;
        $min = $min === null ? kop_defer_folds_min() : (int) $min;
        $a = stripos($html, '<main');
        $b = strripos($html, '</main>');
        if ($a === false || $b === false || $b <= $a) return $html;
        $body = substr($html, $a, $b - $a);

        // Tags that matter, with blocks whose insides are not markup skipped whole.
        if (!preg_match_all('#<(script|style|template|textarea)\b[^>]*>.*?</\1\s*>|<!--.*?-->|<details\b([^>]*)>|</details\s*>#is', $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $html;

        $cuts = array();   // [content start, content end]
        $depth = 0;
        $open_at = null;
        foreach ($m as $t) {
            $tag = $t[0][0];
            $pos = $t[0][1];
            if (!empty($t[1][0]) || strncmp($tag, '<!--', 4) === 0) continue;   // a skipped block
            if (strncasecmp($tag, '</', 2) === 0) {
                if ($depth === 0) continue;   // stray close
                $depth--;
                if ($depth === 0 && $open_at !== null) {
                    $sum = stripos($body, '</summary>', $open_at);
                    if ($sum !== false && $sum < $pos) {
                        $start = $sum + strlen('</summary>');
                        $inner = substr($body, $start, $pos - $start);
                        if (stripos($inner, '<script') === false && preg_match_all('/<[a-z]/i', $inner) >= $min) {
                            $cuts[] = array($start, $pos);
                        }
                    }
                    $open_at = null;
                }
                continue;
            }
            // An opening <details>.
            if ($depth === 0) {
                $attrs = (string) ($t[2][0] ?? '');
                $closed = !preg_match('/\sopen(?=[\s=>\/]|$)/i', ' ' . $attrs) && stripos($attrs, 'data-kop-nodefer') === false;
                $open_at = $closed ? $pos + strlen($tag) : null;
            }
            $depth++;
        }
        if (!$cuts) return $html;

        $out = '';
        $at = 0;
        foreach ($cuts as $c) {
            $out .= substr($body, $at, $c[0] - $at) . '<template data-kop-defer>' . substr($body, $c[0], $c[1] - $c[0]) . '</template>';
            $at = $c[1];
        }
        $out .= substr($body, $at);
        return substr($html, 0, $a) . $out . substr($html, $b);
    }
}

if (!function_exists('kop_defer_folds_start')) {
    /** Called before get_header() on a page whose folds may be deferred. */
    function kop_defer_folds_start() {
        if (!kop_defer_folds_wanted()) return false;
        ob_start();
        return true;
    }
}

if (!function_exists('kop_defer_folds_end')) {
    /** Called after get_footer() with what kop_defer_folds_start() returned. */
    function kop_defer_folds_end($started) {
        if (!$started) return;
        echo kop_defer_folds(ob_get_clean()); // The page's own markup, rearranged.
    }
}

if (!function_exists('kop_defer_folds_enqueue')) {
    function kop_defer_folds_enqueue() {
        $dir = get_stylesheet_directory();
        $file = $dir . '/js/defer-folds.js';
        if (file_exists($file)) {
            wp_enqueue_script('kop-defer-folds', get_stylesheet_directory_uri() . '/js/defer-folds.js', array(), filemtime($file), true);
        }
    }
}
