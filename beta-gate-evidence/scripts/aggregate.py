import json,glob,os,collections
ev='/srv/gate/ev';rows=[]
order=['install','acct','tax','sec','pay','rec','ops','upg','r141','r142','r144','e2e','r144ui','r145','r149','r151','r151ui','r152','r153','r154','beta']
for f in order:
    p=f'{ev}/{f}.json'
    if os.path.exists(p):
        for r in json.load(open(p)): rows.append(r)
cnt=collections.Counter(r['status'] for r in rows)
by=collections.defaultdict(collections.Counter)
for r in rows: by[r['area']][r['status']]+=1
out=['| ID | Area | Test | Result | Evidence |','|---|---|---|---|---|']
for r in rows:
    e=r['evidence'].replace('|','/').replace('\n',' ')[:180]
    out.append(f"| {r['id']} | {r['area']} | {r['title'].replace('|','/')} | **{r['status']}** | {e} |")
# paths
paths=open(f'{ev}/paths.txt').read().splitlines()
ui=[]
for p in sorted(glob.glob(f'{ev}/ui-*.json')):
    d=json.load(open(p));
    if d['tag'] in ('probe','set'):continue
    bad=[s for s in d['screens'] if s['issues']]
    ui.append((d['tag'],d['W'],d['H'],d['Z'],d['theme'],d['nav'],d['mode'],len(d['screens']),len(bad),'; '.join(s['id']+': '+' / '.join(s['issues']) for s in bad)[:200]))
json.dump({'counts':cnt,'byArea':by,'paths':paths,'ui':ui},open(f'{ev}/summary.json','w'),indent=1)
open(f'{ev}/test-matrix.md','w').write('\n'.join(out)+'\n')
print(dict(cnt));print({k:dict(v) for k,v in by.items()});print(sum(1 for p in paths if p.startswith('PASS')),'path/header PASS;',sum(1 for p in paths if p.startswith('FAIL')),'FAIL');print(len(ui),'UI runs;',sum(u[8] for u in ui),'screens with issues')
