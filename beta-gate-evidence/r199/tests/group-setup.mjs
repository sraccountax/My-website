// Group audit (not part of the gate): build a synthetic multinational group.
// Aurora Group Holdings (parent, ON, CAD), Aurora West Branch (BC, CAD), Aurora Quebec (QC, CAD),
// Aurora India (associate, KA, INR). All names, PANs and GSTINs are synthetic.
import {S, sql, need} from './lib.mjs';
import fs from 'fs';
const ST = '/srv/gate/t/.group-state.json';
const PW = 'Gate!Owner#2026pw';
const o = new S(); await o.login('owner@gate.test', PW);
const release = async () => (await o.call('site-admin', {company: false})).b.groups.release;
const cur = await release();
need('india mode', await o.call('site-admin/save', {method: 'POST', company: false, json: {groups: {release: {value: {...cur.value, indiaEdition: 'everyone', editIssuedInvoices: 'everyone'}, revision: cur.revision}}, reason: 'Group audit'}}));
const tag = Date.now().toString(36).slice(-5);
const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const gstin = b => { let s = 0; for (let i = 0; i < 14; i++) { const p = CH.indexOf(b[i]) * (i % 2 === 0 ? 1 : 2); s += Math.floor(p / 36) + p % 36; } return b + CH[(36 - s % 36) % 36]; };
const ca = async (name, province, bn) => need(name, await o.call('companies', {method: 'POST', company: false, json: {businessType: 'corporation', country: 'Canada', province, taxRegistered: true, taxNumber: bn + 'RT0001', moduleMode: 'accounting', fiscalYearEnd: '12-31', booksStartDate: '2026-01-01', chartTemplate: 'default', currency: 'CAD', name, accountingBasis: 'accrual'}})).company.id;
const st = {tag};
st.AGH = await ca(`Aurora Group Holdings ${tag}`, 'ON', '711111111');
st.AWB = await ca(`Aurora West Branch ${tag}`, 'BC', '722222222');
st.AQC = await ca(`Aurora Québec ${tag}`, 'QC', '733333333');
const PAN = 'AURPA' + String(Date.now() % 10000).padStart(4, '0') + 'K';
const oi = new S(); await oi.login('owner@gate.test', PW, {edition: 'IN'});
st.AIN = need('india', await oi.call('companies', {method: 'POST', company: false, json: {name: `Aurora India ${tag}`, legalName: 'Aurora India Private Limited', constitution: 'private_limited', state: 'KA', pin: '560001', building: '4 Lavelle Road', street: 'Lavelle Road', locality: 'Shanthala Nagar', city: 'Bengaluru', district: 'Bengaluru Urban',
  financialYearStart: '2026-04-01', financialYearEnd: '2027-03-31', timezone: 'Asia/Kolkata', bankAccountType: 'current', coaMode: 'default', pan: PAN, gstin: gstin('29' + PAN + '1Z')}}), [201]).company.id;
const as = id => { o.cid = id; return o; };
const acct = (co, code) => sql(`SELECT id FROM accounts WHERE company_id='${co}' AND code='${code}'`);
const mk = async (co, code, name, type) => { as(co); const r = await o.call('accounts', {method: 'POST', json: {code, name, type}}); return r.s < 300 ? acct(co, code) : 'ERR ' + r.s + ' ' + JSON.stringify(r.b).slice(0, 120); };
st.acc = {
  AGH: {dueWB: await mk(st.AGH, '1250', 'Due from Aurora West (IC)', 'asset'), dueQC: await mk(st.AGH, '1251', 'Due from Aurora Québec (IC)', 'asset'), loanWB: await mk(st.AGH, '1260', 'Loan to Aurora West (IC)', 'asset'),
    feeInc: await mk(st.AGH, '4500', 'Management fees (IC)', 'income'), intInc: await mk(st.AGH, '4510', 'Interest income (IC)', 'income')},
  AWB: {dueH: await mk(st.AWB, '2250', 'Due to Aurora Holdings (IC)', 'liability'), loanH: await mk(st.AWB, '2260', 'Loan from Aurora Holdings (IC)', 'liability'),
    feeExp: await mk(st.AWB, '6500', 'Management fees (IC)', 'expense'), intExp: await mk(st.AWB, '6510', 'Interest expense (IC)', 'expense')},
  AQC: {feeExp: await mk(st.AQC, '6500', 'Management fees (IC)', 'expense')},
};
// Intercompany parties: each company sees the others as ordinary customers/vendors.
const cust = async (co, name, province, country = 'Canada') => need('cust ' + name, await as(co).call('customers', {method: 'POST', json: {name, email: '', phone: '', billingAddress: '100 Group Way', country, province}})).customer.id;
const vend = async (co, name, province, country = 'Canada', currency = 'CAD') => { const b = need('vend ' + name, await as(co).call('vendors', {method: 'POST', json: {name, email: '', address: '100 Group Way', defaultTermsDays: 30, currency, country, province}})); return b.vendor?.id || b.id; };
st.p = {
  AGH_custWB: await cust(st.AGH, 'Aurora West Branch Ltd', 'BC'), AGH_custQC: await cust(st.AGH, 'Aurora Québec Inc.', 'QC'), AGH_custIN: await cust(st.AGH, 'Aurora India Pvt Ltd', '', 'India'),
  AWB_vendH: await vend(st.AWB, 'Aurora Group Holdings Inc.', 'ON'), AQC_vendH: await vend(st.AQC, 'Aurora Group Holdings Inc.', 'ON'),
};
fs.writeFileSync(ST, JSON.stringify(st, null, 1));
console.log(JSON.stringify(st, null, 1));
