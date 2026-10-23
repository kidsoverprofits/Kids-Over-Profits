"""
seeds/research-tasks.json: the research tasks a session adds, answers or settles, imported by
KOP Tools > Research Tasks (inc/research-tasks.php) on the first admin request after a deploy.

The site's table is the open list; this file only carries what sessions send it. A task is inserted once by its
key and then belongs to the screen (owner edits, assignments, Undo). An entry that later gains an "answer" or a
"settled" line hands that to the screen once (the import keeps each entry's hash), never over an owner's own.

    python scripts/research-tasks/tasks.py add --section "Sequel renames" --title "Which town was Norris Academy in" \
        --text "Norris, TN (draft) or Andersonville, TN (record 9614)?" --records 9614 --found-by "Sequel audit session"
    python scripts/research-tasks/tasks.py answer <key> --text "Andersonville (TN DCS licence 2015)" --by "Claude session"
    python scripts/research-tasks/tasks.py settle <key> --text "Record fixed in commit <url>"
    python scripts/research-tasks/tasks.py list [--open]
    python scripts/research-tasks/tasks.py import-md      # done once, 2026-10-23: docs/RESEARCH-TASKS.md "Open" -> tasks (the file has no Open list since)

Record ids (--records, and the ids import-md finds in the text) are checked against tmp/prod.sqlite when it is there.
"""

import argparse
import datetime
import json
import os
import re
import sqlite3
import sys

THEME = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SEED = os.path.join(THEME, 'seeds', 'research-tasks.json')
MIRROR = os.path.join(THEME, 'tmp', 'prod.sqlite')


def load():
    if not os.path.exists(SEED):
        return {'tasks': []}
    with open(SEED, encoding='utf-8') as f:
        return json.load(f)


def save(data):
    data['built'] = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M')
    with open(SEED, 'w', encoding='utf-8', newline='\n') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
        f.write('\n')


def slug(s, n):
    s = re.sub(r'[^a-z0-9]+', '-', s.lower()).strip('-')
    return s[:n].rstrip('-') or 'task'


def new_key(section, title, taken):
    base = slug(section, 32) + ':' + slug(title, 48)
    key, i = base, 2
    while key in taken:
        key = '%s-%d' % (base, i)
        i += 1
    taken.add(key)
    return key


def known_ids():
    if not os.path.exists(MIRROR):
        return None
    con = sqlite3.connect('file:%s?mode=ro' % MIRROR.replace('\\', '/'), uri=True)
    try:
        return {r[0] for r in con.execute('SELECT id FROM facilities_v2')}
    finally:
        con.close()


def ids_in(text, known):
    """Facility ids named in the text: 4-6 digit numbers that are not years and are records (when the mirror is here)."""
    out = []
    for m in re.finditer(r'(?<![\d.\-/:#=?&])#?(\d{4,6})(?![\d\-/])', text):
        n = int(m.group(1))
        if 1800 <= n <= 2099:
            continue
        if known is not None and n not in known:
            continue
        if n not in out:
            out.append(n)
    return out


def plain(s):
    s = re.sub(r'\*\*(.+?)\*\*', r'\1', s)
    s = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', s)
    return s.replace('`', '').strip()


def first_sentence(s, n=150):
    s = plain(s).replace('\n', ' ')
    m = re.match(r'(.+?[.?!])(\s|$)', s)
    t = m.group(1) if m and len(m.group(1)) <= n else s
    return t if len(t) <= n else t[:n - 3].rsplit(' ', 1)[0] + '...'


def parse_md(path):
    """The "## Open" part of RESEARCH-TASKS.md as [{section, found, intro, tasks: [{title, text}]}]."""
    with open(path, encoding='utf-8') as f:
        text = f.read().replace('\r\n', '\n')
    m = re.search(r'^## Open\n(.*?)^## Settled', text, re.S | re.M)
    if not m:
        sys.exit('No "## Open" ... "## Settled" in ' + path)
    sections = []
    for block in re.split(r'^### ', m.group(1), flags=re.M)[1:]:
        head, _, body = block.partition('\n')
        hm = re.match(r'(.*?)\s*\((found .*)\)\s*$', head.strip())
        title, found = (hm.group(1), hm.group(2)) if hm else (head.strip(), '')
        sec = {'section': title, 'found': found, 'intro': [], 'tasks': []}
        lines = [l for l in body.rstrip().split('\n') if l.strip() != '---']
        i = 0
        para = []
        header = None

        def flush():
            if para:
                sec['intro'].append(' '.join(x.strip() for x in para))
                para.clear()

        while i < len(lines):
            line = lines[i]
            if line.startswith('|'):
                flush()
                cells = [c.strip() for c in line.strip().strip('|').split('|')]
                if header is None:
                    header = cells
                elif not all(re.fullmatch(r':?-+:?', c) for c in cells):
                    pairs = list(zip(header, cells))
                    todo = cells[-1] if header[-1].lower().startswith('to do') else ''
                    t = plain(cells[0]) + (': ' + first_sentence(todo, 110) if todo else '')
                    sec['tasks'].append({'title': t, 'text': '\n'.join('- **%s**: %s' % (h, c) for h, c in pairs if c)})
                i += 1
                continue
            header = None
            if line.startswith('- '):
                flush()
                item = [line[2:]]
                i += 1
                # Continuation: indented lines, and blank lines followed by an indented line.
                while i < len(lines) and (lines[i].startswith('  ') or (lines[i].strip() == '' and i + 1 < len(lines) and lines[i + 1].startswith('  '))):
                    item.append(lines[i][2:] if lines[i].startswith('  ') else '')
                    i += 1
                body_text = '\n'.join(item).strip()
                lead = re.match(r'\*\*(.+?)\*\*', body_text)
                t = plain(lead.group(1)) if lead else first_sentence(body_text)
                sec['tasks'].append({'title': t, 'text': body_text})
                continue
            if line.strip() == '':
                flush()
            else:
                para.append(line)
            i += 1
        flush()
        if not sec['tasks']:
            # A section written as prose is one task.
            sec['tasks'].append({'title': title, 'text': '\n\n'.join(sec['intro'])})
            sec['intro'] = []
        sections.append(sec)
    return sections


