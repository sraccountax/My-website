# R174: update the three release manifests in place (R173 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Rebuilt after a container restart from the shape of the R171 → R172 manifest change.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R174-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','195c58b','HEAD','--','.'],text=True).splitlines()
r174Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R174 (private beta package, replaces R173): Site & Release Settings for the platform owner — release stage, public sign-up, top-bar notice, version and Beta labels, business details, versioned policy pages and the beta wording on the public pages, changeable without a new release; every change needs a reason and is kept in the change history. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R173',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r173Files':
            out['r174Files']=r174Files;out['r174CacheToken']='5990-r174-site'
            out['r174Migration']='Automatic: site_settings, site_setting_history and site_policy_versions are created the first time Site & Release Settings are used. Config: app.public_signup_enabled is no longer read; app.public_signup_force_off => true is the emergency stop.'
        out[k]=v
    out['release']='R174';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R173 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R174 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r174-site'
    out['testStatus']=('R174 = R173 plus Site & Release Settings. '
                       'Gate results for this exact ZIP (full regression including suite 48-r174, upgrade from R118, screen matrix) are in beta-gate-evidence/r174/ in the source repository. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
