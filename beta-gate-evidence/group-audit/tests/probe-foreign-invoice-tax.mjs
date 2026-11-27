import {S, sql} from '/srv/gate/t/lib.mjs'; import fs from 'fs';
const st = JSON.parse(fs.readFileSync('/srv/gate/t/.group-state.json','utf8'));
const o = new S(); await o.login('owner@gate.test','Gate!Owner#2026pw'); o.cid = st.AGH;
const c = (await o.call('customers',{method:'POST',json:{name:'Berlin GmbH '+Date.now()%100000,email:'',phone:'',billingAddress:'1 Str',country:'Germany',province:''}})).b.customer.id;
const tm = (await o.call('invoice-templates')).b.templates?.[0]?.id;
const r = await o.call('invoices',{method:'POST',json:{customerId:c,issueDate:'2026-09-20',dueDate:'2026-10-20',currency:'CAD',templateId:tm,issue:true,lines:[{description:'Consulting',quantity:1,unitPriceCents:100000,taxable:true}]}});
console.log(r.s, sql(`SELECT CONCAT_WS(' ',subtotal_cents,'tax',tax_cents) FROM invoices WHERE id='${r.b.invoice.id}'`));
