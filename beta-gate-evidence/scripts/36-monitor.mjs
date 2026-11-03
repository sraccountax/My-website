// R164 operator monitoring suite (test host, synthetic data only).
// Backup failure / missing alerts from the real backup script's status file, retention (14 days, verified),
// suspicious sign-in alert, serious-error alert, flood throttling, and alert emails free of personal/financial data.
import {sql,rec,save,mails} from './lib.mjs';
import {execFileSync,spawnSync} from 'child_process';
import fs from 'fs';
const A='MONITOR';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const RT='/srv/gate/sr-accountax-private/storage/runtime', STATE=RT+'/monitor-state.json', BSTATUS=RT+'/backup-status.json';
const OUT='/srv/gate/backups-r164', OPS='/home/user/My-website/beta-ops';
const cron=()=>{const r=spawnSync('runuser',['-u','www-data','--','php','/srv/gate/www/api/monitor-cron.php'],{encoding:'utf8'});return {rc:r.status,out:(r.stdout||'')+(r.stderr||'')}};
const alertMails=()=>mails().filter(m=>/Subject: \[Tegh alert\]/.test(m.t));
const newAlerts=before=>alertMails().filter(m=>!before.has(m.f));
const seen=()=>new Set(alertMails().map(m=>m.f));
const resetState=()=>{fs.rmSync(STATE,{force:true});};
const backup=(extra={})=>spawnSync(OPS+'/tegh-backup.sh',[],{encoding:'utf8',env:{...process.env,TEGH_DB:'tegh_gate',TEGH_STORAGE:'/srv/gate/sr-accountax-private/storage',TEGH_OUT:OUT,...extra}});
const body=m=>m.t;

fs.rmSync(OUT,{recursive:true,force:true});fs.mkdirSync(OUT,{recursive:true});
fs.rmSync(BSTATUS,{force:true});resetState();sql('DELETE FROM login_attempts');sql('UPDATE user_mfa SET locked_until=NULL');
sql('DELETE FROM password_reset_requests WHERE created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR');

await t('MO-01','No backup status recorded: the sweep raises "no successful backup" and emails the operator',async()=>{
  const b=seen();const r=cron();const n=newAlerts(b);
  const ok=r.rc===2&&/backup_missing/.test(r.out)&&n.length===1&&/No successful backup recently/.test(body(n[0]))&&/To: <support@gate\.test>/.test(body(n[0]));
  return {ok,info:`cron rc ${r.rc}: ${r.out.trim()}; new alert emails ${n.length}`}});

await t('MO-02','A repeat inside the throttle window sends no second email and is counted for the next one (flood prevention)',async()=>{
  const b=seen();const r1=cron(),r2=cron();const n=newAlerts(b);const st=JSON.parse(fs.readFileSync(STATE,'utf8'));
  return {ok:n.length===0&&st.backup_missing?.suppressed===2,info:`new emails ${n.length}; suppressed count ${st.backup_missing?.suppressed}`}});

await t('MO-03','A successful run of the real backup script records success; the next sweep raises no backup alert',async()=>{
  const r=backup();resetState();const st=JSON.parse(fs.readFileSync(BSTATUS,'utf8'));const c=cron();
  return {ok:r.status===0&&st.lastRunStatus==='ok'&&!!st.lastSuccessAt&&!/backup_/.test(c.out),info:`backup rc ${r.status}; status ${JSON.stringify(st)}; sweep: ${c.out.trim()}`}});

await t('MO-04','A failed backup run is recorded (last success kept) and raises "the last backup failed"',async()=>{
  const flag=RT+'/maintenance.json';fs.writeFileSync(flag,'{"token":"r164-test","reason":"test"}');
  let r;try{r=backup()}finally{fs.rmSync(flag,{force:true})}
  resetState();const st=JSON.parse(fs.readFileSync(BSTATUS,'utf8'));const b=seen();const c=cron();const n=newAlerts(b);
  const ok=r.status!==0&&st.lastRunStatus==='failed'&&!!st.lastSuccessAt&&/backup_failed/.test(c.out)&&!/backup_missing/.test(c.out)&&n.some(m=>/The last backup failed/.test(body(m)));
  return {ok,info:`backup rc ${r.status}; status ${st.lastRunStatus}, last success ${st.lastSuccessAt}; sweep: ${c.out.trim()}`}});

await t('MO-05','Retention: local copies older than 14 days are deleted and the deletion is verified; younger copies stay',async()=>{
  const old=OUT+'/tegh-20260901-021500',young=OUT+'/tegh-20260920-021500';
  for(const d of [old,young]){fs.mkdirSync(d,{recursive:true});fs.writeFileSync(d+'/db.sql.gz','x')}
  execFileSync('touch',['-d','15 days ago',old]);execFileSync('touch',['-d','13 days ago',young]);
  const r=backup();
  return {ok:r.status===0&&!fs.existsSync(old)&&fs.existsSync(young)&&/retention: \d+ local copies kept \(limit 14 days\)/.test(r.stdout),info:`rc ${r.status}; 15-day copy present ${fs.existsSync(old)}; 13-day copy present ${fs.existsSync(young)}; ${(r.stdout.match(/retention:.*/)||[''])[0]}`}});

