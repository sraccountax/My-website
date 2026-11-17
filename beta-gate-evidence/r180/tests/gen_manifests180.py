# R180: update the three release manifests in place (R179 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R180-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D','9c69479','HEAD','--','.'],text=True).splitlines() if f)
_changed=subprocess.check_output(['git','diff','--name-only','9c69479','HEAD','--','.'],text=True).splitlines()
r180Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R180 (private beta package, replaces R179): 46 files that nothing loads removed (old social-preview images, v4140 public-site files, unused screenshots and font files, an unused SQL copy, licences of fonts not included); no code change. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R179',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r179Files':
            out['r180Files']=r180Files;out['r180CacheToken']='5990-r179-paddle'
            out['r180Migration']='None. No code change; 46 unused files removed. Copies already on the host are harmless.';out['r180RemovedFiles']=_removed
        out[k]=v
    out['release']='R180';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R179 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R180 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r179-paddle'
    out['testStatus']=('R180 = R179 with 46 unused files removed. '
                       'Full regression on this exact ZIP installed on the gate host is in beta-gate-evidence/r180/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
