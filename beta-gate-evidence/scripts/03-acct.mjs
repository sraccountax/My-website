// Beta gate: accounting controls + tax arithmetic on the HTTPS gate host.
// Every expected figure below is written out by hand (dollars/cents computed on paper),
// never produced by Tegh's own calculation functions.
import {S,sql,rec,check,save,need} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
let CID='';const use=c=>{CID=c;o.cid=c};
const acct=code=>sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${code}'`);
const gl=()=>{const o2={};for(const l of sql(`SELECT a.code,SUM(jl.debit_cents)-SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' GROUP BY a.code HAVING SUM(jl.debit_cents)-SUM(jl.credit_cents)<>0 ORDER BY a.code`).split('\n').filter(Boolean)){const [c,v]=l.split('\t');o2[c]=+v}return o2};
const jl=(id)=>{const o2={};for(const l of sql(`SELECT a.code,SUM(jl.debit_cents)-SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.source_id='${id}' GROUP BY a.code HAVING SUM(jl.debit_cents)-SUM(jl.credit_cents)<>0 ORDER BY a.code`).split('\n').filter(Boolean)){const [c,v]=l.split('\t');o2[c]=+v}return o2};
const code=c=>sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='${c}' AND status='active'`);
const tmpl=async()=>{const t=await o.call('invoice-templates');return (t.b?.templates||t.b?.invoiceTemplates||[])[0]?.id};
const customer=async(name,province)=>{const r=need('customer',await o.call('customers',{method:'POST',json:{name,email:'',phone:'',billingAddress:'1 Test St',province}}));return r.customer?.id||r.id};
const vendor=async name=>{const r=need('vendor',await o.call('vendors',{method:'POST',json:{name,email:'',address:'',defaultTermsDays:30,currency:'CAD',defaultExpenseAccountId:null}}));return r.vendor?.id||r.id};
const invoice=async(customerId,lines,extra={})=>o.call('invoices',{method:'POST',json:{customerId,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'CAD',templateId:await tmpl(),issue:true,lines,...extra}});
const invId=r=>r.b?.invoice?.id||r.b?.id;
const bill=async(vendorId,number,amount,taxCodeId,mode='exclusive',extra={})=>o.call('bills',{method:'POST',json:{vendorId,number,billDate:'2026-09-16',dueDate:'2026-10-16',categoryAccountId:acct(EXP),currency:'CAD',taxEntryMode:mode,taxCodeId,foreignAmountCents:amount,issue:true,...extra}});
const A='ACCT';let EXP='';const expected={};const add=(m)=>{for(const [k,v] of Object.entries(m))expected[k]=(expected[k]||0)+v};

// ================= Company BC (accrual, codes mode, starter codes) =================
use(ids.BC);
EXP=sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='expense' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
const EQ=sql(`SELECT code FROM accounts WHERE company_id='${CID}' AND account_type='equity' AND is_control=0 AND active=1 ORDER BY code LIMIT 1`);
const BANK=sql(`SELECT b.id FROM bank_accounts b WHERE b.company_id='${CID}' AND b.account_type='bank' ORDER BY b.created_at LIMIT 1`);const BANKGL=sql(`SELECT a.code FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.id='${BANK}'`);
console.log('expense',EXP,'equity',EQ,'bank gl',BANKGL);
const qcStart=sql(`SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order) FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id WHERE t.company_id='${CID}' AND t.code='QC'`);
check('TX-00b',A,'DEF-08: BC company starter code for QC is GST only (not registered with Revenu Québec)',qcStart,'GST');
check('TX-00c',A,'DEF-08: BC company keeps BC PST on its home-province code',sql(`SELECT GROUP_CONCAT(c.name ORDER BY c.sort_order) FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id WHERE t.company_id='${CID}' AND t.code='BC'`),'GST,PST');
{const r=await o.call('tax-codes',{method:'PUT',json:{id:sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='QC'`),code:'QC',name:'Quebec GST + QST (registered)',region:'QC',components:[{name:'GST',ratePercent:'5',salesAccountId:acct('2100'),purchaseAccountId:acct('1100'),purchaseRecoverable:true},{name:'QST',ratePercent:'9.975',salesAccountId:acct('2115'),purchaseAccountId:acct('1115'),purchaseRecoverable:true}]}});
 check('TX-00d',A,'After registering in Quebec, owner edits the QC code to add QST 9.975% → 2115/1115',r.s,200);}
