// Startup and navigation timing probe. Usage: node probe.mjs <label> [companyId]
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
import {sql} from '../lib.mjs';
const label=process.argv[2]||'run';
const CO=process.argv[3]||sql(`SELECT c.id FROM companies c JOIN journal_entries j ON j.company_id=c.id GROUP BY c.id ORDER BY COUNT(*) DESC LIMIT 1`);
const THROTTLE=process.env.NOTHROTTLE?null:{offline:false,latency:60,downloadThroughput:12.5e6/8*8/8*8/8*8/8,uploadThroughput:5e6/8};
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true});
const p=await ctx.newPage();
const cdp=await ctx.newCDPSession(p);await cdp.send('Network.enable');
if(THROTTLE)await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:60,downloadThroughput:20e6/8,uploadThroughput:5e6/8});
let log=[];const sizes=new Map();
cdp.on('Network.responseReceived',e=>sizes.set(e.requestId,{method:methods.get(e.requestId),url:e.response.url,status:e.response.status,fromCache:e.response.fromDiskCache||e.response.fromMemoryCache,type:e.type,timing:e.response.timing}));
cdp.on('Network.loadingFinished',e=>{const s=sizes.get(e.requestId);if(s)log.push({...s,bytes:e.encodedDataLength})});
cdp.on('Network.requestServedFromCache',e=>{});
const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,200)));p.on('dialog',d=>d.accept());
await cdp.send('Performance.enable');let lastM={};const metrics=async()=>{const m=Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(x=>[x.name,x.value]));const d={script:Math.round(((m.ScriptDuration||0)-(lastM.ScriptDuration||0))*1000),layout:Math.round(((m.LayoutDuration||0)-(lastM.LayoutDuration||0))*1000),style:Math.round(((m.RecalcStyleDuration||0)-(lastM.RecalcStyleDuration||0))*1000),task:Math.round(((m.TaskDuration||0)-(lastM.TaskDuration||0))*1000)};lastM=m;return d};
const out={label,company:CO,phases:{}};
async function take(name,ms){const cpu=await metrics();const l=log;log=[];const net=l.filter(x=>!x.url.startsWith('data:'));
 const by={};for(const x of net){const k=x.url.includes('/api/')?'api':(x.type||'other');by[k]=by[k]||{n:0,bytes:0};by[k].n++;by[k].bytes+=x.bytes}
 const tt=x=>x.timing?Math.round(x.timing.receiveHeadersEnd-x.timing.sendEnd):null;
 out.phases[name]={ms,cpu:null,apiT:net.filter(x=>x.url.includes('/api/')).map(x=>[x.url.replace(/.*route=/,'').replace(/&v=\d+/,'').slice(0,60),tt(x),x.bytes]),requests:net.length,bytes:net.reduce((a,x)=>a+x.bytes,0),by,top:net.sort((a,b)=>b.bytes-a.bytes).slice(0,12).map(x=>[x.url.replace('https://gate.test','').slice(0,90),x.bytes,x.fromCache?'cache':''] ),api:net.filter(x=>x.url.includes('/api/')).map(x=>x.method+' '+x.url.replace('https://gate.test/api/index.php?route=','').slice(0,60))};
 out.phases[name].cpu=cpu;console.log(name.padEnd(60),String(ms).padStart(6)+'ms',String(net.length).padStart(4)+' req',String(Math.round(out.phases[name].bytes/1024)).padStart(7)+' KB','cpu',JSON.stringify(cpu));}
const methods=new Map();let inflight=0;cdp.on('Network.requestWillBeSent',e=>{inflight++;methods.set(e.requestId,e.request.method)});cdp.on('Network.loadingFinished',()=>inflight--);cdp.on('Network.loadingFailed',()=>inflight--);
const BUSY='[aria-busy=true],.srp-table-skeleton,.srp-report-loading,.srp-kpi-skeleton,.tegh-qa-loading';
async function settle(){let quiet=0;const t0=Date.now();while(Date.now()-t0<90000){const busy=await p.evaluate(B=>!!document.querySelector(B),BUSY).catch(()=>true);if(inflight<=0&&!busy){quiet+=25;if(quiet>=300)return}else quiet=0;await new Promise(r=>setTimeout(r,25))}}
const ready=async()=>{await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:90000});await settle()};
let t=Date.now();await p.goto('https://gate.test/app.html',{waitUntil:'load'});await p.waitForSelector('#sr-login-form input[name=email]',{state:'visible',timeout:60000});await take('1 sign-in page (cold)',Date.now()-t);
await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');
t=Date.now();await p.click('#sr-login-form button[type=submit]');await ready();await take('2 sign-in to dashboard',Date.now()-t-300);
await p.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},CO);
t=Date.now();await p.goto('https://gate.test/app.html',{waitUntil:'load'});await ready();await take('3 reload (warm cache)',Date.now()-t-300);
const nav=[['Receivables','Customer Invoice & Note Register'],['Receivables','Customers'],['Receivables','Customer Invoices'],['Receivables','Receivable Ageing'],['Payables','Vendor Invoice & Note Register'],['Payables','Vendors'],['Banking','Match And Post Transactions'],['Banking','Bank Transaction Report'],['General ledger','Journal Entries'],['General ledger','Day Book'],['Reports','Trial Balance'],['Reports','Profit and Loss'],['Reports','Balance Sheet'],['Reports','General Ledger Detail'],['Reports','Report Builder'],['My account','Company Details']];
const menus=await p.evaluate(()=>{try{return TeghPortal.menuNames?TeghPortal.menuNames():null}catch{return null}});
for(const [m,a] of nav){t=Date.now();const ok=await p.evaluate(([m,a])=>{try{TeghPortal.invokeMenuAction(m,a);return true}catch(e){return String(e).slice(0,80)}},[m,a]);
 await new Promise(r=>setTimeout(r,30));await settle();const h=await p.evaluate(()=>(document.querySelector('.srp-page:last-of-type h1,.srp-page:last-of-type h2,main h1')?.textContent||'').trim().slice(0,30));
 await take(('nav '+a).slice(0,34)+(ok===true?'':' !'+ok)+' ['+h+']',Date.now()-t-300);}
const perf=await p.evaluate(()=>({heapMB:Math.round((performance.memory?.usedJSHeapSize||0)/1048576),dom:document.querySelectorAll('*').length}));
out.perf=perf;out.errs=errs;console.log(perf,errs.slice(0,3));
fs.writeFileSync(`/srv/gate/t/perf/${label}.json`,JSON.stringify(out,null,1));await b.close();
