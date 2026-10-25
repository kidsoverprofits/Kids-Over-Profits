"""
seeds/research-tasks.json: the research tasks a session adds, answers or settles, imported by
KOP Tools > Research Tasks (inc/research-tasks.php) on the first admin request after a deploy.

The site's table is the open list; this file only carries what sessions send it. A task is inserted once by its
key and then belongs to the screen (owner edits, assignments, Undo). An entry that later gains an "answer" or a
"settled" line hands that to the screen once (the import keeps each entry's hash), never over an owner's own.

    python scripts/research-tasks/tasks.py add --section "Sequel renames" --title "Was Norris Academy in Norris, TN or Andersonville, TN?" \
        --text "The Sequel draft says Norris; record 9614 says Andersonville." --records 9614 --found-by "Sequel audit session" \
        --look "Tennessee DCS licence list (our copy) | https://kidsoverprofits.org/wp-content/uploads/..."
    python scripts/research-tasks/tasks.py directions [--key <key>]   # rebuild "where to look" from tmp/prod.sqlite
    python scripts/research-tasks/tasks.py questions <file.json>      # retitle from {key: question}

Every task's title is the question itself, in plain words, ending with "?". Its "directions" are where to look among
what KOP already holds: the records' news, court cases, Woodbury Reports issues (our copies) and archived websites,
the links in its notes, and anything added with --look. The screen adds each record's KOP page.
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


def mirror():
    if not os.path.exists(MIRROR):
        return None
    return sqlite3.connect('file:%s?mode=ro' % MIRROR.replace('\\', '/'), uri=True)


def archived(url):
    """Program and industry websites are linked as their archived copy (never live)."""
    if not url or 'web.archive.org' in url or 'kidsoverprofits.org' in url:
        return url
    return 'https://web.archive.org/web/2*/' + url


def site_of(url):
    """The site a link goes to, by name: "strugglingteens.com"; for a Wayback copy, the site it archived; "our copy" for KOP's own files."""
    if 'kidsoverprofits.org/wp-content/uploads/' in url:
        return 'our copy'
    m = re.match(r'https?://web\.archive\.org/web/[^/]+/(?:https?://)?([^/]+)', url)
    host = m.group(1) if m else re.sub(r'^https?://', '', url).split('/')[0]
    host = re.sub(r'^www\.', '', host.split(':')[0])
    return ('archived ' + host) if m else host


def directions_for(task, con):
    """
    Where to look for the answer, from what KOP already holds: per record, its news articles, court cases,
    Woodbury Reports issues (our PDF copies) and archived websites; then the links in the task's own notes.
    The record's KOP page is added by the screen (its address can change), not here.
    [{label, url, kind}], newest first within a kind, a few of each.
    """
    out, seen = [], set()

    def add(label, url, kind):
        url = (url or '').strip()
        if not url or url in seen or not re.match(r'https?://', url):
            return
        seen.add(url)
        out.append({'label': re.sub(r'\s+', ' ', label).strip()[:200], 'url': url, 'kind': kind})

    for fid in (task.get('records') or []) if con else []:
        for title, pub, date, arch, url in con.execute(
                "SELECT n.article_title, n.publication_name, n.publication_date, n.archive_url, n.article_url FROM news_facility_links l"
                " JOIN news_submissions n ON n.id = l.news_id WHERE l.facility_id = ? AND n.status IN ('approved', 'submitted')"
                " ORDER BY l.link_type = 'mentioned', n.publication_date DESC LIMIT 5", (fid,)):
            bits = ', '.join(x for x in (pub, (date or '')[:10]) if x)
            add('News: %s%s' % (title or 'article', ' (%s)' % bits if bits else ''), url or arch, 'news')
        for name, court, filed, src, docs in con.execute(
                "SELECT s.case_name, s.court, s.filing_date, s.source_urls, s.document_urls FROM lawsuit_facility_links l"
                " JOIN lawsuits s ON s.id = l.lawsuit_id WHERE l.facility_id = ? AND s.publication_status <> 'rejected'"
                " ORDER BY s.filing_date DESC LIMIT 4", (fid,)):
            urls = []
            for col in (src, docs):
                try:
                    urls += [u for u in json.loads(col or '[]') if isinstance(u, str)]
                except ValueError:
                    pass
            ours = [u for u in urls if 'kidsoverprofits.org' in u]
            bits = ', '.join(x for x in (court, ('filed ' + filed[:10]) if filed else '') if x)
            add('Court case: %s%s' % (name or 'case', ' (%s)' % bits if bits else ''), (ours or urls or [''])[0], 'court')
        for label, pages, guid in con.execute(
                "SELECT m.issue_label, m.pages, p.guid FROM wpdl_kop_woodbury_mentions m JOIN wpdl_posts p ON p.ID = m.attachment_id"
                " WHERE m.facility_id = ? AND m.status <> 'skipped' ORDER BY m.issue_date DESC LIMIT 4", (fid,)):
            add('Woodbury Reports, %s%s (our copy)' % (label, ', p. %s' % pages if pages else ''), guid, 'woodbury')
        row = con.execute('SELECT json_data FROM facilities_v2 WHERE id = ?', (fid,)).fetchone()
        try:
            j = json.loads(row[0]) if row else {}
        except ValueError:
            j = {}
        sites = list(j.get('profileLinks') or [])
        for k in ('website', 'websites'):
            v = (j.get('resources') or {}).get(k) or j.get(k)
            sites += v if isinstance(v, list) else ([v] if isinstance(v, str) else [])
        for u in [s for s in sites if isinstance(s, str)][:3]:
            add('Linked from its record (%s)' % site_of(archived(u)), archived(u), 'website')

    text = '\n'.join((task.get('text') or '', task.get('context') or ''))
    for label, url in re.findall(r'\[([^\]]+)\]\((https?://[^)\s]+)\)', text):
        site = site_of(url)
        add('From the notes: ' + (label if site.lower() in label.lower() else '%s (%s)' % (label, site)), url, 'note')
    for url in re.findall(r'(?<![(\[])\bhttps?://[^\s<)"\]]+[^\s<).,;:"\]]', text):
        add('From the notes: ' + re.sub(r'^https?://(www\.)?', '', url)[:80], url, 'note')
    return out


