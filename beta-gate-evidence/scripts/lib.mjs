import {execFileSync} from 'child_process';
import crypto from 'crypto';
export const ORIGIN=process.env.GATE_ORIGIN||'https://gate.test'; const B=ORIGIN+'/api/';
// R164: the gate php-fpm runs under libfaketime; TOTP on the server uses that clock, so mirror it.
let _mfaOff=0; try{_mfaOff=Number(execFileSync('bash',['-lc',"grep -hE 'env\\[FAKETIME\\]' /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null | grep -oE '\\-?[0-9]+' | head -1"],{encoding:'utf8'}).trim()||'0')||0}catch{}
function _b32d(s){const al='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';s=String(s).toUpperCase().replace(/[^A-Z2-7]/g,'');let bits=0,val=0,out=[];for(const ch of s){val=(val<<5)|al.indexOf(ch);bits+=5;if(bits>=8){bits-=8;out.push((val>>bits)&0xff)}}return Buffer.from(out)}
const _mfaStep=()=>Math.floor((Date.now()/1000+_mfaOff)/30);
function _mfaTotp(secret,step=_mfaStep()){const key=_b32d(secret),buf=Buffer.alloc(8);buf.writeBigUInt64BE(BigInt(step));const hm=crypto.createHmac('sha1',key).update(buf).digest();const o=hm[hm.length-1]&0x0f;const bin=((hm[o]&0x7f)<<24)|((hm[o+1]&0xff)<<16)|((hm[o+2]&0xff)<<8)|(hm[o+3]&0xff);return String(bin%1000000).padStart(6,'0')}
async function _mfaWaitNextStep(){const s0=_mfaStep();while(_mfaStep()<=s0)await new Promise(r=>setTimeout(r,1000))}
const _sqlQuote=v=>"'"+String(v).replace(/'/g,"''")+"'";
// Cross-process lock (atomic mkdir) so concurrent logins of one account do not interleave enrolment.
async function _mfaLock(key){const dir='/tmp/tegh-mfa-lock-'+String(key).replace(/[^A-Za-z0-9_-]/g,'');const t0=Date.now();
 for(;;){try{fs.mkdirSync(dir);break}catch(e){if(e.code!=='EEXIST')throw e;try{if(Date.now()-fs.statSync(dir).mtimeMs>90000)fs.rmSync(dir,{recursive:true,force:true})}catch{}
  if(Date.now()-t0>180000)throw new Error('mfa lock timeout for '+key);await new Promise(r=>setTimeout(r,150))}}
 return()=>{try{fs.rmSync(dir,{recursive:true,force:true})}catch{}}}
// Trusted-device cookies per account, shared by every test process of one gate run (file reset by reset.sh).
const _TD='tegh_td',_TDF='/srv/gate/t/.td-cache.json';
const _tdCache=new Proxy({},{get:(_,k)=>{try{return JSON.parse(fs.readFileSync(_TDF,'utf8'))[k]}catch{return undefined}},
 set:(_,k,v)=>{let d={};try{d=JSON.parse(fs.readFileSync(_TDF,'utf8'))}catch{}d[k]=v;fs.writeFileSync(_TDF,JSON.stringify(d));return true},
 deleteProperty:(_,k)=>{let d={};try{d=JSON.parse(fs.readFileSync(_TDF,'utf8'))}catch{}delete d[k];fs.writeFileSync(_TDF,JSON.stringify(d));return true}});
export function forgetTrustedDevice(email){delete _tdCache[email]}
export class S{constructor(){this.c={};this.csrf='';this.cid=''}
 ck(){return Object.entries(this.c).map(([k,v])=>k+'='+v).join('; ')}
 async call(route,{method='GET',json,company=true,headers={},origin=ORIGIN,raw=false,body}={}){const h={'Cookie':this.ck(),...headers};if(origin)h.Origin=origin;if(company&&this.cid)h['X-Company-Id']=this.cid;if(json)h['Content-Type']='application/json';if(this.csrf&&method!=='GET'&&!('X-CSRF-Token' in headers))h['X-CSRF-Token']=this.csrf;
  const r=await fetch(B+route,{method,headers:h,body:json?JSON.stringify(json):body,redirect:'manual'});for(const s of r.headers.getSetCookie?.()||[]){const [kv]=s.split(';');const i=kv.indexOf('=');const k=kv.slice(0,i),v=kv.slice(i+1);if(v===''||/Max-Age=0|expires=Thu, 01 Jan 1970/i.test(s))delete this.c[k];else this.c[k]=v}
  if(raw)return r;const t=await r.text();let b;try{b=JSON.parse(t)}catch{b=t}
  return {s:r.status,b,h:r.headers}}
 async _enrolNow(password){
  const beg=await this.call('auth/mfa/enroll-begin',{method:'POST',json:{password},company:false});
  if(beg.s!==200)throw new Error('mfa enroll-begin '+beg.s+' '+JSON.stringify(beg.b));
  for(let attempt=0;attempt<2;attempt++){const cf=await this.call('auth/mfa/enroll-confirm',{method:'POST',json:{code:_mfaTotp(beg.b.secret)},company:false});
   if(cf.s===200){this.csrf=cf.b.csrfToken||this.csrf;return}if(cf.s===422&&attempt===0){await _mfaWaitNextStep();continue}throw new Error('mfa enroll-confirm '+cf.s)}}
 async login(email,password){
  // R164 (revised): every sign-in needs a second step, by default a 6-digit code emailed to the account. The harness
  // reads it from the test mailbox, as the person would, and asks the server to trust this client for 30 days so later
  // sign-ins of the same account in this process skip the code (the trusted-device cookie is cached per account).
  if(_tdCache[email])this.c[_TD]=_tdCache[email];
  const since=mails().length;
  const r=await this.call('auth/login',{method:'POST',json:{email,password},company:false});
  if(r.s!==200)throw new Error('login '+r.s+' '+JSON.stringify(r.b));
  this.csrf=r.b.csrfToken||this.csrf;
  if(!r.b.mfaRequired){if(this.c[_TD])_tdCache[email]=this.c[_TD];return r.b}
  const m=await waitMail(x=>x.t.includes(email)&&/sign-in code is \d{6}/.test(x.t),since);
  const code=m?.t.match(/sign-in code is (\d{6})/)?.[1];
  if(!code)throw new Error('login: no sign-in code email for '+email+' ('+JSON.stringify(r.b).slice(0,200)+')');
  const v=await this.call('auth/mfa/verify',{method:'POST',json:{method:'email',code,rememberDevice:true},company:false});
  if(v.s!==200)throw new Error('mfa verify '+v.s+' '+JSON.stringify(v.b));
  this.csrf=v.b.csrfToken||this.csrf;if(this.c[_TD])_tdCache[email]=this.c[_TD];
  // Test database only: drop this account's used codes so harness sign-ins never reach the 10-per-15-minutes limit.
  try{sql('DELETE o FROM user_email_otp o JOIN users u ON u.id=o.user_id WHERE u.email='+_sqlQuote(email)+' AND o.used_at IS NOT NULL')}catch{}
  return v.b
 }
}
export const sql=q=>execFileSync('mysql',[process.env.GATE_DB||'tegh_gate','-N','-B','-e',q],{encoding:'utf8'}).trim();
export const results=[];
export function rec(id,area,title,status,evidence){results.push({id,area,title,status,evidence:String(evidence??'').slice(0,600)});console.log(status.padEnd(8),id.padEnd(10),title.slice(0,90),'|',String(evidence??'').slice(0,200))}
export const eq=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
export function check(id,area,title,got,want){const p=eq(got,want);rec(id,area,title,p?'PASS':'FAIL',p?JSON.stringify(got):'got '+JSON.stringify(got)+' want '+JSON.stringify(want));return p}
export function need(label,r,codes=[200,201]){if(!codes.includes(r.s)){throw new Error(label+' '+r.s+' '+JSON.stringify(r.b).slice(0,500))}return r.b}
import fs from 'fs';
export function save(file){const p='/srv/gate/ev/'+file;let prev=[];try{prev=JSON.parse(fs.readFileSync(p,'utf8'))}catch{};const ids=new Set(results.map(r=>r.id));fs.writeFileSync(p,JSON.stringify([...prev.filter(r=>!ids.has(r.id)),...results],null,1))}
const dec=t=>t+'\n'+[...t.matchAll(/Content-Transfer-Encoding: base64\s*\n\s*\n([A-Za-z0-9+\/=\s]+?)(?:\n--|$)/g)].map(m=>Buffer.from(m[1].replace(/\s+/g,''),'base64').toString('utf8')).join('\n');
export const mails=()=>fs.readdirSync('/srv/gate/mail').sort().map(f=>({f,t:dec(fs.readFileSync('/srv/gate/mail/'+f,'utf8'))}));
// R164: some emails (password reset) are sent just after the response; wait for one to arrive (up to 15 s).
export async function waitMail(pred,since=0,ms=15000){const t0=Date.now();for(;;){const m=mails().slice(since).find(pred);if(m||Date.now()-t0>ms)return m;await new Promise(r=>setTimeout(r,250))}}
