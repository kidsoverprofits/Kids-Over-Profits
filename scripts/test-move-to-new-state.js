/**
 * Offline checks for "Move to a new state" in the admin data form
 * (moveFacilityToNewState(), js/data-form-modules/admin-data-page.js): a legacy
 * facility comes out exactly as the code before the move to v2 left it (the
 * version at the git ref given, default the commit before it), and a v2
 * document comes out as the normalized form of that result.
 *
 *   node scripts/test-move-to-new-state.js [git-ref]
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { execSync } = require('child_process');

const root = path.join(__dirname, '..');
const ref = process.argv[2] || '74bbb190';
let checks = 0;
const failures = [];
const check = (label, ok, detail) => { checks++; if (!ok) failures.push(label + (detail ? ' -- ' + detail : '')); };

function sandbox(adminSource) {
    const sb = {
        console,
        confirm: () => true,
        prompt: (q) => (q.includes('STARTED') ? '1998' : '2004'),
        document: { querySelector: () => null, getElementById: () => null, readyState: 'complete', addEventListener() {} },
        setTimeout: () => {}
    };
    sb.window = sb;
    vm.createContext(sb);
    vm.runInContext(fs.readFileSync(path.join(root, 'js/data-form-modules/data-normalizer.js'), 'utf8'), sb);
    vm.runInContext(adminSource, sb);
    return sb;
}

const now = fs.readFileSync(path.join(root, 'js/data-form-modules/admin-data-page.js'), 'utf8');
let before;
try {
    before = execSync(`git show ${ref}:js/data-form-modules/admin-data-page.js`, { cwd: root, encoding: 'utf8' });
} catch (e) {
    console.error('Cannot read the earlier admin-data-page.js at ' + ref);
    process.exit(2);
}

const legacyFacility = () => ({
    identification: { name: 'Example Ranch' },
    address: '1 Main St, Provo, UT 84601', location: 'Provo, UT',
    locationDetails: { city: 'Provo', state: 'Utah', country: 'United States', zip: '84601', additionalLocations: [] },
    operatingPeriod: { startYear: '1998', status: 'Closed' }
});

const run = (source, facility) => {
    const sb = sandbox(source);
    sb.formData = { facilities: [facility] };
    sb.currentFacilityIndex = 0;
    sb.KOP_AdminDataPage.moveFacilityToNewState();
    return { sb, out: JSON.parse(JSON.stringify(sb.formData.facilities[0])) };
};

const a = run(before, legacyFacility()).out;
const { sb, out: b } = run(now, legacyFacility());
check('legacy facility: same result as before', JSON.stringify(a) === JSON.stringify(b), JSON.stringify(b));

const N = sb.KOP_DataNormalizer;
const strip = (d) => { const c = JSON.parse(JSON.stringify(d)); delete c.provenance; c.operatingPeriod.notes = []; return c; };
const v2in = N.facilityToV2(legacyFacility());
const v2out = run(now, JSON.parse(JSON.stringify(v2in))).out;
const want = strip(N.facilityToV2(a));
const got = strip(N.facilityToV2(v2out));
check('v2 document: the former location as the legacy result normalizes to', JSON.stringify(got.location) === JSON.stringify(want.location),
    JSON.stringify(got.location) + ' vs ' + JSON.stringify(want.location));
check('v2 document: stays a v2 document with typed years', v2out.schema_version === v2in.schema_version
    && v2out.location.formerLocations[0].fromYear === 1998 && v2out.location.formerLocations[0].state === 'UT' && v2out.location.state === null,
    JSON.stringify(v2out.location));

if (failures.length) {
    console.log(`FAIL ${failures.length} of ${checks}`);
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log(`OK ${checks} checks`);
