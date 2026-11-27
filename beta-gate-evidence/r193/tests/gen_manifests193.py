# R193: update the three release manifests in place (R192 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests187.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='5ded40d'  # source of the R192 package (the R192 ZIP, SHA-256 53e7c39b...)
TOKEN='5990-r193-audit'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R193-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (.htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r193Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R193 (private beta package, replaces R192): fixes from an audit with large companies in both editions, both bookkeeping '
     'modes, light and dark themes and side and top navigation: customer and vendor balances, ageing and party balance reports agree '
     'with the GL (credit notes, refunds, amounts beyond an invoice, India TDS); large-company workspace memory; reports over the row limit '
     'offer their latest month; dark theme readability; India Guided mode shown as not available. Schema stays 46. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R192',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r192Files':
            out['r193Files']=r193Files;out['r193CacheToken']=TOKEN;out['r193RemovedFiles']=removed
            out['r193Migration']='None. Schema stays 46.'
        out[k]=v
    out['release']='R193';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R192 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R193 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R193 = R192 plus the large-data audit fixes listed in R193-CHANGES.md. '
                       'Suite 71-r193, the large-company checks of both editions, the 24-run screen matrix, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r193/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
