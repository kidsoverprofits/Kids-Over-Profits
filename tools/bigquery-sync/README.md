# BigQuery sync

A read-only copy of the live KOP MySQL database in Google BigQuery, for the claude.ai BigQuery connector
(it can't reach kidsoverprofits.org or MySQL). `bigquery-sync.php` runs on the NixiHost server from cron every
night, exports each table to gzipped NDJSON and loads it into `kop-data.kop_prod.<table>` with `WRITE_TRUNCATE`.
It needs no libraries, just PHP 8.2 with PDO, curl and openssl.

| What | Where |
| --- | --- |
| GCP project / dataset | `kop-data` / `kop_prod` (location US), owned by kidsoverprofitsdani@gmail.com |
| Script on the server | `/home/kidsover/bigquery-sync/bigquery-sync.php` (shipped by `.cpanel.yml`, outside `public_html`) |
| Service account | `kop-bigquery-sync@kop-data.iam.gserviceaccount.com`: BigQuery Data Editor on `kop_prod`, Job User on the project |
| Key | `/home/kidsover/.kop-bigquery/sa-key.json` (chmod 600, outside `public_html`, never committed) |
| Cron | `0 2 * * *` server time (America/Chicago), i.e. 03:00 ET, with `flock`; log `/home/kidsover/logs/bigquery-sync.log`; a failure emails the crontab MAILTO |
| Run log | table `kop_prod._sync_log`: one row per table per run (run time, MySQL rows, BigQuery rows, status, error) |

```bash
php bigquery-sync.php --list          # what syncs, what's excluded, which columns drop (no Google calls)
php bigquery-sync.php                 # full sync
php bigquery-sync.php --tables=a,b    # just these tables
php bigquery-sync.php --verify        # BigQuery row counts vs MySQL COUNT(*) for every synced table
```

## What syncs

Every base table not on the deny list, so new tables are picked up automatically. The export reads one consistent
snapshot. A full run deletes BigQuery tables that no longer sync, either because they were newly excluded or
because they were dropped in MySQL.

Types: integers become INT64, decimals NUMERIC, float/double FLOAT64, date DATE, datetime DATETIME, timestamp TIMESTAMP
(the session runs in UTC) and blobs BYTES. Everything else (varchar, text, enum, JSON, time) becomes STRING. Zero dates
(`0000-00-00`) become NULL. JSON columns such as `json_data` are strings: query them with `JSON_VALUE(json_data, '$.x')`.

## No billing = sandbox expiry

`kop-data` has no billing account, so BigQuery runs it as a sandbox. Every table expires 60 days after it was
created, that limit can't be extended, and WRITE_TRUNCATE keeps the creation time. When a table has under 30 days
left, the sync deletes it just before that night's load, which leaves a few seconds with the table missing about once
a month at 3 AM. `_sync_log` is rebuilt in place with `CREATE OR REPLACE TABLE ... AS SELECT *` so its history is
kept. Linking a billing account removes the expiry. The data is far inside the free tier (10 GB storage, 1 TB of
queries a month), so billing should cost nothing.

## What's excluded

The authoritative lists are the constants at the top of `bigquery-sync.php`.

- **Whole tables**, credentials and secrets: `wpdl_users`, `wpdl_usermeta`, `wpdl_options`, `wpdl_wf*` (Wordfence),
  `wpdl_dlm_*`, `wpdl_integrate_google_drive_*`, `wpdl_zotpress*`, `wpdl_snippets`, `wpdl_anonymous_submissions`.
- **Whole tables**, plugin internals with no KOP data: `wpdl_actionscheduler_*`, `wpdl_litespeed_*`, `wpdl_asp_*`,
  `wpdl_imunify_*`, `wpdl_yoast_*`, `wpdl_mclean_*`, `wpdl_wpforms*`, `wpdl_gutenaforms*`, `wpdl_mailerlite_forms`,
  `wpdl_pdfposter_presets`, `wpdl_wpfm_backup`, `wpdl_e_events`, `wpdl_download_log`, `wpdl_links`.
- **Columns**: `wpdl_posts.post_password`, `wpdl_kop_volunteers.token_hash`, `wpdl_kop_researcher_links.token_hash`,
  `wpdl_kop_submission_followups.token`, `suggested_edits.submitter_ip`, `wpdl_comments.comment_author_IP`. Any column
  in any table whose name looks like a password, token, secret, API key, private key, salt, activation key or
  `encrypted_*` is also dropped.
- **Rows**: in `wpdl_postmeta`, `wpdl_termmeta` and `wpdl_commentmeta`, rows whose `meta_key` looks like a secret are
  skipped.

To exclude something more, add it to the lists and push. The next full run deletes the BigQuery copy.

## Who can read it

The connector account (kidsoverprofitsdani@gmail.com) has BigQuery Data Viewer on `kop_prod` and Job User on the
project. It also owns the project, so ownership gives it more than read access. The connector's read-only tool is
what keeps queries to SELECT.

## Rotating the key

```bash
gcloud config set project kop-data
SA=kop-bigquery-sync@kop-data.iam.gserviceaccount.com
gcloud iam service-accounts keys create new-key.json --iam-account=$SA
scp -P 1157 -i ~/.ssh/kop_nixihost new-key.json kidsover@dfw-s07.nixihost.com:.kop-bigquery/sa-key.json
ssh -p 1157 -i ~/.ssh/kop_nixihost kidsover@dfw-s07.nixihost.com 'chmod 600 ~/.kop-bigquery/sa-key.json && /opt/cpanel/ea-php82/root/usr/bin/php ~/bigquery-sync/bigquery-sync.php --verify | tail -1'
rm new-key.json                                   # never leave a key on the laptop
gcloud iam service-accounts keys list --iam-account=$SA                  # find the old key id
gcloud iam service-accounts keys delete <OLD_KEY_ID> --iam-account=$SA
```

`--verify` fetches a token with the new key, so if it prints a result the new key works. Delete the old key only after that.
