# CSS and colour checks

Commands, tests and rules for page CSS: bare text, contrast, mobile overflow, and the Kadence colour traps. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# After any template or page CSS change: no text may sit on the gradient body background
python scripts/check-bare-text.py               # one page per child template, live site
# ...and no text unreadable against its own panel, incl. forced :hover/:focus (see "Kadence colour traps")
python -u scripts/check-contrast.py [--local]   # WCAG AA, text and icons; --local serves the working tree css/js on the live pages
python scripts/test-colour-contrast.py          # static: inks, fills, no accent text or white-on-accent in any stylesheet
# ...and nothing runs out of its box at phone/tablet width: a grid column is minmax(0, 1fr), never a bare 1fr (it grows to a
# long link or a select's longest option), a fixed-width input gets width:100% under 768px, a form's "input { width:100% }"
# never reaches checkboxes. The admin review page is checked by scripts/test-review-inbox-ui.py (1280/768/390)
python -u scripts/check-mobile-overflow.py [--local] [--widths 390,768] [paths]   # WIDE / SPILL / TEXT out of its box, live site
```

## Kadence colour traps (why text goes invisible)
Our stylesheets load **before** `kadence-global-css` on every page, so any
specificity tie goes to Kadence. Kadence also colours these elements directly,
so they do **not** inherit a panel's `color`:

- `h1`-`h6`: #1A202C / #2D3748. A navy panel with `color:#fff` still gets black headings.
- `a`: navy #000080 (hover #000435). A link in a navy panel is navy on navy.
- `button`, `.button`, `input[type=submit]`: white text on teal, and `button:hover`,
  `button:focus` (0,1,1) beat a one-class rule (0,1,0). A button styled
  `.x { background:#fff; color:navy }` or `.x:hover { background:sand }` goes
  white on white/sand on hover, or after a click (focus sticks).
- `input`/`select`/`textarea`: grey text on a white box. A transparent field on a dark panel is grey on navy;
  `select option` popups need their own background and colour.
- Undefined `var(--kop-x)` with no fallback resolves to *inherit*, so it silently
  takes the panel colour (the old `--kop-navy` white-on-white).
- Our own `.panel * { color:#fff !important }` blankets repaint components injected
  into the panel (doc tiles, pills, bug flags) white on their white backgrounds.

Rules: a dark panel sets colours for its headings, links and buttons explicitly.
Every `<button>` rule is `button.x` and names **both** colour and background in
the rest, `:hover`, `:focus` and `:active` states (links styled as buttons too:
Kadence's `a:focus` is midnight). A reusable component sets its own foreground
and background instead of inheriting. No `*` colour blankets.

Every text passes WCAG AA (4.5:1, large text 3:1) and every icon 3:1, in every state.
- Accent as **text or icon** on a light ground: `--kop-*-ink` (teal, orange, coral-pink,
  chartreuse, bubblegum-pink). On navy/midnight keep the bright accent.
- **White text on a fill**: `--kop-*-fill` (white on teal fill 5.35), never the bright
  accent (white on teal 2.86). Dark text on a bright accent is fine (midnight on teal 6.86).
- Secondary text (dates, sources, counts): `--kop-text-muted` (#4A5568), never Kadence
  greys like #718096 / #9ca3af / #999.
- Kadence's button and grey colours are restated from the palette in `css/colors.css`
  (`html:root`); plugin output (FileBird, MailerLite, AddToAny) in `css/plugin-contrast.css`.
- Icons (`kop_icon()` / `kopIcon()`) draw in `currentColor`: colour the text, not the SVG.

Check with `python -u scripts/check-contrast.py [--local] [paths]` (every text node and
small SVG against its real background, hover and focus forced, names the rule that set
each failing colour) and `python scripts/test-colour-contrast.py` (static: tokens, no
accent text, no white on an accent in any stylesheet).
