# AI usage and budget

Commands, tests and rules for the AI Usage screen and the daily AI budget. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# KOP Tools > AI Usage (inc/ai-usage.php): every Groq/Gemini request the site makes, logged by the job that made it (api/ai-usage.php,
# uploads/kop-ai-usage/<day>.tsv, 14 days; kop_ai_generate(), processNewsWith() and kop_rtc_alternate() write it; a job is named by its
# file in kop_ai_usage_jobs(), or by X-KOP-Job on process-news-ai.php), 48 hourly bars, "Check now" (one request per provider, keys never shown).
# Year-by-year timelines on both page kinds (inc/profile-timeline.php, css/js profile-timeline): map deals/takeovers, programs, lawsuits, deaths,
# findings, press; researched company milestones (seeds/company-milestones.json) show only once approved at Review inbox > Company milestones
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=company-milestones
# Daily budget (kop_ai_budget_check()): background jobs stop at KOP_AI_BACKGROUND_SHARE (0.7) of KOP_AI_DAILY_GEMINI / _GROQ (500 / 400) per day
# from midnight Pacific, throwing a plain Exception that says "rate limit"; jobs in kop_ai_budget_protected_jobs() (discovery, by hand) never wait
php -d extension=mbstring scripts/test-ai-usage.php
(kop-pipeline) python scripts/fornits-crawl.py                   # resumable, one request per 3 s
(kop-pipeline) python scripts/fornits-process.py [--no-upload] [--keep-crawling]   # --no-upload: dry run, marks nothing read
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-fornits.php   # every link + each kind on real docs, exact undo
```
