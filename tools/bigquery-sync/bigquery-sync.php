<?php
/**
 * Nightly copy of the live KOP MySQL database into Google BigQuery (dataset kop_prod).
 *
 * Runs on the NixiHost server from cron (no libraries: PDO for MySQL, curl + openssl for Google).
 * Every table not on the deny list is exported to gzipped NDJSON and loaded with WRITE_TRUNCATE.
 * The whole export reads one consistent snapshot. Tables that stop syncing (newly excluded or
 * dropped in MySQL) are deleted from BigQuery. Each run appends one row per table to _sync_log.
 *
 *   php bigquery-sync.php --list            show what would sync / be excluded / which columns drop (no Google calls)
 *   php bigquery-sync.php                   full sync
 *   php bigquery-sync.php --tables=a,b      sync only these tables (no deletion of others)
 *   php bigquery-sync.php --verify          compare BigQuery row counts with MySQL COUNT(*) for every synced table
 *
 * Settings (environment overrides): KOP_BQ_PROJECT (kop-data), KOP_BQ_DATASET (kop_prod),
 * KOP_BQ_KEY (~/.kop-bigquery/sa-key.json), KOP_WP_CONFIG (~/public_html/wp-config.php),
 * KOP_BQ_TMP (~/bigquery-sync/tmp). See README.md.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ---------------------------------------------------------------- exclusions

// Whole tables (fnmatch patterns, without the WordPress prefix check: names as in MySQL).
const KOP_BQ_EXCLUDE_TABLES = [
    // credentials and secrets
    'wpdl_users',                      // password hashes, activation keys
    'wpdl_usermeta',                   // session tokens, capabilities, app passwords
    'wpdl_options',                    // API keys, OAuth tokens, plugin settings
    'wpdl_wf*',                        // Wordfence: 2FA secrets, passkeys, login log, IPs, firewall config
    'wpdl_dlm_*',                      // Download Monitor: API keys, sessions, cookies, orders
    'wpdl_integrate_google_drive_*',   // Drive plugin account/shortcode config
    'wpdl_zotpress*',                  // Zotero API key, OAuth, cache
    'wpdl_snippets',                   // PHP snippets that may embed keys
    'wpdl_anonymous_submissions',      // encrypted whistleblower messages and contacts
    // plugin internals with no KOP data
    'wpdl_actionscheduler_*', 'wpdl_litespeed_*', 'wpdl_asp_*', 'wpdl_imunify_*', 'wpdl_yoast_*',
    'wpdl_mclean_*', 'wpdl_wpforms*', 'wpdl_gutenaforms*', 'wpdl_mailerlite_forms',
    'wpdl_pdfposter_presets', 'wpdl_wpfm_backup', 'wpdl_e_events', 'wpdl_download_log', 'wpdl_links',
];

// Named columns dropped from tables that do sync ("table.column").
const KOP_BQ_EXCLUDE_COLUMNS = [
    'wpdl_posts.post_password',
    'wpdl_kop_volunteers.token_hash',
    'wpdl_kop_researcher_links.token_hash',
    'wpdl_kop_submission_followups.token',
    'suggested_edits.submitter_ip',
    'wpdl_comments.comment_author_IP',
];

// Safety net for tables added later: any column whose name matches is dropped everywhere.
const KOP_BQ_SECRET_COLUMN_RE = '/(password|passwd|^pass$|_pass$|token|secret|api_?key|private_?key|salt|activation_key|^encrypted_|credential)/i';

// Rows of WordPress meta tables whose meta_key looks like a secret are skipped.
const KOP_BQ_META_TABLES = ['wpdl_postmeta', 'wpdl_termmeta', 'wpdl_commentmeta'];
const KOP_BQ_SECRET_META_SQL = "LOWER(meta_key) NOT REGEXP 'password|token|secret|api_?key|private_?key|credential|oauth'";

const KOP_BQ_LOG_TABLE = '_sync_log';

// ---------------------------------------------------------------- settings

$home = getenv('HOME') ?: '/home/kidsover';
$cfg = [
    'project' => getenv('KOP_BQ_PROJECT') ?: 'kop-data',
    'dataset' => getenv('KOP_BQ_DATASET') ?: 'kop_prod',
    'key'     => getenv('KOP_BQ_KEY') ?: "$home/.kop-bigquery/sa-key.json",
    'wpconf'  => getenv('KOP_WP_CONFIG') ?: "$home/public_html/wp-config.php",
    'tmp'     => getenv('KOP_BQ_TMP') ?: "$home/bigquery-sync/tmp",
];

$opts = getopt('', ['list', 'verify', 'tables:']);
$only = isset($opts['tables']) ? array_filter(array_map('trim', explode(',', $opts['tables']))) : null;

function logline(string $msg): void { echo gmdate('Y-m-d H:i:s') . "Z  $msg\n"; }
function fail(string $msg): void { fwrite(STDERR, gmdate('Y-m-d H:i:s') . "Z  ERROR $msg\n"); exit(1); }

// ---------------------------------------------------------------- MySQL

function wp_db(string $wpconf): PDO {
    $src = @file_get_contents($wpconf);
    if ($src === false) fail("cannot read $wpconf");
    $get = function (string $name) use ($src): ?string {
        return preg_match("/define\\(\\s*['\"]" . $name . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]/", $src, $m) ? $m[1] : null;
    };
    $host = $get('DB_HOST') ?: 'localhost';
    $port = null;
    if (strpos($host, ':') !== false) [$host, $port] = explode(':', $host, 2);
    $dsn = "mysql:host=$host;dbname=" . $get('DB_NAME') . ';charset=utf8mb4' . ($port ? ";port=$port" : '');
    $pdo = new PDO($dsn, $get('DB_USER'), $get('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    return $pdo;
}

function table_excluded(string $t): bool {
    foreach (KOP_BQ_EXCLUDE_TABLES as $p) if (fnmatch($p, $t)) return true;
    return false;
}

function column_excluded(string $t, string $c): bool {
    return in_array("$t.$c", KOP_BQ_EXCLUDE_COLUMNS, true) || preg_match(KOP_BQ_SECRET_COLUMN_RE, $c);
}

/** @return array{tables: array<string, array>, excluded: string[]} table => [[name, mysql type, bq type], ...] */
function plan(PDO $db): array {
    $cols = $db->query("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE, c.COLUMN_TYPE, c.NUMERIC_PRECISION, c.NUMERIC_SCALE
        FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
        ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION")->fetchAll(PDO::FETCH_NUM);
    $tables = []; $excluded = []; $dropped = [];
    foreach ($cols as [$t, $c, $dt, $ct, $prec, $scale]) {
        if (table_excluded($t)) { $excluded[$t] = true; continue; }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) { $excluded[$t] = true; continue; }
        if (column_excluded($t, $c)) { $dropped[] = "$t.$c"; $tables[$t] = $tables[$t] ?? []; continue; }
        $tables[$t][] = [$c, strtolower($dt), bq_type(strtolower($dt), (int)$prec, (int)$scale)];
    }
    return ['tables' => array_filter($tables), 'excluded' => array_keys($excluded), 'dropped' => $dropped];
}

