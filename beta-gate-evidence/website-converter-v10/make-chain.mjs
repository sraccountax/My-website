// Synthetic consecutive statements of one made-up account (Jan, Feb, and a Mar that skips a month), plus a scanned copy of Jan.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const OUT='/srv/bk/fx';const fmt=c=>(c/100).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
const t=(x,y,s,o={})=>`<div style="position:absolute;left:${x}px;top:${y}px;font:${o.b?'bold ':''}${o.size||9}px Arial;white-space:nowrap${o.r?';transform:translateX(-100%)':''}">${s.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
const page=b=>`<div style="position:relative;width:816px;height:1056px;overflow:hidden;background:#fff">${b}</div>`;
const html=b=>`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:816px 1056px;margin:0}body{margin:0}</style></head><body>${page(b)}</body></html>`;
const exp={};
function stmt(name,mon,last,open,tx){const p=[t(60,40,'MAPLE RIDGE SAVINGS (fictional test bank)',{b:1,size:12}),t(60,60,`For ${mon} 1 to ${mon} ${last}, 2026`),t(60,76,'Account Number: 00-1234-5678'),t(60,110,'Date',{b:1}),t(130,110,'Description',{b:1}),t(420,110,'Withdrawals ($)',{b:1}),t(520,110,'Deposits ($)',{b:1}),t(610,110,'Balance ($)',{b:1})];
  let y=128,bal=open;p.push(t(60,y,`${mon} 1`),t(130,y,'Opening balance'),t(660,y,fmt(open),{r:1}));y+=16;
  for(const [d,desc,c] of tx){bal+=c;p.push(t(60,y,`${mon} ${d}`),t(130,y,desc),c<0?t(500,y,fmt(-c),{r:1}):t(590,y,fmt(c),{r:1}),t(660,y,fmt(bal),{r:1}));y+=16}
  p.push(t(60,y+6,'Closing balance'),t(660,y+6,fmt(bal),{r:1}));fs.writeFileSync(`${OUT}/${name}.html`,html(p.join('')));exp[name]={rows:tx.length,open,close:bal};return bal}
const jan=stmt('G-jan','Jan',31,250000,[[3,'DEPOSIT CLIENT A',120000],[9,'RENT FEBRUARY',-90000],[20,'SERVICE CHARGE',-1500]]);
const feb=stmt('H-feb','Feb',28,jan,[[2,'E-TRANSFER CLIENT B',45000],[14,'HYDRO NORTH',-12345]]);
stmt('I-apr','Apr',30,feb+5000,[[5,'INSURANCE',-20000]]);   // March is missing: opening differs from February's closing
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const p=await b.newPage({viewport:{width:816,height:1056}});
for(const k of ['G-jan','H-feb','I-apr']){await p.goto('file://'+OUT+'/'+k+'.html');await p.pdf({path:`${OUT}/${k}.pdf`,width:'816px',height:'1056px'})}
const q=await b.newPage({viewport:{width:816,height:1056},deviceScaleFactor:2.2});await q.goto('file://'+OUT+'/G-jan.html');const png=await q.screenshot();
await q.setContent(`<!doctype html><body style="margin:0"><img src="data:image/png;base64,${png.toString('base64')}" style="width:816px"></body>`);await q.pdf({path:`${OUT}/G-jan-scanned.pdf`,width:'816px',height:'1056px'});
await b.close();fs.writeFileSync(`${OUT}/expected.json`,JSON.stringify(exp));console.log(JSON.stringify(exp));
