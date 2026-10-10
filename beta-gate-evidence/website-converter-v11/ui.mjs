// Drives the converter page (free or pro) in Chromium with real PDFs; prints aggregates only.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
const [,,port,mode,acct,...files]=process.argv;
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server']});
const p=await (await b.newContext({viewport:{width:1440,height:900},acceptDownloads:true})).newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message.slice(0,150)));
await p.goto(`http://127.0.0.1:${port}/${mode==='pro'?'member/pro-converter.php':'free-converter.php'}`);
if(acct&&acct!=='-'&&await p.locator('#account-type').count())await p.selectOption('#account-type',acct);
if(process.env.OCR&&await p.locator('#use-ocr').count())await p.check('#use-ocr');await p.setInputFiles('#statement-files',files);await p.click('#extract-button');
await p.waitForFunction(()=>!document.getElementById('review-section').hidden||/error/.test(document.getElementById('converter-status').className),null,{timeout:180000}).catch(()=>{});
await p.waitForTimeout(800);
const r=await p.evaluate(()=>{const t=id=>document.getElementById(id)?.textContent.trim();const rows=[...document.querySelectorAll('#transactions-body tr')];
 const chk=document.getElementById('statement-check');return {status:t('converter-status'),included:t('included-count'),debits:t('total-debits'),credits:t('total-credits'),net:t('net-movement'),pageInfo:t('page-info'),low:rows.filter(r=>r.classList.contains('low-confidence')).length,
 check:chk&&!chk.hidden?{state:chk.dataset.state,items:[...chk.querySelectorAll('li')].map(li=>li.dataset.state+': '+li.querySelector('span').textContent.replace(/\$[\d,]+\.\d{2}/g,'$#'))}:null}});
console.log(JSON.stringify({...r,status:(r.status||'').slice(0,160),errors:errs},null,1));
if(process.env.SHOT)await p.screenshot({path:process.env.SHOT,fullPage:false});
await b.close();
