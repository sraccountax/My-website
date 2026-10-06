// R153: company address and short name (setup, Company Details, customer invoices), multi-company reports with a
// company column on every line, sidebar submenu arrows, and the light/dark/system theme switch.
// Expected figures are worked out here from the documents this test creates, never with Tegh's code.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';import {execFileSync} from 'child_process';import {S,sql,rec,check,save,need} from './lib.mjs';
const A='R153';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const stamp=Date.now().toString(36);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info||'')}catch(e){rec(id,A,title,'FAIL',e.message.replace(/\s+/g,' ').slice(0,300))}};
const mkCo=async(name,extra={})=>need('co',await o.call('companies',{method:'POST',company:false,json:{name,province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:false,coaMode:'default',...extra}})).company;

// ---------- Company profile ----------
let CA,CB;
await t('CO-01','Creating a company with an address, phone, email and short name stores them; the company list returns them with the printed address',async()=>{
  CA=await mkCo('Alpine Ledger Partners '+stamp,{addressLine1:'500 Front St W',addressLine2:'Suite 1200',city:'Toronto',postalCode:'m5v 0a1',phone:'416-555-0123',contactEmail:'office@alpine.example',shortName:'ALP'});
  const got=[CA.shortName,CA.addressLine1,CA.addressLine2,CA.city,CA.postalCode,CA.phone,CA.contactEmail,CA.addressText];
  const want=['ALP','500 Front St W','Suite 1200','Toronto','M5V 0A1','416-555-0123','office@alpine.example','500 Front St W\nSuite 1200\nToronto, ON M5V 0A1\nCanada'];
  return {ok:JSON.stringify(got)===JSON.stringify(want),info:JSON.stringify(got)}});
await t('CO-02','Without a short name, the initials of the company name are used ("Bayview Trading Associates" → BTA)',async()=>{CB=await mkCo('Bayview Trading Associates',{addressLine1:'9 Water St',city:'Halifax',postalCode:'B3J 1A1'});
  return {ok:CB.shortName==='BTA'&&CB.shortNameSet===false,info:`${CB.shortName} (set by owner: ${CB.shortNameSet})`}});
await t('CO-03','Company Details saves a new address and short name; invalid values are refused with a plain message',async()=>{
  const ok=await o.call('settings',{method:'PUT',headers:{'X-Company-Id':CB.id},company:false,json:{name:CB.name,legalName:CB.name,businessType:'corporation',province:'NS',country:'Canada',currency:'CAD',accountingBasis:'accrual',payrollPostingMode:'draft',reportingFramework:'not_set',fiscalYearEndDate:'2026-12-31',booksStartDate:'2026-01-01',taxRegistered:false,addressLine1:'10 Water St',city:'Halifax',postalCode:'B3J 1A2',shortName:'BTA2'}});
  const row=sql(`SELECT CONCAT_WS('|',short_name,address_line1,postal_code,province) FROM companies WHERE id='${CB.id}'`);
  const bad1=await o.call('settings',{method:'PUT',headers:{'X-Company-Id':CB.id},company:false,json:{name:CB.name,legalName:CB.name,businessType:'corporation',province:'NS',country:'Canada',currency:'CAD',accountingBasis:'accrual',payrollPostingMode:'draft',reportingFramework:'not_set',fiscalYearEndDate:'2026-12-31',booksStartDate:'2026-01-01',taxRegistered:false,contactEmail:'not-an-email'}});
  const bad2=await o.call('settings',{method:'PUT',headers:{'X-Company-Id':CB.id},company:false,json:{name:CB.name,legalName:CB.name,businessType:'corporation',province:'NS',country:'Canada',currency:'CAD',accountingBasis:'accrual',payrollPostingMode:'draft',reportingFramework:'not_set',fiscalYearEndDate:'2026-12-31',booksStartDate:'2026-01-01',taxRegistered:false,shortName:'THIRTEEN-CHAR'}});
  // back to BTA for the report checks below
  await o.call('settings',{method:'PUT',headers:{'X-Company-Id':CB.id},company:false,json:{name:CB.name,legalName:CB.name,businessType:'corporation',province:'NS',country:'Canada',currency:'CAD',accountingBasis:'accrual',payrollPostingMode:'draft',reportingFramework:'not_set',fiscalYearEndDate:'2026-12-31',booksStartDate:'2026-01-01',taxRegistered:false,shortName:'BTA'}});
  return {ok:ok.s===200&&row==='BTA2|10 Water St|B3J 1A2|NS'&&bad1.s===422&&/business email/i.test(bad1.b.error)&&bad2.s===422&&/12 characters/.test(bad2.b.error),info:`${ok.s} ${row}; bad email ${bad1.s} "${bad1.b.error}"; long short name ${bad2.s} "${bad2.b.error}"`}});
