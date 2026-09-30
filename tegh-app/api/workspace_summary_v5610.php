<?php
declare(strict_types=1);

/**
 * Build 5610 dashboard projection.
 *
 * This endpoint deliberately returns only the values used by the authenticated
 * home screen. It preserves the same posted-ledger and open-subledger rules as
 * workspace_data(), but it does not materialize every customer, document,
 * journal line, audit event, import batch, or bank transaction.
 *
 * @return array<string,mixed>
 */
function tegh_workspace_summary_today(): string
{
    return (new DateTimeImmutable('now',new DateTimeZone('America/Toronto')))->format('Y-m-d');
}

function tegh_workspace_summary_period_start(string $today,string $fiscalYearEnd): string
{
    $year=(int)substr($today,0,4);$currentEnd=sprintf('%04d-%s',$year,$fiscalYearEnd);
    $end=$today>$currentEnd?new DateTimeImmutable($currentEnd,new DateTimeZone('UTC')):new DateTimeImmutable(sprintf('%04d-%s',$year-1,$fiscalYearEnd),new DateTimeZone('UTC'));
    return $end->modify('+1 day')->format('Y-m-d');
}

function tegh_workspace_summary_data(array $company): array
{
    $started = hrtime(true);
    $queries = 0;
    $companyId = (string)$company['id'];
    $today = tegh_workspace_summary_today();
    $periodStart = tegh_workspace_summary_period_start($today, (string)$company['fiscal_year_end']);
    $dueCutoff = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');
    $cashStart = (new DateTimeImmutable(substr($today, 0, 7).'-01', new DateTimeZone('UTC')))->modify('-5 months')->format('Y-m-d');

    $accountStmt = db()->prepare("SELECT a.id,a.code,a.account_type,a.normal_balance,
      COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) signed_balance_cents,
      COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date BETWEEN ? AND ? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) period_signed_balance_cents,
      COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents ELSE 0 END),0) debit_cents,
      COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.credit_cents ELSE 0 END),0) credit_cents
      FROM accounts a
      LEFT JOIN journal_lines jl ON jl.account_id=a.id
      LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.company_id=a.company_id
      WHERE a.company_id=? GROUP BY a.id,a.code,a.account_type,a.normal_balance");
    $accountStmt->execute([$today,$periodStart,$today,$today,$today,$companyId]);$queries++;
    $balances=[];$income=0;$expenses=0;$ledgerDebits=0;$ledgerCredits=0;$accountCount=0;
    foreach($accountStmt->fetchAll() as $row){
        $accountCount++;
        $signed=(int)$row['signed_balance_cents'];$periodSigned=(int)$row['period_signed_balance_cents'];
        $balance=(string)$row['normal_balance']==='debit'?$signed:-$signed;
        $periodBalance=(string)$row['normal_balance']==='debit'?$periodSigned:-$periodSigned;
        $balances[(string)$row['id']]=$balance;$balances['code:'.(string)$row['code']]=$balance;
        if((string)$row['account_type']==='income')$income+=$periodBalance;
        if((string)$row['account_type']==='expense')$expenses+=$periodBalance;
        $ledgerDebits+=(int)$row['debit_cents'];$ledgerCredits+=(int)$row['credit_cents'];
    }

    $bankStmt=db()->prepare("SELECT ba.id,ba.name,ba.account_type,ba.currency,ba.ledger_account_id,ba.statement_balance_cents
      FROM bank_accounts ba WHERE ba.company_id=? AND ba.active=1 ORDER BY ba.account_type,ba.name");
    $bankStmt->execute([$companyId]);$queries++;$bankAccounts=[];$bankBalance=0;$statementBalance=0;
    foreach($bankStmt->fetchAll() as $row){
        $book=(int)($balances[(string)$row['ledger_account_id']]??0);if((string)$row['account_type']==='credit_card')$book=-$book;
        $bankAccounts[]=['id'=>(string)$row['id'],'name'=>(string)$row['name'],'accountType'=>(string)$row['account_type'],'currency'=>(string)$row['currency'],'bookBalanceCents'=>$book,'statementBalanceCents'=>(int)$row['statement_balance_cents']];
        if((string)$row['account_type']==='bank'){$bankBalance+=$book;$statementBalance+=(int)$row['statement_balance_cents'];}
    }

    $invoiceStmt=db()->prepare("SELECT COUNT(*) document_count,
      COALESCE(SUM(CASE WHEN status='sent' THEN balance_cents ELSE 0 END),0) unpaid_cents,
      COALESCE(SUM(CASE WHEN status='sent' AND due_date<? THEN balance_cents ELSE 0 END),0) overdue_cents,
      SUM(CASE WHEN status='sent' AND balance_cents>0 THEN 1 ELSE 0 END) open_count,
      MAX(issue_date) latest_date FROM invoices WHERE company_id=?");
    $invoiceStmt->execute([$today,$companyId]);$queries++;$invoice=$invoiceStmt->fetch()?:[];

    $billStmt=db()->prepare("SELECT COUNT(*) document_count,
      COALESCE(SUM(CASE WHEN status='open' THEN balance_cents ELSE 0 END),0) payable_cents,
      SUM(CASE WHEN status='open' AND balance_cents>0 THEN 1 ELSE 0 END) open_count,
      SUM(CASE WHEN status='open' AND balance_cents>0 AND due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) due_soon_count,
      MAX(bill_date) latest_date FROM bills WHERE company_id=?");
    $billStmt->execute([$today,$dueCutoff,$companyId]);$queries++;$bill=$billStmt->fetch()?:[];

    $openingStmt=db()->prepare("SELECT party_type,COALESCE(SUM(amount_cents),0) amount_cents FROM party_opening_balances WHERE company_id=? GROUP BY party_type");
    $openingStmt->execute([$companyId]);$queries++;$customerOpening=0;$vendorOpening=0;
    foreach($openingStmt->fetchAll() as $row){if((string)$row['party_type']==='customer')$customerOpening+=(int)$row['amount_cents'];if((string)$row['party_type']==='vendor')$vendorOpening+=(int)$row['amount_cents'];}

    $bankStatsStmt=db()->prepare("SELECT COUNT(*) transaction_count,
      SUM(status='pending') pending_count,SUM(status='duplicate') duplicate_count,MAX(transaction_date) latest_date
      FROM bank_transactions WHERE company_id=?");
    $bankStatsStmt->execute([$companyId]);$queries++;$bankStats=$bankStatsStmt->fetch()?:[];

    $recentStmt=db()->prepare("SELECT bt.id,bt.bank_account_id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.currency,bt.status,bt.confidence,ba.name bank_account_name
      FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id
      WHERE bt.company_id=? AND bt.status='pending' ORDER BY bt.transaction_date DESC,bt.created_at DESC LIMIT 3");
    $recentStmt->execute([$companyId]);$queries++;
    $recent=array_map(static fn(array $row):array=>['id'=>(string)$row['id'],'bankAccountId'=>(string)$row['bank_account_id'],'bankAccountName'=>(string)$row['bank_account_name'],'transactionDate'=>(string)$row['transaction_date'],'description'=>(string)$row['description'],'reference'=>$row['reference']!==null?(string)$row['reference']:null,'amountCents'=>(int)$row['amount_cents'],'currency'=>(string)$row['currency'],'status'=>(string)$row['status'],'confidence'=>(int)$row['confidence']],$recentStmt->fetchAll());

    $cashStmt=db()->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') bucket,
      COALESCE(SUM(CASE WHEN amount_cents>=0 THEN amount_cents ELSE 0 END),0) incoming_cents,
      COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) outgoing_cents
      FROM bank_transactions WHERE company_id=? AND status<>'excluded' AND transaction_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(transaction_date,'%Y-%m')");
    $cashStmt->execute([$companyId,$cashStart,$today]);$queries++;$cashByMonth=[];
    foreach($cashStmt->fetchAll() as $row)$cashByMonth[(string)$row['bucket']]=['incoming'=>(int)$row['incoming_cents'],'outgoing'=>(int)$row['outgoing_cents']];
    $cashFlow=[];$cursor=new DateTimeImmutable($cashStart,new DateTimeZone('UTC'));$maxCash=1;
    for($i=0;$i<6;$i++){$key=$cursor->format('Y-m');$values=$cashByMonth[$key]??['incoming'=>0,'outgoing'=>0];$maxCash=max($maxCash,$values['incoming'],$values['outgoing']);$cashFlow[]=['key'=>$key,'label'=>$cursor->format('M')]+$values;$cursor=$cursor->modify('+1 month');}
    foreach($cashFlow as &$bucket){$bucket['inHeight']=max(8,(int)round($bucket['incoming']*100/$maxCash));$bucket['outHeight']=max(8,(int)round($bucket['outgoing']*100/$maxCash));}unset($bucket);

    $evidenceStmt=db()->prepare("SELECT
      (SELECT COUNT(*) FROM customers WHERE company_id=?) customers,
      (SELECT COUNT(*) FROM vendors WHERE company_id=?) vendors,
      (SELECT COUNT(*) FROM expenses WHERE company_id=?) expenses,
      (SELECT COUNT(*) FROM reconciliations WHERE company_id=?) reconciliations,
      (SELECT COUNT(*) FROM payroll_employees WHERE company_id=?) employees,
      (SELECT COUNT(*) FROM payroll_runs WHERE company_id=?) payroll_runs,
      (SELECT COUNT(*) FROM opening_balance_imports WHERE company_id=?) opening_imports,
      (SELECT COUNT(*) FROM opening_balance_drafts WHERE company_id=? AND amount_cents<>0) opening_drafts,
      (SELECT MAX(entry_date) FROM journal_entries WHERE company_id=?) latest_journal_date");
    $evidenceStmt->execute(array_fill(0,9,$companyId));$queries++;$evidence=$evidenceStmt->fetch()?:[];

    $lastReconStmt=db()->prepare("SELECT period_end,status FROM reconciliations WHERE company_id=? AND status='complete' ORDER BY period_end DESC LIMIT 1");
    $lastReconStmt->execute([$companyId]);$queries++;$lastRecon=$lastReconStmt->fetch()?:null;

    $unpaid=(int)($invoice['unpaid_cents']??0)+$customerOpening;
    $payable=(int)($bill['payable_cents']??0)+$vendorOpening;
    $receivableControl=(int)($balances['code:1200']??0);$payableControl=(int)($balances['code:2050']??0);
    $accrual=(string)$company['accounting_basis']==='accrual';
    $activityDates=array_filter([(string)($invoice['latest_date']??''),(string)($bill['latest_date']??''),(string)($bankStats['latest_date']??''),(string)($evidence['latest_journal_date']??'')]);
    $currentThrough=$activityDates?max($activityDates):$today;
    $durationMs=(hrtime(true)-$started)/1e6;
    header('Server-Timing: workspace-summary;dur='.number_format($durationMs,2,'.',''));
    header('X-Tegh-Query-Count: '.(string)$queries);
    return [
      'mode'=>'summary','organization'=>['id'=>$companyId,'name'=>(string)$company['name'],'currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis'],'fiscalYearEnd'=>(string)$company['fiscal_year_end'],'fiscalYearEndDate'=>$company['fiscal_year_end_date']!==null?(string)$company['fiscal_year_end_date']:null,'booksStartDate'=>$company['books_start_date']!==null?(string)$company['books_start_date']:null],
      'currentThrough'=>$currentThrough,'bankAccounts'=>$bankAccounts,'bankTransactions'=>$recent,'cashFlow'=>$cashFlow,
      'summary'=>['periodStart'=>$periodStart,'periodEnd'=>$today,'bankBalanceCents'=>$bankBalance,'statementBalanceCents'=>$statementBalance,'unpaidInvoicesCents'=>$unpaid,'overdueInvoicesCents'=>(int)($invoice['overdue_cents']??0),'openInvoiceCount'=>(int)($invoice['open_count']??0),'openBillCount'=>(int)($bill['open_count']??0),'dueSoonBillCount'=>(int)($bill['due_soon_count']??0),'receivableControlCents'=>$receivableControl,'receivableSubledgerCents'=>$unpaid,'receivableDifferenceCents'=>$accrual?$receivableControl-$unpaid:0,'receivableControlApplicable'=>$accrual,'payableControlCents'=>$payableControl,'payableSubledgerCents'=>$payable,'payableDifferenceCents'=>$accrual?$payableControl-$payable:0,'payableControlApplicable'=>$accrual,'taxPayableCents'=>max(0,(int)($balances['code:2100']??0)-(int)($balances['code:1100']??0)),'taxSummary'=>['gstHstCollectedCents'=>(int)($balances['code:2100']??0),'gstHstRecoverableCents'=>(int)($balances['code:1100']??0),'gstHstNetCents'=>(int)($balances['code:2100']??0)-(int)($balances['code:1100']??0),'pstPayableCents'=>(int)($balances['code:2110']??0),'pstRecoverableCents'=>(int)($balances['code:1110']??0),'qstPayableCents'=>(int)($balances['code:2115']??0),'qstRecoverableCents'=>(int)($balances['code:1115']??0)],'transactionsToReview'=>(int)($bankStats['pending_count']??0),'duplicateTransactions'=>(int)($bankStats['duplicate_count']??0),'incomeYtdCents'=>$income,'expensesYtdCents'=>$expenses,'netIncomeYtdCents'=>$income-$expenses,'ledgerDebitsCents'=>$ledgerDebits,'ledgerCreditsCents'=>$ledgerCredits,'ledgerDifferenceCents'=>$ledgerDebits-$ledgerCredits],
      'workflowEvidence'=>['accounts'=>$accountCount,'customers'=>(int)($evidence['customers']??0),'vendors'=>(int)($evidence['vendors']??0),'documents'=>(int)($invoice['document_count']??0)+(int)($bill['document_count']??0)+(int)($evidence['expenses']??0),'bankTransactions'=>(int)($bankStats['transaction_count']??0),'reconciliations'=>(int)($evidence['reconciliations']??0),'payroll'=>(int)($evidence['employees']??0)+(int)($evidence['payroll_runs']??0),'opening'=>(int)($evidence['opening_imports']??0)+(int)($evidence['opening_drafts']??0)],
      'openingBalanceStatus'=>['posted'=>(int)($evidence['opening_imports']??0)>0,'importCount'=>(int)($evidence['opening_imports']??0),'draftCount'=>(int)($evidence['opening_drafts']??0)],
      'lastCompletedReconciliation'=>$lastRecon?['periodEnd'=>(string)$lastRecon['period_end'],'status'=>(string)$lastRecon['status']]:null,
      'performance'=>['queryCount'=>$queries,'durationMs'=>round($durationMs,2),'projection'=>'dashboard-summary-v5610'],
      'accountingWrites'=>0,
    ];
}

function handle_tegh_workspace_summary(): never
{
    require_method('GET');$user=require_user();$company=require_company($user);
    json_response(tegh_workspace_summary_data($company));
}
