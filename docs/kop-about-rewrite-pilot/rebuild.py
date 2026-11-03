"""Rebuild page.html from page.v6.html plus out/recNN.html and out/recNN.note.txt."""
import re

s = open("page.v6.html", encoding="utf-8").read()
secs = [m.start() for m in re.finditer(r'<section class="rec"', s)] + [s.index("</main>")]
parts = [s[: secs[0]]]
for n, (a, b) in enumerate(zip(secs, secs[1:])):
    sec = s[a:b]
    new = open(f"out/rec{n:02d}.html", encoding="utf-8").read()
    i = sec.index('<div class="col after">')
    j = sec.rindex("</div></section>")
    sec = sec[:i] + new + sec[j:]
    if n == 17:  # drop superseded review lines from the Teen Challenge base note
        k0 = sec.find("\n\nAdded after review (Nov 2)")
        if k0 > 0:
            sec = sec[:k0] + sec[sec.index("</pre></div>"):]
    note = open(f"out/rec{n:02d}.note.txt", encoding="utf-8").read().strip()
    note = note.replace("&amp;", "&").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    k = sec.index("</pre></div>")
    sec = sec[:k] + "\n\n" + note + sec[k:]
    parts.append(sec)
parts.append(s[secs[-1]:])
open("page.html", "w", encoding="utf-8").write("".join(parts))
print("wrote page.html with", len(secs) - 1, "records")
