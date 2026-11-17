# R183: update the three release manifests in place (R182 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R183-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D','d5198eb','HEAD','--','.'],text=True).splitlines() if f)
_changed=subprocess.check_output(['git','diff','--name-only','d5198eb','HEAD','--','.'],text=True).splitlines()
r183Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R183 (private beta package, replaces R182): Bank Transaction Report has a Download button beside Generate Report (and explains a failed complete report with a support reference, offering the lines on screen as CSV) and a running Balance column; report and Match and Post lines are in date then statement order; Match and Post column headers sort, + Add transaction sits beside Post/Match, and the edit pencil is on the first line. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R182',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r182Files':
            out['r183Files']=r183Files;out['r183CacheToken']='5990-r183-bank'
            out['r183Migration']='None. No database change.'
        out[k]=v
    out['release']='R183';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R182 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R183 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r183-bank'
    out['testStatus']=('R183 = R182 plus Bank Transaction Report download and running balance, and Match and Post sorting and layout fixes. '
                       'Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r183/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
