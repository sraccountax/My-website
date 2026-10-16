import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const IDS=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const W=390,H=844;
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:W,height:H},isMobile:true,hasTouch:true,deviceScaleFactor:2,ignoreHTTPSErrors:true});
await ctx.addInitScript(id=>{try{if(!sessionStorage.getItem('g')){localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]));sessionStorage.setItem('g','1')}}catch{}},IDS.BC);
const p=await ctx.newPage();await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2000);
await p.evaluate(()=>TeghPortal.invokeMenuAction('','payables:vendor-invoices'));await p.waitForTimeout(2500);
await p.screenshot({path:'/srv/gate/ev/r140-vendor-invoice-phone-top.png'});
const cdp=await ctx.newCDPSession(p);
for(let k=0;k<5;k++){await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:200,y:700}]});for(let i=1;i<=12;i++){await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:200,y:700-500*i/12}]});await p.waitForTimeout(16)}await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await p.waitForTimeout(500)}
await p.screenshot({path:'/srv/gate/ev/r140-vendor-invoice-phone-bottom.png'});console.log(await p.evaluate(()=>document.querySelector('.stage').scrollTop+'/'+(document.querySelector('.stage').scrollHeight-document.querySelector('.stage').clientHeight)));await b.close();
