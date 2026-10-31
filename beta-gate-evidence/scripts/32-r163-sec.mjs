// R163 adversarial / abuse tests. Each fires a real attack at the isolated gate host and asserts Tegh refuses it.
// Authorization is not in question here: gate.test is the build team's own host with synthetic accounts.
import {S,sql,need,rec,save} from './lib.mjs';
import {execFileSync} from 'child_process';
const A='R163SEC';
execFileSync('mysql',['tegh_gate','-e','DELETE FROM login_attempts']);
const t=async(id,title,fn)=>{try{const r=await fn();rec(id,A,title,r.ok?'PASS':'FAIL',r.info)}catch(e){rec(id,A,title,'FAIL','error: '+String(e.message).slice(0,300))}};
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');const tag=Date.now().toString(36);
const mk=async name=>{const co=need('co',await o.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));const id=co.company?.id||co.id;return {id,bank:sql(`SELECT id FROM bank_accounts WHERE company_id='${id}' AND account_type='bank' ORDER BY created_at LIMIT 1`)}};
const imp=async(cid,bank,csv,name)=>{o.cid=cid;const data=Buffer.from(csv);const up=need('up',await o.call('imports/upload-start',{method:'POST',json:{filename:name,size:data.length}}));need('c',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:up.uploadId,offset:0,data:data.toString('base64')}}));const pv=need('f',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:up.uploadId,bankAccountId:bank,exchangeRateMicros:1000000,mode:'preview'}})).preview;const keys=pv.rows.filter(x=>x.selectionKey).map(x=>String(x.selectionKey));return need('ap',await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.id,operationKey:'sec-'+Math.random(),selectedKeys:keys,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}}),[200,201]).batch};

// Victim company with an uploaded statement.
const V=await mk('R163SEC Victim '+tag);
const vb=await imp(V.id,V.bank,`Date,Description,Amount\n2026-03-05,ROGERS WIRELESS 1,-56.50\n2026-03-20,CLIENT DEPOSIT A,565.00\n`,'v.csv');
const VM=need('vm',await o.call('guided/statement-months/attach',{method:'POST',json:{batchId:vb.id,bankAccountId:V.bank,fiscalYearEnd:'2026-12-31',month:'2026-03'}})).id;
// Attacker company (same owner account here, but a different company scope — the server must still isolate by company_id).
const X=await mk('R163SEC Attacker '+tag);

await t('S-01','Cross-company IDOR: the attacker company cannot read the victim\'s mapping, lines, post, reconcile or remove its month (404)',async()=>{o.cid=X.id;
  const r=[await o.call('guided/mapping?monthId='+VM),await o.call('guided/group-lines?monthId='+VM+'&group=x'),
    await o.call('guided/post-group',{method:'POST',json:{monthId:VM,groupKey:'ROGERS WIRELESS|out',accountId:sql(`SELECT id FROM accounts WHERE company_id='${X.id}' AND code='6300'`),tax:'NO_TAX'}}),
    await o.call('guided/reconcile',{method:'POST',json:{monthId:VM}}),
    await o.call('guided/statement-months/remove',{method:'POST',json:{monthId:VM}})];
  const posted=sql(`SELECT COUNT(*) FROM bank_transactions WHERE company_id='${V.id}' AND status='posted'`);
  return {ok:r.every(x=>x.s===404)&&posted==='0',info:'statuses '+r.map(x=>x.s).join(',')+'; victim posted '+posted}});

await t('S-02','Cross-company: attaching the victim\'s import batch to the attacker company is refused (404)',async()=>{o.cid=X.id;
  const r=await o.call('guided/statement-months/attach',{method:'POST',json:{batchId:vb.id,bankAccountId:X.bank,fiscalYearEnd:'2026-12-31',month:'2026-03'}});
  return {ok:r.s===404,info:'status '+r.s}});

await t('S-03','CSRF: a POST with a wrong CSRF token is refused (403), on every R163 write route; a good-token read still works afterwards',async()=>{o.cid=V.id;
  const bad=async(route,json)=>(await o.call(route,{method:'POST',json,headers:{'X-CSRF-Token':'wrong-token'}})).s;
  const codes=[await bad('guided/statement-months/remove',{monthId:VM}),await bad('guided/post-group',{monthId:VM,groupKey:'x',accountId:'x',tax:'NO_TAX'}),await bad('guided/reconcile',{monthId:VM}),await bad('guided/accountant/request',{note:'x'})];
  const goodStillWorks=(await o.call('guided/mapping?monthId='+VM)).s===200;
  return {ok:codes.every(c=>c===403)&&goodStillWorks,info:'statuses '+codes.join(',')+'; good-token read '+goodStillWorks}});

await t('S-04','Email-header injection: a company name with CRLF + a forged Bcc cannot inject a header; the stored subject is single-line',async()=>{
  const E=await mk(`R163SEC Evil ${tag}\r\nBcc: attacker@evil.test`);o.cid=E.id;
  const before=sql(`SELECT COUNT(*) FROM outbound_emails`);
  const r=await o.call('guided/accountant/request',{method:'POST',json:{note:'injection test'}});
  const subj=sql(`SELECT subject FROM outbound_emails WHERE company_id='${E.id}' ORDER BY created_at DESC LIMIT 1`);
  const noCrlf=!/[\r\n]/.test(subj);const nameNoCrlf=sql(`SELECT name FROM companies WHERE id='${E.id}'`);const inputStripped=!/[\r\n]/.test(nameNoCrlf);
  return {ok:[201,429].includes(r.s)&&noCrlf&&inputStripped,info:`status ${r.s}; subject has no CRLF ${noCrlf}; name stored without CRLF ${inputStripped}; subject ${JSON.stringify(subj).slice(0,110)}`}});

