# Network map

Commands, tests and rules for the network map: its data, build, auto rebuild, embeds, years and renames. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# After editing the network map CSVs or network-overrides.json
(kop-pipeline) node scripts/build-network-graph.js
(kop-pipeline) node scripts/test-network-graph.js

# The map rebuilds itself: inc/network-rebuild.php hashes what the build reads (staff, owners, names, years, status,
# consultants) hourly and, when it moves, starts kop-pipeline's build-network-map.yml (sync 7 tables over SSH,
# build, test, commit graph.json + layout.json to kop-data, whose deploy ships them). Status and "Rebuild now" at
# KOP Tools > Map Rebuild. The workflow's repo is KOP_NETWORK_REBUILD_REPO in api/config.local.php or .env
# (kop_network_rebuild_repo()), the token KOP_GITHUB_DISPATCH_TOKEN there (Actions: write on that repo)
php scripts/test-network-rebuild.php            # fingerprint + dispatch, against tmp/prod.sqlite, never calls GitHub
# After editing anything in js/network-map/
node scripts/test-network-modules.js
# The map on facility pages (embed.js + kop_network_map_slice_from_graph, PHP must match focus.js)
node scripts/test-network-embed.js [--php=<Local php.exe>]
node scripts/test-network-timeline.js           # the timeline (store years, timeline.js), seconds
# Researched map years reviewed at KOP Tools > Map Years (inc/network-years.php); accepted ones go on the map at once
(kop-pipeline) node scripts/build-years-candidates.js [tmp/years-research]   # results -> js/data/network/years-candidates.json
php scripts/test-network-years.php && python scripts/check-network-years-page.py
# Renamed programs reviewed at KOP Tools > Map Renames (inc/network-renames.php): the rename year splits the two names' years on the map at once
php scripts/test-network-renames.php

# See the working tree's map in a browser before pushing it
python scripts/preview-network-map.py --shots tmp/map-preview
```

## Network map data

`js/data/network/` (the kop-data repo) holds the Miro board export (`tti_nodes.csv`, `tti_edges.csv`)
and the generated `graph.json` that the network map reads. Corrections go in
`network-overrides.json`, never in the CSVs, so the next board export does not
undo them. `staff-movement.csv` holds the reviewed staff moves the build adds
as edges; `node scripts/extract-staff-movement.js` (kop-pipeline) drafts it from the profile
text into `tmp/staff-movement.draft.csv` for comparison. Each build rewrites
`tmp/network-qa.md` (gitignored) listing every row the rules had to guess at.
Facility links are resolved against
`facilities_v2` in `tmp/prod.sqlite` when that mirror is present, and fall back
to the program aggregate otherwise. The build reads `js/network-map/store.js` and
`js/vendor/d3-force.bundle.min.js` from this repo, so a change there is a map change too.
