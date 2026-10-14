#!/usr/bin/env node
/**
 * js/wiki-parser.js staffRoleTitle() == api/sync-wiki-facilities.php kop_wiki_staff_title() on every
 * wiki staff member's role and write-up in tmp/prod.sqlite (approved and published entries), plus a few
 * made-up ones. PHP reads the mirror and prints each role, write-up and its own answer as JSON.
 *
 *   node scripts/test-wiki-staff-title.js --php=<Local php.exe> [--db=tmp/prod.sqlite]
 */
'use strict';
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { staffRoleTitle } = require('../js/wiki-parser.js');

const arg = name => (process.argv.find(a => a.startsWith(`--${name}=`)) || '').split('=').slice(1).join('=');
const php = arg('php') || 'php';
const root = path.resolve(__dirname, '..');
const db = arg('db') || path.join(root, 'tmp', 'prod.sqlite');

const made = [
    ['current Clinical Director', ''],
    ['Co-Founder and current Clinical Director', ''],
    ['current Chief Operating', ''],
    ['one', 'is one of the Founders and the Chief Clinical Officer of Embark Behavioral Health.'],
    ['one', 'He began his career as a youth pastor. Later he was one of the Executive Directors of X.'],
    ['married to Rob Dias', ''],
    ['a drill sergeant', ''],
    ['“one of the founders', 'was "one of the" founders.'],
];
const script = `
define('ABSPATH', 'x/'); define('KOP_DB_CONFIG_LOADED', 1);
require ${JSON.stringify(path.join(root, 'api', 'sync-wiki-facilities.php'))};
$rows = json_decode(${JSON.stringify(JSON.stringify(made))}, true);
$db = ${JSON.stringify(db)};
if (is_file($db)) {
    $pdo = new PDO('sqlite:' . $db);
    foreach ($pdo->query("SELECT json_data FROM wiki_submissions WHERE status IN ('approved', 'published')") as $r) {
        $j = json_decode($r['json_data'], true);
        foreach ((array) ($j['staffMembers'] ?? array()) as $s) if (is_array($s)) $rows[] = array((string) ($s['role'] ?? ''), (string) ($s['bio'] ?? ''));
    }
}
$out = array();
foreach ($rows as $r) $out[] = array($r[0], $r[1], kop_wiki_staff_title($r[0], $r[1]));
echo json_encode($out);
`;
const tmpFile = path.join(require('os').tmpdir(), `kop-wiki-title-${process.pid}.php`);
fs.writeFileSync(tmpFile, '<?php\n' + script);
let rows;
try {
    const ext = path.join(path.dirname(php), 'ext');
    const flags = fs.existsSync(ext) ? ['-n', `-d`, `extension_dir=${ext}`, '-d', 'extension=pdo_sqlite', '-d', 'extension=mbstring'] : [];
    rows = JSON.parse(execFileSync(php, [...flags, tmpFile], { maxBuffer: 1 << 28 }).toString());
} finally {
    fs.unlinkSync(tmpFile);
}

let fails = 0;
const seen = new Set();
for (const [role, bio, want] of rows) {
    const got = staffRoleTitle(role, bio);
    if (got !== want) {
        const key = role + '|' + want + '|' + got;
        if (!seen.has(key)) {
            seen.add(key);
            if (fails < 15) console.log(`FAIL "${role}" -> PHP "${want}", JS "${got}"`);
        }
        fails++;
    }
}
console.log(`${rows.length} roles (${made.length} made up): ${fails ? fails + ' differ' : 'JS == PHP on every one'}`);
process.exit(fails ? 1 : 0);
