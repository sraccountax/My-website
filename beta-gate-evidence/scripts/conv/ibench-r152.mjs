// Runs the working-tree invoice reader (TeghNativeOCR.extract) on ix152 fixtures and compares with expected.json.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const IX='/srv/gate/t/conv/ix152/';const exp=JSON.parse(fs.readFileSync(IX+'expected.json'));const verbose=process.argv.includes('-v');
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server']});
const p=await b.newPage();const logs=[];p.on('pageerror',e=>logs.push(e.message));
await p.goto('http://127.0.0.1:8765/404.html');await p.addScriptTag({url:'/assets/tegh-native-ocr-v5220.js?v='+Date.now()});
let pass=0;
for(const [k,e] of Object.entries(exp)){
  const r=await p.evaluate(async({b64})=>{const bytes=Uint8Array.from(atob(b64),c=>c.charCodeAt(0));const x=await TeghNativeOCR.extract(new File([bytes],'i.pdf',{type:'application/pdf'}));return {c:x.candidate,m:x.extractionMethod}},{b64:fs.readFileSync(IX+k+'.pdf').toString('base64')});
  const c=r.c,probs=[];
  if(c.partyName!==e.vendor)probs.push(`party ${c.partyName}`);
  if(k!=='V4'&&k!=='V5'&&c.documentNumber!==e.number)probs.push(`number ${c.documentNumber}`);
  if(e.ambiguousDate){if(c.documentDate||!c.alternatives?.some(a=>a.documentDate===e.date&&a.dateOrder==='dmy'))probs.push(`date ${c.documentDate} alts ${JSON.stringify(c.alternatives)}`)}else if(c.documentDate!==e.date)probs.push(`date ${c.documentDate}`);
  if(c.subtotalCents!==e.subtotal||c.taxCents!==e.tax||c.totalCents!==e.total)probs.push(`amounts ${c.subtotalCents}/${c.taxCents}/${c.totalCents}`);
  if((c.lineItems||[]).length!==e.lines.length)probs.push(`lines ${(c.lineItems||[]).length}/${e.lines.length}`);
  e.lines.forEach((l,i)=>{const g=c.lineItems?.[i];if(!g)return;if(g.description!==l.description)probs.push(`#${i} desc "${g.description}"`);if(g.amountCents!==l.amountCents)probs.push(`#${i} amt ${g.amountCents}`);
    if(l.quantity!=null&&(g.quantity!==l.quantity||g.unitCents!==l.unitCents||!g.checked))probs.push(`#${i} qty ${g.quantity} unit ${g.unitCents} checked ${g.checked}`)});
  if(c.lineCheck!=='subtotal')probs.push(`lineCheck ${c.lineCheck}`);
  if(!probs.length)pass++;console.log(k,r.m,probs.length?'FAIL':'PASS',probs.join(' | '));if(verbose)console.log(JSON.stringify(c));
}
// Text fallback (OCR-style line) and memory application.
const extra=await p.evaluate(()=>{const o=TeghNativeOCR;const c=o.candidateFromText('Acme Parts Ltd.\nInvoice # AP-5512\nInvoice date: 2026-02-10\nDescription Qty Price Amount\nWidget bracket 4 12.50 50.00\nHex bolts box 2 8.25 16.50 G\nSubtotal 66.50\nGST 3.33\nTotal 69.83');
  const mem=o.applySupplierMemory({documentNumber:'',documentDate:'',alternatives:[{documentDate:'2026-03-04',dateOrder:'mdy'},{documentDate:'2026-04-03',dateOrder:'dmy'}],totalCents:100,amountDueCents:150,subtotalCents:90,taxCents:10},'Ref HF/77-2096 on 2026 order 12345',{dateOrder:'dmy',numberShape:'AA/99-9999',totalSource:'amountDue'});
  return {lines:c.lineItems,check:c.lineCheck,mem}});
const okText=extra.lines.length===2&&extra.lines[1].taxHint==='G'&&extra.lines.every(l=>l.checked)&&extra.check==='subtotal';
const okMem=extra.mem.documentDate==='2026-04-03'&&extra.mem.documentNumber==='HF/77-2096'&&extra.mem.totalCents===150&&extra.mem.subtotalCents===undefined&&extra.mem.learned.length===3;
console.log('TEXT',okText?'PASS':'FAIL',JSON.stringify(extra.lines),extra.check);console.log('MEMORY',okMem?'PASS':'FAIL',JSON.stringify(extra.mem));
console.log(`${pass}/${Object.keys(exp).length} invoices; page errors ${logs.length}`,logs.slice(0,2));await b.close();