check('TX-00',A,'Starter codes present in new BC company',sql(`SELECT GROUP_CONCAT(code ORDER BY code) FROM tax_codes WHERE company_id='${CID}' AND status='active'`),'AB,BC,GST,MB,NB,NL,NS,NT,NU,ON,PE,QC,SK,YT');
// --- Opening balances: $10,000 cash / $10,000 equity
let r=await o.call('setup/opening-balances',{method:'PUT',json:{accountId:acct(BANKGL),amountCents:1000000}});
let r2=await o.call('setup/opening-balances',{method:'PUT',json:{accountId:acct(EQ),amountCents:-1000000}});
let r3=await o.call('setup/opening-balances',{method:'POST',json:{action:'post'}});
const ob=gl();
rec('AC-01',A,'Opening balances post a balanced journal: Dr bank $10,000 / Cr equity $10,000',ob[BANKGL]===1000000&&ob[EQ]===-1000000?'PASS':'FAIL',`PUT ${r.s}/${r2.s} POST ${r3.s} ${JSON.stringify(r3.b).slice(0,160)} GL=${JSON.stringify(ob)}`);
if(ob[BANKGL]===1000000)add({[BANKGL]:1000000,[EQ]:-1000000});
const again=await o.call('setup/opening-balances',{method:'POST',json:{action:'post'}});
rec('AC-02',A,'Posting the same opening balances twice does not double them',gl()[BANKGL]===ob[BANKGL]?'PASS':'FAIL',`second post ${again.s} ${JSON.stringify(again.b).slice(0,140)}; bank now ${gl()[BANKGL]}`);

// --- Parties
const cBC=await customer('Pacific Client BC','BC'),cON=await customer('Toronto Client','ON'),cAB=await customer('Calgary Client','AB'),cQC=await customer('Client Montréal','QC');
const vBC=await vendor('Coastal Supplies'),vQC=await vendor('Fournisseur Québec');

// --- Tax arithmetic controls (hand-computed)
r=await invoice(cON,[{description:'Consulting',quantity:1,unitPriceCents:100000,taxable:true}]);const I_ON=invId(r);
check('TX-01',A,'ON customer $1,000: HST $130.00 to 2100; AR $1,130.00',jl(I_ON),{'1200':113000,'2100':-13000,'4000':-100000});add({'1200':113000,'2100':-13000,'4000':-100000});
r=await invoice(cBC,[{description:'Chairs',quantity:1,unitPriceCents:100000,taxable:true}]);const I_BC=invId(r);
check('TX-02',A,'BC customer $1,000: GST $50.00 → 2100, PST $70.00 → 2110; AR $1,120.00',jl(I_BC),{'1200':112000,'2100':-5000,'2110':-7000,'4000':-100000});add({'1200':112000,'2100':-5000,'2110':-7000,'4000':-100000});
r=await invoice(cQC,[{description:'Services',quantity:1,unitPriceCents:100000,taxable:true}]);const I_QC=invId(r);
check('TX-03',A,'QC customer $1,000: GST $50.00 → 2100, QST $99.75 → 2115; AR $1,149.75',jl(I_QC),{'1200':114975,'2100':-5000,'2115':-9975,'4000':-100000});add({'1200':114975,'2100':-5000,'2115':-9975,'4000':-100000});
r=await invoice(cAB,[{description:'Design',quantity:3,unitPriceCents:16667,taxable:true}]);const I_AB=invId(r);
// 3 x $166.67 = $500.01; GST 5% = $25.0005 → $25.00; AR $525.01
check('TX-04',A,'AB customer 3 × $166.67 = $500.01: GST $25.00; AR $525.01',jl(I_AB),{'1200':52501,'2100':-2500,'4000':-50001});add({'1200':52501,'2100':-2500,'4000':-50001});
r=await invoice(cBC,[{description:'Exempt item (No tax)',quantity:1,unitPriceCents:20000,taxable:true,taxCodeId:''},{description:'Taxable item',quantity:2,unitPriceCents:5000,taxable:true}]);const I_MIX=invId(r);
// No-tax line $200; taxable $100 → GST $5, PST $7
check('TX-05',A,'Exemption: No-tax line $200 + BC taxable $100 → GST $5, PST $7 only on the taxable line',jl(I_MIX),{'1200':31200,'2100':-500,'2110':-700,'4000':-30000});add({'1200':31200,'2100':-500,'2110':-700,'4000':-30000});

