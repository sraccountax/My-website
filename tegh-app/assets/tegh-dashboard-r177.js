/*
 * R177 new dashboard. Loaded on demand (TeghLoadFeature('dashboard-r177')) only for people the platform owner has
 * turned it on for in Site & Release Settings. Data: GET dashboard/summary (one read-only request).
 * Needs tegh-charts-r177.js, tegh-skeleton-r177.js and tegh-dashboard-r177.css.
 *
 *   TeghDashboard177.render(body, ctx)
 *     ctx.api(route)        → promise of JSON (the portal's api(), with company and session headers)
 *     ctx.open(route, opts) → opens an existing Tegh page (see ROUTES in the portal)
 *     ctx.useClassic()      → switches this person back to the classic dashboard
 */
(function (global) {
  'use strict';
  const C = () => global.TeghCharts, S = () => global.TeghSkeleton;
  const el = (tag, cls, parent, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; if (parent) parent.appendChild(n); return n; };
  const store = { get(k) { try { return global.localStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { global.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } } };
  const utc = d => new Date(d + 'T12:00:00Z');
  const dayMonth = d => utc(d).toLocaleDateString('en-CA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
  const longDate = d => utc(d).toLocaleDateString('en-CA', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
  const monthShort = ym => utc(ym + '-15').toLocaleDateString('en-CA', { month: 'short', timeZone: 'UTC' });
  const monthLong = ym => utc(ym + '-15').toLocaleDateString('en-CA', { month: 'long', year: 'numeric', timeZone: 'UTC' });

  const ICON = {
    alert: 'M12 8v5M12 17h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', lines: 'M4 7h16M4 12h16M4 17h10', bill: 'M6 3h9l3 3v15H6zM9 12h6M9 16h4',
    tax: 'M19 5 5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', check: 'M20 6 9 17l-5-5'
  };
  const svgIcon = (name, size) => '<svg aria-hidden="true" width="' + (size || 17) + '" height="' + (size || 17) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' + ICON[name] + '"></path></svg>';
  // Fixed illustrations (no user data inside).
  const ART = {
    bars: '<svg role="img" aria-label="Illustration: bars waiting to be filled" viewBox="0 0 200 120"><rect x="24" y="64" width="22" height="44" rx="5" fill="none" stroke="var(--tc-1)" stroke-dasharray="4 4"/><rect x="50" y="78" width="22" height="30" rx="5" fill="none" stroke="var(--tc-2)" stroke-dasharray="4 4"/><rect x="88" y="44" width="22" height="64" rx="5" fill="var(--tc-1)" opacity=".25"/><rect x="114" y="60" width="22" height="48" rx="5" fill="var(--tc-2)" opacity=".3"/><rect x="152" y="30" width="22" height="78" rx="5" fill="none" stroke="var(--tc-1)" stroke-dasharray="4 4" opacity=".6"/><path d="M14 108h172" stroke="var(--d-line)" stroke-width="1.5"/><path d="M178 10l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#d8b15b"/></svg>',
    clear: '<svg role="img" aria-label="Illustration: all clear" viewBox="0 0 160 120"><circle cx="80" cy="60" r="38" fill="var(--d-good-bg)" stroke="var(--d-good)" stroke-width="2"/><circle cx="80" cy="60" r="48" fill="none" stroke="var(--d-good)" stroke-opacity=".3" stroke-dasharray="3 6"/><path d="M64 61l11 11 22-24" fill="none" stroke="var(--d-good)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="26" cy="30" r="3" fill="#d8b15b"/><rect x="128" y="22" width="7" height="7" rx="1.5" fill="var(--tc-1)"/><circle cx="138" cy="92" r="2.5" fill="#d8b15b"/></svg>',
    paid: '<svg role="img" aria-label="Illustration: a paid invoice" viewBox="0 0 120 120"><rect x="22" y="12" width="70" height="92" rx="10" fill="var(--d-chip)" stroke="var(--d-line)"/><path d="M34 32h40M34 44h30M34 56h36" stroke="var(--d-ink-3)" stroke-opacity=".35" stroke-width="3" stroke-linecap="round"/><path d="M34 84h22" stroke="#d8b15b" stroke-width="3" stroke-linecap="round"/><circle cx="86" cy="82" r="22" fill="var(--d-card)" stroke="var(--d-good)" stroke-width="2.5"/><text x="86" y="87" text-anchor="middle" font-size="12" font-weight="800" fill="var(--d-good)" font-family="Manrope, sans-serif" transform="rotate(-14 86 82)">PAID</text></svg>',
    receipt: '<svg role="img" aria-label="Illustration: a receipt" viewBox="0 0 120 120"><rect x="30" y="22" width="60" height="84" rx="12" fill="var(--d-chip)" stroke="var(--d-line)"/><path d="M46 46h28M46 58h20M46 70h24" stroke="var(--d-ink-3)" stroke-opacity=".35" stroke-width="3" stroke-linecap="round"/><path d="M22 40V28a6 6 0 0 1 6-6h8M98 40V28a6 6 0 0 0-6-6h-8M22 88v12a6 6 0 0 0 6 6h8M98 88v12a6 6 0 0 1-6 6h-8" fill="none" stroke="var(--tc-1)" stroke-width="3" stroke-linecap="round"/><path d="M46 86h22" stroke="var(--tc-2)" stroke-width="3" stroke-linecap="round"/></svg>',
    line: '<svg role="img" aria-label="Illustration: a balance line waiting for data" viewBox="0 0 200 120" style="max-width:360px"><path d="M10 96 C50 90 70 70 100 74 S160 40 190 30" fill="none" stroke="var(--tc-1)" stroke-width="3" stroke-dasharray="6 7" stroke-linecap="round"/><circle cx="100" cy="74" r="6" fill="#d8b15b"/><path d="M10 108h180" stroke="var(--d-line)" stroke-width="1.5"/></svg>'
  };

  // Placeholders: drawn before the data arrives, shaped like each panel.
  const SK = {
    hero: '<div style="flex:1 1 280px;display:flex;flex-direction:column;gap:12px"><span class="sk sk-line" style="width:30%"></span><span class="sk sk-number" style="width:70%;height:54px"></span><div style="display:flex;gap:8px"><span class="sk sk-pill" style="width:44%"></span><span class="sk sk-pill" style="width:38%"></span></div><div><span class="sk sk-line" style="width:92%"></span><span class="sk sk-line" style="width:64%"></span></div></div><div style="flex:999 1 400px;display:flex;flex-direction:column;gap:10px"><span class="sk sk-line" style="width:42%"></span><span class="sk sk-box sk-wave" style="height:210px"></span></div>',
    kpi: '<span class="sk sk-number" style="width:56%"></span><span class="sk sk-line" style="width:72%;margin-top:12px"></span><span class="sk sk-box" style="height:40px;margin-top:12px"></span><span class="sk sk-line" style="width:40%;margin-top:12px"></span>',
    bars: '<div style="display:flex;flex-direction:column;gap:12px"><div style="display:flex;gap:12px"><span class="sk sk-pill" style="width:90px;height:18px"></span><span class="sk sk-pill" style="width:96px;height:18px"></span></div><div class="sk-bars" style="height:240px">' +
      [62, 56, 68, 52, 58, 66, 72, 70, 76, 69, 78, 84].map((h, i) => '<div class="sk-group"><span class="sk" style="--h:' + h + '%"></span><span class="sk" style="--h:' + (h - 8 + (i % 3) * 2) + '%"></span></div>').join('') + '</div></div>',
    list: '<ul class="r177-list">' + [70, 58, 46, 64].map(w => '<li class="r177-todo" style="pointer-events:none"><span class="sk sk-square" style="width:34px;height:34px"></span><span class="t"><span class="sk sk-line" style="width:' + w + '%"></span><span class="sk sk-line" style="width:' + (w + 18) + '%"></span></span><span class="sk sk-pill" style="width:58px;height:20px"></span></li>').join('') + '</ul>',
    rows: '<div style="display:flex;flex-direction:column;gap:14px">' + [100, 54, 32, 16].map((w, i) => '<div><span class="sk sk-line" style="width:' + (28 + i * 4) + '%"></span><span class="sk" style="height:10px;margin-top:8px;width:' + w + '%"></span></div>').join('') + '</div>'
  };

  function region(parent, id, skeleton) {
    const r = el('div', 'sk-region', parent);
    r.id = id; r.dataset.state = 'idle'; r.setAttribute('aria-busy', 'true');
    const layer = el('div', 'sk-layer', r); layer.setAttribute('aria-hidden', 'true'); layer.innerHTML = skeleton;
    el('div', 'sk-content', r); el('div', 'sk-error', r);
    return r;
  }
  function card(parent, cls, title, sub) {
    const c = el('section', 'r177-card' + (cls ? ' ' + cls : ''), parent);
    if (title) { const h = el('h2', '', c, title); h.id = 'r177-h-' + Math.random().toString(36).slice(2, 8); c.setAttribute('aria-labelledby', h.id); }
    let s = null;
    if (sub !== undefined) s = el('p', 'r177-sub', c, sub);
    return { card: c, sub: s };
  }

  function render(body, ctx) {
    const charts = [];
    const keep = c => { charts.push(c); return c; };
    let summary = null, cashflow = null;
    let period = Number(store.get('tegh.dashboard177.period')) || 12;
    const fmt = currency => {
      const nf = new Intl.NumberFormat('en-CA', { style: 'currency', currency: currency || 'CAD', maximumFractionDigits: 0 });
      const nf2 = new Intl.NumberFormat('en-CA', { style: 'currency', currency: currency || 'CAD' });
      return { whole: v => (v < 0 ? '−' : '') + nf.format(Math.abs(v)), cents: c => (c < 0 ? '−' : '') + nf2.format(Math.abs(c) / 100) };
    };
    let money = fmt('CAD');
    const dollars = c => c / 100;
    const go = (route, opts) => ctx.open(route, opts || {});

    body.textContent = '';
    const root = el('div', 'r177-dash', body);
    const live = el('div', 'tegh-assist-sr', root); live.setAttribute('aria-live', 'polite');
    live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)';

    const top = el('div', 'r177-bar', root);
    const asOf = el('p', '', top, 'Getting your figures…');
    const classic = el('button', 'r177-link', top, 'Switch to the classic dashboard');
    classic.type = 'button'; classic.onclick = () => ctx.useClassic();

    const hero = card(root, 'r177-hero');
    hero.card.setAttribute('aria-label', 'Cash at bank');
    const rHero = region(hero.card, 'r177-p-hero', SK.hero);

    const kpis = el('div', 'r177-kpis', root);
    const kAr = card(kpis, '', 'Customers owe you'), kAp = card(kpis, '', 'You owe suppliers'), kPl = card(kpis, '', 'Profit this year');
    [kAr, kAp, kPl].forEach(k => { k.card.querySelector('h2').className = 'r177-label'; });
    const rAr = region(kAr.card, 'r177-p-ar', SK.kpi), rAp = region(kAp.card, 'r177-p-ap', SK.kpi), rPl = region(kPl.card, 'r177-p-pl', SK.kpi);

    const row = el('div', 'r177-row', root);
    const cf = card(row, 'wide', 'Money in and money out', 'From your bank lines');
    const cfHead = el('div', 'r177-head', cf.card);
    cf.card.insertBefore(cfHead, cf.card.firstChild);
    cfHead.appendChild(cf.card.querySelector('h2'));
    const seg = el('div', 'r177-seg', cfHead); seg.setAttribute('role', 'group'); seg.setAttribute('aria-label', 'Months shown');
    [[3, 'Quarter'], [6, '6 months'], [12, '12 months']].forEach(([n, label]) => {
      const b = el('button', '', seg, label); b.type = 'button'; b.dataset.months = String(n);
      b.setAttribute('aria-pressed', n === period ? 'true' : 'false');
      b.onclick = () => {
        period = n; store.set('tegh.dashboard177.period', String(n));
        seg.querySelectorAll('button').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
        if (cashflow) { cashflow.update(cashFlowData()); cf.sub.textContent = cashFlowSub(); }
      };
    });
    const rCf = region(cf.card, 'r177-p-cf', SK.bars);
    const todoCard = card(row, 'side', 'Needs you');
    const rTodo = region(todoCard.card, 'r177-p-todo', SK.list);

    const grid = el('div', 'r177-grid', root);
    const ag = card(grid, '', 'Who owes you', 'By days overdue'), ex = card(grid, '', 'Where the money goes', 'Expenses this year');
    const rAg = region(ag.card, 'r177-p-aging', SK.rows), rEx = region(ex.card, 'r177-p-exp', SK.rows);
    const foot = el('div', 'r177-foot', root);

    function months() { return (summary.cashFlow && summary.cashFlow.months || []).slice(-period); }
    function cashFlowData() {
      const m = months();
      return { labels: m.map(x => monthShort(x.month)), series: [{ name: 'Money in', values: m.map(x => dollars(x.inCents)) }, { name: 'Money out', values: m.map(x => dollars(x.outCents)) }] };
    }
    function cashFlowSub() {
      const m = months(); if (!m.length) return 'From your bank lines';
      const i = m.reduce((a, x) => a + x.inCents, 0), o = m.reduce((a, x) => a + x.outCents, 0);
      return monthLong(m[0].month) + ' to ' + monthLong(m[m.length - 1].month) + ' · in ' + money.cents(i) + ' · out ' + money.cents(o) + ' · net ' + (i >= o ? '+' : '') + money.cents(i - o);
    }
    function empty(host, o) {
      const box = el('div', 'r177-empty', host);
      box.insertAdjacentHTML('beforeend', ART[o.art] || '');
      el('h3', '', box, o.title); el('p', '', box, o.text);
      if (o.actions && o.actions.length) {
        const acts = el('div', 'acts', box);
        o.actions.forEach((a, i) => { const b = el('button', i === 0 ? 'r177-btn primary' : 'r177-btn', acts, a.label); b.type = 'button'; b.onclick = () => go(a.route, a.opts); });
      }
    }
    function link(host, label, route, opts) { const b = el('button', 'r177-link sk-in', host, label + ' →'); b.type = 'button'; b.onclick = () => go(route, opts); return b; }
    const notPermitted = part => summary.unavailable && summary.unavailable[part] === 'not_permitted';

    const panels = [
      [rHero, d => {
        const host = rHero.querySelector('.sk-content');
        if (d.company.isNew) return renderSetup(host, d.setup);
        const h = d.headline, main = el('div', 'r177-hero-main', host);
        el('h2', 'r177-label', main, 'Cash at bank');
        keep(C().kpi(el('div', '', main), { label: 'Cash at bank', value: dollars(h.bankBalanceCents), format: money.whole }));
        const chips = el('div', 'r177-chips sk-in', main);
        const weeks = d.cash ? d.cash.weeks : [], past = weeks.filter(w => !w.forecast);
        if (past.length > 1) {
          const ch = past[past.length - 1].balanceCents - past[0].balanceCents;
          el('span', 'r177-chip ' + (ch >= 0 ? 'r177-tone-good' : 'r177-tone-crit'), chips, (ch >= 0 ? '▲ Up ' : '▼ Down ') + money.cents(Math.abs(ch)) + ' since ' + dayMonth(past[0].weekEnding));
        }
        if (h.bankAccounts.length > 1) el('span', 'r177-chip', chips, h.bankAccounts.map(a => a.name + ' ' + money.cents(a.bookBalanceCents)).join(' · '));
        if (d.cash) {
          const p = el('p', 'r177-note sk-in', main);
          p.append('Lowest expected balance ', Object.assign(document.createElement('b'), { textContent: money.cents(d.cash.minimumProjectedCents) }), ' on ' + longDate(d.cash.minimumProjectedDate) + '.');
          if (d.cash.firstNegativeDate) p.append(' The balance is expected to go below zero on ' + longDate(d.cash.firstNegativeDate) + '.');
        } else if (notPermitted('cash')) el('p', 'r177-note', main, 'Your company role does not include reports, so the forecast is not shown.');
        const acts = el('div', 'r177-chips sk-in', main);
        const b1 = el('button', 'r177-btn', acts, 'Open banking'); b1.type = 'button'; b1.onclick = () => go('banking');
        if (d.cash) { const b2 = el('button', 'r177-link', acts, 'Plan with the forecast →'); b2.type = 'button'; b2.onclick = () => go('forecast'); }
        if (d.cash && weeks.length) {
          const box = el('div', 'r177-hero-chart', host);
          el('span', 'r177-label', box, 'Bank balance by week: ' + past.length + ' weeks actual, ' + (weeks.length - past.length) + ' weeks forecast');
          keep(C().line(el('div', '', box), { height: 230, format: C().format.moneyShort, tipFormat: v => money.whole(v), labels: weeks.map(w => dayMonth(w.weekEnding)),
            forecastFrom: past.length - 1, markMin: true, minLabel: 'Low', series: [{ name: 'Bank balance', values: weeks.map(w => dollars(w.balanceCents)) }] }));
          el('p', 'r177-small', box, 'The forecast is a planning estimate from open invoices and bills, not an accounting record.');
        }
      }],
      [rAr, d => {
        const host = rAr.querySelector('.sk-content'), h = d.headline;
        if (!h.receivablesCents && !h.openInvoiceCount) return kpiEmpty(host, money.cents(0), 'No unpaid invoices, so nobody owes you anything.', 'Create invoice', 'new-invoice');
        keep(C().kpi(el('div', '', host), { label: 'Customers owe you', value: dollars(h.receivablesCents), format: money.whole }));
        el('p', 'r177-note sk-in', host, h.openInvoiceCount + ' unpaid ' + (h.openInvoiceCount === 1 ? 'invoice' : 'invoices') + (h.overdueInvoicesCents > 0 ? ' · ' + money.cents(h.overdueInvoicesCents) + ' overdue' : ' · none overdue'));
        link(host, 'See who owes you', 'aged-ar');
      }],
      [rAp, d => {
        const host = rAp.querySelector('.sk-content'), h = d.headline;
        if (!h.payablesCents && !h.openBillCount) return kpiEmpty(host, money.cents(0), 'No unpaid bills. Enter a supplier bill when one arrives.', 'Enter a bill', 'new-bill');
        keep(C().kpi(el('div', '', host), { label: 'You owe suppliers', value: dollars(h.payablesCents), format: money.whole }));
        el('p', 'r177-note sk-in', host, h.openBillCount + ' unpaid ' + (h.openBillCount === 1 ? 'bill' : 'bills') + (h.dueSoonBillCount ? ' · ' + h.dueSoonBillCount + ' due in the next 30 days' : ''));
        link(host, 'See what you owe', 'aged-ap');
      }],
      [rPl, d => {
        const host = rPl.querySelector('.sk-content'), h = d.headline, p = d.profit;
        const title = kPl.card.querySelector('h2');
        if (h.netIncomeYtdCents === null || !p) {
          title.textContent = 'Profit this year';
          return kpiEmpty(host, '—', 'Appears once income or expenses are posted this year.', 'Open profit and loss', 'pl', { start: h.periodStart, end: h.periodEnd });
        }
        const loss = h.netIncomeYtdCents < 0;
        title.textContent = loss ? 'Loss this year' : 'Profit this year';
        const o = { label: title.textContent, value: dollars(Math.abs(h.netIncomeYtdCents)), format: money.whole, spark: (p.trend || []).map(dollars) };
        if (p.previousYtdCents !== null && !loss) Object.assign(o, { previous: dollars(p.previousYtdCents), compareLabel: 'vs same time last year' });
        keep(C().kpi(el('div', '', host), o));
        el('p', 'r177-note sk-in', host, dayMonth(h.periodStart) + ' to ' + dayMonth(h.periodEnd) + (p.marginPct !== null ? ' · ' + p.marginPct + '% of sales' + (p.lastYearMarginPct !== null ? ', ' + p.lastYearMarginPct + '% last year' : '') : '') + (p.mapped ? ' · uses your Dashboard Figures accounts' : ''));
        link(host, 'Open profit and loss', 'pl', { start: h.periodStart, end: h.periodEnd });
      }],
      [rCf, d => {
        const host = rCf.querySelector('.sk-content');
        seg.hidden = !months().length;
        cf.sub.textContent = cashFlowSub();
        if (!d.cashFlow) return unavailable(host, 'cashFlow');
        if (!months().length) return empty(host, { art: 'bars', title: 'Your monthly money in and out will show here', text: 'Import a bank statement and Tegh sorts each line into money in or money out, month by month.', actions: [{ label: 'Import statement', route: 'bank-import' }] });
        cashflow = keep(C().bar(el('div', '', host), Object.assign({ height: 270, format: C().format.moneyShort, tipFormat: v => money.whole(v) }, cashFlowData())));
      }],
      [rTodo, d => {
        const host = rTodo.querySelector('.sk-content');
        const list = d.todo || [];
        if (!list.length) return empty(host, { art: 'clear', title: 'Nothing needs you right now', text: 'Bank lines to sort, late invoices and bills coming due will appear here as soon as there are any.' });
        const ul = el('ul', 'r177-list', host);
        list.forEach(t => {
          const b = el('button', 'r177-todo sk-in', el('li', '', ul)); b.type = 'button';
          b.insertAdjacentHTML('beforeend', '<span class="r177-ico r177-tone-' + t.severity + '">' + svgIcon(t.icon) + '</span>');
          const tx = el('span', 't', b); el('b', '', tx, t.title); el('small', '', tx, t.detail + (t.amountCents != null ? ' · ' + money.cents(t.amountCents) : ''));
          el('span', 'r177-pill r177-tone-' + t.severity, b, t.status);
          b.onclick = () => go(t.route);
        });
      }],
      [rAg, d => {
        const host = rAg.querySelector('.sk-content'), r = d.receivables;
        if (!r) return unavailable(host, 'receivables');
        if (!r.buckets.length) { const box = el('div', '', host); empty(box, { art: 'paid', title: 'Nobody owes you money', text: 'Unpaid invoices appear here, grouped by how late they are.', actions: [{ label: 'Create invoice', route: 'new-invoice' }] }); return; }
        keep(C().hbar(el('div', '', host), { sort: false, color: 'var(--tc-1)', format: money.whole, tipFormat: v => money.whole(v), items: r.buckets.map(b => ({ label: b.label, value: dollars(b.cents), flag: b.followUp && b.cents > 0 ? 'Follow up' : '', muted: b.label === 'Opening balances' })) }));
        link(host, 'Open the ageing report', 'aged-ar');
      }],
      [rEx, d => {
        const host = rEx.querySelector('.sk-content'), x = d.expenses;
        if (!x) return unavailable(host, 'expenses');
        ex.sub.textContent = x.items.length ? dayMonth(x.periodStart) + ' to ' + dayMonth(x.periodEnd) + ', ' + money.cents(x.totalCents) : 'Expenses this year';
        if (!x.items.length) { empty(host, { art: 'receipt', title: 'No expenses yet', text: 'Record an expense or enter a supplier bill and it shows here by category.', actions: [{ label: 'Record an expense', route: 'new-expense' }] }); return; }
        keep(C().hbar(el('div', '', host), { sort: false, tipFormat: v => money.whole(v), items: x.items.map(i => ({ label: i.label, value: dollars(i.cents), muted: !!i.other })) }));
        link(host, 'Open profit and loss', 'pl', { start: x.periodStart, end: x.periodEnd });
      }]
    ];

    function kpiEmpty(host, value, text, action, route, opts) {
      const v = el('div', 'tc-kpi-value', host, value);
      if (value === '—') { v.style.color = 'var(--d-ink-3)'; v.setAttribute('aria-label', 'No figure yet'); }
      el('p', 'r177-note', host, text);
      link(host, action, route, opts);
    }
    function unavailable(host, part) {
      el('p', 'r177-note', host, notPermitted(part) ? 'Your company role does not include reports, so this panel is not shown.' : 'This panel could not be worked out just now. The rest of the dashboard is up to date.');
    }
    function renderSetup(host, setup) {
      const main = el('div', 'r177-hero-main', host);
      main.style.flex = '999 1 460px';
      el('h2', '', main, 'Get your books going');
      el('p', 'r177-note', main, setup.done + ' of ' + setup.total + ' steps done. You can stop and come back at any time.');
      const ol = el('ol', 'r177-steps', main);
      const detail = { 'bank-import': ['CSV, OFX or PDF from your bank. Tegh suggests a category for each line.', 'Import statement'], 'opening-balances': ['What the business had and owed on the day your books start. Your accountant can do this for you.', 'Add balances'], 'first-invoice': ['Tegh adds the sales tax and tracks who has paid.', 'Create invoice'], company: ['Done.', ''] };
      const next = setup.steps.find(s => !s.done);
      setup.steps.forEach((s, i) => {
        const li = el('li', 'r177-step sk-in' + (s.done ? ' is-done' : '') + (s === next ? ' is-next' : ''), ol);
        const n = el('span', 'n', li); if (s.done) n.insertAdjacentHTML('beforeend', svgIcon('check', 16)); else n.textContent = String(i + 1);
        const t = el('span', 't', li); el('b', '', t, s.title); el('small', '', t, s.done ? 'Done.' : (detail[s.id] || [''])[0]);
        const label = (detail[s.id] || [])[1];
        if (!s.done && label) { const b = el('button', s === next ? 'r177-btn primary' : 'r177-btn', li, label); b.type = 'button'; b.onclick = () => go(s.id === 'first-invoice' ? 'new-invoice' : s.id); }
      });
      const art = el('div', 'r177-hero-chart', host);
      art.style.cssText = 'flex:1 1 260px;justify-content:center;align-items:center';
      art.insertAdjacentHTML('beforeend', ART.line);
    }

    function load() {
      while (charts.length) { try { charts.pop().destroy(); } catch (e) { /* gone */ } }
      cashflow = null;
      const request = Promise.resolve().then(() => ctx.api('dashboard/summary')).then(d => {
        summary = d; money = fmt(d.company.currency);
        asOf.textContent = 'Figures to ' + longDate(d.company.asOf) + ' · ' + d.company.currency + (d.company.accountingBasis === 'cash' ? ' · cash basis' : '');
        foot.textContent = '';
        el('span', '', foot, 'Worked out from your posted books. Nothing on this page changes your records.');
        return d;
      });
      request.catch(err => {
        if (err && (err.code === 'dashboard_preview_not_enabled' || err.status === 403)) ctx.useClassic();
        asOf.textContent = 'Your figures could not load.';
      });
      live.textContent = 'Loading the dashboard';
      return S().loadAll(panels.map(([r, paint]) => ({
        region: r, task: () => request,
        render: data => { r.querySelector('.sk-content').textContent = ''; paint(data); },
        renderError: () => {
          const host = r.querySelector('.sk-error'); host.textContent = '';
          const box = el('div', 'r177-error', host); box.setAttribute('role', 'alert');
          el('b', '', box, 'This panel could not load');
          el('p', '', box, 'Your books are safe and nothing was changed.');
          const b = el('button', 'r177-btn', box, 'Try again'); b.type = 'button'; b.onclick = () => load();
        }
      })), (done, total) => { if (done === total) live.textContent = root.querySelector('.sk-region[data-state="error"]') ? 'The dashboard could not load. Try again.' : 'Dashboard loaded'; });
    }

    load();
    return { reload: load, destroy() { while (charts.length) { try { charts.pop().destroy(); } catch (e) { /* gone */ } } } };
  }

  global.TeghDashboard177 = { render };
})(window);
