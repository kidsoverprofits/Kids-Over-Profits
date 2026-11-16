## 6. Later, or not without the owner's word

- **Network map**: chain hulls and group-by-network (2d.4); "Suggest a correction" from the map (Phase 3); new person
  nodes for relationships to people not on the map.
- **Investors**: a data form tab, a public page, site map, open data, people ids for partners, filing cards on an
  investor.
- **Discovery**: grouping short names by state; a weekly GDELT pass and Bing News (declined 2026-10-13).
- **App**: other outlets' coverage of a story, paging through every inspection report, an offline index, notifications.
- **Directory Editor**: reordering programs within a card; editing the location tab's place groups.
- **Sensitivity readers**: once there are drafts, a way to send one to the readers who ticked "Ask me to read drafts later"
  (KOP Tools > Sensitivity Survey has their emails) and collect their notes; email only until then.
- **Lighter pages**: Provo's staff list and the timeline's later years, if pages are still heavy.
- **Unchecked**: no real upload through the anonymous portal since it began sealing files; no live call to the
  Claude, Gemini or Hugging Face models in the AI helpers.
- **Drive Sync schedule** (owner: Not now, 2026-10-25): keep the nightly `15 3 * * *`, or `*/15 * * * *` for
  documents within 15 minutes (was 2.1).
- **Featured report notes** (owner: Not now, 2026-10-25): no inspection report is featured any more; feature the two
  UHS of Provo Canyon reports again with a `featured_note` in
  [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php),
  or drop it (was 2.1).

- **California backfill** (the owner's call): 30,885 reports: sync the mirror, then `py -3.14 ca_scraper.py
  --backfill-pdfs`. Fixed 2026-10-20 in kop-pipeline `c228130`: CCL's end-of-list answer (HTTP 400 "Index out of
  range") had made every facility count as unfinished, so each run started over. CCL now takes ~1 minute a report
  page, so the whole walk is closer to 3 weeks than 3-5 days; it resumes, so it can run in evening stretches.
  Owner, Plan Board 2026-11-10: "Not this month" (was 2.2). Ask again in December.
- **Colorado is blocked** (2026-10-20). The dashboard's View Data command (`get-view-data-dialog-tab-pres-model`)
  never answers, though every other call answers in under 2 s; it is the only way to the lists and the citation
  text. See `plans/colorado.md` Status. Next: one View Data try in real Chrome on an evening, or a Colorado Open
  Records Act request to CDPHE for the inspection and citation data.
  Owner, Plan Board 2026-11-10: "Drop Colorado for now" (was 2.2).
- **CO: the 9 unsure facilities**: West Pines, Sierra Vista, Johnstown Heights, West Springs, three Eating Recovery
  Center licences, HCA Aurora, M.I.K.I.D. (PLAN-ARCHIVE, Colorado). Moot while Colorado is blocked (2.2).
  Moved with Colorado (was 2.4).
- **OR: a records request for complaint investigations and licensing actions.**
  Owner, Plan Board 2026-11-10: Not now (was 2.4). A session drafts the request on the word.

### Unconfirmed settings

- **Data model and notify settings**: which mode production's `kop_data_model` / `kop_data_model_areas` options use for the `program_index` read area,
  and the value of `kop_submission_notify_emails` (no admin screen; default `admin_email`).
