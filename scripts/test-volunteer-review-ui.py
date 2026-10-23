"""
Browser test for the volunteer page (/volunteer-review/: js/volunteer-review.js,
css/volunteer-review.css), with kop/v1/volunteer/* answered from fixtures.

Checks: a research project opens on its first question (record link, facts,
link rows), "Save my answer" posts the answer with its links and moves on,
"Could not find it" without a word says where to look, "Ask a question about
this project" names the project in the message box, a sent message shows in
the conversation, a reply waiting is marked New with a line at the top and is
marked read once on screen, the review project still shows its card with
"Ask a question about this one", the brief's "Questions others asked", "Your past answers" lists an answer, and
nothing is wider than a phone screen. Screenshots go to --shots.

    python scripts/test-volunteer-review-ui.py [--shots tmp/volunteer-review-ui]
"""
import argparse
import json
import pathlib
import sys

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
BASE = "https://vol.test/"
REST = BASE + "wp-json/kop/v1/volunteer/"

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/colors.css"><link rel="stylesheet" href="/css/volunteer-review.css"></head>
<body style="background:#F2EEDF;margin:0">
<div class="kop-vol-page"><section class="kop-vol-panel kop-vol-intro"><h1>Help review submissions</h1><p>Hi Sam.</p></section>
<div id="kop-vol-app" class="kop-vol-app"><section class="kop-vol-panel"><p role="status">Loading...</p></section></div></div>
<script>window.kopVolunteer = {"rest": "%s", "page": "abc", "nonce": "", "name": "Sam", "preview": false};</script>
<script src="/js/volunteer-review.js"></script></body></html>""" % REST

LINK_KINDS = {"article": "News story", "lawsuit": "Court record", "legislation": "Bill or law", "website": "Other page"}
RESEARCH = {"id": 7, "title": "When did these programs close?: AZ", "question": "When did this program close? Give the year and the link.",
            "yes": "\"Closed in March 2009\" with a link to a news story.", "no": "A year with no link.", "note": "",
            "minutes": 15, "total": 3, "done": 0, "mine": True, "kind": "research", "link_kinds": LINK_KINDS}
REVIEW = {"id": 8, "title": "Serious findings from state inspections", "question": "Does this finding describe real harm?",
          "yes": "A restraint.", "no": "Paperwork.", "note": "", "minutes": 5, "total": 2, "done": 0, "mine": True, "kind": "review", "link_kinds": None, "faq": [{"q": "The finding says allegations. Approve?", "a": "Recommend approve when the inspector cites the facility over it."}]}
QUESTIONS = [
    {"source": "research", "project_id": 7, "key": "f12166", "title": "A Dream Called Hope LLC", "question": RESEARCH["question"],
     "record": {"name": "A Dream Called Hope LLC", "url": "https://kidsoverprofits.org/facility/a-dream-called-hope-llc-az/"},
     "facts": [{"label": "Where", "value": "Laveen, AZ"}, {"label": "Status on our record", "value": "Closed"}, {"label": "Years on our record", "value": "none yet"}],
     "link_kind": "article", "answer": None},
    {"source": "research", "project_id": 7, "key": "f100315", "title": "A Very Long Program Name Academy For Young Women Of The Desert Southwest Incorporated",
     "question": RESEARCH["question"], "record": None, "facts": [], "link_kind": "article", "answer": None},
]
FINDING = {"source": "inspection-highlights", "key": "704", "title": "Inappropriate restraint", "subtitle": "AZ, 2024", "url": "", "text": "Staff restrained a child.",
           "created": "2026-10-01", "facility": {"name": "Some Ranch", "url": "https://kidsoverprofits.org/facility/some-ranch-az/"},
           "facts": [], "links": [], "compare": None, "full_text": False, "approve": ["Approve: shows it"], "reject": ["Reject: hides it"]}


PRACTICE_ITEMS = [
    {"source": "news", "key": "101", "title": "Ranch staff charged", "subtitle": "", "url": "https://news.test/a", "text": "Two staff were charged.", "created": "2026-09-01",
     "facility": None, "facts": [{"label": "Outlet", "value": "The Paper"}], "links": [], "compare": None, "full_text": False, "approve": [], "reject": [], "practice": True},
    {"source": "news", "key": "102", "title": "High school football scores", "subtitle": "", "url": "https://news.test/b", "text": "Scores.", "created": "2026-09-02",
     "facility": None, "facts": [], "links": [], "compare": None, "full_text": False, "approve": [], "reject": [], "practice": True},
]
PRACTICE_ANSWERS = {"101": "approve", "102": "reject"}
BRIEF = {"title": "Practice: News stories", "question": "Is the story about a troubled teen program?", "yes": "Abuse at a ranch.", "no": "Sports scores."}


def practice(width, shots, check):
    """A new volunteer: "Start here" offers a practice round; each call says what the admin decided; passing opens "Pick your next batch"."""
    print("\nPractice round at %d px" % width)
    st = {"started": False, "calls": {}, "passed": False, "posts": []}

    def round_now():
        right = sum(1 for k, v in st["calls"].items() if v == PRACTICE_ANSWERS[k])
        done = len(st["calls"])
        return {"round": 1, "source": "news", "label": "News", "total": 2, "done": done, "right": right, "finished": done == 2, "passed": done == 2 and right >= 1}

    def status():
        return {"size": 2, "pass": 1, "open": round_now() if st["started"] and len(st["calls"]) < 2 else None, "last": None,
                "passed": ["news"] if st["passed"] else [], "queues": [] if st["passed"] else [
                    {"key": "news", "label": "News", "title": "News articles sent in", "blurb": "Most take about a minute."}]}

    BATCH = {"id": 31, "title": "News articles sent in", "question": "Is it about a program?", "yes": "Abuse at a ranch.", "no": "Sports scores.", "note": "",
             "minutes": 10, "total": 2, "done": 0, "mine": True, "kind": "review", "link_kinds": None, "faq": [], "source": "news"}

    def handle(route):
        req = route.request
        url = req.url
        if not url.startswith(REST):
            path = ROOT / url.replace(BASE, "").split("?")[0]
            if path.is_file():
                ctype = "text/css" if path.suffix == ".css" else "application/javascript" if path.suffix == ".js" else "text/html"
                return route.fulfill(status=200, body=path.read_bytes(), headers={"Content-Type": ctype})
            if url.startswith(BASE + "volunteer-review/"):
                return route.fulfill(status=200, body=PAGE, headers={"Content-Type": "text/html"})
            return route.fulfill(status=404, body="")
        name = url[len(REST):].split("?")[0]
        body = json.loads(req.post_data or "{}")
        st["posts"].append((name, body))
        out = {}
        if name == "queues":
            # A new volunteer has no queue of their own until a practice round.
            out = {"queues": [{"key": "news", "label": "News", "count": 3, "guidance": "Is it about a program?"}] if st["passed"] else []}
        elif name == "items":
            out = {"items": [], "next_offset": -1, "total": 0}
        elif name == "selfassign":
            st["batch"] = True
            out = {"id": BATCH["id"], "link": ""}
        elif name == "project":
            out = {"project": BATCH, "items": [dict(PRACTICE_ITEMS[0], practice=False, key="201", title="Real story")]}
        elif name == "projects":
            self_ = ({"eligible": True, "via": "practice", "decided": 0, "agreed": 0, "need_decided": 10, "need_rate": 80, "size": 10, "open": BATCH["id"] if st.get("batch") else 0,
                      "queues": [{"key": "news", "label": "News", "count": 3}], "practice": status()} if st["passed"]
                     else {"eligible": False, "decided": 0, "agreed": 0, "need_decided": 10, "need_rate": 80, "size": 10, "open": 0, "queues": [], "practice": status()})
            out = {"projects": [BATCH] if st.get("batch") else [], "self": self_}
        elif name == "practice":
            do = body.get("do")
            if do == "start":
                st["started"] = True
                out = status()
            elif do == "next":
                left = [i for i in PRACTICE_ITEMS if i["key"] not in st["calls"]]
                out = {"round": round_now(), "brief": BRIEF, "item": left[0] if left else None}
            elif do == "answer":
                st["calls"][body["key"]] = body["verdict"]
                r = round_now()
                st["passed"] = r["passed"]
                ans = PRACTICE_ANSWERS[body["key"]]
                out = {"right": body["verdict"] == ans, "answer": ans, "said": "An admin %s this one." % ("approved" if ans == "approve" else "rejected"), "round": r}
        elif name == "messages":
            out = {"messages": [], "unread": 0}
        return route.fulfill(status=200, body=json.dumps(out), headers={"Content-Type": "application/json"})

    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        page = browser.new_page(viewport={"width": width, "height": 900})
        errors = []
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.route("**/*", handle)
        page.goto(BASE + "volunteer-review/")
        page.wait_for_selector(".kop-vol-projects")
        panel = page.locator(".kop-vol-projects")
        check("a new volunteer sees Start now: the first job, the two steps, one button", panel.locator("h2").inner_text() == "Start now"
              and "Your first job: News articles sent in" in panel.inner_text() and "get 1 of 2 the same as us" in panel.inner_text()
              and panel.locator("button", has_text="Start now").count() == 1, panel.inner_text())
        check("...and no live queue open under it", page.locator(".kop-vol-card").count() == 0 and "nothing to review" not in page.locator("#kop-vol-app").inner_text())
        if shots:
            page.screenshot(path=str(shots / ("start-now-%d.png" % width)), full_page=True)
        panel.locator("button", has_text="Start now").click()
        page.wait_for_selector(".kop-vol-card h3")
        check("the round opens with its brief", "Practice: News stories" in page.locator(".kop-vol-brief").inner_text()
              and "nothing you say here changes anything" in page.locator(".kop-vol-brief").inner_text())
        check("no Skip on a practice card", page.locator(".kop-vol-card button", has_text="Skip for now").count() == 0)
        check("item 1 of 2", "Item 1 of 2" in page.locator(".kop-vol-position").inner_text())
        if shots:
            page.screenshot(path=str(shots / ("practice-%d.png" % width)), full_page=True)
        page.locator(".kop-vol-card button", has_text="Recommend reject").click()
        page.wait_for_selector(".kop-vol-card.is-done")
        done = page.locator(".kop-vol-card.is-done").inner_text()
        check("a wrong call says what the admin decided", "Not this time" in done and "An admin approved this one" in done, done)
        page.locator(".kop-vol-card.is-done button", has_text="Next").click()
        page.wait_for_function("document.querySelector('.kop-vol-card h3') && document.querySelector('.kop-vol-card h3').textContent.indexOf('football') >= 0")
        page.locator(".kop-vol-card button", has_text="Recommend reject").click()
        page.wait_for_selector(".kop-vol-card.is-done")
        check("a right call says so", "Same as the admin" in page.locator(".kop-vol-card.is-done").inner_text())
        page.locator(".kop-vol-card.is-done button", has_text="See how you did").click()
        page.wait_for_selector(".kop-vol-finished")
        fin = page.locator(".kop-vol-finished").inner_text()
        check("the result: 1 of 2, passed", "You got 1 of 2" in fin and "You passed" in fin and "first real batch" in fin, fin)
        if shots:
            page.screenshot(path=str(shots / ("practice-done-%d.png" % width)), full_page=True)
        page.locator(".kop-vol-finished button", has_text="Start my first batch").click()
        page.wait_for_function("document.querySelector('.kop-vol-card h3') && document.querySelector('.kop-vol-card h3').textContent.indexOf('Real story') >= 0")
        check("passing opens the first real batch at once", [n for n, b in st["posts"] if n == "selfassign"] == ["selfassign"]
              and "Item 1 of 2" in page.locator(".kop-vol-position").inner_text())
        check("no practice call was sent as a recommendation", not [n for n, b in st["posts"] if n == "recommend"])
        check("the practice answers went to the practice route", [b.get("verdict") for n, b in st["posts"] if n == "practice" and b.get("do") == "answer"] == ["reject", "reject"])
        wide = page.evaluate("""() => {
            const w = document.documentElement.clientWidth, out = [];
            document.querySelectorAll('.kop-vol-page *').forEach(n => { const r = n.getBoundingClientRect(); if (r.width && r.right > w + 1) out.push(n.className || n.tagName); });
            return out.slice(0, 5);
        }""")
        check("nothing runs off the screen", not wide, json.dumps(wide))
        check("no script errors", not errors, "; ".join(errors))
        browser.close()


PNG_1PX = bytes.fromhex("89504e470d0a1a0a0000000d4948445200000001000000010806000000"
                        "1f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082")
SOCIAL = {"id": 21, "title": "Post ideas: Oct 13 to Oct 19", "question": "Take the ideas you want, make the picture and write the caption, then send it for review.",
          "yes": "One clear fact.", "no": "A guess.", "note": "", "minutes": 20, "total": 2, "done": 0, "mine": True, "kind": "social", "link_kinds": None,
          "faq": [], "caption_max": 2200}
IDEAS = [
    {"source": "social", "project_id": 21, "key": "closure:9", "title": "Sunrise Ranch has closed.", "angle": "closure", "angle_label": "Closure",
     "fact": "Sunrise Ranch (Hurricane, UT) has closed, March 2025.", "url": "https://vol.test/facility/sunrise-ranch/", "url_label": "Our page on Sunrise Ranch",
     "summary": "", "quote": "The ranch closed its doors on March 3.", "image": "", "when": "", "post": None},
    {"source": "social", "project_id": 21, "key": "finding:3401", "title": "UT finding", "angle": "finding", "angle_label": "State finding",
     "fact": "UT inspectors' finding at Some Ranch.", "url": "https://vol.test/severe-reports/#finding-3401", "url_label": "The finding",
     "summary": "A staff member restrained a child face down.", "quote": "Staff placed C1 in a prone restraint.", "image": "", "when": "", "post": None},
]


def social(width, shots, check):
    """The designer's roundup: Taking this opens the caption box, an error keeps the card, Send for review moves on, Take it back works."""
    print("\nSocial media roundup at %d px" % width)
    st = {"posts": {}, "calls": [], "uploads": []}

    def handle(route):
        req = route.request
        url = req.url
        if not url.startswith(REST):
            path = ROOT / url.replace(BASE, "").split("?")[0]
            if path.is_file():
                ctype = "text/css" if path.suffix == ".css" else "application/javascript" if path.suffix == ".js" else "text/html"
                return route.fulfill(status=200, body=path.read_bytes(), headers={"Content-Type": ctype})
            if url.startswith(BASE + "volunteer-review/"):
                return route.fulfill(status=200, body=PAGE, headers={"Content-Type": "text/html"})
            return route.fulfill(status=404, body="")
        name = url[len(REST):].split("?")[0]
        if name == "upload":
            raw = req.post_data_buffer or b""
            st["uploads"].append({"type": req.headers.get("content-type", ""), "raw": raw})
            if b'name="key"' not in raw or b"closure:9" not in raw or b'name="image"' not in raw:
                return route.fulfill(status=400, body=json.dumps({"error": "bad upload"}), headers={"Content-Type": "application/json"})
            cur = st["posts"].setdefault("closure:9", {"status": "taken", "caption": "", "image_url": "", "note": "", "owner_note": ""})
            cur["uploaded"] = True
            return route.fulfill(status=200, body=json.dumps({"post": cur, "message": "Picture saved. Only the reviewers see it until it is approved."}),
                                 headers={"Content-Type": "application/json"})
        body = json.loads(req.post_data or "{}")
        st["calls"].append((name, body))
        done = [k for k, v in st["posts"].items() if v["status"] in ("ready", "approved", "passed")]
        proj = dict(SOCIAL, done=len(done))
        out = {}
        if name == "queues":
            out = {"queues": []}
        elif name == "projects":
            out = {"projects": [proj], "self": {"eligible": False, "queues": [], "open": 0, "decided": 0, "agreed": 0, "need_decided": 10, "need_rate": 80, "size": 10}}
        elif name == "project":
            skip = body.get("skip") or []
            left = [dict(i, post=st["posts"].get(i["key"])) for i in IDEAS if i["key"] not in done and i["key"] not in skip]
            out = {"project": proj, "items": left[:1]}
        elif name == "post":
            key, do = body["key"], body["do"]
            cur = dict(st["posts"].get(key) or {"status": "taken", "caption": "", "image_url": "", "note": "", "owner_note": ""})
            for f in ("caption", "image_url", "note"):
                if f in body:
                    cur[f] = body[f]
            if do == "ready" and not cur["caption"].strip():
                return route.fulfill(status=400, body=json.dumps({"error": "Write the caption first."}), headers={"Content-Type": "application/json"})
            cur["status"] = {"take": "taken", "draft": "taken", "ready": "ready", "pass": "passed", "back": "taken"}[do]
            st["posts"][key] = cur
            out = {"post": cur, "message": "Draft saved." if do == "draft" else "ok"}
        elif name == "messages":
            out = {"messages": [], "unread": 0}
        return route.fulfill(status=200, body=json.dumps(out), headers={"Content-Type": "application/json"})

    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        page = browser.new_page(viewport={"width": width, "height": 900})
        errors = []
        page.on("pageerror", lambda e: errors.append(str(e)))
        page.route("**/*", handle)
        page.goto(BASE + "volunteer-review/?project=21")
        page.wait_for_selector(".kop-vol-social")
        card = page.locator(".kop-vol-social")
        check("the idea opens: angle, fact, our page, the quote", "Closure" in card.locator(".kop-vol-angle").inner_text()
              and "has closed" in card.locator("h3").inner_text() and card.locator("a", has_text="Our page on Sunrise Ranch").count() == 1
              and "closed its doors" in card.locator(".kop-vol-quote").inner_text())
        check("idea 1 of 2; the brief says nothing is posted before approval", "Idea 1 of 2" in page.locator(".kop-vol-position").inner_text()
              and "Nothing is posted until an admin approves it" in page.locator(".kop-vol-brief").inner_text())
        check("no caption box before Taking this", card.locator("textarea").count() == 0)
        card.locator("button", has_text="Taking this").click()
        page.wait_for_selector(".kop-vol-social textarea")
        card = page.locator(".kop-vol-social")
        card.locator("button", has_text="Send for review").click()
        page.wait_for_selector(".kop-vol-social .kop-vol-msg.is-error")
        check("an empty caption is refused and the card stays", "caption" in card.locator(".kop-vol-msg").inner_text())
        card.locator("textarea").fill("Sunrise Ranch in Utah has closed.")
        check("the caption counts its characters", "33 of 2200" in card.locator(".kop-vol-decide .kop-vol-meta").first.inner_text())
        card.locator("input[type=file]").set_input_files({"name": "sunrise.png", "mimeType": "image/png", "buffer": PNG_1PX})
        page.wait_for_function("document.querySelector('.kop-vol-upload-preview') && !document.querySelector('.kop-vol-upload-preview').hidden")
        up = st["uploads"][-1] if st["uploads"] else {"type": "", "raw": b""}
        check("a picture uploads as multipart with the idea's key, and shows", up["type"].startswith("multipart/form-data")
              and b'filename="sunrise.png"' in up["raw"] and "Only the reviewers see it" in card.inner_text())
        card.locator("input[type=url]").fill("https://www.canva.com/design/abc")
        if shots:
            page.screenshot(path=str(shots / ("social-%d.png" % width)), full_page=True)
        card.locator("button", has_text="Send for review").click()
        page.wait_for_function("document.querySelector('.kop-vol-social h3') && document.querySelector('.kop-vol-social h3').textContent.indexOf('inspectors') >= 0")
        sent = [b for n, b in st["calls"] if n == "post" and b.get("do") == "ready"]
        check("Send for review posts the caption and the picture link", sent[-1].get("caption") == "Sunrise Ranch in Utah has closed."
              and sent[-1].get("image_url") == "https://www.canva.com/design/abc")
        check("the next idea shows its plain summary", "face down" in page.locator(".kop-vol-social").inner_text())
        check("the sent one is listed with Take it back", "Sent for review" in page.locator(".kop-vol-saved").inner_text()
              and page.locator(".kop-vol-saved button", has_text="Take it back").count() == 1)
        page.locator(".kop-vol-saved button", has_text="Take it back").click()
        page.wait_for_function("document.querySelector('.kop-vol-social h3') && document.querySelector('.kop-vol-social h3').textContent.indexOf('has closed') >= 0")
        check("taken back, it comes back with its caption", page.locator(".kop-vol-social textarea").input_value() == "Sunrise Ranch in Utah has closed.")
        wide = page.evaluate("""() => {
            const w = document.documentElement.clientWidth, out = [];
            document.querySelectorAll('.kop-vol-page *').forEach(n => { const r = n.getBoundingClientRect(); if (r.width && r.right > w + 1) out.push(n.className || n.tagName); });
            return out.slice(0, 5);
        }""")
        check("nothing runs off the screen", not wide, json.dumps(wide))
        check("no script errors", not errors, "; ".join(errors))
        browser.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--shots", default="")
    args = ap.parse_args()
    shots = pathlib.Path(args.shots) if args.shots else None
    if shots:
        shots.mkdir(parents=True, exist_ok=True)

    failures = []

    def check(label, ok, detail=""):
        print(("  ok   " if ok else "  FAIL ") + label + (" (%s)" % detail if detail else ""))
        if not ok:
            failures.append(label)

    for width in (1280, 390):
        print("\nAt %d px" % width)
        st = {"answered": [], "posts": [], "read": 0,
              "messages": [{"id": 1, "from": "vol", "author": "You", "body": "Is a closure by sale a yes?", "at": "2026-10-19 10:00:00Z",
                            "project_id": 7, "project": RESEARCH["title"], "item": "", "unread": False},
                           {"id": 2, "from": "admin", "author": "Kids Over Profits", "body": "No: a sale is not a closure. Say so in your answer.",
                            "at": "2026-10-19 12:00:00Z", "project_id": 7, "project": RESEARCH["title"], "item": "", "unread": True}]}

        def handle(route):
            req = route.request
            url = req.url
            if not url.startswith(REST):
                path = ROOT / url.replace(BASE, "").split("?")[0]
                if path.is_file():
                    ctype = "text/css" if path.suffix == ".css" else "application/javascript" if path.suffix == ".js" else "text/html"
                    return route.fulfill(status=200, body=path.read_bytes(), headers={"Content-Type": ctype})
                if url.rstrip("/") + "/" == BASE + "volunteer-review/" or url.startswith(BASE + "volunteer-review/"):
                    return route.fulfill(status=200, body=PAGE, headers={"Content-Type": "text/html"})
                return route.fulfill(status=404, body="")
            name = url[len(REST):].split("?")[0]
            body = json.loads(req.post_data or "{}")
            st["posts"].append((name, body))
            out = {}
            if name == "queues":
                out = {"queues": []}
            elif name == "projects":
                out = {"projects": [dict(RESEARCH, done=len(st["answered"])), REVIEW]}
            elif name == "project":
                if body.get("id") == 7:
                    left = [q for q in QUESTIONS if q["key"] not in st["answered"] and q["key"] not in body.get("skip", [])]
                    out = {"project": dict(RESEARCH, done=len(st["answered"])), "items": left[:1]}
                else:
                    out = {"project": REVIEW, "items": [FINDING]}
            elif name == "answer":
                if body.get("withdraw"):
                    out = {"message": "Taken back."}
                else:
                    st["answered"].append(body["key"])
                    links = [dict(l, title="", sent="news review") for l in body.get("links", [])]
                    out = {"answer": {"answer": body.get("answer", ""), "found": not body.get("not_found"), "links": links, "status": ""}, "message": "Saved."}
            elif name == "messages":
                if body.get("read"):
                    st["read"] += 1
                    for m in st["messages"]:
                        m["unread"] = False
                out = {"messages": st["messages"], "unread": sum(1 for m in st["messages"] if m["unread"])}
            elif name == "message":
                st["messages"].append({"id": len(st["messages"]) + 1, "from": "vol", "author": "You", "body": body["body"], "at": "2026-10-20 09:00:00Z",
                                       "project_id": body.get("project_id", 0), "project": RESEARCH["title"] if body.get("project_id") == 7 else "",
                                       "item": body.get("item_title", ""), "unread": False})
                out = {"messages": st["messages"], "message": "Sent."}
            elif name == "mine":
                out = {"rows": [{"kind": "answer", "project_id": 7, "key": "f12166", "title": "A Dream Called Hope LLC", "source_label": RESEARCH["title"],
                                 "answer": "Closed in 2009.", "found": True, "links": 1, "at": "2026-10-20 09:00:00Z", "outcome": ""}]}
            return route.fulfill(status=200, body=json.dumps(out), headers={"Content-Type": "application/json"})

        with sync_playwright() as pw:
            browser = pw.chromium.launch()
            page = browser.new_page(viewport={"width": width, "height": 900})
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.route("**/*", handle)
            page.goto(BASE + "volunteer-review/?project=7")
            page.wait_for_selector(".kop-vol-research")

            card = page.locator(".kop-vol-research")
            check("the research project opens on its first question", card.locator("h3").inner_text() == "A Dream Called Hope LLC")
            check("...with the record's page and what we hold", card.locator("a", has_text="What our page says").count() == 1
                  and "Laveen, AZ" in card.inner_text())
            check("...one link row, news story picked", card.locator(".kop-vol-link-row").count() == 1
                  and card.locator(".kop-vol-link-kind").input_value() == "article")
            check("the brief words the examples for research", "A good answer looks like" in page.locator(".kop-vol-brief").inner_text())
            check("question 1 of 3", "Question 1 of 3" in page.locator(".kop-vol-position").inner_text())
            # On a tall screen the conversation is in view at once, so the reply is read and the line goes straight away.
            check("a reply waiting: marked New, with the line at the top until it is on screen",
                  page.locator(".kop-vol-new").count() == 1 and (page.locator(".kop-vol-newreply").is_visible() or st["read"] >= 1))
            if shots:
                page.screenshot(path=str(shots / ("research-%d.png" % width)), full_page=True)

            card.locator("button", has_text="Could not find it").click()
            check('"Could not find it" with no words asks where they looked', "Say where you looked" in card.locator(".kop-vol-msg").inner_text())

            card.locator("textarea").fill("Closed in June 2009, per the Arizona Republic.")
            card.locator(".kop-vol-link-url").fill("https://example-news.test/closes")
            card.locator("button", has_text="Add another link").click()
            card.locator(".kop-vol-link-url").nth(1).fill("https://court.test/case")
            card.locator(".kop-vol-link-kind").nth(1).select_option("lawsuit")
            card.locator("button", has_text="Save my answer").click()
            page.wait_for_function("document.querySelector('.kop-vol-research h3') && document.querySelector('.kop-vol-research h3').textContent.indexOf('Very Long') >= 0")
            sent = [b for n, b in st["posts"] if n == "answer"][-1]
            check("the answer went with both links and their kinds", sent["links"] == [{"url": "https://example-news.test/closes", "kind": "article"},
                                                                               {"url": "https://court.test/case", "kind": "lawsuit"}], json.dumps(sent))
            check("saved line says where the links went", "sent to the news queue" in page.locator(".kop-vol-saved").inner_text())
            check("on to question 2", "Question 2 of 3" in page.locator(".kop-vol-position").inner_text())

            page.locator(".kop-vol-brief button", has_text="Ask a question about this project").click()
            check("the message names the project", "About this project" in page.locator(".kop-vol-msg-about").inner_text())
            page.wait_for_timeout(600)
            check("the reply is marked read once on screen", st["read"] >= 1)
            check("...and the line at the top goes", not page.locator(".kop-vol-newreply").is_visible())
            page.locator("#kop-vol-msg-body").fill("What if two papers give different years?")
            page.locator(".kop-vol-messages button", has_text="Send").click()
            page.wait_for_function("document.querySelectorAll('.kop-vol-msg-row').length === 3")
            last = [b for n, b in st["posts"] if n == "message"][-1]
            check("sent with the project named", last.get("project_id") == 7 and last["body"].startswith("What if"), json.dumps(last))
            check("the conversation shows it, the box is cleared", page.locator(".kop-vol-msg-row").nth(2).inner_text().find("different years") >= 0
                  and page.locator("#kop-vol-msg-body").input_value() == "" and page.locator(".kop-vol-msg-about").is_hidden())
            if shots:
                page.screenshot(path=str(shots / ("messages-%d.png" % width)), full_page=True)

            page.locator(".kop-vol-project", has_text="Serious findings").locator("button").click()
            page.wait_for_selector(".kop-vol-card:not(.kop-vol-research)")
            rc = page.locator(".kop-vol-card").first
            check("a review project still shows its card", rc.locator("button", has_text="Recommend approve").count() == 1)
            faq = page.locator(".kop-vol-brief details.kop-vol-faq")
            check("the brief has Questions others asked, folded", faq.count() == 1 and "(1)" in faq.locator("summary").inner_text() and not faq.locator("dd").is_visible())
            faq.locator("summary").click()
            check("...and opens on the answer", "cites the facility" in faq.locator("dd").inner_text())
            if shots:
                page.screenshot(path=str(shots / ("brief-faq-%d.png" % width)), full_page=True)
            rc.locator("button", has_text="Ask a question about this one").click()
            check("asking about one item names it", "Inappropriate restraint" in page.locator(".kop-vol-msg-about").inner_text())

            page.locator("summary", has_text="Your past answers").click()
            page.locator("button.kop-vol-tab", has_text="Your past answers").click()
            page.wait_for_selector(".kop-vol-mine li")
            check('"Your past answers" lists the answer', "You answered: Closed in 2009." in page.locator(".kop-vol-mine").inner_text())

            wide = page.evaluate("""() => {
                const w = document.documentElement.clientWidth, out = [];
                document.querySelectorAll('.kop-vol-page *').forEach(n => { const r = n.getBoundingClientRect(); if (r.width && r.right > w + 1) out.push(n.className || n.tagName); });
                return out.slice(0, 5);
            }""")
            check("nothing runs off the screen", not wide, json.dumps(wide))
            check("no script errors", not errors, "; ".join(errors))
            browser.close()

        practice(width, shots, check)
        social(width, shots, check)

    print("\n%d FAILED" % len(failures) if failures else "\nAll passed")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
