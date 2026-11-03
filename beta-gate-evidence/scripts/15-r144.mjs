// R144: Tegh Intelligence. A dedicated synthetic company is seeded with known activity; every expectation below
// is worked out by hand from that activity (today = 2026-10-01), not by calling Tegh's insight code.
import {S,sql,rec,check,save,need} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const A='R144';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
let r={b:need('co',await o.call('companies',{method:'POST',company:false,json:{name:'R144 Insights Inc',province:'ON',country:'Canada',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'accounting',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'123456789RT0001',coaMode:'default'}}))};
const CID=r.b.company.id;o.cid=CID;
const acct=code=>sql(`SELECT id FROM accounts WHERE company_id='${CID}' AND code='${code}'`);
const code=c=>sql(`SELECT id FROM tax_codes WHERE company_id='${CID}' AND code='${c}'`);
const tpl=(await o.call('invoice-templates')).b?.templates?.[0]?.id||sql(`SELECT id FROM invoice_templates WHERE company_id='${CID}' LIMIT 1`);
const cust=async(name,prov)=>need('cust',await o.call('customers',{method:'POST',json:{name,province:prov,country:'Canada',paymentTerms:30}})).customer?.id||sql(`SELECT id FROM customers WHERE company_id='${CID}' AND name='${name}'`);
const vend=async(name)=>need('vend',await o.call('vendors',{method:'POST',json:{name,province:'ON',country:'Canada',paymentTerms:30}})).vendor?.id||sql(`SELECT id FROM vendors WHERE company_id='${CID}' AND name='${name}'`);
const inv=async(customerId,issueDate,dueDate,qty,price,extra={})=>need('inv',await o.call('invoices',{method:'POST',json:{customerId,issueDate,dueDate,currency:'CAD',templateId:tpl,issue:true,lines:[{description:'Service',quantity:qty,unitPriceCents:price,taxable:true,...extra}]}}));
const bill=async(vendorId,number,billDate,dueDate,amount,cat)=>need('bill',await o.call('bills',{method:'POST',json:{vendorId,number,billDate,dueDate,categoryAccountId:acct(cat),currency:'CAD',taxEntryMode:'exclusive',taxCodeId:code('ON'),foreignAmountCents:amount,issue:true}}));
// Customers and invoices
const LAKE=await cust('Lakeview Dental','ON'),COAST=await cust('Coastal Rentals','BC');
await inv(LAKE,'2026-07-01','2026-07-31',2,100000);              // $2,000 + HST = $2,260, 62 days overdue
await inv(COAST,'2026-09-20','2026-10-20',1,50000);              // $500 + GST (BC code, GST only) = $525
await inv(COAST,'2026-09-20','2026-10-20',1,50000);              // duplicate of the one above
await inv(COAST,'2026-09-25','2026-09-28',1,30000,{taxCodeId:code('ON')}); // ON code for a BC customer: $339, 3 days overdue
// Vendors and bills
const ROG=await vend('Rogers Wireless'),NOS=await vend('Northern Office Supply');
for(const [n,d,due] of [['RW-05','2026-05-10','2026-06-09'],['RW-06','2026-06-10','2026-07-10'],['RW-07','2026-07-10','2026-08-09'],['RW-08','2026-08-10','2026-09-09']])await bill(ROG,n,d,due,10000,'6300');
await bill(ROG,'RW-09','2026-09-10','2026-10-10',50000,'6300'); // 5× the usual $113 → unusual
await bill(NOS,'NO-1','2026-09-01','2026-10-01',20000,'6400');
await bill(NOS,'NO-2','2026-09-08','2026-10-08',20000,'6400'); // same vendor, same $226, 7 days apart → duplicate
// $75 left in Unassigned Expense
need('je',await o.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'Unknown receipt',lines:[{accountId:acct('6999'),debitCents:7500,creditCents:0},{accountId:acct('3000'),debitCents:0,creditCents:7500}]}}));
// Bank history: owner investment, two phone bills posted to 6300 with the ON code, then a third phone bill and an old line left pending
const BANK=sql(`SELECT b.id FROM bank_accounts b WHERE b.company_id='${CID}' AND b.account_type='bank' ORDER BY b.created_at LIMIT 1`);
const csv='Date,Description,Reference,Amount,Balance,Currency\n2026-08-05,"ROGERS WIRELESS PAD",R1,-56.50,-56.50,CAD\n2026-08-20,"MISC SERVICE CHARGE",M1,-10.00,-66.50,CAD\n2026-09-02,"OWNER INVESTMENT",OI1,5000.00,4933.50,CAD\n2026-09-05,"ROGERS WIRELESS PAD",R2,-56.50,4877.00,CAD\n2026-09-15,"ROGERS WIRELESS PAD",R3,-56.50,4820.50,CAD\n';
{const data=Buffer.from(csv);const st=need('upload-start',await o.call('imports/upload-start',{method:'POST',json:{filename:'r144.csv',size:data.length}}));
 need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:st.uploadId,offset:0,data:data.toString('base64')}}));
 const pv=need('finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:st.uploadId,bankAccountId:BANK,exchangeRateMicros:1000000,mode:'preview'}}));
 const sel=(pv.preview.rows||[]).filter(x=>x.selectionKey&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
 need('approve',await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.preview.id,operationKey:'r144_'+Date.now(),selectedKeys:sel,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}}));}
