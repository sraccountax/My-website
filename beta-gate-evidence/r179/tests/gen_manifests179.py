# R179: update the three release manifests in place (R178 manifests are the input) and write FILE-MANIFEST.sha256
# over every packaged file. Same shape as gen_manifests175.py.
import json,subprocess,datetime,os,hashlib
root='/home/user/My-website/tegh-app';os.chdir(root)
now=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
parent=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
PKG='Tegh-5_9_9-Build-5990-Schema-46-Sites-R117-Hotfix-R179-PRIVATE-BETA'
files=sorted(f for f in subprocess.check_output(['git','ls-files','-co','--exclude-standard'],text=True).split('\n') if f and f!='FILE-MANIFEST.sha256')
_changed=subprocess.check_output(['git','diff','--name-only','7a72ea0','HEAD','--','.'],text=True).splitlines()
r179Files=sorted({f.removeprefix('tegh-app/') for f in _changed if f and os.path.exists(f.removeprefix('tegh-app/'))}|{'RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json','FILE-MANIFEST.sha256'})
REV=('R179 (private beta package, replaces R178): Document Intake reads photos and scanned pages with PaddleOCR PP-OCRv5 (official models converted to ONNX, run in the browser with ONNX Runtime Web 1.30.0; Apache 2.0 and MIT notices in assets/tegh-ocr/paddle-r179/), Tesseract as the fallback; every runtime file is checked against a pinned manifest. Licences page lists the OCR components. Browser files and an .htaccess type/cache rule only. ')
for name in ['RELEASE-MANIFEST.json','PACKAGE-MANIFEST.json','tegh-build.json']:
    d=json.load(open(name))
    assert d['release']=='R178',(name,d['release'])
    out={}
    for k,v in d.items():
        if k=='r178Files':
            out['r179Files']=r179Files;out['r179CacheToken']='5990-r179-paddle'
            out['r179Migration']='None. Browser files and an .htaccess type/cache rule; no server code or database change. Upload all of assets/tegh-ocr/paddle-r179/ (about 27 MB, one 14 MB file).'
        out[k]=v
    out['release']='R179';out['package']=PKG;out['packageFile']=PKG+'.zip'
    head='Cumulative R118–R178 over the Sites R117 frontend. ';rv=str(d['revision']);assert rv.startswith(head),name
    out['revision']='Cumulative R118–R179 over the Sites R117 frontend. '+REV+rv[len(head):]
    out['generatedAt']=now;out['sourceParentCommit']=parent
    out['cacheToken']='5990-r179-paddle'
    out['testStatus']=('R179 = R178 plus PaddleOCR in Document Intake. '
                       'Full regression on this exact ZIP installed on the gate host, including suites 52-r178 and 53-r179, is in beta-gate-evidence/r179/. Previous: '+str(d['testStatus']).split(' Previous: ')[0])
    fm=out['fileManifest'];fm['entries']=len(files)
    json.dump(out,open(name,'w'),indent=2,ensure_ascii=False);open(name,'a').write('\n')
lines=[]
for f in files:
    h=hashlib.sha256(open(f,'rb').read()).hexdigest();lines.append(f'{h}  {f}')
open('FILE-MANIFEST.sha256','w').write('\n'.join(lines)+'\n')
print('entries',len(files),'parent',parent[:7])
