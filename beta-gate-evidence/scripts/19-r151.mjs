// R151 (server): itemized vendor invoices (GL account + tax per line), credit/debit notes with GL per line,
// notes linked to an original or recorded with a typed reference, draft edits, issued-detail edits.
// Every expected figure is worked out by hand in the comments below; none is computed with Tegh's code.
import {S,sql,check,rec,save,need} from './lib.mjs';
const A='R151';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const stamp=Date.now().toString(36);
const mkCo=async(name,basis)=>need('co',await o.call('companies',{method:'POST',company:false,json:{name,province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:basis,moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'123456789RT0001',coaMode:'default'}})).company.id;
const CID=await mkCo('R151 Documents Inc '+stamp,'accrual');o.cid=CID;
const acct=(code,cid=CID)=>sql(`SELECT id FROM accounts WHERE company_id='${cid}' AND code='${code}'`);
const tcode=(c,cid=CID)=>sql(`SELECT id FROM tax_codes WHERE company_id='${cid}' AND code='${c}'`);
const tpl=sql(`SELECT id FROM invoice_templates WHERE company_id='${CID}' LIMIT 1`);
// Journal of a source document as "code:debit/credit" sorted, from the database.
const jl=(type,id,cid=CID)=>sql(`SELECT CONCAT(a.code,':',SUM(l.debit_cents),'/',SUM(l.credit_cents)) FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id=e.id JOIN accounts a ON a.id=l.account_id WHERE e.company_id='${cid}' AND e.source_type='${type}' AND e.source_id='${id}' GROUP BY a.code ORDER BY a.code`).split('\n').filter(Boolean);
const cust=async(name,cid=CID,s=o)=>need('cust',await s.call('customers',{method:'POST',json:{name,province:'ON',country:'Canada',paymentTerms:30},headers:{'X-Company-Id':cid},company:false})).customer.id;
const vend=async(name,cid=CID,s=o)=>need('vend',await s.call('vendors',{method:'POST',json:{name,province:'ON',country:'Canada',paymentTerms:30},headers:{'X-Company-Id':cid},company:false})).vendor.id;
// A custom code with a recoverable GST 5% and a non-recoverable PST 7% (cost).
need('gpst',await o.call('tax-codes',{method:'POST',json:{code:'GPST',name:'GST + PST test',components:[{name:'GST',ratePercent:5,salesAccountId:acct('2100'),purchaseAccountId:acct('1100'),purchaseRecoverable:true},{name:'PST',ratePercent:7,salesAccountId:acct('2110'),purchaseRecoverable:false}]}}));
const ON=tcode('ON'),GPST=tcode('GPST');
const V=await vend('R151 Supplies Co'),C=await cust('R151 Client Ltd');

// ---------- VI-01: itemized vendor invoice, three accounts, three tax treatments ----------
// L1 Phone 2 × $45.50 = $91.00 @ ON 13%  → tax $11.83 (9100×0.13=1183)
// L2 Paper 3 × $12.25 = $36.75 @ GPST    → GST round(3675×0.05)=184 (183.75), PST round(3675×0.07)=257 (257.25, cost)
// L3 Laptop 1 × $800.00, no tax           → 80000
// Net 9100+3675+80000=92775; tax 1183+184+257=1624; total 94399.
// Posting: Dr 6300 9100 · Dr 6400 3675+257=3932 · Dr 1500 80000 · Dr 1100 1183+184=1367 · Cr 2050 94399.
const vi1Lines=[{description:'Phone service',quantity:2,unitPriceCents:4550,accountId:acct('6300'),taxCodeId:ON},{description:'Printer paper',quantity:3,unitPriceCents:1225,accountId:acct('6400'),taxCodeId:GPST},{description:'Laptop',quantity:1,unitPriceCents:80000,accountId:acct('1500'),taxCodeId:''}];
let r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-1-'+stamp,billDate:'2026-09-15',currency:'CAD',taxEntryMode:'exclusive',lines:vi1Lines,issue:true}});
const VI1=r.b?.bill?.id;
check('DOC-01',A,'Itemized vendor invoice saves and posts (status open)',[r.s,r.b?.bill?.status],[201,'open']);
check('DOC-02',A,'Header totals are the sum of the lines: net $927.75, tax $16.24, total $943.99',sql(`SELECT CONCAT(subtotal_cents,'/',tax_cents,'/',total_cents,'/',balance_cents) FROM bills WHERE id='${VI1}'`),'92775/1624/94399/94399');
check('DOC-03',A,'Each line posts to its own GL account; recoverable tax to 1100; non-recoverable PST added to the paper line (6400)',jl('bill',VI1),['1100:1367/0','1500:80000/0','2050:0/94399','6300:9100/0','6400:3932/0']);
check('DOC-04',A,'Three lines stored with their accounts, tax and cost-tax',sql(`SELECT GROUP_CONCAT(CONCAT((SELECT code FROM accounts WHERE id=account_id),':',amount_cents,':',tax_cents,':',cost_tax_cents) ORDER BY sort_order) FROM bill_lines WHERE bill_id='${VI1}'`),'6300:9100:1183:0,6400:3675:441:257,1500:80000:0:0');
check('DOC-05',A,'Header keeps the largest line’s account (1500) for registers',sql(`SELECT a.code FROM bills b JOIN accounts a ON a.id=b.category_account_id WHERE b.id='${VI1}'`),'1500');
const ws=(await o.call('workspace')).b;const wb=(ws.bills||[]).find(b=>b.id===VI1);
check('DOC-06',A,'Workspace returns the lines for editing',[wb?.itemized,wb?.lines?.length,wb?.lines?.[1]?.description],[true,3,'Printer paper']);