function bq_type(string $dt, int $prec, int $scale): string {
    switch ($dt) {
        case 'tinyint': case 'smallint': case 'mediumint': case 'int': case 'integer': case 'bigint':
        case 'year': case 'bit':
            return 'INT64';
        case 'decimal': case 'numeric':
            return ($scale <= 9 && $prec - $scale <= 29) ? 'NUMERIC' : 'BIGNUMERIC';
        case 'float': case 'double': case 'real':
            return 'FLOAT64';
        case 'date': return 'DATE';
        case 'datetime': return 'DATETIME';
        case 'timestamp': return 'TIMESTAMP';
        case 'time': return 'STRING';   // MySQL TIME can exceed 24h
        case 'binary': case 'varbinary': case 'tinyblob': case 'blob': case 'mediumblob': case 'longblob':
            return 'BYTES';
        default: return 'STRING';       // char, varchar, text, enum, set, json ...
    }
}

/** Convert one MySQL value to its NDJSON form for the BigQuery column type. */
function bq_value($v, string $bq) {
    if ($v === null) return null;
    switch ($bq) {
        case 'INT64':     return is_numeric($v) ? (string)$v : (string)hexdec(bin2hex($v)); // BIT comes as bytes
        case 'FLOAT64':   return is_numeric($v) ? (float)$v : null;
        case 'NUMERIC': case 'BIGNUMERIC': return (string)$v;
        case 'DATE':      return (strncmp($v, '0000', 4) === 0 || substr($v, 5, 2) === '00' || substr($v, 8, 2) === '00') ? null : $v;
        case 'DATETIME':
        case 'TIMESTAMP': return (strncmp($v, '0000', 4) === 0 || substr($v, 5, 2) === '00' || substr($v, 8, 2) === '00') ? null : $v;
        case 'BYTES':     return base64_encode($v);
        default:
            if (!mb_check_encoding($v, 'UTF-8')) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            return $v;
    }
}

