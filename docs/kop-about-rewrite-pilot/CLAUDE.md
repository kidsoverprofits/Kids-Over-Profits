# KOP About rewrite pilot

Rewrites of the "About" text for 21 Kids Over Profits facility and operator records. Each record shows what the site prints now next to a sourced rewrite. Nothing here is on kidsoverprofits.org yet.

Published preview (private, owned by Dani): https://claude.ai/artifact/4feUz655wtt6zshbvg3kDm (same artifact as claude.ai/code/artifact/1db0c755-2e91-4a1b-b58a-5cef38081450)

## Files

- `out/recNN.json`: the source of truth for each record's rewrite (`sources` map, `paragraphs` with `{key}` citations, and `testimony`: survivor and family accounts quoted word for word, see gen.py). Edit these, never the HTML.
- `out/recNN.note.json`: the record's note in three parts (owner, 2026-11-11): `needs_you` (decisions only, shown on the record and gathered at the top of the page), `research` (tasks for agents) and `log` (folded). This replaces the base note in `page.v6.html`.
- `out/recNN.html`: built from the json by `gen.py`.
- `out/recNN.note.txt`: the older check-note additions, kept as history (folded into note.json). These cover what was added and from where, what was cut and why, conflicts, and open gaps.
- `out/recNN.orig.html`: the record as it stood before the rewrite work (old check note, "Now" text, first draft). Use it as reference only.
- `gen.py`: builds a record's Rewrite column. `python -I gen.py out/rec05.json > out/rec05.html`. Citations are numbered by first appearance. A source with an empty URL renders as a print citation.
- `page.v6.html`: the base page (layout, the "Now" columns, the original check notes).
- Dani's answers to the "Needs you" questions: saved by the page's buttons to the artifact's database, collection `answers`, one doc per question (`recNN-i`: choice, note, q, rec, at). Read them with the ArtifactData tool (`list`, collection `answers`) and apply them; only the owner can write.
- `page.html`: the current full page. It is rebuilt from `page.v6.html` plus every `out/recNN.html` and `out/recNN.note.json` (see Rebuild).
- `archive/`: text of archived originals fetched through Chrome, plus `index.md` (claim → URL → supports it?).
- `drive/`: text extracted from originals saved in Dani's Google Drive (NATSAP 2009 directory, Provo 1996–97 parent manual, Swift River family handbook, ISAC WWASP report, Wood v. WWASPS complaint).
- `verify.md`, `verify2.md`, `verify3.md`: independent fact-checks of every sentence that names a person.

Record numbers: 00 Colorado County Boot Camp, 01 Aspen Ranch, 02 Provo Canyon School, 03 Cross Creek, 04 Eagle Ranch, 05 Highlands Youth Academy, 06 Academy at Swift River, 07 Family Foundation School, 08 SLS Residential, 09 Mount Bachelor, 10 Midwest Academy, 11 Canyon State, 12 Falcon Ridge, 13 Lava Heights, 14 Straight – Cincinnati, 15 Straight – Hampton Roads, 16 Rite of Passage, 17 Teen Challenge, 18 G4S Youth Services, 19 InnerChange, 20 Aspen Education Group.

## Sourcing rules (Dani's, strict)

1. Check KOP's own research first: the Google Drive program folders, operator sheets, and site facility/operator pages. Use them to find facts and the sources behind them.
2. Never cite KOP's files, sheets, notes or site pages. Cite the original they record. Where the only copy of an original is a file in Dani's Drive, cite the original as a print citation (empty URL, full outlet/date/docket label).
3. HEAL, the r/troubledteens wiki, Fornits, Wikipedia and Breaking Code Silence are not primary sources. Follow their citations to the original article, filing, record, or the survivor's own published account, and cite that.
4. Survivor testimony is primary when cited to where the survivor published it. It is never retold in the text (owner, 2026-11-11): it is quoted word for word in `testimony`, only spelling and punctuation fixed, "…" for cuts, with one pointer sentence in the text. A survivor quoted by a reporter, court or hearing stays in the text only as a direct quote, named as the source names them. kop-pipeline `scripts/about-rewrite/BRIEF.md` rules 28 to 32.
5. HEAL, the wiki and KOP's pages and files each carry their own citation for a claim (a footnote, a link, a named article or docket). Take that citation and cite the original. You don't have to fetch or verify the original first (Dani, Nov 3). Never cut a claim because the original couldn't be opened or checked. A claim stays out only when the citing source gives no citation at all; list it in the note with the page checked.
6. A "correction" that contradicts KOP's records gets flagged in the note, not silently written into the text.
7. Every sentence naming a person with an accusation, charge, conviction, lawsuit, firing or death must match its source's strength (alleged / charged / convicted, dates, ages, counts). Check new ones against the source before publishing.