// ---------- VI-02: draft itemized invoice edited in full, then issued ----------
// Draft: 1 × $100 @ 6400 ON. Edited to: 1 × $200 @ 6700 ON (tax 2600) + 1 × $50 @ 6400 no tax.
// Issue → Dr 6700 20000 · Dr 6400 5000 · Dr 1100 2600 · Cr 2050 27600.
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-2-'+stamp,billDate:'2026-09-16',currency:'CAD',lines:[{description:'Draft line',quantity:1,unitPriceCents:10000,accountId:acct('6400'),taxCodeId:ON}]}});
const VI2=r.b?.bill?.id;
r=await o.call('bills',{method:'PATCH',json:{action:'update',billId:VI2,vendorId:V,number:'VI-2-'+stamp,billDate:'2026-09-16',currency:'CAD',lines:[{description:'Legal review',quantity:1,unitPriceCents:20000,accountId:acct('6700'),taxCodeId:ON},{description:'Courier',quantity:1,unitPriceCents:5000,accountId:acct('6400'),taxCodeId:''}]}});
check('DOC-07',A,'Draft vendor invoice edited in full (lines replaced): total $276.00',[r.s,sql(`SELECT CONCAT(total_cents,'/',(SELECT COUNT(*) FROM bill_lines WHERE bill_id='${VI2}')) FROM bills WHERE id='${VI2}'`)],[200,'27600/2']);
r=await o.call('bills',{method:'PATCH',json:{action:'issue',billId:VI2}});
check('DOC-08',A,'Issued edited draft posts the edited lines',[r.s,jl('bill',VI2)],[200,['1100:2600/0','2050:0/27600','6400:5000/0','6700:20000/0']]);

// ---------- VI-03: amounts entered including tax ----------
// 1 × $113.00 incl. ON 13% → net 10000, tax 1300.
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-3-'+stamp,billDate:'2026-09-17',currency:'CAD',taxEntryMode:'inclusive',lines:[{description:'Internet',quantity:1,unitPriceCents:11300,accountId:acct('6300'),taxCodeId:ON}],issue:true}});
const VI3=r.b?.bill?.id;
check('DOC-09',A,'Tax-inclusive line: $113.00 → net $100.00 + HST $13.00',jl('bill',VI3),['1100:1300/0','2050:0/11300','6300:10000/0']);

// ---------- issued vendor invoice: details only ----------
r=await o.call('bills',{method:'PATCH',json:{action:'update_details',billId:VI1,dueDate:'2026-11-30',memo:'Pay by cheque'}});
check('DOC-10',A,'Issued vendor invoice: due date and memo change; amounts untouched',[r.s,sql(`SELECT CONCAT(due_date,'|',memo,'|',total_cents) FROM bills WHERE id='${VI1}'`)],[200,'2026-11-30|Pay by cheque|94399']);
r=await o.call('bills',{method:'PATCH',json:{action:'update_details',billId:VI1,number:'CHANGED'}});
check('DOC-11',A,'Issued vendor invoice: changing the number is refused',[r.s,r.b?.code],[422,'bill_edit_fields_forbidden']);
r=await o.call('bills',{method:'PATCH',json:{action:'update',billId:VI1,vendorId:V,number:'VI-1-'+stamp,billDate:'2026-09-15',currency:'CAD',lines:vi1Lines}});
check('DOC-12',A,'Issued vendor invoice cannot be rewritten in full',[r.s,r.b?.code],[409,'posted_bill_edit_blocked']);

