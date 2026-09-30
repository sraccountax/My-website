import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const [,, W='1440', H='900', Z='1', theme='light', nav='side', mode='full', tag='x', user='owner@gate.test', pass='Gate!Owner#2026pw', setPrefs='0'] = process.argv;
const z=Number(Z),vw=Math.round(Number(W)/z),vh=Math.round(Number(H)/z),mobile=vw<800;
const browser=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
const ctx=await browser.newContext({viewport:{width:vw,height:vh},deviceScaleFactor:z,isMobile:mobile,hasTouch:mobile,ignoreHTTPSErrors:true});const IDS=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));await ctx.addInitScript(id=>{try{if(!sessionStorage.getItem('gate-co')){localStorage.setItem('sr-accountax-company',id);localStorage.setItem('sr-accountax-companies',JSON.stringify([id]));sessionStorage.setItem('gate-co','1')}}catch{}},process.env.GATE_COMPANY?IDS[process.env.GATE_COMPANY]:IDS.BC);const page=await ctx.newPage();
await page.route(/clarity\.ms|challenges\.cloudflare|googleapis|gstatic|bing\.com/,r=>r.abort());
const errs=[];page.on('pageerror',e=>errs.push(e.message.slice(0,160)));page.on('dialog',d=>{errs.push('DIALOG '+d.message().slice(0,60));d.dismiss()});
await page.goto('https://gate.test/app.html');await page.fill('#sr-login-form input[name=email]',user);await page.fill('#sr-login-form input[name=password]',pass);await page.click('#sr-login-form button[type=submit]');
await page.waitForFunction(()=>window.TeghPortal&&document.querySelector('.sidebar,.topbar'),null,{timeout:40000});await page.waitForTimeout(2500);
const ids=JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));

if(setPrefs==='1'){await page.evaluate(()=>window.TeghPortal.openTeghPreferences());await page.waitForSelector('[data-tegh-preferences]');await page.waitForTimeout(500);
 await page.locator(`[data-tegh-preferences] input[name=theme][value=${theme}]`).check({force:true});await page.locator(`[data-tegh-preferences] input[name=navigationLayout][value=${nav}]`).check({force:true});
 await page.locator('[data-tegh-preferences] button[type=submit]').first().click();await page.waitForTimeout(1200);
 await page.evaluate(g=>window.TeghPortal.switchBookkeepingMode(g),mode==='guided'?'owner':'accountant');await page.waitForTimeout(1200);await browser.close();process.exit(0)}
const state=await page.evaluate(()=>({company:localStorage.getItem('sr-accountax-company'),theme:document.documentElement.dataset.teghTheme,company:document.querySelector('[data-company-name],.company-name,.srp-company-name')?.textContent?.trim()}));
const acts=await page.evaluate(()=>[...new Set([...document.querySelectorAll('[data-srp-action-id]')].map(b=>b.dataset.srpActionId+'|'+b.textContent.trim().replace(/\|/g,'')))]);
const out={tag,W,H,Z,vw,theme,nav,mode,state,screens:[]};
for(const entry of acts){const [id,label]=entry.split('|');errs.length=0;
 await page.evaluate(id=>TeghPortal.invokeMenuAction('',id),id).catch(e=>errs.push('invoke '+e.message.slice(0,80)));await page.waitForTimeout(1900);
 const m=await page.evaluate(()=>{const de=document.documentElement;const vis=el=>el&&el.offsetParent!==null;const h1=[...document.querySelectorAll('h1')].find(vis);
  const txt=document.querySelector('.srp-page,.stage,main')?.innerText||'';const bad=(txt.match(/\bundefined\b|\bNaN\b|\[object Object\]|Infinity/g)||[]).slice(0,3);
  // elements that stick out past the viewport horizontally (excluding scroll containers' children)
  const over=[...document.querySelectorAll('button,input,select,h1,h2,label,.srp-card')].filter(e=>{if(!vis(e))return false;const r=e.getBoundingClientRect();if(r.width===0)return false;let p=e.parentElement;while(p){const s=getComputedStyle(p);if(/(auto|scroll|hidden)/.test(s.overflowX)&&p!==document.body&&p!==de)return false;p=p.parentElement}return r.right>innerWidth+2||r.left<-2}).length;
  const xss=window.__xss||0;const toast=[...document.querySelectorAll('.srp-toast.error,[data-toast-type=error]')].map(t=>t.textContent.trim().slice(0,80));
  return {h1:h1?.textContent?.trim().slice(0,50)||'',sw:de.scrollWidth,cw:de.clientWidth,bad,over,xss,toast}});
 const issues=[];if(m.sw>m.cw+2)issues.push('page-hscroll '+m.sw+'>'+m.cw);if(m.over)issues.push(m.over+' elements off-screen');if(m.bad.length)issues.push('text:'+m.bad.join(','));if(!m.h1)issues.push('no heading');if(m.xss)issues.push('XSS EXECUTED');if(errs.length)issues.push('js:'+errs.join(';'));if(m.toast.length)issues.push('error-toast:'+m.toast.join(';'));
 const n=id.replace(/[^a-z0-9]+/gi,'_');out.screens.push({id,label,h1:m.h1,issues});
 if(issues.length||process.env.SHOTS)await page.screenshot({path:`/srv/gate/ev/shots/${tag}-${n}.png`});
}
fs.writeFileSync(`/srv/gate/ev/ui-${tag}.json`,JSON.stringify(out,null,1));
const bad=out.screens.filter(s=>s.issues.length);console.log(tag,'screens',out.screens.length,'with issues',bad.length,JSON.stringify(state));for(const s of bad)console.log('  ',s.id.padEnd(40),s.issues.join(' | ').slice(0,220));
await browser.close();
