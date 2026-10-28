# Advocacy History and Survivors hub: drafts for the owner (2026-10-28)

Plan item: docs/plan/4.7-pages-and-templates.md item 6 (spec: docs/FIX-PLAN-2026-09.md 18A and 18D).

Nothing here is on the live site. Both pages are published and were edited in wp-admin after their seeds were
written, so a seed would not apply, and any write to production waits for the owner. To publish:

- paste the block markup below into the code editor of each page
  (Advocacy History: https://kidsoverprofits.org/wp-admin/post.php?post=2102&action=edit ;
  For Survivors: https://kidsoverprofits.org/wp-admin/post.php?post=2082&action=edit), or
- say "ship it" and a session adds them as `post` entries in `seeds/text-fixes.json` (exact insertions, applied once
  on deploy, revision kept), and updates the `seeds/history/advocacy-history.html` copy.

Every fact below is from a source KOP already holds (media library, legislation records, KOP pages), except the
KSHB article on Hilton's Utah testimony, which was sent to the news review queue on 2026-10-28.

---

## 1. Advocacy History: Key milestones

Where: https://kidsoverprofits.org/advocacy-history/ , at the top, before "1950 to 1979".

Point of the section: every check on the industry listed here began with someone from inside a program going on the
record, and each time the industry carried on: under new names, or because Congress passed a study instead of rules.

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Key milestones</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Every check on this industry listed here began with someone who had been inside a program, or a parent whose child died in one, going on the record. Every time, the industry carried on. Straight's officials reopened under new names within weeks of its closing. Congress has still not passed the standards the House voted for in 2008.</p>
<!-- /wp:paragraph -->

<!-- wp:table {"className":"kop-milestones"} -->
<figure class="wp-block-table kop-milestones"><table><thead><tr><th>When</th><th>What happened</th><th>Source</th></tr></thead><tbody>
<tr><td>1973</td><td>Sixteen residents of Lester Roloff's Rebekah Home in Texas testified that they were "whipped with leather straps, beaten with paddles, handcuffed to drainpipes, and locked in isolation cells." On August 3, 1973, a Nueces County court ordered Roloff's homes to stop operating until they were licensed.</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/fountain-of-youth.pdf">source</a></td></tr>
<tr><td>1983</td><td>The Sarasota state attorney's office issued a 600-page report on Straight, Inc.-Sarasota: kidnapping, false imprisonment, children thrown against walls. The evidence came from Straight's own counselors, who were granted immunity for their testimony. Straight-Sarasota closed that year.</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/2025/09/CivilCasesAgainstStraight.pdf">source</a>; <a href="https://kidsoverprofits.org/wp-content/uploads/semblerreport.pdf">source</a></td></tr>
<tr><td>1993</td><td>Straight, Inc. closed its last program, in Atlanta, on July 1-2, 1993, after years of state investigations and lawsuits. Among them was a $720,000 judgment for Karen Norton, whom Miller Newton threw against a wall. The model outlived the company. Former Straight officials had already founded Pathway Family Center near Detroit on June 18 and incorporated the Phoenix Institute for Adolescents near Atlanta on June 21.</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/semblerreport.pdf">source</a></td></tr>
<tr><td>2002</td><td>Survivors started keeping the record themselves. The International Survivors Action Committee (ISAC) published "The Sembler Report" on Straight's backers, Mel and Betty Sembler, on December 9, 2002, and sent it to government agencies, police and the press. Its reports on Pathway Family Center and the WWASP programs followed in 2003 and 2004.</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/semblerreport.pdf">source</a></td></tr>
<tr><td>October 10, 2007</td><td>The Government Accountability Office (GAO) told the House Committee on Education and Labor it had found thousands of abuse allegations against programs between 1990 and 2007, and laid out ten deaths. Three parents testified about their children's deaths: Bob Bacon (Aaron Bacon), Paul Lewis (Ryan Lewis) and Cynthia Clark Harvey (Erica Harvey). The industry sent Jan Moss, executive director of its trade group, the National Association of Therapeutic Schools and Programs (NATSAP).</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/CHRG-110hhrg38055.pdf">source</a>; <a href="https://kidsoverprofits.org/gao-2007-report/">source</a></td></tr>
<tr><td>April 24, 2008</td><td>Two survivors testified to Congress: Jon Martin-Crawford (Family Foundation School) and Kathryn Whitehead (Mission Mountain School), of the survivor-led Community Alliance for the Ethical Treatment of Youth (CAFETY), which Whitehead founded.</td><td><a href="https://kidsoverprofits.org/wp-content/uploads/gao-hearing-04242008.pdf">source</a></td></tr>
<tr><td>June 25, 2008</td><td>The House passed the Stop Child Abuse in Residential Programs for Teens Act (110th Congress, H.R. 6358) 318 to 103. It would have set federal standards for every program, a national complaint hotline and civil penalties. It died in the Senate health committee. So did the 2009 version (H.R. 911), which passed the House 295 to 102. The 2011 version never reached a vote.</td><td><a href="https://kidsoverprofits.org/legislative-efforts/?jurisdiction=Federal#bill-49">source</a>; <a href="https://kidsoverprofits.org/legislative-efforts/?jurisdiction=Federal#bill-50">source</a></td></tr>
<tr><td>February 8, 2021</td><td>Paris Hilton, survivor Jeff Netto and Caroline Lorson of Breaking Code Silence testified for Utah's SB 127. "Children were restrained, hit, thrown into walls, strangled and sexually abused regularly at Provo," Hilton told the committee. It passed unanimously.</td><td><a href="https://www.kshb.com/news/national/paris-hilton-testifies-at-utah-state-legislature-on-troubled-teen-schools">source</a></td></tr>
<tr><td>March 22, 2021</td><td>SB 127 became law. Utah programs must report every restraint and seclusion within one business day. The state must inspect them every quarter, at least twice a year without notice. Pain-inducing techniques, peer restraint and forced exercise are banned.</td><td><a href="https://kidsoverprofits.org/legislative-efforts/?jurisdiction=Utah#bill-22">source</a></td></tr>
<tr><td>December 23, 2024</td><td>The Stop Institutional Child Abuse Act (118th Congress, S. 1351) became law, after passing the Senate unanimously and the House 373 to 33. It sets no standards. It orders a National Academies study of abuse, neglect and deaths in youth residential programs, which must consult people who lived in them. Sixteen years after the House voted for federal standards, Congress passed a study.</td><td><a href="https://kidsoverprofits.org/legislative-efforts/?jurisdiction=Federal#bill-48">source</a></td></tr>
</tbody></table></figure>
<!-- /wp:table -->
```

Sources behind each row (for checking):

| Row | Document | Where in it |
| --- | --- | --- |
| 1973 | Brown, *Fountain of Youth* (ASU dissertation, 2022), media library | p. 114 |
| 1983 | Fager, "Some Civil Suits and Criminal Cases Against Straight, Inc." (2002); ISAC, "The Sembler Report" (2002) | Straight-Sarasota row; Sembler report, Sarasota "Closed July 19, 1983" |
| 1993 | ISAC, "The Sembler Report" | p. 18, "1993 - Present" |
| 2002 | ISAC, "The Sembler Report"; the ISAC reports already cited on the page | cover page |
| Oct 2007 | Hearing transcript, Serial No. 110-68 (media library) | contents, witness list |
| Apr 2008 | Hearing transcript, Serial No. 110-89 (media library, scanned) | contents, witness list |
| Jun 2008 | KOP legislation records 49 (H.R. 6358), 50 (H.R. 911), 51 (H.R. 3126) | last action |
| Feb 2021 | KSHB / Scripps, Feb 8, 2021 (sent to news review 2026-10-28); Deseret News week recap, Feb 13, 2021 (held) | |
| Mar 2021 | KOP legislation record 22 (Utah SB 127) | summary, last action |
| Dec 2024 | KOP legislation record 48 (S. 1351) | summary, last action |

## 2. Advocacy History: Submit your story

Where: the foot of https://kidsoverprofits.org/advocacy-history/ , above "Sources".

```html
<!-- wp:heading -->
<h2 class="wp-block-heading">Submit your story</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Every entry on this page is here because someone who was inside put it on the record. Yours belongs here too.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://kidsoverprofits.org/anon-submit/">Send it privately</a>: a written account, letters or papers from your program. Files and notes are encrypted the moment they reach our server, and only the site owner holds the key.</li>
<li><a href="https://kidsoverprofits.org/send/">Send us the link</a> if you have already told it somewhere: a blog post, video, podcast episode, news story or court record. Nothing you send is published until we have reviewed it.</li>
<li><a href="https://kidsoverprofits.org/tti-data-submission/">Add to your program's record</a>: who ran it, who worked there, and what was done to you.</li>
<li>Or write to <a href="mailto:dani@kidsoverprofits.org">dani@kidsoverprofits.org</a>.</li>
</ul>
<!-- /wp:list -->
```

## 3. For Survivors hub: thesis line and link descriptions

Where: https://kidsoverprofits.org/survivors/ . The thesis goes above the existing intro ("Surviving the TTI was
hard enough. Now what?"), which stays as the owner wrote it. The link list replaces the "TTI Survivors: Common
Experiences" link card and the one-line "Resources" paragraph. The card prints the same article the hub's own
"Written for survivors" list prints just below it, so the page currently shows it twice.

```html
<!-- wp:paragraph {"className":"kop-hub-thesis"} -->
<p class="kop-hub-thesis"><strong>What a program did to you was abuse, not treatment.</strong> Survivors who said so in court, to Congress and online are why programs have been sued and closed, and why Utah has inspected them without notice since 2021.</p>
<!-- /wp:paragraph -->

<!-- (existing intro paragraph stays here) -->

<!-- wp:paragraph -->
<p>Each page below stands on its own. Start wherever you need to.</p>
<!-- /wp:paragraph -->

<!-- wp:list {"className":"kop-hub-links"} -->
<ul class="wp-block-list kop-hub-links">
<li><a href="https://kidsoverprofits.org/resources/">Find support</a><br>Support groups run by survivors, among them Survivors Unrestrained's Zoom meetings on Tuesdays and Thursdays; the advocacy groups; and the films, memoirs and accounts survivors have made.</li>
<li><a href="https://kidsoverprofits.org/wp-content/uploads/2024/08/Survivors-Guide-to-Legal-Action-Against-Troubled-Teen-Industry-Programs.pdf">A survivor's guide to legal action</a><br>From Unsilenced's justice support team: civil suits and criminal charges, what to ask a lawyer, and how a statute of limitations can end a case before it starts.</li>
<li><a href="https://kidsoverprofits.org/report-abuse/">Where to report abuse</a><br>The agencies that take reports about a program or a therapist, state by state.</li>
<li><a href="https://kidsoverprofits.org/lawsuits/">Lawsuits</a><br>The cases survivors and families have brought against programs and their staff, and where each one stands.</li>
<li><a href="https://kidsoverprofits.org/advocacy-history/">Survivors and Families Fight Back</a><br>Fifty years of survivors testifying, suing and publishing, from the Rebekah Home hearing in 1973 to the Stop Institutional Child Abuse Act in 2024.</li>
<li><a href="https://kidsoverprofits.org/in-loving-memory/">In loving memory</a><br>The young people who died in troubled teen programs, deaths the programs could have prevented.</li>
<li><a href="https://kidsoverprofits.org/send/">Send us a link</a><br>Your own post, video or podcast about a program, or a news story or court record we don't hold yet.</li>
</ul>
<!-- /wp:list -->
```

Not page text, for a later session if the owner wants them: the three buttons at the top of the hub (Find support,
Guide to legal action, Where to report abuse) take no notes; the reading list's notes and the "Share what you know"
links are set in `kop_hub_config()` (inc/hub-shell.php). Icons and colours per category (18D) are CSS.

## 4. Errors found in the existing Advocacy History timeline (owner decides)

Found while checking the milestones against the sources. Not changed.

1. **"October 10, 2007 – Congress holds the first of two hearings ... Survivors Jon Martin Crawford (Family
   Foundation School) and Kathryn Whitehead (Mission Mountain School) testify."** The October 10, 2007 witnesses
   were GAO's Greg Kutz, the parents Bob Bacon, Paul Lewis and Cynthia Clark Harvey, NATSAP's Jan Moss and Allison
   Pinto (transcript, Serial No. 110-68). Martin-Crawford and Whitehead testified on **April 24, 2008** (Serial
   No. 110-89). The 2008 row ("Grieving parents testified about the deaths of their children") belongs to 2007.
   The two rows' details should swap.
2. **"June 2008 – H.R. 5876 ... Despite bipartisan concern, legislation failed to pass."** The bill (reported as
   H.R. 6358) passed the House 318-103 on June 25, 2008, and again as H.R. 911 (295-102) on February 23, 2009;
   both died in the Senate. "Failed to pass" should say "passed the House twice and died in the Senate".
3. **Straight-Sarasota closing date.** The page dates the state attorney's report September 1983; ISAC's Sembler
   report gives Straight-Sarasota's closing as July 19, 1983, after the report. One of the two dates is wrong;
   the draft above says only "1983".
4. **/sicaa/ page** (https://kidsoverprofits.org/sicaa/): describes a nine-member "Federal Work Group on Youth
   Residential Programs". KOP's legislation record for the law as signed (S. 1351, P.L. 118-194) describes a
   National Academies study instead. The page may describe an earlier version of the bill.
