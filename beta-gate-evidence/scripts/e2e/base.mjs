import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
export const ids=()=>JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
export async function open(cid,{w=1440,h=900}={}){
 const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
 const ctx=await b.newContext({viewport:{width:w,height:h},ignoreHTTPSErrors:true,acceptDownloads:true,isMobile:w<800,hasTouch:w<800});// When the gate host's clock is pinned (clock-pin.sh), the browser's clock is shifted by the same whole days so dates
 // the screens fill in by default (payment date, period end) match the server. Time keeps running normally.
 const shift=Number(process.env.GATE_CLOCK_SHIFT_SECONDS||0)*1000||Number(process.env.GATE_CLOCK_SHIFT_DAYS||0)*86400000;if(shift>0){await ctx.clock.install({time:Date.now()-shift});await ctx.clock.resume();}
 const p=await ctx.newPage();
 const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,200)));p.on('dialog',d=>d.accept());
 await p.goto('https://gate.test/app.html');await p.fill('#sr-login-form input[name=email]','owner@gate.test');await p.fill('#sr-login-form input[name=password]','Gate!Owner#2026pw');await p.click('#sr-login-form button[type=submit]');
 await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});
 await p.evaluate(id=>{localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]))},cid);await p.goto('https://gate.test/app.html');await p.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await p.waitForTimeout(1200);
 return {b,p,errs};
}
export const menu=async(p,m,a,wait=2500)=>{await p.evaluate(([m,a])=>TeghPortal.invokeMenuAction(m,a),[m,a]);await p.waitForTimeout(wait)};
export const pageEl=p=>p.locator('.srp-page').last();
export const toast=async p=>p.evaluate(()=>[...document.querySelectorAll('.srp-toast,.toast,[role=alert],[role=status]')].map(x=>x.textContent.trim()).filter(Boolean).slice(-3).join(' | '));
export const selectByText=async(loc,text)=>{const v=await loc.evaluate((s,t)=>[...s.options].find(o=>o.text.includes(t))?.value,text);if(v==null)throw new Error('option not found: '+text);await loc.selectOption(v)};
// Tegh's in-app confirmation dialog ("Confirm action" → Continue/Confirm/Post...).
export const confirm=async(p,timeout=4000)=>{const end=Date.now()+timeout;while(Date.now()<end){const btn=p.locator('dialog[open] button, [role=dialog] button, .srp-modal button').filter({hasText:/^(Continue|Confirm|Yes|Post|Issue|Record|OK)\b/});if(await btn.count()){await btn.last().click();await p.waitForTimeout(1500);return true}await p.waitForTimeout(250)}return false};
