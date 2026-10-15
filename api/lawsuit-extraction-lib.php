<?php
/**
 * Shared helpers for AI lawsuit extraction.
 *
 * Used by:
 *   - api/extract-lawsuit-from-document.php  (admin: registers attachment, FileBird)
 *   - api/extract-lawsuit-suggestion.php     (public: rate-limited, no attachment)
 *
 * Both endpoints implement the same three-action protocol (upload / chunk /
 * finalize); everything protocol-independent lives here.
 */

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/ai-providers.php';
if (!function_exists('kop_choices')) require_once dirname(__DIR__) . '/inc/choice-lists.php'; // KOP Tools > Dropdown Lists

/** Case statuses the AI may give (Lawsuit statuses, KOP Tools > Dropdown Lists; hidden ones left out). */
function kop_lawsuit_extract_statuses() {
    return function_exists('kop_lawsuit_status_values') ? kop_lawsuit_status_values('offered')
        : ['filed', 'in_progress', 'settled', 'dismissed', 'ruling', 'appeal', 'closed', 'unknown'];
}

if (!function_exists('kop_resolve_secret')) {
    function kop_resolve_secret($name) {
        $v = getenv($name);
        if ($v !== false && $v !== '') return $v;
        if (!empty($_ENV[$name])) return $_ENV[$name];
        if (!empty($_SERVER[$name])) return $_SERVER[$name];
        if (defined($name)) { $c = constant($name); if (!empty($c)) return $c; }
        return '';
    }
}

/**
 * Sanitize extracted document text and split it into overlapping chunks.
 * Character-based (mb_substr): byte-based substr can cut a multibyte character
 * in half, making the chunk invalid UTF-8 — json_encode of the AI request body
 * then fails and the extraction aborts.
 *
 * @return array{chunks: string[], length: int}
 */
function kop_lawsuit_chunk_text(string $doc_text, int $max_chunks = 10): array {
    $doc_text = str_replace("\0", '', $doc_text);
    $doc_text = iconv('UTF-8', 'UTF-8//IGNORE', $doc_text) ?: $doc_text;
    $doc_text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $doc_text);

    $chunks     = [];
    $chunk_size = 12000;
    $overlap    = 300;
    $offset     = 0;
    $len        = mb_strlen($doc_text, 'UTF-8');
    while ($offset < $len && count($chunks) < $max_chunks) {
        $chunks[] = mb_substr($doc_text, $offset, $chunk_size, 'UTF-8');
        $offset  += $chunk_size - $overlap;
    }
    if ($offset < $len) {
        error_log("lawsuit-extraction: document truncated at $max_chunks chunks (" . ($len - $offset) . ' chars dropped)');
    }
    return ['chunks' => $chunks, 'length' => $len];
}

/** Pick the Groq model for a chunk call (Gemini's turns use GEMINI_MODEL). */
function kop_lawsuit_pick_model(int $total_chunks): string {
    // Groq retires models out from under us (llama-3.3-70b-versatile went 404
    // Sep 2026, llama-3.1-8b-instant days later), so hardcode only one default
    // and let .env override: GROQ_MODEL for everything, GROQ_MODEL_CHUNK to use
    // a cheaper model for multi-chunk documents.
    $default = getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b';
    if ($total_chunks > 1) {
        return getenv('GROQ_MODEL_CHUNK') ?: $default;
    }
    return $default;
}

// =============================================================================
// AI call: Groq and Gemini take turns (api/ai-providers.php)
// =============================================================================
function kop_lawsuit_ai_call(string $text, int $chunk_num = 1, int $total = 1): array {
    $prompt  = kop_lawsuit_extraction_prompt();
    $context = ($total > 1)
        ? "NOTE: This is chunk {$chunk_num} of {$total} of a longer document. Extract whatever fields you can find in this portion.\n\n"
        : '';
    try {
        $raw_text = kop_ai_generate_alternating("{$context}DOCUMENT TEXT:\n{$text}\n\n{$prompt}", [
            'maxTokens' => 4096,
            'groqModel' => kop_lawsuit_pick_model($total),
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'data' => null, 'error' => 'AI error: ' . $e->getMessage()];
    }
    $parsed = kop_ai_extract_json($raw_text);
    if (!is_array($parsed)) {
        return ['ok' => false, 'data' => null, 'error' => 'Could not parse the AI JSON. Preview: ' . substr($raw_text, 0, 200)];
    }
    return ['ok' => true, 'data' => $parsed, 'error' => ''];
}


// =============================================================================
// Merge + normalize helpers
// =============================================================================
function kop_merge_chunk_extractions(array $chunks): array {
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'status', 'outcome', 'settlement_amount', 'summary'];
    $array_fields  = ['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls'];

    $merged = [];
    foreach ($string_fields as $f) {
        foreach ($chunks as $chunk) {
            $v = trim((string)($chunk[$f] ?? ''));
            if ($v !== '') { $merged[$f] = $v; break; }
        }
    }
    foreach ($array_fields as $f) {
        $all = [];
        foreach ($chunks as $chunk) {
            $v = $chunk[$f] ?? [];
            if (is_array($v)) {
                foreach ($v as $item) {
                    if (is_string($item) && trim($item) !== '') $all[] = trim($item);
                }
            }
        }
        $merged[$f] = array_values(array_unique($all));
    }
    return $merged;
}

