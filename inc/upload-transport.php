<?php
/**
 * Base64 transport for files sent by signed-out people.
 *
 * Wordfence's firewall runs PHP's tokenizer over every file a signed-out
 * visitor uploads and refuses the request (rule 76, "Malicious File Upload
 * (PHP)") when stray "<?" bytes in a PDF, a picture or any other binary file
 * look like a PHP block; with short_open_tag on that happens to ordinary
 * files (a visitor's PDF on the anonymous portal, 2026-11-26). The page's
 * script therefore sends the file part as base64 text, which cannot contain
 * "<?", with a field saying so, and the route turns it back into the real
 * bytes before anything else looks at it. Every check the route makes (type,
 * size, scan) runs on the decoded file.
 *
 * Used by the anonymous portal (inc/features.php, its own copy of the
 * decoder), the researcher upload links' chunk route
 * (inc/researcher-documents.php) and the volunteer picture upload
 * (inc/volunteer-social.php). docs/anonymous-portal/README.md, "Submitting".
 */

if (!function_exists('kop_upload_base64_decode_file')) {
    /**
     * Replace the base64 text in the file at $path with the bytes it stands
     * for. Returns the decoded size. Throws when the file cannot be read or
     * written, is empty, is over $max_encoded bytes, or is not base64.
     */
    function kop_upload_base64_decode_file($path, $max_encoded = 0) {
        $path = (string) $path;
        clearstatcache(true, $path);
        if (!is_file($path)) throw new RuntimeException('The file did not arrive.');
        if ($max_encoded > 0 && filesize($path) > $max_encoded) throw new RuntimeException('The file is too big.');
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') throw new RuntimeException('The file did not arrive.');
        $bytes = base64_decode(trim($raw), true);
        unset($raw);
        if ($bytes === false) throw new RuntimeException('The file did not arrive whole.');
        if (@file_put_contents($path, $bytes) === false) throw new RuntimeException('The server could not read the file.');
        clearstatcache(true, $path);
        return strlen($bytes);
    }
}