// ---------- validation and isolation ----------
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-X-'+stamp,billDate:'2026-09-17',currency:'CAD',lines:[{description:'AP control',quantity:1,unitPriceCents:1000,accountId:acct('2050'),taxCodeId:''}]}});
check('DOC-13',A,'A line on a control account is refused',[r.s,r.b?.code],[422,'document_line_account']);
const foreignAcct=sql(`SELECT id FROM accounts WHERE company_id<>'${CID}' AND code='6400' LIMIT 1`);
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-Y-'+stamp,billDate:'2026-09-17',currency:'CAD',lines:[{description:'Other company account',quantity:1,unitPriceCents:1000,accountId:foreignAcct,taxCodeId:''}]}});
check('DOC-14',A,'Another company’s GL account is refused',[r.s,r.b?.code],[422,'document_line_account']);
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-Z-'+stamp,billDate:'2026-09-17',currency:'CAD',lines:[{description:'Zero',quantity:1,unitPriceCents:0,accountId:acct('6400'),taxCodeId:''}]}});
check('DOC-15',A,'Lines adding to zero are refused',[r.s,r.b?.code],[422,'bill_amount_invalid']);
r=await o.call('bills',{method:'POST',json:{vendorId:V,number:'VI-S-'+stamp,billDate:'2026-09-18',currency:'CAD',categoryAccountId:acct('6400'),taxEntryMode:'exclusive',taxCodeId:ON,foreignAmountCents:10000,issue:true}});
check('DOC-16',A,'Single-amount vendor invoices (imports, recurring) still work unchanged: $100 + $13',[r.s,jl('bill',r.b?.bill?.id)],[201,['1100:1300/0','2050:0/11300','6400:10000/0']]);

// ---------- customer invoice with two income accounts ----------
// Consulting 1 × $1,000 @ 4000 ON, Training 1 × $500 @ 4100 ON → subtotal 150000, HST 19500, total 169500.
r=await o.call('invoices',{method:'POST',json:{customerId:C,issueDate:'2026-09-10',dueDate:'2026-10-10',currency:'CAD',templateId:tpl,issue:true,lines:[{description:'Consulting',quantity:1,unitPriceCents:100000,taxable:true,taxCodeId:ON,incomeAccountId:acct('4000')},{description:'Training',quantity:1,unitPriceCents:50000,taxable:true,taxCodeId:ON,incomeAccountId:acct('4100')}]}});
const INV=r.b?.invoice?.id;const invLines=sql(`SELECT GROUP_CONCAT(id ORDER BY sort_order) FROM invoice_lines WHERE invoice_id='${INV}'`).split(',');
check('DOC-17',A,'Customer invoice with two income accounts: total $1,695.00',sql(`SELECT total_cents FROM invoices WHERE id='${INV}'`),'169500');

// ---------- NOTE-1: linked credit note, GL chosen on the line ----------
// Training refund 1 × $200 → HST at the original's 13% = 2600. Line account 4100 (not the original 2:1 split).
// Posting: Dr 4100 20000 · Dr 2100 2600 · Cr 1200 22600. Invoice balance 169500 − 22600 = 146900.
const nk=()=> 'r151-'+stamp+'-'+Math.random().toString(36).slice(2,8);
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',sourceId:INV,date:'2026-09-20',subtotalCents:20000,taxCents:2600,memo:'Training refund',operationKey:nk(),lines:[{description:'Training refund',quantityMilli:1000,foreignUnitPriceCents:20000,sourceLineId:invLines[1],accountId:acct('4100')}]}});
const N1=r.b?.note?.id;
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N1,action:'post'}});
check('NOTE-01',A,'Linked credit note posts to the GL account chosen on its line',[r.s,jl('accounting_note',N1)],[200,['1200:0/22600','2100:2600/0','4100:20000/0']]);
check('NOTE-02',A,'Linked credit note reduces the original invoice to $1,469.00',sql(`SELECT balance_cents FROM invoices WHERE id='${INV}'`),'146900');

