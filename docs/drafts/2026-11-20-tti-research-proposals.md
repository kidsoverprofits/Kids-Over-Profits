# Proposed record changes from the overnight research-tasks run (2026-11-20)

Nothing here has been applied. Each line is a proposed change to site data, for the owner to approve or drop.
The sources are cited in the matching task's answer in `seeds/research-tasks.json` (KOP Tools > Research Tasks).
Merges and deletions are the owner's call and are listed as questions only.

## Held confirmations from the Fixes doc

1. **OBH Council old name.** No source states a rename. "Industry Council" is in use through Sept 2007; "Outdoor
   Behavioral Healthcare Council" appears by 2013 (AEE manual) and Dec 2014 (Counseling Today); the IRS name and the
   CarePaths outcomes portal still say "Industry". The current fill in `seeds/tradeorgs.json` makes no rename claim, so
   it can stay. Proposed added sentence: "It dropped 'Industry' from its public name between 2007 and about 2013; its
   IRS name never changed." Don't write "renamed in [year]".
2. **Three Points Center (10545).** Neither Olympus nor Diamond Ranch Academy was renamed Three Points. Three Points was
   a new program (Woodbury, Sept 11, 2014; Thibault family), first at 150 N State St, La Verkin (the old Cross Creek /
   Youth Foundation Success Academy campus), and at the Hurricane campus only from 2018. Proposed:
   - Undo the merge that cuts Diamond Ranch Academy (1999-2023) into 10545 (owner's call: it is a merge).
   - Olympus as a past name of 10545 only for the Hurricane campus from 2018, not 2013-2014.
   - Youth Foundation Success Academy (10592) ends 2013, not 2014; Three Points starts 2014.
   - Check the Woodbury pages from 2012-2013 filed under "Three Points Center": they predate the program.
3. **Three Points Center NC (11939).** Supported as a campus succession: Auldern Academy closed 2020-21, Three Points
   Center North Carolina got an NPI at Auldern's address on Nov 24, 2021, and is a debtor in the Three Points Chapter 7
   (D. Colo. 25-11440-JGR). Proposed:
   - Keep 11939 separate from Utah 10545; year of the Auldern -> Three Points NC step about 2021.
   - The map's Auldern (9637) -> "Sequel TSI North Carolina" line looks wrong: Sequel TSI of Auldern, LLC was Auldern's
     own operating company.
   - 11939's closure note should cite the bankruptcy sale, not KUTV's Utah story.
   - Second look at news #426, re-pointed from 11939 to 10545 on 2026-11-17.
4. **Three Points Academy Inc (EIN 47-3006182).** Very likely Three Points' school entity: Glenn Thibault (President
   2015-2020) and Norman Thibault (2021-2023) are its officers, and it was exempted in June 2015. Proposed: add it as
   Three Points Center's school entity, citing ProPublica. Not read: the 990 PDFs themselves.
5. **Laurel Oaks (Dothan).** Open today (own site, current in 2026, UHS-linked). Ramsay opened its Dothan facility in
   April 1998; PSI's 2003 10-K lists Laurel Oaks, Dothan, among the facilities bought with Ramsay in June 2003.
   Proposed: start year 1998 (not 2004); Ramsay Youth Services as a past operator, not a past name. No source names a
   "Laurel Hills" in Dothan.
