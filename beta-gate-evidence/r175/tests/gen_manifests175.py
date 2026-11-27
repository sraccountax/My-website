# R175: update the three release manifests in place (R174 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Rebuilt after a container restart from the shape of the R171 → R172 manifest change.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R175-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','552f0ee','HEAD','--','.'],text=True).splitlines()
r175Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R175 (private beta package, replaces R174): public pages use more of the screen width, with larger section headings and a smaller top heading; on phones the product screenshots show the phone layout; the table Actions column is pinned only on screens wider than 700 pixels. No database or server change. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R174',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r174Files':
            out['r175Files']=r175Files;out['r175CacheToken']='5990-r175-mobile'
            out['r175Migration']='None. R175 changes style sheets, images and page markup only.'
        out[k]=v
    out['release']='R175';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R174 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R175 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r175-mobile'
    out['testStatus']=('R175 = R174 plus public-page layout and phone screenshots. '
                       'Gate results for this exact ZIP (full regression including suites 48-r174 and 49-r175, upgrade from R118, screen matrix) are in beta-gate-evidence/r175/ in the source repository. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
