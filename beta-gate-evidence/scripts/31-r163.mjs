// R163: Guided statements by month, keyword mapping, posting, automatic reconciliation, the next step and Connect with an
// accountant. Every expected figure is worked out here from the CSV text (13% HST included in the bank amount:
// net = round(gross × 100 / 113), tax = gross − net), never with Tegh's own calculations.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';import {rec,save,sql,S,need,mails} from './lib.mjs';import {execFileSync} from 'child_process';import fs from 'fs';
const A='R163';const DIR='/srv/gate/t/r163';fs.mkdirSync(DIR,{recursive:true});
execFileSync('mysql',['tegh_gate','-e','DELETE FROM login_attempts']);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).replace(/\x1b\[[0-9;]*m/g,'').slice(0,400))}};
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const tag=Date.now().toString(36);
const mkCo=async(name,fye)=>{const co=need('company',await o.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:fye,booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));const id=co.company?.id||co.id;return {id,bank:sql(`SELECT id FROM bank_accounts WHERE company_id='${id}' AND account_type='bank' ORDER BY created_at LIMIT 1`)}};
const as=cid=>{o.cid=cid;return o};
const acct=(cid,code)=>sql(`SELECT id FROM accounts WHERE company_id='${cid}' AND code='${code}'`);
const gl=cid=>Object.fromEntries((sql(`SELECT a.code,SUM(l.debit_cents-l.credit_cents) FROM journal_lines l JOIN journal_entries j ON j.id=l.journal_entry_id AND j.status='posted' AND j.voided_at IS NULL JOIN accounts a ON a.id=l.account_id WHERE j.company_id='${cid}' GROUP BY a.code HAVING SUM(l.debit_cents-l.credit_cents)<>0 ORDER BY a.code`)||'').split('\n').filter(Boolean).map(x=>{const [c,v]=x.split('\t');return [c,Number(v)]}));
const preview=async(cid,bank,csv,name)=>{as(cid);const data=Buffer.from(csv);const up=need('upload-start',await o.call('imports/upload-start',{method:'POST',json:{filename:name,size:data.length}}));
  for(let off=0;off<data.length;off+=393216)need('upload-chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:up.uploadId,offset:off,data:data.subarray(off,off+393216).toString('base64')}}));
  return need('upload-finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:up.uploadId,bankAccountId:bank,exchangeRateMicros:1000000,mode:'preview'}})).preview};
const approve=async(cid,pv)=>{as(cid);const keys=(pv.rows||[]).filter(x=>x.selectionKey&&x.eligible!==false&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
  return need('approve',await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.id,operationKey:'r163-'+Date.now()+Math.random(),selectedKeys:keys,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}}),[200,201]).batch};
const cents=s=>Math.round(Number(s)*100);const net=g=>Math.round(g*100/113);
const parse=csv=>csv.trim().split('\n').slice(1).map(l=>{const [d,desc,amt]=l.split(',');return {d,desc,c:cents(amt)}});
// A statement issued in February 2026: one line from the previous fiscal year, last line on February 3.
const FEB=`Date,Description,Amount\n2025-12-30,ROGERS WIRELESS 8000,-56.50\n2026-01-05,STAPLES OFFICE 1,-113.00\n2026-01-10,CLIENT DEPOSIT ACME,565.00\n2026-01-12,ROGERS WIRELESS 8001,-56.50\n2026-01-28,MONTHLY ACCOUNT FEE,-25.00\n2026-02-03,UNKNOWN THING 77,-10.00\n`;
const feb=parse(FEB);const FYS='2026-01-01',FYE='2026-12-31';

const C=await mkCo('R163 Gate '+tag,'12-31');const CID=C.id;
// R-01: the fiscal year has 12 statement months, January to December.
await t('R-01','Guided statements list the 12 months of the fiscal year (January to December 2026 for a December year end)',async()=>{const r=need('months',await as(CID).call('guided/statement-months'));
  const want=Array.from({length:12},(_,i)=>`2026-${String(i+1).padStart(2,'0')}`);const got=r.months.map(m=>m.month);
  return {ok:JSON.stringify(got)===JSON.stringify(want)&&r.fiscalYear.start===FYS&&r.fiscalYear.end===FYE&&r.months.every(m=>m.status==='empty')&&r.bankAccountId===C.bank,info:`FY ${r.fiscalYear.start}..${r.fiscalYear.end}; months ${got.join(',')}`}});
await t('R-02','A March 31 year end gives April to March; the current year (clock 2026-10-01) is April 2026 to March 2027',async()=>{const M=await mkCo('R163 March '+tag,'03-31');const r=need('months',await as(M.id).call('guided/statement-months'));
  const want=[];for(let i=0;i<12;i++){const m=(3+i)%12+1,y=2026+((3+i)>=12?1:0);want.push(`${y}-${String(m).padStart(2,'0')}`)}const got=r.months.map(m=>m.month);
  const r2=need('months',await o.call('guided/statement-months?fiscalYearEnd=2026-03-31'));
  return {ok:JSON.stringify(got)===JSON.stringify(want)&&r.fiscalYear.end==='2027-03-31'&&r2.months[0].month==='2025-04'&&r2.months[11].month==='2026-03'&&r.fiscalYears.some(y=>y.end==='2026-03-31'),info:`current ${got[0]}..${got[11]}; earlier ${r2.months[0].month}..${r2.months[11].month}; years ${r.fiscalYears.map(y=>y.end).join(',')}`}});

let PV,BATCH,MONTH;
await t('R-03','A statement whose last line is February 3 is refused for May and the problem names February; February and March (issued early March) are accepted',async()=>{PV=await preview(CID,C.bank,FEB,'feb.csv');
  const chk=m=>o.call('guided/statement-months/check',{method:'POST',json:{previewId:PV.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:m}});
  const may=need('check may',await chk('2026-05')),f=need('check feb',await chk('2026-02')),mar=need('check mar',await chk('2026-03')),jan=need('check jan',await chk('2026-01'));
  return {ok:!may.ok&&/February 2026/.test(may.problems.join(' '))&&may.suggestedMonth==='2026-02'&&f.ok&&mar.ok&&!jan.ok,info:`May ok=${may.ok} "${may.problems[0]?.slice(0,120)}"; Feb ${f.ok}; Mar ${mar.ok}; Jan ${jan.ok}`}});
await t('R-04','The check counts lines outside the fiscal year from the statement dates: 1 before January 1, 2026 and 0 after December 31, 2026',async()=>{
  const before=feb.filter(l=>l.d<FYS).length,after=feb.filter(l=>l.d>FYE).length;const f=need('check',await as(CID).call('guided/statement-months/check',{method:'POST',json:{previewId:PV.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-02'}}));
  return {ok:f.beforeFiscalYear===before&&f.afterFiscalYear===after&&f.lineCount===feb.length&&f.firstDate==='2025-12-30'&&f.lastDate==='2026-02-03',info:`before ${f.beforeFiscalYear} (want ${before}); after ${f.afterFiscalYear} (want ${after}); lines ${f.lineCount}; ${f.firstDate}..${f.lastDate}`}});
await t('R-05','After import the statement is recorded as February: 6 lines, coverage Dec 30 to Feb 3, and the month shows "uploaded"',async()=>{BATCH=await approve(CID,PV);
  const at=need('attach',await as(CID).call('guided/statement-months/attach',{method:'POST',json:{batchId:BATCH.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-02'}}));MONTH=at.id;
  const st=need('months',await o.call('guided/statement-months'));const m=st.months.find(x=>x.month==='2026-02');
  return {ok:at.lineCount===6&&at.beforeFiscalYear===1&&at.afterFiscalYear===0&&m.status==='uploaded'&&m.pending===6&&m.coverageStart==='2025-12-30'&&m.coverageEnd==='2026-02-03'&&st.months.filter(x=>x.status!=='empty').length===1,info:JSON.stringify(m)}});
await t('R-06','One statement per month: a second statement for February is refused at the check and at recording; the same import cannot be recorded twice',async()=>{
  const pv2=await preview(CID,C.bank,`Date,Description,Amount\n2026-02-10,BANK FEE X,-1.00\n`,'feb2.csv');const chk=need('check',await o.call('guided/statement-months/check',{method:'POST',json:{previewId:pv2.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-02'}}));
  const again=await o.call('guided/statement-months/attach',{method:'POST',json:{batchId:BATCH.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-03'}});
  await o.call('operations/statement-preview',{method:'DELETE',json:{previewId:pv2.id}}).catch(()=>{});
  return {ok:!chk.ok&&/already uploaded/.test(chk.problems.join(' '))&&again.s===409,info:`check "${chk.problems[0]}"; re-attach ${again.s} ${again.b?.code||''}`}});
let MAP;
await t('R-07','Mapping groups the lines by keyword and direction: ROGERS WIRELESS (2), STAPLES OFFICE, CLIENT DEPOSIT, MONTHLY ACCOUNT, UNKNOWN THING; 5 lines mapped, 1 not mapped',async()=>{MAP=need('mapping',await as(CID).call('guided/mapping?monthId='+MONTH));
  const g=Object.fromEntries(MAP.groups.map(x=>[x.key,x]));const want={'ROGERS WIRELESS|out':['6300',2],'STAPLES OFFICE|out':['6400',1],'CLIENT DEPOSIT|in':['4000',1],'MONTHLY ACCOUNT|out':['6800',1],'UNKNOWN THING|out':[null,1]};
  const bad=Object.entries(want).filter(([k,[code,n]])=>!g[k]||g[k].count!==n||(code?!String(g[k].suggestedAccount||'').startsWith(code+' '):g[k].suggestedAccountId!==null));
  const sums=MAP.groups.every(x=>x.pendingCents===feb.filter(l=>l.desc.startsWith(x.keyword)&&(l.c>=0)===(x.direction==='in')).reduce((s,l)=>s+l.c,0));
  return {ok:!bad.length&&MAP.groups.length===5&&MAP.totals.lines===6&&MAP.totals.mapped===5&&MAP.totals.unmapped===1&&MAP.totals.pending===6&&sums&&/^CODE:/.test(g['ROGERS WIRELESS|out'].suggestedTax),info:`groups ${MAP.groups.map(x=>`${x.key}:${x.count}:${x.suggestedAccount}:${x.suggestedTax}`).join('; ')}; totals ${JSON.stringify(MAP.totals)}; bad ${bad.map(b=>b[0]).join(',')||'none'}`}});
await t('R-08','Reconciling before every line is posted is refused and says how many lines are left',async()=>{const r=need('reconcile',await as(CID).call('guided/reconcile',{method:'POST',json:{monthId:MONTH}}));
  return {ok:r.reconciled===false&&/6 lines are not posted/.test(r.reason||''),info:JSON.stringify(r)}});
await t('R-09','The group "Lines" view lists both Rogers lines with dates and amounts',async()=>{const r=need('lines',await as(CID).call('guided/group-lines?monthId='+MONTH+'&group='+encodeURIComponent('ROGERS WIRELESS|out')));
  return {ok:r.total===2&&r.lines.map(l=>l.description).sort().join()==='ROGERS WIRELESS 8000,ROGERS WIRELESS 8001'&&r.lines.every(l=>l.amountCents===-5650),info:JSON.stringify(r.lines.map(l=>[l.date,l.description,l.amountCents]))}});
// The person changes the deposit to "HST included" and chooses 6999 for the unknown line (posted as a single-line change).
const HST=()=>MAP.groups.find(x=>x.key==='ROGERS WIRELESS|out').suggestedTax;
const EXP=(()=>{const e={};const add=(c,v)=>e[c]=(e[c]||0)+v;for(const l of feb){add('1000',l.c);const g=-l.c;
  if(/ROGERS/.test(l.desc)){add('6300',net(g));add('1100',g-net(g))}else if(/STAPLES/.test(l.desc)){add('6400',net(g));add('1100',g-net(g))}
  else if(/DEPOSIT/.test(l.desc)){add('4000',-net(l.c));add('2100',-(l.c-net(l.c)))}else if(/ACCOUNT FEE/.test(l.desc))add('6800',g);else add('6999',g)}return e})();
await t('R-10','Posting the groups (the deposit changed to HST included, the unknown line posted on its own to 6999) gives the hand-worked ledger: bank 304.00, HST paid 26.00, HST collected 65.00, sales 500.00',async()=>{as(CID);let calls=0;
  const one=need('post-lines',await o.call('guided/post-lines',{method:'POST',json:{monthId:MONTH,lines:[{id:sql(`SELECT id FROM bank_transactions WHERE company_id='${CID}' AND description='UNKNOWN THING 77'`),accountId:acct(CID,'6999'),tax:'NO_TAX'}]}}));
  for(const g of MAP.groups){if(g.key==='UNKNOWN THING|out')continue;const tax=g.key==='CLIENT DEPOSIT|in'?HST():g.suggestedTax;let r;do{r=need('post-group',await o.call('guided/post-group',{method:'POST',json:{monthId:MONTH,groupKey:g.key,accountId:g.suggestedAccountId,tax}}));calls++}while(r.remaining>0)}
  const got=gl(CID);const ok=Object.keys({...got,...EXP}).every(k=>(got[k]||0)===(EXP[k]||0));
  const m=need('mapping',await o.call('guided/mapping?monthId='+MONTH));
  return {ok:ok&&one.posted===1&&m.totals.posted===6&&m.totals.pending===0&&EXP['1000']===30400,info:`GL ${JSON.stringify(got)}; want ${JSON.stringify(EXP)}; calls ${calls}`}});
let RECON;
await t('R-11','Reconcile completes the bank reconciliation for Dec 30, 2025 to Feb 3, 2026 with difference 0 and all 6 lines cleared; the month shows "reconciled"',async()=>{const r=need('reconcile',await as(CID).call('guided/reconcile',{method:'POST',json:{monthId:MONTH}}));RECON=r.reconciliationId;
  const row=sql(`SELECT status,difference_cents,period_start,period_end,(SELECT COUNT(*) FROM reconciliation_items i WHERE i.reconciliation_id=r.id) FROM reconciliations r WHERE id='${RECON}' AND company_id='${CID}'`).split('\t');
  const m=need('months',await o.call('guided/statement-months')).months.find(x=>x.month==='2026-02');const again=need('again',await o.call('guided/reconcile',{method:'POST',json:{monthId:MONTH}}));
  return {ok:r.reconciled&&row[0]==='complete'&&row[1]==='0'&&row[2]==='2025-12-30'&&row[3]==='2026-02-03'&&row[4]==='6'&&m.status==='reconciled'&&again.reconciled&&again.reconciliationId===RECON,info:`recon ${row.join(' ')}; month ${m.status}; repeat ${again.reconciliationId===RECON}`}});
await t('R-12','The reconciliation report for that period agrees: adjusted balances equal, difference 0.00',async()=>{const r=need('report',await as(CID).call('operations/reconciliation-report?reconciliationId='+RECON),[200]);const b=r.balances||r.report?.balances||{};
  return {ok:Number(b.differenceCents)===0&&Number(b.adjustedBankCents)===Number(b.adjustedBookCents)&&Number(b.bookBalanceCents)===30400,info:JSON.stringify(b).slice(0,300)}});
let MAR;
await t('R-13','An unposted statement can be removed (its lines are deleted and the month is free); a posted one cannot',async()=>{const pv=await preview(CID,C.bank,`Date,Description,Amount\n2026-03-04,ROGERS WIRELESS 9000,-56.50\n2026-03-20,MONTHLY ACCOUNT FEE,-25.00\n`,'mar.csv');const b=await approve(CID,pv);
  MAR=need('attach',await as(CID).call('guided/statement-months/attach',{method:'POST',json:{batchId:b.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-03'}})).id;
  const rm=need('remove',await o.call('guided/statement-months/remove',{method:'POST',json:{monthId:MAR}}));const left=sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CID}' AND import_batch_id='${b.id}'`);
  const st=need('months',await o.call('guided/statement-months')).months.find(x=>x.month==='2026-03');const febRm=await o.call('guided/statement-months/remove',{method:'POST',json:{monthId:MONTH}});
  return {ok:rm.removed&&rm.linesDeleted===2&&left==='0'&&st.status==='empty'&&febRm.s===409,info:`removed ${rm.linesDeleted}; left ${left}; March ${st.status}; remove Feb ${febRm.s} ${febRm.b?.code}`}});
await t('R-14','Company isolation: another company cannot read the mapping, post, reconcile or remove this company\'s statement month (404), nor record its import (refused)',async()=>{const X=await mkCo('R163 Other '+tag,'12-31');as(X.id);
  const r=[await o.call('guided/mapping?monthId='+MONTH),await o.call('guided/group-lines?monthId='+MONTH+'&group=x'),await o.call('guided/post-group',{method:'POST',json:{monthId:MONTH,groupKey:'ROGERS WIRELESS|out',accountId:acct(X.id,'6300'),tax:'NO_TAX'}}),await o.call('guided/reconcile',{method:'POST',json:{monthId:MONTH}}),await o.call('guided/statement-months/remove',{method:'POST',json:{monthId:MONTH}}),await o.call('guided/statement-months/attach',{method:'POST',json:{batchId:BATCH.id,bankAccountId:C.bank,fiscalYearEnd:FYE,month:'2026-04'}})];
  return {ok:r.slice(0,5).every(x=>x.s===404)&&[404,422].includes(r[5].s)&&sql(`SELECT COUNT(*) FROM guided_statement_months WHERE company_id='${X.id}'`)==='0'&&sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CID}' AND status='posted'`)==='6',info:r.map(x=>x.s+(x.b?.code?' '+x.b.code:'')).join(', ')}});

// Large statement: 5,000 lines, 10 merchants. Accounts and tax are the test's own table.
const MER=[['ROGERS WIRELESS',-5650,'6300',1],['STAPLES OFFICE',-11300,'6400',1],['ADOBE SYSTEMS',-3390,'6200',1],['MONTHLY ACCOUNT FEE',-2500,'6800',0],['CLIENT DEPOSIT',56500,'4000',1],['TIM HORTONS',-1250,'6600',0],['ESSO STATION',-6780,'6900',1],['HYDRO ONE',-9040,'6999',1],['INTACT INS',-15000,'6950',0],['UBER TRIP',-2300,'6500',0]];
const L=await mkCo('R163 Large '+tag,'12-31');let big='Date,Description,Amount\n';const bigLines=[];for(let i=0;i<5000;i++){const [m,c,code,tx]=MER[i%MER.length];const d=`2026-04-${String(1+(i%28)).padStart(2,'0')}`;bigLines.push({d,c,code,tx,m});big+=`${d},${m} ${1000+i},${(c/100).toFixed(2)}\n`}
let BIGM,timing={};
await t('R-15','A 5,000-line statement is grouped into 10 keyword groups (500 lines each) in under 10 s, with the suggested account for the 9 known merchants',async()=>{let t0=Date.now();const pv=await preview(L.id,L.bank,big,'apr.csv');const b=await approve(L.id,pv);timing.import=Date.now()-t0;
  BIGM=need('attach',await as(L.id).call('guided/statement-months/attach',{method:'POST',json:{batchId:b.id,bankAccountId:L.bank,fiscalYearEnd:FYE,month:'2026-04'}})).id;
  t0=Date.now();const mp=need('mapping',await o.call('guided/mapping?monthId='+BIGM));timing.mapping=Date.now()-t0;
  const sugg=MER.filter(m=>m[0]!=='HYDRO ONE').every(([m,,code])=>String(mp.groups.find(g=>m.startsWith(g.keyword))?.suggestedAccount||'').startsWith(code+' '));
  return {ok:mp.groups.length===10&&mp.groups.every(g=>g.count===500)&&mp.totals.lines===5000&&timing.mapping<10000&&sugg,info:`import ${timing.import} ms; mapping ${timing.mapping} ms; ${mp.groups.map(g=>`${g.keyword}:${g.count}:${(g.suggestedAccount||'-').slice(0,4)}`).join(' ')}`}});
await t('R-16','The 10 group decisions post all 5,000 lines, 100 per request (each under 15 s); bank, HST and every expense account equal the hand sums',async()=>{as(L.id);const mp=need('mapping',await o.call('guided/mapping?monthId='+BIGM));const hst=mp.groups.find(g=>g.keyword==='ROGERS WIRELESS').suggestedTax;let calls=0,slow=0;const t0=Date.now();
  for(const g of mp.groups){const [, ,code,tx]=MER.find(m=>m[0].startsWith(g.keyword));let r;do{const c0=Date.now();r=need('post-group',await o.call('guided/post-group',{method:'POST',json:{monthId:BIGM,groupKey:g.key,accountId:acct(L.id,code),tax:tx?hst:'NO_TAX'}}));calls++;slow=Math.max(slow,Date.now()-c0)}while(r.remaining>0)}
  timing.post=Date.now()-t0;const e={};const add=(c,v)=>e[c]=(e[c]||0)+v;for(const l of bigLines){add('1000',l.c);if(l.c>0){const n=l.tx?net(l.c):l.c;add(l.code,-n);if(l.tx)add('2100',-(l.c-n))}else{const g=-l.c,n=l.tx?net(g):g;add(l.code,n);if(l.tx)add('1100',g-n)}}
  const got=gl(L.id);const ok=Object.keys({...got,...e}).every(k=>(got[k]||0)===(e[k]||0));
  return {ok:ok&&calls===50&&slow<15000,info:`calls ${calls}; slowest ${slow} ms; total ${timing.post} ms; GL ${JSON.stringify(got)}; want ${JSON.stringify(e)}`}});
await t('R-17','Reconciling the 5,000-line statement completes in one request in under 15 s with 5,000 cleared lines',async()=>{const t0=Date.now();const r=need('reconcile',await as(L.id).call('guided/reconcile',{method:'POST',json:{monthId:BIGM}}));timing.reconcile=Date.now()-t0;
  const n=r.reconciliationId?sql(`SELECT COUNT(*) FROM reconciliation_items WHERE reconciliation_id='${r.reconciliationId}'`):'0';
  return {ok:r.reconciled&&n==='5000'&&timing.reconcile<15000,info:`${timing.reconcile} ms; ${JSON.stringify(r)}; items ${n}`}});

// Connect with an accountant.
await t('R-18','Accountant review: findings and one total in CAD; the response carries no minutes, hours or rate',async()=>{const r=need('review',await as(CID).call('guided/accountant/review'));const s=JSON.stringify(r);
  return {ok:r.currency==='CAD'&&r.quoteCents>0&&r.findings.length>0&&!/minute|hour|rate/i.test(s)&&r.findings.every(f=>!('minutes' in f))&&r.findings.some(f=>f.id==='unassigned'),info:s.slice(0,400)}});
await t('R-19','Sending the request stores it, emails the breakdown to enquiry@sraccountax.ca, and returns an Outlook draft with the findings and total but no rate or hours',async()=>{const before=mails().length;
  const r=need('request',await as(CID).call('guided/accountant/request',{method:'POST',json:{note:'Please finish my books',phone:'416-555-0100'}}));await new Promise(x=>setTimeout(x,2500));
  const m=mails().slice(before).find(x=>/To: .*enquiry@sraccountax\.ca/i.test(x.t)&&x.t.includes(r.requestId));const row=sql(`SELECT email_status,rate_cents,quote_cents,hours_hundredths FROM accountant_requests WHERE id='${r.requestId}' AND company_id='${CID}'`).split('\t');
  const total=(r.quoteCents/100).toFixed(2);const draftOk=r.draft.to==='enquiry@sraccountax.ca'&&r.draft.subject.includes(r.requestId)&&r.draft.body.includes(total)&&!/\/h\b|hour|30\.00 CAD\/h|rate/i.test(r.draft.body);
  const hand=Number(row[3])*Number(row[1])/100;
  return {ok:!!m&&/h at \$30\.00 CAD\/h/.test(m.t)&&row[0]==='sent'&&row[1]==='3000'&&Number(row[2])===r.quoteCents&&hand===r.quoteCents&&draftOk,info:`mail ${!!m}; row ${row.join(' ')}; draft subject "${r.draft.subject}"; draft ok ${draftOk}`}});
await t('R-20','Requests are limited to 5 per company per hour (the sixth is refused with 429)',async()=>{as(CID);const s=[];for(let i=0;i<5;i++)s.push((await o.call('guided/accountant/request',{method:'POST',json:{note:'limit '+i}})).s);
  return {ok:s.slice(0,4).every(x=>x===201)&&s[4]===429,info:s.join(',')}});

// Browser: the same flow through the screens.
const UC=await mkCo('R163 Screens '+tag,'12-31');fs.writeFileSync(DIR+'/feb.csv',FEB);
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});const p=await ctx.newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,200)));
await ctx.route(/^https:\/\/outlook\.office\.com\//,r=>r.fulfill({status:200,contentType:'text/html',body:'<title>Outlook (test stub)</title>'}));
const okDialog=async()=>{await p.waitForSelector('.tegh-action-dialog button[type=submit]',{timeout:15000});const msg=(await p.locator('.tegh-action-dialog').innerText()).replace(/\s+/g,' ');await p.click('.tegh-action-dialog button[type=submit]');await p.waitForTimeout(300);return msg};
execFileSync('mysql',['tegh_gate','-e','DELETE FROM login_attempts']);
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
const ready=()=>p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:60000});await ready();
await p.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},UC.id);await p.goto('https://gate.test/app.html');await ready();
await p.evaluate(()=>TeghPortal.switchBookkeepingMode('owner'));
const shot=n=>p.screenshot({path:`${DIR}/${n}.png`,fullPage:true}).catch(()=>{});
const noSideways=()=>p.evaluate(()=>{const s=document.scrollingElement;const inner=[...document.querySelectorAll('[data-srp-page] .srp-table-wrap,[data-r163-month],.r163-map,.r163-main,.r163-side')].filter(e=>e.offsetParent&&e.scrollWidth>e.clientWidth+1);return s.scrollWidth<=window.innerWidth+1&&!inner.length});
await t('R-21','Guided Home starts with the bank statement card; the top bar has "Connect with an accountant" right after Tegh Assist',async()=>{await p.waitForSelector('[data-r163-home-statements]',{timeout:30000});await p.waitForTimeout(800);
  const txt=(await p.locator('[data-r163-home-statements]').innerText()).replace(/\s+/g,' ');const order=await p.evaluate(()=>{const items=[...document.querySelectorAll('.topbar .top-actions>*')].filter(x=>x.offsetParent);items.sort((a,b)=>(+getComputedStyle(a).order)-(+getComputedStyle(b).order)||(a.compareDocumentPosition(b)&2?1:-1));return items.map(x=>x.matches('[data-shell-accountant]')?'ACCOUNTANT':x.matches('[data-shell-assist]')?'ASSIST':(x.getAttribute('aria-label')||x.textContent.trim()).slice(0,14))});
  const ai=order.indexOf('ASSIST'),ac=order.indexOf('ACCOUNTANT');await shot('1-home');
  return {ok:/statement/i.test(txt)&&ac>=0&&(ai<0||ac===ai+1),info:`card "${txt.slice(0,120)}"; top bar ${order.join(' | ')}`}});
await t('R-22','The statement page shows 12 month rows for January to December 2026, all "Not uploaded", and the PDF converter beside them',async()=>{await p.click('[data-r163-home-open]');await p.waitForSelector('[data-r163-month]',{timeout:30000});await p.waitForTimeout(600);
  const rows=await p.$$eval('[data-r163-month]',r=>r.map(x=>x.dataset.r163Month+':'+(x.querySelector('.srp-status')?.textContent.trim()||'')));const conv=await p.locator('[data-r163-converter]').count();await shot('2-months');
  return {ok:rows.length===12&&rows[0].startsWith('2026-01')&&rows[11].startsWith('2026-12')&&rows.every(r=>/Not uploaded/.test(r))&&conv===1,info:rows.join(', ')+'; converter '+conv}});
let fyNote='';
await t('R-23','Uploading the February statement against May is stopped with "Upload it to February 2026 instead"; accepting moves it, and the fiscal-year note says 1 transaction falls before the year',async()=>{
  await p.click('[data-r163-upload="2026-05"]');await p.setInputFiles('[data-r163-upload-panel="2026-05"] input[type=file]',DIR+'/feb.csv');await p.click('[data-r163-upload-panel="2026-05"] button[type=submit]');
  await p.waitForSelector('[data-r163-use-month]',{timeout:30000});const prob=(await p.locator('.r163-problem').innerText()).replace(/\s+/g,' ');const btn=(await p.locator('[data-r163-use-month]').innerText()).trim();await shot('3-wrong-month');
  await p.click('[data-r163-use-month]');fyNote=await okDialog();
  return {ok:/February 2026/.test(prob)&&/February 2026/.test(btn)&&/1 transaction/.test(fyNote)&&/before/i.test(fyNote),info:`problem "${prob.slice(0,140)}"; button "${btn}"; note "${fyNote.slice(0,160)}"`}});
await t('R-24','After the verification step the mapping page shows 6 lines in 5 groups, 5 mapped and 1 to choose',async()=>{await p.waitForSelector('[data-bank-approve]',{timeout:30000});await p.click('[data-bank-approve]');
  for(let i=0;i<2;i++){const d=await p.waitForSelector('.tegh-action-dialog button[type=submit]',{timeout:6000}).catch(()=>null);if(!d)break;await d.click();await p.waitForTimeout(500)}
  await p.waitForSelector('[data-srp-page="guided-mapping"] .r163-map-table',{timeout:60000});await p.waitForTimeout(1000);const stats=(await p.locator('.r163-map-stats').innerText()).replace(/\s+/g,' ');const groups=await p.locator('[data-r163-group]').count();await shot('4-mapping');
  return {ok:groups===5&&/6/.test(stats)&&/5/.test(stats)&&/1/.test(stats),info:`groups ${groups}; stats ${stats}`}});
await t('R-25','Choosing an account for the unmapped group, "Select all groups with an account" and Post books all 6 lines and reconciles the month automatically',async()=>{
  const sel=p.locator('[data-r163-group="UNKNOWN THING|out"] [data-r163-account]');await sel.selectOption(await sel.evaluate(s=>[...s.options].find(o=>o.textContent.startsWith('6999'))?.value));
  await p.click('[data-r163-select-ready]');const label=(await p.locator('[data-r163-post]').innerText()).replace(/\s+/g,' ');await p.click('[data-r163-post]');await okDialog();
  await p.waitForFunction(()=>/Reconciled/.test(document.querySelector('[data-r163-recon] h2')?.textContent||''),null,{timeout:90000});await p.waitForTimeout(600);const recon=(await p.locator('[data-r163-recon]').innerText()).replace(/\s+/g,' ');await shot('5-reconciled');
  const r=sql(`SELECT COUNT(*) FROM reconciliations WHERE company_id='${UC.id}' AND status='complete' AND difference_cents=0`);const g=gl(UC.id);
  return {ok:/6 lines/i.test(label)&&r==='1'&&g['1000']===30400&&sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${UC.id}' AND status='posted'`)==='6',info:`button "${label}"; recon "${recon.slice(0,140)}"; complete ${r}; bank ${g['1000']}`}});
await t('R-26','The month list then shows February as "Uploaded · reconciled" and Guided Home shows 1 of 12 months',async()=>{await p.evaluate(()=>TeghPortal.openGuidedStatements());await p.waitForSelector('[data-r163-month="2026-02"]',{timeout:30000});await p.waitForTimeout(500);
  const s=(await p.locator('[data-r163-month="2026-02"] .srp-status').innerText()).trim();const note=await p.locator('[data-r163-month="2026-02"] [data-r163-fy-note], [data-r163-fy-note]').count();
  await p.locator('.sidebar nav button:has-text("Home")').first().click();await p.waitForSelector('[data-r163-home-progress]',{timeout:30000});await p.waitForTimeout(800);const prog=(await p.locator('[data-r163-home-progress]').innerText()).replace(/\s+/g,' ');
  return {ok:/reconciled/i.test(s)&&/1 of 12/.test(prog),info:`Feb "${s}"; FY notes ${note}; home "${prog}"`}});
await t('R-27','Next step "No" opens the financial statements page with Trial Balance, Profit and Loss and Balance Sheet',async()=>{await p.evaluate(id=>TeghPortal.openGuidedMapping(id),sql(`SELECT id FROM guided_statement_months WHERE company_id='${UC.id}'`));await p.waitForSelector('[data-r163-next="reports"]',{timeout:30000});
  await p.click('[data-r163-next="reports"]');await p.waitForSelector('[data-r163-fs]',{timeout:30000});await p.waitForTimeout(500);const txt=(await p.locator('[data-srp-page="guided-financial-statements"]').first().innerText()).replace(/\s+/g,' ');await shot('6-statements');
  return {ok:/Trial Balance/.test(txt)&&/Profit and Loss/.test(txt)&&/Balance Sheet/.test(txt),info:txt.slice(0,200)}});
await t('R-28','Next step "Yes" asks what to record, switches to Full Accounting and opens Customer invoices',async()=>{await p.evaluate(id=>TeghPortal.openGuidedMapping(id),sql(`SELECT id FROM guided_statement_months WHERE company_id='${UC.id}'`));await p.waitForSelector('[data-r163-next="documents"]',{timeout:30000});
  await p.click('[data-r163-next="documents"]');await p.waitForSelector('[data-r163-full="invoices"]',{timeout:15000});await shot('7-full-prompt');await p.click('[data-r163-full="invoices"]');
  for(let i=0;i<2;i++){const d=await p.waitForSelector('.tegh-action-dialog button[type=submit]',{timeout:3000}).catch(()=>null);if(!d)break;await d.click();await p.waitForTimeout(400)}
  await p.waitForFunction(()=>document.documentElement.dataset.bookkeepingMode!=='guided',null,{timeout:20000});await p.waitForTimeout(2500);
  const mode=await p.evaluate(()=>document.documentElement.dataset.bookkeepingMode);const page=await p.evaluate(()=>[...document.querySelectorAll('[data-srp-page]')].filter(x=>x.offsetParent).map(x=>x.dataset.srpPage).join('+')+' '+(document.querySelector('.topbar h1,.topbar .page-title')?.textContent||''));
  await p.evaluate(()=>TeghPortal.switchBookkeepingMode('owner'));await p.waitForTimeout(1500);
  return {ok:mode!=='guided'&&/invoice/i.test(page),info:`mode ${mode}; page ${page.slice(0,120)}`}});
await t('R-29','Connect with an accountant: findings and a CAD quote, no hours or rate on the screen; sending opens the Outlook draft to enquiry@sraccountax.ca',async()=>{await p.click('[data-shell-accountant]');await p.waitForSelector('[data-r163-quote]',{timeout:30000});await p.waitForTimeout(400);
  const quote=(await p.locator('[data-r163-quote]').innerText()).replace(/\s+/g,' ');const vis=(await p.locator('[data-r163-accountant]').innerText());await shot('8-accountant');
  await p.fill('[data-r163-accountant-form] textarea','Please finish');await p.check('[data-r163-accountant-form] input[name=agree]');
  const [pop]=await Promise.all([ctx.waitForEvent('page',{timeout:15000}).catch(()=>null),p.click('[data-r163-accountant-form] button[type=submit]')]);await p.waitForSelector('[data-r163-sent]',{timeout:30000});
  let url='';if(pop){await pop.waitForURL(/outlook\.office\.com/,{timeout:10000}).catch(()=>{});url=pop.url();await pop.close().catch(()=>{})}const u=url?new URL(url):null;await shot('9-sent');
  const body=u?.searchParams.get('body')||'';
  return {ok:/CAD/.test(quote)&&!/hour|\/h\b|per h|rate/i.test(vis)&&u?.hostname==='outlook.office.com'&&u.searchParams.get('to')==='enquiry@sraccountax.ca'&&/Accountant request/.test(u.searchParams.get('subject')||'')&&!/hour|\/h\b|rate/i.test(body),info:`quote "${quote}"; draft ${u?u.hostname+u.pathname:'none'} subject "${u?.searchParams.get('subject')}"`}});
await t('R-30','At phone width (390 px) the statement months and the mapping page have no sideways scroll, on the page or inside a table or card',async()=>{await p.keyboard.press('Escape').catch(()=>{});await p.evaluate(()=>document.querySelectorAll('.srp-modal-scrim').forEach(x=>x.remove()));await p.setViewportSize({width:390,height:844});
  await p.evaluate(()=>TeghPortal.openGuidedStatements());await p.waitForSelector('[data-r163-month]',{timeout:30000});await p.waitForTimeout(800);const a=await noSideways();await shot('10-phone-months');
  await p.evaluate(id=>TeghPortal.openGuidedMapping(id),sql(`SELECT id FROM guided_statement_months WHERE company_id='${UC.id}'`));await p.waitForSelector('.r163-map-table',{timeout:30000});await p.waitForTimeout(800);const c=await noSideways();await shot('11-phone-mapping');
  return {ok:a&&c,info:`months ${a}; mapping ${c}`}});
rec('R-31',A,'No script errors on the R163 screens',errs.length?'FAIL':'PASS',errs.join(' | ')||'no page errors');
fs.writeFileSync('/srv/gate/ev/r163-timing.json',JSON.stringify(timing));
await b.close();save('r163.json');
