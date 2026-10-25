"""
New records on the live public form (/tti-data-submission/) with the working tree's js/ served in place of
the live files: a new project's facility, the guided start's new facility in a state, and Add facility each
start as a blank v2 document (docs/FACILITY-SCHEMA.md), take what is typed into their v2 fields, and the
"unnamed facility with data" check passes a blank one. With --live, the live files (to compare).

    python scripts/test-new-facility-v2.py [--live]
"""
import json, os, re, sys

from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__))).replace(os.sep, "/") + "/"
URL = "https://kidsoverprofits.org/tti-data-submission/"
LIVE = "--live" in sys.argv

CHECK = r"""async () => {
    const N = window.KOP_DataNormalizer;
    const v2 = (f) => !!(f && N.isV2Facility(f));
    const out = {};
    const blank = window.KOP_Project.createNewProjectData();
    out.newProject = { v2: v2(blank.facilities[0]), keys: Object.keys(blank.facilities[0]).length,
                       resources: Object.keys((blank.facilities[0] || {}).resources || {}).length };

    // The guided start's "add a facility" in a state (data-wizard.js loadNew), done the way it does it.
    await window.loadProject('UTAH');
    await new Promise((r) => setTimeout(r, 500));
    const f = window.KOP_Project.createNewProjectData().facilities[0];
    f.identification.name = 'Zzq Wizard Ranch';
    window.kopSetFacilityField(f, 'facilityDetails.isPrivatelyOwned', true);
    window.kopSetFacilityField(f, 'location.state', 'Utah');
    window.kopSetFacilityField(f, 'location.text', 'Utah');
    window.formData.facilities.push(f);
    window.currentFacilityIndex = window.formData.facilities.length - 1;
    window.updateAllUI();
    await new Promise((r) => setTimeout(r, 200));
    const city = document.querySelector('.facility-field[data-field="location.city"], .facility-field[data-field="locationDetails.city"]');
    city.value = 'Kanab';
    city.dispatchEvent(new Event('input', { bubbles: true }));
    const added = window.formData.facilities[window.currentFacilityIndex];
    out.wizard = { v2: v2(added), state: v2(added) ? added.location.state : (added.locationDetails || {}).state,
                   city: v2(added) ? added.location.city : (added.locationDetails || {}).city,
                   private: window.kopFacilityField(added, 'facilityDetails.isPrivatelyOwned'),
                   stateShown: (document.querySelector('.facility-field[data-field="location.state"], .facility-field[data-field="locationDetails.state"]') || {}).value };

    // Add facility (ui-actions.js) outside the locations view asks nothing.
    const before = window.formData.facilities.length;
    window.KOP_UI_Actions && window.KOP_UI_Actions.addFacility ? window.KOP_UI_Actions.addFacility() : window.addFacility && window.addFacility();
    const fresh = window.formData.facilities[window.formData.facilities.length - 1];
    out.addFacility = { added: window.formData.facilities.length - before, v2: v2(fresh) };
    return out;
}"""

errors = []
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1280, "height": 900})
    pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.on("dialog", lambda d: d.accept())

    def serve(route, request):
        rel = re.sub(r"^.*/themes/child/", "", request.url.split("?")[0])
        if not os.path.isfile(REPO + rel):
            return route.continue_()
        route.fulfill(path=REPO + rel, content_type="application/javascript")

    if not LIVE:
        pg.route(re.compile(r"/themes/child/js/.*\.js"), serve)
    pg.goto(URL, wait_until="domcontentloaded", timeout=90000)
    pg.wait_for_function("window.formReady === true", timeout=180000)
    out = pg.evaluate(CHECK)
    b.close()

print(json.dumps(out, indent=1))
print("page errors:", errors)
if LIVE:
    sys.exit(0)
ok = (out["newProject"]["v2"] and out["newProject"]["resources"] >= 20
      and out["wizard"]["v2"] and out["wizard"]["state"] == "UT" and out["wizard"]["city"] == "Kanab" and out["wizard"]["private"] is True
      and out["addFacility"]["v2"] and not errors)
print("OK" if ok else "FAIL")
sys.exit(0 if ok else 1)