/** Write table rows to a gzipped NDJSON file; return the row count. */
function export_table(PDO $db, string $table, array $cols, string $file): int {
    $sel = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c[0]) . '`', $cols));
    $where = in_array($table, KOP_BQ_META_TABLES, true) ? ' WHERE ' . KOP_BQ_SECRET_META_SQL : '';
    $stmt = $db->query("SELECT $sel FROM `$table`$where");
    $gz = gzopen($file, 'wb6');
    if (!$gz) fail("cannot write $file");
    $n = 0;
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $obj = [];
        foreach ($cols as $i => $c) $obj[$c[0]] = bq_value($row[$i], $c[2]);
        $line = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) fail("$table: json_encode failed on row $n: " . json_last_error_msg());
        gzwrite($gz, $line . "\n");
        $n++;
    }
    $stmt->closeCursor();
    gzclose($gz);
    return $n;
}

// ---------------------------------------------------------------- Google

final class BQ {
    private array $key; private ?string $token = null; private int $exp = 0;
    public function __construct(string $keyFile, public string $project, public string $dataset) {
        $k = json_decode(preg_replace("/^\xEF\xBB\xBF/", "", (string)@file_get_contents($keyFile)), true);
        if (!is_array($k) || empty($k['private_key']) || empty($k['client_email'])) fail("service account key unreadable: $keyFile");
        $this->key = $k;
    }

