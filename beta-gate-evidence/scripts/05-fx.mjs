import {S,sql,rec,check,save,need} from './lib.mjs';import fs from 'fs';const ids=JSON.parse(fs.readFileSync('ids.json'));const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');o.cid=ids.BC;const CID=ids.BC;const A='FX';
const jl=(id)=>{const o2={};for(const l of sql(`SELECT a.code,SUM(jl.debit_cents)-SUM(jl.credit_cents) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${CID}' AND je.source_id='${id}' GROUP BY a.code HAVING SUM(jl.debit_cents)-SUM(jl.credit_cents)<>0 ORDER BY a.code`).split('\n').filter(Boolean)){const [c,v]=l.split('\t');o2[c]=+v}return o2};
let r=await o.call('currencies',{method:'POST',json:{currency:'USD',rateToBaseMicros:1350000,rateDate:'2026-09-15'}});check('FX-01',A,'Add USD at 1.35',r.s,201);
let rb=await o.call('bank-accounts',{method:'POST',json:{name:'USD Operating',currency:'USD',accountType:'bank',type:'bank',ledgerCode:'1010',ledgerName:'USD Operating'}});rec('FX-01b',A,'Add USD bank account',rb.s<300?'PASS':'FAIL',rb.s+' '+JSON.stringify(rb.b).slice(0,150));
const cust=need('c',await o.call('customers',{method:'POST',json:{name:'US Client '+Date.now(),email:'',phone:'',billingAddress:'1 Test',province:'AB',currency:'USD'}}));const cid=cust.customer?.id||cust.id;
const t=(await o.call('invoice-templates')).b;const tid=(t.templates||t.invoiceTemplates||[])[0]?.id;
r=await o.call('invoices',{method:'POST',json:{customerId:cid,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'USD',exchangeRateMicros:1350000,templateId:tid,issue:true,lines:[{description:'Consulting',quantity:1,unitPriceCents:100000,taxable:true}]}});
const iid=r.b?.invoice?.id||r.b?.id;
// USD 1,000 × 1.35 = CAD 1,350.00; GST USD 50 → CAD 67.50; AR CAD 1,417.50
check('FX-02',A,'USD 1,000 invoice at 1.35: revenue CAD $1,350.00, GST CAD $67.50, AR CAD $1,417.50',[r.s,jl(iid)],[201,{'1200':141750,'2100':-6750,'4000':-135000}]);
r=await o.call('payments',{method:'POST',json:{type:'customer',documentId:iid,paymentDate:'2026-09-25',reference:'USD wire',foreignAmountCents:105000,exchangeRateMicros:1400000,paymentAccountId:sql(`SELECT a.id FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.company_id='${CID}' AND b.currency='USD' LIMIT 1`),operationKey:'gate-fx-'+Date.now()}});
const pj=sql(`SELECT journal_entry_id FROM party_payments WHERE company_id='${CID}' AND document_id='${iid}' LIMIT 1`);
const lines=sql(`SELECT a.code,jl.debit_cents,jl.credit_cents FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE jl.journal_entry_id='${pj}' ORDER BY a.code`);
// USD 1,050 at 1.40 = CAD 1,470.00 received; AR relieved at carrying CAD 1,417.50; FX gain CAD 52.50
rec('FX-03',A,'USD 1,050 receipt at 1.40: bank CAD $1,470.00, AR −$1,417.50, FX gain $52.50',r.s<300&&/147000/.test(lines)&&/141750/.test(lines)&&/5250/.test(lines)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120)+' lines: '+lines.replace(/\n/g,' | '));
check('FX-04',A,'USD invoice fully settled (foreign balance 0)',sql(`SELECT foreign_balance_cents FROM invoices WHERE id='${iid}'`),'0');
save('tax.json');
