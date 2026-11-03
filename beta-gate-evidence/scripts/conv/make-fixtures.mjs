// R145 synthetic statements. Fictional banks, people and amounts that copy only the *layout* of three common
// Canadian statement styles (date/description/withdrawals/deposits/balance; card transaction+posting date with
// signed amounts; chequing with date and balance on the right and page totals). Expected figures are computed
// here with plain integer-cent arithmetic, independently of the converter under test.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const OUT='/srv/gate/t/conv/fx';const fmt=c=>(c/100).toLocaleString('en-CA',{minimumFractionDigits:2,maximumFractionDigits:2});
const t=(x,y,s,o={})=>`<div style="position:absolute;left:${x}px;top:${y}px;font:${o.b?'bold ':''}${o.size||9}px Arial;white-space:nowrap${o.r?';transform:translateX(-100%)':''}">${s.replace(/&/g,'&amp;').replace(/</g,'&lt;')}</div>`;
const page=body=>`<div style="position:relative;width:816px;height:1056px;page-break-after:always;overflow:hidden">${body}</div>`;
const html=pages=>`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:816px 1056px;margin:0}body{margin:0}</style></head><body>${pages.join('')}</body></html>`;
const expected={};

// ---- A: date | description | withdrawals | deposits | balance, wrapped descriptions, date only on a day's first row,
// balance only on a day's last row, side panel with amounts outside the table, two pages.
{
  const open=512345;const days=[
    ['Jan 2',[['E-TRANSFER 105889','Riverbend Bakery Ltd','',-12500],['CREDIT MEMO 4471 MC','NORTHWIND CARD SETTLE',null,48211]]],
    ['Jan 5',[['PREAUTHORIZED DEBIT','ACME PAYROLL SERVICES','',-215000],['SERVICE CHARGE','',null,-1295]]],
    ['Jan 9',[['DEPOSIT','BRANCH 0442',null,150000],['CHEQUE 118','',null,-38050],['POINT OF SALE PURCHASE','LAKESIDE HARDWARE #12','OAKFIELD ON',-8977]]],
    ['Jan 16',[['E-TRANSFER 105902','Pinecrest Dental',null,99500]]],
    ['Jan 23',[['INTERNET BILL PAY','HYDRO NORTH','',-24410],['CREDIT MEMO 4490 VISA','NORTHWIND CARD SETTLE',null,61234]]],
    ['Jan 30',[['MONTHLY FEE','',null,-2500],['E-TRANSFER 105977','Harbour Freight Co',null,-45000]]]];
  let bal=open,rows=[],pages=[[],[]],out=0,inn=0,n=0;
  const head=(p,y)=>{p.push(t(60,y,'Date',{b:1}),t(130,y,'Description',{b:1}),t(420,y,'Withdrawals ($)',{b:1}),t(520,y,'Deposits ($)',{b:1}),t(610,y,'Balance ($)',{b:1}))};
  pages.forEach((p,i)=>{p.push(t(60,40,'MAPLE RIDGE SAVINGS (fictional test bank)',{b:1,size:12}),t(60,60,'Account Statement',{size:11}),t(60,76,'For Jan 1 to Jan 31, 2026'),t(60,92,'Business Operating Account 00-1234-5678'),t(640,40,`Page ${i+1} of 2`));
    p.push(t(690,140,'Need help?'),t(690,152,'Call 1-800-555-0100'),t(690,164,'Overdraft limit'),t(690,176,'5,000.00'));head(p,200)});
  pages[0].push(t(60,110,'Opening balance on Jan 1, 2026'),t(300,110,`$${fmt(open)}`));
  let y=220,pi=0;pages[0].push(t(60,y,'Jan 1'),t(130,y,'Opening balance'),t(660,y,fmt(open),{r:1}));y+=14;
  days.forEach(([d,list],di)=>{if(di===4){pi=1;y=220}
    list.forEach((row,ri)=>{const [l1,l2,l3,amt]=row;const p=pages[pi];if(ri===0)p.push(t(60,y,d));p.push(t(130,y,l1));
      p.push(amt<0?t(500,y,fmt(-amt),{r:1}):t(590,y,fmt(amt),{r:1}));bal+=amt;n++;amt<0?out-=amt:inn+=amt;
      if(ri===list.length-1)p.push(t(660,y,fmt(bal),{r:1}));y+=12;
      if(l2){p.push(t(130,y,l2));y+=12}if(l3){p.push(t(130,y,l3));y+=12}y+=4})});
  pages[1].push(t(60,y+10,'Closing balance'),t(660,y+10,`$${fmt(bal)}`,{r:1}));
  pages[0].push(t(60,124,`Closing balance on Jan 31, 2026 = $${fmt(bal)}`));
  fs.writeFileSync(`${OUT}/A.html`,html(pages.map(p=>page(p.join('')))));
  expected.A={rows:n,netCents:bal-open,outCents:out,inCents:inn,openingCents:open,closingCents:bal,first:'2026-01-02',last:'2026-01-30',balanced:true,card:false,wrapped:days.flatMap(d=>d[1]).filter(r=>r[1]).length};
  // D: same statement with one line missing from page 1 (the 'DEPOSIT' row is dropped) — must NOT balance.
  const dropped=pages.map(p=>p.filter(s=>!/>DEPOSIT<|>BRANCH 0442<|>1,500.00</.test(s)));
  fs.writeFileSync(`${OUT}/D.html`,html(dropped.map(p=>page(p.join('')))));
  expected.D={rows:n-1,balanced:false,mismatchAtLeast:1};
}

