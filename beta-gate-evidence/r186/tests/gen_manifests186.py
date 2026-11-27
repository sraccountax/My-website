# R186: update the three release manifests in place (R185 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests185.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='a34606a'  # R185 evidence commit (last R185 commit)
TOKEN='5990-r186-unpost'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R186-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (R185: .htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r186Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R186 (private beta package, replaces R185): Match and Post can unpost a posted line (account postings are voided as Void does; '
     'receipts and payments of invoices and bills are unapplied and reversed on the statement date so the document is open again; '
     'matches to an earlier payment are unlinked), with the same closed-period, reconciliation, interbank and approval-limit checks as Void; '
     'unposted lines can be edited (date, amount, description, reference, remarks) or deleted from their row. Void now refuses a line posted '
     'as an invoice or bill payment, because voiding only the line left the document marked paid. No switch and no database change. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R185',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r185Files':
            out['r186Files']=r186Files;out['r186CacheToken']=TOKEN;out['r186RemovedFiles']=removed
            out['r186Migration']='None. No table, column or row changes. New PHP file api/bank_unpost_r186.php; unposting writes journal_approvals rows (source_type unpost:bank_transaction) only above the approval limit.'
        out[k]=v
    out['release']='R186';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R185 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R186 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R186 = R185 plus Unpost, Edit and Delete in Match and Post. '
                       'Suite 60-r186, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r186/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