const bt=ref=>sql(`SELECT id FROM bank_transactions WHERE company_id='${CID}' AND reference='${ref}'`);
for(const [ref,ac,tc] of [['R1','6300','ON'],['R2','6300','ON'],['OI1','3000','']])need('post',await o.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:bt(ref),accountId:acct(ac),taxCode:tc?'CODE':'NO_TAX',taxCodeId:tc?code(tc):'',applyGstHst:false,applyPst:false}]}}));
const R3=bt('R3');

// ---------- engine ----------
r=await o.call('insights');const d=r.b||{};
check('IN-00',A,'Insights endpoint answers (owner)',r.s,200);
const an=d.anomalies||[];const kinds=an.map(a=>a.kind);const has=k=>kinds.includes(k);
rec('IN-01',A,'Duplicate vendor invoice found: Northern Office Supply NO-1 / NO-2, $226.00, 7 days apart',an.some(a=>a.kind==='duplicate_bill'&&/Northern Office Supply/.test(a.title)&&/\$226\.00/.test(a.why))?'PASS':'FAIL',JSON.stringify(an.filter(a=>a.kind==='duplicate_bill').map(a=>a.why)).slice(0,200));
rec('IN-02',A,'Duplicate customer invoice found: Coastal Rentals, two × $525.00 on 2026-09-20',an.some(a=>a.kind==='duplicate_invoice'&&/Coastal Rentals/.test(a.title)&&/\$525\.00/.test(a.why))?'PASS':'FAIL',JSON.stringify(an.filter(a=>a.kind==='duplicate_invoice').map(a=>a.why)).slice(0,200));
rec('IN-03',A,'Unusual vendor invoice: Rogers RW-09 $565.00 = 5.0× the usual $113.00 (last 4)',an.some(a=>a.kind==='unusual_amount'&&/RW-09/.test(a.why)&&/\$565\.00/.test(a.why)&&/5(\.0)?×/.test(a.why)&&/\$113\.00/.test(a.why))?'PASS':'FAIL',JSON.stringify(an.filter(a=>a.kind==='unusual_amount').map(a=>a.why)).slice(0,200));
rec('IN-04',A,'Only RW-09 is flagged as unusual (RW-08 at the usual amount is not)',an.filter(a=>a.kind==='unusual_amount').length===1?'PASS':'FAIL',String(an.filter(a=>a.kind==='unusual_amount').length));
rec('IN-05',A,'Tax code mismatch: ON code on an invoice to a BC customer',an.some(a=>a.kind==='tax_code_region'&&/ON/.test(a.title)&&/Coastal Rentals/.test(a.why))?'PASS':'FAIL',JSON.stringify(an.filter(a=>a.kind==='tax_code_region').map(a=>a.title)));
rec('IN-06',A,'Unassigned Expense balance $75.00 flagged',an.some(a=>a.kind==='parked_balance'&&/Unassigned Expense/.test(a.title)&&/\$75\.00/.test(a.title))?'PASS':'FAIL','');
rec('IN-07',A,'Bank line waiting more than 30 days flagged (1 line, oldest 2026-08-20)',an.some(a=>a.kind==='stale_bank_lines'&&/^1 bank line/.test(a.title)&&/2026-08-20/.test(a.why))?'PASS':'FAIL',JSON.stringify(an.filter(a=>a.kind==='stale_bank_lines').map(a=>a.title+' '+a.why)).slice(0,200));
rec('IN-08',A,'No false alarms: no negative bank and no payroll findings in this company',!has('negative_bank')&&!has('payroll_unposted')?'PASS':'FAIL',kinds.join(','));
const lines=(d.brief?.lines||[]).map(l=>l.text);const L=k=>(d.brief?.lines||[]).find(l=>l.key===k)?.text||'';
rec('IN-10',A,'Brief: cash $4,887.00, up $4,943.50 from 30 days ago (−$56.50 on 2026-09-01)',/Cash in the bank is \$4,887\.00, up \$4,943\.50 from 30 days ago/.test(L('cash'))?'PASS':'FAIL',L('cash'));
rec('IN-11',A,'Brief: 2 customer invoices overdue ($2,599.00), oldest 62 days late',/^2 customer invoices are overdue \(\$2,599\.00\); the oldest is 62 days late/.test(L('overdue'))?'PASS':'FAIL',L('overdue'));
rec('IN-12',A,'Brief: 4 vendor invoices past due ($452.00); 2 due in the next 7 days ($452.00)',/^4 vendor invoices are past due \(\$452\.00\)/.test(L('bills_late'))&&/^2 vendor invoices are due in the next 7 days \(\$452\.00\)/.test(L('bills_due'))?'PASS':'FAIL',L('bills_late')+' | '+L('bills_due'));
rec('IN-13',A,'Brief: GST/HST owing $167.00 (collected 260+25+25+39 − credits 52+65+52+13)',/You owe about \$167\.00 in GST\/HST/.test(L('tax'))?'PASS':'FAIL',L('tax'));
rec('IN-14',A,'Brief: 2 bank lines waiting in Match and Post',/^2 bank lines are waiting/.test(L('bank'))?'PASS':'FAIL',L('bank'));
rec('IN-15',A,'Brief: profit so far this year $1,825.00 (income 3,300 − expenses 1,475)',/Profit so far this year is \$1,825\.00/.test(L('profit'))?'PASS':'FAIL',L('profit'));
rec('IN-16',A,'Brief: anomaly count matches the Worth-a-look list',new RegExp('Tegh noticed '+an.filter(a=>!a.dismissed).length+' thing').test(L('anomalies'))?'PASS':'FAIL',L('anomalies'));
const ch=d.chase||[];
rec('IN-20',A,'Who to chase first: Lakeview Dental ($2,260.00, 62 days) ahead of Coastal Rentals ($339.00, 3 days)',ch[0]?.name==='Lakeview Dental'&&ch[0]?.overdueCents===226000&&ch[0]?.oldestDaysLate===62&&ch[1]?.name==='Coastal Rentals'&&ch[1]?.overdueCents===33900&&ch[1]?.oldestDaysLate===3?'PASS':'FAIL',JSON.stringify(ch.map(c=>[c.name,c.overdueCents,c.oldestDaysLate])));
rec('IN-21',A,'Cash runway: balance $4,887.00 and six months of monthly change',d.cash?.balanceCents===488700&&(d.cash?.months||[]).length===6?'PASS':'FAIL',JSON.stringify(d.cash).slice(0,200));
// Bank suggestion
r=await o.call('insights/bank-suggestions',{method:'POST',json:{ids:[R3]}});const sg=r.b?.suggestions?.[R3];
rec('IN-30',A,'Bank suggestion for the pending Rogers line: 6300 Telephone and Internet, ON code, 67% (2 of 2 similar lines × 2/3)',sg&&sg.accountId===acct('6300')&&sg.taxCode==='CODE:'+code('ON')&&sg.confidence===67&&/with the ON tax code 2 times/.test(sg.reason)?'PASS':'FAIL',JSON.stringify(sg));
r=await o.call('insights/bank-suggestions',{method:'POST',json:{ids:[bt('M1')]}});
rec('IN-31',A,'No suggestion for a line with no history (no guessing)',Object.keys(r.b?.suggestions||{}).length===0?'PASS':'FAIL',JSON.stringify(r.b));
// Ask your books
const ask=async q=>(await o.call('insights/ask',{method:'POST',json:{question:q}})).b||{};
let a=await ask('how much did I spend on telephone this year');
rec('IN-40',A,'"how much did I spend on telephone this year" → $1,000.00 (bills 4×100+500, bank 2×50)',a.answered&&/You spent \$1,000\.00 on 6300 Telephone and Internet in this year/.test(a.text)?'PASS':'FAIL',a.text||'');
a=await ask('what were my sales this year');
rec('IN-41',A,'"what were my sales this year" → $3,300.00',a.answered&&/Sales \(income\) were \$3,300\.00 in this year/.test(a.text)?'PASS':'FAIL',a.text||'');
a=await ask('what are my biggest expenses this year');
rec('IN-42',A,'"biggest expenses this year" → 6300 Telephone and Internet $1,000.00 first, then 6400 Office Supplies $400.00',a.answered&&/Telephone and Internet \(\$1,000\.00\)/.test(a.text)&&a.rows?.[1]?.amountCents===40000?'PASS':'FAIL',a.text+' '+JSON.stringify(a.rows));
a=await ask('who are my top customers this year');
rec('IN-43',A,'"top customers this year" → Lakeview Dental $2,000.00 before tax, then Coastal Rentals $1,300.00',a.answered&&/Lakeview Dental \(\$2,000\.00 before tax, 1 invoice\)/.test(a.text)&&a.rows?.[1]?.amountCents===130000?'PASS':'FAIL',a.text+' '+JSON.stringify(a.rows));
a=await ask('how much did we pay rogers in september');
rec('IN-44',A,'"how much did we pay rogers in september" → vendor answer: invoices $565.00, payments $0.00 in September 2026',a.answered&&/Rogers Wireless: vendor invoices of \$565\.00 and payments of \$0\.00 in September 2026/.test(a.text)?'PASS':'FAIL',a.text||'');
a=await ask('how much did I spend on telephone in Q3');
const q3=a;a=await ask('how much did I spend on advertising and marketing this year');
rec('IN-47',A,'"advertising and marketing this year" → only 6100 Advertising and Promotion, $0.00, this year (not March; "and" ignored)',a.answered&&/^You spent \$0\.00 on 6100 Advertising and Promotion in this year\.$/.test(a.text)?'PASS':'FAIL',a.text||'');a=q3;
rec('IN-45',A,'"telephone in Q3" → $800.00 (RW-07 100 + RW-08 100 + RW-09 500 + bank Aug 50 + Sep 50)',a.answered&&/\$800\.00/.test(a.text)&&/Q3 2026/.test(a.text)?'PASS':'FAIL',a.text||'');
a=await ask('please create a customer');
rec('IN-46',A,'Not a books question → not answered (falls through to normal Assist)',a.answered===false?'PASS':'FAIL',JSON.stringify(a));
// Dismissals
const dupKey=an.find(x=>x.kind==='duplicate_invoice')?.key;
r=await o.call('insights/dismiss',{method:'POST',json:{key:dupKey}});
const after=(await o.call('insights')).b.anomalies;
rec('IN-50',A,'"Not a problem" keeps the finding listed as dismissed and drops it from the brief count',r.s===200&&after.find(x=>x.key===dupKey)?.dismissed===true?'PASS':'FAIL',r.s+'');
r=await o.call('insights/dismiss',{method:'POST',json:{key:dupKey,undo:true}});
rec('IN-51',A,'Undo restores it',(await o.call('insights')).b.anomalies.find(x=>x.key===dupKey)?.dismissed===false?'PASS':'FAIL','');
check('IN-52',A,'Dismissal recorded in the audit trail',sql(`SELECT COUNT(*) FROM audit_log WHERE company_id='${CID}' AND action IN ('insight.dismissed','insight.restored')`),'2');
// Read-only + permissions + isolation
const before=sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}'`);await o.call('insights');await o.call('insights/ask',{method:'POST',json:{question:'sales this year'}});await o.call('insights/bank-suggestions',{method:'POST',json:{ids:[R3]}});
check('IN-61',A,'Insights, questions and suggestions create no journal entries and change no bank line',[sql(`SELECT COUNT(*) FROM journal_entries WHERE company_id='${CID}'`),sql(`SELECT status FROM bank_transactions WHERE id='${R3}'`)],[before,'pending']);
const other=new S();await other.login('owner@gate.test','Gate!Owner#2026pw');other.cid=ids.BC;
r=await other.call('insights/bank-suggestions',{method:'POST',json:{ids:[R3]}});
rec('IN-62',A,'Company isolation: another company cannot get suggestions for this company\'s bank line',r.s===200&&Object.keys(r.b?.suggestions||{}).length===0?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,80));
r=await o.call('insights/dismiss',{method:'POST',json:{key:'x'},headers:{'X-CSRF-Token':'bad'}});
rec('IN-63',A,'Dismiss refuses a bad CSRF token',r.s===403||r.s===419||r.s===400?'PASS':'FAIL',String(r.s));
// No outside calls: the module opens no network connections
const src=fs.readFileSync('/srv/gate/www/api/insights_r144.php','utf8');
rec('IN-64',A,'insights_r144.php makes no outbound calls (no curl, sockets, HTTP clients or AI provider)',!/curl_|fsockopen|stream_socket|file_get_contents\s*\(\s*['"]https?:|tegh_ai_provider|openai/i.test(src)?'PASS':'FAIL','');
fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify({...JSON.parse(fs.readFileSync('/srv/gate/t/ids.json')),R144:CID}));
save('r144.json');