Scan for banned hosts after any change (survivors' own Reddit posts are primary under rule 4, so only the wiki is matched):

```
grep -oE 'href="[^"]*(heal-online|reddit\.com/r/troubledteens/wiki|fornits|wikipedia\.org|docs\.google|drive\.google|breakingcodesilence|kidsoverprofits\.org/(facility|operator|glossary))[^"]*"' out/rec*.html
```

## Writing rules

1. Date every event (year, and month where known).
2. Don't frame long-running abuse as recent. Show how far back complaints go.
3. Lawsuits have a history. Present earlier ones before the latest wave.
4. Give big events their real weight and show the consequences.
5. Write it like an article, not a fact list. One topic or period per paragraph.
6. Keep related information together.
7. Use real transitions between paragraphs.
8. Default order: what it is → origins/ownership → programs → regulatory/political history → abuse, deaths, investigations → lawsuits → current status.
9. The lede summarizes the whole record with dated examples.
10. Every factual sentence is cited.
11. Trace secondary sources to the original.
12. Research when the material is thin.
13. Attribute self-told accounts (founder memoirs, program websites).
14. Name the people the sources name, at the strength the sources support.

## Rebuild

```
for f in out/rec??.json; do python -I gen.py "$f" > "${f%.json}.html"; done   # python, not python3, on this machine
python -I rebuild.py   # writes page.html from page.v6.html + out/
```

## State as of Nov 4, 2026 (evening, preview version 19)

All 21 records rewritten and fact-checked. Every sentence carries its own citation. Claims the earlier passes cut as
untraceable were restored, each cited to the original that HEAL, the wiki, Wikipedia or KOP's files name (rule 5).
Each `out/recNN.note.txt` lists what was restored, what is still out and why. Settled by Dani: Fager's case list is a
source; plaintiff counts take the highest reputable-media figure (Mount Bachelor: 51, The Bulletin); Daily Star = Oct 2009.

Settled Nov 4 (evening):
- Mount Bachelor's three suits, from the federal coverage opinion Nautilus Ins. v. Mount Bachelor Educational Center
  (D. Or. 3:12-cv-00825, Dec 21, 2012, govinfo): MLM No. 1107-08552 (Multnomah, July 2011); LF No. 1109-11909
  (Multnomah, dated Sept 13, 2011); Doe 210 No. 12-cv-0008 (Deschutes, Jan 4, 2012). Per-suit counts from The Bulletin,
  Jan 7, 2014: 17/$26M, 15/$22.85M, 13/$19.5M. The Bulletin, Nov 10, 2011: second suit filed Nov 9, 2011 (14, $23M).
  rec09's text gives the third suit's date and case number; its note flags the LF Sept-vs-Nov date and 45-vs-51 totals.
- Falcon Ridge / Lava Heights: the schools' own sites name Sequel through Sept 27-28, 2021 and Rite of Passage by
  Oct 20-22, 2021 (Wayback). KOP's "Sequel 2016-2020" is wrong. The old frr/lha citations (Sept 2021 captures) never
  named Rite of Passage; rec12/13/16 now cite both captures, and the Jan 31, 2022 licences to Utah's records.
- Nichols was Rite of Passage staff (hired Sept 2022), not Sequel's; now in rec16 too. Savage was 32 (Tribune, Fox13,
  Gephardt), now in rec12. Midwest Academy: Trane surrendered Mar 6, 2023 (WGEM); the 2021 date has no source.
- Ian Mulhare stays off Aspen (rec20 note): his death goes on Island View's record (facility 100601; successor
  Elevations RTC 10356) in the full run, cited to the police report (KOP media 2006 holds a copy).
- Eagle Ranch Dec 2009 recording: HEAL's eradec09.mp3 (Wayback Nov 28, 2015, 41 MB) is in the media library
  (17225, UTAH / Eagle Ranch Academy) and sent to Websites Sent In; era09 links KOP's copy.
- Drive "Seq Timeline Draft": the Nichols entry now says he was Rite of Passage staff hired Sept 2022, and a 2021
  entry records the Oct 2021 handover. Savage's age needs no fix: no KOP file gives 23 (the timeline says 32).
  The site's Falcon Ridge (9607) and Lava Heights (9611) records already end Sequel in Oct 2021.

Open:
- Drive sheet "Sequel/TSI/YSI/Vivant" opens View only in Claude in Chrome's profile, so it is not fixed yet (owner):
  operators tab, Falcon Ridge Ranch row, Sequel column: 2016-2020 -> 2016-2021; staff tab, Nichols, Paul Anthony:
  "Sequel TSI: Staff member at Lava Heights Academy" -> Rite of Passage staff (hired Sept 2022), not Sequel.
- Delete the server staging folder ~/staged-era09 (the MP3 is in uploads now).
- Check sltrib22 when archive.ph opens (wiki says 2020, Savage was reported Mar 2021).
- reddit.com is blocked in Claude in Chrome ("safety restrictions"), and archive.is/archive.ph show a CAPTCHA.
- Cross Creek: the "Level 2 secured facility" claim rests on ISAC's 2004 report, attributed to ISAC.
- Mount Bachelor: per-suit plaintiff counts at settlement need OECI (login).
- Then: the owner's go-ahead on the before/after, and the full run (docs/plan/2.5 item 5).
