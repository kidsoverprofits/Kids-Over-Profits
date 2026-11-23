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
# Job "Fact fold: same in other words" (inc/fact-fold.php, kop_fold_ai_judge(), the AI step of the hourly fold): background, not protected.
# At most KOP_FOLD_AI_CALLS_PER_RUN (10) calls an hourly run, each checked against the budget first (kop_fold_ai_budget_ok()); a spent
# budget or a provider's limit stops only the AI part (the rules go on) and the record waits on kop_fold_state.ai_todo. A full pass of the
# 11-02 mirror: about 160 calls, ~306k tokens, 16 hourly runs; afterwards only new or changed lines are asked (answers cached per line).
php -d extension=pdo_sqlite -d extension=mbstring -d memory_limit=2G scripts/fold-dry-run.php --ai-estimate     # calls, lines, tokens; no call
php -d extension=pdo_sqlite -d extension=mbstring -d memory_limit=2G scripts/fold-dry-run.php --ai --facility=N # real calls, one record, writes nothing
(kop-pipeline) python scripts/fornits-crawl.py                   # resumable, one request per 3 s
(kop-pipeline) python scripts/fornits-process.py [--no-upload] [--keep-crawling]   # --no-upload: dry run, marks nothing read
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-fornits.php   # every link + each kind on real docs, exact undo
# Local Qwen after both (api/local-ai.php, routes inc/local-ai.php): when Groq and Gemini both fail (out of quota, budget held),
# kop_ai_generate_alternating(), process-news-ai.php 'auto' and the text titles try the owner's Ollama through the PC worker's
# Cloudflare quick tunnel while it checks in (every 60 s, fresh 180 s); else the prompt is saved in uploads/kop-local-ai/jobs/
# (300 max, 7 days), the call still fails with the old errors (so "rate limit" deferrals work), and once the worker answers,
# the next identical prompt (news: same link) gets the saved answer without calling anyone. Usage rows say provider 'local'.
# The AI Usage status line says whether it is on and how many wait. Opt out per call with 'noLocal', site-wide KOP_LOCAL_AI=off.
(kop-pipeline) python scripts/local-ai-worker.py              # runs at login (Task Scheduler "KOP local AI worker", log
#   %LOCALAPPDATA%\kop-local-ai-worker.log); by hand: live + queue until Ctrl+C; own Ollama on the Intel graphics (:11436)
(kop-pipeline) python scripts/local-ai-worker.py --once       # answer what waits, then stop; --no-tunnel = queue only
php -d extension=mbstring scripts/test-local-ai.php           # KOP_LAI_LIVE=http://127.0.0.1:11435 KOP_LAI_TOKEN=... adds a live ask
# Speed on the Intel graphics (Core Ultra 9, full news article): qwen3.5:9b ~57 s (the worker's default: the biggest qwen),
# qwen3.5:4b ~43 s; on the processor alone ~2.5 min, too slow for the site's 75 s live wait, which is why the worker
# starts its own Ollama with OLLAMA_VULKAN=1 OLLAMA_IGPU_ENABLE=1. Longer answers miss the live wait and go to the queue.
# That Ollama can die mid-answer with nothing in its log (2026-11-23): the worker checks it each minute, starts a new one,
# and sends the site a stop instead of a heartbeat until it answers; the dead one's log is %TEMP%kop-local-ai-ollama.log.prev.
```
