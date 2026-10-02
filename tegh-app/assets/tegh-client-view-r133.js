/* R133: client viewing page. A read-only dashboard and the reports an
   accountant chose to share. Sign-in is a 6-digit code emailed to the client;
   the link token is read from the URL fragment and only ever sent in a POST. */
(()=>{
  'use strict';
  const $=(s,r=document)=>r.querySelector(s),$$=(s,r=document)=>[...r.querySelectorAll(s)];
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const token=(()=>{const m=/[#&]t=([^&]+)/.exec(location.hash);return m?decodeURIComponent(m[1]):''})();
  let me=null,currency='CAD';
  const today=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)};
  const money=(cents,cur=currency)=>{const n=Number(cents||0)/100;try{return new Intl.NumberFormat('en-CA',{style:'currency',currency:cur||'CAD'}).format(n)}catch{return (n<0?'-':'')+'$'+Math.abs(n).toFixed(2)}};
  const dateText=v=>{if(!/^\d{4}-\d{2}-\d{2}/.test(String(v||'')))return String(v??'');const [y,m,d]=String(v).slice(0,10).split('-').map(Number);return new Intl.DateTimeFormat('en-CA',{month:'short',day:'numeric',year:'numeric',timeZone:'UTC'}).format(new Date(Date.UTC(y,m-1,d)))};
  async function api(route,{method='GET',json,params}={}){
    const url=new URL('/api/index.php',location.origin);url.searchParams.set('route',route);url.searchParams.set('v','5990');
    Object.entries(params||{}).forEach(([k,v])=>{if(v!==undefined&&v!==null&&v!=='')url.searchParams.set(k,String(v))});
    const r=await fetch(url.pathname+url.search,{method,credentials:'same-origin',cache:'no-store',headers:json?{'Content-Type':'application/json'}:{},body:json?JSON.stringify(json):undefined});
    let body={};try{body=await r.json()}catch{}
    if(!r.ok)throw Object.assign(new Error(body.error||'Something went wrong. Please try again.'),{status:r.status,code:body.code});
    return body;
  }
  const gate=$('[data-cv-gate]'),app=$('[data-cv-app]'),err=$('[data-cv-error]');
  const showError=m=>{err.hidden=!m;err.textContent=m||''};

  /* ---------- sign-in ---------- */
  async function boot(){
    try{me=await api('client-view/me');return openApp()}catch(e){if(e.status!==401){showGate(e.message);return}}
    if(!token){showGate('This page needs the viewing link your accountant sent you. Open the link from your email.');return}
    $('[data-cv-gate-text]').textContent='Your accountant has shared a view-only dashboard with you.';$('[data-cv-send]').hidden=false;
  }
  function showGate(message){gate.hidden=false;app.hidden=true;$('[data-cv-gate-text]').textContent='';showError(message)}
  async function sendCode(button){
    showError('');button.disabled=true;
    try{const r=await api('client-view/start',{method:'POST',json:{token}});$('[data-cv-sent-to]').textContent=r.sentTo;$('[data-cv-gate-text]').textContent=`${r.companyName} · shared with ${r.clientName}`;$('[data-cv-send]').hidden=true;const form=$('[data-cv-code-form]');form.hidden=false;form.code.value='';form.code.focus()}
    catch(e){showError(e.message)}finally{button.disabled=false}
  }
  $('[data-cv-send-code]').onclick=e=>sendCode(e.currentTarget);
  $('[data-cv-resend]').onclick=e=>sendCode(e.currentTarget);
  $('[data-cv-code-form]').onsubmit=async e=>{
    e.preventDefault();const form=e.currentTarget,code=form.code.value.replace(/\D/g,'');showError('');
    if(code.length!==6){showError('Enter the 6 digits from the email.');return}
    const b=$('button[type="submit"]',form);b.disabled=true;
    try{await api('client-view/verify',{method:'POST',json:{token,code}});me=await api('client-view/me');openApp()}catch(x){showError(x.message)}finally{b.disabled=false}
  };
  $('[data-cv-signout]').onclick=async()=>{try{await api('client-view/signout',{method:'POST',json:{}})}catch{}location.reload()};

  /* ---------- app ---------- */
  function openApp(){
    gate.hidden=true;app.hidden=false;currency=me.company.currency||'CAD';
    const company=$('[data-cv-company]');company.hidden=false;company.innerHTML=`<b>${esc(me.company.name)}</b><small>Shared by ${esc(me.sharedBy)} · view only</small>`;
    $('[data-cv-signout]').hidden=false;document.title=`${me.company.name} · Dashboard`;
    $$('[data-cv-tab]').forEach(t=>t.onclick=()=>switchTab(t.dataset.cvTab));
    if(!me.reports.length)$('[data-cv-tab="reports"]').hidden=true;
    switchTab('dashboard');
  }
  function switchTab(name){
    $$('[data-cv-tab]').forEach(t=>{const on=t.dataset.cvTab===name;t.classList.toggle('is-active',on);if(on)t.setAttribute('aria-current','page');else t.removeAttribute('aria-current')});
    $('[data-cv-dashboard]').hidden=name!=='dashboard';$('[data-cv-reports]').hidden=name!=='reports';
    $('[data-cv-title]').textContent=name==='dashboard'?`Hello ${me.clientName}`:'Reports';
    if(name==='dashboard')loadDashboard();else renderReportList();
  }
  async function loadDashboard(){
    const host=$('[data-cv-dashboard]');host.innerHTML='<p class="cv-muted">Loading your figures…</p>';
    try{
      const {summary:s}=await api('client-view/dashboard');const ytd=s.netIncomeYtdCents==null?null:Number(s.netIncomeYtdCents),t=s.taxSummary||null,net=t?Number(t.gstHstNetCents||0):null,overdue=Number(s.overdueInvoicesCents||0);
      $('[data-cv-subtitle]').textContent=`Here's where ${me.company.name} stands today. All figures in ${currency}.`;
      const cards=[
        {label:'Money in the bank',value:s.bankBalanceCents,note:'What the books show today',tone:Number(s.bankBalanceCents)<0?'bad':''},
        {label:'Customers owe you',value:s.unpaidInvoicesCents,note:`${Number(s.openInvoiceCount||0)} unpaid invoice${Number(s.openInvoiceCount)===1?'':'s'}${overdue>0?` · ${money(overdue)} overdue`:''}`,tone:overdue>0?'warn':''},
        {label:'You owe suppliers',value:s.payableSubledgerCents,note:`${Number(s.openBillCount||0)} unpaid bill${Number(s.openBillCount)===1?'':'s'}`},
        {label:ytd!==null&&ytd<0?'Loss this year':'Profit this year',value:ytd===null?null:Math.abs(ytd),note:`${dateText(s.periodStart)} – ${dateText(s.periodEnd)}`,tone:ytd!==null&&ytd<0?'bad':'good'},
        ...(t?[{label:net<0?'GST/HST refund due':'GST/HST you owe',value:Math.abs(net),note:`Collected ${money(t.gstHstCollectedCents)} · paid on purchases ${money(t.gstHstRecoverableCents)}`,tone:net<0?'good':net>0?'warn':''}]:[])
      ];
      const sentence=[ytd===null?'':ytd>=0?`So far this year the business has made a profit of ${money(ytd)}.`:`So far this year the business has made a loss of ${money(-ytd)}.`,Number(s.unpaidInvoicesCents)>0?`Customers owe ${money(s.unpaidInvoicesCents)}${overdue>0?` (${money(overdue)} overdue)`:''}.`:'',t&&net!==0?(net>0?`About ${money(net)} of GST/HST is owing.`:`A GST/HST refund of about ${money(-net)} is due.`):''].filter(Boolean).join(' ');
      host.innerHTML=`<p class="cv-summary">${esc(sentence)}</p><div class="cv-cards">${cards.map((c,i)=>`<article class="cv-stat" style="--cv-i:${i}" ${c.tone?`data-tone="${c.tone}"`:''}><span>${esc(c.label)}</span><strong data-cv-count="${c.value===null?'':Number(c.value)}">${c.value===null?'—':esc(money(c.value))}</strong><small>${esc(c.note)}</small></article>`).join('')}</div><div data-cv-charts></div>${me.reports.length?`<section class="cv-card"><h2>Reports shared with you</h2><div class="cv-report-links">${me.reports.map(r=>`<button type="button" class="cv-chip" data-cv-open="${esc(r.key)}">${esc(r.title)} →</button>`).join('')}</div></section>`:''}`;
      $$('[data-cv-open]',host).forEach(b=>b.onclick=()=>{switchTab('reports');openReport(b.dataset.cvOpen)});
      const charts=window.TeghClientCharts;
      if(charts){
        $$('[data-cv-count]',host).forEach(n=>{if(n.dataset.cvCount!=='')charts.countUp(n,Number(n.dataset.cvCount),v=>money(v))});
        const titles=Object.fromEntries(me.reports.map(r=>[r.key,r.title]));
        const compact=v=>{try{return new Intl.NumberFormat('en-CA',{style:'currency',currency,notation:'compact',maximumFractionDigits:1}).format(v/100)}catch{return money(v)}};
        charts.mount($('[data-cv-charts]',host),{reports:me.reports.map(r=>r.key),titles,currency,money:v=>money(v),compact,period:{start:s.periodStart,end:s.periodEnd},
          fetch:key=>{const def=me.reports.find(r=>r.key===key);return api('client-view/report',{params:{key,purpose:'chart',...(def.mode==='asOf'?{asOf:s.periodEnd}:{start:s.periodStart,end:s.periodEnd})}}).then(r=>r.output)},
          onOpen:key=>{switchTab('reports');openReport(key)}});
      }
    }catch(e){if(e.status===401||e.status===410)return sessionEnded(e.message);host.innerHTML=`<p class="cv-error">${esc(e.message)}</p>`}
  }
  function sessionEnded(message){me=null;app.hidden=true;gate.hidden=false;$('[data-cv-signout]').hidden=true;$('[data-cv-company]').hidden=true;$('[data-cv-code-form]').hidden=true;$('[data-cv-send]').hidden=!token;$('[data-cv-gate-text]').textContent='';showError(message)}

  /* ---------- reports ---------- */
  function renderReportList(){
    const host=$('[data-cv-reports]');$('[data-cv-subtitle]').textContent='Choose a report. You can change the dates and download it as PDF or Excel.';
    host.innerHTML=`<div class="cv-report-links cv-report-menu">${me.reports.map(r=>`<button type="button" class="cv-chip" data-cv-open="${esc(r.key)}">${esc(r.title)}</button>`).join('')}</div><div data-cv-report></div>`;
    $$('[data-cv-open]',host).forEach(b=>b.onclick=()=>openReport(b.dataset.cvOpen));
  }
  const yearStart=()=>today().slice(0,4)+'-01-01';
  async function openReport(key,params){
    const def=me.reports.find(r=>r.key===key);if(!def)return;
    $$('[data-cv-open]').forEach(b=>b.classList.toggle('is-active',b.dataset.cvOpen===key));
    const host=$('[data-cv-report]');if(!host)return;
    const p=params||(def.mode==='asOf'?{asOf:today()}:{start:yearStart(),end:today()});
    host.innerHTML=`<section class="cv-card cv-report"><form class="cv-dates" data-cv-dates>${def.mode==='asOf'?`<label>As of<input type="date" name="asOf" value="${esc(p.asOf)}" max="${today()}"></label>`:`<label>From<input type="date" name="start" value="${esc(p.start)}"></label><label>To<input type="date" name="end" value="${esc(p.end)}"></label>`}<button type="submit" class="cv-btn cv-btn-small">Show</button></form><p class="cv-muted">Loading ${esc(def.title)}…</p></section>`;
    $('[data-cv-dates]',host).onsubmit=e=>{e.preventDefault();openReport(key,Object.fromEntries(new FormData(e.currentTarget)))};
    try{
      const {output:model}=await api('client-view/report',{params:{key,...p}});
      const card=$('.cv-report',host);$('.cv-muted',card)?.remove();
      card.insertAdjacentHTML('beforeend',`<header class="cv-report-head"><div><h2>${esc(model.title||def.title)}</h2><small>${esc(periodText(model))}</small></div><div class="cv-row"><button type="button" class="cv-btn cv-btn-small cv-btn-secondary" data-cv-dl="pdf">Download PDF</button><button type="button" class="cv-btn cv-btn-small cv-btn-secondary" data-cv-dl="xlsx">Download Excel</button></div></header>${reportTable(model)}`);
      $$('[data-cv-dl]',card).forEach(b=>b.onclick=()=>download(model,key,b.dataset.cvDl,b));
    }catch(e){if(e.status===401||e.status===410)return sessionEnded(e.message);const card=$('.cv-report',host);$('.cv-muted',card).textContent=e.message;$('.cv-muted',card).className='cv-error'}
  }
  function periodText(m){const p=m.period||{};return p.mode==='asOf'?`As of ${dateText(p.asOf)}`:p.start?`${dateText(p.start)} – ${dateText(p.end)}`:''}
  const humanize=k=>String(k).replace(/Cents$/,'').replace(/([a-z])([A-Z])/g,'$1 $2').replace(/^./,c=>c.toUpperCase());
  function cell(model,row,col){const v=row[col.key];if(col.type==='money')return v===null||v===undefined||v===''?'':money(v,row.currency||model.currency);if(col.type==='date')return dateText(v);if(col.type==='rate_bps')return (Number(v||0)/100).toFixed(2)+'%';return v??''}
  function reportTable(model){
    const groupKey=model.groupBy,columns=(model.columns||[]).filter(c=>c.key!==groupKey),rows=model.rows||[];
    if(!columns.length)return '<p class="cv-muted">This report has no rows for the chosen dates.</p>';
    const head=`<thead><tr>${columns.map(c=>`<th class="${c.type==='money'||c.type==='integer'?'num':''}">${esc(c.label)}</th>`).join('')}</tr></thead>`;
    const line=(row,cls='')=>`<tr class="${cls}">${columns.map(c=>`<td class="${c.type==='money'||c.type==='integer'?'num':''}">${esc(cell(model,row,c))}</td>`).join('')}</tr>`;
    let body='';
    if(groupKey){const order=model.groupOrder||[...new Set(rows.map(r=>r[groupKey]))];for(const g of order){const inGroup=rows.filter(r=>String(r[groupKey])===String(g));if(!inGroup.length)continue;body+=`<tr class="cv-group"><th colspan="${columns.length}">${esc((model.groupLabels||{})[g]||humanize(g))}</th></tr>`+inGroup.map(r=>line(r)).join('');const gtRaw=(model.groupTotals||{})[g],moneyCol=columns.find(c=>c.type==='money'),gt=typeof gtRaw==='number'&&moneyCol?{[moneyCol.key]:gtRaw}:gtRaw;if(gt&&typeof gt==='object'){const totalRow={...gt};const first=columns.find(c=>c.type!=='money');if(first)totalRow[first.key]=`Total ${(model.groupLabels||{})[g]||humanize(g)}`;body+=line(totalRow,'cv-subtotal')}}}
    else body=rows.map(r=>line(r)).join('');
    for(const s of model.summaryRows||[])body+=line(s,'cv-summary-row');
    if(!rows.length)body=`<tr><td colspan="${columns.length}" class="cv-muted">No activity for the chosen dates.</td></tr>`;
    const totals=Object.entries(model.totals||{}).filter(([k,v])=>typeof v==='number'&&/Cents$/.test(k));
    return `<div class="cv-table-wrap"><table class="cv-table">${head}<tbody>${body}</tbody></table></div>${totals.length?`<dl class="cv-totals">${totals.map(([k,v])=>`<div><dt>${esc(humanize(k))}</dt><dd>${esc(money(v))}</dd></div>`).join('')}</dl>`:''}`;
  }
  async function download(model,key,format,button){
    const out=window.TeghProfessionalOutput;if(!out){alert('Downloads are still loading. Try again in a moment.');return}
    button.disabled=true;const label=button.textContent;button.textContent='Preparing…';
    try{
      const blob=format==='pdf'?await out.pdfReport(model):out.reportXlsx(model);
      const name=`${(me.company.name||'company').replace(/[^\w-]+/g,'-')}-${key}.${format}`.toLowerCase();
      const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=name;document.body.append(a);a.click();setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},1500);
      api('client-view/download',{method:'POST',json:{key,format}}).catch(()=>{});
    }catch(e){alert(e.message||'The download could not be prepared.')}finally{button.disabled=false;button.textContent=label}
  }
  boot();
})();
