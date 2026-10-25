/**
 * Offline checks for the data form's Download JSON / Copy JSON
 * (buildFormExport() in js/data-form/utilities.js): each facility is exported
 * as its canonical document (KOP_DataNormalizer.facilityToV2, the shape in
 * docs/FACILITY-SCHEMA.md) without provenance or legacy, a blank form exports
 * the full field set, and the rest of the form data is left as it is.
 *
 *   node scripts/test-data-form-export.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
let checks = 0;
const failures = [];
const check = (label, condition, detail) => {
    checks++;
    if (!condition) failures.push(label + (detail ? ' -- ' + detail : ''));
};

const sandbox = { console, document: { readyState: 'complete', addEventListener() {}, getElementById() { return null; }, querySelector() { return null; }, querySelectorAll() { return []; } } };
sandbox.window = sandbox;
vm.createContext(sandbox);
for (const file of ['js/data-form-modules/data-normalizer.js', 'js/data-form-modules/project.js', 'js/data-form/utilities.js']) {
    vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), sandbox, { filename: file });
}
const N = sandbox.KOP_DataNormalizer;
const createNew = sandbox.KOP_Project && sandbox.KOP_Project.createNewProjectData;
check('createNewProjectData is reachable', typeof createNew === 'function');

// The top-level keys of a stored document, minus the two left out.
const expectedKeys = Object.keys(N.facilityToV2({})).filter((k) => k !== 'provenance' && k !== 'legacy');
const exportNow = () => JSON.parse(JSON.stringify(sandbox.buildFormExport()));

// A blank form.
sandbox.formData = createNew();
let out = exportNow();
check('blank form: one facility', Array.isArray(out.facilities) && out.facilities.length === 1);
let doc = out.facilities[0] || {};
check('blank form: every v2 key in order', JSON.stringify(Object.keys(doc)) === JSON.stringify(expectedKeys),
    JSON.stringify(Object.keys(doc)));
check('blank form: no provenance/legacy', !('provenance' in doc) && !('legacy' in doc));
check('blank form: v2 schema_version', doc.schema_version === N.facilityToV2({}).schema_version);
check('blank form: identification block', doc.identification && typeof doc.identification === 'object' && 'name' in doc.identification);
check('blank form: resources full set', Object.keys(doc.resources || {}).length === Object.keys(N.facilityToV2({}).resources).length);
check('blank form: operator kept', JSON.stringify(out.operator) === JSON.stringify(sandbox.formData.operator));

// No facilities at all still exports one blank document.
sandbox.formData = { operator: { name: 'X' } };
out = exportNow();
check('no facilities: one blank document', out.facilities && out.facilities.length === 1
    && JSON.stringify(Object.keys(out.facilities[0])) === JSON.stringify(expectedKeys));

// A filled legacy facility, plus an unknown key that would go to legacy.
const form = createNew();
form.facilities[0].identification.name = 'Example Ranch';
form.facilities[0].locationDetails = Object.assign(form.facilities[0].locationDetails || {}, { city: 'Provo', state: 'UT' });
form.facilities[0].operatingPeriod = Object.assign(form.facilities[0].operatingPeriod || {}, { startYear: '1998', status: 'Closed' });
form.facilities[0].someOddKey = 'kept out';
form.facilities.push({ identification: { name: 'Second Campus' } });
sandbox.formData = form;
out = exportNow();
check('filled: two facilities', out.facilities.length === 2);
doc = out.facilities[0];
check('filled: name', doc.identification.name === 'Example Ranch');
check('filled: state code', doc.location.state === 'UT', JSON.stringify(doc.location));
check('filled: start year is a number', doc.operatingPeriod.startYear === 1998, JSON.stringify(doc.operatingPeriod));
check('filled: status', doc.operatingPeriod.status === 'Closed');
check('filled: unknown key not exported', !JSON.stringify(doc).includes('kept out'));
check('filled: same as facilityToV2 minus provenance/legacy', (() => {
    const want = N.facilityToV2(form.facilities[1]);
    delete want.provenance; delete want.legacy;
    return JSON.stringify(want) === JSON.stringify(out.facilities[1]);
})());
check('filled: form data untouched', sandbox.formData.facilities[0].someOddKey === 'kept out'
    && sandbox.formData.facilities[0].operatingPeriod.startYear === '1998');

// A v2 document in the form exports the same document.
const stored = N.facilityToV2(form.facilities[0]);
sandbox.formData = { facilities: [JSON.parse(JSON.stringify(stored))] };
out = exportNow();
const want = JSON.parse(JSON.stringify(stored));
delete want.provenance; delete want.legacy;
check('round trip: v2 -> form -> export', JSON.stringify(out.facilities[0]) === JSON.stringify(want));

if (failures.length) {
    console.log(`FAIL ${failures.length} of ${checks}`);
    failures.forEach((f) => console.log('  - ' + f));
    process.exit(1);
}
console.log(`OK ${checks} checks`);