def name_index(con):
    """Record names that belong to exactly one record (two words, 10+ letters), lower-cased -> id."""
    by = {}
    for fid, name in con.execute("SELECT id, name FROM facilities_v2 WHERE name IS NOT NULL"):
        n = re.sub(r'\s+', ' ', name).strip().lower()
        if len(n) >= 10 and ' ' in n:
            by.setdefault(n, set()).add(fid)
    return {n: next(iter(ids)) for n, ids in by.items() if len(ids) == 1}


GENERIC = set('the a of and for at in youth academy academies behavioral behavioural health center centre boys girls ranch '
               'school schools home homes treatment residential program programs services healthcare hospital camp '
               'mountain mountains valley ridge new life house inc llc group'.split())


STATES = {
    'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas', 'CA': 'California', 'CO': 'Colorado', 'CT': 'Connecticut',
    'DE': 'Delaware', 'FL': 'Florida', 'GA': 'Georgia', 'HI': 'Hawaii', 'ID': 'Idaho', 'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa',
    'KS': 'Kansas', 'KY': 'Kentucky', 'LA': 'Louisiana', 'ME': 'Maine', 'MD': 'Maryland', 'MA': 'Massachusetts', 'MI': 'Michigan',
    'MN': 'Minnesota', 'MS': 'Mississippi', 'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada', 'NH': 'New Hampshire',
    'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York', 'NC': 'North Carolina', 'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma',
    'OR': 'Oregon', 'PA': 'Pennsylvania', 'RI': 'Rhode Island', 'SC': 'South Carolina', 'SD': 'South Dakota', 'TN': 'Tennessee',
    'TX': 'Texas', 'UT': 'Utah', 'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington', 'WV': 'West Virginia', 'WI': 'Wisconsin', 'WY': 'Wyoming',
}


def states_in(text):
    """The US states a text names, by code ("Hurricane, UT") or in full."""
    out = set(re.findall(r'\b(' + '|'.join(STATES) + r')\b', text))
    return out | {c for c, n in STATES.items() if re.search(r'\b' + n + r'\b', text)}


def named_records(task, names, con=None):
    """
    Ids of the records whose whole name the task's title or notes spell out: the name stands on its own (no
    capitalised word right before or after it, so "Youth Academy" never matches inside "Columbus Youth Academy"),
    it is not made only of generic words, and when the task names states the record is in one of them.
    """
    text = ' '.join((task.get('title') or '', plain(task.get('text') or '')))
    low = text.lower()
    states = states_in(text)
    found = []
    for n, fid in names.items():
        if n not in low or fid in found or set(n.replace('-', ' ').split()) <= GENERIC:
            continue
        if states and con is not None:
            st = (con.execute('SELECT state FROM facilities_v2 WHERE id = ?', (fid,)).fetchone() or [''])[0]
            if st and st not in states:
                continue
        if stands_alone(text, low, n):
            found.append(fid)
    return found


