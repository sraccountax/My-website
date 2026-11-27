// R183: Bank Transaction Report download and running balance; Match and Post ordering, sorting, button placement, pencil.
// R186: the pencil became the line's Actions menu (user request); BT-05 checks that button's placement instead.
// Expected balances are worked out here from the lines the test enters, never read from Tegh first.
import {S, sql, rec, save, need} from './lib.mjs';
import {open} from './e2e/base.mjs';
import fs from 'fs';

const A = 'R183';
const t = async (id, title, fn) => { try { const r = await fn(); rec(id, A, title, r.ok ? 'PASS' : 'FAIL', r.info); return r.ok; } catch (e) { rec(id, A, title, 'FAIL', String(e.message || e).slice(0, 400)); return false; } };
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const tag = Date.now().toString(36);
let n = 0; const key = p => `r183-${p}-${tag}-${++n}`.padEnd(20, 'x');
const CO = need('company', await o.call('companies', {method: 'POST', json: {businessType: 'corporation', country: 'Canada', province: 'ON', taxRegistered: false, moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', accountingBasis: 'accrual', name: `R183 Bank ${tag}`}})).company.id;
o.cid = CO;
const BANK = sql(`SELECT id FROM bank_accounts WHERE company_id='${CO}' AND account_type='bank' AND active=1 ORDER BY created_at LIMIT 1`);

// Lines: an imported statement (Sep 3, Sep 10, Sep 20) and two entered by hand (Sep 5, Sep 15), entered after the import.
const csv = `Date,Description,Amount\n2026-09-03,DEPOSIT CLIENT A ${tag},1500.00\n2026-09-10,HYDRO ${tag},-120.00\n2026-09-20,FEE ${tag},-15.00\n`;
const data = Buffer.from(csv);
const up = need('start', await o.call('imports/upload-start', {method: 'POST', json: {filename: key('csv') + '.csv', size: data.length}}));
need('chunk', await o.call('imports/upload-chunk', {method: 'POST', json: {uploadId: up.uploadId, offset: 0, data: data.toString('base64')}}));
const fin = await o.call('imports/upload-finish', {method: 'POST', json: {uploadId: up.uploadId, bankAccountId: BANK, mode: 'preview'}});
const keys = (fin.b.preview?.rows || []).filter(x => x.eligible && !x.requiresDuplicateReview).map(x => x.selectionKey);
need('approve', await o.call('operations/statement-approve', {method: 'POST', json: {previewId: fin.b.preview.id, operationKey: key('ap'), selectedKeys: keys, reviewedDuplicateKeys: [], balanceGapAcknowledged: true}}));
need('hand 1', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: '2026-09-05', description: 'Cash sale by hand', amountCents: 25000, operationKey: key('h1')}}));
need('hand 2', await o.call('bank-transactions/add', {method: 'POST', json: {bankAccountId: BANK, date: '2026-09-15', description: 'Rent by hand', amountCents: -80000, operationKey: key('h2')}}));
// Worked out by hand, in date order: +1500.00, +250.00, -120.00, -800.00, -15.00.
const EXPECT = [['2026-09-03', 150000], ['2026-09-05', 175000], ['2026-09-10', 163000], ['2026-09-15', 83000], ['2026-09-20', 81500]];
const PERIOD = {start: '2026-09-01', end: '2026-09-30'};

try {
await t('BT-01', 'Bank Transaction Report: lines in date order (hand-entered lines among the statement lines) with a running Balance after each line', async () => {
  const r = need('report', await o.call(`professional-output/v5990/bank-transactions?start=${PERIOD.start}&end=${PERIOD.end}&bankAccountId=${BANK}`));
  const m = r.output; const rows = m.rows.map(x => [x.date, x.balanceCents]);
  return {ok: JSON.stringify(rows) === JSON.stringify(EXPECT) && m.columns.some(c => c.key === 'balanceCents' && c.label === 'Balance'), info: `rows ${JSON.stringify(rows)}`};
});

await t('BT-02', 'Balance does not change with a search filter, and carries the earlier lines when the period starts later', async () => {
  const q = need('search', await o.call(`professional-output/v5990/bank-transactions?start=${PERIOD.start}&end=${PERIOD.end}&bankAccountId=${BANK}&q=hand`)).output.rows.map(x => [x.date, x.balanceCents]);
  const later = need('later', await o.call(`professional-output/v5990/bank-transactions?start=2026-09-12&end=${PERIOD.end}&bankAccountId=${BANK}`)).output.rows.map(x => [x.date, x.balanceCents]);
  return {ok: JSON.stringify(q) === JSON.stringify([EXPECT[1], EXPECT[3]]) && JSON.stringify(later) === JSON.stringify([EXPECT[3], EXPECT[4]]), info: `search "hand" ${JSON.stringify(q)}; from 12 Sep ${JSON.stringify(later)}`};
});

await t('BT-03', 'Screen: Balance column shown; Download (Excel, CSV, PDF) beside Generate Report gives the full filtered report', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report'));
    await p.waitForSelector('.r23-table', {timeout: 60000}); await p.waitForTimeout(1200);
    got.balanceHeader = await p.evaluate(() => [...document.querySelectorAll('.r23-table thead th')].some(th => /Bal/.test(th.textContent)));
    got.besideGenerate = await p.evaluate(() => !!document.querySelector('[data-workspace-filter] [data-r183-download]'));
    for (const fmt of ['xlsx', 'csv', 'pdf']) {
      await p.click('[data-r183-download] > summary'); await p.waitForTimeout(300);
      const dl = p.waitForEvent('download', {timeout: 40000}).then(async d => ({name: d.suggestedFilename(), size: fs.statSync(await d.path()).size, text: fmt === 'csv' ? fs.readFileSync(await d.path(), 'utf8') : ''})).catch(() => null);
      await p.click(`[data-r183-format=${fmt}]`); got[fmt] = await dl;
    }
  } finally { got.errs = errs; await b.close(); }
  const csvOk = !!got.csv && /Balance/.test(got.csv.text) && /Rent by hand/.test(got.csv.text) && /815\.00|815/.test(got.csv.text);
  return {ok: got.balanceHeader && got.besideGenerate && got.xlsx?.size > 1000 && csvOk && got.pdf?.size > 1000 && !got.errs.length, info: JSON.stringify({...got, csv: got.csv ? {name: got.csv.name, size: got.csv.size, balance: /Balance/.test(got.csv.text)} : null})};
});

