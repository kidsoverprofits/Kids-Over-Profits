# Lists uncommitted and untracked files in the KOP checkouts, with how long each
# has sat there. Read-only: it never stages, stashes, cleans or deletes anything
# (the checkouts are shared with other sessions working at the same time).
#
#   powershell -File scripts\tree-check.ps1            # all four repos
#   powershell -File scripts\tree-check.ps1 -Hours 6   # flag anything older than 6 hours
#   powershell -File scripts\tree-check.ps1 -Quiet     # print nothing when every tree is clean
#
# Files older than -Hours (default 24) are marked FORGOTTEN: nobody is still
# working on them, so commit them, move them to tmp/ or the scratchpad, or ignore them.

#   ... -Hook SessionStart                # Claude Code hook: JSON for the user and the session, nothing when clean
#   ... -Hook Stop                        # Claude Code hook: only FORGOTTEN files, nothing otherwise

param(
    [int]$Hours = 24,
    [switch]$Quiet,
    [ValidateSet('', 'SessionStart', 'Stop')]
    [string]$Hook = ''
)

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$repos = @('Kids-Over-Profits', 'kop-pipeline', 'kop-data', 'kop-send-to-kop')
$now = Get-Date
$dirtyCount = 0
$forgottenCount = 0
$lines = @()
$forgotten = @()

foreach ($name in $repos) {
    $path = Join-Path $root $name
    if (-not (Test-Path (Join-Path $path '.git'))) { continue }
    $status = git -C $path status --porcelain=v1 --untracked-files=all
    if (-not $status) { continue }
    $lines += "== $name"
    foreach ($row in $status) {
        $code = $row.Substring(0, 2)
        $file = $row.Substring(3).Trim('"')
        if ($file -match ' -> ') { $file = ($file -split ' -> ')[1] }
        $full = Join-Path $path $file
        $age = ''
        $flag = ''
        if (Test-Path -LiteralPath $full) {
            $span = $now - (Get-Item -LiteralPath $full).LastWriteTime
            if ($span.TotalHours -ge 48) { $age = '{0}d' -f [int]$span.TotalDays }
            elseif ($span.TotalHours -ge 1) { $age = '{0}h' -f [int]$span.TotalHours }
            else { $age = '{0}m' -f [int]$span.TotalMinutes }
            if ($span.TotalHours -ge $Hours) { $flag = '  FORGOTTEN'; $forgottenCount++ }
        }
        $dirtyCount++
        $entry = ('  {0} {1,5}  {2}{3}' -f $code, $age, $file, $flag)
        $lines += $entry
        if ($flag) { $forgotten += "$name/$file ($age)" }
    }
}

if ($Hook) {
    if ($Hook -eq 'Stop') {
        if ($forgottenCount -eq 0) { exit 0 }
        $msg = "Uncommitted for over ${Hours}h: " + ($forgotten -join ', ') + '. Commit, move to tmp/, or ignore them (scripts\tree-check.ps1).'
        $out = @{ systemMessage = $msg }
    } else {
        if ($dirtyCount -eq 0) { exit 0 }
        $text = "Working trees not clean at session start:`n" + ($lines -join "`n") + "`n$dirtyCount uncommitted file(s), $forgottenCount older than ${Hours}h."
        $out = @{
            systemMessage = $text
            hookSpecificOutput = @{
                hookEventName = 'SessionStart'
                additionalContext = $text + "`nThese may belong to another session still working; do not stage, revert or delete them unless they are yours or the owner says so. Mention FORGOTTEN ones to the owner."
            }
        }
    }
    Write-Output ($out | ConvertTo-Json -Depth 4 -Compress)
    exit 0
}

if ($dirtyCount -eq 0) {
    if (-not $Quiet) { Write-Output 'All KOP working trees are clean.' }
    exit 0
}

$lines | ForEach-Object { Write-Output $_ }
Write-Output ''
Write-Output ("{0} uncommitted file(s), {1} older than {2}h." -f $dirtyCount, $forgottenCount, $Hours)
exit 0
