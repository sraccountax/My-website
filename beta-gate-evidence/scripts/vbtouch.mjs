import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const IDS=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const W=+(process.argv[2]||390),H=+(process.argv[3]||844);const css=process.argv[4]||'';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:W,height:H},isMobile:W<800,hasTouch:W<800,deviceScaleFactor:2,ignoreHTTPSErrors:true});
await ctx.addInitScript(id=>{try{if(!sessionStorage.getItem('g')){localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]));sessionStorage.setItem('g','1')}}catch{}},IDS.BC);
const p=await ctx.newPage();await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2000);
if(css)await p.addStyleTag({content:css});
const cdp=await ctx.newCDPSession(p);
const swipe=async(x,y0,y1)=>{await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y:y0}]});for(let i=1;i<=12;i++){await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y0+(y1-y0)*i/12}]});await p.waitForTimeout(16)}await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await p.waitForTimeout(600)};
for(const id of ['receivables:customer-invoices','payables:vendor-invoices']){
 await p.evaluate(id=>TeghPortal.invokeMenuAction('',id),id);await p.waitForTimeout(2500);
 const lay=await p.evaluate(()=>{const l=document.querySelector('.srp-page .r29-invoice-layout');if(!l)return null;const s=getComputedStyle(l);return {oy:s.overflowY,ob:s.overscrollBehaviorY,sh:l.scrollHeight,ch:l.clientHeight,h:Math.round(l.getBoundingClientRect().height)}});
 const res={};res.chain=await p.evaluate(()=>{let n=document.querySelector('.srp-page .r29-invoice-layout');const o=[];while(n&&n!==document.body){const s=getComputedStyle(n);o.push((n.tagName+'.'+String(n.className).split(' ').slice(0,3).join('.')).slice(0,48)+` h=${Math.round(n.getBoundingClientRect().height)} sh=${n.scrollHeight} oy=${s.overflowY} disp=${s.display} flex=${s.flex} minH=${s.minHeight} maxH=${s.maxHeight}`);n=n.parentElement}return o});res.geom=await p.evaluate(()=>{const st=document.querySelector('.stage'),pg=document.querySelector('.srp-page'),f=document.querySelector('.srp-page form'),btn=[...document.querySelectorAll('.srp-page button')].find(b=>/Save|Post|Issue|Record/i.test(b.textContent)&&b.offsetParent);const r=btn?.getBoundingClientRect();return {stage:[st.scrollHeight,st.clientHeight],page:[pg.scrollHeight,pg.clientHeight,getComputedStyle(pg).overflowY],form:f?Math.round(f.getBoundingClientRect().bottom):null,saveBtn:btn?btn.textContent.trim().slice(0,20)+'@'+Math.round(r.top):null,vh:innerHeight}});
 await swipe(W/2,H*0.75,H*0.25);res.touch=await p.evaluate(()=>[...document.querySelectorAll('*')].filter(e=>e.scrollTop>0).map(e=>String(e.className).split(' ')[0]+':'+e.scrollTop).join(',')||'NOTHING SCROLLED');
 await p.evaluate(()=>{document.querySelectorAll('*').forEach(e=>{if(e.scrollTop)e.scrollTop=0})});
 await p.mouse.move(W/2,H*0.6);await p.mouse.wheel(0,500);await p.waitForTimeout(600);res.wheel=await p.evaluate(()=>[...document.querySelectorAll('*')].filter(e=>e.scrollTop>0).map(e=>String(e.className).split(' ')[0]+':'+e.scrollTop).join(',')||'NOTHING SCROLLED');
 await p.evaluate(()=>{document.querySelectorAll('*').forEach(e=>{if(e.scrollTop)e.scrollTop=0})});
 console.log(W+'x'+H,id.padEnd(30),'layout',JSON.stringify(lay),'stage/layout scrollTop after touch',JSON.stringify(res.touch),'after wheel',JSON.stringify(res.wheel),JSON.stringify(res.geom));
}
await b.close();
