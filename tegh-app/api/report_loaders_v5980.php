<?php
declare(strict_types=1);

function tegh_report_load_5980(array $user,array $company,array $d,array $p): array
{
    $key=$d['key'];$start=$p['start'];$end=$p['end'];
    switch($d['loader']){
        case 'financial':
            if ($key === 'profit-loss') return tegh_profit_report_r20($company,$d,$p);
            $m=tegh_output_financial_statement_v5800($user,$company,$key,$start,$key==='balance-sheet'?$p['asOf']:$end);
            if($key!=='trial-balance'){$m['groupBy']='section';$m['groupOrder']=array_values(array_unique(array_column($m['rows'],'section')));$m['groupLabels']=array_combine($m['groupOrder'],$m['groupOrder']);$m['groupTotals']=[];foreach($m['rows'] as $r)$m['groupTotals'][$r['section']]=($m['groupTotals'][$r['section']]??0)+(int)$r['amountCents'];}
            if($key==='trial-balance'){$m['controlTotals']=$m['totals'];if((int)($m['totals']['differenceCents']??0)!==0)$m['exceptions'][]=['code'=>'trial_balance_difference','message'=>'Posted trial balance does not balance. Investigate the disclosed difference before relying on this report.'];}return $m;
        case 'cash_flow':return tegh_output_cash_flow_v5800($user,$company,$start,$end);
        case 'forecast':return tegh_output_forecast_v5800($user,$company,$start,$end,$p['bucketUnit']);
        case 'budget':return tegh_output_budget_v5800($user,$company,$p['budgetId']?:null);
        case 'assets':return tegh_output_fixed_assets_v5800($user,$company);
        case 'day_book':return tegh_report_day_book_5980($company,$d,$p);
        case 'ledger':return tegh_report_ledger_5980($company,$d,$p);
        case 'bank_ledger':return tegh_report_bank_ledger_5980($company,$d,$p);
        case 'directory':return tegh_report_directory_5980($company,$d,$p);
        case 'documents':return tegh_report_documents_5980($company,$d,$p);
        case 'expenses':return tegh_report_expenses_5980($company,$d,$p);
        case 'bank':return tegh_report_bank_5980($company,$d,$p);
        case 'payroll':case 'payroll_detail':return tegh_report_payroll_5980($company,$d,$p);
        case 'reconciliation':return tegh_report_reconciliation_5980($company,$d,$p);
        case 'audit':return tegh_report_audit_5980($company,$d,$p);
        case 'tax':return tegh_report_tax_5980($company,$d,$p);
        case 'currency':return tegh_report_currency_5980($company,$d,$p);
        case 'ageing':return tegh_subledger_ageing_output_5980($company,$d,$p);
        case 'party_balances':return tegh_subledger_party_balances_output_5980($company,$d,$p);
        case 'party_ledger':return tegh_subledger_party_output_5980($company,$d,$p);
        case 'subledger_trial':return tegh_subledger_trial_output_5980($company,$d,$p);
        case 'inventory':return tegh_report_inventory_5980($company,$d,$p);
        case 'remittances':return tegh_report_remittances_5980($company,$d,$p);
        case 'tax_mapping':return tegh_report_tax_mapping_5980($company,$d,$p);
        case 'invoice_document':return tegh_output_invoice_model($user,$company,$p['documentId']);
        case 'bill_document':return tegh_report_bill_document_5980($company,$d,$p);
        default:throw new LogicException('The report registry has no loader.');
    }
}
function tegh_report_day_book_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'],$p['end']];$where='';$status=$p['status'];
    if(!in_array($status,['all','posted','draft','void','reversed','pending_post'],true))fail('Unsupported Day Book status.',422,'report_status_invalid',false);
    if($status!=='all'){$where=' AND je.status=?';$params[]=match($status){'draft'=>'pending_post','void'=>'reversed',default=>$status};}
    if($p['module']!==''&&$p['module']!=='all'){$where.=' AND EXISTS(SELECT 1 FROM vouchers vm WHERE vm.company_id=je.company_id AND (vm.journal_entry_id=je.id OR (vm.source_type=je.source_type AND vm.source_id=je.source_id)) AND vm.module=?)';$params[]=$p['module'];}
    foreach(['sourceType'=>'je.source_type','sourceId'=>'je.source_id'] as $input=>$field)if($p[$input]!==''){$where.=" AND $field=?";$params[]=$p[$input];}
    if($p['q']!==''){
        // Search chooses complete vouchers, never an unbalanced subset of their lines.
        $needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$p['q']).'%';
        $where.=" AND (je.memo LIKE ? ESCAPE '!' OR je.source_id LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM journal_lines qs JOIN accounts qa ON qa.id=qs.account_id AND qa.company_id=je.company_id WHERE qs.journal_entry_id=je.id AND (qs.memo LIKE ? ESCAPE '!' OR qa.code LIKE ? ESCAPE '!' OR qa.name LIKE ? ESCAPE '!')) OR EXISTS(SELECT 1 FROM vouchers qv WHERE qv.company_id=je.company_id AND qv.journal_entry_id=je.id AND qv.voucher_number LIKE ? ESCAPE '!'))";array_push($params,...array_fill(0,6,$needle));
    }
    // Enrich every journal once. The previous correlated lookups ran again for
    // every displayed line and made the complete Day Book degrade sharply under
    // concurrent readers. These company-scoped aggregates preserve the same
    // voucher, party and remarks rules without N-per-line database work.
    $queryParams=array_merge([$company['id'],$company['id']],$params);
    $raw=tegh_report_query_5980("SELECT je.id journal_id,je.entry_date,je.source_type,je.source_id,je.memo,je.status,je.reversal_of_id,je.voided_at,
      jl.id line_id,jl.account_id,jl.memo line_memo,jl.debit_cents,jl.credit_cents,a.code,a.name,a.account_type,
      CASE WHEN vj.voucher_number IS NULL THEN vs.voucher_number WHEN vs.voucher_number IS NULL THEN vj.voucher_number ELSE LEAST(vj.voucher_number,vs.voucher_number) END voucher_number,
      CASE WHEN vj.module IS NULL THEN vs.module WHEN vs.module IS NULL THEN vj.module ELSE LEAST(vj.module,vs.module) END module,
      COALESCE(ppr.partner,ic.name,bv.name,'') partner,
      COALESCE(bt.remarks,ppr.remarks,'') remarks,source_bank.ledger_account_id bank_ledger_account_id
      FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_entry_id=je.id
      JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
      LEFT JOIN (SELECT company_id,journal_entry_id,MIN(voucher_number) voucher_number,MIN(module) module FROM vouchers WHERE company_id=? AND journal_entry_id IS NOT NULL GROUP BY company_id,journal_entry_id) vj ON vj.company_id=je.company_id AND vj.journal_entry_id=je.id
      LEFT JOIN vouchers vs ON vs.company_id=je.company_id AND vs.source_type=je.source_type AND vs.source_id=je.source_id
      LEFT JOIN (SELECT pp.company_id,pp.journal_entry_id,GROUP_CONCAT(DISTINCT CASE WHEN pp.payment_type='customer' THEN pc.name ELSE pv.name END ORDER BY CASE WHEN pp.payment_type='customer' THEN pc.name ELSE pv.name END SEPARATOR ', ') partner,GROUP_CONCAT(DISTINCT NULLIF(pp.memo,'') ORDER BY pp.memo SEPARATOR '; ') remarks FROM party_payments pp LEFT JOIN customers pc ON pp.payment_type='customer' AND pc.id=pp.party_id AND pc.company_id=pp.company_id LEFT JOIN vendors pv ON pp.payment_type='vendor' AND pv.id=pp.party_id AND pv.company_id=pp.company_id WHERE pp.company_id=? AND pp.journal_entry_id IS NOT NULL GROUP BY pp.company_id,pp.journal_entry_id) ppr ON ppr.company_id=je.company_id AND ppr.journal_entry_id=je.id
      LEFT JOIN invoices i ON i.company_id=je.company_id AND i.id=je.source_id AND je.source_type IN('invoice','invoice_reversal')
      LEFT JOIN customers ic ON ic.company_id=i.company_id AND ic.id=i.customer_id
      LEFT JOIN bills b ON b.company_id=je.company_id AND b.id=je.source_id AND je.source_type IN('bill','bill_reversal')
      LEFT JOIN vendors bv ON bv.company_id=b.company_id AND bv.id=b.vendor_id
      LEFT JOIN bank_transactions bt ON bt.company_id=je.company_id AND bt.id=je.source_id AND je.source_type='bank_transaction'
      LEFT JOIN bank_accounts source_bank ON source_bank.company_id=bt.company_id AND source_bank.id=bt.bank_account_id
      WHERE je.company_id=? AND je.entry_date BETWEEN ? AND ? $where ORDER BY je.entry_date,je.created_at,je.id,jl.id",$queryParams);
    $rows=[];foreach($raw as $r)$rows[]=['date'=>$r['entry_date'],'voucher'=>$r['voucher_number']?:'Journal entry','journalId'=>$r['journal_id'],'lineId'=>$r['line_id'],'sourceModule'=>($r['module']?:$r['source_type']),'sourceType'=>$r['source_type'],'sourceId'=>$r['source_id']??'',
        'reference'=>$r['voucher_number']?:'','description'=>$r['memo'],'glAccount'=>$r['code'].' — '.$r['name'],'accountCode'=>$r['code'],'accountName'=>$r['name'],'accountType'=>$r['account_type'],'isSourceFinancialAccount'=>((string)($r['bank_ledger_account_id']??'')!==''&&(string)$r['account_id']===(string)$r['bank_ledger_account_id']),'partner'=>$r['partner'],'remarks'=>$r['remarks'],'lineMemo'=>$r['line_memo'],'debitCents'=>(int)$r['debit_cents'],'creditCents'=>(int)$r['credit_cents'],
        'status'=>match($r['status']){'pending_post'=>'Draft — unposted','reversed'=>'Void / retained history',default=>($r['reversal_of_id']?'Posted reversal':'Posted')},'postingStatus'=>$r['status'],'reversalOfJournalId'=>$r['reversal_of_id'],'voidedAt'=>$r['voided_at'],'rowKind'=>'journal_line'];
    if(in_array($status,['all','draft','pending_post'],true)){
        $params=[$company['id'],$p['start'],$p['end']];$where='';if($p['module']!==''&&$p['module']!=='all'){$where.=' AND v.module=?';$params[]=$p['module'];}
        foreach(['sourceType'=>'v.source_type','sourceId'=>'v.source_id'] as $input=>$field)if($p[$input]!==''){$where.=" AND $field=?";$params[]=$p[$input];}
        $where.=tegh_report_search_sql_5980(['v.voucher_number','v.description','v.source_id'],$p['q'],$params);
        $drafts=tegh_report_query_5980("SELECT v.* FROM vouchers v WHERE v.company_id=? AND v.voucher_date BETWEEN ? AND ? AND v.status='draft' AND v.journal_entry_id IS NULL $where ORDER BY v.voucher_date,v.serial_number",$params);
        foreach($drafts as $r)$rows[]=['date'=>$r['voucher_date'],'voucher'=>$r['voucher_number'],'journalId'=>'draft:'.$r['id'],'lineId'=>null,'sourceModule'=>$r['module'],'sourceType'=>$r['source_type'],'sourceId'=>$r['source_id']??$r['id'],'reference'=>$r['voucher_number'],'description'=>$r['description'],'glAccount'=>'No journal created','accountCode'=>'','accountName'=>'No journal created','accountType'=>'','isSourceFinancialAccount'=>false,'partner'=>'','remarks'=>'','lineMemo'=>'Source draft only; not a ledger line.','debitCents'=>0,'creditCents'=>0,'status'=>'Draft — unposted','postingStatus'=>'pending_post','rowKind'=>'unposted_source','reversalOfJournalId'=>null];
    }
    usort($rows,static fn($a,$b)=>[$a['date'],$a['voucher'],$a['journalId'],$a['lineId']??'']<=>[$b['date'],$b['voucher'],$b['journalId'],$b['lineId']??'']);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([
        ['date','Date','date',12],['voucher','Day Book Entry','identifier',16],['glAccount','General Ledger Account','text',28],['partner','Customer / Vendor','text',22],['remarks','Remarks','text',30],['debitCents','Debit','money',14],['creditCents','Credit','money',14],['status','Status','text',18]]),$rows);
    return tegh_report_day_book_controls_5980($m);
}
/** Pure grouping/control calculation, also exercised without a database. */
function tegh_report_day_book_controls_5980(array $m): array
{
    $m['groupBy']='journalId';$m['groupOrder']=[];$m['groupLabels']=[];$m['groupTotals']=[];$m['groupSummaries']=[];$totals=['debitCents'=>0,'creditCents'=>0];$allDebit=0;$allCredit=0;$exceptions=[];
    foreach($m['rows'] as $r){$id=(string)$r['journalId'];if(!isset($m['groupTotals'][$id])){$m['groupOrder'][]=$id;$m['groupLabels'][$id]=$r['voucher'].' · '.$r['status'];$m['groupTotals'][$id]=['debitCents'=>0,'creditCents'=>0,'postingStatus'=>$r['postingStatus']];$source=(string)($r['sourceType']??$r['sourceModule']??'journal');$sourceLabel=ucwords(str_replace('_',' ',$source));$m['groupSummaries'][$id]=['date'=>$r['date'],'voucher'=>$r['voucher'],'sourceType'=>$sourceLabel,'sourceId'=>$r['sourceId']??'','partner'=>$r['partner']??'','remarks'=>$r['remarks']??'','description'=>$r['description']?:($r['lineMemo']??''),'counterpartAccounts'=>'','debitCents'=>0,'creditCents'=>0,'netCents'=>0,'status'=>$r['status'],'_accounts'=>[],'_preferred'=>[]];}
        $dr=(int)$r['debitCents'];$cr=(int)$r['creditCents'];$m['groupTotals'][$id]['debitCents']+=$dr;$m['groupTotals'][$id]['creditCents']+=$cr;$allDebit+=$dr;$allCredit+=$cr;
        $m['groupSummaries'][$id]['debitCents']+=$dr;$m['groupSummaries'][$id]['creditCents']+=$cr;
        $account=trim((string)($r['accountCode']??'').' — '.(string)($r['accountName']??''),' —');if($account!==''&&($r['rowKind']??'')==='journal_line'){$m['groupSummaries'][$id]['_accounts'][$account]=true;$preferred=false;$source=(string)($r['sourceType']??'');if($source==='bank_transaction')$preferred=empty($r['isSourceFinancialAccount']);elseif(str_starts_with($source,'invoice'))$preferred=($r['accountType']??'')==='income';elseif(str_starts_with($source,'bill'))$preferred=($r['accountType']??'')==='expense';if($preferred)$m['groupSummaries'][$id]['_preferred'][$account]=true;}
        if($r['postingStatus']==='posted'){$totals['debitCents']+=$dr;$totals['creditCents']+=$cr;}}
    foreach($m['groupSummaries'] as &$summary){$accounts=array_keys($summary['_preferred']?:$summary['_accounts']);$summary['counterpartAccounts']=count($accounts)===1?$accounts[0]:(count($accounts)>1?count($accounts).' accounts':'No journal lines');$summary['netCents']=$summary['debitCents']-$summary['creditCents'];unset($summary['_accounts'],$summary['_preferred']);}unset($summary);
    foreach($m['groupTotals'] as $id=>$values)if($values['postingStatus']==='posted'&&$values['debitCents']!==$values['creditCents'])$exceptions[]=['code'=>'posted_voucher_unbalanced','reference'=>$m['groupLabels'][$id],'differenceCents'=>$values['debitCents']-$values['creditCents'],'message'=>'A posted voucher is unbalanced. Do not rely on the totals until independently investigated.'];
    $m['totals']=$totals;$m['totalsLabel']='Posted-ledger totals only';$m['controlTotals']=['postedDebitCents'=>$totals['debitCents'],'postedCreditCents'=>$totals['creditCents'],'postedDifferenceCents'=>$totals['debitCents']-$totals['creditCents'],'allDisplayedDebitCents'=>$allDebit,'allDisplayedCreditCents'=>$allCredit];
    $m['postingScope']='Each actual journal line is shown. Draft and void-history groups are excluded from posted-ledger totals; an unposted source without a journal is labelled, not converted into fictional debit/credit lines.';$m['exceptions']=$exceptions;return $m;
}
function tegh_report_ledger_5980(array $company,array $d,array $p): array
{
    $params=[$company['id']];$where='';if($p['accountId']!==''){$where=' AND a.id=?';$params[]=$p['accountId'];}
    $accounts=tegh_report_query_5980("SELECT a.id,a.code,a.name,a.normal_balance FROM accounts a WHERE a.company_id=? $where ORDER BY a.code,a.id",$params);
    if($p['accountId']!==''&&!$accounts)fail('This GL account is not available in the current company.',404,'report_account_unavailable',false);
    $params=[$company['id'],$p['start']];$where='';if($p['accountId']!==''){$where=' AND jl.account_id=?';$params[]=$p['accountId'];}
    $opening=tegh_report_query_5980("SELECT jl.account_id,SUM(jl.debit_cents-jl.credit_cents) balance FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date<? $where GROUP BY jl.account_id",$params);
    $opening=array_map('intval',array_column($opening,'balance','account_id'));$params=[$company['id'],$p['start'],$p['end']];if($p['accountId']!=='')$params[]=$p['accountId'];
    $lines=tegh_report_query_5980("SELECT jl.id line_id,jl.account_id,jl.memo line_memo,jl.debit_cents,jl.credit_cents,je.id journal_id,je.entry_date,je.memo,je.source_type,je.source_id,je.reversal_of_id,
      (SELECT MIN(v.voucher_number) FROM vouchers v WHERE v.company_id=je.company_id AND(v.journal_entry_id=je.id OR(v.source_type=je.source_type AND v.source_id=je.source_id))) voucher
      FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? $where ORDER BY jl.account_id,je.entry_date,je.created_at,je.id,jl.id",$params);
    $by=[];foreach($lines as $line)$by[$line['account_id']][]=$line;$rows=[];$groups=[];$labels=[];$controls=[];$td=0;$tc=0;$groupTotals=[];
    foreach($accounts as $a){$id=$a['id'];if($p['accountId']===''&&!isset($by[$id])&&($opening[$id]??0)===0)continue;$groups[]=$id;$labels[$id]=$a['code'].' · '.$a['name'];$run=$opening[$id]??0;$dr=0;$cr=0;
        $base=['accountId'=>$id,'accountCode'=>$a['code'],'accountName'=>$a['name']];$rows[]=array_merge($base,['date'=>$p['start'],'voucher'=>'Opening balance','reference'=>'','description'=>'All posted activity before the period','lineMemo'=>'','debitCents'=>0,'creditCents'=>0,'balanceCents'=>$run,'rowKind'=>'opening']);
        foreach($by[$id]??[] as $l){$debit=(int)$l['debit_cents'];$credit=(int)$l['credit_cents'];$dr+=$debit;$cr+=$credit;$run+=$debit-$credit;$voucher=$l['voucher']?:'Journal entry';$rows[]=array_merge($base,['date'=>$l['entry_date'],'voucher'=>$voucher,'journalId'=>$l['journal_id'],'lineId'=>$l['line_id'],'reference'=>$l['voucher']?:'','description'=>$l['memo'],'lineMemo'=>$l['line_memo'],'debitCents'=>$debit,'creditCents'=>$credit,'balanceCents'=>$run,'rowKind'=>'journal_line']);}
        $controls[$id]=['openingSignedCents'=>$opening[$id]??0,'movementDebitCents'=>$dr,'movementCreditCents'=>$cr,'closingSignedCents'=>$run,'equationDifferenceCents'=>($opening[$id]??0)+$dr-$cr-$run];$groupTotals[$id]=['debitCents'=>$dr,'creditCents'=>$cr,'balanceCents'=>$run];$td+=$dr;$tc+=$cr;
    }
    // A search must not remove movements while retaining a misleading running balance.
    if($p['q']!=='')fail('For a complete running-balance ledger, use account and date filters. Search individual vouchers in Day Book.',422,'ledger_search_would_invalidate_balance',false);
    if(!in_array($p['status'],['all','posted',''],true))fail('General Ledger Account Report contains posted entries only.',422,'report_status_invalid',false);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['date','Date','date',12],['voucher','Entry Number','identifier',16],['reference','Reference','identifier',17],['description','Description','text',26],['accountCode','General Ledger Code','identifier',16],['accountName','Account Name','text',22],['lineMemo','Line Memo','text',24],['debitCents','Debit','money',14],['creditCents','Credit','money',14],['balanceCents','Running Balance','money',22]]),$rows,['debitCents'=>$td,'creditCents'=>$tc]);
    $m['groupBy']='accountId';$m['groupOrder']=$groups;$m['groupLabels']=$labels;$m['groupTotals']=$groupTotals;$m['controlTotals']=$controls;$m['totalsLabel']='Period movements';$m['postingScope']='Posted journal entries only; opening + debits − credits = closing. Running balances use debit-positive / credit-negative convention.';return $m;
}
function tegh_report_bank_ledger_5980(array $company,array $d,array $p): array
{
    $q=db()->prepare('SELECT ba.id,ba.name,ba.currency,ba.ledger_account_id,a.code ledger_code,a.name ledger_name FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id WHERE ba.id=? AND ba.company_id=?');$q->execute([$p['bankAccountId'],$company['id']]);$bank=$q->fetch(PDO::FETCH_ASSOC);
    if(!$bank)fail('This bank account is unavailable in the current company.',404,'report_bank_account_unavailable',false);
    $ledgerCurrency=(string)$company['currency'];if($p['currency']!==''&&$p['currency']!==$ledgerCurrency)fail('Bank General Ledger Report amounts are available only in the General Ledger currency.',422,'report_currency_mismatch',false);
    $ledgerParams=$p;$ledgerParams['accountId']=(string)$bank['ledger_account_id'];$ledgerParams['q']='';$ledgerParams['currency']=$ledgerCurrency;
    $m=tegh_report_ledger_5980($company,$d,$ledgerParams);$m['title']='Bank General Ledger Report';$m['currency']=$ledgerCurrency;$m['parameters']=array_merge($m['parameters']??[],['bankAccountId'=>(string)$bank['id'],'currency'=>$ledgerCurrency]);$m['bankAccount']=['id'=>(string)$bank['id'],'name'=>(string)$bank['name'],'bankCurrency'=>(string)$bank['currency'],'ledgerCurrency'=>$ledgerCurrency,'ledgerAccountId'=>(string)$bank['ledger_account_id'],'generalLedgerCode'=>(string)$bank['ledger_code'],'generalLedgerAccount'=>(string)$bank['ledger_name']];$m['postingScope']='All posted journal movements affecting the linked bank General Ledger account, including payments, receipts, journals and transfers. Search is find-and-highlight only and never removes movements from the running balance.';return $m;
}
function tegh_report_directory_5980(array $company,array $d,array $p): array
{
    $customer=$d['key']==='customer-directory';$table=$customer?'customers':'vendors';$address=$customer?'billing_address':'address';$params=[$company['id']];
    $where=tegh_report_status_sql_5980('p.status',$p['status'],$customer?['active','on_hold','inactive']:['active','inactive'],$params).tegh_report_search_sql_5980(['p.name','p.contact_name','p.email','p.phone'], $p['q'],$params);
    $rows=tegh_report_query_5980("SELECT p.id,p.name,p.contact_name contactName,p.email,p.phone,p.$address address,p.default_terms_days termsDays,p.status FROM $table p WHERE p.company_id=? $where ORDER BY p.name,p.id",$params);
    foreach($rows as &$r)$r['termsDays']=(int)$r['termsDays'];unset($r);return tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['name','Name','text',25],['contactName','Contact','text',22],['email','Email','text',28],['phone','Phone','text',18],['address','Address','text',32],['termsDays','Terms (days)','integer',13],['status','Status','text',14]]),$rows,['records'=>count($rows)]);
}
function tegh_report_documents_5980(array $company,array $d,array $p): array
{
    $ar=$d['key']==='invoice-register';$table=$ar?'invoices':'bills';$party=$ar?'customers':'vendors';$partyKey=$ar?'customer_id':'vendor_id';$date=$ar?'issue_date':'bill_date';$params=[$company['id'],$p['start'],$p['end']];$overdueOnly=$ar&&$p['status']==='overdue';
    $where=tegh_report_status_sql_5980('d.status',$overdueOnly?'all':$p['status'],$ar?['draft','sent','paid','void']:['draft','submitted_for_approval','approved','open','paid','void'],$params);
    if($p['partyId']!==''){$where.=" AND d.$partyKey=?";$params[]=$p['partyId'];}
    $where.=tegh_report_search_sql_5980(['d.number','p.name','d.import_reference'],$p['q'],$params);
    $termsSql=$ar?'NULL':'d.payment_terms_days';
    $rows=tegh_report_query_5980("SELECT $termsSql termsDays,d.is_opening_document isOpeningDocument,d.id,d.number,d.$date date,d.due_date dueDate,p.name party,d.import_reference reference,d.status,d.currency foreignCurrency,d.subtotal_cents subtotalCents,d.tax_cents taxCents,d.total_cents totalCents,d.balance_cents balanceCents,d.foreign_subtotal_cents foreignSubtotalCents,d.foreign_tax_cents foreignTaxCents,d.foreign_total_cents foreignTotalCents,d.foreign_balance_cents foreignBalanceCents,d.issued_journal_entry_id journalId FROM $table d JOIN $party p ON p.id=d.$partyKey AND p.company_id=d.company_id WHERE d.company_id=? AND d.$date BETWEEN ? AND ? $where ORDER BY d.$date,d.number,d.id",$params);
    $totals=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];$active=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];
    foreach($rows as &$r){foreach(['subtotalCents','taxCents','totalCents','balanceCents','foreignSubtotalCents','foreignTaxCents','foreignTotalCents','foreignBalanceCents'] as $key)$r[$key]=(int)$r[$key];foreach($totals as $key=>$unused){$totals[$key]+=$r[$key];if(in_array($r['status'],$ar?['sent','paid']:['open','paid'],true))$active[$key]+=$r[$key];}$r['documentDefinition']=$ar?'customer-invoice':'vendor-bill';$r['isOpeningDocument']=(bool)$r['isOpeningDocument'];$r['documentKind']=$r['isOpeningDocument']?'Opening balance':'Invoice';
        // Sales invoices have no saved terms-days column: use document dates,
        // never today's customer default. Vendor terms are saved on the bill.
        if($ar){$r['termsDays']=null;if(!empty($r['date'])&&!empty($r['dueDate'])){try{$r['termsDays']=(int)(new DateTimeImmutable($r['date']))->diff(new DateTimeImmutable($r['dueDate']))->format('%r%a');}catch(Throwable $e){$r['termsDays']=null;}}}
        elseif($r['termsDays']!==null)$r['termsDays']=(int)$r['termsDays'];
        $r['termsSource']=$ar?'Invoice / Due Dates':'Saved Invoice Terms';$r['displayStatus']=$ar&&$r['foreignBalanceCents']>0&&!empty($r['dueDate'])&&$r['dueDate']<canadian_today()&&in_array($r['status'],['sent','open'],true)?'Overdue':ucwords(str_replace('_',' ',(string)$r['status']));}unset($r);
    // R119: one register per side for invoices, credit notes and debit notes.
    $rows=tegh_report_documents_with_notes_r119($company,$ar,$p,$rows,$overdueOnly);
    $totals=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];$active=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];
    foreach($rows as $row)foreach($totals as $key=>$unused){$totals[$key]+=$row[$key];if(in_array($row['status'],$ar?['sent','paid','posted']:['open','paid','posted'],true))$active[$key]+=$row[$key];}
    if($overdueOnly){$rows=array_values(array_filter($rows,static fn(array $row): bool=>$row['displayStatus']==='Overdue'));$totals=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];$active=['subtotalCents'=>0,'taxCents'=>0,'totalCents'=>0,'balanceCents'=>0];foreach($rows as $row)foreach($totals as $key=>$unused){$totals[$key]+=$row[$key];if(in_array($row['status'],['sent','paid'],true))$active[$key]+=$row[$key];}}
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['date','Date','date',12],['number',$ar?'Document':'Bill / Note','identifier',16],['documentKind','Document Type','text',18],['originalNumber','Original Document','identifier',16],['party',$ar?'Customer':'Vendor','text',27],['dueDate','Due Date','date',12],['reference','Reference','text',20],['displayStatus','Status','text',16],['foreignSubtotalCents','Subtotal','money',15],['foreignTaxCents','Tax Amount','money',14],['foreignTotalCents','Total Amount','money',15],['foreignBalanceCents','Outstanding','money',16],['foreignCurrency','Currency','text',12],['termsDays',$ar?'Terms (Days From Dates)':'Terms (Days)','integer',15],['termsSource','Terms Source','text',20],['subtotalCents','Subtotal (Base)','money',15],['taxCents','Tax (Base)','money',14],['totalCents','Total (Base)','money',15],['balanceCents','Outstanding (Base)','money',16]]),$rows,$totals);
    $m['title']=$ar?'Customer Invoice & Note Register':'Vendor Invoice & Note Register';
    $m['totalsLabel']='All selected register rows, net of credit notes, including selected draft/void history';$m['controlTotals']['issuedNonvoid']=$active;$m['postingScope']='Invoice dates · Outstanding is current · Draft/void rows are not posted totals.';$m['foreignColumns']=['foreignSubtotalCents','foreignTaxCents','foreignTotalCents','foreignBalanceCents'];$m['rowCurrencyKey']='foreignCurrency';return $m;
}
/* R119: merge accounting notes into the invoice/bill register.
   Credit notes (and supplier debit notes, which also reduce what is owed) are
   shown as negative amounts so register totals are net. A posted customer debit
   note already exists as its own receivable invoice row; that row is relabelled
   and the note itself is not listed twice. */
