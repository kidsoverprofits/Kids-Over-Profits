"""Build a record's Rewrite column from a JSON spec.

Usage: python -I gen.py spec.json > out.html
spec.json = {
  "sources": {"key": ["https://url", "Outlet, year"], ...},  # url may be "" for a print/offline source: give a full citation (outlet, date, page/docket) as the label
  "paragraphs": ["Text with HTML entities (&amp;) and cites like {key} or {key1,key2} after sentences.", ...],
  "testimony": [  # survivor and family accounts, word for word (kop-pipeline scripts/about-rewrite/BRIEF.md rules 28 to 32)
    {"text": "The person's own words, only spelling and punctuation fixed; ... for a cut", "who": "Former student",
     "where": "WWASP Survivors", "date": "March 2014", "key": "wwasp", "via": "optional: whose copy the words were read through"}
  ]
}
Citations are numbered by first appearance, as the site does. A testimony entry links to its source key's URL
(or its own "url") and does not take a number.
"""
import html
import json
import re
import sys

spec = json.load(open(sys.argv[1], encoding="utf-8"))
SRC = spec["sources"]
order = []


def cite(m):
    out = ""
    for k in m.group(1).split(","):
        k = k.strip()
        if k not in SRC:
            sys.exit(f"unknown source key: {k}")
        if k not in order:
            order.append(k)
        n = order.index(k) + 1
        out += f'<sup><a href="{SRC[k][0]}">[{n}]</a></sup>' if SRC[k][0] else f'<sup>[{n}]</sup>'
    return out


def testimony(items):
    out = ""
    for t in items or []:
        key = t.get("key", "")
        if key and key not in SRC:
            sys.exit(f"unknown testimony source key: {key}")
        url = t.get("url") or (SRC[key][0] if key else "")
        where = html.escape(t.get("where", ""))
        if url and where:
            where = f'<a href="{html.escape(url, quote=True)}">{where}</a>'
        credit = " &middot; ".join(x for x in (html.escape(t.get("who", "")), where, html.escape(t.get("date", ""))) if x)
        via = f'<br><span class="via">Read through {html.escape(t["via"])}; check against the original</span>' if t.get("via") else ""
        text = html.escape(t.get("text", "")) or '<span class="empty">(recording, no transcript yet)</span>'
        out += f"<blockquote>{text}<footer>{credit}{via}</footer></blockquote>"
    return f'<div class="testimony"><h4>Survivor testimony</h4>{out}</div>' if out else ""


body = "".join("<p>" + re.sub(r"\{([A-Za-z0-9_, ]+)\}", cite, p.strip()) + "</p>" for p in spec["paragraphs"])
refs = '<div class="refs"><b>Sources</b><ol>' + "".join(
    (f'<li><a href="{SRC[k][0]}">{SRC[k][1]}</a></li>' if SRC[k][0] else f'<li>{SRC[k][1]}</li>') for k in order) + "</ol></div>"
quotes = testimony(spec.get("testimony"))
used = set(order) | {t.get("key") for t in spec.get("testimony") or []}
unused = set(SRC) - used
if unused:
    print("warning: unused sources " + ", ".join(sorted(unused)), file=sys.stderr)
sys.stdout.reconfigure(encoding="utf-8")
print('<div class="col after"><h3>Rewrite</h3>' + body + quotes + refs + "</div>", end="")
