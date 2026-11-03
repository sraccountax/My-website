import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const IDS=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const W=+(process.argv[2]||390),H=+(process.argv[3]||844);const css=process.argv[4]||'';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await b.newContext({viewport:{width:W,height:H},isMobile:W<800,hasTouch:W<800,ignoreHTTPSErrors:true});
await ctx.addInitScript(id=>{try{if(!sessionStorage.getItem('g')){localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]));sessionStorage.setItem('g','1')}}catch{}},IDS.BC);
const p=await ctx.newPage();await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(2000);if(css)await p.addStyleTag({content:css});
const acts=JSON.parse(fs.readFileSync('/srv/gate/ev/ui-light-390.json')).screens.map(s=>s.id);let n=0;
for(const id of acts){await p.evaluate(id=>TeghPortal.invokeMenuAction('',id),id);await p.waitForTimeout(1600);
 const traps=await p.evaluate(()=>{const out=[];for(const e of document.querySelectorAll('.srp-page *')){const s=getComputedStyle(e);if(!/(auto|scroll)/.test(s.overflowY))continue;if(!/contain|none/.test(s.overscrollBehaviorY))continue;if(e.scrollHeight>e.clientHeight+2)continue;const r=e.getBoundingClientRect();if(r.height<innerHeight*0.25||r.width<innerWidth*0.5)continue;out.push(String(e.className).split(' ').slice(0,2).join('.')+' '+Math.round(r.width)+'x'+Math.round(r.height))}return out});
 if(traps.length){n++;console.log(id.padEnd(40),traps.join(' | '))}}
console.log(W+'x'+H,'screens with swipe traps:',n);await b.close();