// ---------- NOTE-2: credit note for an invoice that is not in Tegh ----------
// Ref OLD-778: L1 1 × $300 @ 4000 ON → HST 3900; L2 2 × $25 @ 4100 no tax → 5000.
// Net 35000, tax 3900, total 38900, posted open. Dr 4000 30000 · Dr 4100 5000 · Dr 2100 3900 · Cr 1200 38900.
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',partyId:C,referenceNumber:'OLD-778',date:'2026-09-21',currency:'CAD',memo:'Pre-Tegh invoice',operationKey:nk(),lines:[{description:'Returned item',quantity:1,unitPriceCents:30000,accountId:acct('4000'),taxCodeId:ON},{description:'Restocking credit',quantity:2,unitPriceCents:2500,accountId:acct('4100'),taxCodeId:''}]}});
const N2=r.b?.note?.id;
check('NOTE-03',A,'Credit note with a typed invoice number (not in Tegh) is saved as a draft with its own totals',[r.s,sql(`SELECT CONCAT(IFNULL(source_id,'none'),'|',reference_number,'|',foreign_subtotal_cents,'/',foreign_tax_cents,'/',foreign_total_cents) FROM accounting_notes WHERE id='${N2}'`)],[201,'none|OLD-778|35000/3900/38900']);
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N2,action:'post'}});
check('NOTE-04',A,'It posts as an open credit: each line to its GL account, HST reversed',[r.s,jl('accounting_note',N2)],[200,['1200:0/38900','2100:3900/0','4000:30000/0','4100:5000/0']]);
let list=(await o.call('accounting-notes')).b.notes;let n2=list.find(n=>n.id===N2);
check('NOTE-05',A,'Register shows the reference, open credit $389.00 and both lines with accounts',[n2?.referenceNumber,n2?.foreignRemainingCents??n2?.remainingForeignCents??null,n2?.lines?.length,n2?.lines?.[0]?.accountId===acct('4000')],['OLD-778',38900,2,true]);
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N2,action:'apply',documentId:INV,applicationDate:'2026-09-22',foreignAmountCents:10000,operationKey:nk()}});
check('NOTE-06',A,'The open credit can be applied to an invoice: $100 off → balance $1,369.00',[r.s,sql(`SELECT balance_cents FROM invoices WHERE id='${INV}'`)],[200,'136900']);

// ---------- NOTE-3: debit note for an invoice that is not in Tegh ----------
// Ref OLD-779: 1 × $80 @ 4000 ON → HST 1040, total 9040. Creates a receivable of $90.40.
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_debit',partyId:C,referenceNumber:'OLD-779',date:'2026-09-22',currency:'CAD',operationKey:nk(),lines:[{description:'Late delivery charge',quantity:1,unitPriceCents:8000,accountId:acct('4000'),taxCodeId:ON}]}});
const N3=r.b?.note?.id;r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N3,action:'post'}});
const N3doc=r.b?.note?.debitDocumentId;
check('NOTE-07',A,'Unlinked debit note posts Dr AR / Cr revenue / Cr HST and creates a $90.40 receivable',[r.s,jl('accounting_note',N3),sql(`SELECT CONCAT(status,'/',balance_cents,'/',(SELECT COUNT(*) FROM invoice_lines WHERE invoice_id='${N3doc}')) FROM invoices WHERE id='${N3doc}'`)],[200,['1200:9040/0','2100:0/1040','4000:0/8000'],'sent/9040/1']);

// ---------- NOTE-4: supplier credit note not in Tegh, with non-recoverable PST ----------
// Ref SUP-CR-9: 1 × $100 @ 6400 GPST → GST 500 (recoverable), PST 700 (cost). Total 11200.
// Dr 2050 11200 · Cr 6400 10000+700=10700 · Cr 1100 500.
r=await o.call('accounting-notes',{method:'POST',json:{kind:'vendor_credit',partyId:V,referenceNumber:'SUP-CR-9',date:'2026-09-23',currency:'CAD',operationKey:nk(),lines:[{description:'Damaged paper',quantity:1,unitPriceCents:10000,accountId:acct('6400'),taxCodeId:GPST}]}});
const N4=r.b?.note?.id;r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N4,action:'post'}});
check('NOTE-08',A,'Unlinked supplier credit: cost and non-recoverable PST back out of 6400, GST out of 1100',[r.s,jl('accounting_note',N4)],[200,['1100:0/500','2050:11200/0','6400:0/10700']]);

