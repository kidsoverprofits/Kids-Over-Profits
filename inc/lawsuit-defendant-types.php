<?php
/**
 * Defendant types on lawsuits: who a case is brought against (a facility, an
 * operator or parent company, an educational consultant, a marketing or
 * referral company). A case can carry several.
 *
 * Stored in lawsuits.defendant_types as a JSON list of choice values from the
 * 'lawsuit-defendant-types' list (KOP Tools > Dropdown Lists). The column is
 * added the first time anything asks for it (kop_lawsuits_defendant_types_ready()).
 * Empty means nobody has said yet: types are set by hand in the lawsuit
 * editor, or by seeds/lawsuit-updates.json, never guessed from names.
 *
 * Read by templates/page-lawsuits.php (filter + line on each case),
 * api/save-lawsuit.php, js/admin-state-content.js (the editor boxes),
 * inc/lawsuit-updates.php and inc/open-data.php (the lawsuits data set).
 * No WordPress needed: api/ endpoints require this file directly.
 */

if (!function_exists('kop_choices')) {
    require_once __DIR__ . '/choice-lists.php';
}

if (!function_exists('kop_lawsuit_defendant_types')) {
    /** value => label. 'all' (checking a stored value) or 'offered' (the editor's boxes, the filter). */
    function kop_lawsuit_defendant_types($mode = 'all') {
        return kop_choices('lawsuit-defendant-types', null, $mode, false);
    }
}

if (!function_exists('kop_lawsuit_defendant_type_label')) {
    function kop_lawsuit_defendant_type_label($value) {
        $all = kop_lawsuit_defendant_types('all');
        return isset($all[$value]) ? (string) $all[$value] : ucfirst(str_replace('_', ' ', (string) $value));
    }
}

if (!function_exists('kop_lawsuit_defendant_types_clean')) {
    /** A stored or posted value (JSON text, list, or one value per line) -> known values, in list order, once each. */
    function kop_lawsuit_defendant_types_clean($value) {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $value);
        }
        $known = kop_lawsuit_defendant_types('all');
        $want = array();
        foreach ((array) $value as $v) {
            $v = trim((string) $v);
            if ($v !== '' && isset($known[$v])) $want[$v] = true;
        }
        $out = array();
        foreach (array_keys($known) as $k) {
            if (isset($want[$k])) $out[] = (string) $k;
        }
        return $out;
    }
}

if (!function_exists('kop_lawsuits_defendant_types_ready')) {
    /**
     * True once lawsuits has its defendant_types column, adding it the first
     * time. One check per request.
     */
    function kop_lawsuits_defendant_types_ready($pdo) {
        static $ready = null;
        if ($ready !== null) return $ready;
        if (!($pdo instanceof PDO)) return false;
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $cols = $pdo->query('PRAGMA table_info(lawsuits)')->fetchAll(PDO::FETCH_ASSOC);
                $have = false;
                foreach ($cols as $c) {
                    if (($c['name'] ?? '') === 'defendant_types') $have = true;
                }
                if (!$have && $cols) $pdo->exec('ALTER TABLE lawsuits ADD COLUMN defendant_types TEXT DEFAULT NULL');
                return $ready = (bool) $cols;
            }
            $stmt = $pdo->query("SHOW COLUMNS FROM lawsuits LIKE 'defendant_types'");
            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE lawsuits ADD COLUMN defendant_types text DEFAULT NULL COMMENT 'JSON list of lawsuit-defendant-types values (facility, operator, consultant, marketer)' AFTER defendants");
            }
            return $ready = true;
        } catch (Throwable $e) {
            error_log('kop_lawsuits_defendant_types_ready: ' . $e->getMessage());
            return $ready = false;
        }
    }
}
