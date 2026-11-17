// R183 development check: report balance + Download, Match and Post placement/pencil/sorting.
import {S, sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
const log = (k, v) => console.log(k.padEnd(26), typeof v === 'string' ? v : JSON.stringify(v));
const cid = process.env.CID || sql("SELECT id FROM companies WHERE name='Volume Lakeshore Inc' LIMIT 1");
const W = Number(process.env.W || 1440);
// Expected balance for the first 3 lines of the busiest account in the report period, worked out in SQL.
const acct = sql(`SELECT bank_account_id FROM bank_transactions WHERE company_id='${cid}' GROUP BY bank_account_id ORDER BY COUNT(*) DESC LIMIT 1`);
const {b, p, errs} = await open(cid, {w: W, h: 900});
try {
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Bank Transaction Report'));
  await p.waitForSelector('.r23-table', {timeout: 60000}); await p.waitForTimeout(1500);
  const period = await p.evaluate(() => [...document.querySelectorAll('[data-workspace-filter] input[type=date]')].map(x => x.value));
  const lines = sql(`SELECT id, foreign_amount_cents FROM bank_transactions WHERE company_id='${cid}' AND bank_account_id='${acct}' AND status NOT IN ('excluded','duplicate') AND transaction_date BETWEEN '${period[0]}' AND '${period[1]}' ORDER BY transaction_date,created_at,COALESCE(source_sequence,source_row_number,0),id LIMIT 3`).split('\n').map(l => l.split('\t'));
  const ledger = sql(`SELECT ledger_account_id FROM bank_accounts WHERE id='${acct}'`);
  const opening = Number(sql(`SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id='${cid}' AND jl.account_id='${ledger}' AND je.status='posted' AND je.source_type='opening_balance' AND je.entry_date<='${period[0]}'`)) + Number(sql(`SELECT COALESCE(SUM(foreign_amount_cents),0) FROM bank_transactions WHERE company_id='${cid}' AND bank_account_id='${acct}' AND status NOT IN ('excluded','duplicate') AND transaction_date<'${period[0]}'`));
  let run = opening; const want = lines.map(([, a]) => (run += Number(a)));
  const got = await p.evaluate(() => { const m = [...document.querySelectorAll('.srp-page')].pop().teghSealedReport; return {cols: m.columns.map(c => c.label), first: m.rows.slice(0, 6).map(r => [r.date, r.bankAccountId, r.balanceCents])}; });
  const gotFor = lines.map(([id]) => got.first.find(() => false));
  const modelBal = await p.evaluate(ids => { const m = [...document.querySelectorAll('.srp-page')].pop().teghSealedReport; return ids.map(id => m.rows.find(r => r.id === id)?.balanceCents ?? null); }, lines.map(l => l[0]));
  log('columns', got.cols); log('balance want', want); log('balance got', modelBal);
  log('balance header on screen', await p.evaluate(() => [...document.querySelectorAll('.r23-table thead th')].some(th => /Balance/.test(th.textContent))));
  await p.screenshot({path: `/srv/gate/ev/shots/r183-report-${W}.png`});
  // Download: the visible button, Excel and CSV.
  for (const fmt of ['xlsx', 'csv']) {
    await p.click('[data-r183-download] > summary'); await p.waitForTimeout(300);
    const dl = p.waitForEvent('download', {timeout: 40000}).then(d => d.suggestedFilename()).catch(() => 'NO DOWNLOAD');
    await p.click(`[data-r183-format=${fmt}]`); log('download ' + fmt, await dl);
  }
  // Match and Post.
  await p.evaluate(() => TeghPortal.invokeMenuAction('Banking', 'Match And Post Transactions'));
  await p.waitForSelector('[data-r28-bank-row]', {timeout: 90000}); await p.waitForTimeout(1500);
  log('add button placement', await p.evaluate(() => { const a = document.querySelector('[data-r182-add-tx]'); return {inModeRow: !!a?.closest('.r28-mode-question'), inFilter: !!a?.closest('[data-r28-filter]')}; }));
  log('pencil on first line', await p.evaluate(() => { const rows = [...document.querySelectorAll('[data-r28-bank-row]')].filter(r => r.querySelector('[data-r182-edit-desc]')).slice(0, 5); return rows.map(r => { const t = r.querySelector('.r183-desc-line > span').getBoundingClientRect(), b = r.querySelector('[data-r182-edit-desc]').getBoundingClientRect(); return Math.abs((t.top + t.height / 2) - (b.top + b.height / 2)) < 6; }); }));
  const dates = () => p.evaluate(() => [...document.querySelectorAll('[data-r28-bank-row]')].map(r => r.children[1].textContent));
  const amounts = () => p.evaluate(() => [...document.querySelectorAll('[data-r28-bank-row]')].map(r => Number(r.children[3].textContent.replace(/[^0-9.-]/g, ''))));
  const sorted = (a, f = (x, y) => x <= y) => a.every((v, i) => !i || f(a[i - 1], v));
  const d0 = await dates(); log('default chronological', sorted(d0.map(d => Date.parse(d))));
  await p.click('th[data-r183-sort=amount] button'); await p.waitForTimeout(400); const a1 = await amounts();
  await p.click('th[data-r183-sort=amount] button'); await p.waitForTimeout(400); const a2 = await amounts();
  log('amount asc / desc', [sorted(a1), sorted(a2, (x, y) => x >= y), await p.getAttribute('th[data-r183-sort=amount]', 'aria-sort')]);
  await p.click('th[data-r183-sort=date] button'); await p.waitForTimeout(400); log('date again asc', sorted((await dates()).map(d => Date.parse(d))));
  await p.screenshot({path: `/srv/gate/ev/shots/r183-match-${W}.png`});
} finally { log('page errors', errs); await b.close(); }
