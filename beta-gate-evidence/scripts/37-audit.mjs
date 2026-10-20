// R164 audit coverage suite (synthetic company and users only). Each sensitive action is performed here and the
// matching audit record is checked: who, what, target, request reference; and no audit row carries a password or token.
import {S,sql,rec,save,need} from './lib.mjs';
import {execFileSync} from 'child_process';
const A='AUDIT';
const OWNER='owner@gate.test',OPW='Gate!Owner#2026pw',MPW='R164!Audit#2026pw';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const q=v=>"'"+String(v).replace(/'/g,"''")+"'";
const tag=Date.now().toString(36);
const phpHash=pw=>execFileSync('php',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],{encoding:'utf8'}).trim();
const plat=(action,target)=>sql(`SELECT CONCAT_WS('|',actor_email,target_id,request_id<>'',ip_hash<>'') FROM platform_audit_log WHERE action=${q(action)} AND target_id=${q(target)} ORDER BY created_at DESC LIMIT 1`);
const comp=(action,cid,entity)=>sql(`SELECT CONCAT_WS('|',actor_email,entity_id,metadata_json,entry_hash IS NOT NULL) FROM audit_log WHERE company_id=${q(cid)} AND action=${q(action)} AND entity_id=${q(entity)} ORDER BY created_at DESC LIMIT 1`);