await t('BT-04', 'When the full report cannot be prepared, Download says why with a support reference and still gives the lines shown as CSV', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.route('**/*professional-output*bank-transactions*', r => r.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({error: 'The server could not complete this request. No accounting changes were confirmed.', code: 'server_error', requestId: 'r183testreference'})}));
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report')); await p.waitForTimeout(6000);
    await p.click('[data-r183-download] > summary'); await p.waitForTimeout(300); await p.click('[data-r183-format=xlsx]');
    await p.waitForSelector('.r183-dl', {timeout: 30000}); got.dialog = await p.evaluate(() => document.querySelector('.r183-dl').innerText.replace(/\s+/g, ' '));
    const dl = p.waitForEvent('download', {timeout: 20000}).then(async d => fs.readFileSync(await d.path(), 'utf8').trim().split('\n').length).catch(() => 0);
    await p.click('[data-r183-csv]'); got.csvLines = await dl;
  } finally { got.errs = errs; await b.close(); }
  return {ok: /could not be prepared/.test(got.dialog) && /r183testreference/.test(got.dialog) && got.csvLines === 6, info: `${got.dialog?.slice(0, 200)}; CSV lines incl. header ${got.csvLines}`};
});

await t('BT-05', 'Match and Post: date order by default; header click sorts (amount up, down) and back to date; + Add transaction beside Post/Match; ✎ on the description’s first line', async () => {
  const {b, p, errs} = await open(CO, {w: 1440, h: 900}); const got = {};
  try {
    await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
    await p.waitForSelector('[data-r28-bank-row]', {timeout: 60000}); await p.waitForTimeout(1200);
    const col = i => p.evaluate(i => [...document.querySelectorAll('[data-r28-bank-row]')].map(r => r.children[i].textContent.trim()), i);
    got.dates = await col(1);
    await p.click('th[data-r183-sort=amount] button'); await p.waitForTimeout(300); got.up = (await col(3)).map(x => Number(x.replace(/[^0-9.-]/g, '')));
    await p.click('th[data-r183-sort=amount] button'); await p.waitForTimeout(300); got.down = (await col(3)).map(x => Number(x.replace(/[^0-9.-]/g, '')));
    await p.click('th[data-r183-sort=date] button'); await p.waitForTimeout(300); got.back = await col(1);
    got.add = await p.evaluate(() => !!document.querySelector('.r28-mode-question [data-r182-add-tx]') && !document.querySelector('[data-r28-filter] [data-r182-add-tx]'));
    got.pencil = await p.evaluate(() => [...document.querySelectorAll('[data-r28-bank-row]')].filter(r => r.querySelector('[data-r186-actions]')).map(r => { const t = r.querySelector('.r27-recon-description > span').getBoundingClientRect(), b = r.querySelector('[data-r186-actions]').getBoundingClientRect(); return Math.abs((t.top + t.height / 2) - (b.top + b.height / 2)) < 6; }));
  } finally { got.errs = errs; await b.close(); }
  const asc = a => a.every((v, i) => !i || a[i - 1] <= v), desc = a => a.every((v, i) => !i || a[i - 1] >= v);
  const wantDates = ['Sep 3, 2026', 'Sep 5, 2026', 'Sep 10, 2026', 'Sep 15, 2026', 'Sep 20, 2026'];
  return {ok: JSON.stringify(got.dates) === JSON.stringify(wantDates) && asc(got.up) && desc(got.down) && JSON.stringify(got.back) === JSON.stringify(wantDates) && got.add && got.pencil.length === 5 && got.pencil.every(Boolean) && !got.errs.length, info: JSON.stringify(got)};
});
} finally {
  save('r183.json');
}
