// Public pages in a dark-mode browser: device dark preference and Chrome's forced dark. Read-only.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
const tag=process.argv[2]||'after', shots=process.argv[3]||'/srv/gate/ev/shots';
const pages=['index.html','product.html','ask-tegh.html','solutions.html','subscriptions.html','resources.html','company.html','security.html','contact.html','migration.html','privacy.html','terms.html','404.html','guides/ar-aging.html','guides/balance-sheet.html','guides/bank-reconciliation.html','guides/cash-vs-accrual.html','app.html'];
const lum=c=>{const m=c.match(/[\d.]+/g).map(Number);return Math.round(0.2126*m[0]+0.7152*m[1]+0.0722*m[2])};
let bad=0;
for(const [mode,args] of [['device-dark',[]],['forced-dark',['--blink-settings=forceDarkModeEnabled=true']]]){
 const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server','--host-resolver-rules=MAP gate.test 127.0.0.1',...args]});
 const ctx=await b.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,ignoreHTTPSErrors:true,colorScheme:'dark'});
 for(const pg of pages){const p=await ctx.newPage();await p.goto('https://gate.test/'+pg,{waitUntil:'load'});await p.waitForTimeout(800);
  const bg=await p.evaluate(()=>getComputedStyle(document.body).backgroundColor);
  let pix=null;if(mode==='forced-dark'||pg==='index.html'){const buf=await p.screenshot({path:pg==='index.html'?`${shots}/dark-${tag}-${mode}-index.png`:undefined});
   // sample rendered page brightness (forced dark changes pixels, not computed styles)
   pix=await p.evaluate(async(b64)=>{const img=new Image();img.src='data:image/png;base64,'+b64;await img.decode();const c=document.createElement('canvas');c.width=img.width;c.height=img.height;const x=c.getContext('2d');x.drawImage(img,0,0);const d=x.getImageData(0,0,c.width,c.height).data;let s=0,n=0;for(let i=0;i<d.length;i+=4*97){s+=0.2126*d[i]+0.7152*d[i+1]+0.0722*d[i+2];n++}return Math.round(s/n)},buf.toString('base64'))}
  const light=pg==='app.html'?null:(lum(bg)>200&&(pix===null||pix>150));if(light===false)bad++;
  console.log(`${light===null?'INFO':light?'PASS':'FAIL'} ${mode.padEnd(12)} ${pg.padEnd(32)} body ${bg} avg-pixel ${pix??'-'}`);await p.close()}
 await b.close()}
console.log(`public pages not light: ${bad}`);
