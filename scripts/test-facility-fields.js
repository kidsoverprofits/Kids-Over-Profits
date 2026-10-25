/**
 * Offline checks for KOP_FacilityFields (end of js/data-form-modules/data-normalizer.js): a facility field
 * read by its v2 path gives the same value on the v2 document and on its legacy
 * projection (what the form edited, and what the server still sends), and a
 * write lands where each shape keeps it.
 *
 *   node scripts/test-facility-fields.js [copies.jsonl]
 *
 * With copies.jsonl (php scripts/normalize-dump.php --sqlite tmp/prod.sqlite --emit copies.jsonl)
 * every stored record is compared; without it, the fixtures only.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
let checks = 0;
const failures = [];
const check = (label, condition, detail) => {
    checks++;
    if (!condition && failures.length < 40) failures.push(label + (detail ? ' -- ' + detail : ''));
    if (!condition && failures.length >= 40) failures.length = 40;
};

const sandbox = { console };
sandbox.window = sandbox;
vm.createContext(sandbox);
for (const file of ['js/data-form-modules/data-normalizer.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), sandbox, { filename: file });
}
const N = sandbox.KOP_DataNormalizer;
const F = sandbox.KOP_FacilityFields;
const json = (v) => JSON.stringify(v === undefined ? null : v);

// The fields whose legacy spelling differs, compared on every record.
const PATHS = [
    'identification.name', 'identification.otherNames', 'identification.currentOperator', 'identification.currentOwners',
    'identification.otherOperators', 'identification.pastOperators', 'identification.investors',
    'location.raw', 'location.text', 'location.city', 'location.state', 'location.zip', 'location.country',
    'location.additionalLocations', 'location.formerLocations',
    'operatingPeriod.status', 'operatingPeriod.startYear', 'facilityDetails.type', 'facilityDetails.ageRange.min',
    'facilityDetails.isPrivatelyOwned', 'staff.notableStaff', 'ttiPractices', 'notes', 'survivorTestimony'
];
// The legacy projection leaves out a country that is the default and keeps what the form keeps.
const empty = (v) => v === undefined || v === null || v === '' || v === 'Unknown'
    || (Array.isArray(v) && !v.length) || (typeof v === 'object' && v !== null && !Array.isArray(v) && !Object.keys(v).length);
const same = (p, a, b) => {
    if (empty(a) && empty(b)) return true;
    if (p === 'location.country') return (a || null) === (b || null) || (a === 'United States' && !b);
    if (p === 'location.additionalLocations' || p === 'location.formerLocations') {
        const pick = (l) => (l || []).map((x) => [x.raw || '', x.city || '', x.state || null, p === 'location.formerLocations' ? [x.fromYear ?? null, x.toYear ?? null] : x.zip || '']);
        return json(pick(a)) === json(pick(b));
    }
    if (p === 'operatingPeriod.startYear' || p === 'facilityDetails.ageRange.min') return String(a ?? '') === String(b ?? '');
    return json(a) === json(b);
};

// Fixtures.
const legacy = {
    identification: { name: 'Example Ranch', currentOwner: 'Jane Roe', currentOwners: [] },
    otherOperators: ['Old Co'], address: '1 Main St, Provo, UT 84601', location: 'Provo, UT',
    addressParts: { street: '1 Main St', city: 'Provo', state: 'UT', zip: '84601' },
    locationDetails: { city: 'Provo', state: 'Utah', country: '', additionalLocations: [{ address: '2 Elm', city: 'Orem', state: 'UT' }],
        formerLocations: [{ city: 'Ogden', state: 'UT', fromYear: '1990', toYear: '' }] },
    isPrivatelyOwned: true
};
const doc = N.facilityToV2(legacy);
check('fixture: v2 is v2, legacy is not', F.isV2(doc) && !F.isV2(legacy));
for (const p of PATHS) check('fixture read ' + p, same(p, F.read(doc, p), F.read(legacy, p)), json(F.read(doc, p)) + ' vs ' + json(F.read(legacy, p)));
check('fixture: legacy owner joins currentOwners', json(F.read(legacy, 'identification.currentOwners')) === '["Jane Roe"]');
check('fixture: nested path into an item list', F.read(legacy, 'location.additionalLocations.0.city') === 'Orem');

const l2 = JSON.parse(JSON.stringify(legacy));
F.write(l2, 'location.city', 'Lehi');
F.write(l2, 'identification.otherOperators', ['New Co']);
F.write(l2, 'facilityDetails.isPrivatelyOwned', false);
F.write(l2, 'identification.currentOwners', ['A', 'B']);
check('write legacy: city where the form keeps it', l2.locationDetails.city === 'Lehi');
check('write legacy: otherOperators top level', json(l2.otherOperators) === '["New Co"]' && !('otherOperators' in l2.identification));
check('write legacy: isPrivatelyOwned top level', l2.isPrivatelyOwned === false);
check('write legacy: owners keep the single copy in step', l2.identification.currentOwner === 'A');
check('write legacy: normalizer reads what was written', N.facilityToV2(l2).identification.otherOperators[0] === 'New Co' && N.facilityToV2(l2).facilityDetails.isPrivatelyOwned === false);
const d2 = JSON.parse(JSON.stringify(doc));
F.write(d2, 'location.city', 'Lehi');
F.write(d2, 'identification.otherOperators', ['New Co']);
F.write(d2, 'location.state', 'Utah');
F.write(d2, 'location.country', 'USA');
check('write v2: a state is stored as its code, a country by name', d2.location.state === 'UT' && d2.location.country === 'United States', json(d2.location));
check('write v2: plain path', d2.location.city === 'Lehi' && d2.identification.otherOperators[0] === 'New Co' && !('otherOperators' in d2));

check('path map both ways', F.toV2Path('locationDetails.city') === 'location.city' && F.toV2Path('otherOperators') === 'identification.otherOperators'
    && F.toV2Path('locationDetails.additionalLocations') === 'location.additionalLocations' && F.toV2Path('identification.name') === 'identification.name'
    && F.toLegacyPath('location.formerLocations') === 'locationDetails.formerLocations' && F.toLegacyPath('notes') === 'notes');

// Both spellings in, the right one out, and a path already in the target spelling unchanged.
const SPELLINGS = [
    ['address', 'location.raw'], ['location', 'location.text'], ['locationDetails.city', 'location.city'],
    ['locationDetails.state', 'location.state'], ['locationDetails.country', 'location.country'], ['locationDetails.zip', 'location.zip'],
    ['locationDetails.additionalLocations', 'location.additionalLocations'], ['locationDetails.formerLocations.0.city', 'location.formerLocations.0.city'],
    ['otherOperators', 'identification.otherOperators'], ['isPrivatelyOwned', 'facilityDetails.isPrivatelyOwned'],
    ['identification.name', 'identification.name'], ['operatingPeriod.status', 'operatingPeriod.status'], ['notes', 'notes']
];
for (const [legacyPath, v2Path] of SPELLINGS) {
    check(`toV2Path(${legacyPath})`, F.toV2Path(legacyPath) === v2Path, F.toV2Path(legacyPath));
    check(`toV2Path(${v2Path}) unchanged`, F.toV2Path(v2Path) === v2Path, F.toV2Path(v2Path));
    check(`toLegacyPath(${v2Path})`, F.toLegacyPath(v2Path) === legacyPath, F.toLegacyPath(v2Path));
    check(`toLegacyPath(${legacyPath}) unchanged`, F.toLegacyPath(legacyPath) === legacyPath, F.toLegacyPath(legacyPath));
}

// Every stored record: its v2 document against its legacy projection.
const input = process.argv[2];
let records = 0;
if (input && fs.existsSync(input)) {
    const lines = fs.readFileSync(input, 'utf8').split('\n');
    const docs = {};
    for (const line of lines) {
        if (!line.trim()) continue;
        const row = JSON.parse(line);
        const id = row.source.replace(/^facilities_v2(?: legacy)? #/, '');
        if (row.source.startsWith('facilities_v2 legacy')) {
            const d = docs[id];
            if (!d) continue;
            records++;
            for (const p of PATHS) {
                const a = F.read(d, p);
                const b = F.read(row.raw, p);
                check(`#${id} ${p}`, same(p, a, b), json(a) + ' vs ' + json(b));
            }
        } else if (row.source.startsWith('facilities_v2 #')) {
            docs[id] = row.raw;
        }
    }
}

if (failures.length) {
    console.log(`FAIL (${checks} checks, ${records} records)`);
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log(`OK ${checks} checks` + (records ? `, ${records} records` : ''));
