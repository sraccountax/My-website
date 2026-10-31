// Compares a restored test installation with the site it was backed up from, through the app's own API:
// every company's trial balance and its uploaded Document Intake files. Read-only on both sites.
//   GATE_ORIGIN is not used; pass the two origins:  node compare-restore.mjs https://live.example https://restore.example email password
import crypto from 'crypto';
const [live,rest,email,password]=process.argv.slice(2);
const session=async origin=>{const c={};const call=async(route,{method='GET',json,company}={})=>{const h={Origin:origin,Cookie:Object.entries(c).map(([k,v])=>k+'='+v).join('; ')};if(company)h['X-Company-Id']=company;if(json)h['Content-Type']='application/json';if(c.csrf&&method!=='GET')h['X-CSRF-Token']=c.csrf;
  const r=await fetch(origin+'/api/'+route,{method,headers:h,body:json?JSON.stringify(json):undefined,redirect:'manual'});for(const s of r.headers.getSetCookie?.()||[]){const [kv]=s.split(';');const i=kv.indexOf('=');c[kv.slice(0,i)]=kv.slice(i+1)}return r};
  const r=await call('auth/login',{method:'POST',json:{email,password}});if(r.status!==200)throw new Error(origin+' sign-in '+r.status);c.csrf=(await r.json()).csrfToken;return call};
const L=await session(live),R=await session(rest);
const me=await (await L('auth/me')).json();const companies=(me.companies||[]).map(x=>x.id);
let same=0,diff=0,files=0,fileDiff=0,rowsSeen=0;
for(const id of companies){
  const tb=async call=>{const r=await call('professional-output/v5990/trial-balance&start=2026-01-01&end=2026-12-31',{company:id});const j=await r.json().catch(()=>({}));return r.status+' '+JSON.stringify((j.output?.rows||[]).map(x=>[x.accountCode||x.code,x.debitCents??x.closingDebitCents,x.creditCents??x.closingCreditCents]))+JSON.stringify(j.output?.totals||{})};
  const [a,b]=await Promise.all([tb(L),tb(R)]);if(!a.startsWith('200 ')){diff++;console.log('NOT LOADED',id,a.slice(0,80));continue}if(a===b){same++;rowsSeen+=(a.match(/\],\[/g)||[]).length+1}else{diff++;console.log('DIFF trial balance',id,a.slice(0,120),'|',b.slice(0,120))}
  const docs=await (await L('native-ap-ar/documents',{company:id})).json().catch(()=>({}));
  for(const d of (docs.documents||[]).slice(0,3)){const h=async call=>crypto.createHash('sha256').update(Buffer.from(await (await call(`native-ap-ar/document/file&documentId=${d.id}`,{company:id})).arrayBuffer())).digest('hex');
    const [x,y]=await Promise.all([h(L),h(R)]);if(x===crypto.createHash('sha256').update('').digest('hex')){fileDiff++;console.log('EMPTY file',d.id);continue}files++;if(x!==y){fileDiff++;console.log('DIFF file',d.id)}}
}
console.log(`companies ${companies.length}: trial balance loaded and identical ${same} (${rowsSeen} account rows), different or not loaded ${diff}; uploaded files compared ${files}, different ${fileDiff}`);
process.exit(diff||fileDiff?1:0);
