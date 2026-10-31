// R166 (browser): the Top navigation bar uses the sidebar's colours, and dropdown menus are painted only where they
// end up (they used to flash off to the right, then jump into view).
import {open} from './e2e/base.mjs';import {sql,rec,save} from './lib.mjs';
const A='R166';
const CID=sql("SELECT id FROM companies WHERE name LIKE 'R151 Documents Inc%' ORDER BY created_at DESC LIMIT 1");
if(!CID)throw new Error('run 19-r151.mjs first');
const INV=sql(`SELECT id FROM invoices WHERE company_id='${CID}' AND status<>'draft' ORDER BY created_at LIMIT 1`);
const {b,p,errs}=await open(CID,{w:1280,h:800});
const shots='/srv/gate/ev/shots';
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL',e.message.slice(0,300))}};
const prefs=async nav=>{await p.evaluate(()=>window.TeghPortal.openTeghPreferences());await p.waitForSelector('[data-tegh-preferences]');await p.locator(`[data-tegh-preferences] input[name=navigationLayout][value=${nav}]`).check({force:true});await p.locator('[data-tegh-preferences] button[type=submit]').first().click();await p.waitForTimeout(1500)};
const lum=c=>{const m=c.match(/\d+(\.\d+)?/g).map(Number);const f=v=>{v/=255;return v<=.03928?v/12.92:((v+.055)/1.055)**2.4};return .2126*f(m[0])+.7152*f(m[1])+.0722*f(m[2])};
const ratio=(a,b)=>{const x=lum(a),y=lum(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)};
// Records where a menu panel is painted on each frame after the click; a panel that is visible in two places jumped.
const painted=async click=>{await p.evaluate(()=>{window.__tr=[];window.__on=true;const tick=()=>{if(!window.__on)return;document.querySelectorAll('details[open]>:not(summary),.tegh-overlay-surface,.r23-menu,.r19-menu-panel').forEach(e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);if(r.width&&s.visibility==='visible'&&Number(s.opacity)>0)window.__tr.push(Math.round(r.left)+','+Math.round(r.top))});requestAnimationFrame(tick)};requestAnimationFrame(tick)});await click();await p.waitForTimeout(700);const tr=await p.evaluate(()=>{window.__on=false;return window.__tr});await p.keyboard.press('Escape');await p.mouse.click(640,780);await p.waitForTimeout(300);return [...new Set(tr)]};

await t('TB-01','Top navigation: the bar is the sidebar green with light text (contrast at least 7:1)',async()=>{
  await prefs('top');await p.evaluate(()=>TeghPortal.openDashboard());await p.waitForTimeout(2000);
  const c=await p.evaluate(()=>{const bar=document.querySelector('.topbar'),btn=document.querySelector('.tegh-top-module>button'),side=getComputedStyle(document.querySelector('aside.sidebar'));return {img:getComputedStyle(bar).backgroundImage,text:getComputedStyle(btn).color,side:side.backgroundColor}});
  await p.evaluate(()=>document.querySelectorAll('.srp-toast').forEach(n=>n.remove()));await p.screenshot({path:shots+'/r166-topbar.png',clip:{x:0,y:0,width:1280,height:200}});
  const r=ratio(c.text,'rgb(13, 48, 40)');return {ok:/rgb\(13, 48, 40\)/.test(c.img)&&r>=7,info:`bar ${c.img.slice(0,90)} · text ${c.text} · contrast ${r.toFixed(1)}:1 · sidebar ${c.side}`}});
await t('TB-02','Top navigation: the current page is highlighted in the sidebar\'s active green',async()=>{
  const c=await p.evaluate(()=>getComputedStyle(document.querySelector('.tegh-top-menubar>button.active')).backgroundColor);return {ok:c==='rgb(0, 117, 88)',info:c}});
await t('DD-01','Top bar menu (Banking) is painted in one place',async()=>{const pos=await painted(()=>p.locator('.tegh-top-menubar button',{hasText:'Banking'}).first().click());return {ok:pos.length===1,info:pos.join(' | ')}});
await t('DD-02','Profile menu is painted in one place',async()=>{const pos=await painted(()=>p.locator('.r17-profile-menu summary').first().click());return {ok:pos.length===1,info:pos.join(' | ')}});
await t('DD-03','Invoice details › Document menu is painted in one place (it used to appear about 180 px to the right first)',async()=>{
  await p.evaluate(id=>TeghPortal.openInvoiceDetails(id),INV);await p.waitForSelector('.r19-menu summary',{timeout:20000});await p.waitForTimeout(500);
  const pos=await painted(()=>p.locator('.r19-menu summary').first().click());return {ok:pos.length===1,info:pos.join(' | ')}});
await t('DD-04','Customers › row menu is painted in one place',async()=>{
  await p.evaluate(()=>TeghPortal.openCustomers());await p.waitForTimeout(2500);
  const pos=await painted(()=>p.locator('.srp-page details summary:visible').first().click());return {ok:pos.length===1,info:pos.join(' | ')}});
await t('DD-05','Report row Actions menu is painted in one place',async()=>{
  await p.evaluate(()=>TeghPortal.openReport('Vendor Invoice & Note Register'));await p.waitForSelector('[data-r23-table] tbody tr',{timeout:25000});await p.waitForTimeout(500);
  const pos=await painted(()=>p.locator('[data-r23-table] tbody tr button:text-is("Actions")').last().click());return {ok:pos.length===1,info:pos.join(' | ')}});
await t('DD-06','Menus still open and work after the change (Create menu lists its items)',async()=>{
  await p.locator('.r17-create-menu summary').first().click();await p.waitForTimeout(400);const n=await p.locator('.r17-menu-panel button:visible, .r17-menu-panel a:visible').count();await p.keyboard.press('Escape');return {ok:n>0,info:n+' items'}});
await t('TB-03','Side navigation is unchanged (white header bar)',async()=>{
  await prefs('side');await p.evaluate(()=>TeghPortal.openDashboard());await p.waitForTimeout(2000);
  const bg=await p.evaluate(()=>getComputedStyle(document.querySelector('.topbar')).backgroundImage+' '+getComputedStyle(document.querySelector('.topbar')).backgroundColor);return {ok:!/13, 48, 40/.test(bg),info:bg}});
await t('TB-04','No browser errors during these checks',async()=>({ok:errs.length===0,info:errs.join(' | ')||'none'}));
await b.close();save('r166.json');