await t('MO-06','A burst of failed sign-ins against many accounts from one source raises "suspicious sign-in activity" with counts only',async()=>{
  resetState();const ip='f'.repeat(64);
  const vals=[];for(let i=0;i<36;i++)vals.push(`('${(i%12).toString(16).padStart(64,'a')}','${ip}',0,UTC_TIMESTAMP())`);
  sql('INSERT INTO login_attempts (email_hash,ip_hash,successful,attempted_at) VALUES '+vals.join(','));
  const b=seen();const c=cron();const n=newAlerts(b);const txt=n.map(body).join('\n');
  const ok=/auth_suspicious/.test(c.out)&&n.length===1&&/Failed sign-ins: 36 \(12 accounts, 1 sources\)/.test(txt)&&/Most accounts tried from one source: 12/.test(txt);
  sql('DELETE FROM login_attempts');
  return {ok,info:`sweep: ${c.out.trim()}; emails ${n.length}`}});

await t('MO-07','A serious server error raises an alert after the response; the email has the request reference but not the error text',async()=>{
  resetState();const b=seen();
  const php=`require '/srv/gate/www/api/bootstrap.php'; require '/srv/gate/www/api/monitoring_r164.php';
    record_system_incident('Tegh could not complete this request.', 500, 'internal_error', new RuntimeException('password=hunter2 invoice total 1234.56 for client@example.com'));`;
  const r=spawnSync('runuser',['-u','www-data','--','php','-r',php],{encoding:'utf8'});
  const n=newAlerts(b);const txt=n.map(body).join('\n');
  const ok=r.status===0&&n.length===1&&/Serious server errors/.test(txt)&&/Request reference:/.test(txt)&&!/hunter2|1234\.56|client@example\.com/.test(txt);
  return {ok,info:`php rc ${r.status} ${r.stderr.slice(0,120)}; emails ${n.length}; leaked text ${/hunter2|1234\.56|client@example\.com/.test(txt)}`}});

await t('MO-08','No alert email body contains email addresses of the people involved, hashes, tokens or amounts',async()=>{
  const all=alertMails().map(m=>{const i=m.t.indexOf('\n\n');return m.t.slice(i)}).join('\n');
  const bad=[/@(?!gate\.test\b)[a-z0-9.-]+\.[a-z]{2,}/i,/\b[a-f0-9]{64}\b/,/hunter2/,/\$\s?\d/,/sess|csrf/i].filter(re=>re.test(all.replace(/support@gate\.test|no-reply@gate\.test/g,'')));
  return {ok:bad.length===0,info:`alert emails checked ${alertMails().length}; patterns found ${bad.map(String).join(' ')||'none'}`}});

await t('MO-09','The monitoring script and its state are not reachable over the web',async()=>{
  const code=u=>execFileSync('curl',['-sk','--noproxy','*','-o','/dev/null','-w','%{http_code}',u],{encoding:'utf8'});
  const a=code('https://gate.test/api/monitor-cron.php'),b=code('https://gate.test/storage/runtime/monitor-state.json'),c=code('https://gate.test/api/monitoring_r164.php');
  return {ok:[a,b].every(x=>x==='403'||x==='404')&&c!=='500',info:`monitor-cron.php ${a}; monitor-state.json ${b}; monitoring_r164.php ${c}`}});

await t('MO-10','A change to a security setting in the private config file is audited (setting names only, no values) and alerted',async()=>{
  const CFG='/srv/gate/sr-accountax-private/config.php';const orig=fs.readFileSync(CFG,'utf8');
  resetState();cron(); // baseline fingerprint
  const before=Number(sql("SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.security_settings_changed'"));
  const b=seen();let c;
  try{fs.writeFileSync(CFG,orig.replace(/'session_hours'\s*=>\s*\d+/,"'session_hours' => 11"));c=cron()}finally{fs.writeFileSync(CFG,orig)}
  const after=Number(sql("SELECT COUNT(*) FROM platform_audit_log WHERE action='platform.security_settings_changed'"));
  const meta=sql("SELECT metadata_json FROM platform_audit_log WHERE action='platform.security_settings_changed' ORDER BY created_at DESC LIMIT 1");
  const n=newAlerts(b);const txt=n.map(body).join('\n');
  const ok=after===before+1&&/"changedSettings":\["app.session_hours"\]/.test(meta)&&!/\b11\b/.test(meta)&&n.length===1&&/Changed settings: app.session_hours/.test(txt);
  return {ok,info:`sweep: ${c?.out.trim()}; audit rows +${after-before}; metadata ${meta.slice(0,160)}; emails ${n.length}`}});

fs.rmSync(OUT,{recursive:true,force:true});resetState();
save('monitor.json');