// ---------- NOTE-5: linked supplier note against the itemized vendor invoice, GL on the line ----------
// Against VI-1 (13% blended? no — tax follows the original proportion 1624/92775). Return paper 1 × $12.25:
// tax = round(1225 × 1624 / 92775) = round(21.44) = 21. Line account 6400.
r=await o.call('accounting-notes',{method:'POST',json:{kind:'vendor_credit',sourceId:VI1,date:'2026-09-24',subtotalCents:1225,taxCents:21,operationKey:nk(),lines:[{description:'Paper returned',quantityMilli:1000,foreignUnitPriceCents:1225,accountId:acct('6400')}]}});
const N5=r.b?.note?.id;r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N5,action:'post'}});
const n5j=jl('accounting_note',N5);
check('NOTE-09',A,'Linked supplier note credits the line’s GL account (6400), not the header account (1500)',[r.s,n5j.some(x=>x.startsWith('6400:0/')),n5j.some(x=>x.startsWith('1500:')),n5j.find(x=>x.startsWith('2050'))],[200,true,false,'2050:1246/0']);

// ---------- NOTE-6: draft edit ----------
// Draft ref X-1: 1 × $10 @ 4000 no tax → edited to 1 × $20 @ 4000 ON → 2000 + 260 = 2260.
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',partyId:C,referenceNumber:'X-1',date:'2026-09-24',currency:'CAD',operationKey:nk(),lines:[{description:'First draft',quantity:1,unitPriceCents:1000,accountId:acct('4000'),taxCodeId:''}]}});
const N6=r.b?.note?.id;const n6num=r.b?.note?.number;
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N6,action:'update',kind:'customer_credit',partyId:C,referenceNumber:'X-1B',date:'2026-09-24',currency:'CAD',lines:[{description:'Edited line',quantity:1,unitPriceCents:2000,accountId:acct('4000'),taxCodeId:ON}]}});
check('NOTE-10',A,'Draft note edited on the main screen: same number, new totals and reference',[r.s,r.b?.note?.number===n6num,sql(`SELECT CONCAT(reference_number,'|',foreign_total_cents,'|',(SELECT COUNT(*) FROM accounting_note_lines WHERE note_id='${N6}')) FROM accounting_notes WHERE id='${N6}'`)],[200,true,'X-1B|2260|1']);
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N2,action:'update',kind:'customer_credit',partyId:C,referenceNumber:'Z',date:'2026-09-24',currency:'CAD',lines:[{description:'x',quantity:1,unitPriceCents:100,accountId:acct('4000')}]}});
check('NOTE-11',A,'A posted note cannot be edited',[r.s,r.b?.code],[409,'note_edit_unavailable']);

// ---------- NOTE validation ----------
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',partyId:C,referenceNumber:'',date:'2026-09-24',currency:'CAD',operationKey:nk(),lines:[{description:'x',quantity:1,unitPriceCents:100,accountId:acct('4000')}]}});
check('NOTE-12',A,'Without an original, the invoice number is required',[r.s,r.b?.code],[422,'note_reference_required']);
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',partyId:C,referenceNumber:'R-2',date:'2026-09-24',currency:'CAD',operationKey:nk(),lines:[{description:'x',quantity:1,unitPriceCents:100,accountId:acct('1200')}]}});
check('NOTE-13',A,'A note line on AR control is refused',[r.s,r.b?.code],[422,'document_line_account']);
r=await o.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',partyId:C,referenceNumber:'R-3',date:'2026-09-24',currency:'CAD',operationKey:nk(),lines:[{description:'x',quantity:1,unitPriceCents:100}]}});
check('NOTE-14',A,'Without an original, each line needs a GL account',[r.s,r.b?.code],[422,'document_line_account']);

// ---------- voids ----------
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N2,action:'void',voidDate:'2026-09-25'}});
check('NOTE-15',A,'A note with an application must have it reversed before voiding',[r.s,r.b?.code],[409,'note_has_settlements']);
r=await o.call('accounting-notes',{method:'PATCH',json:{noteId:N4,action:'void',voidDate:'2026-09-25'}});
check('NOTE-16',A,'Unlinked supplier credit voids with a reversing entry',[r.s,sql(`SELECT status FROM accounting_notes WHERE id='${N4}'`),jl('accounting_note_void',N4)],[200,'void',['1100:500/0','2050:0/11200','6400:10700/0']]);