function kop_normalize_lawsuit_extraction(array $raw): array {
    $array_fields  = ['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls'];
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'summary', 'outcome', 'settlement_amount'];

    $out = [];
    foreach ($array_fields as $f) {
        $v = $raw[$f] ?? [];
        if (is_string($v)) {
            $v = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $v))));
        } elseif (is_array($v)) {
            $v = array_values(array_filter(array_map(static fn($x) => is_string($x) ? trim($x) : '', $v), static fn($x) => $x !== ''));
        } else {
            $v = [];
        }
        $out[$f] = $v;
    }
    foreach ($string_fields as $f) {
        $out[$f] = is_string($raw[$f] ?? '') ? trim($raw[$f] ?? '') : '';
    }

    $filing = $raw['filing_date'] ?? '';
    if (is_string($filing) && $filing !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filing)) {
        $ts     = strtotime($filing);
        $filing = $ts ? date('Y-m-d', $ts) : '';
    }
    $out['filing_date'] = is_string($filing) ? $filing : '';

    // Case status only when the document showed one of the tracker's values.
    $status = is_string($raw['status'] ?? '') ? strtolower(trim(str_replace([' ', '-'], '_', $raw['status'] ?? ''))) : '';
    if ($status !== 'unknown' && in_array($status, kop_lawsuit_extract_statuses(), true)) {
        $out['status'] = $status;
    }

    if ($out['jurisdiction'] !== '') {
        $out['jurisdiction'] = strcasecmp($out['jurisdiction'], 'federal') === 0
            ? 'Federal'
            : ucwords(strtolower($out['jurisdiction']));
        if (function_exists('kop_proper_names_apply')) {
            $out['jurisdiction'] = kop_proper_names_apply($out['jurisdiction']);
        }
    }

    return $out;
}


// =============================================================================
// Text extraction helpers
// =============================================================================
/**
 * $max_pages / $max_chars: 0 = the whole document. Callers that only need the
 * start (document types) pass limits so a long PDF is not read end to end.
 */
function kop_extract_document_text(string $path, string $mime_type, int $max_pages = 0, int $max_chars = 0): string {
    if ($mime_type === 'text/plain') return (string)@file_get_contents($path, false, null, 0, $max_chars > 0 ? $max_chars * 4 : null);
    if ($mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') return kop_extract_docx_text($path);
    return kop_extract_pdf_text($path, $max_pages, $max_chars);
}

/**
 * Text of a PDF: pdftotext when installed, else the gs program (the host has
 * Ghostscript, not pdftotext), else a PHP reader of the content streams.
 *
 * The PHP reader works one stream at a time with capped inflation: it used to
 * inflate every stream of the file into one string, and a 50 MB PDF from the
 * hourly document-type job needed 450 MB+ and killed PHP every hour.
 */
function kop_extract_pdf_text(string $path, int $max_pages = 0, int $max_chars = 0): string {
    $page_args = $max_pages > 0 ? ' -f 1 -l ' . $max_pages : '';
    foreach (['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $bin) {
        $which = trim((string)@shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null'));
        $exe   = ($which !== '') ? $which : $bin;
        if (@is_executable($exe)) {
            $out = @shell_exec(escapeshellarg($exe) . ' -enc UTF-8 -nopgbrk' . $page_args . ' ' . escapeshellarg($path) . ' - 2>/dev/null');
            if ($out !== null && strlen(trim($out)) > 50) return $out;
            break;
        }
    }

    if (function_exists('shell_exec') && DIRECTORY_SEPARATOR === '/' && @is_executable('/usr/bin/gs')) {
        $out = (string)@shell_exec((@is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 120 ' : '')
            . '/usr/bin/gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=txtwrite'
            . ($max_pages > 0 ? ' -dFirstPage=1 -dLastPage=' . $max_pages : '')
            . ' -sOutputFile=- ' . escapeshellarg($path) . ' 2>/dev/null');
        $out = trim(preg_replace('/[ \t]{2,}/', ' ', $out) ?? $out);
        if (strlen($out) > 50) return $out;
    }

    // Past this size the PHP reader is not worth the memory.
    if (@filesize($path) > 80 * 1024 * 1024) return '';
    $raw = @file_get_contents($path);
    if (!$raw) return '';

    $text = '';
    $pos  = 0;
    while (($s = strpos($raw, 'stream', $pos)) !== false) {
        $start = $s + 6;
        if (substr($raw, $start, 2) === "\r\n") {
            $start += 2;
        } elseif (isset($raw[$start]) && ($raw[$start] === "\n" || $raw[$start] === "\r")) {
            $start += 1;
        } else {
            $pos = $start;
            continue;
        }
        $end = strpos($raw, 'endstream', $start);
        if ($end === false) break;
        $pos  = $end + 9;
        $data = rtrim(substr($raw, $start, $end - $start), "\r\n");
        if (function_exists('gzuncompress')) {
            // A content stream is small; a bigger one is an image, not text.
            $d = @gzuncompress($data, 8 * 1024 * 1024);
            if ($d !== false) $data = $d;
        }
        $text .= kop_pdf_content_text($data);
        unset($data, $d);
        if ($max_chars > 0 && strlen($text) > $max_chars * 2) break;
        if (strlen($text) > 20 * 1024 * 1024) break;
    }
    unset($raw);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
}

/** The shown strings (Tj / TJ) of one PDF content stream. */
function kop_pdf_content_text(string $content): string {
    $text = '';
    if (strpos($content, 'BT') === false) return '';
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $b = $block[1];
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $b, $tj, PREG_SET_ORDER);
            foreach ($tj as $m) $text .= kop_pdf_unescape($m[1]) . ' ';
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $b, $TJ, PREG_SET_ORDER);
            foreach ($TJ as $m) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $m[1], $ss, PREG_SET_ORDER);
                foreach ($ss as $s) $text .= kop_pdf_unescape($s[1]);
                $text .= ' ';
            }
        }
    }
    return $text;
}

