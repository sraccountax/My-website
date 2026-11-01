/*
 * R178: sample answers for the Client Dashboard Demo (platform owner). Same shape as GET dashboard/summary (R177),
 * built in the browser from today's date so the demo always looks current. Made-up figures for
 * "Cedar & Co. (Sample)"; nothing here reads or changes any company.
 *
 *   TeghDashboardDemo.sample('established' | 'new')
 */
(function (global) {
  'use strict';
  const k = v => Math.round(v * 100000);                 // thousands of dollars → cents
  const pad = n => String(n).padStart(2, '0');
  const iso = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const addDays = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };

  function established() {
    const today = new Date(); today.setHours(12, 0, 0, 0);
    const asOf = iso(today), yearStart = today.getFullYear() + '-01-01';
    // Weekly bank balance: 8 weeks actual (ending today), 13 weeks forecast.
    const past = [41.2, 43.5, 42.1, 44.8, 46.0, 45.2, 47.1, 48.21];
    const future = [46.5, 44.9, 47.8, 49.6, 45.1, 42.3, 44.0, 46.8, 49.9, 51.2, 47.4, 48.9, 52.1];
    const weeks = past.map((v, i) => ({ weekEnding: iso(addDays(today, -7 * (past.length - 1 - i))), balanceCents: k(v), forecast: false }))
      .concat(future.map((v, i) => ({ weekEnding: iso(addDays(today, 7 * (i + 1))), balanceCents: k(v), forecast: true })));
    const low = weeks.filter(w => w.forecast).reduce((a, w) => (w.balanceCents < a.balanceCents ? w : a));
    // Money in and out, last 12 months ending this month.
    const ins = [31.2, 28.4, 34.1, 26.3, 29.0, 33.4, 36.2, 35.1, 38.4, 34.6, 39.2, 42.1];
    const outs = [27.4, 26.1, 29.3, 27.8, 25.2, 28.6, 30.4, 31.2, 29.5, 30.1, 32.3, 33.0];
    const months = ins.map((v, i) => { const d = new Date(today.getFullYear(), today.getMonth() - (11 - i), 15); return { month: d.getFullYear() + '-' + pad(d.getMonth() + 1), inCents: k(v), outCents: k(outs[i]) }; });
    // Profit this year: running total by month from January to this month.
    const monthsThisYear = today.getMonth() + 1;
    const monthly = [4.8, -1.5, 3.8, 4.8, 5.8, 3.9, 8.9, 4.5, 6.9, 9.1, 5.2, 6.0].slice(0, monthsThisYear);
    const trend = []; monthly.reduce((a, v) => { const t = a + v; trend.push(k(t)); return t; }, 0);
    const ytd = trend[trend.length - 1], income = Math.round(ytd / 0.15);
    const expenses = [['Wages and contractors', 11820000], ['Cost of sales', 9630000], ['Rent', 3456000], ['Software', 1248000], ['Marketing', 985000]];
    const expenseTotal = income - ytd, listed = expenses.reduce((a, x) => a + x[1], 0);
    const scale = Math.min(1, (expenseTotal * 0.9) / listed);
    const items = expenses.map(([label, cents]) => ({ label, code: '', cents: Math.round(cents * scale), other: false }));
    items.push({ label: 'Everything else', code: '', cents: expenseTotal - items.reduce((a, x) => a + x.cents, 0), other: true });
    return {
      contractVersion: 1, demo: true,
      company: { id: 'demo', name: 'Cedar & Co. (Sample)', currency: 'CAD', asOf, isNew: false, accountingBasis: 'accrual' },
      setup: { done: 4, total: 4, steps: [] },
      headline: {
        bankBalanceCents: 4821000,
        bankAccounts: [{ id: 'demo-chq', name: 'Chequing', accountType: 'bank', currency: 'CAD', bookBalanceCents: 4187000, statementBalanceCents: 0 },
          { id: 'demo-sav', name: 'Savings', accountType: 'bank', currency: 'CAD', bookBalanceCents: 634000, statementBalanceCents: 0 }],
        receivablesCents: 1294000, openInvoiceCount: 9, overdueInvoicesCents: 652000,
        payablesCents: 732000, openBillCount: 4, dueSoonBillCount: 2,
        netIncomeYtdCents: ytd, periodStart: yearStart, periodEnd: asOf, customMappedCards: []
      },
      cash: { weeks, openingCents: k(48.21), minimumProjectedCents: low.balanceCents, minimumProjectedDate: low.weekEnding, firstNegativeDate: null,
        planningNotice: 'This cash forecast is a planning estimate, not an accounting record.' },
      cashFlow: { months },
      profit: { ytdCents: ytd, incomeCents: income, expenseCents: expenseTotal, marginPct: 15, previousYtdCents: Math.round(ytd * 0.78), lastYearMarginPct: 12,
        periodStart: yearStart, trend, mapped: false },
      receivables: { agingTotalCents: 1294000, lateCount: 3, lateCents: 334000, lateAfterDays: 30, lateParties: ['Northwind Physio', 'Harbourfront Dental'],
        buckets: [['Not overdue', 642000], ['1–30 days', 318000], ['31–60 days', 194000], ['61–90 days', 82000], ['Over 90 days', 58000]].map(([label, cents], i) => ({ label, cents, followUp: i >= 3 })) },
      expenses: { periodStart: yearStart, periodEnd: asOf, totalCents: expenseTotal, items },
      todo: [
        { severity: 'warn', status: 'To sort', icon: 'lines', title: '14 bank lines to sort', detail: 'Categorise them so your figures are complete', route: 'bank-review' },
        { severity: 'crit', status: 'Overdue', icon: 'alert', title: '3 invoices more than 30 days late', detail: 'Northwind Physio, Harbourfront Dental and 1 more', amountCents: 334000, route: 'aged-ar' },
        { severity: 'warn', status: 'This week', icon: 'bill', title: '2 bills due in the next 7 days', detail: 'Metro Hydro, Bayview Property Management', amountCents: 261300, route: 'bills' },
        { severity: 'calm', status: 'Sales tax', icon: 'tax', title: 'GST/HST you owe', detail: 'Collected less paid on purchases, from your books', amountCents: 418000, route: 'tax' },
        { severity: 'good', status: 'Reconciled', icon: 'check', title: 'Bank reconciled', detail: 'Up to the end of last month', route: 'reconcile' }
      ],
      unavailable: {}, accountingWrites: 0
    };
  }

  function brandNew() {
    const today = new Date(); today.setHours(12, 0, 0, 0);
    const asOf = iso(today), yearStart = today.getFullYear() + '-01-01';
    return {
      contractVersion: 1, demo: true,
      company: { id: 'demo-new', name: 'Cedar & Co. (Sample)', currency: 'CAD', asOf, isNew: true, accountingBasis: 'accrual' },
      setup: { done: 1, total: 4, steps: [
        { id: 'company', title: 'Create your company', done: true }, { id: 'bank-import', title: 'Import a bank statement', done: false },
        { id: 'opening-balances', title: 'Add opening balances', done: false }, { id: 'first-invoice', title: 'Send your first invoice', done: false }] },
      headline: { bankBalanceCents: 0, bankAccounts: [], receivablesCents: 0, openInvoiceCount: 0, overdueInvoicesCents: 0, payablesCents: 0, openBillCount: 0, dueSoonBillCount: 0,
        netIncomeYtdCents: null, periodStart: yearStart, periodEnd: asOf, customMappedCards: [] },
      cash: null, cashFlow: { months: [] }, profit: null,
      receivables: { agingTotalCents: 0, buckets: [], lateCount: 0, lateCents: 0, lateAfterDays: 30, lateParties: [] },
      expenses: { periodStart: yearStart, periodEnd: asOf, totalCents: 0, items: [] },
      todo: [], unavailable: {}, accountingWrites: 0
    };
  }

  global.TeghDashboardDemo = { sample: kind => (kind === 'new' ? brandNew() : established()) };
})(window);
