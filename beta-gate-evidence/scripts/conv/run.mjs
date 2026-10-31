// Runs Tegh's real PDF statement converter (browser) on PDF files and writes the parsed rows to JSON.
// Usage: node run.mjs <pdf> <out.json> [layout] [dateOrder]
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const [,,pdf,out,layout='auto',dateOrder='auto',acct='']=process.argv;
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({ignoreHTTPSErrors:true});const p=await ctx.newPage();const logs=[];p.on('pageerror',e=>logs.push(e.message));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});
const b64=fs.readFileSync(pdf).toString('base64');
const res=await p.evaluate(async({b64,layout,dateOrder,acct,name})=>{
  const bytes=Uint8Array.from(atob(b64),c=>c.charCodeAt(0));const file=new File([bytes],name,{type:'application/pdf'});
  if(globalThis.TeghLoadFeature)await globalThis.TeghLoadFeature('ocr');else await import('/assets/tegh-native-ocr-v5220.js');
  const t0=performance.now();const pageData=await globalThis.TeghNativeOCR.extractStatementPages(file,{onPassword:()=>null,onProgress:()=>{}});
  const mod=await import('/assets/tegh-bank-converter-v5990.js?v=harness'+Date.now());
  const parsed=mod.convertPages(pageData.pages,{layout,dateOrder,accountType:acct});const ms=Math.round(performance.now()-t0);
  return {ms,methods:pageData.pages.map(x=>x.method),pageCount:pageData.pageCount,keys:Object.keys(parsed),opening:parsed.openingBalance??null,closing:parsed.closingBalance??null,
    rows:parsed.rows.map(r=>({date:r.date,desc:String(r.description||'').slice(0,60),amount:r.amount,debit:r.debit,credit:r.credit,balance:r.balance,manual:!!r.needsManualAmount,conf:r.confidence,issues:String(r.issues||'').slice(0,80),page:r.page})),check:parsed.layoutCheck?{balanced:parsed.layoutCheck.balanced,pagesOk:parsed.layoutCheck.pagesOk,text:mod.balanceCheckText(parsed.layoutCheck).replace(/[\d,]+\.\d{2}/g,'#')}:null};
},{b64,layout,dateOrder,acct,name:pdf.split('/').pop()});
res.logs=logs;fs.writeFileSync(out,JSON.stringify(res,null,1));
const n=res.rows.length,man=res.rows.filter(r=>r.manual).length,sum=res.rows.reduce((s,r)=>s+(r.manual?0:Math.round(r.amount*100)),0);
console.log(`${pdf.split('/').pop()}: ${n} rows, ${man} need manual amount, net ${(sum/100).toFixed(2)}, check ${JSON.stringify(res.check)}, ${res.ms} ms, methods ${[...new Set(res.methods)].join(',')}, errors ${logs.length}`);
await b.close();
