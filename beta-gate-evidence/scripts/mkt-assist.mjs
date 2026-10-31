// Asks Tegh Assist the example questions printed on ask-tegh.html and records what it does.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));const CID=ids.R144;
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const p=await (await b.newContext({viewport:{width:1440,height:900},ignoreHTTPSErrors:true})).newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,150)));
await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});
await p.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},CID);await p.goto('https://gate.test/app.html');await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(1500);
const qs=['Who owes me money?','How much did I spend on travel last quarter?','Anything unusual?','am I making money this year?','what bills are due this week?','upload my bank statement','Create a customer invoice.'];
for(const q of qs){await p.evaluate(()=>document.querySelectorAll('.tegh-assist-scrim').forEach(n=>n.remove()));await p.keyboard.press('Escape');await p.waitForTimeout(300);
 await p.locator('button').filter({hasText:/^Tegh Assist$/}).first().click();const inp=p.locator('.tegh-assist-drawer [data-command-free-text] input');await inp.waitFor({timeout:15000});await inp.fill(q);await inp.press('Enter');await p.waitForTimeout(3500);
 const res=await p.evaluate(()=>{const a=document.querySelector('[data-r144-answer]');const r=document.querySelector('.tegh-assist-drawer [data-command-result]');const drawerOpen=!!document.querySelector('.tegh-assist-drawer:not([hidden])');const h=[...document.querySelectorAll('.srp-page h1, main h1')].pop();return {answer:(a&&a.textContent||'').replace(/\s+/g,' ').slice(0,140),result:(r&&r.textContent||'').replace(/\s+/g,' ').slice(0,140),page:h?h.textContent.trim():'',drawerOpen}});
 console.log(JSON.stringify({q,...res}))}
console.log('errors',errs.length,errs.join(' | '));await b.close();
