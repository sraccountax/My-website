# R184: update the three release manifests in place (R183 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R184-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D','03d7fad','HEAD','--','.'],text=True).splitlines() if f)
_changed=subprocess.check_output(['git','diff','--name-only','03d7fad','HEAD','--','.'],text=True).splitlines()
r184Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R184 (private beta package, replaces R183): Report Builder under Reports (off by default; Site & Release Settings › Release › Report Builder). Users build their own read-only reports: rows with subtotals, columns side by side by period or any field, values with change and % change, filters, sort or a list of lines; a figure opens the lines behind it; reports save privately or shared with the company and are included in company backups. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R183',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r183Files':
            out['r184Files']=r184Files;out['r184CacheToken']='5990-r184-builder'
            out['r184Migration']='Table report_builder_reports is created the first time a report is saved (switch on). No existing table or row changes. Rollback: drop the table.'
        out[k]=v
    out['release']='R184';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R183 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R184 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r184-builder'
    out['testStatus']=('R184 = R183 plus the Report Builder (switch off by default). '
                       'Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r184/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
