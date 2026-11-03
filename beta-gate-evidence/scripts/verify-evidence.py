#!/usr/bin/env python3
"""Independently check a Tegh gate evidence bundle.

Run from the unpacked bundle folder, optionally with the package ZIP:
    python3 scripts/verify-evidence.py [path/to/Tegh-...-PRIVATE-BETA.zip]

It does not trust summary.json or the QA report. It:
  1. checks every bundle file against BUNDLE-SHA256SUMS;
  2. if a ZIP is given, checks its SHA-256 equals PACKAGE.sha256, and that the identity
     the gate recorded (raw/identity.txt) names the same hash;
  3. recounts PASS / FAIL / BLOCKED / INFO / NOT APPLICABLE from every raw result file
     (raw/*.json test rows, raw/paths.txt, raw/ui-*.json, beta-ops/beta-ops-test.txt);
  4. prints the counts next to the ones summary.json claims, and exits 1 on any FAIL,
     any mismatch, or any missing file.
"""
import collections, glob, hashlib, json, os, sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.chdir(root)
problems = []

def sha(p):
    h = hashlib.sha256()
    with open(p, 'rb') as f:
        for b in iter(lambda: f.read(1 << 20), b''):
            h.update(b)
    return h.hexdigest()

# 1. bundle integrity
n = 0
for line in open('BUNDLE-SHA256SUMS'):
    want, path = line.rstrip('\n').split('  ', 1)
    n += 1
    if not os.path.exists(path):
        problems.append(f'missing {path}')
    elif sha(path) != want:
        problems.append(f'changed {path}')
print(f'bundle files checked: {n}')

# 2. package hash
pkg_hash, pkg_name = open('PACKAGE.sha256').read().split()[:2]
print(f'package: {pkg_name}\nexpected SHA-256: {pkg_hash}')
ident = open('raw/identity.txt').read()
if pkg_hash not in ident:
    problems.append('raw/identity.txt does not name the package hash')
else:
    print('the gate run recorded this hash in raw/identity.txt')
if len(sys.argv) > 1:
    got = sha(sys.argv[1])
    print(f'given ZIP SHA-256:  {got}')
    if got != pkg_hash:
        problems.append('the given ZIP is not the package this evidence belongs to')

# 3. recount
rows = []
for p in sorted(glob.glob('raw/*.json')):
    if os.path.basename(p) in ('summary.json',) or os.path.basename(p).startswith('ui-'):
        continue
    try:
        d = json.load(open(p))
    except ValueError:
        continue
    if isinstance(d, list) and d and isinstance(d[0], dict) and 'status' in d[0]:
        rows += d
tests = collections.Counter(r['status'] for r in rows)
paths = [l for l in open('raw/paths.txt').read().splitlines() if l.split(' ')[0] in ('PASS', 'FAIL')]
pathc = collections.Counter(l.split(' ')[0] for l in paths)
ui_runs = ui_bad = 0
for p in glob.glob('raw/ui-*.json'):
    d = json.load(open(p))
    if d.get('tag') in ('probe', 'set'):
        continue
    ui_runs += 1
    ui_bad += sum(1 for s in d['screens'] if s['issues'])
ops = collections.Counter()
if os.path.exists('beta-ops/beta-ops-test.txt'):
    for l in open('beta-ops/beta-ops-test.txt'):
        w = l.split(' ')[0]
        if w in ('PASS', 'FAIL'):
            ops[w] += 1

print('\nrecounted from raw files')
print(f'  test rows:          {dict(tests)}  (total {sum(tests.values())})')
print(f'  path/header checks: {dict(pathc)}')
print(f'  layout runs:        {ui_runs} runs, {ui_bad} screens with issues')
print(f'  beta-ops tests:     {dict(ops)}')
claimed = json.load(open('raw/summary.json'))['counts']
if dict(claimed) != dict(tests):
    problems.append(f'summary.json claims {claimed}, raw rows give {dict(tests)}')
else:
    print('  summary.json agrees with the raw rows')
total_pass = tests['PASS'] + pathc['PASS'] + ops['PASS']
total_fail = tests['FAIL'] + pathc['FAIL'] + ops['FAIL'] + ui_bad
print(f'\nPASS {total_pass}  FAIL {total_fail}  INFO {tests["INFO"]}  BLOCKED {tests["BLOCKED"]}  '
      f'NOT APPLICABLE {tests["NOT APPLICABLE"]}  (+{ui_runs} layout runs)')
if total_fail:
    problems.append(f'{total_fail} failing checks')
for r in rows:
    if r['status'] in ('FAIL', 'BLOCKED'):
        print(f"  {r['status']}: {r['id']} {r['title']}")

print()
if problems:
    print('NOT VERIFIED:\n  ' + '\n  '.join(problems))
    sys.exit(1)
print('VERIFIED: the raw results match the claimed counts and belong to this package hash')
