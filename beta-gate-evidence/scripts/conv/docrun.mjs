// Runs Tegh's local document extractor (new and R144) on the synthetic documents and scores fields.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const DX='/srv/gate/t/conv/dx';const X=JSON.parse(fs.readFileSync(DX+'/expected.json'));
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
export async function runAll(script){const p=await (await b.newContext({ignoreHTTPSErrors:true})).newPage();await p.goto('https://gate.test/404.html');await p.addScriptTag({url:script});
  const out={};for(const k of Object.keys(X)){const f=`${DX}/${k}.${X[k].kind==='png'?'png':'pdf'}`;const t0=Date.now();
    out[k]=await p.evaluate(async({b64,type})=>{const bytes=Uint8Array.from(atob(b64),c=>c.charCodeAt(0));try{const r=await window.TeghNativeOCR.extract(new File([bytes],'d',{type}));return {method:r.extractionMethod,c:r.candidate}}catch(e){return {method:'error: '+String(e.message).slice(0,80),c:{}}}},{b64:fs.readFileSync(f).toString('base64'),type:X[k].kind==='png'?'image/png':'application/pdf'});out[k].ms=Date.now()-t0}
  await p.close();return out}
export function score(k,c){const w=X[k].want,res={};for(const [f,v] of Object.entries(w)){let got=f==='lineItems'?(c.lineItems||[]).length:f==='alternatives'?(c.alternatives||[]).map(a=>a.documentDate):c[f]??'';res[f]={ok:v&&v.re?new RegExp(v.re).test(got):JSON.stringify(got)===JSON.stringify(v),got}}return res}
if(process.argv[1].endsWith('docrun.mjs')){for(const [label,src] of [['R144','/assets/zz-ocr-r144-compare.js'],['R145','/assets/tegh-native-ocr-v5220.js?v=r145dev'+Date.now()]]){const r=await runAll(src);let ok=0,n=0;
  for(const k of Object.keys(X)){const s=score(k,r[k].c);const bad=Object.entries(s).filter(([,v])=>!v.ok);ok+=Object.keys(s).length-bad.length;n+=Object.keys(s).length;console.log(label,k,r[k].method,r[k].ms+'ms',bad.length?'MISSES '+bad.map(([f,v])=>f+'='+JSON.stringify(v.got)).join(' '):'all fields')}
  console.log(label,'fields right',ok,'/',n)}await b.close()}
