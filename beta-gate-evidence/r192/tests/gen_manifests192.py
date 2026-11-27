# R192: update the three release manifests in place (R191 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests187.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='9e0d146'  # source of the R191 package (the R191 ZIP, SHA-256 55d2301a...)
TOKEN='5990-r192-audit'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R192-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (.htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r192Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R192 (private beta package, replaces R191): fixes from a functional and UI audit of both editions: India registrations with a '
     'time-zone change, financial-year wording, India payroll pages, Canada-only screens closed in India, recurring GST refusal, expenses '
     'without PST, Indian states in data import; Match and Post on phones, report scope line, labelled role lists. Schema stays 46. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R191',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r191Files':
            out['r192Files']=r192Files;out['r192CacheToken']=TOKEN;out['r192RemovedFiles']=removed
            out['r192Migration']='None. Schema stays 46.'
        out[k]=v
    out['release']='R192';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R191 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R192 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R192 = R191 plus the audit fixes listed in R192-CHANGES.md. '
                       'Suite 70-r192, the India suites, the audit sweep of both editions, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r192/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
