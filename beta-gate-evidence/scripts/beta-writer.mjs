// Keeps writing to the gate (a new customer every 300 ms) for N seconds; counts the outcomes.
import {S} from './lib.mjs';
const secs=Number(process.argv[2]||40),o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
o.cid=(await o.call('auth/me',{company:false})).b.companies[0].id;const tally={ok:0,maintenance_backup:0,other:0};const end=Date.now()+secs*1000;let i=0;
while(Date.now()<end){const r=await o.call('customers',{method:'POST',json:{name:'Backup Writer '+Date.now()+'-'+(i++),province:'ON',country:'Canada',paymentTerms:30}}).catch(e=>({s:0,b:{}}));
  if(r.s===200||r.s===201)tally.ok++;else if(r.s===503&&r.b?.code==='maintenance_backup')tally.maintenance_backup++;else{tally.other++;tally['s'+r.s]=(r.b?.code||'')}
  await new Promise(r=>setTimeout(r,300))}
console.log(JSON.stringify(tally));
