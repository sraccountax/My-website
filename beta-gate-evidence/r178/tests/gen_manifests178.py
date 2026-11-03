# R178: update the three release manifests in place (R177 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R178-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','fb3990b','HEAD','--','.'],text=True).splitlines()
r178Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R178 (private beta package, replaces R177): Client Dashboard Demo for the platform owner (Settings › Platform Admin) showing the new client dashboard with sample figures, an established or a brand-new business, full-screen presenting; nothing is read from or written to any company. Fixes the colours of the R177 empty-panel illustrations. Browser files only. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R177',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r177Files':
            out['r178Files']=r178Files;out['r178CacheToken']='5990-r178-demo'
            out['r178Migration']='None. Browser files only; no server or database change.'
        out[k]=v
    out['release']='R178';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R177 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R178 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r178-demo'
    out['testStatus']=('R178 = R177 plus the Client Dashboard Demo. '
                       'Checks on this exact ZIP installed on the gate host over R177 (suites 50-r176, 51-r177 and 52-r178) are in beta-gate-evidence/r178/; the last full regression ran on R177 (beta-gate-evidence/r177/). Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
