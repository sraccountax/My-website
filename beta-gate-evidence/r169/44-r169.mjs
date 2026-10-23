// R169 (browser, phone 390 × 844): pinch-zoom works, and a zoomed screen can be moved left, right, up and down.
import {chromium} from '/opt/node22/lib/node_modules/playwright/index.mjs';
import {open} from './e2e/base.mjs';import {sql,rec,save} from './lib.mjs';
const A='R169';
const CID=sql("SELECT id FROM companies WHERE name LIKE 'R151 Documents Inc%' ORDER BY created_at DESC LIMIT 1");
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL',e.message.slice(0,300))}};
const kit=async p=>{const cdp=await p.context().newCDPSession(p);
  const touch=(type,pts)=>cdp.send('Input.dispatchTouchEvent',{type,touchPoints:pts.map(([x,y],id)=>({x,y,id}))});
  const pinch=async(out=true)=>{const a=out?[[180,420],[210,420]]:[[90,420],[300,420]];await touch('touchStart',a);for(let i=1;i<=8;i++){const d=out?15+i*8:105-i*11;await touch('touchMove',[[195-d,420],[195+d,420]]);await p.waitForTimeout(16)}await touch('touchEnd',[]);await p.waitForTimeout(700)};
  const drag=async(dx,dy,x=200,y=450)=>{await touch('touchStart',[[x,y]]);for(let i=1;i<=10;i++){await touch('touchMove',[[x+dx*i/10,y+dy*i/10]]);await p.waitForTimeout(16)}await touch('touchEnd',[]);await p.waitForTimeout(700)};
  const vv=()=>p.evaluate(()=>({s:+visualViewport.scale.toFixed(2),x:Math.round(visualViewport.offsetLeft),y:Math.round(visualViewport.offsetTop),stage:Math.round(document.querySelector('.stage')?.scrollTop||document.scrollingElement.scrollTop),hash:location.hash.replace(/&.*/,''),pageX:document.scrollingElement.scrollWidth-document.scrollingElement.clientWidth}));
  return {pinch,drag,vv}};
// Zoom in, move right, down and back left; then zoom out.
const journey=async(p,k)=>{const a=await k.vv();await k.pinch(true);const z=await k.vv();await k.drag(-120,0);const r=await k.vv();await k.drag(0,-220);const d=await k.vv();await k.drag(120,0);const l=await k.vv();await k.pinch(false);await k.pinch(false);const o=await k.vv();
  return {a,z,r,d,l,o,ok:z.s>=1.2&&r.x>z.x&&(d.stage>r.stage||d.y>r.y)&&l.x<r.x&&l.hash===a.hash&&o.s<z.s}};
const fmt=j=>`zoom ${j.z.s}; right x ${j.z.x}→${j.r.x}; down ${j.r.stage}/${j.r.y}→${j.d.stage}/${j.d.y}; left x→${j.l.x}; zoom out ${j.o.s}; screen kept ${j.l.hash===j.a.hash}`;

// Signed out: the sign-in page.
{const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1']});
 const ctx=await b.newContext({viewport:{width:390,height:844},ignoreHTTPSErrors:true,isMobile:true,hasTouch:true});const p=await ctx.newPage();await p.goto('https://gate.test/app.html');await p.waitForSelector('#sr-login-form');await p.waitForTimeout(1000);
 const k=await kit(p);await t('PZ-01','Sign-in page: pinch zooms in and the zoomed page moves sideways',async()=>{await k.pinch(true);const z=await k.vv();await k.drag(-120,0);const r=await k.vv();return {ok:z.s>=1.2&&r.x>z.x,info:`zoom ${z.s}; x ${z.x}→${r.x}`}});await b.close()}

const {b,p,errs}=await open(CID,{w:390,h:844});const k=await kit(p);
const go=async fn=>{await p.evaluate(fn);await p.waitForTimeout(3000)};
await t('PZ-02','Dashboard: zoom in, move right, down and left, zoom back out',async()=>{await go(()=>TeghPortal.openDashboard());const j=await journey(p,k);return {ok:j.ok,info:fmt(j)}});
await t('PZ-03','Report (Vendor Invoice & Note Register): zoom in and move around',async()=>{await go(()=>TeghPortal.openReport('Vendor Invoice & Note Register'));const j=await journey(p,k);return {ok:j.ok,info:fmt(j)}});
await t('PZ-04','Trial Balance: zoom in and move around',async()=>{await go(()=>TeghPortal.openReport('Trial Balance'));const j=await journey(p,k);return {ok:j.ok,info:fmt(j)}});
await t('PZ-05','Customer invoice form: zoom in and move around',async()=>{await go(()=>TeghPortal.openDocumentEditor('customer',{kind:'invoice'}));const j=await journey(p,k);return {ok:j.ok,info:fmt(j)}});
await t('PZ-06','Without zoom: one-finger scrolling still scrolls the screen and the page never scrolls sideways',async()=>{await go(()=>TeghPortal.openReport('Vendor Invoice & Note Register'));const a=await k.vv();await k.drag(0,-250);const d=await k.vv();await k.drag(-150,0);const s=await k.vv();return {ok:a.s===1&&d.stage>a.stage&&s.x===0&&s.pageX<=0&&s.hash===a.hash,info:`scale ${a.s}; scrolled ${a.stage}→${d.stage}; sideways x ${s.x}, page overflow ${s.pageX}; screen kept ${s.hash===a.hash}`}});
await t('PZ-07','No browser errors',async()=>({ok:errs.length===0,info:errs.join(' | ')||'none'}));
await b.close();save('r169.json');
