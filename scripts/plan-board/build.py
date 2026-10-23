"""Build seeds/plan-board.json, the plan the KOP Tools > Plan Board screen shows, from docs/plan/*.md.

    python scripts/plan-board/build.py [--out seeds/plan-board.json] [--json] [--check]

--check writes nothing and exits 1 when the seed is behind docs/plan/ (its "source" hash differs). The workflow
.github/workflows/plan-board.yml runs this on every push touching docs/plan/ and commits the rebuilt seed, and
scripts/test-plan-board.php makes the same check, so a session no longer has to remember the rebuild.

docs/PLAN.md is the index; the items are in docs/plan/*.md, read in name order. A file of a numbered subsection
repeats its section heading ("## 4. ..."), which joins the section already read.
Every list item in sections 2 and up becomes a card with a stable id
("<section>:<slug of its bold lead>") and a hash of its text. An item that waits on the owner ends with a line
"Ask: <one-line question>" (every item of section 2 does); it becomes the card's "ask" and puts the card in the
screen's "Needs you" view. The owner's answers are kept on the site
({prefix}kop_plan_answers, inc/plan-board.php), never in these files. docs/PLAN.md section "Plan Board" says how a
session folds them in. docs/ is not deployed, so this file is how the server sees the plan.
"""
import argparse
import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAN_DIR = ROOT / 'docs' / 'plan'

ITEM_START = re.compile(r'^(\d+)\.\s+|^-\s+')
LEAD = re.compile(r'^\*\*(.+?)\*\*')
ASK = re.compile(r'^Ask:\s*')
DATE = re.compile(r'\b(20\d\d-[01]\d-[0-3]\d)\b')


def slug(text, limit=60):
    s = re.sub(r'[`*\[\]()]', '', text.lower())
    s = re.sub(r'[^a-z0-9]+', '-', s).strip('-')
    return s[:limit].rstrip('-') or 'item'


def parse(md):
    sections = []          # [{key, title, intro, groups: [{key, title, intro, items}]}]
    by_key = {}
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
            if m2 and m2.group(1) in by_key:      # the same section, continued in the next file
                sec = by_key[m2.group(1)]
                grp = sec['groups'][0]
            elif m2 and int(m2.group(1)) >= 2:
                sec = {'key': m2.group(1), 'title': m2.group(2), 'groups': []}
                by_key[sec['key']] = sec
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
            item = {'body': [body], 'ask': []}
            grp['items'].append(item)
            continue
        if item is not None and raw[:1] in (' ', '\t'):
            # "Ask: <question>" (its own line, last in the item) is the one-line question for the owner.
            if item['ask'] or ASK.match(line.strip()):
                item['ask'].append(ASK.sub('', line.strip(), count=1))
            else:
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
                ask = ' '.join(it['ask'])
                lm = LEAD.match(text)
                lead = lm.group(1).rstrip(':.') if lm else ' '.join(text.split()[:8])
                base = g['key'] + ':' + slug(lead)
                iid, n = base, 2
                while iid in seen:
                    iid, n = f'{base}-{n}', n + 1
                seen.add(iid)
                # The newest date in the item: when it was written or last brought up to date. Needs you sorts by it.
                dates = DATE.findall(text)
                items.append({'id': iid, 'lead': lead, 'text': text, 'ask': ask, 'date': max(dates) if dates else ''})
            if items or g['intro']:
                groups.append({'key': g['key'], 'title': g['title'], 'intro': g['intro'], 'items': items})
        out.append({'key': s['key'], 'title': s['title'], 'groups': groups})
    return out


def source_text(files):
    """The plan files as one text; "source" is its sha1. Newlines are LF (read_text translates CRLF), and
    scripts/test-plan-board.php joins the files the same way to tell whether the seed is behind."""
    return '\n\n'.join(p.read_text(encoding='utf-8') for p in files)


def source_hash(files):
    return hashlib.sha1(source_text(files).encode('utf-8')).hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=str(ROOT / 'seeds' / 'plan-board.json'))
    ap.add_argument('--json', action='store_true', help='print the parsed items instead')
    ap.add_argument('--check', action='store_true', help='exit 1 when the seed is behind docs/plan/, write nothing')
    a = ap.parse_args()
    files = sorted(PLAN_DIR.glob('*.md'))
    src = source_hash(files)
    if a.check:
        try:
            have = json.loads(Path(a.out).read_text(encoding='utf-8')).get('source')
        except (OSError, ValueError):
            have = None
        if have != src:
            print(f'{a.out} is behind docs/plan/: run python scripts/plan-board/build.py')
            sys.exit(1)
        print('plan board seed is current')
        return
    sections = parse(source_text(files))
    if a.json:
        json.dump(sections, sys.stdout, indent=1, ensure_ascii=False)
        return
    for s in sections:
        for g in s['groups']:
            for it in g['items']:
                full = it['text'] + ('\n' + it['ask'] if it['ask'] else '')
                it['hash'] = hashlib.sha1(full.encode('utf-8')).hexdigest()[:12]
    data = {'built': datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC'), 'source': src, 'sections': sections}
    out = Path(a.out)
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(data, ensure_ascii=False, indent=1) + '\n', encoding='utf-8', newline='\n')
    n = sum(len(g['items']) for s in sections for g in s['groups'])
    print(f'{n} items -> {out}')


if __name__ == '__main__':
    main()
