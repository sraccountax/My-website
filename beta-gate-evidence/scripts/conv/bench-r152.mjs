// Fast harness: runs the working-tree statement reader on fx152 fixtures and compares with expected.json.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const FX='/srv/gate/t/conv/fx152/';const exp=JSON.parse(fs.readFileSync(FX+'expected.json'));
const only=process.argv.slice(2).filter(x=>!x.startsWith('-'));const verbose=process.argv.includes('-v');
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server']});
const p=await b.newPage();const logs=[];p.on('pageerror',e=>logs.push(e.message));p.on('console',m=>{if(m.type()==='error')logs.push(m.text())});
await p.goto('http://127.0.0.1:8765/404.html');
await p.addScriptTag({url:'/assets/tegh-native-ocr-v5220.js'});
const out={};
for(const k of Object.keys(exp)){ if(only.length&&!only.includes(k))continue;
  const b64=fs.readFileSync(FX+k+'.pdf').toString('base64');
  const r=await p.evaluate(async({b64,k})=>{
    const bytes=Uint8Array.from(atob(b64),c=>c.charCodeAt(0));const file=new File([bytes],k+'.pdf',{type:'application/pdf'});
    const pd=await globalThis.TeghNativeOCR.extractStatementPages(file,{onPassword:()=>null,onProgress:()=>{}});
    const mod=await import('/assets/tegh-bank-converter-v5990.js?v='+Date.now());
    try{const parsed=mod.convertPages(pd.pages,{layout:'auto',dateOrder:'auto',accountType:''});
      const one=x=>({rows:x.rows.map(r=>({date:r.date,desc:String(r.description||'').slice(0,50),cents:Math.round(Number(r.amount)*100),manual:!!r.needsManualAmount})),opening:x.metadata?.openingBalance??null,closing:x.metadata?.closingBalance??null,check:x.layoutCheck?mod.balanceCheckText(x.layoutCheck):null,balanced:x.layoutCheck?.balanced??null,card:x.accountType||x.card||null,acct:x.accountLastFour||null});
      const statements=[];for(const st of parsed.statements||[])statements.push(one(mod.convertPages(pd.pages,{layout:'auto',dateOrder:'auto',accountType:'',section:String(st.index)})));
      return {ok:true,main:one(parsed),statements,list:parsed.statements||null,section:parsed.section||null,acct:parsed.accountLastFour||null};
    }catch(e){return {ok:false,error:e.message}}
  },{b64,k});
  out[k]=r;
}
await b.close();
const cents=v=>v==null?null:Math.round(Number(v)*100);
let pass=0,total=0;
for(const [k,r] of Object.entries(out)){total++;const e=exp[k];
  if(!r.ok){console.log(k,'ERROR',r.error);continue}
  const list=e.statements?e.statements:[e];const got=e.statements?(r.statements.length?r.statements:[r.main]):[r.main];
  const probs=[];
  if(got.length!==list.length)probs.push(`statements ${got.length}/${list.length}`);
  list.forEach((x,i)=>{const g=got[i];if(!g)return;const net=g.rows.reduce((s,y)=>s+(y.manual?0:y.cents),0);
    if(g.rows.length!==x.rows)probs.push(`#${i} rows ${g.rows.length}/${x.rows}`);
    if(net!==x.net)probs.push(`#${i} net ${net}/${x.net}`);
    if(cents(g.opening)!==x.opening)probs.push(`#${i} open ${cents(g.opening)}/${x.opening}`);
    if(cents(g.closing)!==x.closing)probs.push(`#${i} close ${cents(g.closing)}/${x.closing}`);
    if(g.rows[0]?.date!==x.first)probs.push(`#${i} first ${g.rows[0]?.date}/${x.first}`);
    if(g.rows.at(-1)?.date!==x.last)probs.push(`#${i} last ${g.rows.at(-1)?.date}/${x.last}`);
    for(const s of x.sample||[]){if(!g.rows.some(y=>y.date===s.date&&y.cents===s.cents&&y.desc.toLowerCase().includes(s.desc.toLowerCase().slice(0,12))))probs.push(`#${i} sample missing ${s.date} ${s.cents} ${s.desc}`)}
    if(g.balanced===false)probs.push(`#${i} unbalanced`);
  });
  if(!probs.length)pass++;
  console.log(k,probs.length?'FAIL':'PASS',probs.slice(0,8).join(' | '));
  if(verbose)console.log(JSON.stringify(r,null,0).slice(0,3000));
}
console.log(`${pass}/${total} layouts reconcile; page errors ${logs.length}`, logs.slice(0,3));
fs.writeFileSync('/srv/gate/t/conv152/last.json',JSON.stringify(out,null,1));