    private function token(): string {
        if ($this->token && time() < $this->exp - 300) return $this->token;
        $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss' => $this->key['client_email'], 'scope' => 'https://www.googleapis.com/auth/bigquery',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        if (!openssl_sign($unsigned, $sig, $this->key['private_key'], OPENSSL_ALGO_SHA256)) fail('openssl_sign failed');
        $r = $this->http('POST', 'https://oauth2.googleapis.com/token', http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($sig),
        ]), ['Content-Type: application/x-www-form-urlencoded'], false);
        if ($r['code'] !== 200) fail('token request failed: ' . $r['body']);
        $j = json_decode($r['body'], true);
        $this->token = $j['access_token']; $this->exp = $now + (int)$j['expires_in'];
        return $this->token;
    }

    /** @return array{code:int, body:string, headers:array} */
    public function http(string $method, string $url, $body = null, array $headers = [], bool $auth = true, $infile = null): array {
        for ($try = 1; ; $try++) {
            $ch = curl_init($url);
            $h = $headers;
            if ($auth) $h[] = 'Authorization: Bearer ' . $this->token();
            $respHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
                CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_TIMEOUT => 1800,
                CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
                    $p = strpos($line, ':');
                    if ($p) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    return strlen($line);
                },
            ]);
            if ($infile !== null) {
                $fh = fopen($infile, 'rb');
                curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($infile)]);
            } elseif ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (isset($fh)) fclose($fh);
            $retry = $resp === false || $code === 429 || $code >= 500;
            if ($retry && $try < 4) { sleep(5 * $try); continue; }
            if ($resp === false) fail("$method $url: $err");
            return ['code' => $code, 'body' => (string)$resp, 'headers' => $respHeaders];
        }
    }

    public function api(string $method, string $path, ?array $json = null): array {
        $r = $this->http($method, 'https://bigquery.googleapis.com/bigquery/v2/projects/' . $this->project . $path,
            $json === null ? null : json_encode($json), ['Content-Type: application/json']);
        $j = $r['body'] === '' ? [] : (json_decode($r['body'], true) ?? []);
        if ($r['code'] >= 300 && !($method === 'DELETE' && $r['code'] === 404)) {
            throw new RuntimeException("$method $path -> {$r['code']}: " . substr($r['body'], 0, 2000));
        }
        return $j;
    }

    /** Upload an NDJSON(.gz) file as a load job and wait for it. Returns output row count. */
    public function load(string $table, array $schema, string $file, string $disposition): int {
        $job = ['configuration' => ['load' => [
            'destinationTable' => ['projectId' => $this->project, 'datasetId' => $this->dataset, 'tableId' => $table],
            'schema' => ['fields' => $schema],
            'sourceFormat' => 'NEWLINE_DELIMITED_JSON',
            'writeDisposition' => $disposition,
            'createDisposition' => 'CREATE_IF_NEEDED',
            'schemaUpdateOptions' => $disposition === 'WRITE_APPEND' ? ['ALLOW_FIELD_ADDITION'] : [],
            'maxBadRecords' => 0,
        ]]];
        if ($disposition !== 'WRITE_APPEND') unset($job['configuration']['load']['schemaUpdateOptions']);
        $start = $this->http('POST', 'https://bigquery.googleapis.com/upload/bigquery/v2/projects/' . $this->project . '/jobs?uploadType=resumable',
            json_encode($job), ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: application/octet-stream']);
        if ($start['code'] !== 200 || empty($start['headers']['location'])) throw new RuntimeException("$table: load start {$start['code']}: {$start['body']}");
        $up = $this->http('PUT', $start['headers']['location'], null, ['Content-Type: application/octet-stream'], true, $file);
        if ($up['code'] >= 300) throw new RuntimeException("$table: upload {$up['code']}: " . substr($up['body'], 0, 2000));
        $j = json_decode($up['body'], true);
        $id = $j['jobReference']['jobId']; $loc = $j['jobReference']['location'] ?? 'US';
        while (($j['status']['state'] ?? '') !== 'DONE') {
            sleep(2);
            $j = $this->api('GET', "/jobs/$id?location=$loc");
        }
        if (!empty($j['status']['errorResult'])) {
            $errs = array_slice(array_map(fn($e) => $e['message'] ?? '', $j['status']['errors'] ?? [$j['status']['errorResult']]), 0, 5);
            throw new RuntimeException("$table: load failed: " . implode(' | ', $errs));
        }
        return (int)($j['statistics']['load']['outputRows'] ?? 0);
    }

    public function query(string $sql): array {
        $j = $this->api('POST', '/queries', ['query' => $sql, 'useLegacySql' => false, 'timeoutMs' => 60000, 'location' => 'US']);
        while (empty($j['jobComplete'])) {
            sleep(2);
            $j = $this->api('GET', '/queries/' . $j['jobReference']['jobId'] . '?location=US&timeoutMs=60000');
        }
        $names = array_map(fn($f) => $f['name'], $j['schema']['fields'] ?? []);
        return array_map(fn($r) => array_combine($names, array_map(fn($c) => $c['v'], $r['f'])), $j['rows'] ?? []);
    }

    public function tableIds(): array {
        $ids = []; $tok = '';
        do {
            $j = $this->api('GET', "/datasets/{$this->dataset}/tables?maxResults=1000" . ($tok ? '&pageToken=' . urlencode($tok) : ''));
            foreach ($j['tables'] ?? [] as $t) $ids[] = $t['tableReference']['tableId'];
            $tok = $j['nextPageToken'] ?? '';
        } while ($tok);
        return $ids;
    }

    /**
     * Without billing (BigQuery sandbox) every table expires 60 days after it was CREATED, and the expiry can't be
     * pushed past that; WRITE_TRUNCATE keeps the creation time. So a table with under 30 days left is recreated:
     * data tables are deleted just before their load, _sync_log is rebuilt with CREATE OR REPLACE ... AS SELECT.
     * With billing enabled tables have no expiry and this never fires.
     */
    public function expiresSoon(string $table): bool {
        $r = $this->http('GET', 'https://bigquery.googleapis.com/bigquery/v2/projects/' . $this->project . "/datasets/{$this->dataset}/tables/$table");
        if ($r['code'] === 404) return false;
        if ($r['code'] >= 300) throw new RuntimeException("GET table $table -> {$r['code']}: " . substr($r['body'], 0, 500));
        $exp = json_decode($r['body'], true)['expirationTime'] ?? null;
        return $exp !== null && (int)$exp < (time() + 30 * 86400) * 1000;
    }
}

