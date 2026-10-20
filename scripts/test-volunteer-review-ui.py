"""
Browser test for the volunteer page (/volunteer-review/: js/volunteer-review.js,
css/volunteer-review.css), with kop/v1/volunteer/* answered from fixtures.

Checks: a research project opens on its first question (record link, facts,
link rows), "Save my answer" posts the answer with its links and moves on,
"Could not find it" without a word says where to look, "Ask a question about
this project" names the project in the message box, a sent message shows in
the conversation, a reply waiting is marked New with a line at the top and is
marked read once on screen, the review project still shows its card with
"Ask a question about this one", "Your past answers" lists an answer, and
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
          "yes": "A restraint.", "no": "Paperwork.", "note": "", "minutes": 5, "total": 2, "done": 0, "mine": True, "kind": "review", "link_kinds": None}
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

    print("\n%d FAILED" % len(failures) if failures else "\nAll passed")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