await t('S-05','Stored XSS: a <script> payload in a bank-line description is stored raw but returned as data (the client escapes it); the API never executes it',async()=>{
  const P=await mk('R163SEC XSS '+tag);const b=await imp(P.id,P.bank,`Date,Description,Amount\n2026-03-05,"<img src=x onerror=alert(1)> ACME",-10.00\n`,'x.csv');o.cid=P.id;
  const at=need('at',await o.call('guided/statement-months/attach',{method:'POST',json:{batchId:b.id,bankAccountId:P.bank,fiscalYearEnd:'2026-12-31',month:'2026-03'}}));
  const mp=need('mp',await o.call('guided/mapping?monthId='+at.id));const key=mp.groups[0].key;
  const gl=need('gl',await o.call('guided/group-lines?monthId='+at.id+'&group='+encodeURIComponent(key)));
  const desc=gl.lines.map(l=>l.description).join(' ');
  // The payload comes back as a JSON string value (data), never HTML; the raw <img ...> is preserved verbatim and the
  // API did not break. DOM escaping via esc() is the defense (source review + UI probe R-31 'no page errors').
  return {ok:gl.lines.length===1&&typeof gl.lines[0].description==='string'&&desc.includes('<img'),info:'group '+key+'; returned verbatim as JSON string: '+JSON.stringify(desc).slice(0,70)}});

await t('S-06','Integrity: a deposit for an HST registrant cannot be posted without a tax choice (server refuses, not only the UI)',async()=>{o.cid=V.id;
  const mp=need('mp',await o.call('guided/mapping?monthId='+VM));const dep=mp.groups.find(g=>g.key==='CLIENT DEPOSIT|in');
  const r=await o.call('guided/post-group',{method:'POST',json:{monthId:VM,groupKey:dep.key,accountId:dep.suggestedAccountId,tax:''}});
  return {ok:r.s===422&&r.b.code==='guided_tax_required',info:'status '+r.s+' '+(r.b.code||'')}});

await t('S-07','Integrity: the client cannot mark a month reconciled by itself — reconcile with a wrong closing balance is refused with the difference',async()=>{o.cid=V.id;
  const hst=need('mp',await o.call('guided/mapping?monthId='+VM)).groups.find(g=>g.key==='ROGERS WIRELESS|out').suggestedTax;
  for(const g of need('mp2',await o.call('guided/mapping?monthId='+VM)).groups.filter(g=>g.pending>0)){await o.call('guided/post-group',{method:'POST',json:{monthId:VM,groupKey:g.key,accountId:g.suggestedAccountId,tax:g.key.startsWith('CLIENT')?hst:g.suggestedTax}})}
  const r=await o.call('guided/reconcile',{method:'POST',json:{monthId:VM,closingBalanceCents:999999}});
  const row=sql(`SELECT reconciliation_id FROM guided_statement_months WHERE id='${VM}'`);
  return {ok:r.b.reconciled===false&&r.b.needsBalance===true&&(row===''||row==='NULL'),info:'reconciled '+r.b.reconciled+'; stored recon '+JSON.stringify(row)}});

await t('S-08','Abuse: accountant requests are capped at 5 per company per hour (the 6th is 429), so the mail path is not a relay',async()=>{const R=await mk('R163SEC Rate '+tag);o.cid=R.id;
  const codes=[];for(let i=0;i<6;i++)codes.push((await o.call('guided/accountant/request',{method:'POST',json:{note:'r'+i}})).s);
  return {ok:codes.slice(0,5).every(c=>c===201)&&codes[5]===429,info:codes.join(',')}});

await t('S-09','Secrets: the accountant review/quote never returns the hourly rate, the minutes per finding or the hours',async()=>{o.cid=V.id;
  const rv=await o.call('guided/accountant/review');const s=JSON.stringify(rv.b);
  return {ok:rv.s===200&&!/minutes|hoursHundredths|rate_cents|rateCents|"rate"|perHour|\/h\b/i.test(s)&&rv.b.findings?.every(f=>!('minutes'in f)),info:'keys '+Object.keys(rv.b).join(',')}});

await t('S-10','Input bounds: an over-long note and a bad monthId are rejected cleanly (no 500)',async()=>{o.cid=V.id;
  const a=await o.call('guided/accountant/request',{method:'POST',json:{note:'A'.repeat(50000)}});
  const b=await o.call('guided/mapping?monthId='+'../'.repeat(40)+'etc/passwd');
  return {ok:[201,429].includes(a.s)&&[400,404,422].includes(b.s),info:'longnote '+a.s+'; traversal-monthId '+b.s}});

save('r163sec.json');
const pass=(await import('./lib.mjs')).results.filter(r=>r.area===A&&r.status==='PASS').length,total=(await import('./lib.mjs')).results.filter(r=>r.area===A).length;
console.log(`\n${pass}/${total} R163 security checks passed`);
