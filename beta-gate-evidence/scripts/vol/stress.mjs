// Concurrent saves in one company: bills, invoices, payments and expenses at the same moment.
import {S,sql,need} from '../lib.mjs';
const W=+(process.argv[2]||16),N=+(process.argv[3]||160);
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const co=need('co',await o.call('companies',{method:'POST',json:{name:'Concurrency '+Date.now(),province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));
const CID=co.company?.id||co.id;o.cid=CID;const acct=c=>sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${c}'`);const ON=sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='ON' AND status='active'`);
const c=need('c',await o.call('customers',{method:'POST',json:{name:'C',email:'',phone:'',billingAddress:'1',province:'ON'}})).customer.id;const v=need('v',await o.call('vendors',{method:'POST',json:{name:'V',email:'',address:'',defaultTermsDays:30,currency:'CAD',province:'ON'}})).vendor.id;
const st={};let i=0;const docs=[];
await Promise.all(Array.from({length:W},async()=>{while(i<N){const k=i++;const kind=['bill','inv','exp','bill'][k%4];let r;
 if(kind==='bill')r=await o.call('bills',{method:'POST',json:{vendorId:v,number:'S-'+k,billDate:'2026-09-10',dueDate:'2026-10-10',categoryAccountId:acct('6400'),currency:'CAD',taxEntryMode:'exclusive',taxCodeId:ON,foreignAmountCents:10000,issue:true}});
 else if(kind==='inv')r=await o.call('invoices',{method:'POST',json:{customerId:c,issueDate:'2026-09-10',dueDate:'2026-10-10',currency:'CAD',issue:true,lines:[{description:'X',quantity:1,unitPriceCents:10000,taxable:true,taxCodeId:ON}]}});
 else r=await o.call('expenses',{method:'POST',json:{vendor:'Taxi',date:'2026-09-10',expenseDate:'2026-09-10',categoryAccountId:acct('6500'),paidFromAccountId:acct('1000'),currency:'CAD',amountCents:11300,foreignAmountCents:11300,taxEntryMode:'inclusive',taxCodeId:ON}});
 const key=kind+' '+r.s;st[key]=(st[key]||0)+1;if(r.s>=300)console.log(kind,r.s,JSON.stringify(r.b).slice(0,120))}}));
const dup=sql(`SELECT COUNT(*) FROM (SELECT serial_number FROM vouchers WHERE company_id='${CID}' GROUP BY serial_number HAVING COUNT(*)>1) d`);
const dupn=sql(`SELECT COUNT(*) FROM (SELECT voucher_number FROM vouchers WHERE company_id='${CID}' GROUP BY voucher_number HAVING COUNT(*)>1) d`);
const vc=sql(`SELECT COUNT(*) FROM vouchers WHERE company_id='${CID}'`),gaps=sql(`SELECT MAX(serial_number)-MIN(serial_number)+1-COUNT(*) FROM vouchers WHERE company_id='${CID}'`);
const unbal=sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id='${CID}' GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`);
console.log(JSON.stringify({W,N,status:st,vouchers:+vc,duplicateSerials:+dup,duplicateNumbers:+dupn,serialGaps:+gaps,unbalanced:+unbal}));