// ---- B: card statement, transaction + posting date, signed amounts (payments negative), Dec→Jan year change,
// Sub-total / NEW BALANCE / Continued, then a payment slip with two amounts under the amount column.
{
  const prev=245000;const tx=[['DEC 8','DEC 9','PAYMENT - THANK YOU',-50000],['DEC 11','DEC 12','GREENLEAF GROCERY #22 OAKFIELD',7420],['DEC 17','DEC 18','SUMMIT UNIFORM RENTAL',20188],
    ['DEC 20','DEC 22','PAYMENT - THANK YOU',-50000],['DEC 27','DEC 29','HARBOUR WHOLESALE CLUB',47887],['DEC 27','DEC 29','HARBOUR WHOLESALE CLUB',-1099],
    ['DEC 30','JAN 2','FIELDSTONE FUEL 0091',8323],['JAN 3','JAN 4','PAYMENT - THANK YOU',-50000],['JAN 5','JAN 6','OVERLIMIT FEE',2900],['JAN 6','JAN 6','PURCHASE INTEREST 21.99%',4514]];
  const pages=[[],[]];let n=0,charges=0,credits=0;
  const head=(p,y)=>{p.push(t(50,y-12,'TRANSACTION',{b:1}),t(110,y-12,'POSTING',{b:1}),t(50,y,'DATE',{b:1}),t(110,y,'DATE',{b:1}),t(170,y,'ACTIVITY DESCRIPTION',{b:1}),t(420,y,'AMOUNT($)',{b:1}))};
  pages.forEach((p,i)=>{p.push(t(50,40,'NORTHSTAR BUSINESS TRAVEL CARD (fictional)',{b:1,size:12}),t(450,60,'STATEMENT DATE: January 6, 2026'),t(450,74,'PREVIOUS STATEMENT: December 5, 2025'),t(450,88,`Page ${i+1} of 2`));
    p.push(t(560,140,'Credit Limit'),t(700,140,'$10,000.00',{r:1}),t(560,154,'Available Credit'),t(700,154,'$6,123.45',{r:1}),t(560,168,'Minimum Payment'),t(700,168,'$85.00',{r:1}));head(p,200)});
  let y=216;pages[0].push(t(170,y,'PREVIOUS STATEMENT BALANCE'),t(470,y,`$${fmt(prev)}`,{r:1}));y+=14;let pi=0,sub=0;
  tx.forEach((r,i)=>{if(i===7){pages[0].push(t(380,y+4,'Sub-total'),t(470,y+4,`$${fmt(sub)}`,{r:1}),t(380,y+18,'Continued'));
      pages[0].push(t(60,y+60,'NEW BALANCE'),t(150,y+60,'MINIMUM PAYMENT'),t(260,y+60,'PAYMENT DUE DATE'),t(60,y+74,'$0.00'),t(220,y+74,'$1,230.00'),t(380,y+74,'$85.00'),t(450,y+74,'$45.00'));pi=1;y=216}
    const [d1,d2,desc,amt]=r,p=pages[pi];p.push(t(50,y,d1),t(110,y,d2),t(170,y,desc),t(470,y,amt<0?`-$${fmt(-amt)}`:`$${fmt(amt)}`,{r:1}));y+=14;n++;if(amt>0)charges+=amt;else credits-=amt;if(amt>0)sub+=amt});
  const owed=prev+charges-credits;
  pages[1].push(t(170,y+6,'NEW BALANCE'),t(470,y+6,`$${fmt(owed)}`,{r:1}));
  fs.writeFileSync(`${OUT}/B.html`,html(pages.map(p=>page(p.join('')))));
  expected.B={rows:n,netCents:credits-charges,outCents:charges,inCents:credits,openingCents:-prev,closingCents:-owed,first:'2025-12-08',last:'2026-01-06',balanced:true,card:true,yearChange:['2025-12-30','2026-01-03']};
}

