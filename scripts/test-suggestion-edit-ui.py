"""
Browser test for fixing a data suggestion on its card before Approve
(js/admin-submissions.js renderChanges() / suggSave(), css/admin-submissions.css).

Renders templates/page-admin-submissions.php with WordPress stubbed, loads the
real stylesheets and script, and answers api/manage-submissions.php from a small
model of kop_suggestion_edit() (the PHP side is tested by
scripts/test-suggestion-changes.php). Checks: a waiting card has Edit / Move /
Remove on each suggested value and new list item, Edit opens the value in a box
and saves it as the right edit, a person opens as name / role / past jobs, Move
lists the fields and posts nothing until one is picked, the card redraws with
"You fixed this suggestion" and Undo last edit, a refused edit says why and
leaves the buttons working, an approved card has no buttons, and nothing is
wider than a phone. Screenshots go to --shots.

    python scripts/test-suggestion-edit-ui.py [--shots tmp/suggestion-edit-ui]
"""
import argparse
import re
import copy
import json
import pathlib
import subprocess
import sys

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
BASE = "https://subs.test/"

# The template with WordPress stubbed: only its markup is wanted.
RENDER = r"""
function kop_require_page_capability($c) {}
function get_header() { echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
  . '<link rel="stylesheet" href="/css/colors.css"><link rel="stylesheet" href="/css/admin-submissions.css">'
  . '<script>window.kopIcon = function () { return \'<svg class="kop-icon" width="14" height="14" aria-hidden="true"></svg>\'; };</script>'   // inc/icons.php prints the real one
  . '</head><body style="background:#F2EEDF;margin:0;padding:16px">'; }
function get_footer() { echo '<script>window.adminSubmissionsConfig = {manageApi: "/api/manage-submissions.php", reviewer: "Tester"};</script>'
  . '<script src="/js/admin-submissions.js"></script></body></html>'; }
function kop_icon($n, $o = array()) { return '<svg class="kop-icon" width="14" height="14" aria-hidden="true"></svg>'; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function kop_choice_select_attr($k) { return ''; }
function kop_facility_resource_link_kinds() { return array('reference' => 'Reference'); }
function selected($a, $b) { if ($a === $b) echo ' selected'; }
function wp_json_encode($v) { return json_encode($v); }
function get_stylesheet_directory_uri() { return ''; }
function rest_url($p) { return '/wp-json/' . $p; }
function admin_url($p) { return '/wp-admin/' . $p; }
function kop_news_content_warnings($m) { return array(); }
function add_action() {}
function kop_choices() { return array(); }
define('ABSPATH', '/');
$GLOBALS['kop_skip_choice_lists'] = true;
"""

TARGETS = [
    {"path": "notes", "label": "Notes", "list": True},
    {"path": "survivorTestimony", "label": "Survivor testimony", "list": True},
    {"path": "identification.currentOperator", "label": "Operator", "list": False},
    {"path": "location.city", "label": "City", "list": False},
]