const cust=async(co,name)=>need('cust',await o.call('customers',{method:'POST',headers:{'X-Company-Id':co.id},company:false,json:{name,province:'ON',country:'Canada',paymentTerms:30}})).customer.id;
const invoice=async(co,customerId,cents,date='2026-09-10',templateId)=>need('inv',await o.call('invoices',{method:'POST',headers:{'X-Company-Id':co.id},company:false,json:{customerId,issueDate:date,dueDate:'2026-10-10',...(templateId?{templateId}:{}),lines:[{description:'Consulting',quantity:1,unitPriceCents:cents}],issue:true}})).invoice;
// mysql -B prints a backslash as \\; undo that before reading the stored JSON.
const snap=id=>JSON.parse(sql(`SELECT template_snapshot_json FROM invoices WHERE id='${id}'`).replace(/\\\\/g,'\\'));
let INV_A1;
await t('CO-04','A customer invoice prints the company address, phone and email (the seeded "ON, Canada" template address no longer stands in for it)',async()=>{
  const c=await cust(CA,'Northern Client '+stamp);INV_A1=await invoice(CA,c,100000);const s=snap(INV_A1.id);
  return {ok:s.businessAddress==='500 Front St W\nSuite 1200\nToronto, ON M5V 0A1\nCanada'&&s.businessPhone==='416-555-0123'&&s.businessEmail==='office@alpine.example',info:JSON.stringify([s.businessAddress,s.businessPhone,s.businessEmail])}});
await t('CO-05','A template with its own address keeps it; an issued invoice keeps the address it was issued with after the company moves',async()=>{
  const tpl=sql(`SELECT id FROM invoice_templates WHERE company_id='${CA.id}' AND name='Tegh Modern'`);sql(`UPDATE invoice_templates SET business_address='PO Box 77, Toronto ON' WHERE id='${tpl}'`);
  const c=await cust(CA,'Template Client '+stamp),inv=await invoice(CA,c,5000,'2026-09-11',tpl);const own=snap(inv.id).businessAddress;
  sql(`UPDATE companies SET address_line1='1 New Place' WHERE id='${CA.id}'`);const kept=snap(INV_A1.id).businessAddress;sql(`UPDATE companies SET address_line1='500 Front St W' WHERE id='${CA.id}'`);
  return {ok:own==='PO Box 77, Toronto ON'&&kept.startsWith('500 Front St W'),info:`template: ${own} | issued invoice after move: ${kept.split('\n')[0]}`}});

// ---------- Data for the multi-company reports (hand-worked figures) ----------
// ALP: invoices $1,000.00 (CO-04) + $50.00 (CO-05) → income $1,050.00. BTA: one invoice $250.00 → income $250.00.
// Together: income $1,300.00; receivables $1,300.00; the invoice register lists 3 invoices, 2 labelled ALP and 1 BTA.
const cB=await cust(CB,'Harbour Client '+stamp);const INV_B=await invoice(CB,cB,25000,'2026-09-12');

const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true,acceptDownloads:true});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,160)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
const ready=()=>p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await ready();
const select=async ids=>{await p.evaluate(ids=>{localStorage.setItem('sr-accountax-company',ids[0]);localStorage.setItem('sr-accountax-companies',JSON.stringify(ids))},ids);await p.goto('https://gate.test/app.html');await ready();await p.waitForTimeout(800)};
// The visible report table: its headings and, per line, the value under "Co." (if any).
const table=()=>p.evaluate(()=>{const tb=[...document.querySelectorAll('table')].filter(x=>x.offsetParent&&x.querySelector('tbody tr'))[0];if(!tb)return {head:[],cos:[],rows:0,text:''};const head=[...tb.querySelectorAll('thead th')].map(x=>x.textContent.replace(/[⋮⌄↑↓]/g,'').trim());const i=head.indexOf('Co.');
  const rows=[...tb.querySelectorAll('tbody tr')];return {head,cos:i<0?[]:rows.map(tr=>tr.cells[i]?.textContent.trim()||''),rows:rows.length,text:tb.innerText}});
