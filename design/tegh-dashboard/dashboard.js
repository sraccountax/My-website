/*
 * Tegh dashboard: puts the sidebar, charts, loading placeholders and empty states together on one page.
 *
 * Data comes from one read-only request, GET dashboard/summary (see IMPLEMENTATION-PLAN.md). Set
 * window.TeghDashboardConfig.fetchSummary to a function returning that JSON; without it the page uses the sample data.
 */
(function (global) {
  'use strict';
  const C = global.TeghCharts, S = global.TeghSkeleton, cfg = global.TeghDashboardConfig || {};
  const $ = (sel, root) => (root || document).querySelector(sel);
  const el = (tag, cls, parent, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; if (parent) parent.appendChild(n); return n; };
  const money = cents => C.format.money(cents / 100);
  const moneyCents = cents => (cents < 0 ? '−' : '') + '$' + (Math.abs(cents) / 100).toLocaleString('en-CA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const dollars = cents => cents / 100;
  const monthName = ym => new Date(ym + '-15T12:00:00Z').toLocaleDateString('en-CA', { month: 'short', timeZone: 'UTC' });
  const monthLong = ym => new Date(ym + '-15T12:00:00Z').toLocaleDateString('en-CA', { month: 'long', year: 'numeric', timeZone: 'UTC' });
  const dayMonth = iso => new Date(iso + 'T12:00:00Z').toLocaleDateString('en-CA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
  const store = { get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private window */ } } };

  // ---------- Icons and illustrations (fixed markup, never user data) ----------
  const ICON = {
    alert: 'M12 8v5M12 17h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', lines: 'M4 7h16M4 12h16M4 17h10', bill: 'M6 3h9l3 3v15H6zM9 12h6M9 16h4',
    tax: 'M19 5 5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', check: 'M20 6 9 17l-5-5',
    in: 'M12 19V5M5 12l7-7 7 7', out: 'M12 5v14M5 12l7 7 7-7', send: 'M22 2 11 13M22 2l-7 20-4-9-9-4z'
  };
  const icon = (name, size) => '<svg aria-hidden="true" width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' + ICON[name] + '"></path></svg>';
  const ART = {
    bars: '<svg role="img" aria-label="Illustration: bars waiting to be filled" viewBox="0 0 200 120"><rect x="24" y="64" width="22" height="44" rx="5" fill="none" stroke="var(--teal)" stroke-dasharray="4 4"/><rect x="50" y="78" width="22" height="30" rx="5" fill="none" stroke="var(--gold)" stroke-dasharray="4 4"/><rect x="88" y="44" width="22" height="64" rx="5" fill="var(--teal)" opacity=".25"/><rect x="114" y="60" width="22" height="48" rx="5" fill="var(--gold)" opacity=".3"/><rect x="152" y="30" width="22" height="78" rx="5" fill="none" stroke="var(--teal)" stroke-dasharray="4 4" opacity=".6"/><path d="M14 108h172" stroke="var(--line)" stroke-width="1.5"/><path d="M178 10l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#d8b15b"/></svg>',
    clear: '<svg role="img" aria-label="Illustration: all clear" viewBox="0 0 160 120"><circle cx="80" cy="60" r="38" fill="var(--good-bg)" stroke="var(--good)" stroke-width="2"/><circle cx="80" cy="60" r="48" fill="none" stroke="var(--good)" stroke-opacity=".3" stroke-dasharray="3 6"/><path d="M64 61l11 11 22-24" fill="none" stroke="var(--good)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="30" r="3" fill="#d8b15b"/><rect x="128" y="22" width="7" height="7" rx="1.5" fill="var(--teal)"/><circle cx="138" cy="92" r="2.5" fill="#d8b15b"/></svg>',
    paid: '<svg role="img" aria-label="Illustration: a paid invoice" viewBox="0 0 120 120"><rect x="22" y="12" width="70" height="92" rx="10" fill="var(--chip)" stroke="var(--line)"/><path d="M34 32h40M34 44h30M34 56h36" stroke="var(--ink-3)" stroke-opacity=".35" stroke-width="3" stroke-linecap="round"/><path d="M34 84h22" stroke="#d8b15b" stroke-width="3" stroke-linecap="round"/><circle cx="86" cy="82" r="22" fill="var(--card-2)" stroke="var(--good)" stroke-width="2.5"/><text x="86" y="87" text-anchor="middle" font-size="12" font-weight="800" fill="var(--good)" font-family="Manrope, sans-serif" transform="rotate(-14 86 82)">PAID</text></svg>',
    receipt: '<svg role="img" aria-label="Illustration: a receipt being photographed" viewBox="0 0 120 120"><rect x="30" y="22" width="60" height="84" rx="12" fill="var(--chip)" stroke="var(--line)"/><path d="M46 46h28M46 58h20M46 70h24" stroke="var(--ink-3)" stroke-opacity=".35" stroke-width="3" stroke-linecap="round"/><path d="M22 40V28a6 6 0 0 1 6-6h8M98 40V28a6 6 0 0 0-6-6h-8M22 88v12a6 6 0 0 0 6 6h8M98 88v12a6 6 0 0 1-6 6h-8" fill="none" stroke="var(--teal)" stroke-width="3" stroke-linecap="round"/><path d="M46 86h22" stroke="var(--gold)" stroke-width="3" stroke-linecap="round"/></svg>',
    line: '<svg role="img" aria-label="Illustration: a balance line waiting for data" viewBox="0 0 200 120"><path d="M10 96 C50 90 70 70 100 74 S160 40 190 30" fill="none" stroke="var(--teal)" stroke-width="3" stroke-dasharray="6 7" stroke-linecap="round"/><circle cx="100" cy="74" r="6" fill="#d8b15b"/><path d="M10 108h180" stroke="var(--line)" stroke-width="1.5"/></svg>'
  };

  function emptyState(host, o) {
    const box = el('div', 'empty' + (o.compact ? ' compact' : ''), host);
    box.insertAdjacentHTML('beforeend', ART[o.art] || '');
    const text = o.compact ? el('div', '', box) : box;
    el('h3', '', text, o.title);
    el('p', '', text, o.text);
    if (o.primary || o.secondary) {
      const row = o.compact ? text : el('div', 'actions', box);
      if (o.primary) { const b = el('button', o.compact ? 'link' : 'btn primary', row, o.primary.label + (o.compact ? ' →' : '')); b.type = 'button'; b.onclick = () => go(o.primary.route); }
      if (o.secondary && !o.compact) { const b = el('button', 'btn', row, o.secondary.label); b.type = 'button'; b.onclick = () => go(o.secondary.route); }
    }
    return box;
  }

  // ---------- Navigation: in Tegh this calls the portal's own page openers ----------
  let sidebar = null;
  function go(route) {
    if (!route) return;
    if (cfg.navigate) return cfg.navigate(route);
    if (sidebar) sidebar.setActive(route);
    announce('Would open: ' + route);
  }
  const live = () => $('#dash-live');
  function announce(text) { const n = live(); if (n) { n.textContent = ''; setTimeout(() => { n.textContent = text; }, 30); } }

  // ---------- Data ----------
  const demo = { scenario: 'normal', delay: [350, 1300], fail: false };
  let summaryPromise = null;
  function loadSummary() {
    if (cfg.fetchSummary) return Promise.resolve(cfg.fetchSummary());
    const data = demo.scenario === 'fresh' ? global.TeghDemoData.fresh : global.TeghDemoData.normal;
    return new Promise((ok, bad) => setTimeout(() => (demo.fail ? bad(new Error('The server did not answer in time.')) : ok(JSON.parse(JSON.stringify(data)))), demo.delay[1]));
  }
  // Panels appear one after another as soon as the shared answer arrives (in demo mode with a small extra
  // delay each, to mimic panels that load separately).
  const panelDelay = i => (cfg.fetchSummary ? 0 : demo.delay[0] * (i % 4) * 0.35);
  const part = (i, pick) => () => summaryPromise.then(d => new Promise(ok => setTimeout(() => ok(pick(d)), panelDelay(i))));

  // ---------- Panels ----------
  const charts = [];
  const keep = c => { charts.push(c); return c; };
  let period = Number(store.get('tegh.dashboard.period')) || 12;
  let cashflow = null, cashflowMonths = [];

  function cashFlowData() {
    const m = cashflowMonths.slice(-period);
    return { labels: m.map(x => monthName(x.month)), series: [{ name: 'Money in', values: m.map(x => dollars(x.inCents)) }, { name: 'Money out', values: m.map(x => dollars(x.outCents)) }] };
  }
  function cashFlowSubtitle() {
    const m = cashflowMonths.slice(-period);
    if (!m.length) return '';
    const i = m.reduce((a, x) => a + x.inCents, 0), o = m.reduce((a, x) => a + x.outCents, 0);
    return monthLong(m[0].month) + ' to ' + monthLong(m[m.length - 1].month) + ' · in ' + money(i) + ' · out ' + money(o) + ' · net ' + (i >= o ? '+' : '') + money(i - o);
  }

  const panels = [
    { id: 'p-hero', pick: d => d, render(d, host) {
      if (d.company.isNew) return renderSetup(d.setup, host);
      const c = d.cash, main = el('div', 'hero-main', host);
      el('h2', 'label', main, 'Cash at bank');
      const k = el('div', '', main);
      keep(C.kpi(k, { label: 'Cash at bank', value: dollars(c.balanceCents), format: C.format.money }));
      const chips = el('div', 'chips sk-in', main);
      const up = c.changeCents >= 0;
      el('span', 'chip ' + (up ? 'tone-good' : 'tone-crit'), chips, (up ? '▲ Up ' : '▼ Down ') + money(Math.abs(c.changeCents)) + ' since ' + dayMonth(c.changeSince));
      el('span', 'chip', chips, c.accounts.map(a => a.name + ' ' + money(a.balanceCents)).join(' · '));
      const fc = c.weeks.filter(w => w.forecast), low = fc.reduce((a, w) => (w.balanceCents < a.balanceCents ? w : a), fc[0]);
      const p = el('p', 'note sk-in', main);
      p.append('Forecast low of ', Object.assign(document.createElement('b'), { textContent: money(low.balanceCents) }), ' in the week of ' + dayMonth(low.weekEnding) + '. ' + (c.lowNote || ''));
      const btns = el('div', 'chips sk-in', main);
      const b1 = el('button', 'btn', btns, 'Open banking'); b1.type = 'button'; b1.onclick = () => go('banking');
      const chartBox = el('div', 'hero-chart', host);
      el('span', 'label', chartBox, 'Bank balance by week: ' + c.weeks.filter(w => !w.forecast).length + ' weeks actual, ' + fc.length + ' weeks forecast');
      const lineHost = el('div', '', chartBox);
      keep(C.line(lineHost, { height: 230, labels: c.weeks.map(w => dayMonth(w.weekEnding)), forecastFrom: c.weeks.findIndex(w => w.forecast) - 1, markMin: true, minLabel: 'Low',
        series: [{ name: 'Bank balance', values: c.weeks.map(w => dollars(w.balanceCents)) }] }));
    } },
    { id: 'p-ar', pick: d => d.receivables, render(r, host) {
      if (!r.totalCents) return emptyKpi(host, '$0.00', 'No invoices yet, so nobody owes you anything.', 'Create invoice', 'new-invoice');
      keep(C.kpi(el('div', '', host), { label: 'Customers owe you', value: dollars(r.totalCents), previous: dollars(r.previousCents), compareLabel: 'vs last month', goodWhen: 'down', spark: r.trend.map(dollars) }));
      el('p', 'note sk-in', host, r.overdue30Count + ' invoices more than 30 days late, ' + money(r.overdue30Cents));
      const b = el('button', 'link sk-in', host, 'See who owes you →'); b.type = 'button'; b.onclick = () => go('aged-ar');
    } },
    { id: 'p-ap', pick: d => d.payables, render(r, host) {
      if (!r.totalCents) return emptyKpi(host, '$0.00', 'No bills yet. Upload a bill and Tegh reads the vendor, date and tax.', 'Add a bill', 'new-bill');
      keep(C.kpi(el('div', '', host), { label: 'You owe suppliers', value: dollars(r.totalCents), previous: dollars(r.previousCents), compareLabel: 'vs last month', goodWhen: 'down', spark: r.trend.map(dollars) }));
      el('p', 'note sk-in', host, r.dueThisWeekCount + ' bills due this week, ' + money(r.dueThisWeekCents));
      const b = el('button', 'link sk-in', host, 'Review bills →'); b.type = 'button'; b.onclick = () => go('bills');
    } },
    { id: 'p-pl', pick: d => d.profit, render(p, host) {
      if (!p) return emptyKpi(host, '—', 'Appears once income or expenses are recorded.', 'See how profit is worked out', 'help-profit');
      keep(C.kpi(el('div', '', host), { label: 'Profit this year', value: dollars(p.ytdCents), previous: dollars(p.previousYtdCents), compareLabel: 'vs same time last year', spark: p.trend.map(dollars) }));
      el('p', 'note sk-in', host, p.marginPct + '% of sales, up from ' + p.lastYearMarginPct + '% last year');
      const b = el('button', 'link sk-in', host, 'Open profit and loss →'); b.type = 'button'; b.onclick = () => go('pl');
    } },
    { id: 'p-cf', pick: d => d.cashFlow, render(cf, host) {
      cashflowMonths = cf.months;
      const sub = $('#cf-sub');
      if (!cf.months.length) {
        sub.textContent = 'Monthly totals from your bank lines';
        return emptyState(host, { art: 'bars', title: 'Your monthly money in and out will show here', text: 'Import a bank statement and Tegh sorts each line into money in or money out, month by month.', primary: { label: 'Import statement', route: 'bank-import' }, secondary: { label: 'Show me an example', route: 'example' } });
      }
      sub.textContent = cashFlowSubtitle();
      cashflow = keep(C.bar(el('div', '', host), Object.assign({ height: 280 }, cashFlowData())));
    } },
    { id: 'p-todo', pick: d => d.todo, render(list, host) {
      $('#todo-count').textContent = list.length ? String(list.length) : '';
      if (!list.length) return emptyState(host, { art: 'clear', title: 'Nothing needs you right now', text: 'Overdue invoices, bills coming due and bank lines to sort will appear here as soon as there are any.' });
      const ul = el('ul', 'list', host);
      list.forEach(t => {
        const b = el('button', 'todo sk-in', el('li', '', ul));
        b.type = 'button';
        b.insertAdjacentHTML('beforeend', '<span class="ico tone-' + t.severity + '">' + icon(t.icon) + '</span>');
        const tx = el('span', 't', b); el('b', '', tx, t.title); el('small', '', tx, t.detail);
        el('span', 'pill tone-' + t.severity, b, t.status);
        b.onclick = () => go(t.route);
      });
    } },
    { id: 'p-aging', pick: d => d.receivables, render(r, host) {
      if (!r.buckets.length) return emptyState(host, { compact: true, art: 'paid', title: 'Nobody owes you money', text: 'Unpaid invoices appear here, grouped by how late they are.', primary: { label: 'Create invoice', route: 'new-invoice' } });
      keep(C.hbar(el('div', '', host), { sort: false, color: 'var(--tc-1)', table: true, items: r.buckets.map(b => ({ label: b.label, value: dollars(b.cents), flag: b.followUp ? 'Follow up' : '' })) }));
      const b = el('button', 'link sk-in', host, 'Send reminders →'); b.type = 'button'; b.onclick = () => go('reminders');
    } },
    { id: 'p-exp', pick: d => d.expenses, render(x, host) {
      $('#exp-sub').textContent = x.items.length ? x.periodLabel + ', ' + money(x.items.reduce((a, i) => a + i.cents, 0)) : 'Grouped by category';
      if (!x.items.length) return emptyState(host, { compact: true, art: 'receipt', title: 'No expenses yet', text: 'Snap a receipt or upload a bill and Tegh reads the vendor, date and tax for you.', primary: { label: 'Add a receipt', route: 'new-expense' } });
      keep(C.hbar(el('div', '', host), { items: x.items.map(i => ({ label: i.label, value: dollars(i.cents), muted: !!i.other })) }));
    } },
    { id: 'p-feed', pick: d => d.activity, render(list, host) {
      const ul = el('ul', 'list', host);
      list.forEach(a => {
        const li = el('li', 'feed sk-in', ul);
        li.insertAdjacentHTML('beforeend', '<span class="ico tone-calm">' + icon(a.icon) + '</span>');
        const tx = el('span', 't', li), line = el('span', '', tx);
        el('b', '', line, a.who); line.append(' ' + a.what);
        el('small', '', tx, a.when);
        if (a.amountCents != null) el('span', 'amt', li, moneyCents(a.amountCents));
      });
      if (list.length < 3) {
        const b = el('button', 'link', host, 'Invite your accountant →'); b.type = 'button'; b.onclick = () => go('invite');
      }
    } }
  ];

  function emptyKpi(host, value, text, action, route) {
    const v = el('div', 'tc-kpi-value', host, value);
    if (value === '—') { v.style.color = 'var(--ink-3)'; v.setAttribute('aria-label', 'No figure yet'); }
    el('p', 'note', host, text);
    const b = el('button', 'link', host, action + ' →'); b.type = 'button'; b.onclick = () => go(route);
  }

  function renderSetup(setup, host) {
    const main = el('div', 'hero-main', host);
    main.style.flex = '999 1 460px';
    el('h2', '', main, 'Get your books going');
    el('p', 'note', main, setup.done + ' of ' + setup.total + ' steps done. About 15 minutes; you can stop and come back at any time.');
    const ol = el('ol', 'steps', main);
    const next = setup.steps.find(s => !s.done);
    setup.steps.forEach((s, i) => {
      const li = el('li', 'step sk-in' + (s.done ? ' is-done' : '') + (s === next ? ' is-next' : ''), ol);
      const n = el('span', 'n', li);
      if (s.done) n.insertAdjacentHTML('beforeend', icon('check', 16)); else n.textContent = String(i + 1);
      const t = el('span', 't', li); el('b', 'step-title', t, s.title); el('small', '', t, s.detail);
      if (s.action) { const b = el('button', s === next ? 'btn primary' : 'btn', li, s.action); b.type = 'button'; b.onclick = () => go(s.id); }
    });
    const art = el('div', 'hero-chart', host);
    art.insertAdjacentHTML('beforeend', ART.line);
    art.style.justifyContent = 'center';
    art.style.flex = '1 1 280px';
    art.firstElementChild.style.maxWidth = '420px';
  }

  // ---------- Loading everything ----------
  function destroyCharts() { while (charts.length) { try { charts.pop().destroy(); } catch (e) { /* already gone */ } } cashflow = null; }

  function load() {
    destroyCharts();
    $('#dash-banner').hidden = true;
    summaryPromise = loadSummary();
    summaryPromise.then(d => {
      $('#greet-name').textContent = d.company.userFirstName;
      $('#greet-sub').textContent = d.company.isNew ? 'Your company is set up. A few more steps and this page fills in with your real numbers.' : 'Here is what your books show today. Figures to ' + new Date(d.company.asOf + 'T12:00:00Z').toLocaleDateString('en-CA', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }) + ', ' + d.company.currency + '.';
      $('#greet-company').textContent = d.company.name;
      $('#period').hidden = !d.cashFlow.months.length;
    }, () => {});
    const bar = $('#dash-progress');
    bar.style.transition = 'none'; bar.style.width = '0'; bar.style.opacity = '1'; void bar.offsetWidth; bar.style.transition = '';
    announce('Loading the dashboard');
    const jobs = panels.map((p, i) => ({
      region: '#' + p.id,
      task: part(i, p.pick),
      render: (data, region) => { const host = $('.sk-content', region); host.textContent = ''; p.render(data, host); },
      renderError: (err, region, retry) => {
        const host = $('.sk-error', region); host.textContent = '';
        const box = el('div', 'error-box', host); box.setAttribute('role', 'alert');
        el('b', '', box, 'This panel could not load');
        el('p', '', box, 'Your books are safe and nothing was changed.');
        const b = el('button', 'btn', box, 'Try again'); b.type = 'button'; b.onclick = () => load();
      }
    }));
    return S.loadAll(jobs, (done, total) => {
      bar.style.width = (done / total * 100) + '%';
      if (done === total) {
        bar.style.opacity = '0';
        const failed = document.querySelectorAll('.sk-region[data-state="error"]').length;
        if (failed) { $('#dash-banner').hidden = false; announce('The dashboard could not load. Try again.'); }
        else announce('Dashboard loaded');
      }
    });
  }

  // ---------- Theme ----------
  function setTheme(mode) {
    const root = document.documentElement;
    if (mode === 'system') delete root.dataset.theme; else root.dataset.theme = mode;
    store.set('tegh.theme', mode);
    const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    const btn = $('#theme-btn');
    btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
    btn.innerHTML = dark
      ? '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
      : '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  }

  // ---------- Start ----------
  function start() {
    setTheme(store.get('tegh.theme') || cfg.theme || 'dark');
    $('#theme-btn').addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches) ? 'light' : 'dark'));

    sidebar = global.TeghSidebar.mount('#sidebar', Object.assign({
      main: '#main', active: 'dashboard', company: { name: 'Cedar & Co. (Sample)', initials: 'CC' }, user: { name: 'Priya Sharma', role: 'Owner' },
      footerCard: { eyebrow: 'Books', title: 'Up to date to 31 October', progress: 67 },
      sections: [
        { label: 'Workspace', items: [
          { id: 'dashboard', label: 'Dashboard', icon: 'dashboard' },
          { id: 'banking', label: 'Banking', icon: 'banking', badge: 14 },
          { id: 'receivables', label: 'Receivables', icon: 'receivables', children: [{ id: 'invoices', label: 'Invoices' }, { id: 'customers', label: 'Customers' }, { id: 'aged-ar', label: 'Aged receivables', badge: 3 }] },
          { id: 'payables', label: 'Payables', icon: 'payables', children: [{ id: 'bills', label: 'Bills' }, { id: 'vendors', label: 'Vendors' }, { id: 'expenses', label: 'Expenses' }] },
          { id: 'ledger', label: 'General ledger', icon: 'ledger' },
          { id: 'payroll', label: 'Payroll', icon: 'payroll' }
        ] },
        { label: 'Insights', items: [
          { id: 'reports', label: 'Reports', icon: 'reports', children: [{ id: 'pl', label: 'Profit and loss' }, { id: 'bs', label: 'Balance sheet' }, { id: 'cash', label: 'Cash flow' }] },
          { id: 'tax', label: 'Sales tax', icon: 'tax' },
          { id: 'assist', label: 'Tegh Assist', icon: 'assist' }
        ] },
        { label: 'Admin', items: [{ id: 'settings', label: 'Settings', icon: 'settings' }, { id: 'help', label: 'Help', icon: 'help' }] }
      ],
      onNavigate: (id, item, e) => { if (e) e.preventDefault(); if (id !== 'dashboard') announce('Would open: ' + item.label); },
      onSearch: () => $('#search').focus()
    }, cfg.sidebar || {}));
    $('#menu').addEventListener('click', e => sidebar.openDrawer(e.currentTarget));

    document.querySelectorAll('#period [data-months]').forEach(b => {
      b.setAttribute('aria-pressed', Number(b.dataset.months) === period ? 'true' : 'false');
      b.addEventListener('click', () => {
        period = Number(b.dataset.months);
        store.set('tegh.dashboard.period', String(period));
        document.querySelectorAll('#period [data-months]').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        if (cashflow) { cashflow.update(cashFlowData()); $('#cf-sub').textContent = cashFlowSubtitle(); }
      });
    });
    $('#retry').addEventListener('click', () => load());

    // Prototype-only controls.
    const demoBar = $('#demo');
    if (demoBar && !cfg.fetchSummary) {
      demoBar.querySelectorAll('[data-scenario]').forEach(b => b.addEventListener('click', () => {
        demo.scenario = b.dataset.scenario === 'fresh' ? 'fresh' : 'normal';
        demo.fail = b.dataset.scenario === 'error';
        demo.delay = b.dataset.scenario === 'slow' ? [900, 3200] : [350, 1300];
        demoBar.querySelectorAll('[data-scenario]').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        load();
      }));
    } else if (demoBar) demoBar.hidden = true;

    load();
  }

  global.TeghDashboard = { start, reload: load, setTheme, get sidebar() { return sidebar; } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})(window);
