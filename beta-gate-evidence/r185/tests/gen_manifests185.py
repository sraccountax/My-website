# R185: update the three release manifests in place (R184 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests184.py, plus the removed files and a release-token check.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='ad6e421'  # R184 evidence commit (last R184 commit)
TOKEN='5990-r185-speed'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R185-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (R185: .htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r185Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R185 (private beta package, replaces R184): speed. Registers and reports no longer lay out their full legacy table before the sealed report replaces it; '
     'the sign-in check reads the database catalogue with two plain queries instead of one slow joined query; the workspace read runs beside that check; '
     'interface files start downloading during sign-in; files of this release are cached by the browser instead of re-checked on every load; '
     'a profile-menu style rule that re-checked every menu on each page change is replaced by a class; three unused stylesheets are removed. No accounting behaviour changes. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R184',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r184Files':
            out['r185Files']=r185Files;out['r185CacheToken']=TOKEN;out['r185RemovedFiles']=removed
            out['r185Migration']='None. No table, column or row changes.'
        out[k]=v
    out['release']='R185';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R184 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R185 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R185 = R184 plus speed changes (no accounting changes). '
                       'Before/after timings, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r185/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
