"""Rebuild page.html from page.v6.html plus out/recNN.html and out/recNN.note.json.

The note has three parts (kop-pipeline scripts/about-rewrite/BRIEF.md, owner 2026-11-11): needs_you is shown on the
record and gathered into one list at the top of the page; research and log are folded under "How this was checked".
The base page's own check note (page.v6.html) is replaced: its content was folded into note.json's log.
A record still without note.json falls back to out/recNN.note.txt, shown folded as its log.
"""
import html
import json
import os
import re

CSS = """.check ol{margin:6px 0 0;padding-left:22px}.choices{display:block;color:var(--muted);font-size:.9em}
.log{font:13px/1.5 var(--ui);color:var(--muted);margin:0 0 12px}.log summary{cursor:pointer;color:var(--accent)}
.log pre{white-space:pre-wrap;margin:4px 0 8px;font:inherit}.log ul{margin:4px 0 8px;padding-left:20px}
.todo{background:var(--warn);border-radius:8px;padding:14px 18px;margin:0 0 22px;font:15px/1.5 var(--ui)}
.todo h2{font:700 1.1rem/1.3 var(--ui);margin:0 0 6px}.todo ol{margin:0;padding-left:22px}.todo li{margin:0 0 6px}.todo a{color:var(--ink);font-weight:600}
.testimony{border-top:1px solid var(--line);padding-top:8px;margin:0 0 12px}
.testimony h4{font:600 .75rem/1 var(--ui);text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:4px 0 8px}
.testimony blockquote{margin:0 0 12px;padding:2px 0 2px 12px;border-left:3px solid var(--accent);overflow-wrap:anywhere}
.testimony footer{font:13px/1.4 var(--ui);color:var(--muted);margin-top:4px}.via{font-style:italic}
"""
INTRO = ("21 records: what the page prints now, beside the agents' rewrite from the same facts, with citations numbered "
         "the way the site numbers them. The yellow list below is everything that needs your decision; each record's "
         "research to-dos and work log are folded under it. Survivor and family accounts are quoted word for word "
         "under each rewrite, never retold in it. Nothing here is on the site yet.")


def ask_html(x):
    if isinstance(x, str):
        return html.escape(x)
    ch = " / ".join(html.escape(c) for c in x.get("choices") or [])
    return html.escape(x["ask"]) + (f'<span class="choices">{ch}</span>' if ch else "")


def note_html(asks, research, log):
    out = ""
    if asks:
        out += f'<div class="check"><b>Needs you ({len(asks)})</b><ol>' + "".join(f"<li>{ask_html(x)}</li>" for x in asks) + "</ol></div>"
    if research or log:
        bits = ([f"{len(research)} research to-do" + ("" if len(research) == 1 else "s")] if research else []) + (["work log"] if log else [])
        body = ("<b>Research to-dos</b> (for agents, not you)<ul>" + "".join(f"<li>{html.escape(r)}</li>" for r in research) + "</ul>") if research else ""
        body += f"<b>Work log</b><pre>{html.escape(log)}</pre>" if log else ""
        out += f'<details class="log"><summary>How this was checked: {", ".join(bits)}</summary>{body}</details>'
    return out


def load_note(n):
    j = f"out/rec{n:02d}.note.json"
    if os.path.exists(j):
        d = json.load(open(j, encoding="utf-8"))
        return [x for x in d.get("needs_you") or [] if x], [x for x in d.get("research") or [] if x], (d.get("log") or "").strip(), True
    return [], [], open(f"out/rec{n:02d}.note.txt", encoding="utf-8").read().strip().replace("&amp;", "&"), False


s = open("page.v6.html", encoding="utf-8").read()
s = s.replace("</style>\n<main>", CSS + "</style>\n<main>", 1)
s = re.sub(r"(<header><h1>About rewrite pilot</h1><p>).*?(</p></header>)", lambda m: m.group(1) + html.escape(INTRO) + m.group(2), s, count=1)
secs = [m.start() for m in re.finditer(r'<section class="rec"', s)] + [s.index("</main>")]
parts, todo = [s[: secs[0]]], []
for n, (a, b) in enumerate(zip(secs, secs[1:])):
    sec = s[a:b]
    new = open(f"out/rec{n:02d}.html", encoding="utf-8").read()
    i = sec.index('<div class="col after">')
    j = sec.rindex("</div></section>")
    sec = sec[:i] + new + sec[j:]
    asks, research, log, folded = load_note(n)
    c0 = sec.index('<div class="check">')
    c1 = sec.index("</pre></div>", c0) + len("</pre></div>")
    if not folded:  # old record: keep the base note's text in the log
        base = html.unescape(re.sub(r"<[^>]+>", "", sec[c0:c1].split("<pre>", 1)[1]))
        log = (base.strip() + "\n\n" + log).strip()
    sec = sec[:c0] + note_html(asks, research, log) + sec[c1:]
    sec = sec.replace('<section class="rec">', f'<section class="rec" id="rec{n:02d}">', 1)
    name = html.unescape(re.search(r"<h2><a [^>]*>(.*?)</a>", sec).group(1))
    todo += [f'<li><a href="#rec{n:02d}">{html.escape(name)}</a>: {ask_html(x)}</li>' for x in asks]
    parts.append(sec)
summary = (f'<section class="todo"><h2>Needs you ({len(todo)})</h2><ol>' + "".join(todo) + "</ol></section>\n") if todo else \
    '<section class="todo"><h2>Nothing needs you</h2><p>Every open point is a research to-do for the agents.</p></section>\n'
parts.insert(1, summary)
parts.append(s[secs[-1]:])
open("page.html", "w", encoding="utf-8", newline="\n").write("".join(parts))
print("wrote page.html with", len(secs) - 1, "records,", len(todo), "asks")
