# R188: update the three release manifests in place (R187 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests187.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='734cb41'  # source of the R187 package (the R187 ZIP, SHA-256 8069d2c7...)
TOKEN='5990-r188-india'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R188-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (.htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r188Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R188 (private beta package, replaces R187): India edition foundation and India master data behind the Site & Release Settings switch '
     'India edition (Off by default). With it on, the sign-in screen offers Edition: Canada / India; India and Canada companies never mix; an India '
     'company has INR books, an April-March year, an Indian starter chart, Indian number and date formats and no Canadian tax, payroll or GIFI; '
     'Settings > India Masters holds dated India profiles of the company, parties, items, accounts, assets and bank accounts, dated records (GST '
     'registrations, addresses, certificates, employees, pay components, warehouses and more) and statutory rule masters the user enters with source '
     'and effective date (Tegh supplies no rates; a rule is a draft until an Owner or Admin approves it). First use of the India edition adds '
     'sessions.edition, companies.edition and three India tables; Schema stays 46. Also the R187 DD-04 fix (Download menu panel no longer moves 1 px). ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R187',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r187Files':
            out['r188Files']=r188Files;out['r188CacheToken']=TOKEN;out['r188RemovedFiles']=removed
            out['r188Migration']='First-use only, when the India edition is first used: ALTER TABLE sessions/companies ADD COLUMN edition CHAR(2) NOT NULL DEFAULT CA; CREATE TABLE company_india_profile, india_entity_profiles, india_master_records. No existing column changed or removed; Schema stays 46. Nothing runs for an installation that never uses the India edition.'
        out[k]=v
    out['release']='R188';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R187 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R188 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R188 = R187 plus the India edition foundation (IN-1), India master data (IN-2) and the DD-04 fix listed in R188-CHANGES.md. '
                       'Suites 62-in1 and 63-in2, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r188/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
