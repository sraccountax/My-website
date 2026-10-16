import {sql,check,save} from './lib.mjs';const A='REC';
const nid=sql(`SELECT id FROM companies WHERE name='Gate Test BC Ltd (Restored copy)' ORDER BY created_at DESC LIMIT 1`);const src=sql(`SELECT id FROM companies WHERE name='Gate Test BC Ltd'`);
const tl=c=>sql(`SELECT GROUP_CONCAT(CONCAT(t.code,':',c.name,':',c.rate_mpct,':',IFNULL(sa.code,'-'),'/',IFNULL(pa.code,'-'),':',t.status) ORDER BY t.code,c.sort_order) FROM tax_codes t JOIN tax_code_components c ON c.tax_code_id=t.id LEFT JOIN accounts sa ON sa.id=c.sales_account_id LEFT JOIN accounts pa ON pa.id=c.purchase_account_id WHERE t.company_id='${c}'`);
check('BR-06',A,'Tax codes, rates and GL mapping identical after restore',tl(nid),tl(src));
check('BR-07',A,'No cross-company account references after restore',sql(`SELECT COUNT(*) FROM tax_code_components c JOIN tax_codes t ON t.id=c.tax_code_id JOIN accounts a ON a.id IN (c.sales_account_id,c.purchase_account_id) WHERE t.company_id='${nid}' AND a.company_id<>t.company_id`),'0');
check('BR-09',A,'Restored document tax detail points to restored accounts only',sql(`SELECT COUNT(*) FROM document_tax_lines d JOIN accounts a ON a.id=d.account_id WHERE d.company_id='${nid}' AND a.company_id<>d.company_id`),'0');
save('rec.json');
