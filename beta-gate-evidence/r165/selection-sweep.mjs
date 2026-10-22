// Sweep every report's row Actions menu: click each item and record its visible effect.
// Flags: NONE (nothing happened), ONLY-ROW-GROWS (the row just got taller/expanded), ERROR (page error).
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
const CID=process.argv[2], OUT=process.argv[3], ONLY=process.argv[4]?new RegExp(process.argv[4]):null;
const REPORTS=['Profit and Loss','Balance Sheet','Trial Balance','Cash Flow','General Ledger Account Report','General Ledger Detail','Product Activity','Day Book','Audit History','Receivable Ageing','Customer Balances','Customer Invoice & Note Register','Payable Ageing','Vendor Balances','Vendor Invoice & Note Register','Expense Register','Bank Reconciliation Summary','Bank Transaction Report','Bank General Ledger Report','Tax Code Report','GST/HST/PST Summary','Pay Run Register','Currency Exposure','Budget versus Actual','Fixed Asset Register'].filter(n=>!ONLY||ONLY.test(n));
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,acceptDownloads:true});const sh=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000;if(sh>0){await ctx.clock.install({time:Date.now()-sh});await ctx.clock.resume()}
await ctx.addInitScript(id=>{try{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))}catch{}},CID);
const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,200)));p.on('dialog',d=>d.dismiss().catch(()=>{}));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('[data-srp-page]'),null,{timeout:90000});await p.waitForTimeout(2000);
const results=[];
const state=()=>p.evaluate(()=>({page:[...document.querySelectorAll('[data-srp-page]')].map(x=>x.dataset.srpPage).pop()||'',title:(document.querySelector('.srp-page-head h1, .srp-page h1, main h1')?.textContent||'').trim(),
  dialogs:[...document.querySelectorAll('dialog[open], [role=dialog], .r23-modal, .srp-modal')].filter(d=>{if(!(d.open||d.offsetParent!==null))return false;const r=d.getBoundingClientRect();if(!r.width||!r.height)return false;const top=document.elementFromPoint(r.left+r.width/2,r.top+Math.min(r.height/2,40));return !!top&&(d.contains(top)||top.contains(d))}).length,
  toasts:[...document.querySelectorAll('.srp-toast, .toast, [data-toast]')].filter(t=>t.getClientRects().length>0).map(t=>t.textContent.trim().slice(0,80)),
  status:(document.querySelector('[data-r23-status]')?.textContent||'').trim().slice(0,120),
  expanded:document.querySelectorAll('[data-r23-table] tr.r23-detail-row, [data-r23-table] tr.r23-spill-row, [data-r23-table] tr[data-r23-details], .r23-record-details').length,
  scrollY:Math.round(window.scrollY)}));
async function openReport(name){await p.evaluate(()=>document.querySelectorAll('.srp-modal-scrim,.srp-toast,.toast,[data-toast]').forEach(n=>n.remove()));await p.evaluate(n=>TeghPortal.openReport(n),name);if(name==='General Ledger Account Report'){await p.waitForSelector('select[name=accountId]',{timeout:20000});const code=process.env.GLACCT||'2050';await p.evaluate(c=>{const s=document.querySelector('select[name=accountId]');const o=[...s.options].find(o=>o.text.trim().startsWith(c+' '));s.value=o.value;s.dispatchEvent(new Event('change',{bubbles:true}));const f=s.closest('form');(f?.querySelector('button[type=submit],button:not([type])')||f)?.click?.();if(f&&f.requestSubmit)try{f.requestSubmit()}catch{}},code);}try{await p.waitForSelector('[data-r23-table] tbody tr',{timeout:25000})}catch{return false}await p.waitForTimeout(700);return true}
function rowsAll(){return p.$$eval('[data-r23-table] tbody tr',rs=>rs.map((r,i)=>({i,text:r.innerText.replace(/\s+/g,' ').trim().slice(0,140),has:!!r.querySelector('[data-r23-select]')})).filter(r=>r.has))}
async function tick(i){const row=p.locator('[data-r23-table] tbody tr').nth(i);await row.locator('[data-r23-select]').first().check({timeout:5000});await p.waitForTimeout(300);return p.$$eval('[data-r23-selection-actions] button',ns=>ns.map(n=>n.textContent.trim()))}
for(const name of REPORTS){
  if(!(await openReport(name))){console.log('SKIP',name);continue}
  const rows=await rowsAll();const pick=[];const seen=new Set();for(const r of rows){const k=(r.text.match(/\b(SCN|SDN|CN|DN)-\d+/)?.[1])||(pick.length<1?'first':null);if(k&&!seen.has(k)){seen.add(k);pick.push(r)}if(pick.length>=4)break}
  for(const r of pick){
    if(!(await openReport(name)))break;let labels=[];try{labels=await tick(r.i)}catch(e){results.push({report:name,row:r.text,note:'tick failed '+e.message.slice(0,80)});console.log('TICKFAIL',name,r.text.slice(0,40));continue}
    for(const label of labels){
      if(!(await openReport(name)))break;try{await tick(r.i)}catch{continue}
      const before=await state();const e0=errs.length;const h0=await p.locator('[data-r23-table] tbody tr').nth(r.i).evaluate(n=>n.getBoundingClientRect().height).catch(()=>0);
      const pop=ctx.waitForEvent('page',{timeout:2500}).catch(()=>null),dl=p.waitForEvent('download',{timeout:2500}).catch(()=>null);
      await p.locator('[data-r23-selection-actions] button').getByText(label,{exact:true}).first().click({timeout:5000}).catch(()=>{});
      await p.waitForTimeout(1600);const after=await state(),np=await pop,d=await dl;
      const h1=await p.locator('[data-r23-table] tbody tr').nth(r.i).evaluate(n=>n.getBoundingClientRect().height).catch(()=>null);
      const effect=[];if(after.page!==before.page)effect.push('page:'+after.page);if(after.dialogs>before.dialogs)effect.push('dialog');if(np)effect.push('newtab');if(d)effect.push('download:'+d.suggestedFilename());
      if(after.toasts.join('|')!==before.toasts.join('|')&&after.toasts.length)effect.push('toast:'+after.toasts.join('|'));if(after.status!==before.status&&after.status)effect.push('status:'+after.status);if(after.title!==before.title)effect.push('title:'+after.title);
      const grew=h1!==null&&h0&&h1>h0+4,verdict=errs.length>e0?'ERROR':effect.length?'OK':grew?'ONLY-ROW-GROWS':'NONE';
      results.push({report:name,row:r.text.slice(0,90),label,verdict,effect,errors:errs.slice(e0)});console.log(verdict.padEnd(15),name,'|',r.text.slice(0,40),'|',label,'|',effect.join(' ; '));if(np)await np.close().catch(()=>{});
    }
  }
}
fs.writeFileSync(OUT,JSON.stringify(results,null,1));await b.close();