// ---- C: chequing with description first, money columns, then DATE and BALANCE on the right; BALANCE FORWARD on
// each page; balance only on a day's last row; per-page "Debits n total" / "Credits n total"; footer note.
{
  const open=800000;const days=[['SEP02',[['STRIPE MSP',0,21997],['SEND E-TFR ***kQ2',5000,0]]],['SEP03',[['MC DEP 77120 MSP',0,4458],['VSA FEE 77120 MSP',603,0]]],
    ['SEP08',[['GC 0912-DEPOSIT',0,160000],['MONTHLY PLAN FEE',7200,0],['CHQ#00118-4410021',38050,0]]],['SEP15',[['E-TRANSFER ***pL9',0,1000],['SERVICE CHARGE',1250,0]]]];
  const pages=[[],[]];let bal=open,n=0,out=0,inn=0;const per=[{d:[0,0],c:[0,0]},{d:[0,0],c:[0,0]}];
  pages.forEach((p,i)=>{p.push(t(60,40,'Statement of Account',{b:1}),t(300,40,'Account Type'),t(500,40,'Statement From - To'),t(300,54,'BUSINESS CHEQUING ACCOUNT - CAD'),t(500,54,'SEP 1/25 - SEP 30/25'),t(560,70,`Page ${i+1} of 2`),t(60,70,'BIRCHWOOD TRUST (fictional test bank)'));
    p.push(t(130,110,'DESCRIPTION',{b:1}),t(280,110,'CHEQUE/DEBIT',{b:1}),t(380,110,'DEPOSIT/CREDIT',{b:1}),t(500,110,'DATE',{b:1}),t(570,110,'BALANCE',{b:1}))});
  let y=126,pi=0;pages[0].push(t(80,y,'BALANCE FORWARD'),t(500,y,'SEP01'),t(640,y,fmt(open),{r:1}));y+=12;
  days.forEach(([d,list],di)=>{if(di===2){pi=1;y=126;pages[1].push(t(80,y,'BALANCE FORWARD'),t(500,y,'SEP03'),t(640,y,fmt(bal),{r:1}));y+=12}
    list.forEach(([desc,dr,cr],ri)=>{const p=pages[pi];p.push(t(80,y,desc));if(dr){p.push(t(350,y,fmt(dr),{r:1}));out+=dr;bal-=dr;per[pi].d[0]++;per[pi].d[1]+=dr}if(cr){p.push(t(460,y,fmt(cr),{r:1}));inn+=cr;bal+=cr;per[pi].c[0]++;per[pi].c[1]+=cr}
      p.push(t(500,y,d));if(ri===list.length-1)p.push(t(640,y,fmt(bal),{r:1}));y+=12;n++})});
  pages.forEach((p,i)=>{p.push(t(80,860,'1 CHQ ENCLOSED NEXT STATEMENT DATE IS OCT 31/25'),t(80,874,'MONTHLY AVER. CR. BAL. $9,466.63'),t(500,874,`Credits`),t(560,874,String(per[i].c[0])),t(660,874,fmt(per[i].c[1]),{r:1}),t(500,888,'Debits'),t(560,888,String(per[i].d[0])),t(660,888,fmt(per[i].d[1]),{r:1}))});
  fs.writeFileSync(`${OUT}/C.html`,html(pages.map(p=>page(p.join('')))));
  expected.C={rows:n,netCents:bal-open,outCents:out,inCents:inn,openingCents:open,closingCents:bal,first:'2025-09-02',last:'2025-09-15',balanced:true,card:false,pageChecks:4};
}
// ---- E: ISO dates with the year, one signed Amount column and a Balance column; amounts like "(12.50)" and "45.00-".
{
  const open=100000;const tx=[['2026-03-02','COFFEE ROASTERS SUPPLY',-4512,'(45.12)'],['2026-03-04','CLIENT PAYMENT INV-1042',250000,'2,500.00'],['2026-03-09','PHONE COMPANY',-8800,'88.00-'],['2026-03-15','BANK FEE',-1250,'-12.50']];
  const p=[t(60,40,'CEDAR VALLEY CREDIT UNION (fictional test bank)',{b:1,size:12}),t(60,60,'Statement period Mar 1, 2026 to Mar 31, 2026'),t(60,100,'Date',{b:1}),t(160,100,'Description',{b:1}),t(420,100,'Amount',{b:1}),t(540,100,'Balance',{b:1})];
  let y=116,bal=open;p.push(t(160,y,'Opening balance'),t(590,y,fmt(open),{r:1}));y+=14;
  for(const [d,desc,c,shown] of tx){bal+=c;p.push(t(60,y,d),t(160,y,desc),t(470,y,shown,{r:1}),t(590,y,fmt(bal),{r:1}));y+=14}
  p.push(t(160,y+4,'Closing balance'),t(590,y+4,fmt(bal),{r:1}));
  fs.writeFileSync(`${OUT}/E.html`,html([page(p.join(''))]));
  expected.E={rows:tx.length,netCents:bal-open,openingCents:open,closingCents:bal,first:'2026-03-02',last:'2026-03-15',balanced:true,card:false};
}
// ---- F: no column headings at all (plain dated lines). The column reader must step aside and the general reader must still work.
{
  const tx=[['2026-04-01','RENT APRIL',-150000],['2026-04-03','DEPOSIT CLIENT',82000],['2026-04-10','INTERNET',-9900]];
  const p=[t(60,40,'Transactions',{b:1,size:12})];let y=80;for(const [d,desc,c] of tx){p.push(t(60,y,`${d}  ${desc}  ${c<0?'-':''}${fmt(Math.abs(c))}`));y+=14}
  fs.writeFileSync(`${OUT}/F.html`,html([page(p.join(''))]));
  expected.F={rows:tx.length,netCents:tx.reduce((a,r)=>a+r[2],0),first:'2026-04-01',last:'2026-04-10',columnReader:false};
}
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const p=await b.newPage();
for(const k of ['A','B','C','D','E','F']){await p.goto('file://'+OUT+'/'+k+'.html');await p.pdf({path:`${OUT}/${k}.pdf`,width:'816px',height:'1056px',printBackground:false})}
await b.close();fs.writeFileSync(`${OUT}/expected.json`,JSON.stringify(expected,null,1));console.log(JSON.stringify(expected));
