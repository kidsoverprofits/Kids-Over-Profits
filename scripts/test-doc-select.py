"""
The document library's pencils and "Select documents" (js/inline-edit.js, css/inline-edit.css) on a
made-up library shaped like a facility page's: open tiles and a closed subfolder. No server needed.

  python scripts/test-doc-select.py [--shots tmp/doc-select]

A hidden pencil is not drawn (a closed folder's tiles have none), each pencil sits on its own tile,
picking (click, shift-click, "Pick all in this folder"), the docs: ref the bar opens, and the page
settles (no redraw loop). The save side is scripts/test-doc-placement.php.
"""
import argparse, pathlib, sys
from playwright.sync_api import sync_playwright

THEME = pathlib.Path(__file__).resolve().parent.parent
ap = argparse.ArgumentParser()
ap.add_argument("--shots", default="")
ARGS = ap.parse_args()
CSS = (THEME / "css/document-library.css").read_text(encoding="utf-8") + (THEME / "css/inline-edit.css").read_text(encoding="utf-8")
JS = (THEME / "js/inline-edit.js").read_text(encoding="utf-8")

def tile(i, home=50):
    return (f'<li class="doc-item" data-title="Doc {i}" data-kop-edit="doc:{i}:h{home}" data-kop-edit-label="Doc {i}">'
            f'<a href="/f{i}.pdf" class="doc-link"><div class="doc-thumbnail"></div><div class="doc-info"><span class="doc-title">Doc {i}</span></div></a></li>')

html = f"""<!doctype html><html><head><style>{CSS}</style></head><body style="width:900px">
<section class="kop-fp-section kop-fp-documents" id="documents"><h2>Documents</h2>
<div class="kop-document-folder">
 <ul class="doc-list doc-layout-grid">{''.join(tile(i) for i in range(1, 8))}</ul>
 <details class="doc-subfolder"><summary class="doc-subfolder-title">Woodbury Reports Mentions (6)</summary>
  <div class="doc-subfolder-content"><ul class="doc-list doc-layout-grid">{''.join(tile(i) for i in range(101, 107))}</ul></div></details>
</div></section>
<p style="height:1500px">after</p>
<script>window.KOP_INLINE_EDIT={{rest:'/rest',nonce:'n',pageRefs:[],placeSearch:'/ps'}};</script>
<script>{JS}</script></body></html>"""

