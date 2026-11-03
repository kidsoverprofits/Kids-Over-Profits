"""Build a record's Rewrite column from a JSON spec.

Usage: python3 -I gen.py spec.json > out.html
spec.json = {
  "sources": {"key": ["https://url", "Outlet, year"], ...},  # url may be "" for a print/offline source: give a full citation (outlet, date, page/docket) as the label
  "paragraphs": ["Text with HTML entities (&amp;) and cites like {key} or {key1,key2} after sentences.", ...]
}
Citations are numbered by first appearance, as the site does.
"""
import json, re, sys
spec = json.load(open(sys.argv[1]))
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
body = "".join("<p>" + re.sub(r"\{([A-Za-z0-9_, ]+)\}", cite, p.strip()) + "</p>" for p in spec["paragraphs"])
refs = '<div class="refs"><b>Sources</b><ol>' + "".join(
    (f'<li><a href="{SRC[k][0]}">{SRC[k][1]}</a></li>' if SRC[k][0] else f'<li>{SRC[k][1]}</li>') for k in order) + "</ol></div>"
unused = set(SRC) - set(order)
if unused:
    print("warning: unused sources " + ", ".join(sorted(unused)), file=sys.stderr)
print('<div class="col after"><h3>Rewrite</h3>' + body + refs + "</div>", end="")
