# R177: update the three release manifests in place (R176 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R177-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','1ec74d8','HEAD','--','.'],text=True).splitlines()
r177Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R177 (private beta package, replaces R176): a new dashboard (cash forecast line, animated figures, Needs you, who owes you, where the money goes, loading placeholders, empty states for a new company) fed by a new read-only route dashboard/summary; off until the platform owner turns it on in Site & Release Settings (off, platform owner only, everyone). No new tables. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R176',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r176Files':
            out['r177Files']=r177Files;out['r177CacheToken']='5990-r177-dashboard'
            out['r177Migration']='None. No new tables; the New dashboard switch is a field in the existing release row of site_settings (default off).'
        out[k]=v
    out['release']='R177';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R176 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R177 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r177-dashboard'
    out['testStatus']=('R177 = R176 plus the new dashboard (off by default). '
                       'Gate results for this exact ZIP (full regression including suites 48-r174 to 51-r177, upgrade from R118, screen matrix) are in beta-gate-evidence/r177/ in the source repository. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
