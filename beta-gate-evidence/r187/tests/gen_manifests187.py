# R187: update the three release manifests in place (R186 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests186.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='ec7cd95'  # source of the last R186 package delivered (Actions on every row, Unmatch)
TOKEN='5990-r187-batch'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R187-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (.htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r187Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R187 (private beta package, replaces R186): General Ledger lines of invoices, vendor invoices, notes, corrections, receipts and payments name '
     'the document number and the party; one Journal Entry menu (journal, expense voucher, interbank transfer, import, recurring); interbank transfers '
     'posted from Match and Post (Post as > Interbank transfer, other bank accounts under the Bank GL or 1070 clearing, linking to the other side when '
     'it is already in Tegh) and from Journal Entry; the Bank Transfers menu and the old Review Transactions page are removed; Reconcile Account lists '
     'book entries that match unposted bank lines; one Download menu (PDF, Excel, CSV, print) on reports including the Day Book; the Bank '
     'Reconciliation Report explains an empty list; cancelling an invoice edit stays on the invoice; GIFI Report under Reports > Tax Reports; every '
     'customer, vendor and GL account drop-down offers + Add new. No switch and no database change. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R186',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r186Files':
            out['r187Files']=r187Files;out['r187CacheToken']=TOKEN;out['r187RemovedFiles']=removed
            out['r187Migration']='None. No table, column or row changes. Existing journal line memos are not rewritten; only new postings carry the document reference.'
        out[k]=v
    out['release']='R187';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R186 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R187 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R187 = R186 plus the accounting and navigation batch listed in R187-CHANGES.md. '
                       'Suite 61-r187, the Canadian before/after comparison and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r187/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
