# Writing style guide

How Kids Over Profits writes anything a paragraph or longer: articles, Investigatory Spotlights, hub and page
text, related-industry pages, company histories, wiki entries and wiki update drafts, summaries, captions with
more than a line. Read it before writing, and check the draft against it before handing it over.

The model piece is the John Santa spotlight:
https://kidsoverprofits.org/investigatory-spotlight-john-santa-montana-academy/ (about 1,100 words of body).

## 1. Decide the point before writing

- Write the piece's point in one sentence first. Everything else serves it.
- Points come in layers. The piece has one overarching point; each section has one point that supports it;
  each paragraph has one point that supports its section's point. Test: every paragraph's point can be stated
  in a sentence and named as support for its section's point, which in turn supports the piece's.
- When outlining, give every paragraph a "why here" line. A weak answer means cut it or rethink it.
- Two questions before adding anything: for a point, "why does this matter?" (to the reader, to the argument);
  for a supporting fact, "is this interesting?" A fact that is true and relevant but dull, or there only for
  completeness, is cut.
- Never place a fact somewhere because it had nowhere else to go. If no paragraph's point needs it, cut it.

## 2. Argue, don't list

- A piece has a thesis and argues it. Each paragraph moves the argument forward; it does not catalogue cases.
- Order sections so each follows from the last. Never a fixed encyclopedia template ("what it is / funding /
  history / connection / findings").
- No standalone history section. Put each piece of history where it explains something in the argument, so the
  reader knows why it is there.
- Don't stack long bullet lists. Split topics into their own sections of prose.
- See the bigger picture: look for the company-level pattern before listing programs. Chains shed programs that
  get too hot and the same people return under a new company (Aspen -> Family Help & Wellness -> EOSIS,
  Sequel -> Vivant, Embark's umbrella). KOP's company histories (`seeds/operator-histories.json`) and the
  network map's operator and people lines usually hold this already.
- Track what is happening now. Recent cases (roughly the last decade, newest first) carry the argument. Older
  cases are brief background showing the pattern is old, never the bulk of the piece. Research current cases
  (news, KOP's records, the map's dated renames) before leaning on the archive.

## 3. Tone: urgency, and say what the record shows plainly

- The tone comes from urgency and a duty to protect children and other vulnerable people. These programs are
  hurting people now; write as someone whose job is to stop it, not as a neutral observer cataloguing an industry.
  The urgency comes from the facts and how hard they are driven home, not from exclamation marks or adjectives
  piled on (section 4 still holds).
- Use direct quotes when they strengthen the point: the director's own letter, the inspector's finding, the
  survivor's words, the company's marketing beside what happened. A quote that only restates what the sentence
  already says is cut.
- Name who is responsible and say what they did. A judgment sits right after the documented fact it rests on:
  "His manipulative response reflects his true priorities." "The lack of compassion and professionalism in this
  response is astonishing."
- The main priorities are hypocrisy and accountability: set what the industry claims beside what the record
  shows (the man who chaired the ethics committee; a regulator that never sanctioned anyone after 58 complaints;
  a death answered with a dismissive letter).
- Never invent a judgment the record can't support. Attribute allegations to whoever made them.
- Name victims where the record does. Costs ($8,000 a month), counts and dates make the point.
- End on what the facts mean for the wider industry, not on a summary and not on advice.

## 4. Don't talk down to the reader

- State the documented facts and let the reader draw the conclusion the facts already make.
- No framing that treats readers as slow: no "reported here as they gave them; the effect is the same", no
  sentences explaining an implication the reader can see, no closing "before trusting X, do Y" advice, no
  catch-all sections ("Renamed for reasons of their own").
- Prefer more, detailed, sourced examples over commentary. Put each case where it belongs, with what led up to it.
- Having a point of view is wanted (section 3). Explaining the obvious is not.

## 5. Openings, numbers and change over time

- Never open a piece or a section with funding sources, market size or statistics.
- Dry facts and figures always go in a table, chart or other graphic beside the text, never in paragraphs
  (dates and counts by program, revenue by year, inspection totals, lists of names and years). A number that
  carries a sentence's point ("$8,000 a month") is not dry, and belongs in the sentence.
- A claim of change ("moved from public to private hands") needs its time frame and context in the same sentence.
  Without the "since when", it is bad writing.
- Keep it short. Few headings; the paragraphs carry the weight.

## 6. Pages about related industries

Foster care, adult disability group homes, nursing homes, psychiatric hospitals (`/related-industries/`):

- The point is never to explain that industry in general. It is how it connects to or overlaps with the troubled
  teen industry: the same owners, the same children passing through, the same methods, shared roots.
- General facts about the industry (its size, its own oversight gaps, its own drug statistics) go unless they show
  that connection.
- Psychiatric hospitals overlap the TTI (many RTCs and PRTFs sit on a hospital's campus); never call them flatly
  "not the TTI". Migrant children's shelters (ORR), juvenile detention and young adult programs are part of the
  industry, never "related industries". Indian boarding schools are institutional abuse history, outside it.

## 7. Sources and citations

- Rely on primary sources when they exist: the court filing over the article about it, the state inspection report
  over a summary of it, the program's own handbook, website or letter, tax returns and business filings, the
  survivor's own account. Read the primary source itself, quote and cite it; use secondary reporting for what
  only it holds, or to point to the primary source.
- KOP is a primary source. The owner's articles and records come from primary material (newspaper scans, records
  nobody else has put online). Never drop a fact because KOP is its only source, never call a KOP source
  "circular", never lower confidence because of it.
- Cite where a fact came from: a fact from an article, court record or state report cites that, never KOP in its
  place. Names, operators, owners, staff and years the owner researched are KOP's own record and can stand
  without a link to a KOP profile page.
- On facility and company pages, wiki entries and the app, a citation is the linked word "source":
  "([source](url))", several as "([source](a); [source](b))". Never write out "Woodbury Reports, October 2008
  (#170), p. 21" or "Woodbury Reports said ...".
- Articles and related-industry pages cite with numbered superscripts and a Sources list (`[^n]` in page text),
  not long inline phrase links.
- Anything read from the Wayback Machine or another archive is downloaded as a PDF into the media library and our
  copy is cited beside the archive link (CLAUDE.md, "Archive what you cite").
- A source found while writing that KOP doesn't hold yet is submitted to the review inbox (CLAUDE.md, "Sources
  found during research").

## 8. People and their roles

- No year unless it is a start, an end, or both: "from 2010 to 2012", "founded X in 1999". A year that is only
  when the citing source was written ("Headmaster of X in 2008") is dropped.
- "a" or "an" for roles many people hold (staff member, teacher, therapist, counselor, nurse); "the" only for
  one-holder titles (director, CEO, founder, headmaster, president).
- No "Former" and no abbreviations inside a role. A closed program's staff are written in the past tense.
- One paragraph per person.
- Someone who went through a program and now works in the industry is a professional and is named for that work.

## 9. Words to get right

- Restraint is restraint. Being zipped into a bivy sack or sleeping bag overnight, strapped down, bound in sheets
  or pinned is restraint. A physical cause of death (asphyxia, smothering) says how the restraint killed; it never
  replaces the word "restraint". Defer to the owner's knowledge of how programs operate.
- The AI-instruction copies programs bury in their sites are "hidden in the HTML code for its home page", never
  just "hidden in its home page".
- Links read as words, never as bare web addresses.
- No emojis, anywhere.
- American spelling.

## 10. Wiki entries and wiki update drafts

- Copy-edit everything: spelling, grammar, British spellings, including inside survivor, parent and staff quotes.
  Minimal edits only: keep the voice, slang, profanity and capitals. Don't guess at a garbled sentence; list it
  for the owner. Leave headlines, names, link targets and dates alone.
- Every news article goes under "In the Media". "Related Media" is only for websites about the program (its own
  site, archived home pages, survivor sites, and last, the entry's KOP facility page).
- Programs, companies and people link to their own r/troubledteens wiki pages, never to KOP profile pages.

## Checklist before handing a draft over

1. Can you state the piece's point in one sentence? Does every section's and paragraph's point serve it?
2. Does any paragraph list cases instead of moving the argument?
3. Is there a standalone history section, a funding or statistics opener, or a fact with nowhere to go?
4. Is every judgment right after the fact it rests on, and every allegation attributed?
5. Does any sentence explain the obvious or tell the reader what to think or do?
6. Does every claim of change say since when?
7. Are dry figures in a table, not prose?
8. Are recent cases leading, and the company-level pattern drawn?
9. Do the ending and the citations follow sections 3 and 7?
10. Does it read with urgency, as someone with a duty to protect the children involved?
11. Where a primary source exists, is it the one quoted and cited? Would a direct quote land a point harder than
    the paraphrase?
