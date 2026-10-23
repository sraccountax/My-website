// Runs the R145 layout reader in Node on dumped lines; prints only aggregates (no statement contents).
import fs from 'fs';
const mod=await import('/home/user/My-website/tegh-app/assets/tegh-statement-layout-r145.js?'+Date.now());
const [,,f,acct='',verbose='']=process.argv;const d=JSON.parse(fs.readFileSync(f));
const r=mod.parseStatementLayout(d.lines,{accountType:acct});
if(!r){console.log('null');process.exit()}
const c=v=>v==null?null:Math.round(v*100);
const out=r.rows.filter(x=>x.amount<0).reduce((s,x)=>s-c(x.amount),0),inn=r.rows.filter(x=>x.amount>0).reduce((s,x)=>s+c(x.amount),0);
console.log(JSON.stringify({rows:r.rows.length,undated:r.undated,period:r.period&&[r.period.start,r.period.end],isCard:r.isCard,opening:r.openingBalance,closing:r.closingBalance,net:r.netCents/100,out:out/100,in:inn/100,reconciled:r.reconciled,checks:r.balanceChecks,mismatch:r.balanceMismatches,pages:(r.pageChecks||[]).length+"/"+(r.pageChecks||[]).filter(x=>x.ok).length,lastRow:r.closingFromLastRow,notes:r.notes.length,cols:r.columns}));
if(verbose)for(const x of r.rows)console.log(x.date,x.page,x.amount,x.balance,x.balanceMismatch??'',x.description.slice(0,int(verbose)));
function int(v){return Number(v)||0}
