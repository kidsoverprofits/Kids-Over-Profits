"""Claude Code PostToolUse hook: after an edit to a file the mobile app reads, remind the session to carry the change into
the app in the same session (CLAUDE.md, "The app follows the website").

Wired in .claude/settings.json (kept out of git, as .gitignore keeps .claude/ but skills): matcher Edit|Write|MultiEdit,
command  python "$CLAUDE_PROJECT_DIR/scripts/hooks/app-parity-reminder.py". Reads the hook's JSON on stdin and prints
JSON with additionalContext, once per file per session, so a long edit doesn't repeat it. Prints nothing otherwise.
"""
import json
import os
import re
import sys
import tempfile

# Files whose output the app shows or depends on: the pages it mirrors, the routes it calls, the data each page section
# is built from, and the network map page the Map tab drives (class names, button ids, window globals).
APP_FACING = re.compile(
    r"(^|/)("
    r"templates/(facility|operator)-page\.php"
    r"|inc/mobile-[a-z-]+\.php|inc/facility-suggest\.php|inc/global-search\.php"
    r"|inc/(facility|operator)-pages\.php|inc/facility-eras\.php|inc/campus-programs\.php|inc/program-homes\.php"
    r"|inc/profile-(notes|timeline)\.php|inc/about-rewrites\.php|inc/record-writeups\.php"
    r"|inc/reader-accounts\.php|inc/program-practices\.php|inc/podcast-statements\.php"
    r"|inc/(nonprofit|business)-filings\.php|inc/resources-list\.php"
    r"|inc/extension-[a-z-]+\.php|inc/source-submissions\.php"
    r"|js/network-map/.+|templates/page-network-map\.php"
    r")$"
)


def main():
    try:
        data = json.load(sys.stdin)
    except Exception:
        return
    tool_input = data.get("tool_input") or {}
    path = str(tool_input.get("file_path") or (data.get("tool_response") or {}).get("filePath") or "")
    rel = path.replace("\\", "/")
    root = str(os.environ.get("CLAUDE_PROJECT_DIR") or "").replace("\\", "/").rstrip("/")
    if root and rel.lower().startswith(root.lower() + "/"):
        rel = rel[len(root) + 1:]
    if not APP_FACING.search(rel):
        return

    # Once per file per session.
    seen_file = os.path.join(tempfile.gettempdir(), "kop-app-parity-" + re.sub(r"[^A-Za-z0-9_-]", "", str(data.get("session_id") or "x")) + ".txt")
    try:
        with open(seen_file, encoding="utf-8") as f:
            seen = set(f.read().splitlines())
    except OSError:
        seen = set()
    if rel in seen:
        return
    try:
        with open(seen_file, "a", encoding="utf-8") as f:
            f.write(rel + "\n")
    except OSError:
        pass

    note = (
        f"{rel} feeds the mobile app (kids-over-profits-mobile, beside this repo). Standing rule (CLAUDE.md, \"The app "
        "follows the website\"): when this change adds, removes or reshapes what a facility or company page shows, a route "
        "the app calls, or the network map page, carry it into the app in this same session: the payload in "
        "inc/mobile-api.php, the app's types and section (src/api/types.ts, src/components/), its jump list "
        "(model.ts), docs/app-parity.json, then `php scripts/test-app-parity.php`, the app's `npx tsc --noEmit`, "
        "`npx expo lint`, `npx jest --maxWorkers=4`; commit and push both repos (the app push ships an EAS update). "
        "A change the app does not show (admin-only, styling, wording the payload doesn't carry) needs nothing; say so "
        "in the report. Details: docs/claude/mobile-extension.md, \"Keeping the app in step\"."
    )
    print(json.dumps({"hookSpecificOutput": {"hookEventName": "PostToolUse", "additionalContext": note}}))


if __name__ == "__main__":
    main()
