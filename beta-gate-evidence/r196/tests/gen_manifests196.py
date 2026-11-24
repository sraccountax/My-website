# R196: update the three release manifests in place (R195 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests187.py.
import json,subprocess,datetime,os,hashlib,re
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
BASE='2e6883d'  # source of the R195 package (the R195 ZIP, SHA-256 a96f4e42...)
TOKEN='5990-r196-easy-setup'
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R196-PRIVATE-BETA'
# The release token must be the same in the four places that carry it (.htaccess keeps those files cached).
checks={'app.html':open('app.html').read(),'.htaccess':open('.htaccess').read(),'assets/tegh-gate-v5990.js':open('assets/tegh-gate-v5990.js').read(),'assets/tegh-preflight-v5990.js':open('assets/tegh-preflight-v5990.js').read()}
for name,text in checks.items():assert TOKEN in text,(name,'release token missing')
stale=sorted(set(re.findall(r'/assets/[^"?]+\.(?:css|js)\?v=([^"]+)"',checks['app.html']))-{TOKEN});assert not stale,('app.html assets with another token',stale)
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
removed=sorted(f.removeprefix('tegh-app/') for f in subprocess.check_output(['git','diff','--name-only','--diff-filter=D',BASE,'HEAD','--','.'],text=True).splitlines() if f)
changed=subprocess.check_output(['git','diff','--name-only',BASE,'HEAD','--','.'],text=True).splitlines()
r196Files=sorted({f.removeprefix('tegh-app/') for f in changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R196 (private beta package, replaces R195): India only, GST Set-up and Payroll Set-up screens (tick the suggested rates, '
     'confirm once; add employees with three monthly amounts) and the India screens that were refused as not valid JSON now work. '
     'Schema stays 46. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R195',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r195Files':
            out['r196Files']=r196Files;out['r196CacheToken']=TOKEN;out['r196RemovedFiles']=removed
            out['r196Migration']='None. Schema stays 46.'
        out[k]=v
    out['release']='R196';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R195 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R196 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']=TOKEN
    out['testStatus']=('R196 = R195 plus the changes listed in R196-CHANGES.md. '
                       'Suite 74-r196 and the full regression on this exact ZIP installed on the gate host are in beta-gate-evidence/r196/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'removed',removed,'parent',parent[:7])
