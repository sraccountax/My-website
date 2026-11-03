#!/usr/bin/env python3
"""Compare two R181 captures exactly, after removing only measured noise:
timing fields, and the order of rows that the server returns in an unspecified order (sorted by id).
Usage: r181-compare.py <dirA> <dirB> [--report out.json]"""
import json, os, sys

NOISE_KEYS = {'durationMs', 'elapsedMs', 'generatedAt', 'serverTime', 'requestId', 'csrfToken'}

def canon(v):
    if isinstance(v, dict):
        return {k: canon(x) for k, x in sorted(v.items()) if k not in NOISE_KEYS}
    if isinstance(v, list):
        items = [canon(x) for x in v]
        if items and all(isinstance(x, dict) and 'id' in x for x in items):
            items.sort(key=lambda x: (str(x.get('id')), json.dumps(x, sort_keys=True)))
        return items
    return v

def load(path):
    raw = open(path, encoding='utf-8').read()
    if path.endswith('.json'):
        try: return canon(json.loads(raw))
        except ValueError: return raw
    return raw

def main():
    a, b = sys.argv[1], sys.argv[2]
    report = sys.argv[sys.argv.index('--report') + 1] if '--report' in sys.argv else None
    diffs, missing, compared = [], [], 0
    for sub in ('api', 'screens'):
        fa = set(os.listdir(os.path.join(a, sub))); fb = set(os.listdir(os.path.join(b, sub)))
        missing += [f'{sub}/{x} only in A' for x in sorted(fa - fb)] + [f'{sub}/{x} only in B' for x in sorted(fb - fa)]
        for name in sorted(fa & fb):
            compared += 1
            if load(os.path.join(a, sub, name)) != load(os.path.join(b, sub, name)): diffs.append(f'{sub}/{name}')
    sa = json.load(open(os.path.join(a, 'api-status.json'))); sb = json.load(open(os.path.join(b, 'api-status.json')))
    status = [k for k in sa if sa.get(k) != sb.get(k)]
    out = {'compared': compared, 'different': diffs, 'missing': missing, 'statusChanged': status}
    print(f"compared {compared} files: {len(diffs)} different, {len(missing)} missing, {len(status)} status changes")
    for d in diffs[:20]: print('  DIFF', d)
    for d in missing[:10]: print('  MISSING', d)
    if report: json.dump(out, open(report, 'w'), indent=1)
    sys.exit(1 if diffs or missing or status else 0)

main()