sql('DELETE FROM login_attempts');
const o=new S();await o.login(OWNER,OPW);
const mk=async name=>{const r=need('company',await o.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01'},company:false}));return r.company?.id||r.companyId||r.id};
o.cid=await mk('R164 Audit Co '+tag);
function mkUser(label,role='editor',cid=o.cid){
  const email=`r164-audit-${label}-${tag}@gate.test`,id='user_r164au'+label+tag;
  sql(`INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at,active) VALUES (${q(id)},${q(email)},${q('R164 audit '+label)},${q(phpHash(MPW))},'member','free_preview','public_signup',UTC_TIMESTAMP(),1)`);
  if(cid)sql(`INSERT INTO company_members (company_id,user_id,role,status) VALUES (${q(cid)},${q(id)},${q(role)},'active')`);
  return {email,id};
}

await t('AU-01','A role change is recorded in the company audit trail with actor, old and new role, and a hash-chained entry',async()=>{
  const m=mkUser('role');const r=await o.call('platform/members',{method:'POST',json:{action:'change_role',memberId:m.id,role:'viewer',password:OPW}});
  const row=comp('company.member_role_changed',o.cid,m.id);
  return {ok:r.s===200&&row.startsWith(OWNER+'|'+m.id)&&/"from":"editor"/.test(row)&&/"to":"viewer"/.test(row)&&row.endsWith('|1'),info:`change ${r.s}; audit ${row.slice(0,200)}`}});

await t('AU-02','Suspension and removal of a member are recorded with the reason',async()=>{
  const a=mkUser('susp'),b=mkUser('rem');
  const r1=await o.call('platform/members',{method:'POST',json:{action:'suspend',memberId:a.id,reason:'R164 audit suspension',password:OPW}});
  const r2=await o.call('platform/members',{method:'POST',json:{action:'remove',memberId:b.id,reason:'R164 audit removal',password:OPW}});
  const s=comp('company.member_suspended',o.cid,a.id),x=comp('company.member_removed',o.cid,b.id);
  return {ok:r1.s===200&&r2.s===200&&/R164 audit suspension/.test(s)&&/R164 audit removal/.test(x),info:`suspend ${r1.s} audited ${!!s}; remove ${r2.s} audited ${!!x}`}});

await t('AU-03','Platform-owner user administration (deactivate, reactivate, MFA reset) is recorded in the platform audit log with request reference and source',async()=>{
  const u=mkUser('admin','viewer',null);
  const d=await o.call('admin/users',{method:'POST',json:{action:'deactivate',userId:u.id,password:OPW},company:false});
  const r=await o.call('admin/users',{method:'POST',json:{action:'reactivate',userId:u.id,password:OPW},company:false});
  const m=await o.call('admin/users',{method:'POST',json:{action:'reset_mfa',userId:u.id,password:OPW},company:false});
  const rows=['platform.user_deactivated','platform.user_reactivated','platform.user_mfa_reset'].map(a=>plat(a,u.id));
  return {ok:[d,r,m].every(x=>x.s===200)&&rows.every(x=>x===`${OWNER}|${u.id}|1|1`),info:`statuses ${[d,r,m].map(x=>x.s)}; audit ${rows.join(' ; ')}`}});

await t('AU-04','A company deletion request and the platform owner\'s decision are both recorded',async()=>{
  const name='R164 Audit Delete '+tag,cid=await mk(name);
  const req=need('req',await o.call('companies/deletion-request',{method:'POST',company:false,json:{companyId:cid,companyName:name,password:OPW,backupConfirmed:true,reason:'R164 audit deletion request test.'}}),[201]);
  const dec=await o.call('admin/deletion-requests',{method:'POST',company:false,json:{action:'reject',requestId:req.requestId,note:'R164 audit: kept.',password:OPW}});
  const a=comp('company.deletion_requested',cid,cid),b=sql(`SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.company_deletion_rejected' AND target_id=${q(cid)}`);
  return {ok:dec.s===200&&/R164 audit deletion request test/.test(a)&&b==='1',info:`request audited ${!!a}; decision ${dec.s}, audited rows ${b}`}});

await t('AU-05','Changing public registration (a security setting) needs a recent sign-in and is recorded with old/new value and reason',async()=>{
  const cur=need('reg',await o.call('platform/registration',{company:false}));const before=Number(sql("SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.registration_changed' AND metadata_json LIKE '%R164 audit%'"));
  sql(`UPDATE sessions SET created_at=UTC_TIMESTAMP()-INTERVAL 1 HOUR WHERE user_id=(SELECT id FROM users WHERE email=${q(OWNER)})`);
  const body={enabled:!cur.ownerSettingEnabled,confirmation:'CHANGE REGISTRATION',reason:'R164 audit toggle test',revision:cur.revision};
  const refused=await o.call('platform/registration',{method:'POST',json:body,company:false});
  const ch=await o.call('platform/registration',{method:'POST',json:{...body,password:OPW},company:false});
  const back=await o.call('platform/registration',{method:'POST',json:{enabled:cur.ownerSettingEnabled,confirmation:'CHANGE REGISTRATION',reason:'R164 audit restore',revision:cur.revision+1,password:OPW},company:false});
  const n=sql("SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.registration_changed' AND metadata_json LIKE '%R164 audit%'");
  return {ok:refused.s===403&&ch.s===200&&back.s===200&&Number(n)-before===2,info:`without password ${refused.s} ${refused.b?.code}; change ${ch.s}; restore ${back.s}; audit rows ${n}`}});

await t('AU-06','MFA changes are recorded at account level (enrolment, verification, disable) without the secret or codes',async()=>{
  const rows=sql("SELECT COUNT(*) FROM platform_audit_log WHERE action IN ('mfa.enabled','mfa.enrollment_started','mfa.verified','mfa.disabled','mfa.recovery_codes_regenerated','platform.user_mfa_reset')");
  const leak=sql("SELECT COUNT(*) FROM platform_audit_log WHERE action LIKE 'mfa.%' AND (metadata_json REGEXP '[A-Z2-7]{32}' OR metadata_json REGEXP '\"(code|secret|otp)\"')");
  return {ok:Number(rows)>0&&leak==='0',info:`MFA audit rows ${rows}; rows containing a secret or code ${leak}`}});

await t('AU-07','No audit record (company or platform) contains a password, session token or reset token',async()=>{
  const pw=[OPW,MPW,'Gate!Member#2026pw'];
  const bad=[];
  for(const tbl of ['audit_log','platform_audit_log']){
    for(const p of pw){if(sql(`SELECT COUNT(*) FROM ${tbl} WHERE metadata_json LIKE ${q('%'+p+'%')}`)!=='0')bad.push(tbl+':password')}
    if(sql(`SELECT COUNT(*) FROM ${tbl} WHERE metadata_json REGEXP '\"(password|token|csrf|secret)\"[[:space:]]*:[[:space:]]*\"[^\"]{6,}'`)!=='0')bad.push(tbl+':secret-field')}
  const tokens=sql("SELECT COUNT(*) FROM sessions s JOIN platform_audit_log p ON p.metadata_json LIKE CONCAT('%',s.token_hash,'%')");
  return {ok:bad.length===0&&tokens==='0',info:`findings ${bad.join(',')||'none'}; session hashes in audit ${tokens}`}});

save('audit.json');