function kop_pdf_unescape(string $s): string {
    return str_replace(['\\n','\\r','\\t','\\(','\\)','\\\\'], ["\n","\r","\t",'(',')',' '], $s);
}

function kop_extract_docx_text(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml = str_replace(['</w:p>', '<w:br/>', '</w:r>'], ["\n", ' ', ' '], $xml);
    return trim(strip_tags($xml));
}


// =============================================================================
// Extraction prompt
// =============================================================================
function kop_lawsuit_extraction_prompt(): string {
    $statuses = implode(', ', array_diff(kop_lawsuit_extract_statuses(), ['unknown']));
    return <<<PROMPT
You are an expert legal-research assistant. Read the attached court document and extract structured information for a Troubled Teen Industry accountability database.

The document can be any paper from the case: a complaint or petition, an answer, a motion, a brief, an order or ruling, a judgment or verdict, a settlement, a docket sheet, a transcript, or a news-style summary of the case. Read what that kind of paper says: an order, judgment, settlement or docket tells you the case's status and outcome; a complaint tells you the parties and the claims.

Apply trauma-sensitive language: factual and neutral, never sensationalist, no graphic injury details, never describe suicide methods.

Return ONLY valid JSON matching this exact schema (omit fields you can't determine):

{
  "case_name": "e.g. Doe v. Provo Canyon School",
  "case_number": "docket number as printed on the filing",
  "court": "full court name as printed, e.g. 'United States District Court for the District of Utah'",
  "jurisdiction": "Federal OR the US state name (e.g. 'Utah'). Federal courts always = Federal.",
  "filing_date": "YYYY-MM-DD",
  "plaintiffs": ["list of named plaintiffs, one per entry"],
  "defendants": ["list of named defendants, one per entry"],
  "facilities_mentioned": ["TTI facilities/programs named in the document"],
  "staff_mentioned": ["individual staff, owners, or therapists named"],
  "organizations_mentioned": ["parent companies, referrers, accreditation bodies, etc."],
  "claims": ["one short slug per claim, e.g. 'physical_abuse', 'wrongful_death', 'fraud', 'negligence', 'sexual_abuse', 'civil_rights_violation', 'breach_of_contract'"],
  "status": "only if the document shows it, one of: {$statuses}",
  "outcome": "what the court decided or the parties agreed, if this document shows it (e.g. 'Motion to dismiss denied in part', 'Settled; case dismissed with prejudice')",
  "settlement_amount": "if a settlement or award amount is stated, as written (e.g. '$1.5 million')",
  "summary": "3-5 sentence factual summary of the case using neutral language: the allegations, and what this document adds (a ruling, a settlement)",
  "tags": ["3-6 short keyword tags, e.g. 'wilderness therapy', 'restraint', 'class action'"]
}

Rules:
- Use exactly the field names above.
- Arrays must be JSON arrays of strings, not comma-joined strings.
- Use ISO dates (YYYY-MM-DD). If only month/year is shown, leave filing_date blank.
- If a value cannot be determined, omit the field or use an empty string/array — do NOT guess.
- Do not include legal citations or paragraph numbers in the summary.
- Return ONLY the JSON object, no prose, no markdown fences.
PROMPT;
}
