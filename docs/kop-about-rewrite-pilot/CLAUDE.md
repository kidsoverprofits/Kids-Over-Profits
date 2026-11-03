# KOP About rewrite pilot

Rewrites of the "About" text for 21 Kids Over Profits facility and operator records. Each record shows what the site prints now next to a sourced rewrite. Nothing here is on kidsoverprofits.org yet.

Published preview (private, owned by Dani): https://claude.ai/artifact/4feUz655wtt6zshbvg3kDm (same artifact as claude.ai/code/artifact/1db0c755-2e91-4a1b-b58a-5cef38081450)

## Files

- `out/recNN.json`: the source of truth for each record's rewrite (`sources` map plus `paragraphs` with `{key}` citations). Edit these, never the HTML.
- `out/recNN.html`: built from the json by `gen.py`.
- `out/recNN.note.txt`: that record's check-note additions. These cover what was added and from where, what was cut and why, conflicts, and open gaps.
- `out/recNN.orig.html`: the record as it stood before the rewrite work (old check note, "Now" text, first draft). Use it as reference only.
- `gen.py`: builds a record's Rewrite column. `python3 -I gen.py out/rec05.json > out/rec05.html`. Citations are numbered by first appearance. A source with an empty URL renders as a print citation.
- `page.v6.html`: the base page (layout, the "Now" columns, the original check notes).
- `page.html`: the current full page. It is rebuilt from `page.v6.html` plus every `out/recNN.html` and `out/recNN.note.txt` (see Rebuild).
- `archive/`: text of archived originals fetched through Chrome, plus `index.md` (claim → URL → supports it?).
- `drive/`: text extracted from originals saved in Dani's Google Drive (NATSAP 2009 directory, Provo 1996–97 parent manual, Swift River family handbook, ISAC WWASP report, Wood v. WWASPS complaint).
- `verify.md`, `verify2.md`, `verify3.md`: independent fact-checks of every sentence that names a person.

Record numbers: 00 Colorado County Boot Camp, 01 Aspen Ranch, 02 Provo Canyon School, 03 Cross Creek, 04 Eagle Ranch, 05 Highlands Youth Academy, 06 Academy at Swift River, 07 Family Foundation School, 08 SLS Residential, 09 Mount Bachelor, 10 Midwest Academy, 11 Canyon State, 12 Falcon Ridge, 13 Lava Heights, 14 Straight – Cincinnati, 15 Straight – Hampton Roads, 16 Rite of Passage, 17 Teen Challenge, 18 G4S Youth Services, 19 InnerChange, 20 Aspen Education Group.

## Sourcing rules (Dani's, strict)

1. Check KOP's own research first: the Google Drive program folders, operator sheets, and site facility/operator pages. Use them to find facts and the sources behind them.
2. Never cite KOP's files, sheets, notes or site pages. Cite the original they record. Where the only copy of an original is a file in Dani's Drive, cite the original as a print citation (empty URL, full outlet/date/docket label).
3. HEAL, the r/troubledteens wiki, Fornits, Wikipedia and Breaking Code Silence are not primary sources. Follow their citations to the original article, filing, record, or the survivor's own published account, and cite that.
4. Survivor testimony is primary when cited to where the survivor published it, and attributed in the text.
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
for f in out/rec*.json; do python3 -I gen.py "$f" > "${f%.json}.html"; done
python3 -I rebuild.py   # writes page.html from page.v6.html + out/
```

## State as of Nov 3, 2026 (evening)

All 21 records rewritten and fact-checked. Every sentence carries its own citation. Claims the earlier passes cut as
untraceable were restored, each cited to the original that HEAL, the wiki, Wikipedia or KOP's files name (rule 5).
Each `out/recNN.note.txt` lists what was restored, what is still out and why. Settled by Dani: Fager's case list is a
source; plaintiff counts take the highest reputable-media figure (Mount Bachelor: 51, The Bulletin); Daily Star = Oct 2009.

Open:
- HEAL's archived pages were opened in Chrome on Nov 3 (evening); WebFetch is still blocked from web.archive.org,
  so use Claude in Chrome. Restored: Aspen Ranch levels, blackout, accreditation and court-custody clause (enrollment
  agreement pp. 17, 27-28); Eagle Ranch police calls (St. George PD incident table, 2006-13) and the Dec 2009 recording;
  Canyon State police calls (MCSO Location History Report, 2000-13); Falcon Ridge's Apr 2019 e-mail account (Fakahua)
  and the regulator reports (Salt Lake Tribune, Mar 15, 2022, via the wiki mirror). Still out (no citation on HEAL's
  pages): Aspen Ranch cottages, $7,100, Alexander, Hirsch/Finney, cavity search; Canyon State $3M; Midwest Academy
  runaways, foster-care application, fraud probe. To do: archive the HEAL-hosted police PDFs into the media library;
  check sltrib22 when archive.ph opens (wiki says 2020, Savage was reported Mar 2021).
- reddit.com is blocked in Claude in Chrome ("safety restrictions"), and archive.is/archive.ph show a CAPTCHA.
- Mount Bachelor suit dates: The Bulletin puts the 2nd and 3rd suits in 2012; the complaint gives Nov 2011 and Jan 2013.
- Cross Creek: the "Level 2 secured facility" claim rests on ISAC's 2004 report, attributed to ISAC.
- archive.is/archive.ph links can't be read by automation (CAPTCHA); the St. George Spectrum capture for Falcon Ridge is one.
- Conflicts: Falcon Ridge Sequel end date (2020 vs Oct 2021); Lava Heights Nichols (Sequel vs Rite of Passage);
  Midwest Academy custody date; Savage's age (32 vs 23).
- Ian Mulhare died at Island View on July 1, 2004, before Aspen bought it (Nov 30, 2004): belongs on Island View's record.
- Then: the owner's go-ahead on the before/after, and the full run (docs/plan/2.5 item 5).
