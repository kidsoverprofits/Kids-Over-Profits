<?php
/**
 * File the state's PDFs of linked companies' filings in each program's library
 * (inc/business-filings.php kop_bizf_import()).
 *
 * The PDFs come from kop-pipeline `py -3 scripts/colorado-sos.py pdfs` and
 * `florida-sunbiz.py pdfs` (tmp/colorado-sos/pdfs/, tmp/florida-sunbiz/pdfs/,
 * each with its manifest.json), copied to the server by hand (scp -r ...
 * ~/kop-import/business-filings/co, .../fl), one run per folder; never committed.
 *
 * CLI only:  php api/import-business-filings.php <dir> [apply]
 * Without "apply" it reports what it would do. Run again after linking more
 * companies at Review inbox > Business filings.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$dir   = isset($argv[1]) ? rtrim($argv[1], '/') : '';
$apply = in_array('apply', $argv, true);
if ($dir === '' || !is_readable($dir . '/manifest.json')) {
    fwrite(STDERR, "Usage: php api/import-business-filings.php <dir with manifest.json> [apply]\n");
    exit(2);
}

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

try {
    $report = kop_bizf_import($dir, $apply);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo json_encode(array('applied' => $apply, 'companies' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
