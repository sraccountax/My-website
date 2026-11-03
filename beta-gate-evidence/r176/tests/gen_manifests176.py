# R176: update the three release manifests in place (R175 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R176-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','fa6da6b','HEAD','--','.'],text=True).splitlines()
r176Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R176 (private beta package, replaces R175): Site & Release Settings is also listed under Settings › Platform Admin and in the account menu; it was reachable only from a card on Platform Owner Home. One browser file; no database or server change. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R175',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r175Files':
            out['r176Files']=r176Files;out['r176CacheToken']='5990-r176-settings'
            out['r176Migration']='None. R176 changes one browser file and the cache token.'
        out[k]=v
    out['release']='R176';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R175 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R176 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r176-settings'
    out['testStatus']=('R176 = R175 plus Site & Release Settings in Settings › Platform Admin. '
                       'Targeted checks on this exact ZIP installed on the gate host (suites 48-r174, 49-r175 and 50-r176) are in beta-gate-evidence/r176/; the last full regression ran on R175 (beta-gate-evidence/r175/). Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
