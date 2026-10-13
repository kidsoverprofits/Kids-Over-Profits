# cPanel Git Deployment Guide

## CRITICAL RULES

### 1. Unix Line Endings Required
The `.cpanel.yml` file **MUST** have Unix line endings (`LF` not `CRLF`).

**Already configured:** `.gitattributes` forces Unix line endings:
```
.cpanel.yml text eol=lf
```

**Never remove this line** or deployment will break.

### 2. Never Copy .git Directory
**NEVER** use wildcards like `cp -R *` or `cp -R .` in `.cpanel.yml`.

❌ **WRONG:**
```yaml
- /bin/cp -R * $DEPLOYPATH
- /bin/cp -R . $DEPLOYPATH
```

✅ **CORRECT:** Only copy specific directories
```yaml
- /bin/cp -R api $DEPLOYPATH
- /bin/cp -R inc $DEPLOYPATH
```

### 3. Working .cpanel.yml Format
The file in this repository is the working one: a list of `/bin/cp` tasks
into `/home/kidsover/public_html/wp-content/themes/child/`, `inc/` copied
last, and an `rm -f` line for every file that left the repository (a deploy
never deletes on its own). Only the scripts the server runs are copied from
`scripts/`; `docs/` is not deployed.

### 4. Two repositories deploy into the theme folder
Since 2026-10-13 the datasets under `js/data/` are the `kop-data` repository.
It has its own `.cpanel.yml` (copies everything into the theme's `js/data/`)
and its own copy of `deploy.yml`, run from a second cPanel repository:

1. cPanel > Git Version Control > Create: clone URL
   `https://github.com/kidsoverprofits/kop-data.git`, path
   `/home/kidsover/repositories/kop-data`.
2. In the `kop-data` GitHub repository, secrets `CPANEL_HOST`, `CPANEL_USER`,
   `CPANEL_TOKEN` as for the theme and `CPANEL_REPO_ROOT` =
   `/home/kidsover/repositories/kop-data`.

The theme's deploy ships `js/data/pages/` and `js/data/glossary/` only, so the
two never overwrite each other's files.

## Deployment Workflow

1. **Make changes locally**
2. **Commit and push to GitHub:**
   ```bash
   git add .
   git commit -m "Your message"
   git push origin main
   ```
3. **In cPanel Git Version Control:**
   - Click "Manage" on Kids-Over-Profits repository
   - Click "Pull or Deploy" tab
   - Click "Update from Remote" (pulls from GitHub)
   - Click "Deploy HEAD Commit" (runs .cpanel.yml)

## Troubleshooting

### "The system cannot deploy" Error
**Cause:** Working tree is not clean or .cpanel.yml is invalid

**Fix:**
1. Check for uncommitted changes in GitHub Desktop
2. Commit and push all changes
3. In cPanel, click "Update from Remote" before deploying

### "fatal: not a git repository" Error
**Cause:** Repository corrupted (usually from manual file deletion)

**Fix:**
1. cPanel → Git Version Control
2. Delete the broken repository
3. Click "Create" and re-clone from GitHub
4. URL: `https://github.com/kidsoverprofits/Kids-Over-Profits.git`
5. Path: `/home/kidsover/repositories/Kids-Over-Profits`

Over SSH the same thing is `cd /home/kidsover/repositories/Kids-Over-Profits
&& git fetch origin && git reset --hard origin/main`, which is also how the
checkout was moved onto the rewritten history of 2026-10-13 (a pull cannot
fast-forward across a rewrite).

### .git Folder Copied to Live Site
**Cause:** Wildcard in .cpanel.yml copied everything

**Fix:**
1. Use the PHP cleanup script or cPanel Terminal:
   ```bash
   rm -rf /home/kidsover/public_html/wp-content/themes/child/.git
   ```
2. Fix .cpanel.yml to only copy specific directories
3. Redeploy

### Deploy Button Grayed Out
**Causes:**
- .cpanel.yml has Windows line endings (CRLF instead of LF)
- Invalid YAML syntax
- Repository not pulled from remote

**Fix:**
1. Verify .gitattributes exists with: `.cpanel.yml text eol=lf`
2. Click "Update from Remote" first
3. Check .cpanel.yml syntax matches the working format above

## Adding New Directories

When you add a new directory that should be deployed:

1. Edit `.cpanel.yml`
2. Add the directory copy command:
   ```yaml
   - /bin/cp -R newdirectory $DEPLOYPATH
   ```
3. Commit and push
4. Pull and deploy in cPanel

## Reference

- **Official docs:** https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/
- **Working commit:** `728b3114` (Dec 28, 2025 - "Rollback .cpanel.yml to stable version")
- **Git history:** `git log --oneline -- .cpanel.yml` to see all changes

## Emergency Contacts

If deployment completely breaks:
1. Check git history for last working version: `git show 728b3114:.cpanel.yml`
2. Restore it: Copy that content to .cpanel.yml
3. Commit and push
4. Re-clone repository in cPanel if needed
