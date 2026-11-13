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
CSS += """.bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 22px;font:14px/1.4 var(--ui)}
.bar button,.live summary{font:14px/1.3 var(--ui);color:var(--ink);background:var(--panel);border:1px solid var(--accent);border-radius:6px;padding:6px 12px;cursor:pointer}
.bar button:hover{border-width:2px;padding:5px 11px}.bar button:focus-visible,.live summary:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.bar button:disabled{opacity:.55;cursor:not-allowed}.bar .lst{color:var(--muted)}
.live{border-top:1px solid var(--line);margin:14px 0 0;padding:12px 0 0;font:14px/1.5 var(--ui)}
.live .lstat{margin:0 0 8px}.live .lstat b{font-weight:700}.live.same .lstat b{color:var(--accent)}
.live.diff .lstat,.live.old .lstat,.live.err .lstat{background:var(--warn);border-radius:6px;padding:8px 10px}
.live details{margin:6px 0 0}.live summary{display:inline-block;color:var(--accent);border-color:var(--line)}
.live .ltext{font:16px/1.6 var(--body);margin:10px 0 0}.live .ltext p{margin:0 0 12px;overflow-wrap:anywhere}
.live .ltext a{color:var(--accent)}.live mark{background:var(--warn);color:var(--ink);border-bottom:2px solid var(--accent)}
"""
INTRO = ("21 records: the rule-built About each page printed before, beside the agents' rewrite from the same facts, "
         "with citations numbered the way the site numbers them. The rewrites went live on November 12, 2026 "
         "(inc/about-rewrites.php). Under each record, \"On the site now\" is read from kidsoverprofits.org when this page "
         "opens; Refresh reads it again and flags any sentence the site prints differently. Survivor and family accounts "
         "are quoted word for word under each rewrite, never retold in it. Each record's research to-dos and work log "
         "are folded under it.")
BAR = ('<div class="bar"><button type="button" id="refresh">Refresh from the live site</button>'
       '<span class="lst" id="lstat" role="status">Not checked yet.</span></div>\n')

