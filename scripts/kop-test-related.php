<?php
/**
 * A TEMP related_facilities table on $pdo (SQLite reads temp tables before
 * main ones; the mirror is never written), shaped as inc/related-facilities.php
 * makes it in MySQL. With $move, the mirror's young_adult_programs rows are
 * copied in by the real move (kop_ya_move_to_related()), at their new ids.
 * Tests of related records and young adult programs call this.
 */
function kop_test_related_table(PDO $pdo, $move = false) {
    $pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
    $pdo->exec('DROP TABLE IF EXISTS temp.related_facilities');
    $pdo->exec("CREATE TEMP TABLE related_facilities (
        id INTEGER PRIMARY KEY, category TEXT NOT NULL DEFAULT '', review TEXT NOT NULL DEFAULT 'approved',
        unique_name TEXT NOT NULL UNIQUE, json_data TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        name TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.name')) STORED,
        name_key TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.nameKey')) STORED,
        state TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.state')) STORED,
        city TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.city')) STORED,
        country TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.country')) STORED,
        status TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.status')) STORED,
        facility_type TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.facilityDetails.type')) STORED,
        start_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.startYear')) STORED,
        end_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.endYear')) STORED)");
    if (function_exists('kop_related_flush')) kop_related_flush();
    if (!$move || !function_exists('kop_ya_move_to_related')) return array();
    $writes = !empty($GLOBALS['kop_test_wpdb_writes']);
    $GLOBALS['kop_test_wpdb_writes'] = true;
    // The company links the move makes go to a temp copy too.
    $op = (isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb']->prefix : 'wpdl_') . 'kop_operator_facilities';
    if (!$pdo->query("SELECT name FROM sqlite_temp_master WHERE name = '$op'")->fetch()) {
        try { $pdo->exec("CREATE TEMP TABLE $op AS SELECT * FROM main.$op"); } catch (PDOException $e) {}
    }
    $log = kop_ya_move_to_related($pdo);
    $GLOBALS['kop_test_wpdb_writes'] = $writes;
    return $log;
}
