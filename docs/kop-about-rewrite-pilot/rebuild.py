"""Rebuild page.html from page.v6.html plus out/recNN.html and out/recNN.note.json.

The note has three parts (kop-pipeline scripts/about-rewrite/BRIEF.md, owner 2026-11-11): needs_you is shown on the
record and gathered into one list at the top of the page, where each choice is a button that saves the answer to
the artifact's db (published with capabilities {db, user}; read back with ArtifactData, collection "answers"); research and log are folded under "How this was checked".
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
.ask{margin:4px 0 0}.ask .q{margin:0 0 6px}.chs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 6px}
.ch,.savenote{font:14px/1.3 var(--ui);color:var(--ink);background:var(--panel);border:1px solid var(--accent);border-radius:6px;padding:6px 10px;cursor:pointer;text-align:left}
.ch[aria-pressed="true"]{background:var(--accent);color:var(--panel);border-color:var(--accent)}
.ch:hover,.savenote:hover{border-width:2px;padding:5px 9px}.ch:focus-visible,.savenote:focus-visible,.own input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.ch:disabled,.savenote:disabled,.own input:disabled{opacity:.55;cursor:not-allowed}
.own{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font:13px/1.4 var(--ui);color:var(--muted)}
.own input{flex:1 1 220px;min-width:0;font:14px/1.3 var(--ui);color:var(--ink);background:var(--panel);border:1px solid var(--line);border-radius:6px;padding:6px 8px}
.st{font:13px/1.4 var(--ui);color:var(--muted);margin:4px 0 0;min-height:1.4em}.ask.done .q::before{content:"\2713  ";color:var(--accent);font-weight:700}
.todo li{margin:0 0 14px}.todo .how{margin:0 0 10px;color:var(--muted)}
"""
INTRO = ("21 records: what the page prints now, beside the agents' rewrite from the same facts, with citations numbered "
         "the way the site numbers them. The yellow list below is everything that needs your decision; each record's "
         "research to-dos and work log are folded under it. Survivor and family accounts are quoted word for word "
         "under each rewrite, never retold in it. Nothing here is on the site yet.")

SCRIPT = """<script>
/* Answers to the "Needs you" questions live in the artifact's db: collection "answers", one doc per question id
   (recNN-i) holding {choice, note, q, rec, at}. The top list and each record's box show the same doc. */
(function () {
  var state = {}, db = null, col = null;
  var asks = Array.prototype.slice.call(document.querySelectorAll(".ask"));
  function setAll(msg, off) {
    asks.forEach(function (el) {
      el.querySelectorAll("button,input").forEach(function (b) { b.disabled = !!off; });
      if (msg !== null) el.querySelector(".st").textContent = msg;
    });
  }
  function render() {
    var done = {};
    asks.forEach(function (el) {
      var a = state[el.dataset.ask];
      el.querySelectorAll(".ch").forEach(function (b) {
        b.setAttribute("aria-pressed", a && a.choice === b.dataset.choice ? "true" : "false");
      });
      var inp = el.querySelector("input");
      if (document.activeElement !== inp) inp.value = (a && a.note) || "";
      el.classList.toggle("done", !!a);
      if (!el.dataset.busy) el.querySelector(".st").textContent = a ? "Saved" + (a.choice ? ": " + a.choice : "") + (a.note ? (a.choice ? " + note" : ": your own answer") : "") : "";
      if (a) done[el.dataset.ask] = 1;
    });
    var c = document.querySelector(".todo .count");
    var total = new Set(asks.map(function (el) { return el.dataset.ask; })).size;
    if (c) c.textContent = "(" + Object.keys(done).length + " of " + total + " answered)";
  }
  function save(el, patch) {
    var id = el.dataset.ask, cur = state[id] || {};
    var next = { choice: cur.choice || "", note: cur.note || "", q: el.dataset.q, rec: el.dataset.rec, at: new Date().toISOString() };
    for (var k in patch) next[k] = patch[k];
    el.dataset.busy = "1";
    el.querySelector(".st").textContent = "Saving...";
    var ref = col.doc(id);
    var p = (!next.choice && !next.note) ? ref.delete() : ref.set(next);
    p.then(function () {
      delete el.dataset.busy;
      if (!next.choice && !next.note) delete state[id]; else state[id] = next;
      render();
    }, function (e) {
      delete el.dataset.busy;
      el.querySelector(".st").textContent = "Not saved (" + ((e && e.code) || "error") + "). You may not have edit access to this page.";
    });
  }
  document.addEventListener("click", function (ev) {
    var b = ev.target.closest(".ch,.savenote");
    if (!b || !col) return;
    var el = b.closest(".ask"), cur = state[el.dataset.ask] || {};
    if (b.classList.contains("ch")) save(el, { choice: cur.choice === b.dataset.choice ? "" : b.dataset.choice });
    else save(el, { note: el.querySelector("input").value.trim() });
  });
  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Enter" && ev.target.matches(".own input") && col) {
      save(ev.target.closest(".ask"), { note: ev.target.value.trim() });
    }
  });
  setAll("Loading saved answers...", true);
  var timer = setTimeout(function () { setAll("Saved answers could not be loaded. Reload the page to try again.", true); }, 10000);
  var use = window.claude && window.claude.use;
  if (!use) { clearTimeout(timer); setAll("Answers can only be saved when this page is open on claude.ai.", true); return; }
  use("db").then(function (d) {
    if (!d) { clearTimeout(timer); setAll("Answers can't be saved in this view. Open the page on claude.ai while signed in.", true); return; }
    db = d; col = db.collection("answers");
    col.onSnapshot(function (snap) {
      clearTimeout(timer);
      state = {};
      snap.docs.forEach(function (doc) { state[doc.id] = doc.data() || {}; });
      setAll(null, false);
      render();
    }, function (e) {
      clearTimeout(timer);
      setAll("Saved answers could not be loaded (" + ((e && e.code) || "error") + ").", true);
    });
  }, function () { clearTimeout(timer); setAll("Answers can't be saved in this view.", true); });
})();
</script>
"""


