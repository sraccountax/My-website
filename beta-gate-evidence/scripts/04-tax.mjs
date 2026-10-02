// Beta gate: QST/GST remittance, custom GL mapping in reports, non-registered business, cash basis, FX.
// Expected amounts are hand-computed and written as literals.
import {S,sql,rec,check,save,need} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
let CID='';const use=c=>{CID=c;o.cid=c};const A='TAX';
const acct=code=>sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${code}'`);
const bal=code=>+sql(`SELECT COALESCE(SUM(jl.debit_cents)-SUM(jl.credit_cents),0) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND a.code='${code}' AND je.status='posted'`);
const jl=(id)=>{const o2={};for(const l of sql(`SELECT a.code,SUM(jl.debit_cents)-SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.source_id='${id}' GROUP BY a.code HAVING SUM(jl.debit_cents)-SUM(jl.credit_cents)<>0 ORDER BY a.code`).split('\n').filter(Boolean)){const [c,v]=l.split('\t');o2[c]=+v}return o2};
const code=c=>sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='${c}' AND status='active'`);
const tmpl=async()=>{const t=await o.call('invoice-templates');return (t.b?.templates||t.b?.invoiceTemplates||[])[0]?.id};
const customer=async(name,province)=>{const r=need('customer',await o.call('customers',{method:'POST',json:{name,email:'',phone:'',billingAddress:'1 Test St',province}}));return r.customer?.id||r.id};
const vendor=async name=>{const r=need('vendor',await o.call('vendors',{method:'POST',json:{name,email:'',address:'',defaultTermsDays:30,currency:'CAD',defaultExpenseAccountId:null}}));return r.vendor?.id||r.id};
const invoice=async(customerId,lines,extra={})=>o.call('invoices',{method:'POST',json:{customerId,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'CAD',templateId:await tmpl(),issue:true,lines,...extra}});
const invId=r=>r.b?.invoice?.id||r.b?.id;
let EXP='';const bill=async(vendorId,number,amount,taxCodeId,mode='exclusive',extra={})=>o.call('bills',{method:'POST',json:{vendorId,number,billDate:'2026-09-16',dueDate:'2026-10-16',categoryAccountId:acct(EXP),currency:'CAD',taxEntryMode:mode,taxCodeId,foreignAmountCents:amount,issue:true,...extra}});
let BANK='';
async function importCsv(name,csv){const data=Buffer.from(csv);const st=need('upload-start',await o.call('imports/upload-start',{method:'POST',json:{filename:name,size:data.length}}));
  need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:st.uploadId,offset:0,data:data.toString('base64')}}));
  const pv=need('finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:st.uploadId,bankAccountId:BANK,exchangeRateMicros:1000000,mode:'preview'}}));
  const sel=(pv.preview.rows||[]).filter(x=>x.selectionKey&&x.eligible!==false&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
  return o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.preview.id,operationKey:'statement_'+Date.now()+Math.random().toString(36).slice(2,10),selectedKeys:sel,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}})}
const tx=d=>sql(`SELECT id FROM bank_transactions WHERE company_id='${CID}' AND description='${d}' ORDER BY created_at LIMIT 1`);
const taxSummary=async()=>{const r=await o.call('professional-output/v5980/tax-summary?start=2026-01-01&end=2026-09-30');return r};

// ================= QC company: QST collected, QST ITR, remittance =================
use(ids.QC);EXP=sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='expense' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
BANK=sql(`SELECT id FROM bank_accounts WHERE company_id='${CID}' AND account_type='bank' LIMIT 1`);const BG=sql(`SELECT a.code FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.id='${BANK}'`);
const cQ=await customer('Client Québec','QC'),vQ=await vendor('Fournisseur Laval');
let r=await invoice(cQ,[{description:'Services',quantity:1,unitPriceCents:100000,taxable:true}]);
check('QT-01',A,'QC company invoice $1,000: GST $50 → 2100, QST $99.75 → 2115',jl(invId(r)),{'1200':114975,'2100':-5000,'2115':-9975,'4000':-100000});
r=await bill(vQ,'FQ-1',50000,code('QC'));
// $500 × 9.975% = $49.875 → $49.88 (half-up); GST $25.00; AP $574.88
check('QT-02',A,'QC bill $500: GST ITC $25.00 → 1100, QST ITR $49.88 (49.875 rounded half-up) → 1115; AP $574.88',jl(r.b?.bill?.id),{'1100':2500,'1115':4988,'2050':-57488,[EXP]:50000});
r=await importCsv('qc-sept.csv','Date,Description,Amount\n2026-09-28,Revenu Quebec QST,-49.87\n2026-09-28,Receiver General GST,-25.00\n');
rec('QT-03',A,'QC statement import (2 rows)',r.s<300?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
const q=tx('Revenu Quebec QST');
r=await o.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:q,salesTaxSettlement:'qst',salesTaxPeriodEnd:'2026-09-30',taxCode:'NO_TAX'}]}});
check('QT-04a',A,'Remittance with period end after the payment date refused',[r.s,r.b?.code],[422,'sales_tax_period_invalid']);
r=await o.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:q,salesTaxSettlement:'qst',salesTaxPeriodEnd:'2026-09-28',taxCode:'NO_TAX',applyGstHst:false,applyPst:false}]}});
check('QT-04',A,'QST remittance $49.87: Dr 2115 $99.75, Cr 1115 $49.88, Cr bank $49.87',[r.s,jl(q)],[200,{[BG]:-4987,'1115':-4988,'2115':9975}]);
check('QT-05',A,'After QST remittance: 2115 = $0.00 and 1115 = $0.00',[bal('2115'),bal('1115')],[0,0]);
const g=tx('Receiver General GST');
r=await o.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:g,salesTaxSettlement:'gst_hst',salesTaxPeriodEnd:'2026-09-28',taxCode:'NO_TAX',applyGstHst:false,applyPst:false}]}});
check('QT-06',A,'GST remittance $25.00: Dr 2100 $50, Cr 1100 $25, Cr bank $25; 2100 and 1100 back to $0',[r.s,jl(g),bal('2100'),bal('1100')],[200,{'1100':-2500,'2100':5000,[BG]:-2500},0,0]);
let ts=await taxSummary();fs.writeFileSync('/srv/gate/ev/qc-tax-summary.json',JSON.stringify(ts.b,null,1));
const tsCodes=JSON.stringify(ts.b).match(/"(?:code|accountCode)":"(\d{4})"/g)||[];
rec('QT-07',A,'GST/HST (tax) Summary report includes QST accounts 2115 and 1115',ts.s===200&&/2115/.test(JSON.stringify(ts.b))&&/1115/.test(JSON.stringify(ts.b))?'PASS':'FAIL',`status ${ts.s} codes ${[...new Set(tsCodes)].join(' ')}`);

// ================= ON company: custom GL mapping =================
use(ids.ON);EXP=sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='expense' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
r=await o.call('accounts',{method:'POST',json:{code:'2120',name:'HST Payable (custom)',type:'liability'}});
let r2=await o.call('accounts',{method:'POST',json:{code:'1120',name:'HST Recoverable (custom)',type:'asset'}});
rec('CG-01',A,'Create custom tax accounts 2120/1120',r.s<300&&r2.s<300?'PASS':'FAIL',r.s+'/'+r2.s+' '+JSON.stringify(r.b).slice(0,120));
const on=code('ON');
r=await o.call('tax-codes',{method:'PUT',json:{id:on,code:'ON',name:'Ontario HST (custom GL)',region:'ON',components:[{name:'HST',ratePercent:'13',salesAccountId:acct('2120'),purchaseAccountId:acct('1120'),purchaseRecoverable:true}]}});
check('CG-02',A,'Starter ON code edited to post HST to custom 2120/1120',r.s,200);
const cO=await customer('Ottawa Client','ON'),vO=await vendor('Ottawa Supplies');
r=await invoice(cO,[{description:'Work',quantity:1,unitPriceCents:20000,taxable:true}]);
check('CG-03',A,'ON invoice $200: HST $26.00 → 2120 (custom), nothing to 2100',jl(invId(r)),{'1200':22600,'2120':-2600,'4000':-20000});
r=await bill(vO,'OS-1',10000,on);
check('CG-04',A,'ON bill $100: HST ITC $13.00 → 1120 (custom)',jl(r.b?.bill?.id),{'1120':1300,'2050':-11300,[EXP]:10000});
const tb=await o.call('portal/trial-balance?start=2026-01-01&end=2026-09-30');const row=c=>(tb.b.rows||[]).find(x=>x.code===c);
check('CG-05',A,'Trial Balance shows 2120 Cr $26.00 and 1120 Dr $13.00',[row('2120')?.closingCreditCents,row('1120')?.closingDebitCents],[2600,1300]);
const gla=await o.call(`portal/gl-account-ledger?accountId=${acct('2120')}&start=2026-01-01&end=2026-09-30`);
rec('CG-06',A,'General Ledger for 2120 lists the $26.00 credit',gla.s===200&&/2600/.test(JSON.stringify(gla.b))?'PASS':'FAIL',`status ${gla.s} ${JSON.stringify(gla.b).slice(0,160)}`);
ts=await taxSummary();fs.writeFileSync('/srv/gate/ev/on-tax-summary.json',JSON.stringify(ts.b,null,1));
rec('CG-07',A,'Tax Summary report includes custom 2120 ($26.00) and 1120 ($13.00)',ts.s===200&&/2120/.test(JSON.stringify(ts.b))&&/1120/.test(JSON.stringify(ts.b))?'PASS':'FAIL',`status ${ts.s}`);
const ws=await o.call('workspace/summary');fs.writeFileSync('/srv/gate/ev/on-workspace-summary.json',JSON.stringify(ws.b,null,1));
rec('CG-08',A,'Dashboard tax card reports custom-account HST: other tax accounts net $13.00 owed (2120 $26 − 1120 $13)',ws.b?.summary?.taxSummary?.otherTaxNetCents===1300?'PASS':'FAIL',JSON.stringify(ws.b).match(/"[a-zA-Z]*(?:gst|Gst|hst|Hst|pst|Pst|qst|Qst|salesTax|SalesTax)[a-zA-Z]*":[^,}]*/g)?.join(' ')?.slice(0,400));

// ================= Non-registered business =================
const mk=async(name,province,extra)=>{const r=need('company',await o.call('companies',{method:'POST',json:{name,province,businessType:'sole_proprietor',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',...extra},company:false}));return r.company?.id||r.companyId||r.id};
const NR=await mk('Gate Unregistered Sole Prop','BC',{taxRegistered:false});ids.NR=NR;use(NR);
const nrCodes=sql(`SELECT COUNT(*) FROM tax_codes WHERE company_id='${CID}' AND status='active'`);
const cN=await customer('Neighbour BC','BC');
r=await invoice(cN,[{description:'Lawn care',quantity:1,unitPriceCents:10000,taxable:true}]);
const nrj=jl(invId(r));
rec('NR-01',A,'Company NOT registered for GST/HST: default taxable invoice line charges no GST/PST',!nrj['2100']&&!nrj['2110']?'PASS':'FAIL',`starter codes=${nrCodes} mode=${sql(`SELECT tax_setup_mode FROM companies WHERE id='${CID}'`)} journal=${JSON.stringify(nrj)}`);
const vN=await vendor('Unregistered supplier');EXP=sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='expense' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
r=await bill(vN,'NR-B1',10000,code('BC'));
check('NR-02',A,'Unregistered company: $100 bill with BC code — GST $5 and PST $7 added to cost ($112), no ITC',jl(r.b?.bill?.id),{'2050':-11200,[EXP]:11200});
r=await invoice(cN,[{description:'Chosen code',quantity:1,unitPriceCents:10000,taxable:true,taxCodeId:code('GST')}]);
check('NR-03',A,'Unregistered company: a code chosen explicitly on the line is still applied (GST $5)',jl(invId(r)),{'1200':10500,'2100':-500,'4000':-10000});
// ================= Cash basis =================
const CB=await mk('Gate Cash Basis ON','ON',{taxRegistered:true,taxNumber:'123456789RT0001',accountingBasis:'cash',businessType:'corporation'});ids.CB=CB;use(CB);
const cC=await customer('Cash Client','ON');r=await invoice(cC,[{description:'Consulting',quantity:1,unitPriceCents:100000,taxable:true}]);const icb=invId(r);
const atIssue=jl(icb);
r=await o.call('payments',{method:'POST',json:{type:'customer',documentId:icb,paymentDate:'2026-09-25',reference:'half',foreignAmountCents:56500,paymentAccountId:acct('1000'),operationKey:'gate-cash-'+Date.now()}});
// Half of $1,130 received: revenue $500 and HST $65 recognised (cash basis)
check('CB-01',A,'Cash basis: receipt of $565 (half) recognises HST $65.00 in 2100 and revenue $500',[bal('2100'),bal('4000')],[-6500,-50000]);
rec('CB-02',A,'Cash basis: invoice issue posting recorded for review',Object.keys(atIssue).length?'INFO':'INFO',JSON.stringify(atIssue));
fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify(ids));
save('tax.json');