fails = 0
def check(label, ok, detail=""):
    global fails
    fails += 0 if ok else 1
    print(("PASS " if ok else "FAIL ") + label + (f"  ({detail})" if detail else ""))

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1000, "height": 900})
    opened = []
    pg.route("http://kop.test/rest*", lambda r: (opened.append(r.request.url), r.fulfill(json={"title": "x", "fields": []})))
    pg.route("http://kop.test/page", lambda r: r.fulfill(body=html, content_type="text/html"))
    pg.goto("http://kop.test/page")
    pg.wait_for_timeout(400)
    pens = pg.evaluate("""() => [...document.querySelectorAll('.kop-ie-pen')].map(p => ({t: p.title, hidden: p.hidden || getComputedStyle(p).display === 'none', drawnWhileHidden: p.hidden && getComputedStyle(p).display !== 'none', top: parseInt(p.style.top), left: parseInt(p.style.left)}))""")
    vis = [x for x in pens if not x["hidden"]]
    check("a hidden pencil is not drawn", not any(x["drawnWhileHidden"] for x in pens))
    check("open tiles have pencils", sum(1 for x in vis if x["t"] in [f"Edit Doc {i}" for i in range(1, 8)]) == 7, len(vis))
    check("closed-folder tiles have none", not any(x["t"].startswith("Edit Doc 10") for x in vis))
    # each visible pencil sits at its tile's top-right corner
    off = pg.evaluate("""() => [...document.querySelectorAll('li.doc-item')].filter(li => !li.closest('details:not([open])')).map(li => {
        const r = li.getBoundingClientRect(); const pen = [...document.querySelectorAll('.kop-ie-pen')].find(p => p.title === 'Edit ' + li.dataset.title);
        return Math.abs(parseInt(pen.style.top) - Math.round(r.top + scrollY)) + Math.abs(parseInt(pen.style.left) - Math.round(r.right - 28)); })""")
    check("every pencil on its own tile's corner", max(off) <= 1, off)
    pg.click("summary")
    pg.wait_for_timeout(300)
    n = pg.evaluate("() => [...document.querySelectorAll('.kop-ie-pen')].filter(p => !p.hidden && p.title.startsWith('Edit Doc 10')).length")
    check("opening the folder brings its pencils", n == 6, n)
    pg.click("summary")
    pg.wait_for_timeout(300)

    check("one Select documents button", pg.locator(".kop-ie-docsel-toggle").count() == 1)
    check("the bar is hidden until selecting", pg.locator(".kop-ie-docsel-bar").is_hidden())
    pg.click(".kop-ie-docsel-toggle")
    pg.wait_for_timeout(200)
    check("the bar shows while selecting", pg.locator(".kop-ie-docsel-bar").is_visible())
    check("pencils hide while selecting", pg.evaluate("() => [...document.querySelectorAll('.kop-ie-pen')].every(p => p.hidden && getComputedStyle(p).display === 'none')"))
    check("Pick all over each list", pg.locator(".kop-ie-docsel-all").count() == 2, pg.locator(".kop-ie-docsel-all").all_inner_texts())
    url0 = pg.url
    pg.click('li[data-title="Doc 2"]')
    check("a click picks, not opens", pg.url == url0 and pg.locator('li[data-title="Doc 2"].kop-ie-picked').count() == 1)
    pg.click('li[data-title="Doc 5"]', modifiers=["Shift"])
    check("shift-click picks the run", pg.locator(".kop-ie-picked").count() == 4, pg.locator(".kop-ie-docsel-count").inner_text())
    pg.click('li[data-title="Doc 3"]')
    check("a second click unpicks", pg.locator(".kop-ie-picked").count() == 3)
    pg.click("summary")
    pg.locator(".kop-ie-docsel-all").nth(1).click()
    check("Pick all picks the folder's", pg.locator(".kop-ie-picked").count() == 9, pg.locator(".kop-ie-docsel-count").inner_text())
    pg.click("button.kop-ie-docsel-main")
    pg.wait_for_timeout(300)
    ref = opened[-1].split("ref=")[-1] if opened else ""
    from urllib.parse import unquote
    ref = unquote(ref)
    check("Move opens one dialog for all of them", ref.startswith("docs:2h50.4h50.5h50.101h50") and ref.endswith(":move") and ref.count(".") == 8, ref)
    pg.keyboard.press("Escape")
    pg.click(".kop-ie-docsel-toggle")
    pg.wait_for_timeout(200)
    check("Stop selecting clears and hides the bar", pg.locator(".kop-ie-picked").count() == 0 and pg.locator(".kop-ie-docsel-bar").is_hidden())
    # the page settles: no endless redraws
    frames = pg.evaluate("""() => new Promise(r => { let n = 0; const lay = document.querySelector('.kop-ie-layer'); const seen = {}; const o = new MutationObserver(m => m.forEach(x => { if (lay.contains(x.target)) return; n++; const k = x.target.className + '|' + x.attributeName; seen[k] = (seen[k]||0)+1; })); o.observe(document.body, {subtree: true, attributes: true, childList: true});
        setTimeout(() => { o.disconnect(); r(JSON.stringify(seen)); }, 1000); })""")
    check("no endless redraw outside the pencil layer", frames == "{}", frames)
    pg.click(".kop-ie-docsel-toggle")
    pg.wait_for_timeout(200)
    pg.click('li[data-title="Doc 1"]')
    pg.wait_for_timeout(200)
    if ARGS.shots:
        pathlib.Path(ARGS.shots).mkdir(parents=True, exist_ok=True)
        pg.screenshot(path=str(pathlib.Path(ARGS.shots) / "selecting.png"))
    b.close()
print("All passed" if not fails else f"{fails} FAILED")
sys.exit(1 if fails else 0)
