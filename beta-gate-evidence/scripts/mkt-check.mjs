// Renders every public page at desktop and phone width; reports console/CSP errors, failed requests, horizontal overflow
// and links that return an error. Read-only.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const pages=['index.html','product.html','ask-tegh.html','solutions.html','subscriptions.html','resources.html','company.html','security.html','contact.html','migration.html','privacy.html','terms.html','404.html','guides/ar-aging.html','guides/balance-sheet.html','guides/bank-reconciliation.html','guides/cash-vs-accrual.html'];
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const out=[];const links=new Set();
for(const [w,h,tag] of [[1440,900,'desk'],[390,844,'phone']]){const ctx=await b.newContext({viewport:{width:w,height:h},ignoreHTTPSErrors:true,isMobile:w<800,hasTouch:w<800});
 for(const pg of pages){const p=await ctx.newPage();const errs=[];p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,140))});p.on('pageerror',e=>errs.push('js: '+e.message.slice(0,140)));p.on('requestfailed',r=>errs.push('failed: '+r.url().slice(0,100)));
  const r=await p.goto('https://gate.test/'+pg,{waitUntil:'load'});await p.waitForTimeout(1200);
  const m=await p.evaluate(()=>({sw:document.documentElement.scrollWidth,cw:document.documentElement.clientWidth,h1:(document.querySelector('h1')||{}).textContent||'',links:[...document.querySelectorAll('a[href]')].map(a=>a.getAttribute('href'))}));
  m.links.forEach(l=>{if(!/^(https?:|mailto:|tel:|#)/.test(l))links.add(new URL(l,'https://gate.test/'+pg).pathname)});
  if(tag==='phone'&&pg==='product.html')await p.screenshot({path:'/srv/gate/ev/shots/mkt-product-phone.png',fullPage:true});
  if(tag==='desk'&&pg==='product.html')await p.screenshot({path:'/srv/gate/ev/shots/mkt-product-desk.png',fullPage:true});
  out.push({pg,tag,status:r.status(),overflow:m.sw>m.cw+1?m.sw-m.cw:0,h1:m.h1.trim().slice(0,60),errs});await p.close()}
 await ctx.close()}
const bad=[];for(const l of links){const r=await fetch('https://gate.test'+l,{redirect:'manual'}).catch(e=>({status:'ERR'}));if(![200,301,302,304].includes(r.status))bad.push(`${l} ${r.status}`)}
await b.close();
for(const o of out)console.log(`${o.status===200||o.pg==='404.html'?'OK  ':'FAIL'} ${o.tag.padEnd(5)} ${o.pg.padEnd(32)} status ${o.status} overflow ${o.overflow}px errors ${o.errs.length}${o.errs.length?' '+o.errs.join(' | '):''}`);
console.log('internal links checked',links.size,'bad',bad.length,bad.join(', '));fs.writeFileSync('/srv/gate/ev/mkt-check.json',JSON.stringify({out,links:[...links],bad},null,1));
