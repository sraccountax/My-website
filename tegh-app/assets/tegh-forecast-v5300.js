(() => {
  'use strict';

  const BUILD = '5300';
  const WIDGETS = ['bank_balances','profit_loss','financial_position'];
  const WIDGET_LABELS = {
    cash_outlook:'Four-Week Cash Outlook',bank_balances:'Bank Balances',receivables:'Outstanding Invoices',profit_loss:'Profit and Loss',financial_position:'Financial Position / Equity'
  };
  const DISCLAIMER = 'This forecast is a management planning estimate, not an accounting record. It does not post entries, collect money or schedule payments.';
  const pageStates = new WeakMap();
  let authCache = null;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  const cents = (value) => Number.isFinite(Number(value)) ? Math.round(Number(value)) : 0;
  const money = (value, currency = 'CAD') => new Intl.NumberFormat('en-CA',{style:'currency',currency,minimumFractionDigits:2,maximumFractionDigits:2}).format(cents(value)/100);
  const date = (value) => value ? new Intl.DateTimeFormat('en-CA',{year:'numeric',month:'short',day:'numeric',timeZone:'UTC'}).format(new Date(`${String(value).slice(0,10)}T12:00:00Z`)) : '—';
  const dateTime = (value) => value ? new Intl.DateTimeFormat('en-CA',{dateStyle:'medium',timeStyle:'short'}).format(new Date(value)) : '—';
  const companyId = () => localStorage.getItem('sr-accountax-company') || authCache?.companies?.[0]?.id || '';
  const accountingMode = () => document.documentElement.dataset.bookkeepingMode === 'guided' ? 'guided' : 'full';
  const dashboardPeriodKey = () => `tegh-dashboard-profit-months:${companyId() || 'current'}`;
  const savedDashboardPeriod = () => { try { const value=localStorage.getItem(dashboardPeriodKey());return ['1','3','6'].includes(value)?value:'6'; } catch (_) { return '6'; } };

  async function auth(force = false) {
    if (authCache && !force) return authCache;
    const response = await fetch('/api/index.php?route=auth/me',{credentials:'same-origin',cache:'no-store'});
    const body = await response.json().catch(()=>({}));
    if (!response.ok) throw Error(body.error || 'Your Tegh session is unavailable.');
    authCache = body; return body;
  }

  async function api(route, options = {}) {
    const session = await auth();
    const headers = new Headers(options.headers || {});
    const id = companyId(); if (id) headers.set('X-Company-Id',id);
    const method = String(options.method || 'GET').toUpperCase();
    if (!['GET','HEAD','OPTIONS'].includes(method)) headers.set('X-CSRF-Token',session.csrfToken || '');
    let body;
    if (options.json !== undefined) { headers.set('Content-Type','application/json'); body=JSON.stringify(options.json); }
    const url = new URL('/api/index.php',location.origin); url.searchParams.set('route',route);
    Object.entries(options.params || {}).forEach(([key,value])=>{if(value !== '' && value !== null && value !== undefined)url.searchParams.set(key,String(value));});
    const response = await fetch(url.pathname+url.search,{method,headers,body,credentials:'same-origin',cache:'no-store'});
    const data = await response.json().catch(()=>({}));
    if (!response.ok) { const error=Error(data.error || `Tegh could not complete this request (${response.status}).`);error.code=data.code||'request_error';error.requestId=data.requestId||response.headers.get('X-SR-Request-ID')||'';throw error; }
    return data;
  }

  function announce(message, tone = 'success') {
    let node = $('.tegh5300-toast'); if (node) node.remove();
    node=document.createElement('div');node.className=`tegh5300-toast is-${tone}`;node.setAttribute('role',tone==='error'?'alert':'status');node.textContent=message;document.body.append(node);
    window.setTimeout(()=>node.remove(),5200);
  }

  function loadingMarkup(title) {
    return `<section class="tegh5300-loading" role="status"><span aria-hidden="true"></span><div><b>${esc(title)}</b><p>Reading one consistent snapshot of the current company books…</p></div></section>`;
  }

  function errorMarkup(error, kind) {
    return `<section class="tegh5300-error" role="alert"><b>${esc(kind)} could not load</b><p>${esc(error?.message || 'Refresh and try again.')}</p>${error?.requestId?`<small>Request reference: ${esc(error.requestId)}</small>`:''}<button type="button" class="srp-btn secondary" data-tegh5300-retry>Retry</button></section>`;
  }

  function exactTable(headers, rows, label) {
    return `<details class="tegh5300-exact"><summary>Show the numbers</summary><div class="srp-table-wrap"><table class="srp-table"><caption>${esc(label)}</caption><thead><tr>${headers.map(header=>`<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map((cell,index)=>`<td class="${index?'srp-money':''}">${esc(cell)}</td>`).join('')}</tr>`).join('')}</tbody></table></div></details>`;
  }

  function dashboardCashChart(outlook, currency) {
    const expected=outlook.weeks||[],conservative=outlook.conservativeWeeks||[];if(!expected.length)return '<p class="tegh5300-empty">No four-week source events are available.</p>';
    const max=Math.max(1,...expected.flatMap(row=>[row.inflowsCents,row.outflowsCents]).map(Math.abs));
    return `<div class="tegh5300-mini-chart" role="group" aria-label="Expected receipts and payments by week, with Expected and Conservative closing cash">
      ${expected.map((week,index)=>`<button type="button" data-dashboard-week="${week.week}" aria-label="${esc(week.label)}: cash in ${esc(money(week.inflowsCents,currency))}, cash out ${esc(money(week.outflowsCents,currency))}, Expected closing ${esc(money(week.closingCents,currency))}, Conservative closing ${esc(money(conservative[index]?.closingCents,currency))}"><span class="tegh5300-bars"><i class="in" style="height:${Math.max(3,Math.round(Math.abs(week.inflowsCents)*100/max))}%"></i><i class="out" style="height:${Math.max(3,Math.round(Math.abs(week.outflowsCents)*100/max))}%"></i></span><b>${esc(week.label)}</b><small>${esc(money(week.closingCents,currency))}</small></button>`).join('')}
    </div>${exactTable(['Week','Dates','Cash in','Cash out','Expected closing','Conservative closing'],expected.map((week,index)=>[week.label,`${date(week.periodStart)}–${date(week.periodEnd)}`,money(week.inflowsCents,currency),money(week.outflowsCents,currency),money(week.closingCents,currency),money(conservative[index]?.closingCents,currency)]),'Four-week cash outlook exact values')}`;
  }

  function dashboardBankChart(bank, currency) {
    const rows=bank.accounts||[],max=Math.max(1,...rows.map(row=>Math.abs(row.balanceCents)));
    return `<div class="tegh5300-horizontal-chart">${rows.map(row=>`<button type="button" data-bank-account="${esc(row.accountId)}" aria-label="Open ${esc(row.name)} ledger, ${esc(money(row.balanceCents,currency))}"><span><b>${esc(row.code)} · ${esc(row.name)}</b><em>${esc(money(row.balanceCents,currency))}</em></span><i class="${row.balanceCents<0?'negative':''}" style="--bar:${Math.max(2,Math.round(Math.abs(row.balanceCents)*100/max))}%"></i></button>`).join('')||'<p class="tegh5300-empty">No active posted bank or cash ledger account was found.</p>'}</div>${exactTable(['Account','Book balance'],rows.map(row=>[`${row.code} · ${row.name}`,money(row.balanceCents,currency)]),'Posted bank and cash balances')}`;
  }

  function dashboardReceivables(data, currency) {
    const ar=data.receivables||{},ap=data.payables||{},weeks=ar.weeklyExpectedReceiptsCents||[],max=Math.max(1,...weeks.map(Math.abs));
    return `<div class="tegh5300-dual-kpis"><span><small>Customer invoices open</small><b>${esc(money(ar.totalOutstandingCents,currency))}</b><em>${esc(money(ar.overdueCents,currency))} overdue</em></span><span><small>Vendor invoices open</small><b>${esc(money(ap.totalOutstandingCents,currency))}</b><em>${esc(money(ap.overdueCents,currency))} overdue</em></span></div><div class="tegh5300-receipt-bars" aria-label="Expected customer receipts by forecast week">${weeks.map((value,index)=>`<button type="button" data-ar-week="${index+1}" aria-label="Week ${index+1} expected customer receipts ${esc(money(value,currency))}"><i style="height:${Math.max(3,Math.round(Math.abs(value)*100/max))}%"></i><b>W${index+1}</b><small>${esc(money(value,currency))}</small></button>`).join('')}</div>${exactTable(['Measure','Amount'],[['Customer invoices open',money(ar.totalOutstandingCents,currency)],['Customer overdue',money(ar.overdueCents,currency)],['Customer receipts after four weeks',money(ar.afterFourWeeksCents,currency)],['Vendor invoices open',money(ap.totalOutstandingCents,currency)],['Vendor overdue',money(ap.overdueCents,currency)],['Vendor payments after four weeks',money(ap.afterFourWeeksCents,currency)]],'Outstanding documents and expected payments')}`;
  }

  function signedProfitLossChart(months,currency,period,availableWidth=520){
    if(!months.length)return '<p class="tegh5300-empty">No posted Profit and Loss activity is available for this period.</p>';
    const values=months.flatMap(row=>[cents(row.incomeCents),cents(row.expenseCents),cents(row.netIncomeCents)]),low=Math.min(0,...values),high=Math.max(0,...values),span=Math.max(1,high-low),width=Math.max(280,Math.round(availableWidth)),height=220,left=52,right=16,top=16,bottom=38,plotHeight=height-top-bottom,y=value=>top+(high-value)*plotHeight/span,zero=y(0),step=(width-left-right)/Math.max(1,months.length),bar=Math.min(20,step*.29),compact=value=>(value<0?'−$':'$')+new Intl.NumberFormat('en-CA',{notation:'compact',maximumFractionDigits:1}).format(Math.abs(value)/100),niceStep=(()=>{const raw=Math.max(1,span/4),power=10**Math.floor(Math.log10(raw)),n=raw/power;return (n<=1?1:n<=2?2:n<=2.5?2.5:n<=5?5:10)*power})(),ticks=[];for(let value=Math.ceil(low/niceStep)*niceStep;value<=high+.5;value+=niceStep)ticks.push(Math.round(value));
    return `<svg class="tegh-r17-profit-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Posted income and expenses, ${esc(period)}"><title>Profit and loss for ${esc(period)}. Negative reversals appear below zero and zero values have zero-height bars.</title>${ticks.map(value=>`<line x1="${left}" x2="${width-right}" y1="${y(value)}" y2="${y(value)}" stroke="currentColor" opacity=".12"/><text x="${left-7}" y="${y(value)+4}" text-anchor="end">${esc(compact(value))}</text>`).join('')}<line x1="${left}" x2="${width-right}" y1="${zero}" y2="${zero}" stroke="currentColor" opacity=".38"/>${months.map((row,index)=>{const x=left+step*(index+.5);return `<g tabindex="0" role="button" data-pl-start="${esc(row.periodStart)}" data-pl-end="${esc(row.periodEnd)}" aria-label="Open ${esc(row.label)} Profit and Loss. Income ${esc(money(row.incomeCents,currency))}, expenses ${esc(money(row.expenseCents,currency))}, net income ${esc(money(row.netIncomeCents,currency))}">${`<rect class="r19-profit-hit" x="${left+step*index}" y="0" width="${step}" height="${height}" fill="transparent" pointer-events="all"/>`}${['incomeCents','expenseCents'].map((key,series)=>{const value=cents(row[key]);return `<rect class="${series?'expense':'income'}" x="${x+(series?2:-bar-2)}" y="${Math.min(y(value),zero)}" width="${bar}" height="${Math.abs(y(value)-zero)}" rx="2"><title>${esc(row.label)} · ${series?'Expenses':'Income'} ${esc(money(value,currency))}</title></rect>`}).join('')}<text x="${x}" y="${height-13}" text-anchor="middle">${esc(row.label.replace(/\s\d{4}$/,''))}</text></g>`}).join('')}<polyline class="r19-net-line" fill="none" stroke="currentColor" stroke-width="2" pointer-events="none" points="${months.map((row,index)=>`${left+step*(index+.5)},${y(cents(row.netIncomeCents))}`).join(' ')}"/></svg>`;
  }

  function dashboardProfitLoss(data, currency) {
    const months=data.profitAndLoss?.months||[],period=months.length?`${date(months[0].periodStart)} – ${date(months.at(-1).periodEnd)}`:'No posted period';
    return `<div class="tegh5300-signed-profit-chart">${signedProfitLossChart(months,currency,period)}</div>${exactTable(['Month','Income','Expenses','Net income'],months.map(row=>[row.label,money(row.incomeCents,currency),money(row.expenseCents,currency),money(row.netIncomeCents,currency)]),'Six-month Profit and Loss')}`;
  }

  function dashboardPosition(position, currency) {
    const values=[position.assetsCents,position.liabilitiesCents,position.equityCents],max=Math.max(1,...values.map(value=>Math.abs(value)));
    return `<div class="tegh5300-position"><span><small>Assets</small><b>${esc(money(position.assetsCents,currency))}</b><i style="--bar:${Math.max(2,Math.round(Math.abs(position.assetsCents)*100/max))}%"></i></span><span><small>Liabilities</small><b>${esc(money(position.liabilitiesCents,currency))}</b><i style="--bar:${Math.max(2,Math.round(Math.abs(position.liabilitiesCents)*100/max))}%"></i></span><span><small>Equity</small><b>${esc(money(position.equityCents,currency))}</b><i style="--bar:${Math.max(2,Math.round(Math.abs(position.equityCents)*100/max))}%"></i></span></div><p class="tegh5300-equation ${position.balanced?'balanced':'unbalanced'}">${position.balanced?'✓ Balance equation agrees':'! Balance equation difference'} · ${esc(money(position.differenceCents,currency))}</p>${exactTable(['Measure','Amount'],[['Assets',money(position.assetsCents,currency)],['Liabilities',money(position.liabilitiesCents,currency)],['Equity',money(position.equityCents,currency)],['Liabilities + equity',money(position.liabilitiesEquityCents,currency)],['Difference',money(position.differenceCents,currency)]],'Financial position exact values')}`;
  }

  function widgetMarkup(id, data) {
    const currency=data.currency||'CAD',content=id==='cash_outlook'?dashboardCashChart(data.fourWeekCashOutlook||{},currency):id==='bank_balances'?dashboardBankChart(data.bankBalances||{},currency):id==='receivables'?dashboardReceivables(data,currency):id==='profit_loss'?dashboardProfitLoss(data,currency):dashboardPosition(data.financialPosition||{},currency);
    const meta=id==='cash_outlook'?`${date(data.fourWeekCashOutlook?.forecastStart)}–${date(data.fourWeekCashOutlook?.forecastEnd)}`:id==='bank_balances'?`Book balances as of ${date(data.asOf)}`:id==='profit_loss'?'Six months ending at the as-of month':`As of ${date(data.asOf)}`;
    return `<article class="tegh5300-widget" data-dashboard-widget="${id}"><header><div><span>${esc(meta)}</span><h2>${esc(WIDGET_LABELS[id])}</h2></div><button type="button" data-widget-open="${id}">Open <span aria-hidden="true">→</span></button></header>${content}</article>`;
  }

  function wireDashboard(host, data, ids, state) {
    $$('[data-widget-open]',host).forEach(button=>button.onclick=()=>{const id=button.dataset.widgetOpen,portal=window.TeghPortal;if(id==='cash_outlook')portal?.openFinancialAnalyst?.();else if(id==='bank_balances')portal?.openFinancialAccounts?.();else if(id==='receivables')portal?.openAgeing?.('receivable','Dashboard');else if(id==='profit_loss')portal?.openFinancialReport?.('profit-loss');else portal?.openFinancialReport?.('balance-sheet');});
    $$('[data-bank-account]',host).forEach(button=>button.onclick=()=>window.TeghPortal?.openGlAccountLedger?.(button.dataset.bankAccount,'Dashboard'));
    $$('[data-pl-start]',host).forEach(button=>{const open=()=>window.TeghPortal?.openFinancialReport?.('profit-loss',{start:button.dataset.plStart,end:button.dataset.plEnd});button.onclick=open;button.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open()}}});
    $$('[data-ar-week]',host).forEach(button=>button.onclick=()=>window.TeghPortal?.openAgeing?.('receivable','Dashboard'));
    $$('[data-dashboard-week]',host).forEach(button=>button.onclick=()=>window.TeghPortal?.openFinancialAnalyst?.());
    $$('[data-dashboard-customize]',state.page||host).forEach(customize=>{customize.disabled=false;customize.title='Choose and arrange your dashboard widgets';customize.onclick=()=>openWidgetManager(state,ids)});
  }

  function renderCompactDashboard(data, page) {
    const body=$('.r17-dashboard',page);if(!body)return;
    const currency=data.currency||'CAD',equity=data.financialPosition?.equityCents;
    const periodSelect=$('[data-dashboard-months]',body);if(!periodSelect)return;
    if(periodSelect.dataset.dashboardPeriodReady!=='1')periodSelect.value=savedDashboardPeriod();
    periodSelect.disabled=false;periodSelect.setAttribute('aria-busy','false');periodSelect.removeAttribute('title');
    const card=$('[data-dashboard-kpi="3"]',body);
    // R19's fourth card is the authoritative YTD result from workspace/summary; never replace it with equity.
    if(card&&!body.classList.contains('r19-dashboard')){$('strong',card).textContent=Number.isFinite(equity)?money(equity,currency):'Unavailable';$('small',card).textContent=`As of ${date(data.asOf)}`;}
    const months=(data.profitAndLoss?.months||[]).slice(-Number(periodSelect.value||6));
    const total=key=>months.reduce((sum,row)=>sum+cents(row[key]),0);
    const totals=[total('incomeCents'),total('expenseCents'),total('netIncomeCents')];
    $$('[data-dashboard-profit-total]',body).forEach((node,i)=>{node.textContent=money(totals[i],currency);node.classList.toggle('negative',totals[i]<0)});
    const period=months.length?`${date(months[0].periodStart)} – ${date(months.at(-1).periodEnd)}`:'No posted period';
    $('[data-dashboard-profit-period]',body).textContent=period;
    // R125: one plain sentence under the totals, so the chart answers "am I making money?".
    const plain=$('[data-dashboard-profit-plain]',body);if(plain){const net=totals[2],span=months.length===1?'this month':`these ${months.length} months`;plain.textContent=!months.length?'No income or expenses recorded yet.':net>=0?`Over ${span} you earned ${money(net,currency)} more than you spent.`:`Over ${span} you spent ${money(-net,currency)} more than you earned.`;plain.dataset.tone=net>=0?'good':'bad';}
    const netLabel=$('[data-dashboard-profit-total="2"]',body)?.previousElementSibling;if(netLabel)netLabel.textContent=totals[2]<0?'Loss':'Profit';
    $('[data-sites-profit]',body).onclick=()=>window.TeghPortal?.openFinancialReport?.('profit-loss',months.length?{start:months[0].periodStart,end:months.at(-1).periodEnd}:{});
    const chart=$('[data-dashboard-profit-chart]',body);chart.innerHTML=signedProfitLossChart(months,currency,period,chart.clientWidth||520)+exactTable(['Month','Income','Expenses','Net profit'],months.map(row=>[row.label,money(row.incomeCents,currency),money(row.expenseCents,currency),money(row.netIncomeCents,currency)]),'Posted monthly figures');chart.dataset.ready='true';chart.removeAttribute('role');
    chart._r19Data=data;if(!chart._r19Observer&&typeof ResizeObserver==='function'){chart._r19Width=chart.clientWidth;chart._r19Observer=new ResizeObserver(()=>{if(!page.isConnected){chart._r19Observer.disconnect();chart._r19Observer=null;return}const width=chart.clientWidth;if(width>0&&Math.abs(width-chart._r19Width)>1){chart._r19Width=width;requestAnimationFrame(()=>{if(page.isConnected)renderCompactDashboard(chart._r19Data,page)})}});chart._r19Observer.observe(chart);}
    $$('[data-pl-start]',body).forEach(button=>{const open=()=>window.TeghPortal?.openFinancialReport?.('profit-loss',{start:button.dataset.plStart,end:button.dataset.plEnd});button.onclick=open;button.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open()}}});
    periodSelect.dataset.dashboardPeriodReady='1';
    page.dataset.r17DashboardReady='1';
  }

  function renderDashboard(host, data, ids, state) {
    host.innerHTML=`<div class="tegh5300-dashboard-grid">${ids.map(id=>widgetMarkup(id,data)).join('')||'<section class="tegh5300-empty-dashboard"><p>Choose the financial summaries you want to see.</p><button type="button" class="srp-btn" data-dashboard-customize>Customize dashboard</button></section>'}</div>`;
    wireDashboard(host,data,ids,state);
    renderCompactDashboard(data,state.page);
  }

  function openWidgetManager(state, current) {
    const selected=[...current];const scrim=document.createElement('div');scrim.className='tegh5300-dialog-scrim';scrim.innerHTML=`<section class="tegh5300-dialog" role="dialog" aria-modal="true" aria-labelledby="tegh5300-widget-title"><header><div><small>Per user · company · accounting mode</small><h2 id="tegh5300-widget-title">Customize Dashboard Widgets</h2></div><button type="button" data-close aria-label="Close">×</button></header><div data-widget-list></div><footer><button type="button" class="srp-btn secondary" data-defaults>Restore Defaults</button><span></span><button type="button" class="srp-btn secondary" data-cancel>Cancel</button><button type="button" class="srp-btn" data-save>Save Dashboard</button></footer></section>`;document.body.append(scrim);document.body.classList.add('srp-modal-open');const list=$('[data-widget-list]',scrim);
    const render=()=>{list.innerHTML=WIDGETS.map(id=>{const index=selected.indexOf(id),checked=index>=0;return `<article><label><input type="checkbox" data-widget-check="${id}" ${checked?'checked':''}><span><b>${esc(WIDGET_LABELS[id])}</b><small>${checked?`Position ${index+1}`:'Hidden'}</small></span></label><div><button type="button" data-up="${id}" aria-label="Move ${esc(WIDGET_LABELS[id])} up" ${!checked||index===0?'disabled':''}>↑</button><button type="button" data-down="${id}" aria-label="Move ${esc(WIDGET_LABELS[id])} down" ${!checked||index===selected.length-1?'disabled':''}>↓</button></div></article>`}).join('');$$('[data-widget-check]',list).forEach(input=>input.onchange=()=>{const id=input.dataset.widgetCheck,index=selected.indexOf(id);if(input.checked&&index<0)selected.push(id);if(!input.checked&&index>=0)selected.splice(index,1);render()});$$('[data-up],[data-down]',list).forEach(button=>button.onclick=()=>{const id=button.dataset.up||button.dataset.down,index=selected.indexOf(id),next=index+(button.dataset.up!==undefined?-1:1);if(index<0||next<0||next>=selected.length)return;[selected[index],selected[next]]=[selected[next],selected[index]];render()});};
    const close=()=>{scrim.remove();document.body.classList.remove('srp-modal-open')};$('[data-close]',scrim).onclick=close;$('[data-cancel]',scrim).onclick=close;scrim.onclick=event=>{if(event.target===scrim)close()};scrim.onkeydown=event=>{if(event.key==='Escape'){event.preventDefault();close()}};$('[data-defaults]',scrim).onclick=()=>{selected.splice(0,selected.length,...WIDGETS);render()};$('[data-save]',scrim).onclick=async event=>{const button=event.currentTarget;button.disabled=true;try{if(state.companyId!==companyId()||state.mode!==accountingMode())throw Error('The company or bookkeeping mode changed. Close this dialog and open it again.');await api('agent/interface-preferences',{method:'PUT',json:{dashboardWidgetMode:accountingMode(),widgetIds:selected}});state.widgetIds=[...selected];renderDashboard(state.host,state.data,state.widgetIds,state);close();announce('Dashboard widget choices saved.')}catch(error){button.disabled=false;announce(error.message,'error')}};render();requestAnimationFrame(()=>$('[data-close]',scrim)?.focus());
  }

  async function enhanceDashboard(page) {
    if(!page||page.dataset.teghRenderState==='loading')return;
    const body=$('.srp-page-body',page),slot=$('[data-dashboard-slot="widgets"]',body);if(!slot)return;
    const previous=pageStates.get(page);if(previous?.host?.parentElement===slot)return;
    const originCompany=companyId(),originMode=accountingMode(),host=document.createElement('section');host.className='tegh5300-dashboard';host.innerHTML=loadingMarkup('Loading dashboard widgets');slot.replaceChildren(host);
    const state={host,page,data:null,widgetIds:[...WIDGETS],companyId:originCompany,mode:originMode};pageStates.set(page,state);page.dataset.tegh5300Dashboard='loading';
    const current=()=>page.isConnected&&host.parentElement===slot&&pageStates.get(page)===state&&companyId()===originCompany&&accountingMode()===originMode;
    const periodSelect=$('[data-dashboard-months]',body);
    if(periodSelect){periodSelect.value=savedDashboardPeriod();periodSelect.dataset.dashboardPeriodReady='1';periodSelect.disabled=false;periodSelect.setAttribute('aria-busy','false');periodSelect.removeAttribute('title')}
    const preferenceRequest=api('agent/interface-preferences').catch(()=>null);
    try{
      const result=await api('financial-analysis/dashboard');
      if(!current())return;
      if(!result.dashboard)throw Error('The dashboard snapshot is unavailable.');
      state.data=result.dashboard;
      renderDashboard(host,state.data,state.widgetIds,state);
      if(!body.classList.contains('r17-dashboard'))$('.srp-sites-stat-grid',body)?.remove();
      $('.srp-sites-dashboard-grid',body)?.remove();
      page.dataset.tegh5300Dashboard='ready';
      void preferenceRequest.then(prefs=>{if(!current()||!prefs)return;const saved=prefs.dashboardWidgets?.[originMode]?.widgetIds;if(!Array.isArray(saved))return;state.widgetIds=saved.filter(id=>WIDGETS.includes(id));renderDashboard(host,state.data,state.widgetIds,state)});
    }
    catch(error){if(!current())return;host.innerHTML=errorMarkup(error,'Dashboard widgets');const primary=$('[data-dashboard-profit-chart]',page);if(primary){primary.innerHTML=errorMarkup(error,'Posted income and expenses');primary.dataset.ready='error';$('[data-tegh5300-retry]',primary)?.addEventListener('click',()=>{host.remove();enhanceDashboard(page)})}$('[data-tegh5300-retry]',host)?.addEventListener('click',()=>{host.remove();enhanceDashboard(page)});page.dataset.tegh5300Dashboard='error';const customize=$('[data-dashboard-customize]',page);if(customize){customize.disabled=false;customize.title='Reload dashboard options';customize.onclick=()=>{host.remove();enhanceDashboard(page)}}}
  }

  function forecastChart(forecast) {
    const expected=forecast.expected?.weeks||[],conservative=forecast.conservative?.weeks||[],currency=forecast.currency||'CAD';if(!expected.length)return '<p class="tegh5300-empty">No forecast points are available.</p>';
    const width=820,height=330,left=74,right=28,top=28,bottom=66,plotWidth=width-left-right,plotHeight=height-top-bottom;const values=[0,forecast.minimumCashThresholdCents||0,...expected.flatMap(row=>[row.inflowsCents,row.outflowsCents,row.closingCents]),...conservative.map(row=>row.closingCents)];const min=Math.min(...values),max=Math.max(...values),span=Math.max(1,max-min);const y=value=>top+(max-value)*plotHeight/span,x=index=>left+(index+.5)*plotWidth/4,barWidth=30;const expectedLine=expected.map((row,index)=>`${x(index)},${y(row.closingCents)}`).join(' '),conservativeLine=conservative.map((row,index)=>`${x(index)},${y(row.closingCents)}`).join(' '),zeroY=y(0),thresholdY=y(forecast.minimumCashThresholdCents||0);
    return `<figure class="tegh5300-forecast-chart"><figcaption><div><b>Four-week cash movement</b><span>Bars: cash in / cash out · Lines: Expected / Conservative closing cash</span></div><div><span class="in">Cash in</span><span class="out">Cash out</span><span class="expected">Expected</span><span class="conservative">Conservative</span></div></figcaption><svg viewBox="0 0 ${width} ${height}" role="group" aria-label="Four-week cash forecast from ${esc(money(min,currency))} to ${esc(money(max,currency))}"><line class="zero" x1="${left}" x2="${width-right}" y1="${zeroY}" y2="${zeroY}"></line>${Number(forecast.minimumCashThresholdCents||0)!==0?`<line class="minimum" x1="${left}" x2="${width-right}" y1="${thresholdY}" y2="${thresholdY}"><title>Minimum cash ${esc(money(forecast.minimumCashThresholdCents,currency))}</title></line>`:''}${expected.map((week,index)=>{const groupX=x(index),inY=y(week.inflowsCents),outY=y(week.outflowsCents);return `<g tabindex="0" role="button" data-forecast-week="${week.week}" aria-label="Open ${esc(week.label)} sources"><rect class="in" x="${groupX-barWidth-3}" y="${inY}" width="${barWidth}" height="${Math.max(2,zeroY-inY)}"><title>${esc(week.label)} cash in ${esc(money(week.inflowsCents,currency))}</title></rect><rect class="out" x="${groupX+3}" y="${outY}" width="${barWidth}" height="${Math.max(2,zeroY-outY)}"><title>${esc(week.label)} cash out ${esc(money(week.outflowsCents,currency))}</title></rect><text x="${groupX}" y="${height-28}" text-anchor="middle">W${week.week}</text></g>`}).join('')}<polyline class="expected-line" points="${expectedLine}"></polyline><polyline class="conservative-line" points="${conservativeLine}"></polyline>${expected.map((week,index)=>`<circle class="expected-point" tabindex="0" cx="${x(index)}" cy="${y(week.closingCents)}" r="6"><title>${esc(week.label)} Expected closing ${esc(money(week.closingCents,currency))}</title></circle><circle class="conservative-point" tabindex="0" cx="${x(index)}" cy="${y(conservative[index]?.closingCents)}" r="6"><title>${esc(week.label)} Conservative closing ${esc(money(conservative[index]?.closingCents,currency))}</title></circle>`).join('')}<text x="${left-10}" y="${top+5}" text-anchor="end">${esc(money(max,currency))}</text><text x="${left-10}" y="${height-bottom+5}" text-anchor="end">${esc(money(min,currency))}</text></svg></figure>`;
  }

  function weekTable(forecast, series) {
    const currency=forecast.currency||'CAD',expected=forecast.expected?.weeks||[],conservative=forecast.conservative?.weeks||[];const showExpected=series!=='conservative',showConservative=series!=='expected';
    return `<div class="srp-table-wrap tegh5300-week-table"><table class="srp-table"><caption>Exact four-week forecast values</caption><thead><tr><th>Week and exact dates</th>${showExpected?'<th>Expected opening</th><th>Cash in</th><th>Cash out</th><th>Expected closing</th>':''}${showConservative?'<th>Conservative opening</th><th>Cash in</th><th>Cash out</th><th>Conservative closing</th>':''}</tr></thead><tbody>${expected.map((row,index)=>{const con=conservative[index]||{};return `<tr tabindex="0" data-week-row="${row.week}"><th><b>${esc(row.label)}</b><small>${esc(date(row.periodStart))}–${esc(date(row.periodEnd))}</small></th>${showExpected?`<td>${esc(money(row.openingCents,currency))}</td><td>${esc(money(row.inflowsCents,currency))}</td><td>${esc(money(row.outflowsCents,currency))}</td><td><b>${esc(money(row.closingCents,currency))}</b></td>`:''}${showConservative?`<td>${esc(money(con.openingCents,currency))}</td><td>${esc(money(con.inflowsCents,currency))}</td><td>${esc(money(con.outflowsCents,currency))}</td><td><b>${esc(money(con.closingCents,currency))}</b></td>`:''}</tr>`}).join('')}</tbody></table></div>`;
  }

  function sourceRows(state) {
    const forecast=state.forecast,currency=forecast.currency||'CAD',filter=state.week||'all',start=filter==='all'?'':forecast.weeks?.[Number(filter)-1]?.periodStart,end=filter==='all'?'':forecast.weeks?.[Number(filter)-1]?.periodEnd;return (forecast.events||[]).filter(event=>{if(filter==='after')return event.expected.projectedDate>forecast.forecastEnd||event.conservative.projectedDate>forecast.forecastEnd;if(filter==='all')return true;return (event.expected.projectedDate>=start&&event.expected.projectedDate<=end)||(event.conservative.projectedDate>=start&&event.conservative.projectedDate<=end)}).map(event=>`<tr data-source-row data-source-type="${esc(event.sourceType)}" data-source-id="${esc(event.sourceId)}" data-source-revision="${esc(event.sourceRevisionHash)}" data-open-cents="${cents(event.openBalanceCents||event.baseAmountCents)}" data-direction="${esc(event.direction)}"><td><b>${esc(event.reference)}</b><small>${esc(event.party||event.sourceType.replaceAll('_',' '))}</small></td><td>${esc(event.sourceType.replaceAll('_',' '))}<small>${esc(money(event.baseAmountCents,currency))}${event.sourceCurrency&&event.sourceCurrency!==currency?` · ${esc(money(event.sourceAmountCents,event.sourceCurrency))}`:''}</small></td><td>${['invoice','bill'].includes(event.sourceType)?`<label class="tegh5300-inline-check"><input type="checkbox" data-source-expected ${event.expected.included?'checked':''}> Expected</label><input type="date" data-source-expected-date value="${esc(event.expected.projectedDate)}" aria-label="Expected date for ${esc(event.reference)}">`:`<b>${event.expected.included?'Included':'Not included'}</b><small>${esc(date(event.expected.projectedDate))}</small>`}</td><td>${['invoice','bill'].includes(event.sourceType)?`<label class="tegh5300-inline-check"><input type="checkbox" data-source-conservative ${event.conservative.included?'checked':''}> Conservative</label><input type="date" data-source-conservative-date value="${esc(event.conservative.projectedDate)}" aria-label="Conservative date for ${esc(event.reference)}">`:`<b>${event.conservative.included?'Included':'Not included'}</b><small>${esc(date(event.conservative.projectedDate))}</small>`}</td><td><b>${Number(event.expected.confidenceBps||0)/100}%</b><small>${esc(event.expected.dateBasis.replaceAll('_',' '))}</small><button type="button" data-source-explain aria-expanded="false">Why?</button><p hidden>${esc(event.expected.explanation)} ${esc(event.conservative.explanation)}</p></td></tr>`).join('')||'<tr><td colspan="5" class="srp-empty">No sources match this view.</td></tr>';
  }

  function forecastMarkup(state) {
    const f=state.forecast,currency=f.currency||'CAD',expected=f.expected||{},conservative=f.conservative||{},minimum=Math.min(Number(expected.minimumProjectedCashCents||0),Number(conservative.minimumProjectedCashCents||0)),firstNegative=conservative.firstNegativeCashDate||expected.firstNegativeCashDate;
    return `<section class="tegh5300-forecast-head"><div><span>28-day management planning · ${esc(f.company?.name||'Current company')}</span><h2>Four-Week Cash Forecast</h2><p>${esc(currency)} · As of ${esc(date(f.asOf))} · ${esc(date(f.forecastStart))}–${esc(date(f.forecastEnd))}</p></div><div class="tegh5300-boundaries"><span>Read-only snapshot</span><span>Accounting writes: ${Number(f.accountingWrites||0)}</span><span>Provider attempts: ${Number(f.providerAttempts||0)}</span></div></section><section class="tegh5300-forecast-controls"><label>As of<input type="date" data-forecast-asof value="${esc(f.asOf)}" max="${esc(f.asOf)}"></label><label>Display<select data-forecast-series><option value="both" ${state.series==='both'?'selected':''}>Show Both</option><option value="expected" ${state.series==='expected'?'selected':''}>Expected</option><option value="conservative" ${state.series==='conservative'?'selected':''}>Conservative</option></select></label><label>Conservative receipts %<input type="number" data-forecast-realization min="0" max="100" step="1" value="${Number(state.assumptions.conservativeReceiptRealizationBps||8000)/100}"></label><label>Safety delay days<input type="number" data-forecast-safety min="0" max="90" step="1" value="${Number(state.assumptions.receiptSafetyDelayDays||0)}"></label><label>Minimum cash<input type="number" data-forecast-minimum min="0" step="0.01" value="${(Number(state.assumptions.minimumCashCents||0)/100).toFixed(2)}"></label><div><button type="button" class="srp-btn" data-forecast-apply>Update Forecast</button><button type="button" class="srp-btn secondary" data-forecast-refresh>Refresh from Current Books</button><button type="button" class="srp-btn secondary" data-forecast-defaults>Restore Defaults</button><button type="button" class="srp-btn secondary" data-forecast-save>Save as Scenario</button></div></section><section class="tegh5300-kpis"><article><small>Opening Cash</small><b>${esc(money(f.openingCashCents,currency))}</b><span>${f.openingCashAccounts?.length||0} posted cash account${f.openingCashAccounts?.length===1?'':'s'}</span></article><article><small>Expected Cash In</small><b>${esc(money(expected.totalInflowsCents,currency))}</b><span>Receipts and explicit inflows</span></article><article><small>Expected Cash Out</small><b>${esc(money(expected.totalOutflowsCents,currency))}</b><span>Payables, charges and explicit outflows</span></article><article><small>Expected Closing Cash</small><b>${esc(money(expected.closingCashCents,currency))}</b><span>Conservative ${esc(money(conservative.closingCashCents,currency))}</span></article><article class="${minimum<Number(f.minimumCashThresholdCents||0)?'warning':''}"><small>Minimum Cash</small><b>${esc(money(minimum,currency))}</b><span>${firstNegative?`First negative ${esc(date(firstNegative))}`:`Threshold ${esc(money(f.minimumCashThresholdCents,currency))}`}</span></article></section>${forecastChart(f)}${weekTable(f,state.series)}<section class="tegh5300-forecast-lower"><details class="tegh5300-sources" open><summary>Source events and planning overrides <span>${f.events?.length||0}</span></summary><div class="tegh5300-source-toolbar"><label>Show<select data-event-week><option value="all" ${state.week==='all'?'selected':''}>All source events</option>${[1,2,3,4].map(week=>`<option value="${week}" ${String(state.week)===String(week)?'selected':''}>Week ${week}</option>`).join('')}<option value="after" ${state.week==='after'?'selected':''}>After four weeks</option></select></label><p>Invoice and vendor-invoice dates are editable planning overrides. Nothing is posted or scheduled.</p></div><div class="srp-table-wrap"><table class="srp-table"><thead><tr><th>Source</th><th>Booked amount</th><th>Expected</th><th>Conservative</th><th>Evidence</th></tr></thead><tbody data-source-body>${sourceRows(state)}</tbody></table></div></details><details class="tegh5300-assumptions"><summary>Assumptions and scenario</summary><dl><div><dt>Mode</dt><dd>Four-week / 28 days</dd></div><div><dt>Conservative receipt realization</dt><dd>${Number(state.assumptions.conservativeReceiptRealizationBps||0)/100}%</dd></div><div><dt>Receipt confidence threshold</dt><dd>${Number(state.assumptions.receiptConfidenceThresholdBps||0)/100}%</dd></div><div><dt>Receipt safety delay</dt><dd>${Number(state.assumptions.receiptSafetyDelayDays||0)} days</dd></div><div><dt>Charge threshold</dt><dd>${Number(state.assumptions.recurringChargeConfidenceThresholdBps||0)/100}%</dd></div><div><dt>Scenario</dt><dd>${esc(f.scenario?.name||'Current-book baseline')}</dd></div></dl><p>Expected includes every qualifying receipt and payable. Conservative keeps vendor outflows at 100%, includes qualifying receipts at the selected realization percentage and later robust date, and uses higher/earlier recurring-charge evidence.</p></details><details class="tegh5300-quality" ${f.quality?.status!=='complete'?'open':''}><summary>Data quality · ${esc(f.quality?.status||'unknown')}</summary><div class="tegh5300-quality-grid"><article><h3>Coverage</h3><ul>${Object.entries(f.quality?.coverage||{}).map(([key,value])=>`<li><span>${esc(key.replace(/([A-Z])/g,' $1'))}</span><b>${Number(value)}</b></li>`).join('')}</ul></article><article><h3>Warnings</h3><ul>${(f.quality?.warnings||[]).map(warning=>`<li>${esc(warning)}</li>`).join('')||'<li>No forecast warnings.</li>'}</ul></article><article><h3>Excluded evidence</h3><ul>${(f.quality?.excludedSources||[]).slice(0,50).map(row=>`<li><b>${esc(row.reference)}</b> · ${esc(row.reason)}</li>`).join('')||'<li>No qualifying source was excluded.</li>'}</ul></article></div>${f.recurringCharges?.possible?.length?`<h3>Possible Charges—Not Included</h3><ul>${f.recurringCharges.possible.map(row=>`<li><b>${esc(row.signature||'Possible charge')}</b> · ${esc(row.reason||'Below inclusion policy')} · ${Number(row.confidenceBps||0)/100}%</li>`).join('')}</ul>`:''}</details></section><footer class="tegh5300-evidence"><span>Evidence hash <code>${esc(String(f.sourceRevisionHash||'').slice(0,24))}</code></span><span>Generated ${esc(dateTime(f.generatedAt))}</span><span>Day 28 included · Day 29 excluded</span></footer><p class="tegh5300-disclaimer">${esc(f.planningDisclaimer||DISCLAIMER)}</p>`;
  }

  function syncSourceOverride(state,row) {
    const type=row.dataset.sourceType,id=row.dataset.sourceId;if(!['invoice','bill'].includes(type))return;const expected=$('[data-source-expected]',row),conservative=$('[data-source-conservative]',row);if(type==='invoice'&&!expected.checked)conservative.checked=false;if(type==='bill'){conservative.checked=expected.checked;}
    const open=cents(row.dataset.openCents),realization=Number(state.assumptions.conservativeReceiptRealizationBps||8000);const override={sourceType:type,sourceId:id,includeExpected:expected.checked,includeConservative:conservative.checked,expectedDate:$('[data-source-expected-date]',row).value,conservativeDate:$('[data-source-conservative-date]',row).value,expectedAmountCents:expected.checked?open:0,conservativeAmountCents:conservative.checked?(type==='invoice'?Math.round(open*realization/10000):open):0,sourceRevisionHash:row.dataset.sourceRevision,reason:'Reviewed in the four-week cash forecast'};const list=Array.isArray(state.assumptions.sourceOverrides)?state.assumptions.sourceOverrides:[],index=list.findIndex(item=>item.sourceType===type&&item.sourceId===id);if(index>=0)list[index]=override;else list.push(override);state.assumptions.sourceOverrides=list;
  }

  function wireForecast(state) {
    const host=state.host;
    $('[data-forecast-series]',host).onchange=event=>{state.series=event.target.value;renderForecast(state)};
    $('[data-event-week]',host).onchange=event=>{state.week=event.target.value;$('[data-source-body]',host).innerHTML=sourceRows(state);wireSourceRows(state)};
    const openWeek=week=>{state.week=String(week);const select=$('[data-event-week]',host);if(select)select.value=state.week;$('[data-source-body]',host).innerHTML=sourceRows(state);wireSourceRows(state);const details=$('.tegh5300-sources',host);details.open=true;details.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});};
    $$('[data-forecast-week],[data-week-row]',host).forEach(node=>{node.onclick=()=>openWeek(node.dataset.forecastWeek||node.dataset.weekRow);node.onkeydown=event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();node.click()}}});
    $('[data-forecast-apply]',host).onclick=()=>{state.asOf=$('[data-forecast-asof]',host).value;state.assumptions.conservativeReceiptRealizationBps=Math.round(Number($('[data-forecast-realization]',host).value)*100);state.assumptions.receiptSafetyDelayDays=Number($('[data-forecast-safety]',host).value);state.assumptions.minimumCashCents=Math.round(Number($('[data-forecast-minimum]',host).value)*100);loadForecast(state,true)};
    $('[data-forecast-refresh]',host).onclick=()=>{authCache=null;state.assumptions.sourceOverrides=[];loadForecast(state,false)};
    $('[data-forecast-defaults]',host).onclick=()=>{state.assumptions={forecastMode:'four_week',conservativeReceiptRealizationBps:8000,receiptConfidenceThresholdBps:7500,receiptSafetyDelayDays:2,recurringChargeConfidenceThresholdBps:7500,includeMediumConfidenceChargesConservative:false,minimumCashCents:0,sourceOverrides:[]};loadForecast(state,true)};
    $('[data-forecast-save]',host).onclick=()=>openScenarioSave(state);
    wireSourceRows(state);
  }

  function wireSourceRows(state) {
    $$('[data-source-row]',state.host).forEach(row=>{$$('input',row).forEach(input=>input.onchange=()=>syncSourceOverride(state,row));$('[data-source-explain]',row)?.addEventListener('click',event=>{const paragraph=event.currentTarget.nextElementSibling,open=paragraph.hidden;paragraph.hidden=!open;event.currentTarget.setAttribute('aria-expanded',String(open))})});
  }

  function renderForecast(state) { state.host.innerHTML=forecastMarkup(state);wireForecast(state); }

  async function loadForecast(state, preview = false) {
    state.host.innerHTML=loadingMarkup(preview?'Recalculating the four-week forecast':'Refreshing current-book evidence');
    try{let result;if(preview)result=await api('financial-analysis/four-week-preview',{method:'POST',json:{asOf:state.asOf,scenarioId:state.scenarioId||null,assumptions:state.assumptions}});else result=await api('financial-analysis/four-week',{params:{asOf:state.asOf||'',scenarioId:state.scenarioId||''}});if(!state.page.isConnected)return;state.forecast=result.forecast;state.asOf=result.forecast.asOf;state.assumptions={...(result.forecast.assumptions||{}),sourceOverrides:[...(result.forecast.assumptions?.sourceOverrides||[])]};renderForecast(state);}
    catch(error){state.host.innerHTML=errorMarkup(error,'Four-Week Cash Forecast');$('[data-tegh5300-retry]',state.host)?.addEventListener('click',()=>loadForecast(state,preview));}
  }

  function openScenarioSave(state) {
    const f=state.forecast,scrim=document.createElement('div');scrim.className='tegh5300-dialog-scrim';scrim.innerHTML=`<form class="tegh5300-dialog" role="dialog" aria-modal="true" aria-labelledby="tegh5300-scenario-title"><header><div><small>Revisioned planning assumptions only</small><h2 id="tegh5300-scenario-title">Save Four-Week Scenario</h2></div><button type="button" data-close aria-label="Close">×</button></header><label>Scenario name<input name="name" maxlength="160" required placeholder="Example: Conservative collections case"></label><label>Description<textarea name="description" maxlength="1000" rows="3">Four-week cash planning case as of ${esc(f.asOf)}.</textarea></label><footer><span>No invoice, bill, payment or journal will be changed.</span><button type="button" class="srp-btn secondary" data-cancel>Cancel</button><button type="submit" class="srp-btn">Save Scenario</button></footer></form>`;document.body.append(scrim);document.body.classList.add('srp-modal-open');const form=$('form',scrim),close=()=>{scrim.remove();document.body.classList.remove('srp-modal-open')};$('[data-close]',form).onclick=close;$('[data-cancel]',form).onclick=close;scrim.onclick=event=>{if(event.target===scrim)close()};form.onsubmit=async event=>{event.preventDefault();if(!form.reportValidity())return;const values=Object.fromEntries(new FormData(form)),button=$('[type="submit"]',form);button.disabled=true;try{const result=await api('financial-analysis/scenarios',{method:'POST',json:{name:values.name,description:values.description,horizonStart:f.forecastStart,horizonEnd:f.forecastEnd,baseKind:'open_items',sourceBudgetId:null,status:'draft',assumptions:{...state.assumptions,forecastMode:'four_week'},adjustments:[]}});state.scenarioId=result.scenario.id;close();announce('Four-week scenario saved with revisioned assumptions.');await loadForecast(state,false)}catch(error){button.disabled=false;announce(error.message,'error')}};requestAnimationFrame(()=>$('[name="name"]',form)?.focus());
  }

  async function enhanceFinancialAnalyst(page) {
    if(!page||page.dataset.tegh5300Forecast||$('.r15-financial',page))return;const panel=$('[data-fa-panel="forecast"]',page);if(!panel)return;page.dataset.tegh5300Forecast='loading';const scenarioSelect=$('[data-fa-scenario-select]',panel),scenarioId=scenarioSelect?.value||'';const legacy=document.createElement('details');legacy.className='tegh5300-long-range';legacy.innerHTML='<summary>Longer-range and budget forecast</summary><div data-long-range-content></div>';const content=$('[data-long-range-content]',legacy);while(panel.firstChild)content.append(panel.firstChild);const host=document.createElement('section');host.className='tegh5300-forecast';host.innerHTML=loadingMarkup('Preparing the four-week forecast');panel.append(host,legacy);const state={page,panel,host,legacy,scenarioId,asOf:'',series:'both',week:'all',assumptions:{},forecast:null};pageStates.set(page,state);await loadForecast(state,false);page.dataset.tegh5300Forecast='ready';
  }

  function enhanceCurrentPage(page) {
    const id=page?.dataset.srpPage;if(id==='dashboard')enhanceDashboard(page);if(id==='financial-analyst')enhanceFinancialAnalyst(page);
  }

  window.addEventListener('tegh:page-rendered',event=>enhanceCurrentPage(event.detail?.page));
  // Bind once at the document boundary. The dashboard body is replaced while
  // its authoritative snapshot loads, so a listener attached to an earlier
  // select can disappear before the user makes a choice.
  document.addEventListener('change',event=>{
    const select=event.target?.closest?.('[data-dashboard-months]');if(!select)return;
    const page=select.closest('.srp-page[data-srp-page="dashboard"]'),state=page&&pageStates.get(page);
    if(select.disabled)return;
    try{localStorage.setItem(dashboardPeriodKey(),select.value)}catch(_){}
    if(state?.data)renderCompactDashboard(state.data,page);
  });
  let resizeFrame=0;window.addEventListener('resize',()=>{cancelAnimationFrame(resizeFrame);resizeFrame=requestAnimationFrame(()=>{const page=$('.srp-page[data-srp-page="dashboard"]'),state=page&&pageStates.get(page);if(state?.data&&page.isConnected)renderCompactDashboard(state.data,page)})});
  const boot=()=>{const page=$('.srp-page[data-srp-page="dashboard"],.srp-page[data-srp-page="financial-analyst"]');if(page)enhanceCurrentPage(page)};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>window.setTimeout(boot,0),{once:true});else window.setTimeout(boot,0);
})();