def changes_for(doc):
    """The card rows for the model document, as kop_suggestion_changes() shapes them."""
    rows = []
    if doc["operator"]:
        rows.append({"label": "Operator", "path": "identification.currentOperator", "kind": "changed",
                     "before": "Charter Behavioral Health", "after": doc["operator"], "editable": True})
    if doc["staff"]:
        rows.append({"label": "Staff", "path": "staff.notableStaff", "kind": "list", "had": 0,
                     "added": [", ".join(v for v in (p["name"], p["role"]) if v) for p in doc["staff"]], "dropped": [],
                     "items": [{"index": i, "text": ", ".join(v for v in (p["name"], p["role"]) if v),
                                "parts": [{"key": "name", "label": "Name", "value": p["name"]},
                                          {"key": "role", "label": "Role", "value": p["role"]},
                                          {"key": "pastJobs", "label": "Past jobs", "value": ""}]}
                               for i, p in enumerate(doc["staff"])]})
    if doc["notes"] or doc["dropped"]:
        rows.append({"label": "Notes", "path": "notes", "kind": "list", "had": 3, "added": list(doc["notes"]), "dropped": list(doc["dropped"]),
                     "items": [{"index": i, "text": t, "parts": [{"key": "", "label": "", "value": t}]} for i, t in enumerate(doc["notes"])],
                     "dropped_items": [{"index": 2, "text": t, "parts": []} for t in doc["dropped"]]})
    if doc["gender_removed"]:
        rows.append({"label": "Gender", "path": "facilityDetails.gender", "kind": "removed", "before": "Female", "after": "", "editable": True, "keepable": True})
    rows.append({"label": "Has deaths", "path": "criticalIncidents.hasDeaths", "kind": "added", "before": "", "after": "Yes", "editable": False})
    return {"entries": [{"index": 0, "name": "Linden Oaks", "facility_id": 12, "url": "https://kidsoverprofits.org/facility/linden-oaks-il/",
                         "is_new": False, "changes": rows}],
            "unchanged": [], "targets": TARGETS,
            "other": [{"label": "Transporters", "after": "", "path": ["transporters"], "bool": False, "list": True, "value": "",
                       "items": [{"index": i, "text": ", ".join(t.values()), "parts": [{"key": k, "label": k, "value": v} for k, v in t.items()]}
                                 for i, t in enumerate(doc["transporters"])], "moves": []},
                      {"label": "Is independent transporter", "after": "Yes", "path": ["isIndependentTransporter"], "bool": True, "list": False,
                       "value": "", "moves": []}]}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--shots", default=str(ROOT / "tmp" / "suggestion-edit-ui"))
    args = ap.parse_args()
    shots = pathlib.Path(args.shots)
    shots.mkdir(parents=True, exist_ok=True)

    page_html = subprocess.run(
        ["php", "-r", RENDER + " include " + json.dumps(str(ROOT / "templates" / "page-admin-submissions.php")) + ";"],
        capture_output=True, text=True, cwd=str(ROOT))
    if "<div class=\"admin-submissions-page\"" not in page_html.stdout:
        print("Could not render the template:\n" + page_html.stdout[-2000:] + page_html.stderr[-2000:])
        return 1

    fails = []

    def check(ok, label, detail=""):
        print(("PASS " if ok else "FAIL ") + label + (f"  ({detail})" if detail and not ok else ""))
        if not ok:
            fails.append(label)

    for width in (1280, 390):
        doc = {"operator": "This is not a Charter Behavioral Health program.",
               "staff": [{"name": "Brittney-house manager", "role": ""}],
               "notes": ["Teh staff yelled at us."], "dropped": ["An old note the sender deleted."], "gender_removed": True,
               "transporters": [{"firstName": "Bobby", "lastName": "Tredinnik", "role": "CEO"}]}
        history = []
        calls = []
        refuse = {"next": False}
        approved = {"on": False}

        def row(status="pending"):
            return {"id": 58 if status == "pending" else 52, "program_name": "Linden Oaks", "status": status, "created_at": "2026-10-21 04:17:55",
                    "submitted_by": "Anonymous", "submission_notes": 'Public mini-form submission (facility) for "Linden Oaks" from /facility/linden-oaks-il/',
                    "json_data": {"facilities": [{}]}, "changes": changes_for(doc), "edits": len(history), "on_file": None}

        def handle(route):
            req = route.request
            url = req.url
            if url.startswith(BASE + "css/") or url.startswith(BASE + "js/"):
                path = ROOT / url[len(BASE):].split("?")[0]
                ctype = "text/css" if path.suffix == ".css" else "application/javascript"
                return route.fulfill(status=200, body=path.read_bytes(), headers={"Content-Type": ctype})
            if "manage-submissions.php" in url:
                if req.method == "GET":
                    if "stats" in url:
                        return route.fulfill(status=200, json={"success": True, "stats": {}})
                    rows = [row("approved")] if approved["on"] else [row()]
                    return route.fulfill(status=200, json={"success": True, "data": rows, "total": len(rows)})
                body = json.loads(req.post_data or "{}")
                calls.append(body)
                if body.get("action") == "undo_suggestion_edit":
                    doc.update(history.pop())
                    return route.fulfill(status=200, json={"success": True, "message": "Edit undone.", "json_data": {},
                                                           "changes": changes_for(doc), "edits": len(history)})
                if body.get("action") == "edit_suggestion":
                    if refuse["next"]:
                        refuse["next"] = False
                        return route.fulfill(status=200, json={"success": False, "error": "City already says \"Naperville\". Edit or move that first."})
                    e = body["edit"]
                    history.append(copy.deepcopy(doc))
                    if e["op"] == "item" and e["path"] == "notes":
                        doc["notes"][e["index"]] = e["parts"][""]
                    elif e["op"] == "item" and e["path"] == "staff.notableStaff":
                        doc["staff"][e["index"]] = {"name": e["parts"]["name"], "role": e["parts"]["role"]}
                    elif e["op"] == "move" and e["path"] == "identification.currentOperator":
                        doc["notes"].append(doc["operator"])
                        doc["operator"] = ""
                    elif e["op"] == "keep" and e["path"] == "notes":
                        doc["dropped"].pop()
                    elif e["op"] == "keep" and e["path"] == "facilityDetails.gender":
                        doc["gender_removed"] = False
                    elif e["op"] == "item" and e["path"] == ["transporters"]:
                        doc["transporters"][e["index"]].update(e["parts"])
                    elif e["op"] == "remove" and e["path"] == "notes":
                        doc["notes"].pop(e["index"])
                    return route.fulfill(status=200, json={"success": True, "message": "Saved.", "json_data": {},
                                                           "changes": changes_for(doc), "edits": len(history)})
                return route.fulfill(status=200, json={"success": True})
            if url.startswith(BASE) and "?" in url and url.split("?")[0] == BASE:
                return route.fulfill(status=200, body=page_html.stdout, headers={"Content-Type": "text/html"})
            return route.fulfill(status=200, json={"success": True, "data": []})

        with sync_playwright() as p:
            browser = p.chromium.launch()
            page = browser.new_page(viewport={"width": width, "height": 900})
            page.route("**/*", handle)
            errors = []
            page.on("pageerror", lambda e: errors.append(str(e)))
            page.goto(BASE + "?type=data")
            page.wait_for_selector(".submission-card .sugg-changes")
            card = page.locator(".submission-card").first

            def rowof(label):
                """A table row by its exact field name (the text in its cells can repeat the word)."""
                return card.locator("tr").filter(has=page.locator("th", has_text=re.compile("^" + re.escape(label) + "$")))

            op_row = rowof("Operator")
            check(op_row.locator("button.sugg-act").all_inner_texts() == ["Edit", "Move", "Remove"], f"@{width} a suggested value has Edit, Move, Remove")
            check(rowof("Has deaths").locator("button.sugg-act").all_inner_texts() == ["Remove"], f"@{width} a tick box can only be removed")

            # Edit a note: the box holds the text; Save sends one item edit.
            note_li = rowof("Notes").locator("li.sugg-ins").first
            note_li.locator('button.sugg-act[data-op="edit"]').click()
            box = card.locator(".sugg-form textarea.sugg-input")
            check(box.input_value() == "Teh staff yelled at us.", f"@{width} Edit opens the value in a box")
            check("never reword" in card.locator(".sugg-hint").inner_text(), f"@{width} the box says testimony keeps its words")
            if width == 390:
                page.screenshot(path=str(shots / f"edit-{width}.png"), full_page=True)
                over = page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
                check(over <= 0, f"@{width} nothing wider than the screen with the box open", str(over))
            box.fill("The staff yelled at us.")
            card.locator(".sugg-form button", has_text="Save").click()
            page.wait_for_selector(".sugg-edited")
            check(calls[-1] == {"action": "edit_suggestion", "type": "data", "id": 58,
                                "edit": {"entry": 0, "op": "item", "path": "notes", "index": 0, "parts": {"": "The staff yelled at us."}}},
                  f"@{width} Save sends the item edit", json.dumps(calls[-1]))
            check("The staff yelled at us." in rowof("Notes").inner_text(), f"@{width} the card redraws with the fix")
            check("once" in card.locator(".sugg-edited").inner_text() and card.locator(".sugg-status").inner_text() == "Saved.",
                  f"@{width} the card says it was fixed and saved")

            # A person: three boxes.
            rowof("Staff").locator('button.sugg-act[data-op="edit"]').click()
            labels = card.locator(".sugg-form label").all_inner_texts()
            check(labels == ["Name", "Role", "Past jobs"], f"@{width} a person opens as name, role, past jobs", str(labels))
            card.locator(".sugg-form input").nth(0).fill("Brittney")
            card.locator(".sugg-form input").nth(1).fill("House manager")
            card.locator(".sugg-form button", has_text="Save").click()
            page.wait_for_function("document.querySelector('.sugg-edited') && /2 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1]["edit"]["parts"] == {"name": "Brittney", "role": "House manager", "pastJobs": ""}, f"@{width} the person's parts are sent", json.dumps(calls[-1]))

            # Move: nothing is sent until a field is picked.
            n = len(calls)
            rowof("Operator").locator('button.sugg-act[data-op="move"]').click()
            opts = card.locator(".sugg-form select option").all_inner_texts()
            check("Operator" not in opts and "Notes (adds to the list)" in opts, f"@{width} Move lists the other fields", str(opts))
            card.locator(".sugg-form button", has_text="Move").click()
            check(len(calls) == n, f"@{width} Move without a field sends nothing")
            card.locator(".sugg-form select").select_option("notes")
            card.locator(".sugg-form button", has_text="Move").click()
            page.wait_for_function("/3 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1]["edit"] == {"entry": 0, "op": "move", "path": "identification.currentOperator", "to": "notes"}, f"@{width} Move sends where to", json.dumps(calls[-1]))
            check(rowof("Operator").count() == 0 and "Charter" in rowof("Notes").inner_text(),
                  f"@{width} the moved text shows under its new field")

            # A refused edit says why and the buttons work again.
            refuse["next"] = True
            rowof("Notes").locator("li.sugg-ins").first.locator('button.sugg-act[data-op="remove"]').click()
            page.wait_for_selector(".sugg-status.is-error")
            check("Naperville" in card.locator(".sugg-status").inner_text(), f"@{width} a refused edit says why")
            check(card.locator("button.sugg-act:disabled").count() == 0, f"@{width} the buttons work again after a refusal")

            # Undo steps back one fix.
            card.locator("button.sugg-undo").click()
            page.wait_for_function("/2 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1] == {"action": "undo_suggestion_edit", "type": "data", "id": 58}, f"@{width} Undo last edit is sent")
            check(rowof("Operator").count() == 1, f"@{width} Undo puts the moved text back")
            if width == 1280:
                page.screenshot(path=str(shots / f"card-{width}.png"), full_page=True)

            # What a whole-record edit dropped: Keep it.
            g = rowof("Gender")
            check(g.locator("button.sugg-act").all_inner_texts() == ["Edit", "Keep it"], f"@{width} an emptied field can be edited or kept")
            g.locator('button.sugg-act[data-op="keep"]').click()
            page.wait_for_function("/3 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1]["edit"] == {"entry": 0, "op": "keep", "path": "facilityDetails.gender"}, f"@{width} Keep it sends keep", json.dumps(calls[-1]))
            check(rowof("Gender").count() == 0, f"@{width} a kept field leaves the changes")
            dropped = rowof("Notes").locator("li.sugg-del")
            check(dropped.locator("button.sugg-act").all_inner_texts() == ["Keep it"], f"@{width} a dropped item has Keep it")
            dropped.locator('button.sugg-act[data-op="keep"]').click()
            page.wait_for_function("/4 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1]["edit"] == {"entry": 0, "op": "keep", "path": "notes", "index": 2}, f"@{width} Keep it sends the item's place", json.dumps(calls[-1]))

            # The non-facility parts: a list of people item by item, a tick box only removed.
            tr = rowof("Transporters").locator("li").first
            check(tr.locator("button.sugg-act").all_inner_texts() == ["Edit", "Move", "Remove"], f"@{width} a person in a list has Edit, Move, Remove")
            check(rowof("Is independent transporter").locator("button.sugg-act").all_inner_texts() == ["Remove"], f"@{width} a tick box there has only Remove")
            tr.locator('button.sugg-act[data-op="edit"]').click()
            check(card.locator(".sugg-form input").count() == 3, f"@{width} a person there opens as its parts")
            card.locator(".sugg-form input").nth(1).fill("Tredinnick")
            card.locator(".sugg-form button", has_text="Save").click()
            page.wait_for_function("/5 times/.test(document.querySelector('.sugg-edited').textContent)")
            check(calls[-1]["edit"] == {"entry": "other", "op": "item", "path": ["transporters"], "index": 0,
                                        "parts": {"firstName": "Bobby", "lastName": "Tredinnick", "role": "CEO"}}, f"@{width} the person's fix is sent", json.dumps(calls[-1]))
            check("Tredinnick" in rowof("Transporters").inner_text(), f"@{width} the card shows the person's fix")
            if width == 390:
                over = page.evaluate("document.documentElement.scrollWidth - document.documentElement.clientWidth")
                check(over <= 0, f"@{width} nothing wider than the screen with every row", str(over))
                page.screenshot(path=str(shots / f"all-{width}.png"), full_page=True)

            # An approved suggestion is not edited on its card.
            approved["on"] = True
            page.reload()
            page.wait_for_selector(".submission-card .sugg-changes")
            check(page.locator(".submission-card button.sugg-act").count() == 0, f"@{width} an approved card has no edit buttons")
            check(not errors, f"@{width} no script errors", "; ".join(errors))
            browser.close()

    print(f"\n{len(fails)} failed" if fails else "\nAll passed")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