6. **Journey Home (9672).** Andrea Jones and Donovan Tagaloa belong to Family Help & Wellness's Utah Journey Home Young
   Adult (Salt Lake City), not JRI's Journey Home in Attleboro. Proposed: move them to a Utah Journey Home record; leave
   Emily Smith off until sourced (KOP's notes put her at Journey Home East, also FHW); Justin Lubecki's tie to 9672 has
   no source. Caveat: the only page naming them is a pages.dev copy of the program's site.

## Trade groups and marketers

7. **NATC.** No source uses "National Association of Therapeutic Camping". The record looks like a mislabel of NATWC
   (renamed 2006, last seen Aug 2010 in Davis, WV; natwc.org has lapsed). Proposed: title the record NATWC and note
   that no later activity was found.
8. **AMATS.** The 2023 site (amats-association.squarespace.com) lists 7 current members under a "Founding members" menu,
   3 of them on the 2007 list, with a McDonough, GA contact. No source says it was refounded. Proposed: add the 2023
   site and member list; don't call it refounded.
9. **Help My Teen.** Wood v. WWASPS complaint, Exhibit 101, names Lisa Irvin as intake supervisor; it was paid per
   enrollment. Proposed: add the WWASPS tie and the Wood case. Owner still unknown.
10. **Lifelines / Teen Life Lines.** A Utah corporation d/b/a Teen Life Lines; Jane Hawley named. Proposed: add the
    Dungan lawsuit outcome (settled and dismissed Aug 26, 2010). Founder still unknown.

## Sequel and Vivant staff (titles for profiles)

11. Christine Aron: VP Business Development (joined Sequel 2014, VP from 2016; SEC exhibit); member of Vivant Behavioral
    Holdings in Apr 2022. Fix: `seeds/operator-histories.json` should cite the SEC exhibit, not the Notice of Removal,
    for the VP-from-2016 fact.
12. Jacob Brantz: Vivant CFO (own affidavit, Apr 20, 2022). The Drive people_jobs sheet has his roles reversed. The
    Sequel controller role is unsourced.
13. Jason Cagle: Sequel EVP, Chief Administrative Officer and CFO from June 2019 (PR Newswire). Vivant role unsourced.
14. Elizabeth Carey: Starr Commonwealth President and CEO from June 1, 2015. She was not a Sequel employee; the
    Starr-Sequel partnership ran 2015 to July 2020.
15. Jeff Andrews: "EVP, Chief Operating Officer & Interim President for Sequel" (Oct 2021 release), years unknown.
16. Roy Day: Sequel VP Human Resources, at least 2016 (arbitration award, Utah Civil No. 200500204).
17. Tony Harper: Three Springs VP HR (2008); later Sequel Management Services VP HR and Administrative Services, years
    unknown; Parkridge Valley assistant administrator from 2017.
18. Michael Henderson: CEO of Torii Behavioral Health (renamed Sequel Pomegranate), Sept 2021; Executive Director, Casa
    Grande Academy (Vivant), by 2025.
19. Sharon Laney: Paint Rock Valley Program Administrator (Three Springs, 2009); VP Operations, Private Division, Sequel
    TSI (2010). NATSAP VP 2005-2006 and President 2007-2008 (the Rosemary Tippett draft says 2006 and 2007).
20. Samantha Lee: Sequel Chief Compliance and Ethics Officer, June 2019 to at least Dec 2020; earlier CCO at South
    Dakota Human Services Center. The Vivant title is unsourced.
21. Steve Gilbert: SVP Business Development (2007); EVP Customer Development (about 2016-2019). "EVP and Chief
    Administrative Officer" is unsourced (that title was Stupak's, then Cagle's); drop or mark unverified.
22. Cindy Cox: co-founder (1999) is settled; "assistant director in the early days" (APM) most likely means Clarinda
    Academy in the early 1990s. Record it as APM's words, with no years.
23. Jacqueline (Jacque) Gabler: testified for Sequel at the 2019 arbitration; no title found.
24. Ryan Santi: Woodward Academy executive director 2013-2016, then Sequel VP of Operations.
25. Matt Sparling: Vivant SVP Strategy and Development. Brian Steinberg: Vivant President of Operations, Autism
    Division. Susanne Young: Vivant VP Risk Management. Mandy Moses: Woodward executive director 2001-2008, Sequel
    Chief Program Officer by 2015.
26. Chris Perkins: Swan Valley director under Cornerstone, then Maryland DJS (resigned about Jan 2008). No Sequel tie
    found; the "Sequel VP" card may be a different person.

Still open with nothing found: Lynn Askew, Richard Block, Laura Brand-Bauer, Jack Rachko, Beverly Richard, Kenny
Roberts, Beverly Turner, Kellie Miller (possible duplicate of Kim Miller), Rebecca Oatts, Tommy Patey, Kendall Payne,
Rod Peterson, Paula Pickens. Evoke Cascades: no federal award but the 2020 PPP loan.
