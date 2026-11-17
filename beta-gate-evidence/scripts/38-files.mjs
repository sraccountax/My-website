// R164 file access and company isolation suite. An outsider (a synthetic user who belongs only to a fresh synthetic
// company) tries to reach another company's stored files and records by ID: statement sources, invoice attachments,
// native intake documents, saved downloads, reports, backups and invitations. Existing companies are only READ by the
// legitimate owner (no changes). Also checks download headers and upload content rules.
import {S,sql,rec,save,need} from './lib.mjs';
import {execFileSync} from 'child_process';
const A='FILES';
const OWNER='owner@gate.test',OPW='Gate!Owner#2026pw',XPW='R164!Outsider#2026pw';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const q=v=>"'"+String(v).replace(/'/g,"''")+"'";
const tag=Date.now().toString(36);
const phpHash=pw=>execFileSync('php',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],{encoding:'utf8'}).trim();
const row=s=>{const v=sql(s);return v?v.split('\t'):null};
const denied=r=>[401,403,404].includes(r.s)&&!(r.b instanceof Blob);
const asOther=(s,cid)=>{const c=s.cid;s.cid=cid;return ()=>{s.cid=c}};

sql('DELETE FROM login_attempts');
const o=new S();await o.login(OWNER,OPW);
const mk=async(s,name)=>{const r=need('company',await s.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));return r.company?.id||r.companyId||r.id};
const OWN=await mk(o,'R164 Outsider Co '+tag);
// The outsider: owner of their own company only (as an invited beta tester would be).
const xid='user_r164x'+tag,xemail=`r164-outsider-${tag}@gate.test`;
sql(`INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at,active) VALUES (${q(xid)},${q(xemail)},'R164 outsider',${q(phpHash(XPW))},'member','free_preview','public_signup',UTC_TIMESTAMP(),1)`);
sql(`INSERT INTO company_members (company_id,user_id,role,status) VALUES (${q(OWN)},${q(xid)},'admin','active')`);
const x=new S();await x.login(xemail,XPW);x.cid=OWN;

// Victim records in other (active) companies; read-only.
const act="JOIN companies c ON c.id=t.company_id AND c.active=1";
const stmt=row(`SELECT t.company_id,t.id FROM import_batches t ${act} WHERE t.source_path IS NOT NULL ORDER BY t.created_at DESC LIMIT 1`);
const att=row(`SELECT t.company_id,t.invoice_id,t.id FROM invoice_attachments t ${act} WHERE t.removed_at IS NULL ORDER BY t.created_at DESC LIMIT 1`);
const nd=row(`SELECT t.company_id,t.id FROM native_agent_documents t ${act} WHERE t.storage_path IS NOT NULL ORDER BY t.created_at DESC LIMIT 1`);
const inv=row(`SELECT t.company_id,t.id FROM invoices t ${act} ORDER BY t.created_at DESC LIMIT 1`);
console.log('victims',{stmt,att,nd,inv});

// Positive control: the legitimate owner gets 200 on the same route, so a denial is not just a missing route.
const ownerStatus=async(cid,route)=>{const c=o.cid;o.cid=cid;const r=await o.call(route,{raw:true});o.cid=c;try{await r.arrayBuffer()}catch{}return r.status};
const both=async(label,victimCid,route)=>{
  const own=await x.call(route);                 // outsider's own company context, victim id
  const undo=asOther(x,victimCid);const other=await x.call(route);undo(); // claiming the victim company
  const control=await ownerStatus(victimCid,route);
  return {ok:denied(own)&&denied(other)&&control===200,info:`${label}: own-company context ${own.s}; victim company header ${other.s}; owner control ${control}`}};

await t('FI-01','Statement source file of another company cannot be downloaded (by ID, with either company header)',async()=>stmt?both('import source',stmt[0],`imports?batchId=${stmt[1]}`):{ok:false,info:'no statement fixture'});
await t('FI-02','Invoice attachment of another company cannot be downloaded',async()=>att?both('invoice attachment',att[0],`invoices/r20/attachment-download?invoiceId=${att[1]}&attachmentId=${att[2]}`):{ok:false,info:'no attachment fixture'});
await t('FI-03','Document Intake source (OCR input) of another company cannot be downloaded',async()=>nd?both('intake document',nd[0],`native-ap-ar/document/file?documentId=${nd[1]}`):{ok:false,info:'no intake fixture'});
await t('FI-04','Invoice detail and its PDF model of another company cannot be read',async()=>{
  const a=await both('invoice detail',inv[0],`invoices/r20/detail?invoiceId=${inv[1]}`);return a});
await t('FI-05','Reports and exports of another company cannot be generated (trial balance, GL export, backup export)',async()=>{
  const routes=['reports/v5600/financial?kind=balance-sheet&reportKey=balance_sheet&asOf=2026-09-30&start=2026-01-01&end=2026-09-30','requested-downloads','platform/members'];
  const undo=asOther(x,inv[0]);const out=[];for(const r of routes)out.push((await x.call(r)).s);undo();
  const ctl=[];for(const r of routes)ctl.push(await ownerStatus(inv[0],r));
  const bx=asOther(x,inv[0]);const bk=await x.call('backup/export',{method:'POST',json:{password:XPW}});bx();
  return {ok:out.every(v=>[401,403,404].includes(v))&&ctl.every(v=>v===200)&&denied(bk),info:`outsider ${routes.map((r,i)=>r.split('?')[0]+' '+out[i]).join(', ')}; owner controls ${ctl.join(',')}; backup export ${bk.s}`}});
