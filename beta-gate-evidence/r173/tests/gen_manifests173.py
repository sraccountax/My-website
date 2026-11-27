# R173: update the three release manifests in place (R172 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Rebuilt after a container restart from the shape of the R171 → R172 manifest change.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R173-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','88cfcde','HEAD','--','.'],text=True).splitlines()
r173Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R173 (private beta package, replaces R172): the public pages and the sign-in screen use the design of sraccountax.ca — '
     'Manrope and Playfair Display, navy, teal and gold, white sticky header, navy footer; the words, the signed-in app, '
     'accounting and the database are unchanged. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R172',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r172Files':
            out['r173Files']=r173Files;out['r173CacheToken']='5990-r173-sr'
            out['r173Migration']='None. R173 changes style sheets, fonts and page head tags only; no database or server change.'
        out[k]=v
    out['release']='R173';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R172 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R173 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r173-sr'
    out['testStatus']=('R173 = R172 plus the sraccountax.ca design on the public pages and the sign-in screen. '
                       'Checks for this exact ZIP are in beta-gate-evidence/r173/ in the source repository; the full gate was not re-run for this style-only release. '
                       'Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
