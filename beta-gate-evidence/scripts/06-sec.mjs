// Beta gate: security, company isolation, roles, CSRF, throttling, uploads, error leakage, client viewing links.
import {S,sql,rec,check,save,need,mails,ORIGIN} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const st=JSON.parse(fs.readFileSync('/srv/gate/t/state-bc.json'));
const A='SEC';const pw='Gate!Member#2026pw';
const owner=new S();await owner.login('owner@gate.test','Gate!Owner#2026pw');owner.cid=ids.BC;
const other=new S();await other.login('otherowner@gate.test',pw);other.cid=ids.X;
const viewer=new S();await viewer.login('viewer@gate.test',pw);viewer.cid=ids.BC;
const editor=new S();await editor.login('editor@gate.test',pw);editor.cid=ids.BC;
const admin=new S();await admin.login('admin@gate.test',pw);admin.cid=ids.BC;
const deny=(r)=>r.s>=400&&r.s!==500;
const noLeak=(r)=>!/Gate Test BC|Pacific Client|114975|112000/.test(JSON.stringify(r.b));
// ---------- Cross-company tampering (admin of company X only) ----------
let r=await other.call('invoices/r20/detail?invoiceId='+st.I_ON);rec('SI-02',A,'Other company: BC invoice detail by ID refused',deny(r)&&noLeak(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await other.call('professional-output/v5980/customer-invoice?documentId='+st.I_ON);rec('SI-03',A,'Other company: BC invoice PDF model refused',deny(r)&&noLeak(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
const xBank=sql(`SELECT a.id FROM bank_accounts b JOIN accounts a ON a.id=b.ledger_account_id WHERE b.company_id='${ids.X}' AND b.account_type='bank' LIMIT 1`);
r=await other.call('payments',{method:'POST',json:{type:'customer',documentId:st.I_ON,paymentDate:'2026-09-25',reference:'x',foreignAmountCents:100,paymentAccountId:xBank,operationKey:'gate-xpay-'+Date.now()}});
rec('SI-04',A,'Other company: payment against BC invoice refused',deny(r)&&+sql(`SELECT COUNT(*) FROM party_payments WHERE document_id='${st.I_ON}'`)===0?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
const bcQC=sql(`SELECT id FROM tax_codes WHERE company_id='${ids.BC}' AND code='QC'`);
r=await other.call('tax-codes',{method:'PUT',json:{id:bcQC,code:'QC',name:'hijack',region:'QC',components:[{name:'GST',ratePercent:'50'}]}});
rec('SI-05',A,'Other company: editing BC tax code by ID refused',deny(r)&&sql(`SELECT name FROM tax_codes WHERE id='${bcQC}'`)!=='hijack'?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await other.call('tax-codes',{method:'DELETE',json:{id:bcQC}});rec('SI-06',A,'Other company: deleting BC tax code refused',sql(`SELECT status FROM tax_codes WHERE id='${bcQC}'`)==='active'?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
const pendingBC=sql(`SELECT id FROM bank_transactions WHERE company_id='${ids.BC}' AND status='pending' LIMIT 1`)||st.T4;
const xInc=sql(`SELECT id FROM accounts WHERE company_id='${ids.X}' AND code='4000'`);
r=await other.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:pendingBC,accountId:xInc,taxCode:'NO_TAX'}]}});
rec('SI-07',A,'Other company: posting a BC bank transaction refused',deny(r)||(r.b?.results||[]).every(x=>!x.posted)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,160));
r=await other.call('accounting-notes',{method:'POST',json:{kind:'customer_credit',sourceId:st.I_ON,date:'2026-09-20',subtotalCents:1000,taxCents:0,memo:'x',operationKey:'gate-xnote-000001',lines:[{description:'x',quantityMilli:1000,foreignUnitPriceCents:1000}]}});
rec('SI-08',A,'Other company: credit note against BC invoice refused',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await other.call('backup/export',{method:'POST',json:{},headers:{'X-Company-Id':ids.BC},company:false});
rec('SI-09',A,'Other company: backup export of BC refused',deny(r)&&noLeak(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await other.call(`portal/gl-account-ledger?accountId=${sql(`SELECT id FROM accounts WHERE company_id='${ids.BC}' AND code='1200'`)}&start=2026-01-01&end=2026-09-30`);
rec('SI-10',A,'Other company: BC GL account ledger by account ID refused / empty',deny(r)||noLeak(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,120));
r=await other.call('invoices',{method:'POST',json:{customerId:st.cBC,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'CAD',issue:true,lines:[{description:'x',quantity:1,unitPriceCents:100}]}});
rec('SI-11',A,'Other company: invoicing a BC customer ID refused',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await other.call('client-view/links',{headers:{'X-Company-Id':ids.BC},company:false});rec('SI-12',A,'Other company: listing BC client viewing links refused',deny(r)?'PASS':'FAIL',String(r.s));
// ---------- Roles ----------
const tmplId=(await owner.call('invoice-templates')).b?.templates?.[0]?.id;
const invBody={customerId:st.cBC,issueDate:'2026-09-25',dueDate:'2026-10-25',currency:'CAD',templateId:tmplId,issue:false,lines:[{description:'role test',quantity:1,unitPriceCents:1000,taxable:true}]};
r=await viewer.call('invoices',{method:'POST',json:invBody});rec('RO-01',A,'Viewer cannot create invoices',deny(r)?'PASS':'FAIL',String(r.s));
r=await viewer.call('journals',{method:'POST',json:{date:'2026-09-20',memo:'v',lines:[{accountId:sql(`SELECT id FROM accounts WHERE company_id='${ids.BC}' AND code='6000'`),debitCents:100,creditCents:0},{accountId:sql(`SELECT id FROM accounts WHERE company_id='${ids.BC}' AND code='3000'`),debitCents:0,creditCents:100}]}});
rec('RO-02',A,'Viewer cannot post journals',deny(r)?'PASS':'FAIL',String(r.s));
r=await viewer.call('tax-codes',{method:'POST',json:{code:'VV',name:'v',components:[{name:'V',ratePercent:'1'}]}});rec('RO-03',A,'Viewer cannot create tax codes',deny(r)?'PASS':'FAIL',String(r.s));
r=await viewer.call('period-close',{method:'POST',json:{closedThroughDate:''}});rec('RO-04',A,'Viewer cannot reopen a locked period',deny(r)&&sql(`SELECT COUNT(*) FROM period_locks WHERE company_id='${ids.BC}' AND locked=1`)!=='0'?'PASS':'FAIL',String(r.s));
r=await viewer.call('bank-transactions/post',{method:'POST',json:{decisions:[{id:pendingBC,accountId:sql(`SELECT id FROM accounts WHERE company_id='${ids.BC}' AND code='6000'`),taxCode:'NO_TAX'}]}});rec('RO-05',A,'Viewer cannot post bank transactions',deny(r)?'PASS':'FAIL',String(r.s));
r=await viewer.call('portal/trial-balance?start=2026-01-01&end=2026-09-30');rec('RO-06',A,'Viewer can read the Trial Balance',r.s===200?'PASS':'FAIL',String(r.s));
r=await viewer.call('platform/invitations',{method:'POST',json:{action:'create_account',email:'x@gate.test',scope:'company',assignments:[{companyId:ids.BC,role:'owner'}],operationKey:'gate-viewer-inv-'+Date.now()}});rec('RO-07',A,'Viewer cannot invite users',deny(r)?'PASS':'FAIL',String(r.s));
r=await viewer.call('backup/export',{method:'POST',json:{}});rec('RO-08',A,'Viewer cannot export a backup',deny(r)?'PASS':'FAIL',String(r.s));
r=await editor.call('invoices',{method:'POST',json:invBody});rec('RO-09',A,'Editor can create a draft invoice',r.s<300?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
r=await editor.call('period-close',{method:'POST',json:{closedThroughDate:''}});rec('RO-10',A,'Editor cannot reopen a locked period',deny(r)&&sql(`SELECT COUNT(*) FROM period_locks WHERE company_id='${ids.BC}' AND locked=1`)!=='0'?'PASS':'FAIL',String(r.s));
r=await editor.call('platform/invitations',{method:'POST',json:{action:'create_account',email:'y@gate.test',scope:'company',assignments:[{companyId:ids.BC,role:'owner'}],operationKey:'gate-editor-inv-'+Date.now()}});rec('RO-11',A,'Editor cannot invite an owner',deny(r)?'PASS':'FAIL',String(r.s));
r=await admin.call('platform/invitations',{method:'POST',json:{action:'create_account',email:'z@gate.test',scope:'company',assignments:[{companyId:ids.BC,role:'owner'}],operationKey:'gate-admin-inv-'+Date.now()}});rec('RO-12',A,'Company admin cannot grant the Owner role',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,100));
// ---------- CSRF / origin ----------
r=await owner.call('customers',{method:'POST',json:{name:'CSRF test',province:'BC'},headers:{'X-CSRF-Token':''}});rec('CS-01',A,'POST without CSRF token refused',deny(r)&&sql(`SELECT COUNT(*) FROM customers WHERE name='CSRF test'`)==='0'?'PASS':'FAIL',String(r.s));
r=await owner.call('customers',{method:'POST',json:{name:'CSRF test2',province:'BC'},headers:{'X-CSRF-Token':'bogus-token-value'}});rec('CS-02',A,'POST with wrong CSRF token refused',deny(r)?'PASS':'FAIL',String(r.s));
r=await owner.call('customers',{method:'POST',json:{name:'CSRF test3',province:'BC'},origin:'https://evil.example'});rec('CS-03',A,'POST from foreign Origin refused',deny(r)&&sql(`SELECT COUNT(*) FROM customers WHERE name='CSRF test3'`)==='0'?'PASS':'FAIL',String(r.s));
r=await owner.call('customers',{method:'POST',body:'name=x',headers:{'Content-Type':'application/x-www-form-urlencoded'}});rec('CS-04',A,'Form-encoded (simple-request) POST refused',deny(r)?'PASS':'FAIL',String(r.s));
// ---------- XSS payloads stored for UI check ----------
r=await owner.call('customers',{method:'POST',json:{name:'<img src=x onerror="window.__xss=1">Evil',email:'',phone:'',billingAddress:'<script>window.__xss=2</script>',province:'BC'}});
rec('XS-01',A,'XSS payload customer stored as text (rendering checked in UI run)',r.s<300?'INFO':'FAIL',String(r.s));
// ---------- Uploads ----------
const up=async(name,content,type)=>{const fd=new FormData();fd.append('file',new Blob([content],{type}),name);return owner.call('attachments',{method:'POST',body:fd})};
r=await up('shell.php','<?php echo 1; ?>','application/x-php');rec('UP-01',A,'Receipt upload of .php refused',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,80));
r=await up('evil.svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml');rec('UP-02',A,'Receipt upload of .svg refused',deny(r)?'PASS':'FAIL',String(r.s));
r=await up('fake.png','<?php echo 1; ?>','image/png');rec('UP-03',A,'PHP disguised as .png refused by content check',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,80));
r=await up('receipt.pdf',Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'),'application/pdf');rec('UP-04',A,'Valid PDF receipt accepted and stored outside the web root',r.s===201&&!fs.existsSync('/srv/gate/www/'+r.b.key)&&fs.readdirSync('/srv/gate/sr-accountax-private/storage',{recursive:true}).some(f=>String(f).includes('receipts'))?'PASS':'FAIL',r.s+' key='+(r.b?.key||'').replace(/[a-f0-9]{16,}/g,'…'));
r=await owner.call('imports/upload-start',{method:'POST',json:{filename:'x.php',size:10}});rec('UP-05',A,'Statement upload-start with .php refused',deny(r)?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,80));
r=await owner.call('imports/upload-start',{method:'POST',json:{filename:'big.csv',size:11*1024*1024}});rec('UP-06',A,'Statement over 10 MB refused',deny(r)?'PASS':'FAIL',String(r.s));
const big=Buffer.alloc(11*1024*1024,65);r=await up('big.pdf',big,'application/pdf');rec('UP-07',A,'Receipt over 10 MB refused',deny(r)?'PASS':'FAIL',String(r.s));
// ---------- Error leakage & headers ----------
const leak=t=>/\/srv\/|Stack trace|PDOException|SQLSTATE|\.php on line|Fatal error|Warning:/i.test(t);
const raw=async(route,init)=>{const res=await fetch(ORIGIN+'/api/'+route,init);return {s:res.status,t:await res.text(),h:res.headers}};
const ck={Cookie:owner.ck(),'X-Company-Id':ids.BC,'X-CSRF-Token':owner.csrf,Origin:ORIGIN};
let e1=await raw('customers',{method:'POST',headers:{...ck,'Content-Type':'application/json'},body:'{"name":'});
let e2=await raw("invoices/r20/detail?invoiceId='%20OR%201=1--",{headers:ck});
let e3=await raw('customers',{method:'POST',headers:{...ck,'Content-Type':'application/json'},body:JSON.stringify({name:'x'.repeat(100000),province:'BC'})});
let e4=await raw('reports/v5600/financial?kind=../../etc/passwd',{headers:ck});
let e5=await raw('journals',{method:'POST',headers:{...ck,'Content-Type':'application/json'},body:JSON.stringify({date:'2026-99-99',lines:[{accountId:['a'],debitCents:{x:1}}]})});
const errs=[e1,e2,e3,e4,e5];rec('EL-01',A,'Malformed JSON, SQL-like IDs, oversize and wrong-type input: clean 4xx, no paths/stack traces/SQL',errs.every(x=>x.s>=400&&x.s<500&&!leak(x.t))?'PASS':'FAIL',errs.map(x=>x.s+':'+x.t.slice(0,60)).join(' | '));
const apiH=await raw('auth/me',{headers:ck});rec('EL-02',A,'Authenticated API responses are not cacheable (Cache-Control no-store/private)',/no-store|private/.test(apiH.h.get('cache-control')||'')?'PASS':'FAIL','cache-control='+apiH.h.get('cache-control'));
rec('EL-03',A,'API does not expose X-Powered-By / PHP version',!apiH.h.get('x-powered-by')?'PASS':'FAIL','x-powered-by='+apiH.h.get('x-powered-by')+' server='+apiH.h.get('server'));
const phpErr=fs.existsSync('/srv/gate/apache-error.log')?fs.readFileSync('/srv/gate/apache-error.log','utf8'):'';
rec('EL-04',A,'Server error log has no PHP fatal errors during the gate run',!/PHP Fatal|PHP Parse/.test(phpErr)?'PASS':'FAIL',(phpErr.match(/PHP (Fatal|Parse)[^\n]*/g)||[]).slice(0,3).join(' | ').slice(0,300));
// ---------- Throttling ----------
const t=new S();let codes=[];for(let i=0;i<24;i++){const x=await t.call('auth/login',{method:'POST',json:{email:'viewer@gate.test',password:'wrong-password-'+i},company:false});codes.push(x.s)}
rec('TH-01',A,'Repeated wrong passwords are throttled (429 or CAPTCHA required) before 24 attempts',codes.some(c=>c===429||c===503||c===403)?'PASS':'FAIL',codes.join(','));
const good=await t.call('auth/login',{method:'POST',json:{email:'viewer@gate.test',password:pw},company:false});
rec('TH-02',A,'Correct password while throttled is still refused (no bypass)',good.s!==200?'PASS':'FAIL',String(good.s));
save('sec.json');
