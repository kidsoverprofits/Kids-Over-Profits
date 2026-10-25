"""
The data form's move to v2 (docs/plan 4.3 item 17 c) is done one module per
commit, each with no change in behaviour. This checks that on the live public
form (/tti-data-submission/): one run with the live assets, one with the
working tree's js/ served in their place (and js/data-form-modules/
facility-fields.js added after the normalizer, for pages that do not list it
yet). In each run a set of real projects is opened facility by facility and
the form is read back: every field's value, every list's items, the private
ownership toggle, the facility the toolbar search finds, and window.formData.
Both runs must give the same.

    python scripts/test-data-form-equivalence.py [--projects 12] [--show]
"""
import argparse, json, os, re, sys

from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__))).replace(os.sep, "/") + "/"
URL = "https://kidsoverprofits.org/tti-data-submission/"
FIELDS_JS = REPO + "js/data-form-modules/facility-fields.js"

ap = argparse.ArgumentParser()
ap.add_argument("--projects", type=int, default=12)
ap.add_argument("--show", action="store_true")
ap.add_argument("--v2-probes", default="private,keyword,operator,location",
                help="probes that must give the same on a facility and on its v2 document")
args = ap.parse_args()

READ_FORM = r"""() => {
    const out = {};
    document.querySelectorAll('.container input, .container textarea, .container select').forEach((el, i) => {
        if (el.type === 'file' || el.type === 'password' || el.closest('#kop-wizard')) return;
        const key = (el.dataset.path || el.dataset.field || el.name || el.id || ('#' + i)) + '|' + i;
        out[key] = el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value;
    });
    document.querySelectorAll('.container .array-container[data-path]').forEach((c) => {
        out['list:' + c.dataset.path] = Array.from(c.querySelectorAll('input, textarea')).map((e) => e.value);
    });
    return out;
}"""

PICK = r"""(n) => Object.entries(window.projects || {})
    .filter(([name, p]) => { const d = p && (p.data || p); return d && Array.isArray(d.facilities) && d.facilities.length; })
    .map(([name, p]) => [name, (p.data || p).facilities.length])
    .sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]))
    .filter((e, i, all) => i < n / 2 || i % Math.max(1, Math.floor(all.length / n)) === 0)
    .slice(0, n).map((e) => e[0])"""

OPEN = r"""async (name) => {
    window.loadProject(name);
    await new Promise((r) => setTimeout(r, 400));
    const n = (window.formData.facilities || []).length;
    return n;
}"""

SHOW = r"""async (i) => {
    window.currentFacilityIndex = i;
    if (window.updateAllUI) window.updateAllUI();
    await new Promise((r) => setTimeout(r, 150));
    const toggle = document.getElementById('private-ownership-toggle');
    const f = window.formData.facilities[i];
    // What the module functions say about this facility; in the working tree also about it as a v2 document.
    const probe = (fac) => {
        const out = {};
        if (window.resolvePrivateOwnership) out.private = window.resolvePrivateOwnership(fac);
        if (window.extractDataPointsForSearch) {
            const id = fac.identification || {};
            const terms = [String(id.name || '').split(' ')[0], 'Utah', 'UT', 'Provo', 'Academy', 'Ranch',
                String(id.currentOperator || '').split(' ')[0]].filter((t) => t && t.length > 1);
            for (const type of ['keyword', 'operator', 'location']) {
                for (const t of terms) out[type + ':' + t] = window.extractDataPointsForSearch(fac, type, t, window.formData.operator || null);
            }
        }
        return out;
    };
    const legacy = probe(f);
    const N = window.KOP_DataNormalizer;
    const v2 = window.KOP_FacilityFields && N ? probe(N.facilityToV2(JSON.parse(JSON.stringify(f)))) : null;
    return { toggle: toggle ? toggle.checked : null, probe: legacy, probeV2: v2 };
}"""


v2_checks = []


def run(live_only, names=None):
    errors = []
    result = {}
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(viewport={"width": 1280, "height": 900})
        pg.on("pageerror", lambda e: errors.append(str(e)))

        def serve(route, request):
            rel = re.sub(r"^.*/themes/child/", "", request.url.split("?")[0])
            path = REPO + rel
            if not os.path.isfile(path):
                return route.continue_()
            body = open(path, "rb").read()
            if rel.endswith("js/data-form-modules/data-normalizer.js"):
                body += b"\n" + open(FIELDS_JS, "rb").read()
            route.fulfill(body=body, content_type="application/javascript")

        if not live_only:
            pg.route(re.compile(r"/themes/child/js/.*\.js"), serve)
        pg.goto(URL, wait_until="domcontentloaded", timeout=90000)
        pg.wait_for_function("window.formReady === true", timeout=180000)
        if names is None:
            names = pg.evaluate(PICK, args.projects)
        for name in names:
            count = pg.evaluate(OPEN, name)
            for i in range(min(count, 6)):
                extra = pg.evaluate(SHOW, i)
                v2 = extra.pop("probeV2")
                if v2 is not None:
                    v2_checks.append((f"{name} [{i}]", extra["probe"], v2))
                result[f"{name} [{i}]"] = {"form": pg.evaluate(READ_FORM), **extra}
            result[f"{name} formData"] = pg.evaluate("JSON.parse(JSON.stringify(window.formData))")
        b.close()
    return names, result, errors


names, live, live_errors = run(True)
_, tree, tree_errors = run(False, names)
print(f"{len(names)} projects, {sum(1 for k in live if not k.endswith('formData'))} facilities")
diffs = []
for key in live:
    a, b = live[key], tree.get(key)
    if json.dumps(a, sort_keys=True) != json.dumps(b, sort_keys=True):
        if isinstance(a, dict) and isinstance(b, dict) and "form" in a:
            fa, fb = a["form"], b["form"]
            keys = sorted(set(fa) | set(fb))
            bad = [k for k in keys if fa.get(k) != fb.get(k)]
            extra = [("toggle", a["toggle"], b["toggle"])] if a["toggle"] != b["toggle"] else []
            extra += [("probe " + k, a["probe"].get(k), b["probe"].get(k)) for k in sorted(set(a["probe"]) | set(b["probe"]))
                      if json.dumps(a["probe"].get(k)) != json.dumps(b["probe"].get(k))]
            diffs.append((key, [(k, fa.get(k), fb.get(k)) for k in bad[:6]] + extra))
        else:
            diffs.append((key, "formData differs"))
# A module moved onto KOP_FacilityFields must say the same about a facility in either shape (ready for the flip).
# --v2-modules lists the probes that must already agree (the moved modules' functions).
for key, a, b in v2_checks:
    for probe in a:
        kind = probe.split(":")[0]
        if kind in args.v2_probes and json.dumps(a[probe]) != json.dumps(b.get(probe)):
            diffs.append((key + " as v2", [(probe, a[probe], b.get(probe))]))
new_errors = [e for e in tree_errors if e not in live_errors]
if new_errors:
    print("New page errors:", *new_errors[:10], sep="\n  ")
for key, d in diffs[:20]:
    print("DIFF", key)
    if isinstance(d, list):
        for k, x, y in d:
            print(f"   {k}: live={json.dumps(x)[:120]} tree={json.dumps(y)[:120]}")
    else:
        print("  ", d)
if args.show:
    print("names:", names)
ok = not diffs and not new_errors
print("OK, same form and data on both" if ok else f"FAIL: {len(diffs)} differ, {len(new_errors)} new errors")
sys.exit(0 if ok else 1)
