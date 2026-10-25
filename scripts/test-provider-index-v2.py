"""
/mental-health-providers/ (js/provider-index.js) with provider records in either shape: the live page with
the working tree's provider-index.js, fed the live providers feed (api/get-providers-only.php) as it is and
with each provider converted to its v2 document (KOP_DataNormalizer.facilityToV2(), providerDetails at the
top). Every card, opened, must read the same, and the same as the live page.

    python scripts/test-provider-index-v2.py
"""
import json, os, re, subprocess, sys, tempfile, urllib.request

from playwright.sync_api import sync_playwright

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__))).replace(os.sep, "/") + "/"
URL = "https://kidsoverprofits.org/mental-health-providers/"
FEED = "https://kidsoverprofits.org/wp-content/themes/child/api/get-providers-only.php"

req = urllib.request.Request(FEED, headers={"User-Agent": "Mozilla/5.0 Chrome/120"})
live_feed = json.load(urllib.request.urlopen(req, timeout=60))

# The same feed with each provider as its v2 document.
convert = r"""
const fs = require('fs'), vm = require('vm');
const sb = { console }; sb.window = sb; vm.createContext(sb);
vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), sb);
const feed = JSON.parse(fs.readFileSync(0, 'utf8'));
const inner = (p) => { let d = p.payload || {}; for (let i = 0; i < 3 && d && !d.facilities && d.data; i++) d = d.data; return d; };
for (const p of feed.projects) { const d = inner(p); if (Array.isArray(d.facilities)) d.facilities = d.facilities.map((f) => sb.KOP_DataNormalizer.facilityToV2(f)); }
process.stdout.write(JSON.stringify(feed));
"""
v2_feed = json.loads(subprocess.run(["node", "-e", convert, REPO + "js/data-form-modules/data-normalizer.js"],
                                    input=json.dumps(live_feed), capture_output=True, text=True, check=True).stdout)

READ = """async () => {
    for (let i = 0; i < 60 && !document.querySelector('details.provider-card'); i++) await new Promise((r) => setTimeout(r, 500));
    document.querySelectorAll('details.provider-card').forEach((d) => { d.open = true; });
    const c = document.querySelector('.provider-grid') || document.body;
    return Array.from(document.querySelectorAll('details.provider-card')).map((d) => d.innerText.replace(/\\s+/g, ' ').trim());
}"""


def render(feed, tree_js):
    errors = []
    with sync_playwright() as p:
        b = p.chromium.launch()
        pg = b.new_page(viewport={"width": 1280, "height": 900})
        pg.on("pageerror", lambda e: errors.append(str(e)))
        if feed is not None:
            pg.route(re.compile(r"get-providers-only\.php"), lambda route, request: route.fulfill(body=json.dumps(feed), content_type="application/json"))
        if tree_js:
            pg.route(re.compile(r"/themes/child/js/provider-index\.js"),
                     lambda route, request: route.fulfill(path=REPO + "js/provider-index.js", content_type="application/javascript"))
        pg.goto(URL, wait_until="domcontentloaded", timeout=90000)
        cards = pg.evaluate(READ)
        b.close()
    return cards, errors


live, live_errors = render(None, False)
legacy, legacy_errors = render(live_feed, True)
v2, v2_errors = render(v2_feed, True)
print(f"{len(live)} provider cards on the live page")
fails = []
# The working tree shows a provider's first profile link as its website when it names none (v2 keeps the site there).
# The bug reporter adds its button to a card when it loads, so it is left out too.
strip_site = lambda cards: [re.sub(r" WEBSITE .*?(?= Submit info|$)", "", c.replace(" Report a problem", "")) for c in cards]
if strip_site(legacy) != strip_site(live):
    fails.append(("working tree, live feed vs live page", live, legacy))
if strip_site(v2) != strip_site(legacy):
    fails.append(("v2 feed vs live feed", legacy, v2))
for label, a, b in fails:
    print("FAIL", label)
    for x, y in zip(a, b):
        if x != y:
            i = next((k for k in range(min(len(x), len(y))) if x[k] != y[k]), min(len(x), len(y)))
            print("  live/legacy: ..." + x[max(0, i - 80):i + 120])
            print("  tree/v2:     ..." + y[max(0, i - 80):i + 120])
            break
    if len(a) != len(b):
        print(f"  cards: {len(a)} vs {len(b)}")
errors = [e for e in legacy_errors + v2_errors if e not in live_errors]
if errors:
    print("New page errors:", *errors, sep="\n  ")
sites = [m.group(0) for c in v2 for m in [re.search(r"WEBSITE .*?(?= Submit info|$)", c)] if m]
if sites:
    print("Website shown from profileLinks:", sites)
ok = not fails and not errors and len(live) > 0
print("OK, the same cards from either shape" if ok else "FAIL")
sys.exit(0 if ok else 1)
