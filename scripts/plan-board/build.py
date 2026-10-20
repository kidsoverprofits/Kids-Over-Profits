"""Build the Plan Board page (an Artifact) from docs/PLAN.md.

    python scripts/plan-board/build.py [--out tmp/plan-board/index.html] [--json]

Every list item in sections 2 and up of PLAN.md becomes a card with a stable id
("<section>:<slug of its bold lead>"); the owner's answers are kept in the
artifact's database (collection "answers", one document per item id), never in
this page. docs/PLAN.md section "Plan Board" says how a session folds them in.
"""
import argparse
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAN = ROOT / 'docs' / 'PLAN.md'
TEMPLATE = Path(__file__).with_name('template.html')

ITEM_START = re.compile(r'^(\d+)\.\s+|^-\s+')
LEAD = re.compile(r'^\*\*(.+?)\*\*')


def slug(text, limit=60):
    s = re.sub(r'[`*\[\]()]', '', text.lower())
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    return s[:limit].rstrip('-') or 'item'


def parse(md):
    sections = []          # [{key, title, intro, groups: [{key, title, intro, items}]}]
    sec = grp = item = None
    para = []

    def flush_para():
        nonlocal para
        if para and grp is not None:
            text = ' '.join(l.strip() for l in para)
            grp['intro'].append(text)
        para = []

    def close_item():
        nonlocal item
        item = None

    for raw in md.split('\n'):
        line = raw.rstrip()
        m2 = re.match(r'^## (\d+)\.\s+(.*)', line)
        m3 = re.match(r'^### (?:(\d+\.\d+)\s+)?(.*)', line)
        if line.startswith('## '):
            flush_para(); close_item()
            if m2 and int(m2.group(1)) >= 2:
                sec = {'key': m2.group(1), 'title': m2.group(2), 'groups': []}
                grp = {'key': m2.group(1), 'title': '', 'intro': [], 'items': []}
                sec['groups'].append(grp)
                sections.append(sec)
            else:
                sec = grp = None
            continue
        if sec is None:
            continue
        if line.startswith('### '):
            flush_para(); close_item()
            key = m3.group(1) or (sec['key'] + '.' + slug(m3.group(2), 20))
            grp = {'key': key, 'title': m3.group(2), 'intro': [], 'items': []}
            sec['groups'].append(grp)
            continue
        if line.strip() in ('', '---'):
            flush_para(); close_item()
            continue
        if ITEM_START.match(line):
            flush_para()
            body = ITEM_START.sub('', line, count=1)
            item = {'body': [body]}
            grp['items'].append(item)
            continue
        if item is not None and raw[:1] in (' ', '\t'):
            item['body'].append(line.strip())
            continue
        close_item()
        para.append(line)
    flush_para()

    seen = set()
    out = []
    for s in sections:
        groups = []
        for g in s['groups']:
            items = []
            for it in g['items']:
                text = ' '.join(it['body'])
                lm = LEAD.match(text)
                lead = lm.group(1).rstrip(':.') if lm else ' '.join(text.split()[:8])
                base = g['key'] + ':' + slug(lead)
                iid, n = base, 2
                while iid in seen:
                    iid, n = f'{base}-{n}', n + 1
                seen.add(iid)
                items.append({'id': iid, 'lead': lead, 'text': text})
            if items or g['intro']:
                groups.append({'key': g['key'], 'title': g['title'], 'intro': g['intro'], 'items': items})
        out.append({'key': s['key'], 'title': s['title'], 'groups': groups})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=str(ROOT / 'tmp' / 'plan-board' / 'index.html'))
    ap.add_argument('--json', action='store_true', help='print the parsed items instead')
    a = ap.parse_args()
    sections = parse(PLAN.read_text(encoding='utf-8'))
    if a.json:
        json.dump(sections, sys.stdout, indent=1, ensure_ascii=False)
        return
    data = {'built': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC'), 'sections': sections}
    blob = json.dumps(data, ensure_ascii=False).replace('</', '<\\/')
    html = TEMPLATE.read_text(encoding='utf-8').replace('/*__PLAN__*/null', blob)
    out = Path(a.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(html, encoding='utf-8', newline='\n')
    n = sum(len(g['items']) for s in sections for g in s['groups'])
    print(f'{n} items -> {out}')


if __name__ == '__main__':
    main()
