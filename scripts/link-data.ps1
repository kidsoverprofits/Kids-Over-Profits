# Make the sibling checkouts reachable where this theme expects them.
#
# Since the 2026-10 split, js/data/ (except pages/ and glossary/) is the
# kop-data repository and the research scratch (tmp/, with the production
# mirror tmp/prod.sqlite) belongs to kop-pipeline. The offline tests here
# read both by their old paths, so this script makes directory junctions:
#
#   js/data/<folder>  ->  ..\kop-data\<folder>      one per entry of kop-data
#   tmp               ->  ..\kop-pipeline\tmp       unless tmp/ already exists here
#
# Run once after cloning (PowerShell, no admin rights needed for junctions):
#   .\scripts\link-data.ps1 [-Data <path to kop-data>] [-Pipeline <path to kop-pipeline>]
# Junctions are listed in .gitignore, so git never sees the linked files.
param(
    [string]$Data = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'kop-data'),
    [string]$Pipeline = (Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'kop-pipeline')
)
$ErrorActionPreference = 'Stop'
$theme = Split-Path -Parent $PSScriptRoot
$dataDir = Join-Path $theme 'js\data'

if (-not (Test-Path (Join-Path $Data 'network'))) {
    throw "kop-data not found at $Data (clone it next to this repo, or pass -Data)"
}
New-Item -ItemType Directory -Force $dataDir | Out-Null

$made = 0
foreach ($entry in Get-ChildItem $Data -Directory | Where-Object { $_.Name -notin @('.git', '.github', 'pages', 'glossary') }) {
    $link = Join-Path $dataDir $entry.Name
    if (Test-Path $link) {
        $item = Get-Item $link -Force
        if ($item.LinkType -eq 'Junction') { continue }
        Write-Warning "$link exists and is a real folder; leaving it alone"
        continue
    }
    New-Item -ItemType Junction -Path $link -Target $entry.FullName | Out-Null
    $made++
}
# Files at the top of kop-data (the *.json the site reads) are hard-linked
# so the theme's readers see them at js/data/<name>.
foreach ($file in Get-ChildItem $Data -File | Where-Object { $_.Extension -eq '.json' }) {
    $link = Join-Path $dataDir $file.Name
    if (Test-Path $link) { continue }
    New-Item -ItemType HardLink -Path $link -Target $file.FullName | Out-Null
    $made++
}

$tmp = Join-Path $theme 'tmp'
if (-not (Test-Path $tmp)) {
    if (Test-Path $Pipeline) {
        New-Item -ItemType Directory -Force (Join-Path $Pipeline 'tmp') | Out-Null
        New-Item -ItemType Junction -Path $tmp -Target (Join-Path $Pipeline 'tmp') | Out-Null
        $made++
        Write-Host "tmp -> $Pipeline\tmp (the production mirror lives there)"
    } else {
        Write-Warning "kop-pipeline not found at $Pipeline; tmp/ was not linked (python ../kop-pipeline/scripts/sync-prod-sqlite.py writes the mirror)"
    }
}
Write-Host "$made links made. js/data now has $((Get-ChildItem $dataDir -Force).Count) entries."
