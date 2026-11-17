# R181: update the three release manifests in place (R180 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R181-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D','891e26c','HEAD','--','.'],text=True).splitlines() if f)
_changed=subprocess.check_output(['git','diff','--name-only','891e26c','HEAD','--','.'],text=True).splitlines()
r181Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R181 (private beta package, replaces R180): books in any currency for companies outside Canada (Canada stays CAD) and exchange rates recorded by date; every foreign-currency document converts at the rate for its date, refuses to save without one, and asks before using a different rate. Behind the Worldwide currencies switch (off by default); existing companies keep the R180 rule. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R180',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r180Files':
            out['r181Files']=r181Files;out['r181CacheToken']='5990-r181-fx'
            out['r181Migration']='None at install. With the switch on, table company_exchange_rates and columns companies.fx_rate_policy and companies.fx_rate_max_age_days are added on first use; no existing row changes.'
        out[k]=v
    out['release']='R181';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R180 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R181 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r181-fx'
    out['testStatus']=('R181 = R180 plus worldwide currencies. '
                       'Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r181/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