LIVE_SCRIPT = r"""<script>
/* "On the site now": each record's About and quotes as kidsoverprofits.org prints them, read from the theme's public
   read-only api/about-live.php (open CORS; the REST routes are pinned to one origin by the site's root .htaccess),
   compared sentence by sentence with the Rewrite column. Read on open and on Refresh; nothing is written anywhere. */
(function () {
  var API = "https://kidsoverprofits.org/wp-content/themes/child/api/about-live.php?page=";
  var btn = document.getElementById("refresh"), top = document.getElementById("lstat");
  var boxes = Array.prototype.slice.call(document.querySelectorAll(".live[data-page]"));
  function norm(s) {
    return String(s || "").normalize("NFKC").replace(/[‘’]/g, "'").replace(/[“”]/g, '"')
      .replace(/[‐-―]/g, "-").replace(/…/g, "...").replace(/\s+/g, " ").trim().toLowerCase();
  }
  function rewriteText(box) {
    var col = box.closest(".rec").querySelector(".col.after").cloneNode(true);
    col.querySelectorAll("sup,.testimony,.refs,h3").forEach(function (n) { n.remove(); });
    return norm(Array.prototype.map.call(col.querySelectorAll("p"), function (p) { return p.textContent; }).join(" "));
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function safeUrl(u) { return /^https?:\/\//i.test(u || "") ? u : ""; }
  function render(box, d) {
    box.textContent = "";
    box.className = "live";
    var stat = el("p", "lstat");
    box.appendChild(stat);
    if (!d || !d.ok) {
      box.classList.add("err");
      stat.textContent = "This page was not found on the site" + (d && d.status ? " (HTTP " + d.status + ")." : ".");
      return { kind: "err" };
    }
    var rw = rewriteText(box), total = 0, off = [], liveLen = 0;
    d.paragraphs.forEach(function (para) {
      para.forEach(function (s) { total++; var t = norm(s.text); liveLen += t.length; if (rw.indexOf(t) < 0) off.push(s); });
    });
    // a recording with no transcript yet (.empty) is not a quote the site can print
    var rq = Array.prototype.filter.call(box.closest(".rec").querySelectorAll(".col.after .testimony blockquote"),
      function (q) { return !q.querySelector(".empty"); }).length;
    var lq = d.testimony.length, isOp = box.dataset.page.indexOf("operator/") === 0;
    var quotes = isOp ? (rq ? " Its " + rq + " quote" + (rq === 1 ? "" : "s") + " go on the programs they name." : "")
      : " " + lq + " of the " + rq + " quote" + (rq === 1 ? "" : "s") + " shown on the page.";
    var kind;
    if (!d.writeup) {
      kind = "old"; box.classList.add("old");
      stat.appendChild(el("b", null, "Not live: "));
      stat.appendChild(document.createTextNode("the site still prints a rule-built About, not this rewrite."));
    } else if (!off.length && liveLen >= rw.length * 0.9) {
      kind = "same"; box.classList.add("same");
      stat.appendChild(el("b", null, "✓ Live as written: "));
      stat.appendChild(document.createTextNode("the site prints this rewrite, all " + total + " sentences." + quotes));
    } else {
      kind = "diff"; box.classList.add("diff");
      stat.appendChild(el("b", null, "Live, with differences: "));
      stat.appendChild(document.createTextNode(off.length
        ? off.length + " of " + total + " sentences on the site are not in the rewrite (highlighted below)." + quotes
        : "every sentence on the site is in the rewrite, but some of the rewrite is missing from the site." + quotes));
    }
    if (d.updated_label) stat.appendChild(document.createTextNode(" Record updated " + d.updated_label + "."));
    var det = el("details"), sum = el("summary", null, "Show what the site prints");
    det.appendChild(sum);
    var wrap = el("div", "ltext"), order = [], refs = el("ol");
    d.paragraphs.forEach(function (para) {
      var p = el("p");
      para.forEach(function (s, i) {
        if (i) p.appendChild(document.createTextNode(" "));
        p.appendChild(off.indexOf(s) >= 0 ? el("mark", null, s.text) : document.createTextNode(s.text));
        s.sources.forEach(function (c) {
          var key = c.url || c.cite, n = order.indexOf(key);
          if (n < 0) {
            order.push(key); n = order.length - 1;
            var li = el("li"), u = safeUrl(c.url);
            if (u) { var a = el("a", null, c.cite || u); a.href = u; li.appendChild(a); } else li.textContent = c.cite;
            refs.appendChild(li);
          }
          var sup = el("sup"), u2 = safeUrl(c.url);
          if (u2) { var a2 = el("a", null, "[" + (n + 1) + "]"); a2.href = u2; sup.appendChild(a2); } else sup.textContent = "[" + (n + 1) + "]";
          p.appendChild(sup);
        });
      });
      wrap.appendChild(p);
    });
    if (lq) {
      var tw = el("div", "testimony"); tw.appendChild(el("h4", null, "Survivor testimony on the page"));
      d.testimony.forEach(function (t) {
        var q = el("blockquote", null, t.text), f = el("footer"), u = safeUrl(t.url);
        f.appendChild(document.createTextNode(t.credit));
        if (u) { f.appendChild(document.createTextNode(" · ")); var a = el("a", null, "original"); a.href = u; f.appendChild(a); }
        if (t.note) { f.appendChild(el("br")); f.appendChild(document.createTextNode(t.note)); }
        q.appendChild(f); tw.appendChild(q);
      });
      wrap.appendChild(tw);
    }
    if (order.length) { var r = el("div", "refs"); r.appendChild(el("b", null, "Sources")); r.appendChild(refs); wrap.appendChild(r); }
    det.appendChild(wrap);
    box.appendChild(det);
    return { kind: kind };
  }
  function one(box) {
    var ctl = new AbortController(), t = setTimeout(function () { ctl.abort(); }, 30000);
    return fetch(API + encodeURIComponent(box.dataset.page) + "&_=" + Date.now(), { signal: ctl.signal, credentials: "omit" })
      .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
      .then(function (j) { clearTimeout(t); return render(box, (j.pages || {})[box.dataset.page]); })
      .catch(function (e) {
        clearTimeout(t);
        box.className = "live err"; box.textContent = "";
        box.appendChild(el("p", "lstat", "Could not read this page from kidsoverprofits.org (" + (e && e.name === "AbortError" ? "timed out" : (e && e.message) || "error") + ")."));
        return { kind: "fail" };
      });
  }
  function run() {
    btn.disabled = true;
    top.textContent = "Reading " + boxes.length + " pages from kidsoverprofits.org...";
    boxes.forEach(function (b) { b.className = "live"; b.textContent = ""; b.appendChild(el("p", "lstat", "Reading the live page...")); });
    var queue = boxes.slice(), res = [];
    function worker() { var b = queue.shift(); return b ? one(b).then(function (r) { res.push(r.kind); return worker(); }) : Promise.resolve(); }
    Promise.all([worker(), worker(), worker(), worker()]).then(function () {
      btn.disabled = false;
      var c = function (k) { return res.filter(function (x) { return x === k; }).length; };
      var at = new Date().toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
      if (c("fail") === res.length) { top.textContent = "Could not reach kidsoverprofits.org from this view (" + at + "). The Rewrite column is the copy from the last build."; return; }
      var bits = [c("same") + " live as written"];
      if (c("diff")) bits.push(c("diff") + " live with differences");
      if (c("old")) bits.push(c("old") + " not live");
      if (c("err") + c("fail")) bits.push((c("err") + c("fail")) + " not read");
      top.textContent = "Checked against kidsoverprofits.org " + at + ": " + bits.join(", ") + ".";
    });
  }
  btn.addEventListener("click", run);
  run();
})();
</script>
"""

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
    href = re.search(r'<h2><a href="https://kidsoverprofits\.org/((?:facility|operator)/[A-Za-z0-9_-]+)/?"', sec).group(1)
    sec = sec[:i] + new + "</div>" + f'<div class="live" data-page="{href}"><p class="lstat">Not read from the site yet.</p></div>' + sec[j + len("</div>"):]
    sec = sec.replace("<h3>Now</h3>", "<h3>Before (rule-built)</h3>", 1)
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
parts.insert(1, BAR + summary)
parts.append(s[secs[-1]:].replace("</main>", "</main>\n" + SCRIPT + LIVE_SCRIPT, 1))
open("page.html", "w", encoding="utf-8", newline="\n").write("".join(parts))
print("wrote page.html with", len(secs) - 1, "records,", len(todo), "asks")