// ---------------------------------------------------------------- main

$db = wp_db($cfg['wpconf']);
$plan = plan($db);
$tables = $plan['tables'];
if ($only !== null) {
    foreach ($only as $t) if (!isset($tables[$t])) fail("not a synced table: $t");
    $tables = array_intersect_key($tables, array_flip($only));
}

if (isset($opts['list'])) {
    echo "SYNCED (" . count($plan['tables']) . "):\n";
    foreach ($plan['tables'] as $t => $cols) echo "  $t (" . count($cols) . " columns)\n";
    echo "EXCLUDED TABLES (" . count($plan['excluded']) . "):\n  " . implode("\n  ", $plan['excluded']) . "\n";
    echo "DROPPED COLUMNS (" . count($plan['dropped']) . "):\n  " . implode("\n  ", $plan['dropped']) . "\n";
    exit(0);
}

$bq = new BQ($cfg['key'], $cfg['project'], $cfg['dataset']);

if (isset($opts['verify'])) {
    $bqCounts = [];
    foreach ($bq->query("SELECT table_id, row_count FROM `{$cfg['project']}.{$cfg['dataset']}.__TABLES__`") as $r) $bqCounts[$r['table_id']] = (int)$r['row_count'];
    $bad = 0;
    printf("%-42s %10s %10s  %s\n", 'table', 'mysql', 'bigquery', '');
    foreach ($tables as $t => $cols) {
        $where = in_array($t, KOP_BQ_META_TABLES, true) ? ' WHERE ' . KOP_BQ_SECRET_META_SQL : '';
        $st = $db->query("SELECT COUNT(*) FROM `$t`$where"); $my = (int)$st->fetchColumn(); $st->closeCursor();
        $b = $bqCounts[$t] ?? null;
        $ok = $b === $my;
        if (!$ok) $bad++;
        printf("%-42s %10d %10s  %s\n", $t, $my, $b === null ? 'missing' : $b, $ok ? 'ok' : 'DIFF');
    }
    $extra = array_diff(array_keys($bqCounts), array_keys($tables), [KOP_BQ_LOG_TABLE]);
    if ($extra && $only === null) { echo "In BigQuery but not synced: " . implode(', ', $extra) . "\n"; $bad++; }
    echo $bad ? "$bad table(s) differ\n" : "all " . count($tables) . " tables match\n";
    exit($bad ? 1 : 0);
}

if (!is_dir($cfg['tmp']) && !mkdir($cfg['tmp'], 0700, true)) fail("cannot create {$cfg['tmp']}");
$runId = gmdate('Ymd\THis\Z');
$runStarted = gmdate('Y-m-d H:i:s');
logline("run $runId: " . count($tables) . " tables -> {$cfg['project']}.{$cfg['dataset']}");

