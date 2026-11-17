/*
 * Sample answers from GET dashboard/summary, in the shape described in IMPLEMENTATION-PLAN.md.
 * Made-up figures for "Cedar & Co. (Sample)". Amounts are in cents, as everywhere in Tegh.
 * The totals agree with each other: money in minus money out for January to October equals profit this year,
 * and the ageing buckets add up to what customers owe.
 */
(function (global) {
  'use strict';
  const k = v => Math.round(v * 100000);   // thousands of dollars → cents

  const months = ['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'];
  const moneyIn = [31.2, 28.4, 34.1, 26.3, 29.0, 33.4, 36.2, 35.1, 38.4, 34.6, 39.2, 42.1];
  const moneyOut = [27.4, 26.1, 29.3, 27.8, 25.2, 28.6, 30.4, 31.2, 29.5, 30.1, 32.3, 33.0];
  const weekly = [41.2, 43.5, 42.1, 44.8, 46.0, 45.2, 47.1, 48.21, 46.5, 44.9, 47.8, 49.6, 45.1, 42.3, 44.0, 46.8, 49.9, 51.2, 47.4, 48.9, 52.1];
  const firstWeek = new Date(Date.UTC(2026, 8, 12));
  const iso = d => d.toISOString().slice(0, 10);

  const normal = {
    company: { name: 'Cedar & Co. (Sample)', currency: 'CAD', asOf: '2026-10-31', isNew: false, userFirstName: 'Priya' },
    setup: { done: 4, total: 4, steps: [] },
    cash: {
      balanceCents: 4821000, changeCents: 698000, changeSince: '2026-09-30',
      accounts: [{ name: 'Chequing', balanceCents: 4187000 }, { name: 'Savings', balanceCents: 634000 }],
      weeks: weekly.map((v, i) => ({ weekEnding: iso(new Date(firstWeek.getTime() + i * 7 * 864e5)), balanceCents: k(v), forecast: i > 7 })),
      lowNote: 'Payroll and the GST/HST payment fall in the same week.'
    },
    receivables: { totalCents: 1294000, overdue30Count: 3, overdue30Cents: 334000, trend: [10.1, 11.4, 12.2, 11.8, 13.6, 12.9, 13.3, 12.94].map(k), previousCents: 1180000,
      buckets: [['Not yet due', 642000], ['1–30 days', 318000], ['31–60 days', 194000], ['61–90 days', 82000], ['Over 90 days', 58000]].map(([label, cents], i) => ({ label, cents, followUp: i >= 3 })) },
    payables: { totalCents: 732000, dueThisWeekCount: 2, dueThisWeekCents: 261300, trend: [6.2, 7.9, 6.8, 7.5, 8.1, 6.9, 7.7, 7.32].map(k), previousCents: 810000 },
    profit: { ytdCents: 5100000, previousYtdCents: 3960000, marginPct: 15, lastYearMarginPct: 12, trend: [4.8, 3.3, 7.1, 11.9, 17.7, 21.6, 30.5, 35.0, 41.9, 51.0].map(k) },
    cashFlow: { months: months.map((m, i) => ({ month: m, inCents: k(moneyIn[i]), outCents: k(moneyOut[i]) })) },
    expenses: { periodLabel: 'January to October', items: [
      { label: 'Wages and contractors', cents: 11820000 }, { label: 'Cost of sales', cents: 9630000 }, { label: 'Rent', cents: 3456000 },
      { label: 'Software', cents: 1248000 }, { label: 'Marketing', cents: 985000 }, { label: 'Everything else', cents: 2601000, other: true }
    ] },
    todo: [
      { severity: 'crit', status: 'Overdue', icon: 'alert', title: '3 invoices more than 30 days late', detail: 'Northwind Physio and 2 others · $3,340', route: 'aged-ar' },
      { severity: 'warn', status: 'Today', icon: 'lines', title: '14 bank lines to sort', detail: 'Chequing · imported 30 October', route: 'banking' },
      { severity: 'warn', status: 'This week', icon: 'bill', title: '2 bills due', detail: 'Metro Hydro, Bayview Property · $2,613', route: 'bills' },
      { severity: 'calm', status: '31 Dec', icon: 'tax', title: 'GST/HST return for Q4', detail: 'Estimated $4,180 owing', route: 'tax' },
      { severity: 'good', status: 'On track', icon: 'check', title: 'October month-end', detail: '6 of 9 steps done', route: 'month-end' }
    ],
    activity: [
      { icon: 'in', who: 'Harbourfront Dental', what: 'paid INV-0142', amountCents: 161025, when: '2 hours ago' },
      { icon: 'lines', who: 'Priya', what: 'sorted 22 bank lines', amountCents: null, when: 'Yesterday' },
      { icon: 'send', who: 'INV-0148', what: 'sent to Maple Street Bakery', amountCents: 96050, when: 'Yesterday' },
      { icon: 'out', who: 'Northern Software Inc.', what: 'bill recorded', amountCents: 56387, when: '29 Oct' },
      { icon: 'check', who: 'September', what: 'books closed', amountCents: null, when: '15 Oct' }
    ]
  };

  const fresh = {
    company: { name: 'Cedar & Co.', currency: 'CAD', asOf: '2026-11-01', isNew: true, userFirstName: 'Priya' },
    setup: { done: 1, total: 4, steps: [
      { id: 'company', title: 'Create your company', detail: 'Done. Ontario, GST/HST registered, year end 31 December.', done: true },
      { id: 'bank-import', title: 'Import a bank statement', detail: 'CSV, OFX or PDF from any Canadian bank. Tegh suggests a category for each line.', action: 'Import statement' },
      { id: 'opening-balances', title: 'Add opening balances', detail: 'Bank, loans and anything owed on the day you start. Your accountant can do this for you.', action: 'Add balances' },
      { id: 'first-invoice', title: 'Send your first invoice', detail: 'Tegh adds the GST/HST and tracks who has paid.', action: 'Create invoice' }
    ] },
    cash: null,
    receivables: { totalCents: 0, overdue30Count: 0, overdue30Cents: 0, trend: [], buckets: [] },
    payables: { totalCents: 0, dueThisWeekCount: 0, dueThisWeekCents: 0, trend: [] },
    profit: null,
    cashFlow: { months: [] },
    expenses: { periodLabel: 'This year', items: [] },
    todo: [],
    activity: [{ icon: 'check', who: 'You', what: 'created Cedar & Co.', amountCents: null, when: 'Today' }]
  };

  global.TeghDemoData = { normal, fresh };
})(window);