// ---------- subledgers and registers ----------
// AR control 1200 must equal the customer subledger: invoice 136900 + debit note 9040 − open credit (38900−10000)=28900 → 117040.
const gl1200=Number(sql(`SELECT COALESCE(SUM(l.debit_cents-l.credit_cents),0) FROM journal_lines l JOIN journal_entries e ON e.id=l.journal_entry_id JOIN accounts a ON a.id=l.account_id WHERE e.company_id='${CID}' AND a.code='1200' AND e.status='posted'`));
r=await o.call('professional-output/v5980/ar-trial-balance?start=2026-01-01&end=2026-10-01');
const art=JSON.stringify(r.b);
check('DOC-18',A,'GL 1200 = $1,170.40 (136,900 + 9,040 − 28,900) and the receivable trial balance builds without error',[gl1200,r.s],[117040,200]);
r=await o.call('professional-output/v5980/customer-ledger?start=2026-01-01&end=2026-10-01&partyId='+C);
check('DOC-19',A,'Customer ledger lists the typed-reference note',[r.s,JSON.stringify(r.b).includes('OLD-778')],[200,true]);
r=await o.call('professional-output/v5980/invoice-register?start=2026-01-01&end=2026-10-01');
check('DOC-20',A,'Invoice & note register shows the reference as the original number',[r.s,JSON.stringify(r.b).includes('OLD-778')],[200,true]);
r=await o.call('professional-output/v5980/ap-trial-balance?start=2026-01-01&end=2026-10-01');
check('DOC-21',A,'Payable trial balance builds with itemized invoices and notes',r.s,200);

// ---------- cash basis: an itemized vendor invoice recognized on payment ----------
const CC=await mkCo('R151 Cash Basis Inc '+stamp,'cash');
const s2=new S();Object.assign(s2,o);s2.cid=CC;
const V2=await vend('R151 Cash Vendor',CC,s2);
const ON2=tcode('ON',CC),GPST2=(need('gpst2',await s2.call('tax-codes',{method:'POST',json:{code:'GPST',name:'GST + PST test',components:[{name:'GST',ratePercent:5,salesAccountId:acct('2100',CC),purchaseAccountId:acct('1100',CC),purchaseRecoverable:true},{name:'PST',ratePercent:7,salesAccountId:acct('2110',CC),purchaseRecoverable:false}]}})),tcode('GPST',CC));
r=await s2.call('bills',{method:'POST',json:{vendorId:V2,number:'CB-1-'+stamp,billDate:'2026-09-15',currency:'CAD',lines:vi1Lines.map(l=>({...l,accountId:acct(sql(`SELECT code FROM accounts WHERE id='${l.accountId}'`),CC),taxCodeId:l.taxCodeId===ON?ON2:l.taxCodeId===GPST?GPST2:''})),issue:true}});
const CB1=r.b?.bill?.id;
check('DOC-22',A,'Cash basis: the itemized vendor invoice posts nothing until paid',[r.s,jl('bill',CB1,CC).length],[201,0]);
const payAcct=need('pa',await s2.call('payments?type=vendor')).paymentAccounts.find(a=>!a.undeposited&&a.currency==='CAD').id;
// Pay $500.00 of $943.99. Spread over debits 9100 / 3932 / 80000 / 1367 (sum 94399), largest remainder:
// 50000×9100/94399=4819.96 · ×3932=2082.65 · ×80000=42373.33 · ×1367=724.06 → floors 4819+2082+42373+724=49998,
// the two extra cents go to .96 and .65 → 6300 4820 · 6400 2083 · 1500 42373 · 1100 724.
r=await s2.call('payments',{method:'POST',json:{type:'vendor',documentId:CB1,paymentDate:'2026-09-25',reference:'CHQ 151',foreignAmountCents:50000,paymentAccountId:payAcct,operationKey:'r151-pay-'+stamp}});
const pj=sql(`SELECT CONCAT(a.code,':',SUM(l.debit_cents),'/',SUM(l.credit_cents)) FROM party_payments p JOIN journal_lines l ON l.journal_entry_id=p.journal_entry_id JOIN accounts a ON a.id=l.account_id WHERE p.company_id='${CC}' AND p.document_id='${CB1}' GROUP BY a.code ORDER BY a.code`).split('\n').filter(x=>!x.startsWith('10'));
check('DOC-23',A,'Cash basis: a $500 payment is spread over the lines’ accounts and recoverable tax to the cent',[r.s,pj],[201,['1100:724/0','1500:42373/0','6300:4820/0','6400:2083/0']]);

// ---------- backup ----------
r=await o.call('backup/export',{method:'POST',json:{}});
check('DOC-24',A,'Backup export succeeds with the new line tables present',r.s,200);
save('r151.json');
