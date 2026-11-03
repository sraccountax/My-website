// R181 safety baseline: capture what Canadian companies see, so a later build can be compared exactly.
// Usage: node r181-capture.mjs <outdir>. Reads only (GET requests and screen text); writes nothing to the books.
import {S, sql} from '../lib.mjs';
import {open} from '../e2e/base.mjs';
import fs from 'fs';
import crypto from 'crypto';

const OUT = process.argv[2]; fs.mkdirSync(OUT + '/api', {recursive: true}); fs.mkdirSync(OUT + '/screens', {recursive: true});
const ids = JSON.parse(fs.readFileSync('/srv/gate/t/ids.json'));
const companies = sql(`SELECT DISTINCT c.id FROM companies c JOIN company_members m ON m.company_id=c.id JOIN users u ON u.id=m.user_id
  WHERE u.email='owner@gate.test' AND c.country='Canada' AND c.archived_at IS NULL ORDER BY c.id`).split('\n').filter(Boolean);
const o = new S(); await o.login('owner@gate.test', 'Gate!Owner#2026pw');
const routes = [
  ['workspace', ''], ['workspace-summary', 'workspace/summary'], ['dashboard-summary', 'dashboard/summary'],
  ['pl', 'portal/financial-report?kind=profit-loss&start=2026-01-01&end=2026-12-31'],
  ['bs', 'portal/financial-report?kind=balance-sheet&start=2026-01-01&end=2026-10-01'],
  ['tb', 'portal/trial-balance?scope=general-ledger&start=2026-01-01&end=2026-12-31'],
  ['ar', 'portal/aging?type=receivable&asOf=2026-10-01'], ['ap', 'portal/aging?type=payable&asOf=2026-10-01'],
  ['control', 'operations/control-balances?asOf=2026-10-01'],
];
const index = {};
for (const cid of companies) {
  o.cid = cid;
  for (const [name, route] of routes) {
    const r = await o.call(route || 'workspace');
    const body = typeof r.b === 'string' ? r.b : JSON.stringify(r.b, null, 1);
    fs.writeFileSync(`${OUT}/api/${cid}.${name}.json`, body);
    index[`${cid}.${name}`] = r.s;
  }
}
fs.writeFileSync(OUT + '/api-status.json', JSON.stringify(index, null, 1));

// Screens: the visible text of the main pages for three companies with different data.
const pick = [ids.BC, ...companies.filter(c => c !== ids.BC).slice(0, 2)];
const pages = [['Dashboard', null], ['Receivables', 'Customer Invoices'], ['Receivables', 'Customer Invoice & Note Register'], ['Receivables', 'Receivable Ageing'],
  ['Payables', 'Vendor Invoices'], ['Payables', 'Payable Ageing'], ['Banking', 'Manage Bank Accounts'], ['Banking', 'Bank Transaction Report'],
  ['General ledger', 'Journal Entries'], ['General ledger', 'Day Book'], ['General ledger', 'GIFI Report'],
  ['Reports', 'Profit and Loss'], ['Reports', 'Balance Sheet'], ['Reports', 'Trial Balance'], ['Reports', 'Cash Flow'],
  ['My account', 'Currency Exchange Rates'], ['My account', 'Company Details']];
for (const cid of pick) {
  const {b, p} = await open(cid);
  for (const [mod, item] of pages) {
    try {
      await p.evaluate(([m, i]) => { document.querySelectorAll('.srp-page').forEach(x => x.dataset.capOld = '1'); if (i) TeghPortal.invokeMenuAction(m, i); else TeghPortal.invokeMenuAction('Dashboard', 'Dashboard'); }, [mod, item]);
      await p.waitForTimeout(3500);
      const text = await p.evaluate(() => { const pg = [...document.querySelectorAll('.srp-page')].filter(x => !x.dataset.capOld).pop() || document.querySelector('main'); return (pg?.innerText || '').trim(); });
      fs.writeFileSync(`${OUT}/screens/${cid}.${(mod + '-' + (item || 'home')).replace(/[^A-Za-z0-9]+/g, '-')}.txt`, text);
    } catch (e) { fs.writeFileSync(`${OUT}/screens/${cid}.${(mod + '-' + (item || 'home')).replace(/[^A-Za-z0-9]+/g, '-')}.txt`, 'CAPTURE ERROR ' + e.message.slice(0, 200)); }
  }
  await b.close();
}
const files = fs.readdirSync(OUT + '/api').length + fs.readdirSync(OUT + '/screens').length;
console.log('captured', companies.length, 'companies', files, 'files');
