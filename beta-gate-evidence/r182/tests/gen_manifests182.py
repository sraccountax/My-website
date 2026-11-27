# R182: update the three release manifests in place (R181 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R182-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D','c4c8a56','HEAD','--','.'],text=True).splitlines() if f)
_changed=subprocess.check_output(['git','diff','--name-only','c4c8a56','HEAD','--','.'],text=True).splitlines()
r182Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R182 (private beta package, replaces R181): issued customer invoices can be corrected (same number; original entry reversed and corrected entry posted; payments unchanged, any overpayment kept as customer credit; history and reason), behind the Edit issued invoices switch (off by default); bank lines entered by hand with monthly repeat; description edits in Match and Post and the import review; one scroll area in statement windows; a review after switching Guided/Full Accounting; a redesigned new-company page. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R181',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r181Files':
            out['r182Files']=r182Files;out['r182CacheToken']='5990-r182-edit'
            out['r182Migration']='None at install. Table invoice_revisions is created the first time an invoice is corrected (switch on); no existing table or row changes.'
        out[k]=v
    out['release']='R182';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R181 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R182 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r182-edit'
    out['testStatus']=('R182 = R181 plus invoice corrections, bank lines entered by hand and five screen improvements. '
                       'Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r182/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
