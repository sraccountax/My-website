// Final functional test, part B: the large companies in the browser, single company and both together, Full Accounting and Guided.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';import {rec,save} from '../lib.mjs';import fs from 'fs';
const A='R161';const E=fs.readdirSync('/srv/gate/t/vol').filter(f=>/^expected-company_.*\.json$/.test(f)).map(f=>JSON.parse(fs.readFileSync('/srv/gate/t/vol/'+f)));
const NW=E.find(e=>e.name==='Volume Northwind Ltd'),LS=E.find(e=>e.name==='Volume Lakeshore Inc');
const money=c=>(c/100).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
const net=e=>-Object.entries(e.gl).filter(([c])=>c>='4000').reduce((s,[,v])=>s+v,0);
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,160)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
const ready=()=>p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:60000});await ready();
const select=async(ids,mode='accountant')=>{await p.evaluate(ids=>{localStorage.setItem('sr-accountax-company',ids[0]);localStorage.setItem('sr-accountax-companies',JSON.stringify(ids))},ids);await p.goto('https://gate.test/app.html');await ready();await p.evaluate(m=>TeghPortal.switchBookkeepingMode(m),mode);await p.waitForTimeout(1500)};
const settle=async()=>{const t=Date.now();await p.waitForTimeout(400);await p.waitForFunction(()=>{const x=[...document.querySelectorAll('[data-tegh-render-state]')].pop();return !x||x.dataset.teghRenderState!=='loading'},null,{timeout:60000}).catch(()=>{});await p.waitForFunction(()=>[...document.querySelectorAll('table')].some(t=>t.offsetParent&&t.querySelector('tbody tr'))||document.querySelector('.tegh-guided-review-row,.r144-brief'),null,{timeout:20000}).catch(()=>{});await p.waitForTimeout(1200);return Date.now()-t};
const text=()=>p.evaluate(()=>(document.querySelector('.srp-page:last-of-type,.stage')?.innerText||'').replace(/ /g,' '));
const rows=()=>p.evaluate(()=>{const pg=[...document.querySelectorAll('.r23-pagination span')].find(x=>x.offsetParent);const m=pg&&pg.textContent.match(/of\s+([\d,]+)/);if(m)return Number(m[1].replace(/,/g,''));const tb=[...document.querySelectorAll('table')].filter(x=>x.offsetParent&&x.querySelector('tbody tr'))[0];return tb?tb.querySelectorAll('tbody tr').length:0});
const report=async name=>{errs.length=0;await p.evaluate(n=>TeghPortal.openReport(n),name);const ms=await settle();return {ms,txt:await text(),rows:await rows(),errs:[...errs]}};
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+e.message.slice(0,300))}};
// ---- one large company, Full Accounting
await select([NW.id]);
if(!process.env.ONLY)await t('B-01',`Northwind in the browser: Profit and Loss shows the net income summed independently (${money(net(NW))})`,async()=>{const r=await report('Profit and Loss');return {ok:r.txt.includes(money(net(NW)))&&!r.errs.length,info:`${r.ms} ms; net shown ${r.txt.includes(money(net(NW)))}; errors ${r.errs.join(';')||'none'}`}});
await t('B-02',`Northwind: Receivable Ageing lists the open invoices and totals ${money(NW.arOpen)}`,async()=>{const r=await report('Receivable Ageing');return {ok:r.txt.includes(money(NW.arOpen))&&!r.errs.length,info:`${r.ms} ms; ${r.rows} rows; total shown ${r.txt.includes(money(NW.arOpen))}`}});
await t('B-03',`Northwind: the customer invoice register opens with ${NW.counts.invoices} invoices in time, and the search narrows it to one client`,async()=>{const r=await report('Customer Invoice & Note Register');
  const box=p.locator('form [name=q]:visible').first();let after=-1;if(await box.count()){await box.fill('Client 0007 ');await box.press('Enter');await settle();after=await rows()}
  const scrolled=await p.evaluate(()=>{const tb=[...document.querySelectorAll('table')].filter(x=>x.offsetParent)[0];if(!tb)return false;tb.scrollIntoView({block:'end'});return tb.getBoundingClientRect().bottom<=innerHeight+5});
  return {ok:r.rows===NW.counts.invoices&&r.ms<15000&&!r.errs.length&&after>0&&after<r.rows,info:`${r.ms} ms; ${r.rows} rows shown; after search "Client 0007": ${after}; scrolled to the last row ${scrolled}; errors ${r.errs.join(';')||'none'}`}});
await t('B-04','Northwind: the period Trial Balance screen shows equal debit and credit totals of the independently summed amount',async()=>{errs.length=0;await p.evaluate(()=>TeghPortal.openPeriodTrialBalance());const ms=await settle();const txt=await text();
  const want=money(Object.values(NW.gl).filter(v=>v>0).reduce((s,v)=>s+v,0));return {ok:txt.split(want).length>=3&&!errs.length,info:`${ms} ms; ${want} shown ${txt.split(want).length-1} times`}});
