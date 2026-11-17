// Seeds one synthetic "Perf Volume" company: 40 customers, 15 vendors, ~600 invoices, ~250 bills, ~200 payments,
// 100 journals, 800 bank lines over 2026. Used only to measure speed on the test server.
import {S, sql, need} from '../lib.mjs';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const tag=Date.now().toString(36);let n=0;const key=p=>`perf-${p}-${tag}-${++n}`.padEnd(20,'x');
const base={businessType:'corporation',country:'Canada',province:'ON',taxRegistered:true,taxNumber:'123456789RT0001',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default',currency:'CAD',accountingBasis:'accrual'};
const CO=need('company',await o.call('companies',{method:'POST',json:{...base,name:'Perf Volume '+tag}})).company.id;o.cid=CO;
const acct=c=>sql(`SELECT id FROM accounts WHERE company_id='${CO}' AND code='${c}'`);
const TMPL=(await o.call('invoice-templates')).b.templates?.[0]?.id;
const BANKACCT=(await o.call('payments?type=customer')).b.paymentAccounts.find(a=>!a.undeposited)?.id;
const ON=sql(`SELECT id FROM tax_codes WHERE company_id='${CO}' AND code='ON'`);
let seed=7;const rnd=m=>{seed=(seed*1103515245+12345)%2147483648;return seed%m};
const day=d=>{const t=new Date(Date.UTC(2026,0,1)+d*86400000);return t.toISOString().slice(0,10)};
const cust=[];for(let i=0;i<40;i++)cust.push(need('c',await o.call('customers',{method:'POST',json:{name:`Perf Customer ${i} ${tag}`,email:'',phone:'',billingAddress:i+' Main St',country:'Canada',province:'ON'}})).customer.id);
const vend=[];for(let i=0;i<15;i++)vend.push(need('v',await o.call('vendors',{method:'POST',json:{name:`Perf Vendor ${i} ${tag}`,province:'ON',country:'Canada',paymentTerms:30}})).vendor.id);
const inv=[];const conc=async(N,f,k=6)=>{let i=0;await Promise.all(Array.from({length:k},async()=>{while(i<N){const j=i++;await f(j)}}))};
await conc(600,async i=>{const d=rnd(300);const r=await o.call('invoices',{method:'POST',json:{customerId:cust[rnd(40)],issueDate:day(d),dueDate:day(d+30),currency:'CAD',templateId:TMPL,issue:true,lines:[{description:'Service '+i,quantity:1+rnd(5),unitPriceCents:5000+rnd(90000),taxable:true},{description:'Extra',quantity:1,unitPriceCents:1000+rnd(5000),taxable:true}]}});if(r.s<300)inv.push({...r.b.invoice,d});else console.log('inv',r.s,JSON.stringify(r.b).slice(0,200))});
console.log('invoices',inv.length);
await conc(250,async i=>{const d=rnd(300);const r=await o.call('bills',{method:'POST',json:{vendorId:vend[rnd(15)],number:'PB-'+i+'-'+tag,billDate:day(d),dueDate:day(d+30),categoryAccountId:acct(['6400','6800','5000'][rnd(3)]||'6400'),currency:'CAD',taxEntryMode:'exclusive',taxCodeId:ON,foreignAmountCents:2000+rnd(200000),issue:true}});if(r.s>=300)console.log('bill',r.s,JSON.stringify(r.b).slice(0,200))});
let paid=0;await conc(200,async i=>{const v=inv[i*2];if(!v)return;const r=await o.call('payments',{method:'POST',json:{type:'customer',documentId:v.id,paymentDate:day(Math.min(v.d+20,310)),reference:'EFT '+i,foreignAmountCents:v.totalCents??v.total_cents,paymentAccountId:BANKACCT,operationKey:key('pay')}});if(r.s<300)paid++;else if(paid<1)console.log('pay',r.s,JSON.stringify(r.b).slice(0,200))});
console.log('payments',paid);
await conc(100,async i=>{await o.call('journals',{method:'POST',json:{date:day(rnd(300)),memo:'Perf journal '+i,lines:[{accountId:acct('6800'),debitCents:1000+rnd(9000),creditCents:0},{accountId:acct('1000'),debitCents:0,creditCents:0}]}})},1);
// journals need balanced lines
await conc(100,async i=>{const c=1000+rnd(9000);await o.call('journals',{method:'POST',json:{date:day(rnd(300)),memo:'Perf journal '+i,lines:[{accountId:acct('6800'),debitCents:c,creditCents:0},{accountId:acct('1000'),debitCents:0,creditCents:c}]}})},3);
const BANK=sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);
for(let part=0;part<4;part++){let csv='Date,Description,Amount\n';for(let i=0;i<200;i++){const a=(rnd(2)?1:-1)*(100+rnd(300000));csv+=`${day(part*75+rnd(75))},PERF LINE ${part}-${i} ${tag},${(a/100).toFixed(2)}\n`}
 const data=Buffer.from(csv);const up=need('start',await o.call('imports/upload-start',{method:'POST',json:{filename:key('csv')+'.csv',size:data.length}}));
 need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:up.uploadId,offset:0,data:data.toString('base64')}}));
 const fin=await o.call('imports/upload-finish',{method:'POST',json:{uploadId:up.uploadId,bankAccountId:BANK,mode:'preview'}});
 const keys=(fin.b.preview?.rows||[]).filter(x=>x.eligible&&!x.requiresDuplicateReview).map(x=>x.selectionKey);
 const ap=await o.call('operations/statement-approve',{method:'POST',json:{previewId:fin.b.preview.id,operationKey:key('ap'),selectedKeys:keys,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}});console.log('bank part',part,ap.s,keys.length)}
console.log('COMPANY',CO);
console.log(sql(`SELECT (SELECT COUNT(*) FROM invoices WHERE company_id='${CO}'),(SELECT COUNT(*) FROM bills WHERE company_id='${CO}'),(SELECT COUNT(*) FROM journal_entries WHERE company_id='${CO}'),(SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CO}')`));