def ask_html(x, aid, where, rec):
    """One decision as an answer widget: a button per choice and a box for an answer of your own.
    The page saves the answer to the artifact's db (collection "answers", doc = aid); see SCRIPT."""
    q = x if isinstance(x, str) else x["ask"]
    chs = [] if isinstance(x, str) else (x.get("choices") or [])
    btns = "".join(f'<button type="button" class="ch" data-choice="{html.escape(c, quote=True)}" aria-pressed="false">{html.escape(c)}</button>' for c in chs)
    nid = f"{where}-{aid}-note"
    return (f'<div class="ask" data-ask="{aid}" data-rec="{html.escape(rec, quote=True)}" data-q="{html.escape(q, quote=True)}">'
            f'<p class="q">{html.escape(q)}</p><div class="chs">{btns}</div>'
            f'<div class="own"><label for="{nid}">Or your own answer</label><input type="text" id="{nid}" autocomplete="off">'
            f'<button type="button" class="savenote">Save</button></div><p class="st" role="status"></p></div>')


def note_html(asks, research, log, n, rec):
    out = ""
    if asks:
        out += f'<div class="check"><b>Needs you ({len(asks)})</b><ol>' + "".join(f"<li>{ask_html(x, f'rec{n:02d}-{i + 1}', 'r', rec)}</li>" for i, x in enumerate(asks)) + "</ol></div>"
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
    sec = sec.replace('<section class="rec">', f'<section class="rec" id="rec{n:02d}">', 1)
    asks, research, log, folded = load_note(n)
    c0 = sec.index('<div class="check">')
    c1 = sec.index("</pre></div>", c0) + len("</pre></div>")
    if not folded:  # old record: keep the base note's text in the log
        base = html.unescape(re.sub(r"<[^>]+>", "", sec[c0:c1].split("<pre>", 1)[1]))
        log = (base.strip() + "\n\n" + log).strip()
    name = html.unescape(re.search(r"<h2><a [^>]*>(.*?)</a>", sec).group(1))
    sec = sec[:c0] + note_html(asks, research, log, n, name) + sec[c1:]
    todo += [f'<li><a href="#rec{n:02d}">{html.escape(name)}</a>{ask_html(x, f"rec{n:02d}-{i + 1}", "t", name)}</li>' for i, x in enumerate(asks)]
    parts.append(sec)
summary = (f'<section class="todo"><h2>Needs you <span class="count">({len(todo)})</span></h2>'
           '<p class="how">Click a choice to save it, or write your own answer. Click a saved choice again to clear it. Claude reads the answers from here.</p>'
           '<ol>' + "".join(todo) + "</ol></section>\n") if todo else \
    '<section class="todo"><h2>Nothing needs you</h2><p>Every open point is a research to-do for the agents.</p></section>\n'
parts.insert(1, summary)
parts.append(s[secs[-1]:].replace("</main>", "</main>\n" + SCRIPT, 1))
open("page.html", "w", encoding="utf-8", newline="\n").write("".join(parts))
print("wrote page.html with", len(secs) - 1, "records,", len(todo), "asks")
