// R164 restore safety suite (synthetic login and company only). Simulates restoring an older database backup after
// a deletion by putting the deleted rows back exactly as they were (including an old session), then checks that
// nothing deleted becomes usable again, that monitoring alerts, and that the operator can delete again.
import {S,sql,rec,save,need} from './lib.mjs';
import {execFileSync,spawnSync} from 'child_process';
import fs from 'fs';
const A='RESTORE';
const OWNER='owner@gate.test',OPW='Gate!Owner#2026pw',XPW='R164!Restore#2026pw';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const q=v=>"'"+String(v).replace(/'/g,"''")+"'";
const tag=Date.now().toString(36);
const phpHash=pw=>execFileSync('php',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',pw],{encoding:'utf8'}).trim();
const REG='/srv/gate/sr-accountax-private/deletion-register.ndjson';
const cron=()=>{const r=spawnSync('runuser',['-u','www-data','--','php','/srv/gate/www/api/monitor-cron.php'],{encoding:'utf8'});return (r.stdout||'')+(r.stderr||'')};

sql('DELETE FROM login_attempts');
const o=new S();await o.login(OWNER,OPW);
const name='R164 Restore Co '+tag;
const cr=need('company',await o.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01'},company:false}));
const CID=cr.company?.id||cr.companyId||cr.id;
const xid='user_r164r'+tag,xemail=`r164-restore-${tag}@gate.test`;
sql(`INSERT INTO users (id,email,display_name,password_hash,platform_role,account_plan,signup_source,terms_accepted_at,active) VALUES (${q(xid)},${q(xemail)},'R164 restore',${q(phpHash(XPW))},'member','free_preview','public_signup',UTC_TIMESTAMP(),1)`);
sql(`INSERT INTO company_members (company_id,user_id,role,status) VALUES (${q(CID)},${q(xid)},'editor','active')`);
const x=new S();await x.login(xemail,XPW);x.cid=CID;

// "Backup": copies of the rows as they are now.
const T=`r164_bk_${tag}`;
sql(`CREATE TABLE ${T}_users AS SELECT * FROM users WHERE id=${q(xid)}`);
sql(`CREATE TABLE ${T}_sessions AS SELECT * FROM sessions WHERE user_id=${q(xid)}`);
sql(`CREATE TABLE ${T}_companies AS SELECT * FROM companies WHERE id=${q(CID)}`);
sql(`CREATE TABLE ${T}_members AS SELECT * FROM company_members WHERE company_id=${q(CID)}`);

await t('RS-01','Deleting a login and a company records both on the deletion register (outside the database and the backed-up storage), with no plain email',async()=>{
  const d1=await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:xid,confirmationText:xemail,password:OPW,reason:'R164 restore-safety test deletion',overrideConfirmed:true,dataLossAccepted:true}});
  const d2=await o.call('admin/companies',{method:'POST',company:false,json:{action:'delete_company_override',companyId:CID,confirmationText:name,password:OPW,reason:'R164 restore-safety test deletion',overrideConfirmed:true,dataLossAccepted:true}});
  const reg=fs.existsSync(REG)?fs.readFileSync(REG,'utf8'):'';
  const ok=d1.s===200&&d2.s===200&&reg.includes(`"id":"${xid}"`)&&reg.includes(`"id":"${CID}"`)&&!reg.includes(xemail)&&!REG.startsWith('/srv/gate/sr-accountax-private/storage');
  return {ok,info:`delete login ${d1.s} ${d1.b?.code||''}; delete company ${d2.s} ${d2.b?.code||''}; register has login ${reg.includes(xid)}, company ${reg.includes(CID)}; plain email present ${reg.includes(xemail)}`}});

// "Restore of the older backup": the deleted rows come back exactly as they were, including the old session.
sql(`UPDATE users u JOIN ${T}_users b ON b.id=u.id SET u.email=b.email,u.display_name=b.display_name,u.password_hash=b.password_hash,u.active=1,u.deleted_at=NULL,u.deleted_by=NULL`);
sql(`INSERT INTO sessions SELECT * FROM ${T}_sessions`);
sql(`INSERT INTO companies SELECT * FROM ${T}_companies`);
sql(`INSERT INTO company_members SELECT * FROM ${T}_members`);

await t('RS-02','After the restore, the deleted login cannot sign in with its old password',async()=>{
  sql('DELETE FROM login_attempts');const s=new S();const r=await s.call('auth/login',{method:'POST',json:{email:xemail,password:XPW},company:false});
  return {ok:r.s===401,info:`login ${r.s} ${r.b?.code||''}`}});
await t('RS-03','After the restore, the deleted login\'s old session is refused and removed',async()=>{
  const me=await x.call('auth/me',{company:false});const left=sql(`SELECT COUNT(*) FROM sessions WHERE user_id=${q(xid)}`);
  return {ok:me.s===401&&left==='0',info:`old session auth/me ${me.s}; sessions left ${left}`}});
await t('RS-04','After the restore, the deleted company cannot be opened, even by its owner',async()=>{
  const c=o.cid;o.cid=CID;const w=await o.call('workspace');o.cid=c;
  return {ok:w.s===410&&w.b?.code==='company_deleted',info:`workspace ${w.s} ${w.b?.code}`}});
await t('RS-05','Monitoring reports the reappeared login and company and an incident is recorded',async()=>{
  fs.rmSync('/srv/gate/sr-accountax-private/storage/runtime/monitor-state.json',{force:true});
  const out=cron();const inc=sql("SELECT COUNT(*) FROM platform_incident_log WHERE error_code IN ('deleted_user_reappeared','deleted_company_reappeared') AND occurred_at>=UTC_TIMESTAMP()-INTERVAL 10 MINUTE");
  return {ok:/deleted_reappeared/.test(out)&&Number(inc)>=2,info:`sweep: ${out.trim().slice(0,160)}; incidents ${inc}`}});
await t('RS-06','The platform owner can delete them again; afterwards monitoring is clear',async()=>{
  const d1=await o.call('admin/users',{method:'POST',company:false,json:{action:'delete_login',userId:xid,confirmationText:xemail,password:OPW,reason:'R164 re-delete after restore',overrideConfirmed:true,dataLossAccepted:true}});
  const d2=await o.call('admin/companies',{method:'POST',company:false,json:{action:'delete_company_override',companyId:CID,confirmationText:name,password:OPW,reason:'R164 re-delete after restore',overrideConfirmed:true,dataLossAccepted:true}});
  fs.rmSync('/srv/gate/sr-accountax-private/storage/runtime/monitor-state.json',{force:true});const out=cron();
  return {ok:d1.s===200&&d2.s===200&&!/deleted_reappeared/.test(out),info:`re-delete login ${d1.s}, company ${d2.s}; sweep: ${out.trim().slice(0,160)}`}});
await t('RS-07','The deletion register is not reachable over the web',async()=>{
  const c=execFileSync('curl',['-sk','--noproxy','*','-o','/dev/null','-w','%{http_code}','https://gate.test/../sr-accountax-private/deletion-register.ndjson'],{encoding:'utf8'});
  const d=execFileSync('curl',['-sk','--noproxy','*','-o','/dev/null','-w','%{http_code}','https://gate.test/deletion-register.ndjson'],{encoding:'utf8'});
  return {ok:c!=='200'&&d!=='200',info:`attempts ${c}, ${d}`}});

for(const s of ['users','sessions','companies','members'])sql(`DROP TABLE IF EXISTS ${T}_${s}`);
save('restore.json');