// --- invalid / inactive / cross-company taxCodeId
const other=sql(`SELECT id FROM tax_codes WHERE company_id='${ids.QC}' AND code='QC'`);
for(const [tid,label,cid] of [['taxcode_doesnotexist000000000000','non-existent','TX-06'],[other,'another company\'s','TX-07']]){
  const b0=+sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${CID}'`);
  r=await invoice(cBC,[{description:'x',quantity:1,unitPriceCents:1000,taxable:true,taxCodeId:tid}]);
  rec(cid,A,`Invoice with ${label} taxCodeId refused, nothing saved`,r.s===422&&+sql(`SELECT COUNT(*) FROM invoices WHERE company_id='${CID}'`)===b0?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
  r=await bill(vBC,'BAD-'+cid,1000,tid);rec(cid+'b',A,`Vendor invoice with ${label} taxCodeId refused`,r.s===422?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
  r=await o.call('expenses',{method:'POST',json:{vendor:'X',date:'2026-09-16',expenseDate:'2026-09-16',categoryAccountId:acct(EXP),paidFromAccountId:acct(BANKGL),currency:'CAD',amountCents:1000,foreignAmountCents:1000,taxEntryMode:'inclusive',taxCodeId:tid}});
  rec(cid+'e',A,`Expense with ${label} taxCodeId refused`,r.s===422?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
}
// inactive: create a temporary code, deactivate by deleting (unused → deleted; so use it once then delete → inactive)
r=need('tmp code',await o.call('tax-codes',{method:'POST',json:{code:'TMP',name:'Temp 1%',components:[{name:'TMPX',ratePercent:'1',salesAccountId:acct('2100'),purchaseAccountId:acct('1100'),purchaseRecoverable:true}]}}));
const TMP=r.taxCode?.id||code('TMP');
r=await invoice(cBC,[{description:'uses TMP',quantity:1,unitPriceCents:10000,taxable:true,taxCodeId:TMP}]);const I_TMP=invId(r);
check('TX-08a',A,'Temporary 1% code on $100 → $1.00 to 2100',jl(I_TMP),{'1200':10100,'2100':-100,'4000':-10000});add({'1200':10100,'2100':-100,'4000':-10000});
r=await o.call('tax-codes',{method:'DELETE',json:{id:TMP}});
const st=sql(`SELECT status FROM tax_codes WHERE id='${TMP}'`);
r=await invoice(cBC,[{description:'x',quantity:1,unitPriceCents:1000,taxable:true,taxCodeId:TMP}]);
rec('TX-08',A,'Used code removed → inactive; invoice with inactive taxCodeId refused',st==='inactive'&&r.s===422?'PASS':'FAIL',`status=${st} invoice ${r.s} ${JSON.stringify(r.b).slice(0,100)}`);
const tb=await bill(vBC,'BAD-INACT',1000,TMP);rec('TX-08b',A,'Vendor invoice with inactive taxCodeId refused',tb.s===422?'PASS':'FAIL',String(tb.s));
check('TX-08c',A,'Earlier invoice keeps its 1% snapshot after the code became inactive',jl(I_TMP),{'1200':10100,'2100':-100,'4000':-10000});

// --- Purchases
r=await bill(vBC,'B-BC-1',100000,code('BC'));const B_BC=r.b?.bill?.id;
check('TX-09',A,'BC purchase $1,000 (BC code): ITC $50 → 1100; PST $70 in cost (expense $1,070); AP $1,120',jl(B_BC),{'1100':5000,'2050':-112000,[EXP]:107000});add({'1100':5000,'2050':-112000,[EXP]:107000});
r=await bill(vQC,'B-QC-1',100000,code('QC'));const B_QC=r.b?.bill?.id;
check('TX-10',A,'QC purchase $1,000 (QC code): GST ITC $50 → 1100, QST ITR $99.75 → 1115; AP $1,149.75',jl(B_QC),{'1100':5000,'1115':9975,'2050':-114975,[EXP]:100000});add({'1100':5000,'1115':9975,'2050':-114975,[EXP]:100000});
r=await bill(vBC,'B-GST-1',50000,code('GST'));const B_G=r.b?.bill?.id;
check('TX-11',A,'GST-only supplier $500: ITC $25; AP $525',jl(B_G),{'1100':2500,'2050':-52500,[EXP]:50000});add({'1100':2500,'2050':-52500,[EXP]:50000});
r=await o.call('expenses',{method:'POST',json:{vendor:'Toronto taxi',date:'2026-09-16',expenseDate:'2026-09-16',categoryAccountId:acct(EXP),paidFromAccountId:acct(BANKGL),currency:'CAD',amountCents:11300,foreignAmountCents:11300,taxEntryMode:'inclusive',taxCodeId:code('ON')}});
const E1=r.b?.expense?.id||r.b?.id;
check('TX-12',A,'Expense $113.00 tax-included with ON code: expense $100.00, HST ITC $13.00',jl(E1),{'1100':1300,[BANKGL]:-11300,[EXP]:10000});add({'1100':1300,[BANKGL]:-11300,[EXP]:10000});

// --- Credit note and payments
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',sourceId:I_BC,date:'2026-09-20',subtotalCents:20000,taxCents:2400,memo:'Return',operationKey:'gate-cn-bc-000001',lines:[{description:'Return',quantityMilli:1000,foreignUnitPriceCents:20000}]}});
const CN=r.b?.note?.id||r.b?.id;if(r.s>=300)console.log('CREDIT NOTE',r.s,JSON.stringify(r.b).slice(0,300));const p=await o.call('accounting-notes',{method:'PATCH',json:{noteId:CN,action:'post'}});
check('TX-13',A,'Credit note $200 + tax on BC invoice: reverses GST $10.00 (2100) and PST $14.00 (2110); AR −$224.00',jl(CN),{'1200':-22400,'2100':1000,'2110':1400,'4000':20000});add({'1200':-22400,'2100':1000,'2110':1400,'4000':20000});
const payKey='gate-pay-bc-'+Date.now();
const pay=()=>o.call('payments',{method:'POST',json:{type:'customer',documentId:I_BC,paymentDate:'2026-09-25',reference:'EFT 1',foreignAmountCents:89600,paymentAccountId:acct(BANKGL),operationKey:payKey}});
const rs=await Promise.all([pay(),pay(),pay(),pay(),pay()]);
const nPay=+sql(`SELECT COUNT(*) FROM party_payments WHERE company_id='${CID}' AND document_id='${I_BC}' AND status<>'reversed'`);
rec('AC-03',A,'Five simultaneous identical receipt submissions (same operation key) record one payment',nPay===1?'PASS':'FAIL',`statuses ${rs.map(x=>x.s).join(',')} payments=${nPay}`);
add({[BANKGL]:89600,'1200':-89600});
check('AC-04',A,'Invoice fully settled after credit note + receipt: balance due $0.00',sql(`SELECT balance_cents FROM invoices WHERE id='${I_BC}'`),'0');
r=await o.call('payments',{method:'POST',json:{type:'customer',documentId:I_BC,paymentDate:'2026-09-26',reference:'EFT 2',foreignAmountCents:100,paymentAccountId:acct(BANKGL),operationKey:'gate-over-'+Date.now()}});
rec('AC-05',A,'Over-payment of a settled invoice refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await o.call('payments',{method:'POST',json:{type:'vendor',documentId:B_G,paymentDate:'2026-09-26',reference:'CHQ 9',foreignAmountCents:52500,paymentAccountId:acct(BANKGL),operationKey:'gate-vpay-'+Date.now()}});
rec('AC-06',A,'Vendor payment $525 settles GST-only bill',r.s<300&&sql(`SELECT balance_cents FROM bills WHERE id='${B_G}'`)==='0'?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));add({[BANKGL]:-52500,'2050':52500});

// --- Real statement import (upload → preview → approve)
async function importCsv(name,csv){const data=Buffer.from(csv);const st=need('upload-start',await o.call('imports/upload-start',{method:'POST',json:{filename:name,size:data.length}}));
  need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:st.uploadId,offset:0,data:data.toString('base64')}}));
  const pv=need('finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:st.uploadId,bankAccountId:BANK,exchangeRateMicros:1000000,mode:'preview'}}));
  const rows=pv.preview.rows||[];const sel=rows.filter(x=>x.selectionKey&&x.eligible!==false&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
  const ap=await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.preview.id,operationKey:'statement_'+Date.now()+Math.random().toString(36).slice(2,10),selectedKeys:sel,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}});
  return {pv:pv.preview,rows,sel,ap}}