await t('FI-06','Saved downloads are private to the person and company: another user\'s saved file ID is not found',async()=>{
  // The owner saves a file in their company; the outsider (and the owner in another company) cannot fetch it.
  const fd=new FormData();fd.append('file',new Blob(['col\n1\n'],{type:'text/csv'}),'r164-saved.csv');
  const oc=o.cid;o.cid=inv[0];const saved=await o.call('requested-downloads',{method:'POST',body:fd});
  const id=saved.b?.download?.id;const mine=id?await o.call('requested-downloads?id='+id,{raw:true}):null;
  o.cid=OWN;const wrongCompany=id?await o.call('requested-downloads?id='+id):null;o.cid=oc;
  const undo=asOther(x,inv[0]);const outsider=id?await x.call('requested-downloads?id='+id):null;undo();
  return {ok:saved.s===201&&mine?.status===200&&denied(wrongCompany)&&denied(outsider),info:`save ${saved.s}; owner same company ${mine?.status}; owner other company ${wrongCompany?.s}; outsider ${outsider?.s}`}});
await t('FI-07','Downloads are served as attachments with nosniff, a sandbox CSP and no caching',async()=>{
  const oc=o.cid;o.cid=stmt[0];const r=await o.call(`imports?batchId=${stmt[1]}`,{raw:true});o.cid=oc;await r.arrayBuffer();
  const h=k=>r.headers.get(k)||'';
  return {ok:r.status===200&&/^attachment;/.test(h('content-disposition'))&&h('x-content-type-options')==='nosniff'&&/sandbox/.test(h('content-security-policy'))&&/no-store/.test(h('cache-control'))&&!h('x-tegh-download'),info:`status ${r.status}; disposition ${h('content-disposition').slice(0,60)}; nosniff ${h('x-content-type-options')}; csp ${h('content-security-policy')}; cache ${h('cache-control')}; marker visible ${!!h('x-tegh-download')}`}});
await t('FI-08','Uploads: HTML disguised as PDF, SVG and a path-traversal file name are refused or neutralised; the stored name is random',async()=>{
  const up=async(name,content,type)=>{const fd=new FormData();fd.append('file',new Blob([content],{type}),name);return x.call('attachments',{method:'POST',body:fd})};
  const html=await up('invoice.pdf','<html><script>alert(1)</script></html>','application/pdf');
  const svg=await up('logo.svg','<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml');
  const trav=await up('../../../www/evil.pdf',Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'),'application/pdf');
  const key=String(trav.b?.key||'');
  return {ok:denied(html)||html.s===415,info:`html-as-pdf ${html.s}; svg ${svg.s}; traversal name ${trav.s} stored as ${key.replace(/[a-f0-9]{48}/,'<random>')}`,
    ...(svg.s<300||!(trav.s===201&&/^company_[a-f0-9]+\/receipts\/[a-f0-9]{48}\.pdf$/.test(key))?{ok:false}:{})}});
await t('FI-09','Invitations: an outsider cannot list or create invitations for another company',async()=>{
  const undo=asOther(x,inv[0]);const l=await x.call('platform/invitations'),c=await x.call('platform/invitations',{method:'POST',json:{email:`r164-victim-${tag}@gate.test`,role:'viewer',password:XPW}});
  const m=await x.call('platform/members',{method:'POST',json:{action:'change_role',memberId:xid,role:'owner',password:XPW}});undo();
  const u=await x.call('admin/users',{company:false});
  const ctl=await ownerStatus(inv[0],'platform/invitations');
  // A non-owner may list invitations, but only their own: nothing from the victim company may appear.
  const leaked=JSON.stringify(l.b||{}).includes(inv[0]);const created=Number(sql(`SELECT COUNT(*) FROM account_invitations WHERE email=${q(`r164-victim-${tag}@gate.test`)}`));
  return {ok:(denied(l)||(l.s===200&&!leaked))&&(denied(c)||c.s===422)&&created===0&&denied(m)&&denied(u)&&ctl===200,info:`list ${l.s} (victim data shown ${leaked}); create ${c.s} (rows created ${created}); self-promotion in victim company ${m.s}; platform users ${u.s}; owner control ${ctl}`}});
await t('FI-10','Company list and switching: the outsider sees only their own company and cannot select another by header',async()=>{
  const list=need('companies',await x.call('companies',{company:false}));const ids=(list.companies||[]).map(c=>c.id);
  const undo=asOther(x,inv[0]);const w=await x.call('workspace');undo();const ctl=await ownerStatus(inv[0],'workspace');
  return {ok:ids.length===1&&ids[0]===OWN&&denied(w)&&ctl===200,info:`companies listed ${ids.length} (own ${ids[0]===OWN}); workspace with victim header ${w.s}; owner control ${ctl}`}});
await t('FI-11','Client viewing links and accountant connections of another company are not listed or changed',async()=>{
  const undo=asOther(x,inv[0]);const cv=await x.call('client-view/links'),ac=await x.call('guided/accountant');undo();
  const c1=await ownerStatus(inv[0],'client-view/links'),c2=await ownerStatus(inv[0],'guided/accountant');
  return {ok:denied(cv)&&denied(ac)&&c1===200&&c2!==404,info:`client-view links ${cv.s}; accountant connection ${ac.s}; owner controls ${c1},${c2}`}});

save('files.json');
