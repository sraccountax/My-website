import {execFileSync} from 'child_process';
export const ORIGIN=process.env.GATE_ORIGIN||'https://gate.test'; const B=ORIGIN+'/api/';
export class S{constructor(){this.c={};this.csrf='';this.cid=''}
 ck(){return Object.entries(this.c).map(([k,v])=>k+'='+v).join('; ')}
 async call(route,{method='GET',json,company=true,headers={},origin=ORIGIN,raw=false,body}={}){const h={'Cookie':this.ck(),...headers};if(origin)h.Origin=origin;if(company&&this.cid)h['X-Company-Id']=this.cid;if(json)h['Content-Type']='application/json';if(this.csrf&&method!=='GET'&&!('X-CSRF-Token' in headers))h['X-CSRF-Token']=this.csrf;
  const r=await fetch(B+route,{method,headers:h,body:json?JSON.stringify(json):body,redirect:'manual'});for(const s of r.headers.getSetCookie?.()||[]){const [kv]=s.split(';');const i=kv.indexOf('=');const k=kv.slice(0,i),v=kv.slice(i+1);if(v===''||/Max-Age=0|expires=Thu, 01 Jan 1970/i.test(s))delete this.c[k];else this.c[k]=v}
  if(raw)return r;const t=await r.text();let b;try{b=JSON.parse(t)}catch{b=t}return {s:r.status,b,h:r.headers}}
 async login(email,password){const r=await this.call('auth/login',{method:'POST',json:{email,password},company:false});if(r.s!==200)throw new Error('login '+r.s+' '+JSON.stringify(r.b));this.csrf=r.b.csrfToken;return r.b}}
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