const report=async name=>{await p.evaluate(n=>TeghPortal.openReport(n),name);await p.waitForTimeout(3500);return table()};

// ---------- Company Details screen ----------
await t('UI-01','Company Details shows the address block and short name; saving from the screen updates the company',async()=>{await select([CA.id]);await p.evaluate(()=>TeghPortal.invokeMenuAction('My account','Company Details'));const f=p.locator('[data-company-form]');await f.waitFor({timeout:20000}).catch(async()=>{await p.evaluate(()=>window.TeghPortal.openCompanyDetails?.());await f.waitFor({timeout:20000})});
  const vals=[await f.locator('[name=addressLine1]').inputValue(),await f.locator('[name=city]').inputValue(),await f.locator('[name=shortName]').inputValue()];await f.locator('[name=phone]').fill('416-555-0199');await f.locator('button[type=submit]').click();
  let phone='';for(let i=0;i<30&&phone!=='416-555-0199';i++){await p.waitForTimeout(300);phone=sql(`SELECT phone FROM companies WHERE id='${CA.id}'`)}await p.screenshot({path:'/srv/gate/ev/shots/r153-company-details.png'});
  return {ok:JSON.stringify(vals)===JSON.stringify(['500 Front St W','Toronto','ALP'])&&phone==='416-555-0199',info:`${vals.join(' | ')}; phone saved ${phone}`}});

// ---------- Multi-company reports ----------
await select([CA.id,CB.id]);
await t('MC-01','Customer Invoice & Note Register with both companies: every line has a Co. label — ALP twice, BTA once',async()=>{const r=await report('Customer Invoice & Note Register');await p.screenshot({path:'/srv/gate/ev/shots/r153-mc-register.png'});
  const counts={};r.cos.forEach(c=>counts[c]=(counts[c]||0)+1);return {ok:r.head.includes('Co.')&&counts.ALP===2&&counts.BTA===1&&r.rows===3,info:`${r.head.slice(0,4).join('|')} · ${JSON.stringify(counts)}`}});
await t('MC-02','Profit and Loss with both companies: lines labelled ALP and BTA and total income $1,300.00 (ALP $1,050.00 + BTA $250.00), with no notice saying the figures were withheld',async()=>{const r=await report('Profit and Loss');await p.screenshot({path:'/srv/gate/ev/shots/r153-mc-pl.png'});
  const page=await p.locator('.srp-page:visible').first().innerText();const contradicts=/No partial consolidated figures|Select one company/i.test(page);
  return {ok:r.head.includes('Co.')&&r.cos.includes('ALP')&&r.cos.includes('BTA')&&/Total Income[^\n]*1,300\.00/.test(r.text)&&!contradicts,info:(r.text.match(/Total Income[^\n]*/)||[''])[0]+` | page says "select one company": ${contradicts}`}});
await t('MC-03','Receivable Ageing with both companies: the three open invoices are listed with their company',async()=>{const r=await report('Receivable Ageing');const lines=r.cos.filter(c=>['ALP','BTA'].includes(c));
  return {ok:r.head.includes('Co.')&&lines.filter(c=>c==='ALP').length===2&&lines.filter(c=>c==='BTA').length===1,info:JSON.stringify(r.cos)}});
await t('MC-04','Day Book with both companies: each voucher row shows its company; the two companies\' entries are not merged',async()=>{const r=await report('Day Book');await p.screenshot({path:'/srv/gate/ev/shots/r153-mc-daybook.png'});
  return {ok:r.head[0]==='Co.'&&r.cos.filter(c=>c==='ALP').length>=2&&r.cos.filter(c=>c==='BTA').length>=1,info:JSON.stringify(r.cos)}});
await t('MC-05','Audit History, Tax Code Report and Trial Balance with both companies show the Co. column with both labels',async()=>{const out=[];for(const n of ['Audit History','Tax Code Report','Trial Balance']){const r=await report(n);out.push([n,r.head.includes('Co.'),r.cos.includes('ALP'),r.cos.includes('BTA')])}
  return {ok:out.every(x=>x[1]&&x[2]&&x[3]),info:JSON.stringify(out)}});
