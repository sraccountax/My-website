// R168 (browser): working in several tabs. Each tab keeps its own company; Ctrl/Cmd-click or middle-click opens a menu
// entry or a record in a new tab (same company); refresh and Back keep the screen; a link carries its company.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {sql,rec,save} from './lib.mjs';
const A='R168';
const C1=sql("SELECT id FROM companies WHERE name LIKE 'R151 Documents Inc%' ORDER BY created_at DESC LIMIT 1");
const C2=sql("SELECT c.id FROM companies c JOIN company_members m ON m.company_id=c.id JOIN users u ON u.id=m.user_id WHERE u.email='owner@gate.test' AND m.status='active' AND c.archived_at IS NULL AND c.name='Gate Test ON Corp' LIMIT 1");
const NAME=id=>sql(`SELECT name FROM companies WHERE id='${id}'`);
const SCN=sql(`SELECT id FROM accounting_notes WHERE company_id='${C1}' AND note_kind='vendor_credit' AND status='posted' ORDER BY number LIMIT 1`);
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});
const shift=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000;if(shift>0){await ctx.clock.install({time:Date.now()-shift});await ctx.clock.resume()}
const errs=[];ctx.on('page',pg=>pg.on('pageerror',e=>errs.push(e.message.slice(0,160))));
const ready=async pg=>{await pg.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar')&&document.querySelector('.srp-page[data-tegh-render-state="ready"]'),null,{timeout:45000});await pg.waitForTimeout(800)};
const waitPage=async(pg,re,ms=25000)=>{await pg.waitForFunction(src=>{const pg=[...document.querySelectorAll('.srp-page')].pop();return pg&&pg.dataset.teghRenderState==='ready'&&new RegExp(src).test(pg.dataset.srpPage)},re.source,{timeout:ms}).catch(()=>{});await pg.waitForTimeout(600)};
const company=pg=>pg.evaluate(()=>({id:localStorage.getItem('sr-accountax-company'),label:(document.querySelector('.srp-company-trigger b')?.textContent||'').trim()}));
const screen=pg=>pg.evaluate(()=>({page:[...document.querySelectorAll('.srp-page')].pop()?.dataset.srpPage||'',title:([...document.querySelectorAll('.srp-page')].pop()?.querySelector('.srp-page-head h1')?.textContent||'').trim(),hash:location.hash}));
const useCompany=async(pg,id)=>{await pg.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},id);await pg.goto('https://gate.test/app.html');await ready(pg)};
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL',e.message.slice(0,300))}};

const p1=await ctx.newPage();
await p1.goto('https://gate.test/app.html');await p1.fill('#sr-login-form input[name=email]','owner@gate.test');await p1.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p1.click('#sr-login-form button[type=submit]');
try{await p1.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:60000})}catch(e){await p1.screenshot({path:'/srv/gate/ev/shots/r168-login-fail.png'});console.log('LOGIN-FAIL',await p1.evaluate(()=>document.body.innerText.slice(0,400)));throw e}
await useCompany(p1,C1);

await t('MT-01','Two tabs, two companies: switching company in one tab leaves the other tab on its company, without reloading it',async()=>{
  const p2=await ctx.newPage();await p2.goto('https://gate.test/app.html');await ready(p2);
  const start=await company(p2);await p1.evaluate(()=>{window.__teghStillHere=1});
  await useCompany(p2,C2);await p1.waitForTimeout(1500);
  const a=await company(p1),b2=await company(p2),still=await p1.evaluate(()=>window.__teghStillHere===1);
  // The first tab's next request still goes to its own company.
  const sent=await p1.evaluate(async()=>{let h='';const orig=window.fetch;window.fetch=function(u,o){const v=o?.headers&&(o.headers['X-Company-Id']||o.headers.get?.('X-Company-Id'));if(v)h=v;return orig.apply(this,arguments)};await TeghPortal.openReport('Vendor Invoice & Note Register');await new Promise(r=>setTimeout(r,2500));window.fetch=orig;return h});
  await p2.close();
  return {ok:start.id===C1&&a.id===C1&&b2.id===C2&&still&&(sent===''||sent===C1),info:`new tab started in ${start.id===C1?'tab 1\'s company':start.id}; tab 1 ${a.label} (${a.id===C1}); tab 2 ${b2.label} (${b2.id===C2}); tab 1 not reloaded ${still}; tab 1 request company ${sent||'(header via XHR)'}`}});
await t('MT-02','Ctrl+click on a sidebar entry opens it in a new tab in the same company; the current tab stays where it was',async()=>{
  await p1.evaluate(()=>TeghPortal.openDashboard());await p1.waitForTimeout(1200);
  const parent=p1.locator('aside.sidebar nav button.srp-nav-parent',{hasText:'Payables'}).first();if(await parent.getAttribute('aria-expanded')!=='true')await parent.click();await p1.waitForTimeout(600);
  const before=await screen(p1);const pop=ctx.waitForEvent('page');
  await p1.locator('aside.sidebar .srp-subnav.is-open button',{hasText:'Vendor Ledgers'}).click({modifiers:['ControlOrMeta']});const nt=await pop;await ready(nt);await waitPage(nt,/ledger/);
  const s=await screen(nt),c=await company(nt),after=await screen(p1);await nt.close();
  return {ok:/ledger/i.test(s.page)&&c.id===C1&&after.page===before.page,info:`new tab ${s.page} "${s.title}" company ${c.label}; this tab still ${after.page}`}});