// One consistent snapshot for the whole export.
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

$log = []; $failed = 0;
foreach ($tables as $t => $cols) {
    $t0 = microtime(true);
    $file = $cfg['tmp'] . "/$t.ndjson.gz";
    $mysqlRows = null; $bqRows = null; $status = 'ok'; $error = null;
    try {
        $mysqlRows = export_table($db, $t, $cols, $file);
        $schema = array_map(fn($c) => ['name' => $c[0], 'type' => $c[2], 'mode' => 'NULLABLE'], $cols);
        if ($bq->expiresSoon($t)) {
            $bq->api('DELETE', "/datasets/{$cfg['dataset']}/tables/$t");
            logline("$t: recreating (sandbox expiry under 30 days)");
        }
        $bqRows = $bq->load($t, $schema, $file, 'WRITE_TRUNCATE');
        if ($bqRows !== $mysqlRows) { $status = 'count_mismatch'; $failed++; }
    } catch (Throwable $e) {
        $status = 'error'; $error = substr($e->getMessage(), 0, 1000); $failed++;
    }
    @unlink($file);
    $secs = round(microtime(true) - $t0, 1);
    logline(sprintf('%-42s mysql=%-8s bq=%-8s %5.1fs %s%s', $t, $mysqlRows ?? '-', $bqRows ?? '-', $secs, $status, $error ? " $error" : ''));
    $log[] = ['run_id' => $runId, 'run_started' => $runStarted, 'table_name' => $t, 'mysql_rows' => $mysqlRows,
              'bq_rows' => $bqRows, 'status' => $status, 'error' => $error, 'seconds' => $secs];
}
$db->exec('COMMIT');

// Remove tables that no longer sync (newly excluded, or dropped in MySQL). Only on a full run.
if ($only === null && $failed === 0) {
    foreach (array_diff($bq->tableIds(), array_keys($tables), [KOP_BQ_LOG_TABLE]) as $gone) {
        $bq->api('DELETE', "/datasets/{$cfg['dataset']}/tables/$gone");
        logline("deleted BigQuery table $gone (no longer synced)");
    }
}

// Append this run to _sync_log.
$runFinished = gmdate('Y-m-d H:i:s');
$file = $cfg['tmp'] . '/_sync_log.ndjson.gz';
$gz = gzopen($file, 'wb6');
foreach ($log as $r) gzwrite($gz, json_encode($r + ['run_finished' => $runFinished]) . "\n");
gzclose($gz);
$bq->load(KOP_BQ_LOG_TABLE, [
    ['name' => 'run_id', 'type' => 'STRING'], ['name' => 'run_started', 'type' => 'TIMESTAMP'],
    ['name' => 'run_finished', 'type' => 'TIMESTAMP'], ['name' => 'table_name', 'type' => 'STRING'],
    ['name' => 'mysql_rows', 'type' => 'INT64'], ['name' => 'bq_rows', 'type' => 'INT64'],
    ['name' => 'status', 'type' => 'STRING'], ['name' => 'error', 'type' => 'STRING'],
    ['name' => 'seconds', 'type' => 'FLOAT64'],
], $file, 'WRITE_APPEND');
@unlink($file);
try {
    if ($bq->expiresSoon(KOP_BQ_LOG_TABLE)) {
        $fq = "`{$cfg['project']}.{$cfg['dataset']}." . KOP_BQ_LOG_TABLE . '`';
        $bq->query("CREATE OR REPLACE TABLE $fq AS SELECT * FROM $fq");
        logline(KOP_BQ_LOG_TABLE . ': recreated (sandbox expiry under 30 days)');
    }
} catch (Throwable $e) { logline(KOP_BQ_LOG_TABLE . ' expiry: ' . $e->getMessage()); $failed++; }

logline("run $runId done: " . (count($tables) - $failed) . ' ok, ' . $failed . ' failed');
exit($failed ? 1 : 0);