// R154 replaced the R153 "select one company" notice on these three reports with combined screens (checked in 23-r154).
await t('MC-06','Reports about one GL or bank account, and Budgets, never show only the first company\'s figures: each opens its combined screen (R154)',async()=>{const out=[];for(const [n,sel] of [['General Ledger Account Report','[data-gl-ledger-filter] select[name=code]'],['Bank General Ledger Report','[data-r154-bank]'],['Budget versus Actual','[data-r154-budget-list]']]){await p.evaluate(x=>TeghPortal.openReport(x),n);await p.waitForTimeout(2500);out.push([n,await p.locator(sel).count()>0,await p.locator('.r153-one-company:visible').count()])}
  return {ok:out.every(x=>x[1]&&x[2]===0),info:JSON.stringify(out)}});
await t('MC-07','The Excel export of the combined register contains the Co. column and both companies',async()=>{await report('Customer Invoice & Note Register');let file='';
  await p.locator('button[aria-label="Table actions"]:visible').first().click();await p.locator('[role=menu] button:visible,[role=menuitem]:visible,.r23-menu button:visible',{hasText:/^Export/}).first().click();
  const dialog=p.locator('dialog[open]').last();await dialog.locator('select').first().selectOption('xlsx');
  const dl=p.waitForEvent('download',{timeout:20000});await dialog.locator('button',{hasText:/^Export$/}).click();const d=await dl;file='/tmp/r153-export.xlsx';await d.saveAs(file);
  const xml=execFileSync('unzip',['-p',file],{encoding:'latin1',maxBuffer:50e6});fs.unlinkSync(file);return {ok:/>Co\.</.test(xml)&&xml.includes('ALP')&&xml.includes('BTA'),info:`${d.suggestedFilename()}: Co. ${/>Co\.</.test(xml)}, ALP ${xml.includes('ALP')}, BTA ${xml.includes('BTA')}`}});
await t('MC-08','With one company selected, reports are unchanged: no Co. column',async()=>{await select([CA.id]);const r=await report('Customer Invoice & Note Register');const r2=await report('Profit and Loss');return {ok:!r.head.includes('Co.')&&!r2.head.includes('Co.')&&r.rows===2,info:`${r.head.slice(0,3).join('|')} · ${r.rows} rows`}});

// ---------- Sidebar arrows ----------
const st=()=>p.evaluate(()=>Object.fromEntries([...document.querySelectorAll('.sidebar .srp-nav-parent')].map(x=>[x.dataset.srpLabel,x.getAttribute('aria-expanded')])));
const par=l=>p.locator(`.sidebar .srp-nav-parent[data-srp-label="${l}"]`);
await t('NAV-01','The arrow beside a module opens and closes its submenu without leaving the page, and two submenus can be open at once',async()=>{await p.evaluate(()=>TeghPortal.invokeMenuAction('Payables','Vendors')).catch(()=>{});await p.waitForTimeout(800);
  const title=await p.locator('h1:visible').first().textContent();await par('Banking').locator('.srp-chevron').click();await p.waitForTimeout(300);await par('Receivables').locator('.srp-chevron').click();await p.waitForTimeout(300);const s1=await st();const title2=await p.locator('h1:visible').first().textContent();
  await par('Banking').locator('.srp-chevron').click();await p.waitForTimeout(300);const s2=await st();const box=await par('Banking').locator('.srp-chevron').boundingBox();await p.screenshot({path:'/srv/gate/ev/shots/r153-sidebar-arrows.png'});
  return {ok:s1.Banking==='true'&&s1.Receivables==='true'&&title===title2&&s2.Banking==='false'&&s2.Receivables==='true'&&box.width>=24&&box.height>=24,info:`after opening: Banking ${s1.Banking}, Receivables ${s1.Receivables}; page stayed "${title2}"; after closing Banking: ${s2.Banking}; arrow ${Math.round(box.width)}×${Math.round(box.height)}`}});
await t('NAV-02','A submenu closed with its arrow stays closed while moving between pages of that module; clicking the module name opens it again',async()=>{await par('Payables').click();await p.waitForTimeout(800);await par('Payables').locator('.srp-chevron').click();await p.waitForTimeout(300);
  await p.evaluate(()=>TeghPortal.invokeMenuAction('Payables','Vendors')).catch(()=>{});await p.waitForTimeout(800);const closed=(await st()).Payables;await par('Payables').click();await p.waitForTimeout(800);const reopened=(await st()).Payables;
  return {ok:closed==='false'&&reopened==='true',info:`after navigating: ${closed}; after clicking the name: ${reopened}`}});

