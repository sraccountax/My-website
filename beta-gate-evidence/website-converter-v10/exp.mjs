import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const [,,file,acct='-']=process.argv;fs.mkdirSync('/srv/bk/dl',{recursive:true});
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server']});const p=await (await b.newContext({acceptDownloads:true})).newPage();const errs=[];p.on('pageerror',e=>errs.push(e.message));
await p.goto('http://127.0.0.1:8088/member/pro-converter.php');if(acct!=='-')await p.selectOption('#account-type',acct);await p.setInputFiles('#statement-files',file);await p.click('#extract-button');await p.waitForSelector('#statement-check:not([hidden])',{timeout:120000});
const out={};for(const f of ['xlsx','qbo','ofx','json','pdf','csv']){const btn=p.locator(`[data-export="${f}"]`);if(!await btn.count()){out[f]='no button';continue}if(!await btn.isVisible()){const d=btn.locator('xpath=ancestor::details');if(await d.count())await d.locator('summary').click()}
 const [dl]=await Promise.all([p.waitForEvent('download',{timeout:30000}),btn.click()]);const path=`/srv/bk/dl/${dl.suggestedFilename()}`;await dl.saveAs(path);out[f]=path}
console.log(JSON.stringify({out,errs}));await b.close();
