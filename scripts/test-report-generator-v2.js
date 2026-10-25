/**
 * Offline checks for the data form's report (js/data-form/data-report-generator.js)
 * during the move to v2: for real facilities, the working tree's report of the
 * legacy copy is the same as the earlier code's (git ref, default the commit
 * before the move), and the report of the facility's v2 document is the same
 * as of its legacy copy.
 *
 *   node scripts/test-report-generator-v2.js [copies.jsonl] [--ref <git ref>] [--limit 400]
 *
 * copies.jsonl: php scripts/normalize-dump.php --sqlite tmp/prod.sqlite --emit copies.jsonl
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { execSync } = require('child_process');

const root = path.join(__dirname, '..');
const args = process.argv.slice(2);
const opt = (name, dflt) => { const i = args.indexOf(name); return i === -1 ? dflt : args[i + 1]; };
const input = args.find((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--')));
const ref = opt('--ref', 'bd600a61');
const limit = parseInt(opt('--limit', '400'), 10);

function load(generatorSource) {
    // escapeHtml() writes textContent and reads innerHTML.
    const createElement = () => {
        let text = '';
        return {
            set textContent(v) { text = String(v); },
            get textContent() { return text; },
            get innerHTML() { return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        };
    };
    const doc = { readyState: 'complete', addEventListener() {}, getElementById: () => null, querySelector: () => null, querySelectorAll: () => [], createElement };
    const sb = { console: { log() {}, warn() {}, error: console.error, info() {}, debug() {} }, document: doc, URL };
    sb.window = sb;
    vm.createContext(sb);
    vm.runInContext(fs.readFileSync(path.join(root, 'js/data-form-modules/data-normalizer.js'), 'utf8'), sb);
    vm.runInContext(fs.readFileSync(path.join(root, 'js/data-form/report-config.js'), 'utf8'), sb);
    vm.runInContext(generatorSource + '\n;window.__facilitiesSection = generateFacilitiesSection;', sb);
    return sb;
}

const tree = load(fs.readFileSync(path.join(root, 'js/data-form/data-report-generator.js'), 'utf8'));
const before = load(execSync(`git show ${ref}:js/data-form/data-report-generator.js`, { cwd: root, encoding: 'utf8' }));
const N = tree.KOP_DataNormalizer;

const report = (sb, facility) => {
    sb.formData = { facilities: [JSON.parse(JSON.stringify(facility))] };
    vm.runInContext('formData = window.formData', sb);
    const section = sb.__facilitiesSection();
    const full = sb.FacilityReportGenerator.renderReportHTML({ facilities: sb.formData.facilities, operator: {} }, true);
    return section + '\n' + full;
};

let facilities = [];
if (input && fs.existsSync(input)) {
    for (const line of fs.readFileSync(input, 'utf8').split('\n')) {
        if (!line.trim()) continue;
        const row = JSON.parse(line);
        if (row.source.startsWith('facilities_v2 legacy')) facilities.push(row.raw);
    }
    const step = Math.max(1, Math.floor(facilities.length / limit));
    facilities = facilities.filter((f, i) => i % step === 0).slice(0, limit);
} else {
    facilities = [{
        identification: { name: 'Example Ranch', currentOperator: 'Example Co' }, address: '1 Main St, Provo, UT 84601', location: 'Provo, UT',
        locationDetails: { city: 'Provo', state: 'UT' }, otherOperators: ['Old Co'], operatingPeriod: { startYear: 1998, status: 'Closed' }
    }];
}

let same = 0;
const failures = [];
for (const f of facilities) {
    const name = (f.identification || {}).name;
    const a = report(before, f);
    const b = report(tree, f);
    if (a !== b) { failures.push(`${name}: legacy report changed`); continue; }
    const v2 = report(tree, N.facilityFromV2 ? N.facilityToV2(JSON.parse(JSON.stringify(f))) : f);
    if (v2 !== b) {
        let i = 0;
        while (i < v2.length && v2[i] === b[i]) i++;
        failures.push(`${name}: v2 report differs near ...${b.slice(Math.max(0, i - 60), i + 60).replace(/\s+/g, ' ')}... | v2: ...${v2.slice(Math.max(0, i - 60), i + 60).replace(/\s+/g, ' ')}...`);
        continue;
    }
    same++;
}

if (failures.length) {
    console.log(`FAIL ${failures.length} of ${facilities.length}`);
    failures.slice(0, 12).forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log(`OK ${same} facilities: legacy report as before, v2 report the same`);