// ---------- Theme switch ----------
const themeState=()=>p.evaluate(()=>({theme:document.documentElement.dataset.teghTheme,choice:document.documentElement.dataset.teghThemeChoice,pressed:[...document.querySelectorAll('[data-theme-choice]')].filter(x=>x.getAttribute('aria-pressed')==='true').map(x=>x.dataset.themeChoice)}));
await t('TH-01','The top bar has Light, Dark and System icon buttons; Dark switches the app to dark and is remembered after reload; System follows the device fully (dark page on a dark device, light when it turns light)',async()=>{
  const labels=await p.locator('[data-theme-choice]').evaluateAll(xs=>xs.map(x=>x.getAttribute('aria-label')+(x.querySelector('svg')?':icon':'')));
  await p.click('[data-theme-choice="dark"]');await p.waitForTimeout(400);const dark=await themeState();await p.screenshot({path:'/srv/gate/ev/shots/r153-theme-dark.png'});await p.reload();await ready();await p.waitForTimeout(800);const after=await themeState();
  await p.emulateMedia({colorScheme:'dark'});await p.click('[data-theme-choice="auto"]');await p.waitForTimeout(400);const sys=await themeState();const bg=await p.evaluate(()=>{const c=getComputedStyle(document.body).backgroundColor.match(/\d+/g).map(Number);return c[0]+c[1]+c[2]});await p.screenshot({path:'/srv/gate/ev/shots/r153-theme-system-dark.png'});
  await p.emulateMedia({colorScheme:'light'});await p.waitForTimeout(400);const sysLight=await themeState();
  await p.emulateMedia({colorScheme:'light'});await p.click('[data-theme-choice="light"]');await p.waitForTimeout(400);const light=await themeState();await p.screenshot({path:'/srv/gate/ev/shots/r153-theme-light.png'});
  return {ok:JSON.stringify(labels)===JSON.stringify(['Light theme:icon','Dark theme:icon','System theme:icon'])&&dark.theme==='dark'&&after.theme==='dark'&&after.pressed[0]==='dark'&&sys.choice==='auto'&&sys.theme==='dark'&&bg<120&&sys.pressed[0]==='auto'&&sysLight.theme==='light'&&light.theme==='light',info:`${labels.join(',')} · dark ${dark.theme} → after reload ${after.theme} · System on a dark device: ${sys.theme} (page brightness ${bg}), device turns light: ${sysLight.theme} · Light ${light.theme}`}});
await t('TH-02','On a phone the switch shows only the current icon and a tap moves Light → Dark → System → Light',async()=>{const q=await ctx.newPage();await q.setViewportSize({width:390,height:844});await q.goto('https://gate.test/app.html');await q.waitForFunction(()=>document.querySelector('[data-theme-choice]'),null,{timeout:40000});await q.waitForTimeout(800);
  const seq=[];for(let i=0;i<3;i++){await q.click('[data-theme-choice].is-active');await q.waitForTimeout(300);seq.push(await q.evaluate(()=>[document.documentElement.dataset.teghThemeChoice,[...document.querySelectorAll('[data-theme-choice]')].filter(x=>x.offsetParent).length].join(':')))}
  await q.screenshot({path:'/srv/gate/ev/shots/r153-theme-phone.png',clip:{x:0,y:0,width:390,height:70}});await q.close();return {ok:JSON.stringify(seq)===JSON.stringify(['dark:1','auto:1','light:1']),info:JSON.stringify(seq)}});
await t('TH-03','The Appearance page shows the same Light, Dark and System icons',async()=>{await p.evaluate(()=>TeghPortal.openTeghPreferences?TeghPortal.openTeghPreferences():TeghPortal.invokeMenuAction('My account','Appearance'));await p.waitForTimeout(1200);
  const n=await p.locator('input[name=theme]').evaluateAll(xs=>xs.map(x=>x.closest('label')?.querySelector('svg')?1:0).reduce((a,b)=>a+b,0));const txt=await p.locator('.tegh-interface-choice',{has:p.locator('input[name=theme]')}).first().innerText().catch(()=>'');return {ok:n===3&&/System/.test(txt),info:`${n} icons; ${txt.replace(/\s+/g,' ').slice(0,80)}`}});

rec('R153-JS',A,'No page errors during the R153 journeys',errs.length?'FAIL':'PASS',errs.slice(0,3).join(' | '));
await b.close();save('r153.json');
