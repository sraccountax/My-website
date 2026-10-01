// R141: DEF-08 starter codes, country + province/state, foreign tax codes, OBS-2 links,
// tax code report usage, dashboard figure mapping, payroll Canada-only, Clarity removal.
import {S,sql,rec,check,save,need,mails} from './lib.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const A='R141';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const mk=async(json)=>o.call('companies',{method:'POST',company:false,json:{businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',...json}});
const comps=(cid,code)=>sql(`SELECT GROUP_CONCAT(CONCAT(c.name,' ',c.rate_mpct) ORDER BY c.sort_order SEPARATOR ' + ') FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id WHERE t.company_id='${cid}' AND t.code='${code}'`);
// ---------- DEF-08 ----------
let r=await mk({name:'R141 Quebec Home Inc',province:'QC',taxRegistered:true,taxNumber:'123456789RT0001'});const QC=r.b.company?.id;
check('D8-01',A,'Quebec company: home code QC = GST 5% + QST 9.975%',comps(QC,'QC'),'GST 5000 + QST 9975');
check('D8-02',A,'Quebec company: BC code = GST only (no BC PST unless registered there)',comps(QC,'BC'),'GST 5000');
check('D8-03',A,'Quebec company: MB and SK codes = GST only',[comps(QC,'MB'),comps(QC,'SK')],['GST 5000','GST 5000']);
check('D8-04',A,'Quebec company: ON code still HST 13% (federal HST applies everywhere)',comps(QC,'ON'),'HST 13000');
rec('D8-05',A,'Non-home PST province codes explain how to add the provincial tax',/register.*edit this code and add PST/i.test(sql(`SELECT description FROM tax_codes WHERE company_id='${QC}' AND code='BC'`))?'PASS':'FAIL',sql(`SELECT description FROM tax_codes WHERE company_id='${QC}' AND code='BC'`));
// ---------- Country + province/state on companies ----------
r=await mk({name:'R141 New York LLC',country:'United States',province:'NY',taxRegistered:true,taxNumber:'NY-123'});const US=r.b.company?.id;
rec('CO-01',A,'Company in United States / NY can be created',r.s===201?'PASS':'FAIL',r.s+' '+JSON.stringify(r.b).slice(0,140));
check('CO-02',A,'Stored country and state; no Canadian starter codes; accounting only (payroll is Canada only)',[sql(`SELECT CONCAT(country,'|',province,'|',module_mode) FROM companies WHERE id='${US}'`),sql(`SELECT COUNT(*) FROM tax_codes WHERE company_id='${US}'`)],['United States|NY|accounting','0']);
r=await mk({name:'R141 Bad Province',country:'Canada',province:'NY'});check('CO-03',A,'Canadian company with non-Canadian province refused',[r.s,r.b?.code],[422,'province_invalid']);
r=await mk({name:'R141 Bad Country',country:'Narnia',province:''});check('CO-04',A,'Unknown country refused',[r.s,r.b?.code],[422,'country_invalid']);
r=await mk({name:'R141 Payroll Abroad',country:'United Kingdom',province:'ENG',moduleMode:'payroll'});check('CO-05',A,'Payroll-only company outside Canada refused',[r.s,r.b?.code],[422,'payroll_canada_only']);
o.cid=US;r=await o.call('payroll/setup',{method:'POST',json:{payrollAccountNumber:'123456789RP0001',remitterType:'regular',defaultFrequency:'biweekly'}});
check('CO-06',A,'Payroll Support setup refused for a company outside Canada',[r.s,r.b?.code],[422,'payroll_canada_only']);
// ---------- Foreign tax codes + customers ----------
const acct=(cid,code)=>sql(`SELECT id FROM accounts WHERE company_id='${cid}' AND code='${code}'`);
const nyCode=need('ny code',await o.call('tax-codes',{method:'POST',json:{code:'NY',name:'New York sales tax',region:'US-NY',components:[{name:'Sales tax',ratePercent:'8.875',salesAccountId:acct(US,'2100'),purchaseAccountId:null,purchaseRecoverable:false}]}}));
const usCode=need('us code',await o.call('tax-codes',{method:'POST',json:{code:'US',name:'US default',region:'US',components:[{name:'Sales tax',ratePercent:'6',salesAccountId:acct(US,'2100')}]}}));
rec('FC-01',A,'Tax codes for a country (US) and a state (US-NY) saved',nyCode&&usCode?'PASS':'FAIL',sql(`SELECT GROUP_CONCAT(CONCAT(code,':',region)) FROM tax_codes WHERE company_id='${US}'`));
const cust=async(name,country,province)=>o.call('customers',{method:'POST',json:{name,email:'',phone:'',billingAddress:'1 Main St',country,province}});
const cNY=(await cust('Brooklyn Client','United States','NY')).b?.customer?.id,cCA=(await cust('Los Angeles Client','United States','CA')).b?.customer?.id,cUK=(await cust('London Client','United Kingdom','')).b?.customer?.id;
rec('FC-02',A,'Foreign customers saved with country and optional state',cNY&&cCA&&cUK?'PASS':'FAIL',sql(`SELECT GROUP_CONCAT(CONCAT(name,'|',country,'|',IFNULL(province,'-')) ORDER BY name SEPARATOR '; ') FROM customers WHERE company_id='${US}'`));
r=await cust('Bad Canadian','Canada','');check('FC-03',A,'Canadian customer without a province refused',[r.s,r.b?.code],[422,'province_invalid']);
const tmpl=(await o.call('invoice-templates')).b?.templates?.[0]?.id;
const inv=async(customerId)=>{const x=await o.call('invoices',{method:'POST',json:{customerId,issueDate:'2026-09-15',dueDate:'2026-10-15',currency:'CAD',templateId:tmpl,issue:true,lines:[{description:'Service',quantity:1,unitPriceCents:100000,taxable:true}]}});const id=x.b?.invoice?.id;return {s:x.s,b:x.b,tax:+sql(`SELECT tax_cents FROM invoices WHERE id='${id}'`)}};
r=await inv(cNY);check('FC-04',A,'NY customer $1,000: state code US-NY 8.875% → $88.75',[r.s,r.tax],[201,8875]);
r=await inv(cCA);check('FC-05',A,'California customer (no US-CA code): falls back to country code US 6% → $60.00',[r.s,r.tax],[201,6000]);
r=await inv(cUK);check('FC-06',A,'UK customer with no UK code: no tax',[r.s,r.tax],[201,0]);
o.cid=ids.BC;const cUSfromBC=(await cust('US buyer of BC company','United States','WA')).b?.customer?.id;r=await inv(cUSfromBC);
check('FC-07',A,'BC company selling to a US customer (no US code): no Canadian tax applied',[r.s,r.tax],[201,0]);
const vr=await o.call('vendors',{method:'POST',json:{name:'Indian Supplier',email:'',address:'',defaultTermsDays:30,currency:'CAD',defaultExpenseAccountId:null,country:'India',province:'MH'}});
check('FC-08',A,'Vendor with country India / state MH saved',[vr.s,sql(`SELECT CONCAT(country,'|',province) FROM vendors WHERE company_id='${ids.BC}' AND name='Indian Supplier'`)],[201,'India|MH']);
o.cid=US;r=await o.call('settings',{method:'PUT',json:{name:'R141 New York LLC',legalName:'R141 New York LLC',businessType:'corporation',country:'United States',province:'NJ',currency:'CAD',accountingBasis:'accrual',payrollPostingMode:'draft',reportingFramework:'not_set',fiscalYearEndDate:'2026-12-31',booksStartDate:'2026-01-01',taxRegistered:true,taxNumber:'NY-123'}});
check('FC-09',A,'Company Details: state changed to NJ',[r.s,sql(`SELECT province FROM companies WHERE id='${US}'`)],[200,'NJ']);
// ---------- OBS-2 ----------
o.cid=ids.BC;const before=mails().length;
r=await o.call('platform/invitations',{method:'POST',json:{action:'create_account',email:'r141invite@gate.test',scope:'company',assignments:[{companyId:ids.BC,role:'viewer'}],operationKey:'r141-inv-'+Date.now()}});
const m=mails().slice(before).find(x=>/r141invite@gate.test/.test(x.t));const link=m?.t.match(/https:\/\/gate\.test\/app\.html[^\s"<]*/)?.[0]||'';
rec('OB-01',A,'Invitation email link carries the token after # (not in the query string)',/app\.html#accountSetup=/.test(link)&&!/\?accountSetup=/.test(m?.t||'')?'PASS':'FAIL',link.replace(/=[A-Za-z0-9_-]{10,}/,'=<token>'));
const token=link.split('accountSetup=')[1]||'';const n=new S();
r=await n.call('platform/invite-details',{method:'POST',company:false,json:{token}});check('OB-02',A,'Invitation details looked up by POST body',r.s,200);
r=await n.call('platform/invite-details?token='+token,{company:false});check('OB-03',A,'Old-style lookup (?token=) still works for links already sent',r.s,200);
r=await n.call('platform/invite-details',{method:'POST',company:false,json:{token},origin:'https://evil.example'});check('OB-04',A,'POST lookup from a foreign origin refused',r.s>=400,true);
const al=fs.readFileSync('/srv/gate/apache-access.log','utf8');const hits=al.split('\n').filter(l=>l.includes(token.slice(0,24)));rec('OB-05',A,'POST lookups leave no token in the web access log (only the one old-style GET call does)',hits.length===1&&/GET /.test(hits[0])?'PASS':'FAIL',hits.length+' log line(s) contain the token');
const rr=await new S().call('auth/password-reset-request',{method:'POST',company:false,json:{email:'viewer@gate.test'}});const rm=mails().reverse().find(x=>/viewer@gate.test/.test(x.t)&&/passwordReset/.test(x.t));
rec('OB-06',A,'Password reset email link carries the token after #',rm&&/app\.html#passwordReset=/.test(rm.t)?'PASS':(rm?'FAIL':'BLOCKED'),rr.s+' '+(rm?rm.t.match(/https:\/\/gate\.test\/app\.html[^\s"<]*/)?.[0]?.replace(/=[A-Za-z0-9_-]{10,}/,'=<token>'):'no reset email captured'));
// ---------- Tax code report ----------
o.cid=ids.BC;r=await o.call('tax-codes?usage=1');const bcCode=sql(`SELECT id FROM tax_codes WHERE company_id='${ids.BC}' AND code='BC'`);
const docs=+sql(`SELECT COUNT(DISTINCT CONCAT(document_type,':',document_id)) FROM document_tax_lines WHERE company_id='${ids.BC}' AND tax_code_id='${bcCode}'`);
check('TR-01',A,'Tax Code report usage: BC code document count equals the saved tax detail (SQL)',r.b?.usage?.[bcCode]?.documents,docs);
// ---------- Dashboard figure mapping ----------
const ws0=(await o.call('workspace/summary')).b.summary;
const bankId=acct(ids.BC,'1000'),inc=acct(ids.BC,'4000'),exp=acct(ids.BC,'6000');
r=await o.call('dashboard-mappings',{method:'PUT',json:{card:'bank',plus:[bankId]}});check('DM-01',A,'Map "Money in the bank" to GL 1000 only',r.s,200);
r=await o.call('dashboard-mappings',{method:'PUT',json:{card:'profit',plus:[inc],minus:[exp]}});check('DM-02',A,'Map "Profit this year" to 4000 minus 6000',r.s,200);
const ws1=(await o.call('workspace/summary')).b.summary;
const gl=code=>+sql(`SELECT COALESCE(SUM(jl.debit_cents)-SUM(jl.credit_cents),0) FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id='${ids.BC}' AND a.code='${code}' AND je.status='posted'`);
check('DM-03',A,'Dashboard bank figure = GL 1000 balance (SQL)',ws1.bankBalanceCents,gl('1000'));
check('DM-04',A,'Dashboard profit = −(4000) − 6000 (SQL), mapped cards listed',[ws1.netIncomeYtdCents,ws1.customMappedCards],[-gl('4000')-gl('6000'),['bank','profit']]);
r=await o.call('dashboard-mappings',{method:'PUT',json:{card:'profit',plus:[exp],minus:[inc]}});check('DM-05',A,'Wrong account types for profit refused',[r.s,r.b?.code],[422,'dashboard_account_type']);
const vw=new S();await vw.login('viewer@gate.test','Gate!Member#2026pw').catch(()=>{});vw.cid=ids.BC;r=await vw.call('dashboard-mappings',{method:'PUT',json:{card:'bank',reset:true}});
rec('DM-06',A,'Viewer cannot change dashboard figures',r.s>=400?'PASS':'FAIL',String(r.s));
const x=new S();await x.login('otherowner@gate.test','Gate!Member#2026pw');x.cid=ids.X;r=await x.call('dashboard-mappings',{method:'PUT',json:{card:'bank',plus:[bankId]}});
check('DM-07',A,'Another company\'s GL account refused',[r.s,r.b?.code],[422,'dashboard_account_unavailable']);
await o.call('dashboard-mappings',{method:'PUT',json:{card:'bank',reset:true}});await o.call('dashboard-mappings',{method:'PUT',json:{card:'profit',reset:true}});
const ws2=(await o.call('workspace/summary')).b.summary;check('DM-08',A,'Reset: dashboard figures back to the standard calculation',[ws2.bankBalanceCents,ws2.netIncomeYtdCents,(ws2.customMappedCards||[]).length],[ws0.bankBalanceCents,ws0.netIncomeYtdCents,0]);
// ---------- Clarity / payroll wording ----------
const www='/srv/gate/www/';const files=['app.html','index.html','company.html','product.html','.htaccess'];
rec('CL-01',A,'No Microsoft Clarity script or CSP allowance anywhere in the package',files.every(f=>!/clarity\.ms|c\.bing\.com/.test(fs.readFileSync(www+f,'utf8')))?'PASS':'FAIL','');
rec('PW-01',A,'Product page says Payroll Support Tool, Canada only, no "payroll engine"',/Payroll Support Tool/.test(fs.readFileSync(www+'product.html','utf8'))&&!/payroll engine/i.test(fs.readFileSync(www+'product.html','utf8'))&&/Canada only/.test(fs.readFileSync(www+'product.html','utf8'))?'PASS':'FAIL','');
rec('PW-02',A,'In-app Payroll Support notice says Canadian payroll only',/Payroll Support is for Canadian payroll only/.test(fs.readFileSync(www+'assets/tegh-portal-v5990.js','utf8'))&&/Payroll Support is for Canadian payroll only/.test(fs.readFileSync(www+'assets/index-BsxPiq85-v2817.js','utf8'))?'PASS':'FAIL','');
fs.writeFileSync('/srv/gate/t/ids.json',JSON.stringify({...ids,US,QCHOME:QC}));
save('r141.json');
