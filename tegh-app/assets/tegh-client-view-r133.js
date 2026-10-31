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
    const capabilities=new Set(me.capabilities||['dashboard','reports']);
    $$('[data-cv-tab]').forEach(t=>t.hidden=t.dataset.cvTab!=='dashboard'&&!capabilities.has(t.dataset.cvTab));
    if(!me.reports.length)$('[data-cv-tab="reports"]').hidden=true;
    switchTab('dashboard');
  }
  function switchTab(name){
    $$('[data-cv-tab]').forEach(t=>{const on=t.dataset.cvTab===name;t.classList.toggle('is-active',on);if(on)t.setAttribute('aria-current','page');else t.removeAttribute('aria-current')});
    const titles={dashboard:`Hello ${me.clientName}`,invoices:'Invoices',documents:'Documents',requests:'Requests',approvals:'Approvals',messages:'Messages',reports:'Reports'};
    ['dashboard','invoices','documents','requests','approvals','messages','reports'].forEach(key=>{const section=$(`[data-cv-${key}]`);if(section)section.hidden=key!==name});
    $('[data-cv-title]').textContent=titles[name]||'Client dashboard';
    ({dashboard:loadDashboard,invoices:loadInvoices,documents:loadDocuments,requests:loadRequests,approvals:loadApprovals,messages:loadMessages,reports:renderReportList}[name]||loadDashboard)();
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

  /* ---------- shared client workspace ---------- */
  function workspaceError(host,error){if(error.status===401||error.status===410)return sessionEnded(error.message);host.innerHTML=`<p class="cv-error">${esc(error.message)}</p>`}
  async function loadInvoices(){
    const host=$('[data-cv-invoices]');$('[data-cv-subtitle]').textContent='Issued invoices shared for your customer record.';host.innerHTML='<p class="cv-muted">Loading invoices…</p>';
    try{const {invoices=[]}=await api('client-view/invoices');host.innerHTML=`<section class="cv-card"><div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th></tr></thead><tbody>${invoices.map(x=>`<tr><td><button type="button" class="cv-link" data-cv-invoice="${esc(x.id)}">${esc(x.number)}</button></td><td>${dateText(x.issueDate)}</td><td>${dateText(x.dueDate)}</td><td>${esc(x.status)}</td><td class="num">${money(x.totalCents,x.currency)}</td><td class="num">${money(x.balanceCents,x.currency)}</td></tr>`).join('')||'<tr><td colspan="6" class="cv-muted">No issued invoices are available.</td></tr>'}</tbody></table></div><div data-cv-invoice-detail></div></section>`;$$('[data-cv-invoice]',host).forEach(button=>button.onclick=()=>openInvoice(button.dataset.cvInvoice))}catch(error){workspaceError(host,error)}
  }
  async function openInvoice(invoiceId){const host=$('[data-cv-invoice-detail]');host.innerHTML='<p class="cv-muted">Loading invoice…</p>';try{const {invoice:x,lines=[]}=await api('client-view/invoice',{params:{invoiceId}});host.innerHTML=`<article class="cv-card"><h2>Invoice ${esc(x.number)}</h2><p>${esc(x.customer_name)} · issued ${dateText(x.issue_date)} · due ${dateText(x.due_date)}</p><div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>Description</th><th class="num">Amount</th><th class="num">Tax</th></tr></thead><tbody>${lines.map(line=>`<tr><td>${esc(line.description)}</td><td class="num">${money(line.amount_cents,x.currency)}</td><td class="num">${money(line.tax_cents,x.currency)}</td></tr>`).join('')}</tbody></table></div><dl class="cv-totals"><div><dt>Total</dt><dd>${money(x.total_cents,x.currency)}</dd></div><div><dt>Balance</dt><dd>${money(x.balance_cents,x.currency)}</dd></div></dl></article>`}catch(error){workspaceError(host,error)}}
  async function loadDocuments(){
    const host=$('[data-cv-documents]');$('[data-cv-subtitle]').textContent='Download files shared with you or upload a file to the accounting team.';host.innerHTML='<p class="cv-muted">Loading documents…</p>';
    try{const {documents=[]}=await api('client-view/documents');host.innerHTML=`<section class="cv-card"><h2>Share a document</h2><p class="cv-muted">Accepted: PDF, PNG, JPG, WebP, CSV, XLSX or text, up to 10 MB. Do not upload information the accounting team did not request.</p><form class="cv-dates" data-cv-document-form enctype="multipart/form-data"><label>Choose file<input name="file" type="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.csv,.xlsx,.txt"></label><button class="cv-btn cv-btn-small" type="submit">Upload</button></form></section><section class="cv-card"><h2>Documents</h2><div class="cv-table-wrap"><table class="cv-table"><thead><tr><th>File</th><th>Direction</th><th>Date</th><th></th></tr></thead><tbody>${documents.map(x=>`<tr><td>${esc(x.name)}<br><small>${Number(x.size||0).toLocaleString()} bytes</small></td><td>${x.direction==='client_to_team'?'You uploaded':'Shared with you'}</td><td>${dateText(x.createdAt)}</td><td><a class="cv-btn cv-btn-small cv-btn-secondary" href="/api/index.php?route=client-view/documents/download&v=5990&documentId=${encodeURIComponent(x.id)}">Download</a></td></tr>`).join('')||'<tr><td colspan="4" class="cv-muted">No documents yet.</td></tr>'}</tbody></table></div></section>`;const form=$('[data-cv-document-form]',host);form.onsubmit=async event=>{event.preventDefault();const button=$('button',form);button.disabled=true;try{const url=new URL('/api/index.php',location.origin);url.searchParams.set('route','client-view/documents/upload');url.searchParams.set('v','5990');const response=await fetch(url.pathname+url.search,{method:'POST',credentials:'same-origin',body:new FormData(form)});let result={};try{result=await response.json()}catch{}if(!response.ok)throw new Error(result.error||'Upload failed.');await loadDocuments()}catch(error){alert(error.message)}finally{button.disabled=false}}}catch(error){workspaceError(host,error)}
  }
  async function loadRequests(){const host=$('[data-cv-requests]');$('[data-cv-subtitle]').textContent='Ask a question or track a request and the accounting team’s response.';host.innerHTML='<p class="cv-muted">Loading requests…</p>';try{const {requests=[]}=await api('client-view/requests');host.innerHTML=`<section class="cv-card"><form class="cv-dates" data-cv-request-form><label>Subject<input name="subject" maxlength="180" required></label><label>Details<textarea name="details" maxlength="3000" required></textarea></label><button class="cv-btn cv-btn-small" type="submit">Send request</button></form></section>${requests.map(x=>`<article class="cv-card"><h2>${esc(x.subject)}</h2><p>${esc(x.details)}</p><small>${esc(x.status)} · ${dateText(x.createdAt)}</small>${x.response?`<p><b>Response:</b> ${esc(x.response)}</p>`:''}</article>`).join('')||'<p class="cv-muted">No requests yet.</p>'}`;$('[data-cv-request-form]',host).onsubmit=async event=>{event.preventDefault();const form=event.currentTarget,d=Object.fromEntries(new FormData(form));try{await api('client-view/requests',{method:'POST',json:d});await loadRequests()}catch(error){alert(error.message)}}}catch(error){workspaceError(host,error)}}
  async function loadMessages(){const host=$('[data-cv-messages]');$('[data-cv-subtitle]').textContent='Messages stay inside this recipient-verified workspace.';host.innerHTML='<p class="cv-muted">Loading messages…</p>';try{const {messages=[]}=await api('client-view/messages');host.innerHTML=`<section class="cv-card">${[...messages].reverse().map(x=>`<article><b>${x.sender==='client'?'You':'Accounting team'}</b><p>${esc(x.body)}</p><small>${dateText(x.createdAt)}</small></article>`).join('')||'<p class="cv-muted">No messages yet.</p>'}<form class="cv-dates" data-cv-message-form><label>Message<textarea name="body" maxlength="3000" required></textarea></label><button class="cv-btn cv-btn-small" type="submit">Send</button></form></section>`;$('[data-cv-message-form]',host).onsubmit=async event=>{event.preventDefault();const form=event.currentTarget;try{await api('client-view/messages',{method:'POST',json:{body:form.body.value}});await loadMessages()}catch(error){alert(error.message)}}}catch(error){workspaceError(host,error)}}
  async function loadApprovals(){const host=$('[data-cv-approvals]');$('[data-cv-subtitle]').textContent='Review the exact scope before acknowledging or declining.';host.innerHTML='<p class="cv-muted">Loading approvals…</p>';try{const {approvals=[],notice}=await api('client-view/approvals');host.innerHTML=`<p class="cv-summary">${esc(notice)}</p>${approvals.map(x=>`<article class="cv-card"><h2>${esc(x.title)}</h2><p>${esc(x.scope)}</p><p><b>Status:</b> ${esc(x.status)}</p>${x.status==='pending'?`<form class="cv-dates" data-cv-approval="${esc(x.id)}"><label>Optional note<textarea name="note" maxlength="2000"></textarea></label><div class="cv-row"><button class="cv-btn cv-btn-small" type="submit" value="approved">Acknowledge</button><button class="cv-btn cv-btn-small cv-btn-secondary" type="submit" value="declined">Decline</button></div></form>`:x.responseNote?`<p><b>Your note:</b> ${esc(x.responseNote)}</p>`:''}</article>`).join('')||'<p class="cv-muted">No approvals requested.</p>'}`;$$('[data-cv-approval]',host).forEach(form=>form.onsubmit=async event=>{event.preventDefault();try{await api('client-view/approvals/respond',{method:'POST',json:{approvalId:form.dataset.cvApproval,decision:event.submitter.value,note:form.note.value}});await loadApprovals()}catch(error){alert(error.message)}})}catch(error){workspaceError(host,error)}}

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