await t('MT-03','Middle-click on a note number in a report opens the note in a new tab',async()=>{
  await p1.evaluate(()=>TeghPortal.openReport('Vendor Invoice & Note Register'));await p1.waitForSelector('[data-r23-table] .r23-record-link[data-tegh-route^="document-view:note:"]',{timeout:25000});
  const pop=ctx.waitForEvent('page');await p1.locator('[data-r23-table] .r23-record-link[data-tegh-route^="document-view:note:"]').first().click({button:'middle'});const nt=await pop;await ready(nt);await waitPage(nt,/^document-view$/);
  const s=await screen(nt),here=await screen(p1);await nt.close();return {ok:s.page==='document-view'&&/Note/.test(s.title)&&here.page!=='document-view',info:`new tab ${s.page} "${s.title}"; this tab ${here.page}`}});
await t('MT-04','Refresh keeps the screen: a vendor invoice form, a note page and a report each reopen after reload, and the address still names them',async()=>{
  const out=[];
  for(const [label,go] of [['new vendor invoice',()=>p1.evaluate(()=>TeghPortal.openDocumentEditor('vendor',{kind:'invoice'}))],['note page',()=>p1.evaluate(id=>TeghPortal.openDocumentView('note',id,{side:'vendor'}),SCN)],['Payable Ageing',()=>p1.evaluate(()=>TeghPortal.openReport('Payable Ageing'))]]){
    await go();await ready(p1);await p1.waitForTimeout(800);const a=await screen(p1);await p1.reload();await ready(p1);await waitPage(p1,new RegExp('^'+a.page+'$'));const r=await screen(p1);out.push([label,a.page,r.page,a.title===r.title,a.hash.slice(0,70),r.hash.slice(0,70)])}
  return {ok:out.every(([,a,r,same,ha,hr])=>a===r&&same&&ha===hr),info:JSON.stringify(out)}});
await t('MT-05','Back returns to the previous screen',async()=>{
  await p1.evaluate(()=>TeghPortal.openReport('Payable Ageing'));await ready(p1);await p1.evaluate(id=>TeghPortal.openDocumentView('note',id,{side:'vendor'}),SCN);await ready(p1);
  await p1.goBack();await p1.waitForTimeout(3000);const s=await screen(p1);return {ok:/ageing|aging/i.test(s.page+s.title),info:`${s.page} "${s.title}"`}});
await t('MT-06','A copied link opens in the company it names, in a brand-new tab',async()=>{
  const link=await p1.evaluate(()=>location.href);const fresh=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,storageState:await ctx.storageState()});
  const p3=await fresh.newPage();await p3.goto(link);await ready(p3);await p3.waitForTimeout(2000);const c=await company(p3),s=await screen(p3);await fresh.close();
  return {ok:/co=/.test(link)&&c.id===C1,info:`link ${link.replace(/^.*#/,'#').slice(0,120)} → ${c.label} ${s.page}`}});
await t('MT-07','Refreshing after switching company in a tab keeps the new company (the old company in the address does not win)',async()=>{
  await useCompany(p1,C2);await p1.reload();await ready(p1);const c=await company(p1);await useCompany(p1,C1);return {ok:c.id===C2,info:`${c.label} ${c.id===C2}`}});
await t('MT-08','A link naming a company the person cannot open is not honoured: Tegh opens one of their own companies',async()=>{
  const fresh=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,storageState:await ctx.storageState()});const p4=await fresh.newPage();
  await p4.goto('https://gate.test/app.html#tegh=dashboard&co=company_00000000000000000000000000000000');await p4.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:45000});await p4.waitForTimeout(3000);
  await p4.waitForTimeout(3000);const c=await company(p4);const listed=await p4.evaluate(async name=>{const r=await fetch('/api/index.php?route=auth/me&v=5990',{credentials:'same-origin'});const j=await r.json();return (j.companies||[]).some(x=>x.name===name)},c.label);
  const stored=await p4.evaluate(()=>[localStorage.getItem('sr-accountax-company'),localStorage.getItem('sr-accountax-companies')]);await fresh.close();
  return {ok:listed&&!JSON.stringify(stored).includes('company_000000'),info:`opened ${c.label}; one of this person's companies (per the server) ${listed}; the unknown id kept ${JSON.stringify(stored).includes('company_000000')}`}});
await t('MT-09','No browser errors in any tab',async()=>({ok:errs.length===0,info:errs.join(' | ')||'none'}));
await b.close();save('r168.json');
