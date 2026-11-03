// Final functional test, part V: the large companies' reports against figures summed independently by seed.mjs.
import {S,rec,save,sql} from '../lib.mjs';import fs from 'fs';
const A='R161';const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const exps=fs.readdirSync('/srv/gate/t/vol').filter(f=>/^expected-company_.*\.json$/.test(f)).map(f=>JSON.parse(fs.readFileSync('/srv/gate/t/vol/'+f))).filter(e=>/^Volume (Northwind|Lakeshore)/.test(e.name));
const timed=async(route)=>{const t=Date.now();const r=await o.call(route);return {...r,ms:Date.now()-t}};
const money=c=>(c<0?'-':'')+'$'+(Math.abs(c)/100).toLocaleString('en-CA',{minimumFractionDigits:2});
let k=0;for(const e of exps.sort((a,b)=>b.counts.invoices-a.counts.invoices)){k++;o.cid=e.id;const tag=e.name.split(' ')[1];
 const gl=await timed('portal/trial-balance?scope=general-ledger&start=2026-01-01&end=2026-09-30');
 const got={};let dr=0,cr=0;for(const r of gl.b.rows||[]){const v=(r.closingDebitCents||0)-(r.closingCreditCents||0);if(v)got[r.code]=v;dr+=r.closingDebitCents||0;cr+=r.closingCreditCents||0}
 const diff=Object.keys({...got,...e.gl}).filter(c=>(got[c]||0)!==(e.gl[c]||0)).map(c=>`${c}: app ${money(got[c]||0)} expected ${money(e.gl[c]||0)}`);
 rec(`V-0${k}1`,A,`${tag}: Trial Balance after ${e.counts.invoices} invoices, ${e.counts.bills} bills, ${e.counts.customerPayments+e.counts.vendorPayments} payments and ${e.counts.journals} journals equals the independently summed figures for every account, and debits equal credits`,gl.s===200&&!diff.length&&dr===cr?'PASS':'FAIL',`${gl.s} in ${gl.ms} ms; ${Object.keys(got).length} accounts; debits ${money(dr)} credits ${money(cr)}; ${diff.length?'differences: '+diff.join('; '):'all equal'}`);
 const ar=await timed('portal/trial-balance?scope=receivables&start=2026-01-01&end=2026-09-30'),ap=await timed('portal/trial-balance?scope=payables&start=2026-01-01&end=2026-09-30');
 const arT=(ar.b.rows||[]).reduce((s,r)=>s+(r.closingDebitCents||0)-(r.closingCreditCents||0),0),apT=(ap.b.rows||[]).reduce((s,r)=>s+(r.closingCreditCents||0)-(r.closingDebitCents||0),0);
 rec(`V-0${k}2`,A,`${tag}: customer and vendor balances (${(ar.b.rows||[]).length} customers, ${(ap.b.rows||[]).length} vendors) add up to the open receivables and payables, which equal the GL control accounts`,arT===e.arOpen&&apT===e.apOpen&&arT===(got['1200']||0)&&apT===-(got['2050']||0)?'PASS':'FAIL',`AR ${money(arT)} (expected ${money(e.arOpen)}, GL 1200 ${money(got['1200']||0)}) in ${ar.ms} ms; AP ${money(apT)} (expected ${money(e.apOpen)}, GL 2050 ${money(-(got['2050']||0))}) in ${ap.ms} ms`);
 const bs=await timed('reports/v5600/financial?kind=balance-sheet&reportKey=balance_sheet&asOf=2026-09-30&start=2026-01-01&end=2026-09-30');
 const pl=await timed('reports/v5600/financial?kind=profit-loss&reportKey=profit_loss&start=2026-01-01&end=2026-09-30');
 const netExp=-Object.entries(e.gl).filter(([c])=>c>='4000').reduce((s,[,v])=>s+v,0);
 const flat=JSON.stringify(pl.b);const plNet=(()=>{const m=flat.match(/"netIncomeCents":(-?\d+)/)||flat.match(/"netProfitCents":(-?\d+)/)||flat.match(/"net(?:Income|Profit)[A-Za-z]*Cents":(-?\d+)/);return m?+m[1]:null})();
 rec(`V-0${k}3`,A,`${tag}: Profit and Loss net income equals revenue less expenses summed independently; Balance Sheet and P&L each load in under 10 seconds`,bs.s===200&&pl.s===200&&plNet===netExp&&bs.ms<10000&&pl.ms<10000?'PASS':'FAIL',`net income ${plNet===null?'not found':money(plNet)} expected ${money(netExp)}; balance sheet ${bs.s} in ${bs.ms} ms; P&L ${pl.s} in ${pl.ms} ms`);
 rec(`V-0${k}4`,A,`${tag}: the Trial Balance and party balances each answer in under 10 seconds`,gl.ms<10000&&ar.ms<10000&&ap.ms<10000?'PASS':'FAIL',`GL ${gl.ms} ms, receivables ${ar.ms} ms, payables ${ap.ms} ms`);
}
save('r161.json');
// P-01: every report definition's complete (verified) output for the largest company, with the filters it accepts.
{const e=exps.sort((a,b)=>b.counts.invoices-a.counts.invoices)[0];o.cid=e.id;
 const ar=sql(`SELECT id FROM accounts WHERE company_id='${e.id}' AND code='1200'`),bank=sql(`SELECT id FROM bank_accounts WHERE company_id='${e.id}' AND account_type='bank' ORDER BY created_at LIMIT 1`);
 const R='start=2026-01-01&end=2026-09-30',defs=[['general-ledger','start=2026-09-01&end=2026-09-30'],['gl-account-ledger',`${R}&accountId=${ar}`],['audit-history',R],['tax-summary',R],['currency-exposure',''],['reconciliation-summary',R],['ar-ageing',R],['ap-ageing',R],['customer-balances',R],['vendor-balances',R],['ar-trial-balance',R],['ap-trial-balance',R],['customer-directory',''],['vendor-directory',''],['invoice-register',R],['bill-register',R],['expense-register',R],['bank-transactions',R],['bank-general-ledger',`${R}&bankAccountId=${bank}`],['payroll-runs',R],['payroll-remittances',R],['tax-mapping',R]];
 const res=[];for(const [d,q] of defs){const r=await timed(`professional-output/v5990/${d}${q?'&'+q:''}`);res.push([d,r.s,r.ms])}
 const slow=res.filter(([,s,ms])=>s!==200||ms>=10000);
 rec('P-01',A,`${e.name.split(' ')[1]}: the complete output of all ${defs.length} report definitions (registers, ledgers, Bank General Ledger, ageing, trial balances, tax) answers in under 10 seconds each`,slow.length?'FAIL':'PASS',res.map(([d,s,ms])=>`${d} ${s} ${ms} ms`).join('; '))}
save('r161.json');