function tegh_report_documents_with_notes_r119(array $company,bool $ar,array $p,array $rows,bool $overdueOnly): array
{
    foreach($rows as &$r){$r['originalNumber']=null;}unset($r);
    if(!function_exists('schema_table_exists')||!schema_table_exists('accounting_notes'))return $rows;
    $companyId=(string)$company['id'];$sourceType=$ar?'invoice':'bill';$sourceTable=$ar?'invoices':'bills';$party=$ar?'customers':'vendors';
    $q=db()->prepare("SELECT n.*,src.number source_number,pp.name party_name FROM accounting_notes n JOIN `$sourceTable` src ON src.id=n.source_id AND src.company_id=n.company_id JOIN `$party` pp ON pp.id=n.party_id AND pp.company_id=n.company_id WHERE n.company_id=? AND n.source_type=? ORDER BY n.note_date,n.number");
    $q->execute([$companyId,$sourceType]);$notes=$q->fetchAll(PDO::FETCH_ASSOC);if(!$notes)return $rows;
    $debitDocs=[];foreach($notes as $n)if(!empty($n['debit_document_id']))$debitDocs[(string)$n['debit_document_id']]=$n;
    foreach($rows as &$r)if(isset($debitDocs[(string)$r['id']])){$n=$debitDocs[(string)$r['id']];$r['documentKind']='Debit Note';$r['originalNumber']=(string)$n['source_number'];$r['noteId']=(string)$n['id'];}unset($r);
    if($overdueOnly)return $rows;
    $statusMap=['draft'=>'draft','void'=>'void','sent'=>'posted','open'=>'posted'];
    if($p['status']!==''&&$p['status']!=='all'&&!isset($statusMap[$p['status']]))return $rows;
    $wanted=($p['status']===''||$p['status']==='all')?null:$statusMap[$p['status']];
    $needle=mb_strtolower(trim((string)$p['q']));
    $labels=['customer_credit'=>'Credit Note','customer_debit'=>'Debit Note','vendor_credit'=>'Supplier Credit Note','vendor_debit'=>'Supplier Debit Note'];
    foreach($notes as $n){
        if(!empty($n['debit_document_id']))continue;
        $date=(string)$n['note_date'];if($date<$p['start']||$date>$p['end'])continue;
        if($wanted!==null&&$n['status']!==$wanted)continue;
        if($p['partyId']!==''&&(string)$n['party_id']!==$p['partyId'])continue;
        $kind=(string)$n['note_kind'];
        if($needle!==''&&!str_contains(mb_strtolower(implode(' ',[(string)$n['number'],(string)$n['party_name'],(string)$n['source_number'],(string)$n['memo'],$labels[$kind]??''])),$needle))continue;
        $sign=$kind==='customer_debit'?1:-1;
        $open=0;$foreignOpen=0;
        if($n['status']==='posted'&&$kind!=='customer_debit'&&function_exists('note_remaining_amounts')&&function_exists('tegh_notes_r70_settlements_ready')&&tegh_notes_r70_settlements_ready()){
            try{$left=note_remaining_amounts($companyId,$n);$open=-(int)$left['remainingCents'];$foreignOpen=-(int)$left['foreignRemainingCents'];}catch(Throwable $e){$open=0;$foreignOpen=0;}
        }
        $rows[]=['termsDays'=>null,'isOpeningDocument'=>false,'id'=>(string)$n['id'],'noteId'=>(string)$n['id'],'number'=>(string)$n['number'],'date'=>$date,'dueDate'=>null,'party'=>(string)$n['party_name'],'reference'=>(string)$n['memo'],
            'status'=>(string)$n['status'],'foreignCurrency'=>(string)$n['currency'],
            'subtotalCents'=>$sign*(int)$n['subtotal_cents'],'taxCents'=>$sign*(int)$n['tax_cents'],'totalCents'=>$sign*(int)$n['total_cents'],'balanceCents'=>$open,
            'foreignSubtotalCents'=>$sign*(int)$n['foreign_subtotal_cents'],'foreignTaxCents'=>$sign*(int)$n['foreign_tax_cents'],'foreignTotalCents'=>$sign*(int)$n['foreign_total_cents'],'foreignBalanceCents'=>$foreignOpen,
            'journalId'=>$n['journal_entry_id']?(string)$n['journal_entry_id']:null,'documentDefinition'=>'accounting-note','noteKind'=>$kind,'documentKind'=>$labels[$kind]??'Note','originalNumber'=>(string)$n['source_number'],
            'termsSource'=>'Not applicable','displayStatus'=>ucwords((string)$n['status'])];
    }
    usort($rows,static fn(array $a,array $b): int=>[(string)$a['date'],(string)$a['number'],(string)$a['id']]<=>[(string)$b['date'],(string)$b['number'],(string)$b['id']]);
    return $rows;
}
function tegh_report_expenses_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_status_sql_5980('e.status',$p['status'],['posted','void'],$params).tegh_report_search_sql_5980(['e.vendor','a.code','a.name'],$p['q'],$params);
    $rows=tegh_report_query_5980("SELECT e.id,e.expense_date date,e.vendor,a.code accountCode,a.name accountName,e.tax_code taxCode,e.status,e.subtotal_cents subtotalCents,e.gst_hst_cents gstHstCents,e.pst_cents pstCents,e.total_cents totalCents,e.journal_entry_id journalId,(SELECT MIN(vx.voucher_number) FROM vouchers vx WHERE vx.company_id=e.company_id AND vx.source_type='expense' AND vx.source_id=e.id) expenseNumber FROM expenses e JOIN accounts a ON a.id=e.category_account_id AND a.company_id=e.company_id WHERE e.company_id=? AND e.expense_date BETWEEN ? AND ? $where ORDER BY e.expense_date,e.id",$params);
    $totals=['subtotalCents'=>0,'gstHstCents'=>0,'pstCents'=>0,'totalCents'=>0];foreach($rows as &$r){$r['expenseNumber']=trim((string)($r['expenseNumber']??''))?:'Expense '.$r['date'];foreach($totals as $key=>$unused){$r[$key]=(int)$r[$key];if($r['status']==='posted')$totals[$key]+=$r[$key];}}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['date','Date','date',12],['expenseNumber','Expense','identifier',18],['vendor','Vendor','text',25],['accountCode','General Ledger Code','identifier',16],['accountName','Account','text',24],['taxCode','Tax Treatment','text',15],['subtotalCents','Subtotal','money',14],['gstHstCents','GST/HST','money',14],['pstCents','PST','money',14],['totalCents','Total','money',14],['status','Status','text',13]]),$rows,$totals);$m['totalsLabel']='Posted expenses only; void rows are retained history';return $m;
}
function tegh_report_bank_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_status_sql_5980('bt.status',$p['status'],['pending','posted','excluded','duplicate'],$params);
    if($p['bankAccountId']!==''){$accountCheck=db()->prepare('SELECT COUNT(*) FROM bank_accounts WHERE id=? AND company_id=?');$accountCheck->execute([$p['bankAccountId'],$company['id']]);if(!(int)$accountCheck->fetchColumn())fail('This bank account is unavailable in the current company.',404,'report_bank_account_unavailable',false);$where.=' AND bt.bank_account_id=?';$params[]=$p['bankAccountId'];}
    if($p['currency']!==''){$currencyCheck=db()->prepare('SELECT COUNT(*) FROM bank_accounts WHERE company_id=? AND currency=?'.($p['bankAccountId']!==''?' AND id=?':''));$currencyArgs=[$company['id'],$p['currency']];if($p['bankAccountId']!=='')$currencyArgs[]=$p['bankAccountId'];$currencyCheck->execute($currencyArgs);if(!(int)$currencyCheck->fetchColumn())fail('The selected currency is not available for the selected bank account scope.',422,'report_currency_unavailable',false);$where.=' AND bt.currency=?';$params[]=$p['currency'];}
    $where.=tegh_report_search_sql_5980(['bt.description','bt.reference','ba.name','a.code','a.name'],$p['q'],$params);
    $rows=tegh_report_query_5980("SELECT bt.id,bt.bank_account_id bankAccountId,bt.transaction_date date,ba.name bankAccount,CASE WHEN EXISTS(SELECT 1 FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id=bt.id AND g.status='matched') THEN 1 ELSE 0 END matched,CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.status='complete') THEN 1 ELSE 0 END reconciled,COALESCE(st.full_description,bt.description) description,bt.reference,bt.remarks,bt.status,bt.currency,bt.foreign_amount_cents amountCents,a.code accountCode,a.name accountName,bt.journal_entry_id journalId FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id LEFT JOIN accounts a ON a.id=COALESCE(bt.decided_account_id,bt.suggested_account_id) AND a.company_id=bt.company_id LEFT JOIN bank_transaction_source_text st ON st.company_id=bt.company_id AND st.transaction_id=bt.id WHERE bt.company_id=? AND bt.transaction_date BETWEEN ? AND ? $where ORDER BY bt.transaction_date,bt.id",$params);
    $currencies=[];foreach($rows as &$r){$r['matched']=(bool)(int)$r['matched'];$r['reconciled']=(bool)(int)$r['reconciled'];if(in_array($r['status'],['excluded','duplicate'],true)){$r['accountCode']=null;$r['accountName']=null;}$amount=(int)$r['amountCents'];$r['moneyOutCents']=max(0,-$amount);$r['moneyInCents']=max(0,$amount);$r['amountCents']=$amount;$c=$r['currency'];$currencies[$c]??=['moneyOutCents'=>0,'moneyInCents'=>0];$currencies[$c]['moneyOutCents']+=$r['moneyOutCents'];$currencies[$c]['moneyInCents']+=$r['moneyInCents'];}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['date','Date','date',12],['bankAccount','Bank Account','text',23],['description','Description','text',40],['reference','Reference','text',18],['currency','Currency','text',11],['moneyOutCents','Money Out','money',15],['moneyInCents','Money In','money',15],['accountCode','General Ledger Code','identifier',16],['accountName','Account','text',22],['status','Status','text',14]]),$rows);
    $m['currencyQualifications']='Statement amounts retain each row’s currency. No cross-currency grand total is calculated.';$m['rowCurrencyKey']='currency';$m['controlTotals']=$currencies;$m['groupBy']='currency';$m['groupOrder']=array_keys($currencies);$m['groupLabels']=array_combine(array_keys($currencies),array_keys($currencies));$m['groupTotals']=$currencies;return $m;
}
function tegh_report_payroll_5980(array $company,array $d,array $p): array
{
    $detail=$d['loader']==='payroll_detail';$params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_status_sql_5980('r.status',$p['status'],['draft','verified','posted','paid','reversed'],$params);
    if($p['runId']!==''){$where.=' AND r.id=?';$params[]=$p['runId'];}
    if($detail){$q=db()->prepare('SELECT id FROM payroll_runs WHERE id=? AND company_id=?');$q->execute([$p['runId'],$company['id']]);if(!$q->fetchColumn())fail('This pay run is unavailable.',404,'report_run_unavailable',false);
        $where.=tegh_report_search_sql_5980(['e.employee_number','e.first_name','e.last_name'],$p['q'],$params);
        $rows=tegh_report_query_5980("SELECT r.id runId,r.pay_date payDate,r.period_start periodStart,r.period_end periodEnd,r.status,r.gl_status glStatus,e.employee_number employeeNumber,CONCAT(e.first_name,' ',e.last_name) employee,i.gross_pay_cents grossCents,i.employee_cpp_cents cppCents,i.employee_cpp2_cents cpp2Cents,i.employee_ei_cents eiCents,COALESCE(i.verified_income_tax_cents,i.estimated_income_tax_cents) incomeTaxCents,i.other_deductions_cents otherCents,i.net_pay_cents netCents,i.verification_status verificationStatus FROM payroll_runs r JOIN payroll_run_items i ON i.payroll_run_id=r.id JOIN payroll_employees e ON e.id=i.employee_id AND e.company_id=r.company_id WHERE r.company_id=? AND r.pay_date BETWEEN ? AND ? $where ORDER BY r.pay_date,r.id,e.employee_number,i.id",$params);
        $columns=[['payDate','Pay date','date',12],['employeeNumber','Employee #','identifier',13],['employee','Employee','text',24],['grossCents','Gross','money',14],['cppCents','CPP','money',12],['cpp2Cents','CPP2','money',12],['eiCents','EI','money',12],['incomeTaxCents','Income tax','money',14],['otherCents','Other deductions','money',14],['netCents','Net','money',14],['verificationStatus','Verification','text',18],['status','Run status','text',14]];
    }else{
        $where.=tegh_report_search_sql_5980(['r.id','r.frequency','r.verification_reference'],$p['q'],$params);
        $rows=tegh_report_query_5980("SELECT r.id runId,r.pay_date payDate,r.period_start periodStart,r.period_end periodEnd,r.frequency,r.run_sequence runSequence,r.status,r.gl_status glStatus,r.gross_pay_cents grossCents,r.employee_cpp_cents cppCents,r.employee_cpp2_cents cpp2Cents,r.employee_ei_cents eiCents,r.income_tax_cents incomeTaxCents,r.other_deductions_cents otherCents,r.net_pay_cents netCents,r.employer_cpp_cents employerCppCents,r.employer_cpp2_cents employerCpp2Cents,r.employer_ei_cents employerEiCents,r.employer_levy_cents employerLevyCents,(SELECT MIN(vx.voucher_number) FROM vouchers vx WHERE vx.company_id=r.company_id AND vx.source_type='payroll_run' AND vx.source_id=r.id) runVoucher FROM payroll_runs r WHERE r.company_id=? AND r.pay_date BETWEEN ? AND ? $where ORDER BY r.pay_date,r.period_start,r.run_sequence,r.id",$params);
        foreach($rows as &$row)$row['runReference']=trim((string)($row['runVoucher']??''))?:'Pay run '.$row['payDate'].' #'.(int)$row['runSequence'];unset($row);
        $columns=[['runReference','Run','identifier',22],['periodStart','Period Start','date',12],['periodEnd','Period End','date',12],['payDate','Pay Date','date',12],['frequency','Frequency','text',15],['grossCents','Gross','money',14],['cppCents','CPP','money',12],['cpp2Cents','CPP2','money',12],['eiCents','EI','money',12],['incomeTaxCents','Income Tax','money',14],['otherCents','Other Deductions','money',14],['netCents','Net','money',14],['status','Run Status','text',14],['glStatus','General Ledger Status','text',20]];
    }
    $totals=[];$posted=[];foreach($columns as $c)if(($c[2]??'')==='money'){$totals[$c[0]]=0;$posted[$c[0]]=0;}
    foreach($rows as &$row)foreach(array_keys($totals) as $key){$row[$key]=(int)$row[$key];$totals[$key]+=$row[$key];if($row['glStatus']==='posted')$posted[$key]+=$row[$key];}unset($row);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980($columns),$rows,$totals);$m['controlTotals']=['postedToLedger'=>$posted];$m['totalsLabel']='Selected pay runs; draft and verification states are explicit';$m['postingScope']='Payroll calculations and GL posting status are separate. Draft/verified calculations are not represented as posted ledger activity. SIN, tax-account credentials and bank information are excluded.';return $m;
}
function tegh_report_reconciliation_5980(array $company,array $d,array $p): array
{
    $params=[(string)$company['id'],$p['start'],$p['end']];$where='';
    if($p['status']==='complete')$where.=" AND r.status='complete'";
    elseif($p['status']==='draft')$where.=" AND r.status='draft' AND r.reopened_at IS NULL";
    elseif($p['status']==='reopened')$where.=" AND r.status='draft' AND r.reopened_at IS NOT NULL";
    elseif(!in_array($p['status'],['','all'],true))fail('That status is not supported by this report.',422,'report_status_invalid',false);
    if($p['bankAccountId']!==''){$where.=' AND r.bank_account_id=?';$params[]=$p['bankAccountId'];}
    $where.=tegh_report_search_sql_5980(['b.name','b.masked_number','r.notes'],$p['q'],$params);
    $base=" FROM reconciliations r JOIN bank_accounts b ON b.id=r.bank_account_id AND b.company_id=r.company_id LEFT JOIN users prep ON prep.id=COALESCE(r.prepared_by,r.created_by) LEFT JOIN users review ON review.id=r.reviewed_by WHERE r.company_id=? AND r.period_end BETWEEN ? AND ? $where";
    $count=db()->prepare('SELECT COUNT(*)'.$base);$count->execute($params);$totalRows=(int)$count->fetchColumn();
    $page=max(1,(int)$p['page']);$pageSize=50;$totalPages=max(1,(int)ceil($totalRows/$pageSize));if($page>$totalPages)$page=$totalPages;$offset=($page-1)*$pageSize;
    $stmt=db()->prepare("SELECT r.id,r.bank_account_id bankAccountId,r.period_start periodStart,r.period_end periodEnd,b.name bankAccount,b.masked_number maskedNumber,b.currency,r.statement_balance_cents statementCents,r.book_balance_cents bookCents,r.difference_cents differenceCents,r.status,r.notes,r.completed_at completedAt,r.reopened_at reopenedAt,r.reopen_reason reopenReason,prep.display_name preparedBy,review.display_name reviewedBy".$base." ORDER BY r.period_end DESC,b.name,r.id LIMIT $pageSize OFFSET $offset");$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $exceptions=[];foreach($rows as &$r){foreach(['statementCents','bookCents','differenceCents'] as $key)$r[$key]=(int)$r[$key];$r['statusKey']=$r['status']==='complete'?'complete':($r['reopenedAt']!==null?'reopened':'draft');$r['status']=$r['statusKey']==='complete'?'Reconciled':ucfirst($r['statusKey']);$r['preparedReviewed']=trim((string)($r['preparedBy']??''));if(!empty($r['reviewedBy']))$r['preparedReviewed'].=($r['preparedReviewed']!==''?' / ':'').(string)$r['reviewedBy'];if($r['preparedReviewed']==='')$r['preparedReviewed']='—';if($r['statusKey']==='complete'&&$r['differenceCents']!==0)$exceptions[]=['code'=>'completed_reconciliation_difference','reference'=>$r['bankAccount'].' · '.$r['periodEnd'],'message'=>'A completed reconciliation has a non-zero stored difference.'];}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['periodEnd','Period end','date',12],['bankAccount','Financial account','text',28],['maskedNumber','Account number','text',15],['currency','Currency','text',10],['statementCents','Statement balance','money',17],['bookCents','Book balance','money',17],['differenceCents','Difference','money',16],['status','Status','text',13],['preparedReviewed','Prepared / reviewed','text',24],['completedAt','Completed','text',20]]),$rows,['reconciliations'=>$totalRows]);$m['exceptions']=$exceptions;$m['postingScope']='Stored reconciliation snapshots; balances from different periods are not added.';$m['rowCurrencyKey']='currency';$m['pagination']=['page'=>$page,'pageSize'=>$pageSize,'totalRows'=>$totalRows,'totalPages'=>$totalPages,'fixedPageSize'=>true];return $m;
}
function tegh_report_audit_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'].' 00:00:00',(new DateTimeImmutable($p['end']))->modify('+1 day')->format('Y-m-d').' 00:00:00'];$where='';
    $categories=['posting'=>" AND action LIKE '%post%'",'voiding'=>" AND (action LIKE '%void%' OR action LIKE '%reverse%')",'reconciliation'=>" AND action LIKE 'reconciliation.%'",'users'=>" AND (action LIKE 'company.user%' OR action LIKE 'company.account_setup%' OR action LIKE '%role%' OR entity_type IN ('company_member','company_invitation'))",'transactions'=>" AND entity_type NOT IN ('company_member','company_invitation','audit_integrity_run','support_request')",'accounting'=>" AND entity_type IN ('invoice','bill','expense','journal_entry','bank_transaction','party_payment','payroll_run','reconciliation','voucher')"];
    if(!in_array($p['category'],['','all'],true)){if(!isset($categories[$p['category']]))fail('Unsupported audit category.',422,'report_parameter_invalid',false);$where=$categories[$p['category']];}
    $where.=tegh_report_search_sql_5980(['actor_email','action','entity_type'],$p['q'],$params);
    $rows=tegh_report_query_5980("SELECT created_at createdAt,actor_email actor,action,entity_type recordType FROM audit_log WHERE company_id=? AND created_at>=? AND created_at<? $where ORDER BY created_at DESC,id DESC",$params);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['createdAt','Recorded (UTC)','text',24],['actor','User','text',30],['action','Action','text',38],['recordType','Record type','text',24]]),$rows,['events'=>count($rows)]);$m['postingScope']='User-facing audit records only. Arbitrary metadata, secrets, network identifiers and technical incident details are not exported.';return $m;
}
function tegh_report_tax_5980(array $company,array $d,array $p): array
{
    // Use the control ledgers actually used by the posting services. No tax return or compliance opinion is inferred.
    $sql="SELECT a.id,a.code,a.name,a.normal_balance,
        COALESCE(SUM(CASE WHEN je.entry_date<? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) openingCents,
        COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.debit_cents ELSE 0 END),0) debitCents,
        COALESCE(SUM(CASE WHEN je.entry_date BETWEEN ? AND ? THEN jl.credit_cents ELSE 0 END),0) creditCents
      FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' AND je.entry_date<=?
      WHERE a.company_id=? AND (a.code IN ('1100','1110','2100','2110') OR EXISTS(SELECT 1 FROM company_system_accounts cs WHERE cs.company_id=a.company_id AND cs.account_id=a.id AND cs.status='active' AND cs.system_key IN ('gst_hst_receivable','pst_receivable','gst_hst_payable','pst_payable'))) GROUP BY a.id,a.code,a.name,a.normal_balance ORDER BY a.code,a.id";
    $rows=tegh_report_query_5980($sql,[$p['start'],$p['start'],$p['end'],$p['start'],$p['end'],$p['end'],$company['id']]);$totals=['debitCents'=>0,'creditCents'=>0];
    foreach($rows as &$r){foreach(['openingCents','debitCents','creditCents'] as $key)$r[$key]=(int)$r[$key];$r['closingCents']=$r['openingCents']+$r['debitCents']-$r['creditCents'];$totals['debitCents']+=$r['debitCents'];$totals['creditCents']+=$r['creditCents'];}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['code','General Ledger Code','identifier',16],['name','Tax Control Ledger','text',34],['openingCents','Opening (Debit + / Credit −)','money',22],['debitCents','Debit','money',17],['creditCents','Credit','money',17],['closingCents','Closing (Debit + / Credit −)','money',22]]),$rows,$totals);$m['postingScope']='Posted tax-control ledgers; not a tax return or a determination of claim eligibility.';if(!$rows)$m['exceptions'][]=['code'=>'tax_controls_missing','message'=>'No configured or standard tax-control accounts were found.'];return $m;
}
function tegh_report_currency_5980(array $company,array $d,array $p): array
{
    $rows=tegh_report_query_5980("SELECT currency_code currency,rate_to_base_micros rateMicros,rate_date rateDate,active FROM company_currencies WHERE company_id=? ORDER BY currency_code",[$company['id']]);
    $ar=tegh_report_query_5980("SELECT currency,SUM(balance_cents) baseCents,SUM(foreign_balance_cents) foreignCents FROM invoices WHERE company_id=? AND status IN ('sent','paid') GROUP BY currency ORDER BY currency",[$company['id']]);$ap=tegh_report_query_5980("SELECT currency,SUM(balance_cents) baseCents,SUM(foreign_balance_cents) foreignCents FROM bills WHERE company_id=? AND status IN ('open','paid') GROUP BY currency ORDER BY currency",[$company['id']]);
    $by=[];foreach($rows as $r)$by[$r['currency']]=$r;foreach(array_merge($ar,$ap) as $r)$by[$r['currency']]??=['currency'=>$r['currency'],'rateMicros'=>null,'rateDate'=>null,'active'=>0];$ar=array_column($ar,null,'currency');$ap=array_column($ap,null,'currency');ksort($by);$rows=[];$totals=['receivableBaseCents'=>0,'payableBaseCents'=>0,'netBaseCents'=>0];
    foreach($by as $c=>$r){$r['rate']=isset($r['rateMicros'])?(int)$r['rateMicros']/1000000:null;$r['status']=$r['active']?'Active':'Inactive / not configured';$r['receivableForeignCents']=(int)($ar[$c]['foreignCents']??0);$r['payableForeignCents']=(int)($ap[$c]['foreignCents']??0);$r['receivableBaseCents']=(int)($ar[$c]['baseCents']??0);$r['payableBaseCents']=(int)($ap[$c]['baseCents']??0);$r['netBaseCents']=$r['receivableBaseCents']-$r['payableBaseCents'];foreach($totals as $key=>$unused)$totals[$key]+=$r[$key];$rows[]=$r;}
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['currency','Currency','text',12],['rate','Configured rate to base','number',20],['rateDate','Rate date','date',13],['receivableForeignCents','AR foreign (row currency)','money',21],['payableForeignCents','AP foreign (row currency)','money',21],['receivableBaseCents','AR base','money',18],['payableBaseCents','AP base','money',18],['netBaseCents','Net base exposure','money',20],['status','Rate status','text',20]]),$rows,$totals);$m['foreignColumns']=['receivableForeignCents','payableForeignCents'];$m['rowCurrencyKey']='currency';$m['currencyQualifications']='Foreign columns use the indicated row currency and are never totalled across currencies. Base columns use '.$company['currency'].'. Configured rates are informational; carrying balances are not silently revalued. Includes outstanding issued invoices/bills, not a comprehensive market-risk valuation.';return $m;
}
function tegh_report_control_account_5980(array $company,bool $ar): ?array
{
    $keys=$ar?['accounts_receivable','ar_control']:['accounts_payable','ap_control'];$q=db()->prepare("SELECT a.id,a.code,a.name FROM company_system_accounts cs JOIN accounts a ON a.id=cs.account_id AND a.company_id=cs.company_id WHERE cs.company_id=? AND cs.status='active' AND cs.system_key IN (?,?) ORDER BY cs.system_key");$q->execute([$company['id'],...$keys]);$configured=$q->fetchAll(PDO::FETCH_ASSOC);if(count($configured)>1&&count(array_unique(array_column($configured,'id')))>1)return null;if($configured)return $configured[0];
    $q=db()->prepare('SELECT id,code,name FROM accounts WHERE company_id=? AND code=?');$q->execute([$company['id'],$ar?'1200':'2050']);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function tegh_report_inventory_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_search_sql_5980(['ps.code','ps.name','i.number'],$p['q'],$params);
    $sales=tegh_report_query_5980("SELECT i.issue_date date,i.number document,ps.code,ps.name,il.quantity_milli quantityMilli,il.amount_cents valueCents,i.status,il.id FROM invoice_lines il JOIN invoices i ON i.id=il.invoice_id JOIN products_services ps ON ps.id=il.product_service_id AND ps.company_id=i.company_id WHERE i.company_id=? AND ps.kind='product' AND i.issue_date BETWEEN ? AND ? AND i.status IN ('sent','paid') $where ORDER BY i.issue_date,i.id,il.id",$params);
    $params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_search_sql_5980(['ps.code','ps.name','b.number'],$p['q'],$params);
    $buys=tegh_report_query_5980("SELECT b.bill_date date,b.number document,ps.code,ps.name,b.quantity_milli quantityMilli,b.subtotal_cents valueCents,b.status,b.id FROM bills b JOIN products_services ps ON ps.id=b.product_service_id AND ps.company_id=b.company_id WHERE b.company_id=? AND ps.kind='product' AND b.bill_date BETWEEN ? AND ? AND b.status IN ('open','paid') $where ORDER BY b.bill_date,b.id",$params);
    $rows=[];foreach([['Sales',$sales],['Purchases',$buys]] as [$type,$list])foreach($list as $r){$r['activity']=$type;$r['quantity']=(int)$r['quantityMilli']/1000;$r['valueCents']=(int)$r['valueCents'];$rows[]=$r;}usort($rows,static fn($a,$b)=>[$a['date'],$a['activity'],$a['document'],$a['id']]<=>[$b['date'],$b['activity'],$b['document'],$b['id']]);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['date','Document date','date',13],['activity','Activity','text',15],['document','Document','identifier',18],['code','Item code','identifier',14],['name','Product','text',30],['quantity','Quantity','number',15],['valueCents','Base amount','money',17],['status','Document status','text',16]]),$rows,['activityRows'=>count($rows)]);$m['postingScope']='Issued non-void product invoice/bill activity only. Services are excluded. This is not quantity on hand, cost of goods sold or inventory valuation.';return $m;
}
function tegh_report_bill_document_5980(array $company,array $d,array $p): array
{
    $q=db()->prepare('SELECT b.*,v.name vendor_name,v.address vendor_address,v.email vendor_email,a.code account_code,a.name account_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id JOIN accounts a ON a.id=b.category_account_id AND a.company_id=b.company_id WHERE b.id=? AND b.company_id=?');$q->execute([$p['documentId'],$company['id']]);$bill=$q->fetch(PDO::FETCH_ASSOC);if(!$bill)fail('This vendor bill is unavailable.',404,'bill_output_unavailable',false);
    $currency=(string)$bill['currency'];$base=$currency===(string)$company['currency'];$pick=static fn(string $key):int=>$base&&(int)$bill['foreign_'.$key]===0&&(int)$bill[$key]!==0?(int)$bill[$key]:(int)$bill['foreign_'.$key];
    $rows=[['description'=>(string)$bill['memo'],'accountCode'=>$bill['account_code'],'accountName'=>$bill['account_name'],'quantity'=>(int)$bill['quantity_milli']/1000,'subtotalCents'=>$pick('subtotal_cents'),'taxCents'=>$pick('tax_cents'),'totalCents'=>$pick('total_cents')]];
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['description','Description','text',36],['accountCode','Account code','identifier',13],['accountName','Account name','text',25],['quantity','Quantity','number',12],['subtotalCents','Subtotal','money',16],['taxCents','Tax','money',14],['totalCents','Total','money',16]]),$rows,['subtotalCents'=>$pick('subtotal_cents'),'taxCents'=>$pick('tax_cents'),'totalCents'=>$pick('total_cents'),'balanceDueCents'=>$bill['status']==='void'?0:$pick('balance_cents')]);
    $m['kind']='bill';$m['title']='Vendor Bill '.$bill['number'];$m['currency']=$currency;$m['subtitle']='Vendor: '.$bill['vendor_name'].' · Status: '.$bill['status'];$m['period']=['mode'=>'asOf','asOf'=>$bill['bill_date'],'start'=>null,'end'=>null,'inclusive'=>true];
    $m['document']=['billId'=>$bill['id'],'number'=>$bill['number'],'issueDate'=>$bill['bill_date'],'dueDate'=>$bill['due_date'],'status'=>$bill['status'],'reference'=>$bill['import_reference'],'watermark'=>$bill['status']==='void'?'VOID':($bill['status']==='draft'?'DRAFT':null)];
    $m['vendor']=['name'=>$bill['vendor_name'],'address'=>$bill['vendor_address'],'email'=>$bill['vendor_email']];$m['postingScope']='Tegh vendor-bill record, not a customer invoice or reproduction of an external supplier’s original document. Vendor contact details are current master data. The stored bill has one category line; this output does not invent journal lines.';$m['currencyQualifications']='Bill amounts are presented in '.$currency.'; the base ledger remains '.$company['currency'].'.';return $m;
}
function tegh_report_remittances_5980(array $company,array $d,array $p): array
{
    $params=[$company['id'],$p['start'],$p['end']];$where=tegh_report_status_sql_5980('r.status',$p['status'],['posted','reversed'],$params).tegh_report_search_sql_5980(['r.id','b.name'],$p['q'],$params);
    $rows=tegh_report_query_5980("SELECT r.id,r.period_end periodEnd,r.payment_date paymentDate,b.name bankAccount,r.employee_tax_cents taxCents,r.cpp_cents cppCents,r.ei_cents eiCents,r.total_cents totalCents,r.status,r.journal_entry_id journalId,r.reversal_journal_entry_id reversalJournalId FROM payroll_remittances r LEFT JOIN bank_accounts b ON b.id=r.bank_account_id AND b.company_id=r.company_id WHERE r.company_id=? AND r.payment_date BETWEEN ? AND ? $where ORDER BY r.payment_date,r.id",$params);
    $totals=['taxCents'=>0,'cppCents'=>0,'eiCents'=>0,'totalCents'=>0];foreach($rows as &$r)foreach(array_keys($totals) as $key){$r[$key]=(int)$r[$key];if($r['status']==='posted')$totals[$key]+=$r[$key];}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['paymentDate','Payment date','date',14],['periodEnd','Period end','date',14],['bankAccount','Financial account','text',25],['taxCents','Employee tax','money',16],['cppCents','CPP remitted','money',16],['eiCents','EI remitted','money',16],['totalCents','Total','money',16],['status','Status','text',14]]),$rows,$totals);$m['postingScope']='Recorded remittance payments by payment date; reversed payments are retained history and excluded from totals. Not a filing confirmation.';return $m;
}
function tegh_report_tax_mapping_5980(array $company,array $d,array $p): array
{
    $rows=tegh_report_query_5980("SELECT a.code,a.name,a.account_type type,a.normal_balance normalBalance,a.gifi_code gifiCode,a.t2125_line t2125Line,COALESCE(SUM(CASE WHEN je.id IS NULL THEN 0 WHEN a.account_type IN ('income','expense') AND je.entry_date>=? THEN jl.debit_cents-jl.credit_cents WHEN a.account_type NOT IN ('income','expense') THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) amountCents FROM accounts a LEFT JOIN journal_lines jl ON jl.account_id=a.id LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id AND je.status='posted' AND je.entry_date<=? WHERE a.company_id=? GROUP BY a.id,a.code,a.name,a.account_type,a.normal_balance,a.gifi_code,a.t2125_line ORDER BY a.code,a.id",[$p['start'],$p['end'],$company['id']]);
    $exceptions=[];foreach($rows as &$r){$r['amountCents']=(int)$r['amountCents'];if($r['normalBalance']==='credit')$r['amountCents']=-$r['amountCents'];if($r['amountCents']!==0&&empty($r['gifiCode'])&&empty($r['t2125Line']))$exceptions[]=['code'=>'tax_mapping_missing','accountCode'=>$r['code'],'message'=>'GL '.$r['code'].' has no configured tax mapping. No mapping was created or assumed.'];}unset($r);
    $m=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([['code','General Ledger Code','identifier',16],['name','Account','text',32],['type','Type','text',15],['normalBalance','Normal Balance','text',15],['gifiCode','GIFI Code','identifier',13],['t2125Line','T2125 Line','identifier',13],['amountCents','Amount','money',20]]),$rows);$m['postingScope']='Configured mappings only. Income/expense uses period movement; assets/liabilities/equity use closing balances, with account-normal signs. This working paper is not a tax return or filing opinion.';$m['exceptions']=$exceptions;return $m;
}