def stands_alone(text, low, n):
    """The name appears whole: no capitalised word right before or after it."""
    for m in re.finditer(r'(?<![\w-])' + re.escape(n) + r'(?![\w-])', low):
        before = re.search(r'([\w&.\'-]+)\s*$', text[:m.start()])
        after = re.match(r'\s*([\w&\'-]+)', text[m.end():])
        if before and before.group(1)[:1].isupper() or after and after.group(1)[:1].isupper():
            continue
        return True
    return False


def named_companies(task, con):
    """kop_operators ids whose name (or short name) the task spells out whole; names of one company only, 4+ letters."""
    by = {}
    for oid, short, name in con.execute('SELECT id, unique_name, name FROM wpdl_kop_operators'):
        for n in {re.sub(r'\s+', ' ', x or '').strip().lower() for x in (short, name)}:
            if len(n) >= 4 and not set(n.split()) <= GENERIC:
                by.setdefault(n, set()).add(oid)
    text = ' '.join((task.get('title') or '', plain(task.get('text') or ''), plain(task.get('context') or '')))
    low = text.lower()
    found = []
    for n, ids in by.items():
        if len(ids) == 1 and n in low and stands_alone(text, low, n) and next(iter(ids)) not in found:
            found.append(next(iter(ids)))
    return found


def cmd_directions(a):
    con = mirror()
    if con is None:
        sys.exit('No tmp/prod.sqlite (python ../kop-pipeline/scripts/sync-prod-sqlite.py).')
    data = load()
    names = name_index(con)
    n = 0
    for t in data['tasks']:
        if a.key and t['key'] not in a.key:
            continue
        for fid in named_records(t, names, con):
            if fid not in t['records']:
                t['records'].append(fid)
        t['companies'] = named_companies(t, con)
        t['directions'] = directions_for(t, con)
        n += bool(t['directions'] or t['records'] or t['companies'])
    save(data)
    print('%d of %d tasks have somewhere to look' % (n, len(data['tasks'])))


def cmd_questions(a):
    """Retitles tasks from a JSON {key: question} file (a session's rewording pass)."""
    with open(a.file, encoding='utf-8') as f:
        q = json.load(f)
    data = load()
    n = 0
    for t in data['tasks']:
        new = (q.get(t['key']) or '').strip()
        if new and new != t['title']:
            t['title'] = new[:255]
            n += 1
    save(data)
    print('%d retitled' % n)


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
    title = a.title.strip()
    if not title.endswith('?'):
        sys.exit('--title is the question itself, in plain words, ending with "?" ("What year did X in Town, ST close?").')
    taken = {t['key'] for t in data['tasks']}
    key = new_key(a.section, title, taken)
    task = {'key': key, 'section': a.section, 'title': title[:255], 'text': a.text or '', 'context': '',
            'records': recs, 'found': 'found %s, %s' % (datetime.date.today().isoformat(), a.found_by) if a.found_by else
            'found ' + datetime.date.today().isoformat()}
    con = mirror()
    task['directions'] = directions_for(task, con) if con else []
    for d in a.look or []:
        label, _, url = d.rpartition('|')
        task['directions'].insert(0, {'label': label.strip() or url.strip(), 'url': url.strip(), 'kind': 'note'})
    data['tasks'].append(task)
    save(data)
    print(key)
    for d in task['directions']:
        print('  look: %s  %s' % (d['label'], d['url']))
    if not task['directions']:
        print('  nothing to point to: add --look "Label | https://..." for each document or page KOP holds about it')


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
    s.add_argument('--look', action='append', help='"Label | https://..." a document or page KOP holds about it (repeatable)')
    s.set_defaults(fn=cmd_add)
    s = sub.add_parser('directions', help='rebuild "where to look" from tmp/prod.sqlite (every task, or --key)')
    s.add_argument('--key', action='append')
    s.set_defaults(fn=cmd_directions)
    s = sub.add_parser('questions', help='retitle tasks from a JSON {key: question} file')
    s.add_argument('file')
    s.set_defaults(fn=cmd_questions)
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