// ---- both companies together
await select([NW.id,LS.id]);
const both=net(NW)+net(LS),bothAR=NW.arOpen+LS.arOpen;
await t('B-05',`Both companies selected: Profit and Loss shows the combined net income ${money(both)}`,async()=>{const r=await report('Profit and Loss');return {ok:r.txt.includes(money(both))&&!r.errs.length,info:`${r.ms} ms; combined shown ${r.txt.includes(money(both))}; Northwind alone shown ${r.txt.includes(money(net(NW)))}`}});
await t('B-06',`Both companies: Receivable Ageing totals ${money(bothAR)} and labels each line with its company`,async()=>{const r=await report('Receivable Ageing');const co=await p.evaluate(()=>{const tb=[...document.querySelectorAll('table')].filter(x=>x.offsetParent&&x.querySelector('tbody tr'))[0];const h=[...tb.querySelectorAll('thead th')].map(x=>x.textContent.trim());const i=h.findIndex(x=>/^(Co\.?|Company)\b/i.test(x.replace(/[⋮⌄↑↓]/g,'').trim()));return i<0?[]:[...new Set([...tb.querySelectorAll('tbody tr')].map(tr=>tr.cells[i]?.textContent.trim()))]});return {ok:r.txt.includes(money(bothAR))&&co.length>=2,info:`${r.ms} ms; ${r.rows} rows; total shown ${r.txt.includes(money(bothAR))}; company labels ${co.slice(0,4).join(', ')}`}});
await t('B-07','Both companies: the all-time Day Book (over 25,000 lines) says it is too large to show complete and to narrow the filters; one month of the Day Book loads with lines from both companies',async()=>{const r=await report('Day Book');const big=/exceeds 25,000 rows/.test(r.txt);
  await p.fill('input[name=start],input[name=from]','2026-09-01').catch(()=>{});await p.fill('input[name=end],input[name=to]','2026-09-30').catch(()=>{});await p.locator('button:has-text("Generate Report"),button:has-text("Run Report"),button[type=submit]:visible').first().click();const ms=await settle();const n=await rows();
  return {ok:big&&n>0&&!errs.length,info:`all time: ${big?'clear "exceeds 25,000 rows" message':'no message'} (${r.ms} ms); September 2026: ${n} lines in ${ms} ms; errors ${errs.join(';')||'none'}`}});
// ---- Guided mode on the large company
await select([NW.id],'owner');
await t('B-08',`Guided mode with ${NW.counts.bankImported} bank lines waiting: Home shows the count, the To Do opens the review queue in time, and no amount is covered by the scrollbar`,async()=>{errs.length=0;await p.evaluate(()=>TeghPortal.openDashboard());const ms=await settle();const txt=await text();
  const n=NW.counts.bankImported,shown=txt.includes(n.toLocaleString('en-CA'));const t0=Date.now();await p.locator('.r19-attention button:has-text("bank transaction")').first().click();
  const opened=await p.waitForFunction(n=>(document.body.innerText||'').includes(n+' matching'),String(n),{timeout:45000}).then(()=>true).catch(()=>false);const lms=Date.now()-t0;await p.waitForTimeout(1500);
  const covered=await p.evaluate(()=>{let c=0;for(const el of document.querySelectorAll('.tegh-review-row strong')){const r=el.getBoundingClientRect();if(!r.width)continue;for(const x of [r.right-2]){const top=document.elementFromPoint(x,r.top+r.height/2);if(top&&top.closest('.r22-drag-scrollbar'))c++}}return c});
const stale=await p.evaluate(()=>/workspace changed while this request was loading/.test(document.body.innerText));
  await p.screenshot({path:'/srv/gate/ev/vol/guided-review-volume.png'});
  return {ok:ms<15000&&shown&&opened&&lms<15000&&!covered&&!stale&&!errs.length,info:`home ${ms} ms, count shown ${shown}; review queue "${n} matching" in ${lms} ms; amounts under the scrollbar ${covered}; stale-workspace message ${stale}; errors ${errs.join(';')||'none'}`}});
await select([NW.id],'accountant');
save('r161.json');await b.close();
// M-01: no request is left running after the browser checks (the R161 Bank General Ledger request ran 151 s, so every
// opening of that screen left a request behind, and the host backup then waited for them and gave up).
{await new Promise(r=>setTimeout(r,20000));const dir='/srv/gate/sr-accountax-private/storage/runtime/active-requests';const now=Date.now();
 const left=(fs.existsSync(dir)?fs.readdirSync(dir):[]).map(f=>({f,age:Math.round((now-fs.statSync(dir+'/'+f).mtimeMs)/1000),route:fs.readFileSync(dir+'/'+f,'utf8')})).filter(x=>x.age>15&&x.age<900);
 rec('M-01',A,'After the browser checks, no request is still running (a long-running request would hold up the host backup)',left.length?'FAIL':'PASS',left.length?left.map(x=>`${x.route} ${x.age}s`).join('; '):'none');save('r161.json')}