const csv='Date,Description,Amount\n2026-09-20,QC client deposit,1149.75\n2026-09-21,Quebec supplier,-1149.75\n2026-09-22,BC furniture supplier,-1120.00\n2026-09-23,Bank service fee,-25.00\n2026-09-24,Owner transfer,-500.00\n';
let im=await importCsv('gate-statement-sept.csv',csv);
rec('BK-01',A,'CSV statement import: preview shows 5 rows, approve imports 5',im.rows.length===5&&im.ap.s<300&&+sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CID}'`)===5?'PASS':'FAIL',`rows=${im.rows.length} selected=${im.sel.length} approve=${im.ap.s} ${JSON.stringify(im.ap.b).slice(0,160)}`);
im=await importCsv('gate-statement-sept-again.csv',csv);
rec('BK-02',A,'Re-importing the same statement: all 5 rows flagged duplicate, none imported',im.rows.filter(x=>x.duplicate||x.requiresDuplicateReview).length===5&&+sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CID}'`)===5?'PASS':'FAIL',`dups=${im.rows.filter(x=>x.duplicate||x.requiresDuplicateReview).length} approve=${im.ap.s} count=${sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${CID}'`)}`);
const tx=d=>sql(`SELECT id FROM bank_transactions WHERE company_id='${CID}' AND description='${d}' ORDER BY created_at LIMIT 1`);
const post=(id,accountId,tc)=>o.call('bank-transactions/post',{method:'POST',json:{decisions:[{id,accountId,taxCode:tc?'CODE':'NO_TAX',applyGstHst:false,applyPst:false,taxCodeId:tc||''}]}});
const T1=tx('QC client deposit');r=await post(T1,acct('4000'),code('QC'));
check('TX-14',A,'Bank deposit $1,149.75 with QC code: income $1,000, GST $50 → 2100, QST $99.75 → 2115',jl(T1),{[BANKGL]:114975,'2100':-5000,'2115':-9975,'4000':-100000});add({[BANKGL]:114975,'2100':-5000,'2115':-9975,'4000':-100000});
const T2=tx('Quebec supplier');r=await post(T2,acct(EXP),code('QC'));
check('TX-15',A,'Bank purchase $1,149.75 with QC code: ITC $50 → 1100, QST ITR $99.75 → 1115',jl(T2),{[BANKGL]:-114975,'1100':5000,'1115':9975,[EXP]:100000});add({[BANKGL]:-114975,'1100':5000,'1115':9975,[EXP]:100000});
const T3=tx('BC furniture supplier');r=await post(T3,acct(EXP),code('BC'));
check('TX-16',A,'Bank purchase $1,120 with BC code: ITC $50, PST $70 in cost ($1,070)',jl(T3),{[BANKGL]:-112000,'1100':5000,[EXP]:107000});add({[BANKGL]:-112000,'1100':5000,[EXP]:107000});
const T4=tx('Bank service fee');const par=await Promise.all([post(T4,acct(EXP),''),post(T4,acct(EXP),''),post(T4,acct(EXP),'')]);
rec('AC-07',A,'Three simultaneous posts of the same bank line create one journal',+sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}' AND source_id='${T4}'`)===1?'PASS':'FAIL',`statuses ${par.map(x=>x.s).join(',')} journals=${sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}' AND source_id='${T4}'`)}`);
add({[BANKGL]:-2500,[EXP]:2500});
const T5=tx('Owner transfer');r=await post(T5,acct(BANKGL),'');
rec('AC-08',A,'Posting a bank line to its own bank GL account (same-account transfer) refused',r.s>=400&&!Object.keys(jl(T5)).length?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,140));
r=await post(T5,acct(EQ),'');add({[BANKGL]:-50000,[EQ]:50000});
rec('AC-08b',A,'Owner transfer posted to equity instead',r.s===200?'PASS':'FAIL',String(r.s));
r=await post(T1,acct('4000'),code('QC'));
rec('AC-09',A,'Re-posting an already posted bank line refused / no second journal',+sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}' AND source_id='${T1}'`)===1?'PASS':'FAIL',r.s+' journals='+sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}' AND source_id='${T1}'`));

// --- Manual journals
r=await o.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'Gate accrual',lines:[{accountId:acct(EXP),debitCents:12345,creditCents:0},{accountId:acct(EQ),debitCents:0,creditCents:12345}]}});
rec('AC-10',A,'Balanced manual journal $123.45 posts',r.s<300?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));if(r.s<300)add({[EXP]:12345,[EQ]:-12345});
r=await o.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'Unbalanced',lines:[{accountId:acct(EXP),debitCents:10000,creditCents:0},{accountId:acct(EQ),debitCents:0,creditCents:9999}]}});
rec('AC-11',A,'Unbalanced manual journal ($100.00 / $99.99) refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
r=await o.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'AR direct',lines:[{accountId:acct('1200'),debitCents:10000,creditCents:0},{accountId:acct('4000'),debitCents:0,creditCents:10000}]}});
rec('AC-12',A,'Manual journal to AR control account 1200 refused (subledger protection)',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));if(r.s<300)add({'1200':10000,'4000':-10000});
r=await o.call('advanced/recurring-journals',{method:'POST',json:{name:'AR accrual',frequency:'monthly',nextRunDate:'2026-10-01',memo:'x',lines:[{accountId:acct('1200'),debitCents:5000,creditCents:0},{accountId:acct('4000'),debitCents:0,creditCents:5000}]}});
rec('AC-12b',A,'Recurring journal template posting to AR control 1200 refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
r=await o.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'AP direct',lines:[{accountId:acct(EXP),debitCents:10000,creditCents:0},{accountId:acct('2050'),debitCents:0,creditCents:10000}]}});
rec('AC-12c',A,'Manual journal to AP control account 2050 refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));if(r.s<300)add({[EXP]:10000,'2050':-10000});
r=await o.call('journals',{method:'POST',json:{date:'2026-12-31',memo:'Future',lines:[{accountId:acct(EXP),debitCents:100,creditCents:0},{accountId:acct(EQ),debitCents:0,creditCents:100}]}});
rec('AC-13',A,'Future-dated journal refused',r.s>=400?'PASS':'FAIL',String(r.s));

// --- Period lock
r=await o.call('period-close',{method:'POST',json:{closedThroughDate:'2026-08-31'}});
rec('AC-14',A,'Close books through 2026-08-31',r.s<300?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await o.call('journals',{method:'POST',json:{date:'2026-08-15',memo:'In locked period',lines:[{accountId:acct(EXP),debitCents:100,creditCents:0},{accountId:acct(EQ),debitCents:0,creditCents:100}]}});
rec('AC-15',A,'Journal dated in locked period refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await invoice(cBC,[{description:'locked',quantity:1,unitPriceCents:1000,taxable:true}],{issueDate:'2026-08-20',dueDate:'2026-09-20'});
rec('AC-16',A,'Invoice dated in locked period refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await bill(vBC,'B-LOCK',1000,'','exclusive',{billDate:'2026-08-20',dueDate:'2026-09-20'});
rec('AC-17',A,'Vendor invoice dated in locked period refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await o.call('payments',{method:'POST',json:{type:'customer',documentId:I_ON,paymentDate:'2026-08-30',reference:'late',foreignAmountCents:1000,paymentAccountId:acct(BANKGL),operationKey:'gate-lockpay-'+Date.now()}});
rec('AC-18',A,'Receipt dated in locked period refused',r.s>=400?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));

// --- Ledger integrity (SQL, independent of report code)
const unbal=sql(`SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id HAVING SUM(jl.debit_cents)<>SUM(jl.credit_cents)) x`);
check('AC-19',A,'Every journal entry in the database balances (debits = credits)',unbal,'0');
const G=gl();
check('AC-20',A,'GL balances equal the independently accumulated expected ledger (BC company)',G,Object.fromEntries(Object.entries(expected).filter(([,v])=>v!==0).sort()));
fs.writeFileSync('/srv/gate/ev/bc-expected-vs-gl.json',JSON.stringify({expected,gl:G},null,1));
// hand check of the key tax accounts
check('AC-21',A,'2100 GST/HST payable net: 130+50+50+25+5+1−10+50 collected, 50+50+25+13+50+50 ITCs in 1100',[G['2100'],G['1100']],[-30100,23800]);
check('AC-22',A,'2110 PST $70+$7−$14 = $63.00; 2115 QST $99.75+$99.75 = $199.50; 1115 QST ITR $99.75+$99.75 = $199.50',[G['2110'],G['2115'],G['1115']],[-6300,-19950,19950]);
// AR/AP control vs subledger
const arSub=+sql(`SELECT COALESCE(SUM(balance_cents),0) FROM invoices WHERE company_id='${CID}' AND status NOT IN ('draft','void','voided')`);
check('AC-23',A,'AR control 1200 = open customer invoices (SQL subledger): $1,130 + $1,149.75 + $525.01 + $312 + $101 = $3,217.76',[G['1200'],arSub],[321776,321776]);
const apSub=+sql(`SELECT COALESCE(SUM(balance_cents),0) FROM bills WHERE company_id='${CID}' AND status NOT IN ('draft','void','voided')`);
check('AC-24',A,'AP control 2050 = open vendor invoices: $1,120 + $1,149.75',[-G['2050'],apSub],[226975,226975]);
// Report APIs vs ledger
const ag=await o.call('portal/aging?type=receivable&asOf=2026-09-30');const agTot=(ag.b?.totals||[]).reduce((a,b)=>a+b,0);
rec('AC-25',A,'AR ageing report total = AR subledger ($3,217.76) and = AR control 1200',+agTot===321776&&G['1200']===321776?'PASS':'FAIL',`status ${ag.s} total=${agTot} keys=${Object.keys(ag.b||{}).join(',')}`);
const ap=await o.call('portal/aging?type=payable&asOf=2026-09-30');const apTot=(ap.b?.totals||[]).reduce((a,b)=>a+b,0);
rec('AC-26',A,'AP ageing report total = AP control ($2,269.75)',+apTot===226975?'PASS':'FAIL',`status ${ap.s} total=${apTot}`);
const tbr=await o.call('portal/trial-balance?start=2026-01-01&end=2026-09-30');
fs.writeFileSync('/srv/gate/ev/bc-tb.json',JSON.stringify(tbr.b,null,1));
const rows=tbr.b?.rows||tbr.b?.trialBalance?.rows||tbr.b?.accounts||[];
const tbMap={};for(const x of rows){const c=x.code||x.accountCode;const net=(+(x.closingDebitCents??0))-(+(x.closingCreditCents??0));if(c&&net)tbMap[c]=net}
check('AC-27',A,'Trial Balance report equals the GL computed by SQL, account by account',tbMap,G);
const td=rows.reduce((s,x)=>s+(+(x.closingDebitCents??0)),0),tc=rows.reduce((s,x)=>s+(+(x.closingCreditCents??0)),0);
check('AC-28',A,'Trial Balance debits = credits',td,tc);
const bs=await o.call('reports/v5600/financial?kind=balance-sheet&reportKey=balance_sheet&asOf=2026-09-30&start=2026-01-01&end=2026-09-30');
fs.writeFileSync('/srv/gate/ev/bc-bs.json',JSON.stringify(bs.b,null,1));
const bsT=bs.b?.report?.totals||bs.b?.totals||{};
const sqlAssets=+sql(`SELECT SUM(jl.debit_cents-jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND a.account_type='asset' AND je.status='posted'`);
rec('AC-29',A,'Balance Sheet: Assets = Liabilities + Equity, difference $0, assets = SQL asset total',bsT.differenceCents===0&&bsT.assetsCents===bsT.liabilitiesEquityCents&&bsT.assetsCents===sqlAssets?'PASS':'FAIL','sqlAssets='+sqlAssets+' '+`status ${bs.s} totals ${JSON.stringify(bsT).slice(0,300)}`);
// History stability: rerun TB, compare; edit a code, compare posted history
const tb2=await o.call('portal/trial-balance?start=2026-01-01&end=2026-09-30');
check('AC-30',A,'Trial Balance identical on re-run',JSON.stringify(tb2.b?.rows||tb2.b?.accounts||[]),JSON.stringify(rows));
const qc=sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='QC'`);
r=await o.call('tax-codes',{method:'PUT',json:{id:qc,code:'QC',name:'Quebec (edited rate)',region:'QC',components:[{name:'GST',ratePercent:'5',salesAccountId:acct('2100'),purchaseAccountId:acct('1100'),purchaseRecoverable:true},{name:'QST',ratePercent:'10',salesAccountId:acct('2115'),purchaseAccountId:acct('1115'),purchaseRecoverable:true}]}});
check('AC-31',A,'Editing the QC code after posting leaves posted history unchanged',[r.s,JSON.stringify(gl())===JSON.stringify(G)],[200,true]);
r=await o.call('tax-codes',{method:'PUT',json:{id:qc,code:'QC',name:'Quebec GST + QST',region:'QC',components:[{name:'GST',ratePercent:'5',salesAccountId:acct('2100'),purchaseAccountId:acct('1100'),purchaseRecoverable:true},{name:'QST',ratePercent:'9.975',salesAccountId:acct('2115'),purchaseAccountId:acct('1115'),purchaseRecoverable:true}]}});
save('acct.json');
fs.writeFileSync('/srv/gate/t/state-bc.json',JSON.stringify({I_ON,I_BC,I_QC,I_AB,I_MIX,B_BC,B_QC,B_G,CN,T1,T2,T3,T4,T5,cBC,cQC,vBC,vQC,EXP,EQ,BANK,BANKGL}));
