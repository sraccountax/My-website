import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
const [,,url,file,out,w,h]=process.argv;const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-proxy-server']});
const p=await b.newPage({viewport:{width:+w,height:+h}});await p.goto(url);await p.setInputFiles('#statement-files',file.split(','));await p.click('#extract-button');await p.waitForSelector('#statement-check:not([hidden])',{timeout:120000});await p.waitForTimeout(1200);
await p.locator('#statement-check').scrollIntoViewIfNeeded();await p.evaluate(()=>window.scrollBy(0,-80));await p.screenshot({path:out});await b.close();
