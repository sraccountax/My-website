// R152 synthetic vendor invoices (fictional suppliers, amounts and numbers). Expected figures use plain integer-cent
// arithmetic here, independently of Tegh's reader: line = round(qty × unit), tax = round(subtotal × rate).
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const OUT='/srv/gate/t/conv/ix152';fs.mkdirSync(OUT,{recursive:true});
const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;');
const t=(x,y,s,o={})=>`<div style="position:absolute;left:${x}px;top:${y}px;font:${o.b?'bold ':''}${o.size||10}px Arial;white-space:nowrap${o.r?';transform:translateX(-100%)':''}">${esc(s)}</div>`;
const page=body=>`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:816px 1056px;margin:0}body{margin:0}</style></head><body><div style="position:relative;width:816px;height:1056px;overflow:hidden">${body}</div></body></html>`;
const en=c=>`${c<0?'-':''}${Math.floor(Math.abs(c)/100).toLocaleString('en-CA')}.${String(Math.abs(c)%100).padStart(2,'0')}`;
const fr=c=>`${Math.floor(c/100).toLocaleString('en-CA').replace(/,/g,' ')},${String(c%100).padStart(2,'0')} $`;
const rnd=(n,d)=>Math.floor((2*n+d)/(2*d)); // round half up for positive integers
const expected={};
function invoice(key,o){
  const m=o.lang==='fr'?fr:en,b=[];
  b.push(t(60,50,o.vendor,{b:1,size:16}),t(60,72,o.address),t(60,86,o.email),t(600,50,o.lang==='fr'?'FACTURE':'INVOICE',{b:1,size:18}));
  let y=120;for(const [l,v] of o.header){b.push(t(500,y,l),t(740,y,v,{r:1}));y+=15}
  b.push(t(60,120,o.lang==='fr'?'Facturé à :':'Bill to:',{b:1}),t(60,135,'Gate Test Company Inc.'));
  y=250;const cols=o.cols;for(const [role,label,x] of cols)b.push(t(x,y,label,{b:1}));y+=20;
  const lines=[];
  for(const L of o.lines){const amount=L.qty!=null?rnd(L.qty*L.unit*1000,1000*1):L.amount;const a=L.qty!=null?Math.round(L.qty*1000)*L.unit/1000:L.amount;const cents=L.qty!=null?rnd(Math.round(L.qty*1000)*L.unit,1000):L.amount;
    for(const [role,,x] of cols){if(role==='desc')b.push(t(x,y,L.desc));if(role==='code'&&L.code)b.push(t(x,y,L.code));if(role==='qty')b.push(t(x+30,y,String(L.qty).replace('.',o.lang==='fr'?',':'.'),{r:1}));if(role==='unit')b.push(t(x+70,y,m(L.unit),{r:1}));if(role==='amount')b.push(t(x+70,y,m(cents),{r:1}));if(role==='tax'&&L.tax)b.push(t(x,y,L.tax))}
    if(L.wrap){y+=13;b.push(t(cols.find(c=>c[0]==='desc')[2],y,L.wrap))}
    lines.push({description:L.wrap?`${L.desc} ${L.wrap}`:L.desc,quantity:L.qty!=null?String(L.qty):null,unitCents:L.qty!=null?L.unit:null,amountCents:cents});y+=18}
  const subtotal=lines.reduce((s,l)=>s+l.amountCents,0);const taxes=o.taxes.map(([label,bp])=>[label,rnd(subtotal*bp,10000)]);const tax=taxes.reduce((s,x)=>s+x[1],0),total=subtotal+tax;
  y+=10;const ax=cols.find(c=>c[0]==='amount')[2]+70,lx=ax-200;
  b.push(t(lx,y,o.lang==='fr'?'Sous-total':'Subtotal'),t(ax,y,m(subtotal),{r:1}));y+=15;for(const [label,c] of taxes){b.push(t(lx,y,label),t(ax,y,m(c),{r:1}));y+=15}
  b.push(t(lx,y,'Total',{b:1}),t(ax,y,m(total),{r:1,b:1}));y+=15;b.push(t(lx,y,o.lang==='fr'?'Montant dû':'Amount due'),t(ax,y,m(total),{r:1}));
  if(o.footer)b.push(t(60,1000,o.footer,{size:8}));
  expected[key]={vendor:o.vendor,number:o.number,date:o.date,dueDate:o.dueDate||null,ambiguousDate:!!o.ambiguous,subtotal,tax,total,lines,lang:o.lang||'en'};
  return page(b.join(''));
}
const NW_COLS=[['code','Item',60],['desc','Description',120],['qty','Qty',380],['unit','Unit price',450],['amount','Amount',560]];
const NW_LINES=[{code:'P-1001',desc:'Copy paper, letter, 10 reams',qty:3,unit:5499},{code:'T-26A',desc:'Toner cartridge HP 26A',wrap:'(high yield, black)',qty:2,unit:18950},{code:'D-0077',desc:'Desk organizer',qty:1,unit:2345},{code:'',desc:'Delivery',qty:1,unit:1500}];
const docs={
  V1:invoice('V1',{vendor:'Northwind Office Supply Ltd.',address:'120 Harbour Street, Halifax NS B3J 1A1',email:'billing@northwind-office.example',number:'NW-2026-0142',date:'2026-03-03',dueDate:'2026-04-02',
    header:[['Invoice #','NW-2026-0142'],['Invoice date','March 3, 2026'],['Due date','April 2, 2026'],['Terms','Net 30']],cols:NW_COLS,lines:NW_LINES,taxes:[['GST 5%',500]]}),
  V2:invoice('V2',{lang:'fr',vendor:'Fournitures Boréales Inc.',address:'45, rue du Fleuve, Québec QC G1K 3A1',email:'comptes@fournitures-boreales.example',number:'F-88231',date:'2026-04-03',ambiguous:true,
    header:[['Facture n°','F-88231'],['Date :','03/04/2026'],['Conditions :','Net 30']],cols:[['desc','Description',60],['qty','Qté',380],['unit','Prix unitaire',450],['amount','Montant',560]],
    lines:[{desc:'Classeurs à anneaux',qty:12,unit:425},{desc:'Stylos (boîte de 12)',qty:5,unit:1190},{desc:'Agrafeuse robuste',qty:1,unit:3275}],taxes:[['TPS (5 %)',500],['TVQ (9,975 %)',998]]}),
  V3:invoice('V3',{lang:'fr',vendor:'Fournitures Boréales Inc.',address:'45, rue du Fleuve, Québec QC G1K 3A1',email:'comptes@fournitures-boreales.example',number:'F-88457',date:'2026-06-05',ambiguous:true,
    header:[['Facture n°','F-88457'],['Date :','05/06/2026'],['Conditions :','Net 30']],cols:[['desc','Description',60],['qty','Qté',380],['unit','Prix unitaire',450],['amount','Montant',560]],
    lines:[{desc:'Classeurs à anneaux',qty:6,unit:425},{desc:'Papier recyclé (caisse)',qty:2,unit:4850}],taxes:[['TPS (5 %)',500],['TVQ (9,975 %)',998]]}),
  V4:invoice('V4',{vendor:'Harbour Freight Logistics',address:'9 Wharf Road, Saint John NB E2L 4Z6',email:'ar@harbourfreight-log.example',number:'HF/77-2031',date:'2026-05-12',
    header:[['Our reference','HF/77-2031'],['Invoice date','May 12, 2026']],cols:[['desc','Description',60],['amount','Amount',560]],
    lines:[{desc:'Freight Halifax to Moncton, 2 pallets',amount:48000},{desc:'Fuel surcharge',amount:3600}],taxes:[['HST 15%',1500]]}),
  V5:invoice('V5',{vendor:'Harbour Freight Logistics',address:'9 Wharf Road, Saint John NB E2L 4Z6',email:'ar@harbourfreight-log.example',number:'HF/77-2096',date:'2026-06-09',
    header:[['Our reference','HF/77-2096'],['Invoice date','June 9, 2026']],cols:[['desc','Description',60],['amount','Amount',560]],
    lines:[{desc:'Freight Halifax to Fredericton, 1 pallet',amount:29500},{desc:'Fuel surcharge',amount:2200}],taxes:[['HST 15%',1500]]}),
  // V6: the same invoice as V1 sent again (different file: a reminder footer) — a duplicate by supplier and number.
  V6:invoice('V6',{vendor:'Northwind Office Supply Ltd.',address:'120 Harbour Street, Halifax NS B3J 1A1',email:'billing@northwind-office.example',number:'NW-2026-0142',date:'2026-03-03',dueDate:'2026-04-02',
    header:[['Invoice #','NW-2026-0142'],['Invoice date','March 3, 2026'],['Due date','April 2, 2026'],['Terms','Net 30']],cols:NW_COLS,lines:NW_LINES,taxes:[['GST 5%',500]],footer:'REMINDER COPY — please disregard if already paid.'}),
};
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const pg=await b.newPage();
for(const [k,h] of Object.entries(docs)){fs.writeFileSync(`${OUT}/${k}.html`,h);await pg.setContent(h);await pg.pdf({path:`${OUT}/${k}.pdf`,width:'816px',height:'1056px',printBackground:true})}
await b.close();fs.writeFileSync(`${OUT}/expected.json`,JSON.stringify(expected,null,1));console.log(Object.keys(expected).join(' '));
