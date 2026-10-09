// Large-volume seed for the final functional test. Every posting goes through the public API; expected
// figures are summed here from the generated amounts (13% Ontario HST on whole-dollar prices, so tax is exact).
import {S,sql,need} from '../lib.mjs';import fs from 'fs';
const [,,NAME,NC,NV,NI,NB,NJ,NBANK]=process.argv;const N={c:+NC,v:+NV,i:+NI,b:+NB,j:+NJ,bank:+NBANK};
let seed=[...NAME].reduce((a,c)=>a*31+c.charCodeAt(0)>>>0,7);const rnd=()=>(seed=(seed*1664525+1013904223)>>>0)/4294967296;const ri=(a,b)=>a+Math.floor(rnd()*(b-a+1));
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const co=need('company',await o.call('companies',{method:'POST',json:{name:NAME,province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));
const CID=co.company?.id||co.companyId||co.id;o.cid=CID;console.log(NAME,CID);
const acct=c=>sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${c}'`);const ON=sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='ON' AND status='active'`);
const BANK=sql(`SELECT id FROM bank_accounts WHERE company_id='${CID}' AND account_type='bank' ORDER BY created_at LIMIT 1`);
const A={bank:acct('1000'),ar:acct('1200'),ap:acct('2050'),cap:acct('3000'),rev:acct('4000'),fees:acct('6800')};const EXP=['6100','6200','6300','6400','6500','6700'].map(c=>({c,id:acct(c)}));
const exp={};const add=(c,v)=>exp[c]=(exp[c]||0)+v;let arOpen=0,apOpen=0;const arByCust={};
const day=(i,n)=>{const d=new Date(Date.UTC(2026,0,1)+Math.floor(i*(272/n))*86400000);return d.toISOString().slice(0,10)};
const plus=(d,k)=>new Date(Date.parse(d)+k*86400000).toISOString().slice(0,10);const cap=d=>d>'2026-09-30'?'2026-09-30':d;
async function pool(items,w,fn){let i=0,err=null;await Promise.all(Array.from({length:w},async()=>{while(i<items.length&&!err){const k=i++;try{await fn(items[k],k)}catch(e){err=e}}}));if(err)throw err}
const jl=(docRef)=>Object.fromEntries(sql(`SELECT a.code,SUM(jl.debit_cents-jl.credit_cents) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.source_id='${docRef}' GROUP BY a.code`).split('\n').filter(Boolean).map(l=>{const [c,v]=l.split('\t');return [c,+v]}));
const t0=Date.now();
// opening capital (opening balances: Dr bank / Cr share capital)
{const amt=ri(400,900)*100000;need('ob1',await o.call('setup/opening-balances',{method:'PUT',json:{accountId:A.bank,amountCents:amt}}));need('ob2',await o.call('setup/opening-balances',{method:'PUT',json:{accountId:A.cap,amountCents:-amt}}));need('ob3',await o.call('setup/opening-balances',{method:'POST',json:{action:'post'}}));add('1000',amt);add('3000',-amt)}
// customers/vendors
const cust=[],vend=[];
await pool(Array.from({length:N.c},(_,k)=>k),8,async k=>{const r=need('cust',await o.call('customers',{method:'POST',json:{name:`${NAME.split(' ')[1]} Client ${String(k+1).padStart(4,'0')} ${['Holdings','Dental','Studio','Logistics','Café & Bakery','O\'Neil Partners','Mapleview Ltd'][k%7]}`,email:`client${k+1}@example.test`,phone:'',billingAddress:`${k+1} King St W`,province:'ON'}}));cust[k]=r.customer.id});
await pool(Array.from({length:N.v},(_,k)=>k),8,async k=>{const r=need('vend',await o.call('vendors',{method:'POST',json:{name:`${NAME.split(' ')[1]} Supplier ${String(k+1).padStart(3,'0')}`,email:'',address:'',defaultTermsDays:30,currency:'CAD',defaultExpenseAccountId:null,province:'ON'}}));vend[k]=r.vendor?.id||r.id});
console.log('parties',Date.now()-t0,'ms');
// invoices
const invs=[];let first=true;
await pool(Array.from({length:N.i},(_,k)=>k),8,async k=>{
  const n=ri(1,4),lines=[];let net=0,taxable=0;for(let j=0;j<n;j++){const q=ri(1,12),p=ri(25,1800)*100,tx=rnd()<0.85;lines.push({description:['Consulting hours','Monthly retainer','Site visit','Training session','Exempt disbursement'][tx?j%4:4],quantity:q,unitPriceCents:p,taxable:tx,...(tx?{taxCodeId:ON}:{})});net+=q*p;if(tx)taxable+=q*p}
  const tax=taxable*13/100,total=net+tax,date=day(k,N.i),c=k%N.c;
  const r=need('inv '+k,await o.call('invoices',{method:'POST',json:{customerId:cust[c],issueDate:date,dueDate:plus(date,30),currency:'CAD',issue:true,lines}}));const inv=r.invoice||r;
  if(first&&k===0){first=false;const g=jl(inv.id);const want={'1200':total,'4000':-net,...(tax?{'2100':-tax}:{})};if(JSON.stringify(Object.keys(g).sort().map(x=>[x,g[x]]))!==JSON.stringify(Object.keys(want).sort().map(x=>[x,want[x]])))throw new Error('first invoice posting '+JSON.stringify(g)+' want '+JSON.stringify(want))}
  add('1200',total);add('4000',-net);add('2100',-tax);invs[k]={id:inv.id,total,date,c}});
console.log('invoices',Date.now()-t0,'ms');
// bills
const bills=[];
await pool(Array.from({length:N.b},(_,k)=>k),8,async k=>{const net=ri(40,4500)*100,tax=net*13/100,date=day(k,N.b),e=EXP[k%EXP.length];
  const r=need('bill '+k,await o.call('bills',{method:'POST',json:{vendorId:vend[k%N.v],number:`B-${String(k+1).padStart(5,'0')}`,billDate:date,dueDate:plus(date,30),categoryAccountId:e.id,currency:'CAD',taxEntryMode:'exclusive',taxCodeId:ON,foreignAmountCents:net,issue:true}}));const b=r.bill||r;
  if(k===0){const g=jl(b.id);if(g[e.c]!==net||g['1100']!==tax||g['2050']!==-(net+tax))throw new Error('first bill posting '+JSON.stringify(g)+` want ${e.c}=${net} 1100=${tax} 2050=${-(net+tax)}`)}
  add(e.c,net);add('1100',tax);add('2050',-(net+tax));bills[k]={id:b.id,total:net+tax,date}});
console.log('bills',Date.now()-t0,'ms');
// customer payments: 65% paid in full, 10% part-paid
const cp=[];invs.forEach((x,k)=>{const r=rnd();if(r<0.65)cp.push([x,x.total]);else if(r<0.75)cp.push([x,Math.floor(x.total*ri(20,80)/100)])});
await pool(cp,8,async([x,amt],k)=>{need('cpay',await o.call('payments',{method:'POST',json:{type:'customer',documentId:x.id,paymentDate:cap(plus(x.date,ri(3,25))),reference:'EFT '+(k+1),foreignAmountCents:amt,paymentAccountId:A.bank,operationKey:'vol-cp-'+CID.slice(-8)+'-'+k}}));add('1000',amt);add('1200',-amt);x.paid=amt});
const vp=bills.filter(()=>rnd()<0.6);
await pool(vp,8,async(x,k)=>{need('vpay',await o.call('payments',{method:'POST',json:{type:'vendor',documentId:x.id,paymentDate:cap(plus(x.date,ri(5,25))),reference:'CHQ '+(1000+k),foreignAmountCents:x.total,paymentAccountId:A.bank,operationKey:'vol-vp-'+CID.slice(-8)+'-'+k}}));add('1000',-x.total);add('2050',x.total);x.paid=x.total});
console.log('payments',cp.length,vp.length,Date.now()-t0,'ms');
// journals: bank charges
await pool(Array.from({length:N.j},(_,k)=>k),8,async k=>{const amt=ri(150,9500);need('jnl',await o.call('journals',{method:'POST',json:{date:day(k,N.j),memo:'Bank charges '+(k+1),lines:[{accountId:A.fees,debitCents:amt,creditCents:0,description:'Monthly fee'},{accountId:A.bank,debitCents:0,creditCents:amt,description:'Monthly fee'}]}}));add('6800',amt);add('1000',-amt)});
// bank statement: imported, left pending for review (no GL effect until posted)
let csv='Date,Description,Amount\n';for(let k=0;k<N.bank;k++){const v=(rnd()<0.5?-1:1)*ri(5,250000)/100;csv+=`${day(k,N.bank)},${['INTERAC e-Transfer','POS Purchase Staples','Hydro One','Payroll deposit','Rogers Wireless','Client deposit'][k%6]} ${k+1},${v.toFixed(2)}\n`}
const data=Buffer.from(csv);const st=need('up',await o.call('imports/upload-start',{method:'POST',json:{filename:`vol-statement-${N.bank}.csv`,size:data.length}}));
for(let off=0;off<data.length;off+=512*1024)need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:st.uploadId,offset:off,data:data.subarray(off,off+512*1024).toString('base64')}}));
const pv=need('finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:st.uploadId,bankAccountId:BANK,exchangeRateMicros:1000000,mode:'preview'}}));
const sel=(pv.preview.rows||[]).filter(x=>x.selectionKey&&x.eligible!==false&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
const ap=await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.preview.id,operationKey:'vol-stmt-'+CID.slice(-10),selectedKeys:sel,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}});
console.log('bank import',pv.preview.rows?.length,sel.length,ap.s,Date.now()-t0,'ms');
for(const x of invs){if(x.total-(x.paid||0)>0){arOpen+=x.total-(x.paid||0);arByCust[x.c]=(arByCust[x.c]||0)+x.total-(x.paid||0)}}for(const x of bills)apOpen+=x.total-(x.paid||0);
const out={name:NAME,id:CID,counts:{customers:N.c,vendors:N.v,invoices:N.i,bills:N.b,customerPayments:cp.length,vendorPayments:vp.length,journals:N.j,bankLines:N.bank,bankImported:sel.length},gl:Object.fromEntries(Object.entries(exp).filter(([,v])=>v!==0).sort()),arOpen,apOpen,openInvoices:invs.filter(x=>x.total>(x.paid||0)).length,seconds:Math.round((Date.now()-t0)/1000)};
fs.writeFileSync(`/srv/gate/t/vol/expected-${CID}.json`,JSON.stringify(out,null,1));console.log(JSON.stringify(out));
