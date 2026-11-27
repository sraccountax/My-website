// R161 final functional test. Concurrency, then two large companies seeded through the API (expected figures summed
// independently by vol/seed.mjs), their reports for one company and both together, and Guided mode with a large queue.
import {rec,save} from './lib.mjs';import {execFileSync,spawn} from 'child_process';import fs from 'fs';
const A='R161';fs.rmSync('/srv/gate/ev/r161.json',{force:true});
const env={...process.env,NODE_EXTRA_CA_CERTS:'/srv/gate/pki/ca.crt'};
// C-01: simultaneous saves in one company (the R160 voucher-number deadlock).
try{const out=execFileSync('node',['vol/stress.mjs','16','160'],{encoding:'utf8',env,timeout:600000});const j=JSON.parse(out.trim().split('\n').pop());const ok=Object.entries(j.status).every(([k])=>/ 201$/.test(k))&&j.vouchers===160&&!j.duplicateSerials&&!j.duplicateNumbers&&!j.serialGaps&&!j.unbalanced;
  rec('C-01',A,'160 bills, invoices and expenses saved at the same moment in one company (16 at a time): every save succeeds, with no duplicate or skipped voucher number and every entry balanced',ok?'PASS':'FAIL',JSON.stringify(j))}catch(e){rec('C-01',A,'Simultaneous saves in one company','FAIL','error: '+String(e.message).slice(0,300))}
save('r161.json');
// Seed both companies in parallel.
for(const f of fs.readdirSync('vol'))if(/^expected-company_/.test(f))fs.rmSync('vol/'+f);
const seed=(...a)=>new Promise(res=>{const p=spawn('node',['seed.mjs',...a],{cwd:'vol',env});let out='';p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>out+=d);p.on('close',code=>res({code,out}))});
const [sa,sb]=await Promise.all([seed('Volume Northwind Ltd','400','150','5000','2000','400','3000'),seed('Volume Lakeshore Inc','150','60','1500','600','150','1000')]);
rec('S-01',A,'Seeding through the API: Northwind (400 customers, 150 vendors, 5,000 invoices, 2,000 bills, payments, 400 journals, 3,000 imported bank lines) and Lakeshore (150, 60, 1,500, 600, payments, 150, 1,000) complete without an error',sa.code===0&&sb.code===0?'PASS':'FAIL',(sa.out.trim().split('\n').pop()+' | '+sb.out.trim().split('\n').pop()).slice(0,600));
save('r161.json');
for(const s of ['check-vol.mjs','vol-ui.mjs']){try{process.stdout.write(execFileSync('node',[s],{cwd:'vol',encoding:'utf8',env,timeout:1500000}))}catch(e){rec('X-'+s,A,s+' ran to the end','FAIL',String(e.stdout||e.message).slice(-400));save('r161.json')}}
