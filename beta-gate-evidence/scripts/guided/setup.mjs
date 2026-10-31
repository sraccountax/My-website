// Small company with a known statement for the Guided-mode suite.
import {S,sql,need} from '../lib.mjs';import fs from 'fs';
const o=new S();await o.login('owner@gate.test','Gate!Owner#2026pw');
const name='Guided Test Co '+Date.now().toString(36);
const co=need('co',await o.call('companies',{method:'POST',json:{name,province:'ON',businessType:'corporation',taxRegistered:true,taxNumber:'123456789RT0001',currency:'CAD',accountingBasis:'accrual',moduleMode:'both',fiscalYearEnd:'12-31',booksStartDate:'2026-01-01',chartTemplate:'default'},company:false}));
const CID=co.company?.id||co.id;o.cid=CID;const BANK=sql(`SELECT id FROM bank_accounts WHERE company_id='${CID}' AND account_type='bank' ORDER BY created_at LIMIT 1`);
const csv=`Date,Description,Amount
2026-09-02,STAPLES OFFICE SUPPLIES 4411,-113.00
2026-09-03,CLIENT DEPOSIT HARBOUR DESIGN,565.00
2026-09-04,MONTHLY ACCOUNT FEE,-25.00
2026-09-05,ROGERS WIRELESS 9001,-56.50
2026-09-06,ROGERS WIRELESS 9002,-56.50
2026-09-07,ROGERS WIRELESS 9003,-56.50
2026-09-08,OWNER PERSONAL PURCHASE,-40.00
2026-09-09,ADOBE SOFTWARE SUBSCRIPTION,-33.90
`;
const data=Buffer.from(csv);const st=need('up',await o.call('imports/upload-start',{method:'POST',json:{filename:'guided-sept.csv',size:data.length}}));
need('chunk',await o.call('imports/upload-chunk',{method:'POST',json:{uploadId:st.uploadId,offset:0,data:data.toString('base64')}}));
const pv=need('finish',await o.call('imports/upload-finish',{method:'POST',json:{uploadId:st.uploadId,bankAccountId:BANK,exchangeRateMicros:1000000,mode:'preview'}}));
const sel=(pv.preview.rows||[]).filter(x=>x.selectionKey&&x.eligible!==false&&!x.duplicate&&!x.requiresDuplicateReview).map(x=>String(x.selectionKey));
const ap=await o.call('operations/statement-approve',{method:'POST',json:{previewId:pv.preview.id,operationKey:'guided-stmt-'+CID.slice(-10),selectedKeys:sel,reviewedDuplicateKeys:[],balanceGapAcknowledged:true}});
console.log(JSON.stringify({CID,BANK,name,imported:sel.length,approve:ap.s}));
fs.writeFileSync('/srv/gate/t/guided/company.json',JSON.stringify({CID,BANK,name}));
