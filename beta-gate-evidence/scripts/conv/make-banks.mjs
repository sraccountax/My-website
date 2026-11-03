// R152 synthetic statements: fictional banks, people and amounts that copy only the *layout style* of common Canadian
// statements (English and French, business accounts and cards, numeric and month-name dates, one PDF holding several
// statements). Expected figures are computed here with plain integer-cent arithmetic, independently of the reader.
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';import fs from 'fs';
const OUT='/srv/gate/t/conv/fx152';fs.mkdirSync(OUT,{recursive:true});
const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;');
const t=(x,y,s,o={})=>`<div style="position:absolute;left:${x}px;top:${y}px;font:${o.b?'bold ':''}${o.size||9}px Arial;white-space:nowrap${o.r?';transform:translateX(-100%)':''}">${esc(s)}</div>`;
const page=body=>`<div style="position:relative;width:816px;height:1056px;page-break-after:always;overflow:hidden">${body}</div>`;
const html=pages=>`<!doctype html><html><head><meta charset="utf-8"><style>@page{size:816px 1056px;margin:0}body{margin:0}</style></head><body>${pages.join('')}</body></html>`;
const EN=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],FR=['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
const EN_LONG=['January','February','March','April','May','June','July','August','September','October','November','December'],FR_LONG=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
const p2=n=>String(n).padStart(2,'0');
// Money: en "1,234.56"; fr "1 234,56" (narrow no-break space between thousands, as French PDFs print).
const money=(c,lang)=>{const a=Math.abs(c),w=Math.floor(a/100),f=p2(a%100);const g=lang==='fr'?w.toLocaleString('en-CA').replace(/,/g,' '):w.toLocaleString('en-CA');return lang==='fr'?`${g},${f}`:`${g}.${f}`};
const dateText=(iso,fmt)=>{const [y,m,d]=iso.split('-').map(Number);switch(fmt){
  case 'DD Mon':return `${p2(d)} ${EN[m-1]}`;case 'Mon DD':return `${EN[m-1]} ${p2(d)}`;case 'MM/DD':return `${p2(m)}/${p2(d)}`;case 'DD/MM':return `${p2(d)}/${p2(m)}`;
  case 'Mon D, YYYY':return `${EN[m-1]} ${d}, ${y}`;case 'DD mon fr':return `${p2(d)} ${FR[m-1]}`;case 'YYYY-MM-DD':return iso;default:throw new Error(fmt)}};
const expected={};
/**
 * spec: key, lang, bank, period:{start,end,text}, cols:[[role,label,x,(label2)]], dateFmt, mode:'split'|'signed'|'card'|'crdr',
 * opening, rows:[[iso,desc,cents]] (bank: + is money in; card: + is a charge), openLabel, closeLabel, perPage, account, balanceSuffix
 */
function build(spec){
  const lang=spec.lang||'en',pages=[];let bal=spec.opening;const rows=[];const per=spec.perPage||14;
  const chunks=[];for(let i=0;i<spec.rows.length;i+=per)chunks.push(spec.rows.slice(i,i+per));
  const x=role=>spec.cols.find(c=>c[0]===role)?.[2];
  const amt=(c)=>money(c,lang);
  chunks.forEach((chunk,pi)=>{const b=[];
    b.push(t(60,40,`${spec.bank} (fictional test bank)`,{b:1,size:12}),t(60,60,spec.title||(lang==='fr'?'Relevé de compte':'Account Statement'),{size:11}),t(60,76,spec.period.text),t(60,92,(lang==='fr'?'Compte : ':'Account number: ')+spec.account),t(650,40,(lang==='fr'?'Page ':'Page ')+(pi+1)+(lang==='fr'?' de ':' of ')+chunks.length));
    if(pi===0&&spec.summary)b.push(t(470,110,spec.summary[0]),t(700,110,spec.summary[1],{r:1}));
    let y=150;
    for(const c of spec.cols){b.push(t(c[2],y,c[1],{b:1}));if(c[3])b.push(t(c[2],y+11,c[3],{b:1}))}
    y+=spec.cols.some(c=>c[3])?30:18;
    if(pi===0&&spec.openLabel){const row=[t(x('desc'),y,spec.openLabel)];if(x('date')!=null)row.push(t(x('date'),y,dateText(spec.period.start,spec.dateFmt)));
      const shown=spec.mode==='card'?bal:bal;if(x('balance')!=null)row.push(t(x('balance')+80,y,amt(shown)+(bal<0&&spec.balanceSuffix?' '+spec.balanceSuffix:''),{r:1}));else row.push(t(x('amount')+70,y,amt(shown),{r:1}));b.push(...row);y+=14}
    for(const [iso,desc,c] of chunk){
      bal+=c;rows.push({date:iso,desc,cents:c});
      b.push(t(x('date'),y,dateText(iso,spec.dateFmt)));if(x('postdate')!=null)b.push(t(x('postdate'),y,dateText(iso,spec.dateFmt)));
      if(x('code')!=null)b.push(t(x('code'),y,c<0?'RA':'DE'));
      b.push(t(x('desc'),y,desc));
      // twoLine: the amounts sit on a second line with a reference (the description line has none).
      if(spec.twoLine){y+=11;b.push(t(x('desc'),y,'Ref '+String(4000+rows.length)))}
      if(spec.mode==='split'&&spec.splitCells&&Math.abs(c)>=100000){const col=c<0?'debit':'credit',a=amt(c),cut=a.search(/[\u202f ,]/);b.push(t(x(col)+80,y,a.slice(cut+1),{r:1}),t(x(col)+80-31,y,a.slice(0,cut),{r:1}))}
      else if(spec.mode==='split'){const col=c<0?'debit':'credit';b.push(t(x(col)+80,y,amt(c),{r:1}))}
      else if(spec.mode==='signed'){b.push(t(x('amount')+70,y,(c<0?'-':'')+amt(c),{r:1}))}
      else if(spec.mode==='card'){b.push(t(x('amount')+70,y,c<0?(spec.cardCredit==='CR'?amt(c)+' CR':'-'+amt(c)):amt(c),{r:1}))}
      else if(spec.mode==='crdr'){b.push(t(x('amount')+70,y,amt(c),{r:1}),t(x('drcr'),y,c<0?'DR':'CR'))}
      if(x('balance')!=null)b.push(t(x('balance')+80,y,amt(bal)+(bal<0&&spec.balanceSuffix?' '+spec.balanceSuffix:(bal<0?'-':'')),{r:1}));
      y+=14;
    }
    if(pi===chunks.length-1&&spec.closeLabel){const row=[t(x('desc'),y+4,spec.closeLabel)];if(x('balance')!=null)row.push(t(x('balance')+80,y+4,amt(bal)+(bal<0&&spec.balanceSuffix?' '+spec.balanceSuffix:(bal<0?'-':'')),{r:1}));else row.push(t(x('amount')+70,y+4,amt(bal),{r:1}));b.push(...row)}
    else if(pi<chunks.length-1)b.push(t(x('desc'),y+4,lang==='fr'?'Suite à la page suivante':'Continued on next page'));
    pages.push(page(b.join('')));
  });
  const net=rows.reduce((s,r)=>s+r.cents,0);
  // Tegh's reader reports card figures from the account holder's side: what is owed is negative, a charge is money out.
  const k=spec.mode==='card'?-1:1;
  return {pages,exp:{rows:rows.length,net:k*net,opening:k*spec.opening,closing:k*(spec.opening+net),first:rows[0].date,last:rows.at(-1).date,card:spec.mode==='card',account:spec.account.replace(/\D/g,'').slice(-4),sample:rows.slice(0,3).map(r=>({...r,cents:k*r.cents}))}};
}
// Transactions: deterministic fictional data.
const tx=(year,month,list)=>list.map(([d,desc,c])=>[`${year}-${p2(month)}-${p2(d)}`,desc,c]);
const BANK_ROWS=tx(2026,1,[[2,'E-TRANSFER Riverbend Bakery',-12500],[3,'DEPOSIT Branch 0442',150000],[5,'PREAUTHORIZED DEBIT Acme Payroll',-215000],[6,'SERVICE CHARGE',-1295],[9,'CHEQUE 118',-38050],[12,'POS PURCHASE Lakeside Hardware',-8977],
  [14,'E-TRANSFER Pinecrest Dental',99500],[16,'INTERNET BILL PAY Hydro North',-24410],[19,'CREDIT MEMO Northwind Card Settle',61234],[21,'INSURANCE PREMIUM Maple Mutual',-18045],[23,'E-TRANSFER Harbour Freight',-45000],[26,'DEPOSIT Branch 0442',38900],
  [27,'RENT Oakfield Properties',-160000],[28,'INTEREST',412],[30,'MONTHLY FEE',-2500],[31,'E-TRANSFER Cedar Lane Clinic',77550]]);
const FR_ROWS=tx(2026,1,[[2,'Virement Interac Boulangerie Rive',-12500],[3,'Dépôt guichet',150000],[5,'Paiement préautorisé Paie Acme',-215000],[6,'Frais de service',-1295],[9,'Chèque 118',-38050],[12,'Achat Quincaillerie Lac',-8977],
  [14,'Virement reçu Clinique Pin',99500],[16,'Paiement facture Hydro Nord',-24410],[19,'Dépôt carte marchand',1261234],[21,'Assurance Mutuelle Érable',-18045],[23,'Virement Fret Havre',-45000],[27,'Loyer Propriétés Chêne',-160000],[30,'Frais mensuels',-2500]]);
const CARD_ROWS=tx(2026,1,[[2,'GRAND & TOY #221',4599],[4,'PETRO-CANADA 1188',8231],[7,'PAYMENT - THANK YOU',-150000],[9,'AMAZON.CA MARKETPLACE',12649],[12,'TIM HORTONS #4410',1287],[15,'AIR CANADA 0142',64320],[18,'STAPLES 0099',23415],[22,'REFUND STAPLES 0099',-5000],[25,'ROGERS WIRELESS',11300],[28,'COSTCO WHOLESALE 552',38862]]);
const specs=[
  {key:'G',bank:'Northstar Royal Bank',account:'00012-123-456-7',period:{start:'2026-01-01',end:'2026-01-31',text:'January 1, 2026 to January 31, 2026'},dateFmt:'DD Mon',mode:'split',opening:842210,
   cols:[['date','Date',60],['desc','Description',115],['debit','Cheques & Debits ($)',380],['credit','Deposits & Credits ($)',490],['balance','Balance ($)',610]],openLabel:'Opening balance',closeLabel:'Closing balance',rows:BANK_ROWS,perPage:9},
  {key:'H',bank:'Bank of the Mountains',account:'1234 5566-778',period:{start:'2026-01-01',end:'2026-01-31',text:'For the period ending January 31, 2026 · Statement period Jan 1, 2026 to Jan 31, 2026'},dateFmt:'Mon DD',mode:'split',opening:1200000,
   cols:[['date','Date',60],['desc','Description',115],['debit','Amounts deducted',370,'from your account ($)'],['credit','Amounts added to',490,'your account ($)'],['balance','Balance ($)',610]],openLabel:'Opening balance',closeLabel:'Closing totals',rows:BANK_ROWS},
  {key:'I',bank:'Coastal Scotia Bank',account:'40500 01234 18',period:{start:'2026-01-01',end:'2026-01-31',text:'Jan 1 2026 - Jan 31 2026'},dateFmt:'MM/DD',mode:'split',opening:300000,
   cols:[['date','Date',60],['desc','Transactions',115],['debit','Withdrawn/Debits ($)',380],['credit','Deposited/Credits ($)',490],['balance','Balance ($)',610]],openLabel:'Balance forward',closeLabel:'Closing balance',rows:BANK_ROWS.filter((r,i)=>i%2===0)},
  {key:'J',lang:'fr',bank:'Caisse Populaire du Fleuve',account:'815-30123-456789',period:{start:'2026-01-01',end:'2026-01-31',text:'Du 1er janvier 2026 au 31 janvier 2026'},dateFmt:'DD mon fr',mode:'split',opening:1534212,
   cols:[['date','Date',60],['code','Code',110],['desc','Description',150],['debit','Retrait',400],['credit','Dépôt',500],['balance','Solde',610]],openLabel:"Solde d'ouverture",closeLabel:'Solde de clôture',rows:FR_ROWS},
  {key:'K',lang:'fr',bank:'Banque Nationale du Nord',account:'00123-45-67890',period:{start:'2026-01-01',end:'2026-01-31',text:'Période du 2026-01-01 au 2026-01-31'},dateFmt:'DD/MM',mode:'split',opening:2000000,
   cols:[['date','Date',60],['desc','Description',115],['debit','Débit',400],['credit','Crédit',500],['balance','Solde',610]],openLabel:'Solde précédent',closeLabel:'Nouveau solde',rows:FR_ROWS},
  {key:'L',bank:'Clementine Direct',account:'3012345678',period:{start:'2026-01-01',end:'2026-01-31',text:'Statement period: January 1, 2026 - January 31, 2026'},dateFmt:'Mon D, YYYY',mode:'signed',opening:450000,
   cols:[['date','Transaction Date',60],['desc','Transaction Description',160],['amount','Amount',470],['balance','Balance',610]],openLabel:'Opening Balance',closeLabel:'Closing Balance',rows:BANK_ROWS},
  {key:'M',bank:'Centurion Express',account:'XXXX-XXXXXX-71004',title:'Business Card Statement',period:{start:'2025-12-29',end:'2026-01-28',text:'Statement period Dec 29, 2025 to Jan 28, 2026'},dateFmt:'Mon DD',mode:'card',opening:212560,
   cols:[['date','Date',60],['desc','Description',140],['amount','Amount ($)',540]],openLabel:'Previous Balance',closeLabel:'New Balance',rows:CARD_ROWS,
   summary:['Credit limit','25,000.00']},
  {key:'N',lang:'fr',bank:'Visa Caisse Boréale',account:'4510 **** **** 2207',title:'Relevé de carte de crédit',period:{start:'2026-01-01',end:'2026-01-31',text:'Du 1er janvier 2026 au 31 janvier 2026'},dateFmt:'DD mon fr',mode:'card',cardCredit:'CR',opening:98765,
   cols:[['date','Date de transaction',60],['postdate',"Date d'inscription",160],['desc','Description',260],['amount','Montant',560]],openLabel:'Solde précédent',closeLabel:'Nouveau solde',rows:CARD_ROWS,
   summary:['Limite de crédit','10 000,00']},
  {key:'P',bank:'Prairie Card Services',account:'5520 **** **** 8841',title:'Credit Card Statement',period:{start:'2025-12-06',end:'2026-01-05',text:'Statement period Dec 6, 2025 to Jan 5, 2026'},dateFmt:'MM/DD',mode:'card',opening:54321,
   cols:[['date','Trans Date',60],['postdate','Post Date',130],['desc','Description',210],['amount','Amount',560]],openLabel:'Previous Statement Balance',closeLabel:'New Balance',
   rows:[['2025-12-08','SHELL 4471',6512],['2025-12-14','CANADIAN TIRE 118',14599],['2025-12-20','PAYMENT RECEIVED',-54321],['2025-12-28','BEST BUY 922',89999],['2026-01-02','NETFLIX.COM',1999],['2026-01-04','UBER EATS',3450]],summary:['Available credit','7,500.00']},
  {key:'Q',bank:'Valley Credit Union',account:'Member 88213-01',period:{start:'2026-01-01',end:'2026-01-31',text:'Statement period Jan 1, 2026 to Jan 31, 2026'},dateFmt:'YYYY-MM-DD',mode:'crdr',opening:50000,balanceSuffix:'OD',
   cols:[['date','Date',60],['desc','Description',140],['amount','Amount',430],['drcr','DR/CR',520],['balance','Balance',610]],openLabel:'Opening balance',closeLabel:'Closing balance',
   rows:tx(2026,1,[[3,'Cheque 41',-30000],[6,'Loan payment',-45000],[10,'Deposit',20000],[15,'Transfer in',80000],[20,'Service fee',-750],[29,'Cheque 42',-12500]])},
];
specs.push(
  {key:'R',bank:'Lakeshore Dominion Bank',account:'1234-5098765',period:{start:'2026-01-01',end:'2026-01-31',text:'Statement period Jan 1, 2026 to Jan 31, 2026'},dateFmt:'MM/DD',mode:'split',opening:500000,twoLine:true,
   cols:[['date','Date',60],['desc','Description',115],['debit','Withdrawals',400],['credit','Deposits',500],['balance','Balance',610]],openLabel:'Balance forward',closeLabel:'Closing balance',rows:BANK_ROWS.slice(0,10)},
  {key:'S',lang:'fr',bank:'Banque Laurentienne Fictive',account:'0456-7890123',period:{start:'2026-01-01',end:'2026-01-31',text:'Du 1er janvier 2026 au 31 janvier 2026'},dateFmt:'DD/MM',mode:'split',opening:2500000,splitCells:true,
   cols:[['date','Date',60],['desc','Description de la transaction',115],['debit','Retraits ($)',400],['credit','Dépôts ($)',500],['balance','Solde ($)',610]],openLabel:'Solde reporté',closeLabel:'Solde final',rows:FR_ROWS});
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});const ctx=await b.newContext();const pg=await ctx.newPage();
for(const spec of specs){const {pages,exp}=build(spec);expected[spec.key]=exp;fs.writeFileSync(`${OUT}/${spec.key}.html`,html(pages));await pg.setContent(html(pages));await pg.pdf({path:`${OUT}/${spec.key}.pdf`,width:'816px',height:'1056px',printBackground:true})}
// O: one PDF holding three statements — account …4567 for January and February (February opens at January's close),
// then account …9012 for January.
{const jan=specs.find(s=>s.key==='G');const a=build(jan);const febRows=tx(2026,2,[[2,'E-TRANSFER Riverbend Bakery',-9900],[6,'DEPOSIT Branch 0442',120000],[13,'PREAUTHORIZED DEBIT Acme Payroll',-215000],[27,'MONTHLY FEE',-2500]]);
 const feb=build({...jan,period:{start:'2026-02-01',end:'2026-02-28',text:'February 1, 2026 to February 28, 2026'},opening:a.exp.closing,rows:febRows});
 const other=build({...jan,account:'00012-987-901-2',opening:100000,rows:tx(2026,1,[[4,'DEPOSIT',50000],[18,'TRANSFER TO SAVINGS',-25000]])});
 expected.O={statements:[{...a.exp},{...feb.exp},{...other.exp}],chain:a.exp.closing===feb.exp.opening};
 const all=[...a.pages,...feb.pages,...other.pages];fs.writeFileSync(`${OUT}/O.html`,html(all));await pg.setContent(html(all));await pg.pdf({path:`${OUT}/O.pdf`,width:'816px',height:'1056px',printBackground:true})}
await b.close();fs.writeFileSync(`${OUT}/expected.json`,JSON.stringify(expected,null,1));console.log(Object.keys(expected).join(' '));