def cmd_import_md(a):
    data = load()
    if any(t.get('from') == 'RESEARCH-TASKS.md' for t in data['tasks']) and not a.force:
        sys.exit('Already imported (use --force to add again).')
    known = known_ids()
    taken = {t['key'] for t in data['tasks']}
    n = 0
    for s in parse_md(os.path.join(THEME, 'docs', 'RESEARCH-TASKS.md')):
        intro = '\n\n'.join(s['intro'])
        for t in s['tasks']:
            data['tasks'].append({
                'key': new_key(s['section'], t['title'], taken), 'section': s['section'], 'title': t['title'][:255],
                'text': t['text'], 'context': intro, 'records': ids_in(t['text'] + '\n' + t['title'], known),
                'found': s['found'], 'from': 'RESEARCH-TASKS.md',
            })
            n += 1
    save(data)
    print('%d tasks from docs/RESEARCH-TASKS.md%s' % (n, '' if known is not None else ' (no tmp/prod.sqlite: record ids not checked)'))


def cmd_add(a):
    data = load()
    known = known_ids()
    recs = [int(x) for x in re.split(r'[,\s]+', a.records or '') if x]
    bad = [r for r in recs if known is not None and r not in known]
    if bad:
        sys.exit('Not facilities_v2 ids: %s' % bad)
    taken = {t['key'] for t in data['tasks']}
    key = new_key(a.section, a.title, taken)
    data['tasks'].append({'key': key, 'section': a.section, 'title': a.title[:255], 'text': a.text or '', 'context': '',
                          'records': recs, 'found': 'found %s, %s' % (datetime.date.today().isoformat(), a.found_by) if a.found_by else
                          'found ' + datetime.date.today().isoformat()})
    save(data)
    print(key)


def find(data, key):
    for t in data['tasks']:
        if t['key'] == key:
            return t
    sys.exit('No task ' + key + ' in the seed (a task added on the screen has no key here: answer it there).')


def cmd_answer(a):
    data = load()
    t = find(data, a.key)
    t['answer'] = {'text': a.text, 'by': a.by, 'at': datetime.date.today().isoformat()}
    save(data)
    print('answered ' + a.key)


def cmd_settle(a):
    data = load()
    t = find(data, a.key)
    t['settled'] = {'text': a.text, 'at': datetime.date.today().isoformat()}
    save(data)
    print('settled ' + a.key)


def cmd_list(a):
    section = None
    for t in load()['tasks']:
        state = 'settled' if t.get('settled') else ('answered' if t.get('answer') else 'open')
        if a.open and state != 'open':
            continue
        if t['section'] != section:
            section = t['section']
            print('\n## ' + section)
        print('%-9s %s\n          %s' % (state, t['key'], t['title']))


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest='cmd', required=True)
    s = sub.add_parser('add')
    s.add_argument('--section', required=True)
    s.add_argument('--title', required=True)
    s.add_argument('--text', default='')
    s.add_argument('--records', default='')
    s.add_argument('--found-by', default='')
    s.set_defaults(fn=cmd_add)
    s = sub.add_parser('answer')
    s.add_argument('key')
    s.add_argument('--text', required=True)
    s.add_argument('--by', default='Claude session')
    s.set_defaults(fn=cmd_answer)
    s = sub.add_parser('settle')
    s.add_argument('key')
    s.add_argument('--text', required=True)
    s.set_defaults(fn=cmd_settle)
    s = sub.add_parser('list')
    s.add_argument('--open', action='store_true')
    s.set_defaults(fn=cmd_list)
    s = sub.add_parser('import-md')
    s.add_argument('--force', action='store_true')
    s.set_defaults(fn=cmd_import_md)
    a = p.parse_args()
    a.fn(a)


if __name__ == '__main__':
    main()
