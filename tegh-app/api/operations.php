<?php
declare(strict_types=1);

require_once __DIR__ . '/reconciliation_position_r122.php';

const SR_OFFICIAL_PAYROLL_CALCULATOR_URL = 'https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/payroll-deductions-online-calculator.html';
const SR_OFFICIAL_PAYROLL_FORMULAS_URL = 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas.html';
const SR_OFFICIAL_GIFI_URL = 'https://www.canada.ca/en/revenue-agency/services/forms-publications/publications/rc4088/general-index-financial-information-gifi.html';
const SR_OFFICIAL_T2125_URL = 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t2125.html';

function operations_json_decode(string $value, string $label): array
{
    try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { fail($label . ' is invalid.'); }
    if (!is_array($decoded)) fail($label . ' must be a list or object.');
    return $decoded;
}

function operations_owner_password(array $user, string $password): void
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? AND active = 1');
    $stmt->execute([$user['id']]);
    $hash = (string)$stmt->fetchColumn();
    if ($hash === '' || !password_verify($password, $hash)) {
        usleep(400000);
        fail('The login password is incorrect.', 403, 'password_incorrect');
    }
}

function operations_bank_balance(string $companyId, string $ledgerAccountId, string $throughDate): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0)
        FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE je.company_id=? AND jl.account_id=? AND je.status='posted' AND je.entry_date<=?");
    $stmt->execute([$companyId,$ledgerAccountId,$throughDate]);
    return (int)$stmt->fetchColumn();
}

function operations_statement_row_fingerprint(string $companyId,string $bankAccountId,string $currency,array $row,int $occurrence): string
{
    $base=$companyId.'|'.$bankAccountId.'|'.(string)$row['date'].'|'.mb_strtoupper(trim((string)($row['fullDescription']??$row['description']))).'|'.(int)$row['foreignAmountCents'].'|'.strtoupper($currency);
    return hash('sha256',$base.'|occurrence:'.$occurrence);
}

function operations_statement_rows_with_keys(string $companyId,string $bankAccountId,string $currency,array $rows): array
{
    $occ=[];$result=[];
    foreach($rows as $row){
        $wording=mb_strtoupper(trim((string)($row['fullDescription']??$row['description'])));
        $base=(string)$row['date'].'|'.$wording.'|'.(int)$row['foreignAmountCents'].'|'.strtoupper($currency);
        $number=($occ[$base]??0)+1;$occ[$base]=$number;
        $fingerprint=operations_statement_row_fingerprint($companyId,$bankAccountId,$currency,$row,$number);
        $legacyBase=$companyId.'|'.$bankAccountId.'|'.(string)$row['date'].'|'.$wording.'|'.(int)$row['foreignAmountCents'];
        $row['selectionKey']='stmt_'.substr($fingerprint,0,40);$row['sourceFingerprint']=$fingerprint;
        $row['legacySourceFingerprint']=hash('sha256',$legacyBase.($number===1?'':'|occurrence:'.$number));
        $row['sourceBankAccountId']=$bankAccountId;$row['sourceCurrency']=strtoupper($currency);$row['sourceOccurrence']=$number;
        $result[]=$row;
    }
    return $result;
}

/**
 * Recompute duplicate status from committed current-company evidence. Long
 * descriptions keep their full identity; an older compact-only record can be
 * a possible duplicate but is never silently treated as a proven duplicate.
 */
function operations_statement_mark_duplicates(string $companyId,array $rows): array
{
    $fingerprints=[];
    foreach($rows as $row)foreach(['sourceFingerprint','legacySourceFingerprint'] as $field)if(!empty($row[$field]))$fingerprints[(string)$row[$field]]=true;
    $existing=[];
    foreach(array_chunk(array_keys($fingerprints),400) as $chunk){
        $placeholders=implode(',',array_fill(0,count($chunk),'?'));
        $stmt=db()->prepare("SELECT source_hash FROM bank_transactions WHERE company_id=? AND source_hash IN ($placeholders)");
        $stmt->execute(array_merge([$companyId],$chunk));foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash)$existing[(string)$hash]=true;
    }
    $dateGroups=[];
    foreach($rows as $row)if(mb_strlen((string)$row['description'])>=499&&!empty($row['sourceBankAccountId']))$dateGroups[(string)$row['sourceBankAccountId']][(string)$row['date']]=true;
    $candidates=[];$previewIds=[];
    foreach($dateGroups as $bankId=>$dates)foreach(array_chunk(array_keys($dates),400) as $chunk){
        $placeholders=implode(',',array_fill(0,count($chunk),'?'));
        $stmt=db()->prepare("SELECT bt.id,bt.bank_account_id,bt.transaction_date,bt.description,bt.foreign_amount_cents,bt.amount_cents,bt.currency,bt.source_hash,ib.preview_id FROM bank_transactions bt LEFT JOIN import_batches ib ON ib.id=bt.import_batch_id AND ib.company_id=bt.company_id WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.transaction_date IN ($placeholders) AND CHAR_LENGTH(bt.description)>=499");
        $stmt->execute(array_merge([$companyId,$bankId],$chunk));
        foreach($stmt->fetchAll() as $candidate){$key=(string)$candidate['bank_account_id'].'|'.(string)$candidate['transaction_date'].'|'.mb_strtoupper(trim((string)$candidate['description'])).'|'.(int)($candidate['foreign_amount_cents']??$candidate['amount_cents']).'|'.strtoupper((string)$candidate['currency']);$candidates[$key][]=$candidate;if(!empty($candidate['preview_id']))$previewIds[(string)$candidate['preview_id']]=true;}
    }
    // Read each potentially relevant preview once, not once per transaction.
    $fullEvidence=[];
    foreach(array_chunk(array_keys($previewIds),100) as $chunk){
        $placeholders=implode(',',array_fill(0,count($chunk),'?'));
        $stmt=db()->prepare("SELECT id,rows_json FROM statement_previews WHERE company_id=? AND id IN ($placeholders)");$stmt->execute(array_merge([$companyId],$chunk));
        foreach($stmt->fetchAll() as $preview){$stored=json_decode((string)$preview['rows_json'],true);if(!is_array($stored))continue;foreach($stored as $row)if(is_array($row)&&isset($row['fullDescription'],$row['sourceFingerprint']))$fullEvidence[(string)$preview['id']][(string)$row['sourceFingerprint']]=['description'=>mb_strtoupper(trim((string)$row['fullDescription'])),'occurrence'=>isset($row['sourceOccurrence'])?(int)$row['sourceOccurrence']:null];}
    }
    foreach($rows as &$row){
        $duplicate=isset($existing[(string)($row['sourceFingerprint']??'')])||isset($existing[(string)($row['legacySourceFingerprint']??'')]);$ambiguous=[];
        if(!$duplicate&&mb_strlen((string)$row['description'])>=499){
            $key=(string)($row['sourceBankAccountId']??'').'|'.(string)$row['date'].'|'.mb_strtoupper(trim((string)$row['description'])).'|'.(int)$row['foreignAmountCents'].'|'.strtoupper((string)($row['sourceCurrency']??$row['currency']??''));
            foreach($candidates[$key]??[] as $candidate){$known=$fullEvidence[(string)($candidate['preview_id']??'')][(string)$candidate['source_hash']]??null;
                if($known!==null){
                    $wording=mb_strtoupper(trim((string)($row['fullDescription']??$row['description'])));
                    if($known['description']!==$wording)continue; // Proven different full wording, including the tail.
                    if($known['occurrence']!==null&&$known['occurrence']>0){
                        if($known['occurrence']===(int)($row['sourceOccurrence']??0)){$duplicate=true;$ambiguous=[];break;}
                        continue; // Same wording, but a separately retained occurrence.
                    }
                }
                $ambiguous[]=(string)$candidate['id'];
            }
        }
        $row['duplicate']=$duplicate;$row['requiresDuplicateReview']=!$duplicate&&$ambiguous!==[];$row['legacyDuplicateIds']=array_values(array_unique($ambiguous));
        $row['eligible']=!$duplicate&&empty($row['userExcluded']);
        $row['validationReason']=$duplicate?'Already imported for this financial account; this source line cannot be selected again.':(!empty($row['userExcluded'])?'Excluded during statement review.':($ambiguous!==[]?'Possible duplicate: an older imported record has the same date, amount and first 500 description characters. Compare the source statements before confirming this is a separate transaction.':'Ready for selection.'));
    }unset($row);
    return $rows;
}

function operations_workspace(array $user, array $company): never
{
    require_method('GET');
    $companyId = (string)$company['id'];
    $canViewPayroll = company_role_can((string)$company['role'],'payroll.view');
    $stmt=db()->prepare('SELECT * FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY account_type,name');
    $stmt->execute([$companyId]);
    $banks=array_map(static fn(array $r):array=>array_merge([
        'id'=>(string)$r['id'],'name'=>(string)$r['name'],'accountType'=>(string)$r['account_type'],
        'maskedNumber'=>$r['masked_number'],'currency'=>(string)$r['currency'],'statementBalanceCents'=>(int)$r['statement_balance_cents'],
        'lastReconciledDate'=>$r['last_reconciled_date'],'ledgerAccountId'=>(string)$r['ledger_account_id'],'active'=>(bool)$r['active'],
    ],function_exists('tegh_bank_profile')?tegh_bank_profile($r):[]),$stmt->fetchAll());
    $stmt=db()->prepare('SELECT id,code,name,account_type,normal_balance,is_control,active,gifi_code,t2125_line,reporting_group,expense_category FROM accounts WHERE company_id=? ORDER BY code');
    $stmt->execute([$companyId]);
    $accounts=array_map(static fn(array $r):array=>[
        'id'=>(string)$r['id'],'code'=>(string)$r['code'],'name'=>(string)$r['name'],'type'=>(string)$r['account_type'],
        'normalBalance'=>(string)$r['normal_balance'],'isControl'=>(bool)$r['is_control'],'active'=>(bool)$r['active'],
        'gifiCode'=>$r['gifi_code'],'t2125Line'=>$r['t2125_line'],'reportingGroup'=>$r['reporting_group'],'expenseCategory'=>$r['expense_category'],
    ],$stmt->fetchAll());
    $stmt=db()->prepare('SELECT id,bank_account_id,filename,currency,opening_balance_cents,closing_balance_cents,first_transaction_date,last_transaction_date,row_count,excluded_count,status,approved_batch_id,created_at FROM statement_previews WHERE company_id=? ORDER BY created_at DESC LIMIT 30');
    $stmt->execute([$companyId]);
    $previews=array_map(static fn(array $r):array=>[
        'id'=>(string)$r['id'],'bankAccountId'=>(string)$r['bank_account_id'],'filename'=>(string)$r['filename'],'currency'=>(string)$r['currency'],
        'openingBalanceCents'=>$r['opening_balance_cents']===null?null:(int)$r['opening_balance_cents'],
        'closingBalanceCents'=>$r['closing_balance_cents']===null?null:(int)$r['closing_balance_cents'],
        'firstDate'=>$r['first_transaction_date'],'lastDate'=>$r['last_transaction_date'],'rowCount'=>(int)$r['row_count'],
        'excludedCount'=>(int)$r['excluded_count'],'status'=>(string)$r['status'],'approvedBatchId'=>$r['approved_batch_id'],'createdAt'=>(string)$r['created_at'],
    ],$stmt->fetchAll());
    $stmt=db()->prepare('SELECT id,period_start,period_end,locked,reason,updated_at FROM period_locks WHERE company_id=? ORDER BY period_end DESC LIMIT 36');
    $stmt->execute([$companyId]);
    $locks=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'periodStart'=>(string)$r['period_start'],'periodEnd'=>(string)$r['period_end'],'locked'=>(bool)$r['locked'],'reason'=>(string)$r['reason'],'updatedAt'=>(string)$r['updated_at']],$stmt->fetchAll());
    $stmt=db()->prepare('SELECT r.*,ba.name AS bank_name,u.display_name AS prepared_name,rv.display_name AS reviewed_name FROM reconciliations r JOIN bank_accounts ba ON ba.id=r.bank_account_id LEFT JOIN users u ON u.id=COALESCE(r.prepared_by,r.created_by) LEFT JOIN users rv ON rv.id=r.reviewed_by WHERE r.company_id=? ORDER BY r.period_end DESC LIMIT 36');
    $stmt->execute([$companyId]);
    $reconciliations=array_map(static fn(array $r):array=>[
        'id'=>(string)$r['id'],'bankAccountId'=>(string)$r['bank_account_id'],'bankName'=>(string)$r['bank_name'],'periodStart'=>$r['period_start'],
        'periodEnd'=>(string)$r['period_end'],'statementBalanceCents'=>(int)$r['statement_balance_cents'],'bookBalanceCents'=>(int)$r['book_balance_cents'],
        'differenceCents'=>(int)$r['difference_cents'],'status'=>(string)$r['status'],'notes'=>(string)($r['notes']??''),'preparedName'=>$r['prepared_name'],
        'reviewedName'=>$r['reviewed_name'],'completedAt'=>$r['completed_at'],'reopenedAt'=>$r['reopened_at'],'reopenReason'=>$r['reopen_reason'],
    ],$stmt->fetchAll());
    $rates=[];$levyProfile=null;$levyRates=[];$payrollRuns=[];
    if($canViewPayroll){
        $stmt=db()->prepare('SELECT id,jurisdiction,person_type,rate_key,label,effective_from,effective_to,value_decimal,value_cents,threshold_min_cents,threshold_max_cents,source_url,source_label,status,created_at FROM statutory_rates WHERE company_id=? ORDER BY rate_key,effective_from DESC');
        $stmt->execute([$companyId]);
        $rates=array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'jurisdiction'=>(string)$r['jurisdiction'],'personType'=>(string)$r['person_type'],'rateKey'=>(string)$r['rate_key'],
            'label'=>(string)$r['label'],'effectiveFrom'=>(string)$r['effective_from'],'effectiveTo'=>$r['effective_to'],
            'valueDecimal'=>$r['value_decimal']===null?null:(float)$r['value_decimal'],'valueCents'=>$r['value_cents']===null?null:(int)$r['value_cents'],
            'thresholdMinCents'=>$r['threshold_min_cents']===null?null:(int)$r['threshold_min_cents'],'thresholdMaxCents'=>$r['threshold_max_cents']===null?null:(int)$r['threshold_max_cents'],
            'sourceUrl'=>$r['source_url'],'sourceLabel'=>$r['source_label'],'status'=>(string)$r['status'],'createdAt'=>(string)$r['created_at'],
        ],$stmt->fetchAll());
        $stmt=db()->prepare('SELECT * FROM employer_levy_profiles WHERE company_id=?');$stmt->execute([$companyId]);$levy=$stmt->fetch();
        $levyProfile=$levy?[
            'jurisdiction'=>(string)$levy['jurisdiction'],'applicability'=>(string)$levy['applicability'],'postingMode'=>(string)$levy['posting_mode'],
            'eligibleForExemption'=>(bool)$levy['eligible_for_exemption'],'associatedGroupPayrollCents'=>(int)$levy['associated_group_payroll_cents'],
            'expenseAccountId'=>$levy['expense_account_id'],'payableAccountId'=>$levy['payable_account_id'],
        ]:null;
        $stmt=db()->prepare('SELECT id,jurisdiction,label,effective_from,effective_to,payroll_min_cents,payroll_max_cents,exemption_cents,exemption_threshold_cents,rate_bps,rate_decimal,source_url,active FROM employer_levy_rates WHERE company_id=? ORDER BY effective_from DESC,payroll_min_cents');
        $stmt->execute([$companyId]);
        $levyRates=array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'jurisdiction'=>(string)$r['jurisdiction'],'label'=>(string)$r['label'],'effectiveFrom'=>(string)$r['effective_from'],'effectiveTo'=>$r['effective_to'],
            'payrollMinCents'=>(int)$r['payroll_min_cents'],'payrollMaxCents'=>$r['payroll_max_cents']===null?null:(int)$r['payroll_max_cents'],
            'exemptionCents'=>(int)$r['exemption_cents'],'exemptionThresholdCents'=>$r['exemption_threshold_cents']===null?null:(int)$r['exemption_threshold_cents'],
            'rateBps'=>(int)$r['rate_bps'],'rateDecimal'=>$r['rate_decimal']===null?((int)$r['rate_bps']/10000):(float)$r['rate_decimal'],'sourceUrl'=>$r['source_url'],'active'=>(bool)$r['active'],
        ],$stmt->fetchAll());
        $stmt=db()->prepare("SELECT pr.id,pr.pay_date,pr.period_start,pr.period_end,pr.status,pr.calculation_version,pr.verification_reference,pr.gross_pay_cents,
            SUM(CASE WHEN pv.method='official_calculator' THEN 1 ELSE 0 END) AS official_count
            FROM payroll_runs pr LEFT JOIN payroll_verifications pv ON pv.payroll_run_id=pr.id
            WHERE pr.company_id=? GROUP BY pr.id ORDER BY pr.pay_date DESC,pr.created_at DESC LIMIT 30");
        $stmt->execute([$companyId]);
        $payrollRuns=array_map(static fn(array $r):array=>[
            'id'=>(string)$r['id'],'payDate'=>(string)$r['pay_date'],'periodStart'=>(string)$r['period_start'],'periodEnd'=>(string)$r['period_end'],
            'status'=>(string)$r['status'],'calculationVersion'=>(string)$r['calculation_version'],'verificationReference'=>$r['verification_reference'],
            'grossPayCents'=>(int)$r['gross_pay_cents'],'officialVerificationCount'=>(int)$r['official_count'],
        ],$stmt->fetchAll());
    }
    json_response(['organization'=>[
        'id'=>$companyId,'name'=>(string)$company['name'],'legalName'=>(string)$company['legal_name'],'businessType'=>(string)$company['business_type'],
        'province'=>(string)$company['province'],'country'=>(string)($company['country']??'Canada'),'currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis'],
        'moduleMode'=>(string)($company['module_mode']??'both'),'payrollPostingMode'=>$canViewPayroll?(string)($company['payroll_posting_mode']??'draft'):null,
        'taxReportingProfile'=>(string)($company['tax_reporting_profile']??'none'),'reportingFramework'=>(string)($company['reporting_framework']??'not_set'),
        'fiscalYearEnd'=>(string)$company['fiscal_year_end'],
        'fiscalYearEndDate'=>$company['fiscal_year_end_date']!==null?(string)$company['fiscal_year_end_date']:null,
        'booksStartDate'=>$company['books_start_date']!==null?(string)$company['books_start_date']:null,
        'role'=>(string)$company['role'],
    ],'bankAccounts'=>$banks,'accounts'=>$accounts,'statementPreviews'=>$previews,'periodLocks'=>$locks,'reconciliations'=>$reconciliations,
      'rates'=>$rates,'levyProfile'=>$levyProfile,'levyRates'=>$levyRates,'payrollRuns'=>$payrollRuns,
      'officialLinks'=>['payrollCalculator'=>SR_OFFICIAL_PAYROLL_CALCULATOR_URL,'payrollFormulas'=>SR_OFFICIAL_PAYROLL_FORMULAS_URL,'gifi'=>SR_OFFICIAL_GIFI_URL,'t2125'=>SR_OFFICIAL_T2125_URL]]);
}

function operations_parse_statement_upload(array $company, array $bankAccount): array
{
    $companyId=(string)$company['id'];
    $upload=save_private_upload($companyId,'statement-previews',statement_allowed_extensions(),statement_allowed_mimes());
    try {
        $excluded=0;$extraction=null;
        if($upload['extension']==='csv')$rows=parse_csv_statement($upload['absolutePath'],$excluded);
        else $rows=parse_xlsx_statement_v5990($upload['absolutePath'],$excluded);
        if($rows===[])fail('No transaction rows were found in the statement.');
        return ['upload'=>$upload,'rows'=>$rows,'excluded'=>$excluded,'extraction'=>$extraction];
    }catch(Throwable $e){delete_private_file($upload['relativePath']);throw $e;}
}

function operations_statement_preview_rows(array $rows,int $rate,?int &$opening,array &$dates,?int &$closing=null): array
{
    // R121: a blank running-balance cell is "not provided", never 0. When the
    // statement carries its own balance column and no opening balance was
    // entered, the opening is derived from the first row so continuity is
    // still checked and the preview shows real balances instead of $0.00.
    $first=$rows[0]??null;
    if($opening===null&&is_array($first)&&isset($first['sourceRunningBalanceCents'])&&$first['sourceRunningBalanceCents']!==null)$opening=(int)$first['sourceRunningBalanceCents']-(int)$first['amountCents'];
    $last=$rows===[]?null:$rows[array_key_last($rows)];
    if($closing===null&&is_array($last)&&isset($last['sourceRunningBalanceCents'])&&$last['sourceRunningBalanceCents']!==null)$closing=(int)$last['sourceRunningBalanceCents'];
    $running=$opening??0;$mapped=[];foreach($rows as $index=>$row){$running+=(int)$row['amountCents'];$dates[]=(string)$row['date'];$source=isset($row['sourceRunningBalanceCents'])&&$row['sourceRunningBalanceCents']!==null?(int)$row['sourceRunningBalanceCents']:null;if($source!==null&&$opening!==null&&$source!==$running)fail('Running balance does not reconcile at source row '.($row['sourceRow']??($index+2)).'.',422,'statement_running_balance_difference');$shown=$source??($opening!==null?$running:null);$mapped[]=['sequence'=>$index+1,'date'=>(string)$row['date'],'description'=>(string)$row['description'],'reference'=>(string)($row['reference']??''),'foreignAmountCents'=>(int)$row['amountCents'],'amountCents'=>convert_to_base_cents((int)$row['amountCents'],$rate),'runningBalanceCents'=>$shown,'runningBalanceSource'=>$source!==null?'source':($opening!==null?'calculated':'unavailable')]+statement_description_metadata($row)+(isset($row['sourcePage'])?statement_converter_row_metadata($row):[]);}return $mapped;
}

function operations_statement_preview_from_upload(
    array $user,
    array $company,
    array $bank,
    array $upload,
    string $clientExtraction,
    string $currency,
    int $rate,
    ?int $opening = null,
    ?int $closing = null,
    string $uploadTransport = 'multipart'
): array {
    $companyId=(string)$company['id'];
    try {
        $excluded=0;$extraction=null;
        if($upload['extension']==='csv')$rows=parse_csv_statement($upload['absolutePath'],$excluded);
        else $rows=parse_xlsx_statement_v5990($upload['absolutePath'],$excluded);
        if($rows===[])fail('No transaction rows were found in the statement.');
        foreach($rows as $row)if(!empty($row['currency'])&&strtoupper((string)$row['currency'])!==$currency)fail('A statement Currency value does not match the selected financial account currency.',422,'statement_currency_mismatch');
        $sum=array_sum(array_map(static fn(array $r):int=>(int)$r['amountCents'],$rows));
        $dates=[];$mapped=operations_statement_preview_rows($rows,$rate,$opening,$dates,$closing);$mapped=operations_statement_mark_duplicates($companyId,operations_statement_rows_with_keys($companyId,(string)$bank['id'],$currency,$mapped));
        $calculatedClosing=$opening===null?null:$opening+$sum;$variance=($closing!==null&&$calculatedClosing!==null)?$closing-$calculatedClosing:null;
        $sha=hash_file('sha256',$upload['absolutePath']);$previewId=new_id('preview');
        // R143 (DEF-10): a draft left open by a refreshed or closed page blocked re-uploading the same file, and
        // nothing on screen listed it. Drafts create no bank transactions or GL entries, so a new upload of the
        // same file for the same account replaces the stale draft.
        $stale=db()->prepare("SELECT id,source_path FROM statement_previews WHERE company_id=? AND bank_account_id=? AND source_sha256=? AND status='draft'");$stale->execute([$companyId,$bank['id'],$sha]);
        foreach($stale->fetchAll() as $old){db()->prepare("DELETE FROM statement_previews WHERE id=? AND company_id=? AND status='draft'")->execute([$old['id'],$companyId]);if((string)$old['source_path']!=='')delete_private_file((string)$old['source_path']);audit_event($user,$companyId,'statement.preview_replaced','statement_preview',(string)$old['id'],['filename'=>$upload['originalName'],'replacedBy'=>$previewId]);}
        try{
            db()->prepare('INSERT INTO statement_previews (id,company_id,bank_account_id,filename,file_type,source_path,source_sha256,currency,exchange_rate_micros,opening_balance_cents,closing_balance_cents,first_transaction_date,last_transaction_date,row_count,excluded_count,rows_json,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',?)')
                ->execute([$previewId,$companyId,$bank['id'],$upload['originalName'],$upload['mime'],$upload['relativePath'],$sha,$currency,$rate,$opening,$closing,min($dates),max($dates),count($mapped),$excluded,json_encode($mapped,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$user['id']]);
        }catch(PDOException $e){if((string)$e->getCode()==='23000')fail('This statement file already has an active preview for the selected account. Cancel that preview before uploading it again.',409,'statement_preview_duplicate');throw $e;}
        audit_event($user,$companyId,'statement.previewed','statement_preview',$previewId,['filename'=>$upload['originalName'],'rowCount'=>count($mapped),'openingBalanceCents'=>$opening,'closingBalanceCents'=>$closing,'calculatedClosingBalanceCents'=>$calculatedClosing,'varianceCents'=>$variance,'uploadTransport'=>$uploadTransport]);
        return ['id'=>$previewId,'bankAccountId'=>(string)$bank['id'],'filename'=>$upload['originalName'],'currency'=>$currency,'openingBalanceCents'=>$opening,'closingBalanceCents'=>$closing,'calculatedClosingBalanceCents'=>$calculatedClosing,'varianceCents'=>$variance,'rowCount'=>count($mapped),'duplicateCount'=>count(array_filter($mapped,static fn(array $row):bool=>!empty($row['duplicate']))),'excludedCount'=>$excluded,'rows'=>$mapped];
    } catch(Throwable $e){delete_private_file($upload['relativePath']);throw $e;}
}

function operations_statement_preview(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');
    $companyId=(string)$company['id'];$bankId=clean_text($_POST['bankAccountId']??'','Bank account',64);
    $stmt=db()->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1');$stmt->execute([$bankId,$companyId]);$bank=$stmt->fetch();
    if(!$bank)fail('Choose a valid bank or credit-card account.');
    $parsed=operations_parse_statement_upload($company,$bank);$upload=$parsed['upload'];$rows=$parsed['rows'];
    $currency=safe_currency_code($_POST['currency']??$bank['currency'],'Currency');
    $currencyRow=company_currency($companyId,$currency);if(!$currencyRow)fail('The selected currency is not active for this company.');
    $rate=safe_exchange_rate_micros($_POST['exchangeRateMicros']??$currencyRow['rate_to_base_micros'],$currency,(string)$company['currency']);
    $openingRaw=trim((string)($_POST['openingBalanceCents']??''));$closingRaw=trim((string)($_POST['closingBalanceCents']??''));
    $opening=$openingRaw===''?null:safe_cents($openingRaw,'Opening balance',true);$closing=$closingRaw===''?null:safe_cents($closingRaw,'Closing balance',true);
    $sum=array_sum(array_map(static fn(array $r):int=>(int)$r['amountCents'],$rows));
    $dates=[];$mapped=operations_statement_preview_rows($rows,$rate,$opening,$dates,$closing);$mapped=operations_statement_mark_duplicates($companyId,operations_statement_rows_with_keys($companyId,$bankId,$currency,$mapped));
    $calculatedClosing=$opening===null?null:$opening+$sum;$variance=($closing!==null&&$calculatedClosing!==null)?$closing-$calculatedClosing:null;
    $sha=hash_file('sha256',$upload['absolutePath']);$previewId=new_id('preview');
    try{
        db()->prepare('INSERT INTO statement_previews (id,company_id,bank_account_id,filename,file_type,source_path,source_sha256,currency,exchange_rate_micros,opening_balance_cents,closing_balance_cents,first_transaction_date,last_transaction_date,row_count,excluded_count,rows_json,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'draft\',?)')
            ->execute([$previewId,$companyId,$bankId,$upload['originalName'],$upload['mime'],$upload['relativePath'],$sha,$currency,$rate,$opening,$closing,min($dates),max($dates),count($mapped),(int)$parsed['excluded'],json_encode($mapped,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$user['id']]);
    }catch(PDOException $e){delete_private_file($upload['relativePath']);if((string)$e->getCode()==='23000')fail('This statement file was already previewed for the selected account.',409,'statement_preview_duplicate');throw $e;}
    audit_event($user,$companyId,'statement.previewed','statement_preview',$previewId,['filename'=>$upload['originalName'],'rowCount'=>count($mapped),'openingBalanceCents'=>$opening,'closingBalanceCents'=>$closing,'calculatedClosingBalanceCents'=>$calculatedClosing,'varianceCents'=>$variance]);
    json_response(['preview'=>['id'=>$previewId,'filename'=>$upload['originalName'],'currency'=>$currency,'openingBalanceCents'=>$opening,'closingBalanceCents'=>$closing,'calculatedClosingBalanceCents'=>$calculatedClosing,'varianceCents'=>$variance,'rowCount'=>count($mapped),'duplicateCount'=>count(array_filter($mapped,static fn(array $row):bool=>!empty($row['duplicate']))),'excludedCount'=>(int)$parsed['excluded'],'rows'=>$mapped]],201);
}

function operations_statement_preview_get(array $company): never
{
    require_method('GET');$id=clean_text($_GET['previewId']??'','Statement preview',64);
    $stmt=db()->prepare('SELECT * FROM statement_previews WHERE id=? AND company_id=?');$stmt->execute([$id,$company['id']]);$p=$stmt->fetch();if(!$p)fail('Statement preview not found.',404,'statement_preview_not_found');
    json_response(['preview'=>['id'=>(string)$p['id'],'bankAccountId'=>(string)$p['bank_account_id'],'filename'=>(string)$p['filename'],'currency'=>(string)$p['currency'],'openingBalanceCents'=>$p['opening_balance_cents']===null?null:(int)$p['opening_balance_cents'],'closingBalanceCents'=>$p['closing_balance_cents']===null?null:(int)$p['closing_balance_cents'],'rowCount'=>(int)$p['row_count'],'excludedCount'=>(int)$p['excluded_count'],'status'=>(string)$p['status'],'rows'=>operations_json_decode((string)$p['rows_json'],'Statement preview rows')]]);
}

function operations_statement_preview_update(array $user,array $company): never
{
    require_method('PUT'); require_csrf(); require_company_role($company,'owner','bookkeeper');
    $input=request_json(); $id=clean_text($input['previewId']??'','Statement preview',64);
    $stmt=db()->prepare("SELECT * FROM statement_previews WHERE id=? AND company_id=? AND status='draft' FOR UPDATE");
    db()->beginTransaction();
    try {
        $stmt->execute([$id,$company['id']]); $preview=$stmt->fetch();
        if(!$preview) fail('Editable statement preview not found.',404,'statement_preview_not_found');
        $rows=$input['rows']??operations_json_decode((string)$preview['rows_json'],'Statement rows');
        if(!is_array($rows)||count($rows)<1||count($rows)>5000) fail('The preview must contain between 1 and 5,000 transactions.');
        $opening=array_key_exists('openingBalanceCents',$input)&&$input['openingBalanceCents']!==null?safe_cents($input['openingBalanceCents'],'Opening balance',true):($preview['opening_balance_cents']===null?null:(int)$preview['opening_balance_cents']);
        $closing=array_key_exists('closingBalanceCents',$input)&&$input['closingBalanceCents']!==null?safe_cents($input['closingBalanceCents'],'Closing balance',true):($preview['closing_balance_cents']===null?null:(int)$preview['closing_balance_cents']);
        $rate=(int)$preview['exchange_rate_micros']; $running=$opening; $clean=[]; $dates=[];
        foreach($rows as $index=>$row){
            if(!is_array($row)) fail('A statement row is invalid.');
            $date=safe_date($row['date']??'','Transaction date');assert_not_future_date($date,'Transaction date');
            $full=(string)($row['fullDescription']??'');$descriptionFields=statement_description_fields($full!==''&&mb_substr($full,0,500)===(string)($row['description']??'')?$full:($row['description']??''),'Description in statement row '.($index+1));$description=$descriptionFields['description'];$row=array_replace($row,$descriptionFields);
            $foreign=safe_cents($row['foreignAmountCents']??$row['amountCents']??0,'Transaction amount',true);
            if($foreign===0) fail('A genuine bank transaction cannot have a zero amount.');
            if($running!==null)$running+=$foreign; $dates[]=$date;$source=array_key_exists('sourceRunningBalanceCents',$row)&&$row['sourceRunningBalanceCents']!==null?safe_cents($row['sourceRunningBalanceCents'],'Source running balance',true):null;if($source!==null&&$running!==null&&$source!==$running)fail('Running balance does not reconcile at statement row '.($index+1).'.',422,'statement_running_balance_difference');$shown=$source??$running;
            $clean[]=['sequence'=>$index+1,'date'=>$date,'description'=>$description,'reference'=>optional_text($row['reference']??'',160)??'','foreignAmountCents'=>$foreign,
                'amountCents'=>convert_to_base_cents($foreign,$rate),'runningBalanceCents'=>$shown,'runningBalanceSource'=>$source!==null?'source':($shown!==null?'calculated':'unavailable')]+statement_description_metadata($row)+(isset($row['sourcePage'])?statement_converter_row_metadata($row):[]);
        }
        if((strtotime(max($dates))-strtotime(min($dates)))>366*86400)fail('Validated transactions span more than 366 days.',422,'statement_period_invalid');
        $clean=operations_statement_mark_duplicates((string)$company['id'],operations_statement_rows_with_keys((string)$company['id'],(string)$preview['bank_account_id'],(string)$preview['currency'],$clean));$calculated=$running; $variance=($closing!==null&&$calculated!==null)?$closing-$calculated:null;
        db()->prepare('UPDATE statement_previews SET opening_balance_cents=?,closing_balance_cents=?,first_transaction_date=?,last_transaction_date=?,row_count=?,rows_json=? WHERE id=?')
          ->execute([$opening,$closing,min($dates),max($dates),count($clean),json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$id]);
        audit_event($user,(string)$company['id'],'statement.preview_updated','statement_preview',$id,['rowCount'=>count($clean),'varianceCents'=>$variance]);
        db()->commit();
    } catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['preview'=>['id'=>$id,'openingBalanceCents'=>$opening,'closingBalanceCents'=>$closing,'calculatedClosingBalanceCents'=>$calculated,'varianceCents'=>$variance,'rows'=>$clean]]);
}

function operations_statement_preview_cancel_service(array $user,array $company,string $id): array
{
    $companyId=(string)$company['id'];$sourcePath='';$filename='';
    db()->beginTransaction();try{$stmt=db()->prepare("SELECT source_path,filename,status FROM statement_previews WHERE id=? AND company_id=? FOR UPDATE");$stmt->execute([$id,$companyId]);$preview=$stmt->fetch();if(!$preview)fail('Statement preview not found.',404,'statement_preview_not_found');if((string)$preview['status']!=='draft')fail('Only a draft statement preview can be cancelled.',409,'statement_preview_decided');$sourcePath=(string)$preview['source_path'];$filename=(string)$preview['filename'];db()->prepare('DELETE FROM statement_previews WHERE id=? AND company_id=?')->execute([$id,$companyId]);audit_event($user,$companyId,'statement.preview_cancelled','statement_preview',$id,['filename'=>$filename]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    if($sourcePath!=='')delete_private_file($sourcePath);return ['cancelled'=>true,'previewId'=>$id];
}

function operations_statement_preview_delete(array $user,array $company): never
{
    require_method('DELETE');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$id=clean_text($input['previewId']??'','Statement preview',64);json_response(operations_statement_preview_cancel_service($user,$company,$id));
}

function operations_statement_approve_service(array $user,array $company,string $previewId,?array $selectedKeys=null,array $reviewedDuplicateKeys=[],bool $balanceGapAcknowledged=false,string $operationKey=''): array
{
    if(count($reviewedDuplicateKeys)>5000||array_filter($reviewedDuplicateKeys,static fn(mixed $key):bool=>!is_string($key)||preg_match('/^stmt_[a-f0-9]{40}$/',$key)!==1))fail('The reviewed duplicate selections are invalid.',422,'statement_duplicate_review_invalid');$reviewedDuplicateKeys=array_values(array_unique($reviewedDuplicateKeys));$reviewedLegacyIds=[];
    $companyId=(string)$company['id'];$legacySelectAll=$selectedKeys===null;$selectedKeys=$legacySelectAll?[]:array_values(array_unique(array_map(static fn(mixed $key):string=>trim((string)$key),$selectedKeys)));if(!$legacySelectAll&&(count($selectedKeys)<1||count($selectedKeys)>5000))fail('Select between 1 and 5,000 statement transactions.',422,'statement_selection_required');
    $operationKey=$operationKey!==''?clean_text($operationKey,'Import operation key',120):'statement:'.$previewId;
    $payloadHash=hash('sha256',json_encode(['companyId'=>$companyId,'userId'=>(string)$user['id'],'previewId'=>$previewId,'selectedKeys'=>$selectedKeys,'legacySelectAll'=>$legacySelectAll,'reviewedDuplicateKeys'=>$reviewedDuplicateKeys,'balanceGapAcknowledged'=>$balanceGapAcknowledged],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $priorReceipt=db()->prepare('SELECT payload_hash,result_json FROM bank_import_control_receipts WHERE company_id=? AND user_id=? AND operation_key=? LIMIT 1');$priorReceipt->execute([$companyId,(string)$user['id'],$operationKey]);$prior=$priorReceipt->fetch();
    if($prior){if(!hash_equals((string)$prior['payload_hash'],$payloadHash))fail('This import operation key was already used for a different approval.',409,'statement_operation_conflict');$saved=json_decode((string)$prior['result_json'],true,64,JSON_THROW_ON_ERROR);if(!is_array($saved))throw new RuntimeException('The saved import receipt is invalid.');return $saved;}
    db()->beginTransaction();try{
        $stmt=db()->prepare("SELECT sp.*,ba.account_type,ba.masked_number FROM statement_previews sp JOIN bank_accounts ba ON ba.id=sp.bank_account_id AND ba.company_id=sp.company_id AND ba.active=1 WHERE sp.id=? AND sp.company_id=? FOR UPDATE");$stmt->execute([$previewId,$companyId]);$p=$stmt->fetch();
        if(!$p)fail('Statement preview not found.',404,'statement_preview_not_found');if($p['status']!=='draft')fail('This statement preview has already been decided.',409,'statement_preview_decided');
        $accountLock=db()->prepare('SELECT id,ledger_account_id,currency,account_type FROM bank_accounts WHERE id=? AND company_id=? AND active=1 FOR UPDATE');$accountLock->execute([$p['bank_account_id'],$companyId]);$bank=$accountLock->fetch();if(!$bank)fail('The selected financial account is no longer available.',409,'statement_account_unavailable');
        $bookBefore=operations_bank_balance($companyId,(string)$bank['ledger_account_id'],'9999-12-31');
        $rows=operations_json_decode((string)$p['rows_json'],'Statement rows');$rows=operations_statement_mark_duplicates($companyId,operations_statement_rows_with_keys($companyId,(string)$p['bank_account_id'],(string)$p['currency'],$rows));$byKey=[];foreach($rows as $row)$byKey[(string)$row['selectionKey']]=$row;if($legacySelectAll)$selectedKeys=array_keys(array_filter($byKey,static fn(array $row):bool=>!empty($row['eligible'])));$selectedRows=[];foreach($selectedKeys as $key){if(!isset($byKey[$key]))fail('The selected statement rows no longer match this preview.',409,'statement_selection_changed');if(empty($byKey[$key]['eligible']))fail('A selected statement row was already imported and is no longer eligible.',409,'statement_duplicate_selection');if(!empty($byKey[$key]['requiresDuplicateReview'])){if(!in_array($key,$reviewedDuplicateKeys,true))fail('Review the possible duplicate against the older source statement and explicitly confirm that it is a separate transaction.',409,'statement_duplicate_review_required');$reviewedLegacyIds=array_merge($reviewedLegacyIds,$byKey[$key]['legacyDuplicateIds']??[]);}$selectedRows[]=$byKey[$key];}$eligibleCount=count(array_filter($rows,static fn(array $row):bool=>!empty($row['eligible'])));$allSelected=count($selectedRows)===count($rows);
        if($p['opening_balance_cents']!==null&&$p['closing_balance_cents']!==null){$sum=array_sum(array_map(static fn(array $r):int=>(int)$r['foreignAmountCents'],$rows));$variance=(int)$p['closing_balance_cents']-((int)$p['opening_balance_cents']+$sum);if($variance!==0)fail('The statement running balance does not agree with the stated closing balance. Correct the preview before approving it.',409,'statement_balance_mismatch',['varianceCents'=>$variance]);}
        elseif(!$balanceGapAcknowledged)fail('Balance check unavailable — review the complete rows and explicitly acknowledge the missing independent opening/closing balance.',422,'statement_balance_acknowledgement_required');
        $batchId=new_id('import');
        db()->prepare("INSERT INTO import_batches (id,company_id,bank_account_id,filename,file_type,source_path,preview_id,status,row_count,duplicate_count,currency,exchange_rate_micros,opening_balance_cents,closing_balance_cents) VALUES (?,?,?,?,?,?,?,'extracted',0,0,?,?,?,?)")
            ->execute([$batchId,$companyId,$p['bank_account_id'],$p['filename'],$p['file_type'],$p['source_path'],$previewId,$p['currency'],$p['exchange_rate_micros'],$allSelected?$p['opening_balance_cents']:null,$allSelected?$p['closing_balance_cents']:null]);
        $accountCodes=account_code_map($companyId);$inserted=0;$duplicates=count(array_filter($rows,static fn(array $row):bool=>!empty($row['duplicate'])));$created=[];
        $dupe=db()->prepare('SELECT id FROM bank_transactions WHERE company_id=? AND source_hash=? LIMIT 1');
        $ins=db()->prepare("INSERT INTO bank_transactions (id,company_id,bank_account_id,import_batch_id,transaction_date,description,reference,normalized_merchant,amount_cents,currency,foreign_amount_cents,exchange_rate_micros,suggested_account_id,tax_code,confidence,suggestion_source,source_hash,source_row_number,source_sequence,source_running_balance_cents,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending')");
        foreach($selectedRows as $row){$foreign=(int)$row['foreignAmountCents'];$base=(int)$row['amountCents'];$hash=(string)$row['sourceFingerprint'];$dupe->execute([$companyId,$hash]);if($dupe->fetchColumn()!==false){$duplicates++;continue;}$s=statement_import_row_suggestion($companyId,$row,$base,$accountCodes);$transactionId=new_id('btx');$ins->execute([$transactionId,$companyId,$p['bank_account_id'],$batchId,$row['date'],$row['description'],$row['reference']??null,$s['merchant'],$base,$p['currency'],$foreign,$p['exchange_rate_micros'],$s['accountId'],$s['taxCode'],$s['confidence'],$s['source'],$hash,isset($row['sourceRow'])?(int)$row['sourceRow']:null,isset($row['sequence'])?(int)$row['sequence']:null,$row['runningBalanceCents']??null]);if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'BT','BS','bank_transaction',$transactionId,(string)$row['date'],'Bank statement · '.(string)$row['description'],abs($base),null,false);if(schema_table_exists('bank_transaction_source_text'))db()->prepare('INSERT INTO bank_transaction_source_text(transaction_id,company_id,source_fingerprint,full_description) VALUES(?,?,?,?)')->execute([$transactionId,$companyId,$hash,(string)($row['fullDescription']??$row['description'])]);$created[]=['id'=>$transactionId,'description'=>(string)$row['description'],'reference'=>(string)($row['reference']??''),'date'=>(string)$row['date'],'runningBalanceCents'=>$row['runningBalanceCents']??null];$inserted++;}
        if($inserted===0&&$duplicates>0)fail('This statement has already been imported. No duplicate batch was created.',409,'statement_duplicate');
        db()->prepare("UPDATE import_batches SET status='needs_review',row_count=?,duplicate_count=? WHERE id=?")->execute([$inserted,$duplicates,$batchId]);
        // Release the original full-file hash after the decision so a user may
        // re-upload the same source file later and select previously omitted
        // lines. Per-line fingerprints still prevent duplicate transactions.
        db()->prepare("UPDATE statement_previews SET status='approved',approved_batch_id=?,source_sha256=SHA2(CONCAT(source_sha256,'|approved|',id),256) WHERE id=?")->execute([$batchId,$previewId]);
        $omitted=max(0,count($rows)-$duplicates-count($selectedRows));$coverageDates=array_values(array_filter(array_map(static fn(array $row):string=>(string)($row['date']??''),$rows)));sort($coverageDates);$coverageStart=$coverageDates[0]??null;$coverageEnd=$coverageDates!==[]?$coverageDates[count($coverageDates)-1]:null;
        $moneyIn=array_sum(array_map(static fn(array $row):int=>max(0,(int)$row['foreignAmountCents']),$rows));$moneyOut=array_sum(array_map(static fn(array $row):int=>max(0,-(int)$row['foreignAmountCents']),$rows));$sourceOpening=$p['opening_balance_cents']===null?null:(int)$p['opening_balance_cents'];$sourceClosing=$p['closing_balance_cents']===null?null:(int)$p['closing_balance_cents'];$calculatedClosing=$sourceOpening===null?null:$sourceOpening+$moneyIn-$moneyOut;$difference=$sourceClosing!==null&&$calculatedClosing!==null?$sourceClosing-$calculatedClosing:null;
        $anchorStmt=db()->prepare("SELECT balance_cents,anchor_date,revision FROM bank_statement_balance_anchors WHERE company_id=? AND bank_account_id=? AND status='active' ORDER BY anchor_date DESC,revision DESC LIMIT 1 FOR UPDATE");$anchorStmt->execute([$companyId,$p['bank_account_id']]);$priorAnchor=$anchorStmt->fetch();$priorStatement=$priorAnchor?(int)$priorAnchor['balance_cents']:null;$projected=$allSelected&&$sourceClosing!==null?$sourceClosing:($priorStatement!==null?$priorStatement+array_sum(array_map(static fn(array $row):int=>(int)$row['foreignAmountCents'],$selectedRows)):null);
        $invalid=count(array_filter($rows,static fn(array $row):bool=>!empty($row['userExcluded'])||empty($row['date'])||!isset($row['foreignAmountCents'])));$state=$sourceOpening===null||$sourceClosing===null?'balance_check_unavailable':($difference!==0?'difference_found':(!$allSelected||$omitted>0?'partial_import':($priorStatement===null&&$sourceOpening===null?'history_gap':'checks_passed_review_required')));
        if($allSelected&&$sourceClosing!==null&&$coverageEnd!==null){$revision=(int)($priorAnchor['revision']??0)+1;$sourceChecksum=(string)$p['source_sha256'];db()->prepare("UPDATE bank_statement_balance_anchors SET status='superseded' WHERE company_id=? AND bank_account_id=? AND status='active' AND anchor_date<=?")->execute([$companyId,$p['bank_account_id'],$coverageEnd]);db()->prepare("INSERT INTO bank_statement_balance_anchors(id,company_id,bank_account_id,anchor_date,balance_cents,currency,provenance,source_checksum,evidence_json,revision,status,created_by) VALUES(?,?,?,?,?,?,'source_file',?,?,?,'active',?)")->execute([new_id('stmtanchor'),$companyId,$p['bank_account_id'],$coverageEnd,$sourceClosing,$p['currency'],$sourceChecksum,json_encode(['previewId'=>$previewId,'importBatchId'=>$batchId,'openingBalanceCents'=>$sourceOpening,'closingBalanceCents'=>$sourceClosing,'calculatedClosingBalanceCents'=>$calculatedClosing],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$revision,$user['id']]);}
        $result=['id'=>$batchId,'status'=>'needs_review','rowCount'=>$inserted,'selectedCount'=>count($selectedRows),'omittedCount'=>$omitted,'duplicateCount'=>$duplicates,'created'=>$created,'control'=>['operationKey'=>$operationKey,'coverageStart'=>$coverageStart,'coverageEnd'=>$coverageEnd,'currency'=>(string)$p['currency'],'parsedCount'=>count($rows),'eligibleCount'=>$eligibleCount,'existingCount'=>$duplicates,'invalidCount'=>$invalid,'moneyInCents'=>$moneyIn,'moneyOutCents'=>$moneyOut,'sourceOpeningCents'=>$sourceOpening,'calculatedClosingCents'=>$calculatedClosing,'sourceClosingCents'=>$sourceClosing,'differenceCents'=>$difference,'priorStatementBalanceCents'=>$priorStatement,'projectedStatementBalanceCents'=>$projected,'bookBalanceBeforeCents'=>$bookBefore,'bookBalanceAfterCents'=>$bookBefore,'completenessState'=>$state]];
        db()->prepare('INSERT INTO bank_import_control_receipts(id,company_id,user_id,preview_id,import_batch_id,bank_account_id,operation_key,payload_hash,source_checksum,coverage_start,coverage_end,currency,parsed_count,selected_count,existing_count,invalid_count,omitted_count,money_in_cents,money_out_cents,source_opening_cents,calculated_closing_cents,source_closing_cents,difference_cents,prior_statement_balance_cents,projected_statement_balance_cents,book_balance_before_cents,book_balance_after_cents,completeness_state,result_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([new_id('importreceipt'),$companyId,$user['id'],$previewId,$batchId,$p['bank_account_id'],$operationKey,$payloadHash,$p['source_sha256'],$coverageStart,$coverageEnd,$p['currency'],count($rows),count($selectedRows),$duplicates,$invalid,$omitted,$moneyIn,$moneyOut,$sourceOpening,$calculatedClosing,$sourceClosing,$difference,$priorStatement,$projected,$bookBefore,$bookBefore,$state,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
        audit_event($user,$companyId,'statement.approved','import_batch',$batchId,['previewId'=>$previewId,'operationKey'=>$operationKey,'selectedCount'=>count($selectedRows),'selectedKeys'=>$selectedKeys,'omittedCount'=>$omitted,'insertedCount'=>$inserted,'duplicateCount'=>$duplicates,'reviewedDuplicateKeys'=>array_values(array_intersect($selectedKeys,$reviewedDuplicateKeys)),'reviewedLegacyTransactionIds'=>array_values(array_unique($reviewedLegacyIds)),'balanceGapAcknowledged'=>$balanceGapAcknowledged,'completenessState'=>$state]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    return $result;
}

function operations_statement_approve(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$previewId=clean_text($input['previewId']??'','Statement preview',64);$keys=$input['selectedKeys']??null;if(!is_array($keys))fail('Choose at least one statement transaction.',422,'statement_selection_required');$reviewed=$input['reviewedDuplicateKeys']??[];if(!is_array($reviewed))fail('The reviewed duplicate selections must be a list.',422,'statement_duplicate_review_invalid');json_response(['batch'=>operations_statement_approve_service($user,$company,$previewId,$keys,$reviewed,($input['balanceGapAcknowledged']??false)===true,clean_text($input['operationKey']??('statement:'.$previewId),'Import operation key',120))],201);
}

function operations_period_locks(array $user,array $company): never
{
    if(request_method()==='GET'){operations_workspace($user,$company);}require_method('POST');require_csrf();$input=request_json();
    $locked=!empty($input['locked']);
    require_company_permission($company,$locked?'period.lock':'period.unlock');
    $start=safe_date($input['periodStart']??'','Period start');$end=safe_date($input['periodEnd']??'','Period end');if($start>$end)fail('Period start cannot be after period end.');$reason=clean_text($input['reason']??($locked?'Period locked by company administrator':'Period reopened by company administrator'),'Reason',500);$id=new_id('periodlock');
    db()->prepare('INSERT INTO period_locks (id,company_id,period_start,period_end,locked,reason,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE locked=VALUES(locked),reason=VALUES(reason),updated_by=VALUES(updated_by)')
        ->execute([$id,$company['id'],$start,$end,$locked?1:0,$reason,$user['id']]);
    audit_event($user,(string)$company['id'],$locked?'period.locked':'period.unlocked','period_lock',$id,['periodStart'=>$start,'periodEnd'=>$end,'reason'=>$reason]);json_response(['period'=>['periodStart'=>$start,'periodEnd'=>$end,'locked'=>$locked,'reason'=>$reason]]);
}

function operations_bulk_bank(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$ids=$input['transactionIds']??null;if(!is_array($ids)||count($ids)<1||count($ids)>1000)fail('Select between 1 and 1,000 transactions.');$ids=array_values(array_unique(array_map('strval',$ids)));$action=(string)($input['action']??'assign_gl');
    $place=implode(',',array_fill(0,count($ids),'?'));$params=array_merge([(string)$company['id']],$ids);$stmt=db()->prepare("SELECT id,status FROM bank_transactions WHERE company_id=? AND id IN ($place) FOR UPDATE");
    db()->beginTransaction();try{$stmt->execute($params);$rows=$stmt->fetchAll();if(count($rows)!==count($ids))fail('One or more selected transactions are unavailable.');foreach($rows as $r)if($r['status']!=='pending'&&$action!=='restore')fail('Only pending transactions can be changed in bulk.',409,'transaction_bulk_unavailable');
        if($action==='assign_gl'){$account=company_account((string)$company['id'],clean_text($input['accountId']??'','GL code',64));if(!$account||$account['is_control'])fail('Choose a valid non-control GL code.');$tax=clean_text($input['taxCode']??'NO_TAX','Tax code',30);$sql="UPDATE bank_transactions SET decided_account_id=?,tax_code=?,suggestion_source='manual' WHERE company_id=? AND id IN ($place) AND status='pending'";db()->prepare($sql)->execute(array_merge([$account['id'],$tax,(string)$company['id']],$ids));}
        elseif($action==='exclude'){db()->prepare("UPDATE bank_transactions SET status='excluded' WHERE company_id=? AND id IN ($place) AND status='pending'")->execute($params);}
        elseif($action==='restore'){db()->prepare("UPDATE bank_transactions SET status='pending' WHERE company_id=? AND id IN ($place) AND status='excluded'")->execute($params);}
        elseif($action==='delete'){db()->prepare("DELETE FROM bank_transactions WHERE company_id=? AND id IN ($place) AND status IN ('pending','excluded') AND journal_entry_id IS NULL")->execute($params);}
        else fail('Bulk action is invalid.');
        audit_event($user,(string)$company['id'],'bank_transactions.bulk_action','bank_transaction',new_id('bulk'),['action'=>$action,'count'=>count($ids)]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}json_response(['updated'=>count($ids),'action'=>$action]);
}

function operations_bank_ledger(array $company): never
{
    require_method('GET');$companyId=(string)$company['id'];$bankId=clean_text($_GET['bankAccountId']??'','Financial account',64);
    $start=safe_date($_GET['start']??date('Y-01-01'),'From date');$end=safe_date($_GET['end']??canadian_today(),'To date');if($start>$end)fail('From date cannot be after To date.');
    $q=db()->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=?');$q->execute([$bankId,$companyId]);$bank=$q->fetch();if(!$bank)fail('Financial account not found.',404,'bank_account_not_found');
    $before=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date<?");$before->execute([$companyId,$bank['ledger_account_id'],$start]);$opening=(int)$before->fetchColumn();$running=$opening;
    $q=db()->prepare("SELECT je.entry_date,je.source_type,je.source_id,je.memo,jl.debit_cents,jl.credit_cents,v.voucher_number,
        CASE WHEN EXISTS(SELECT 1 FROM bank_match_book_items mbi JOIN bank_match_groups mg ON mg.id=mbi.match_group_id WHERE mbi.journal_entry_id=je.id AND mg.bank_account_id=? AND mg.status='matched')
             OR EXISTS(SELECT 1 FROM bank_transactions linked_bt WHERE linked_bt.company_id=je.company_id AND linked_bt.bank_account_id=? AND linked_bt.journal_entry_id=je.id AND linked_bt.status='posted') THEN 1 ELSE 0 END matched,
        CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations rr ON rr.id=ri.reconciliation_id JOIN bank_transactions rbt ON rbt.id=ri.bank_transaction_id WHERE rr.company_id=je.company_id AND rr.bank_account_id=? AND rr.status='complete' AND rbt.journal_entry_id=je.id)
             OR EXISTS(SELECT 1 FROM bank_match_book_items rbi JOIN bank_match_groups rmg ON rmg.id=rbi.match_group_id JOIN reconciliation_match_groups rlink ON rlink.match_group_id=rmg.id JOIN reconciliations rr2 ON rr2.id=rlink.reconciliation_id WHERE rbi.journal_entry_id=je.id AND rmg.bank_account_id=? AND rmg.status='matched' AND rr2.status='complete') THEN 1 ELSE 0 END reconciled,
        COALESCE((SELECT CASE WHEN src_bt.import_batch_id IS NULL THEN 'manual' ELSE 'imported' END FROM bank_transactions src_bt WHERE src_bt.company_id=je.company_id AND src_bt.bank_account_id=? AND src_bt.journal_entry_id=je.id AND src_bt.status='posted' ORDER BY src_bt.created_at LIMIT 1),'accounting') source_indicator
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id
        WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date BETWEEN ? AND ? ORDER BY je.entry_date,je.created_at,je.id");
    $q->execute([$bankId,$bankId,$bankId,$bankId,$bankId,$companyId,$bank['ledger_account_id'],$start,$end]);
    $entries=[];foreach($q->fetchAll() as $r){$debit=(int)$r['debit_cents'];$credit=(int)$r['credit_cents'];$running+=$debit-$credit;$recon=(bool)$r['reconciled']?'reconciled':((bool)$r['matched']?'matched':'unreconciled');$entries[]=['date'=>(string)$r['entry_date'],'description'=>(string)$r['memo'],'sourceType'=>(string)$r['source_type'],'transactionNumber'=>$r['voucher_number'],'debitCents'=>$debit,'creditCents'=>$credit,'runningBalanceCents'=>$running,'reconciliationStatus'=>$recon,'indicator'=>(string)$r['source_indicator']];}
    $q=db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,bt.import_batch_id,
        lv.voucher_number linked_transaction_number,
        CASE WHEN EXISTS(SELECT 1 FROM bank_match_bank_items mbi JOIN bank_match_groups mg ON mg.id=mbi.match_group_id WHERE mbi.bank_transaction_id=bt.id AND mg.status='matched') OR (bt.status='posted' AND bt.journal_entry_id IS NOT NULL) THEN 1 ELSE 0 END matched,
        CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.status='complete') THEN 1 ELSE 0 END reconciled
        FROM bank_transactions bt
        LEFT JOIN journal_entries lje ON lje.id=bt.journal_entry_id AND lje.company_id=bt.company_id
        LEFT JOIN vouchers lv ON lv.company_id=lje.company_id AND lv.source_type=lje.source_type AND lv.source_id=lje.source_id
        WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.transaction_date BETWEEN ? AND ? AND bt.status NOT IN ('duplicate','excluded') ORDER BY bt.transaction_date,bt.created_at");$q->execute([$companyId,$bankId,$start,$end]);
    $bankRows=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'date'=>(string)$r['transaction_date'],'description'=>(string)$r['description'],'reference'=>$r['reference'],'amountCents'=>(int)$r['amount_cents'],'status'=>(string)$r['status'],'matched'=>(bool)$r['matched'],'reconciled'=>(bool)$r['reconciled'],'indicator'=>$r['import_batch_id']!==null?'imported':'manual','linkedTransactionNumber'=>$r['linked_transaction_number']],$q->fetchAll());
    json_response(['account'=>['id'=>$bankId,'name'=>(string)$bank['name'],'accountType'=>(string)$bank['account_type'],'currency'=>(string)$bank['currency'],'openingBalanceCents'=>$opening],'start'=>$start,'end'=>$end,'ledgerEntries'=>$entries,'bankTransactions'=>$bankRows]);
}

function operations_reconciliation_workspace_v2(array $company): never
{
    require_method('GET');
    $bankId=clean_text($_GET['bankAccountId']??'','Financial account',64);
    $start=safe_date($_GET['start']??date('Y-m-01'),'From date');$end=safe_date($_GET['end']??canadian_today(),'To date');
    if(($_GET['includeMatched']??'')==='1'){
        require_company_permission($company,'banking.view');
        $ids=[$bankId];if($bankId==='all'){$q=db()->prepare("SELECT id FROM bank_accounts WHERE company_id=? AND active=1 AND account_type IN ('bank','credit_card') ORDER BY name,id");$q->execute([$company['id']]);$ids=array_column($q->fetchAll(),'id');}
        $result=['bankSide'=>[],'bookSide'=>[],'matchGroups'=>[],'suggestions'=>[]];
        foreach($ids as $id){$data=operations_reconciliation_workspace_data($company,(string)$id,$start,$end);$data['bookSide']=array_merge($data['bookSide'],operations_reconciliation_matched_books($company,(string)$id,$start,$end));foreach(['bankSide','bookSide','matchGroups','suggestions'] as $key)foreach($data[$key]??[] as $row){$row['bankAccountId']=(string)$id;$result[$key][]=$row;}}
        json_response($result);
    }
    json_response(operations_reconciliation_workspace_data($company,$bankId,$start,$end));
}

/**
 * Add human-readable source evidence to book rows without changing accounting
 * eligibility. These values are presentation/ranking hints only; commit-time
 * authority remains the journal and bank records selected by ID.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function operations_reconciliation_matched_books(array $company,string $bankId,string $start,string $end): array
{
    $q=db()->prepare("SELECT je.id journalEntryId,je.entry_date date,je.memo description,je.source_type sourceType,v.voucher_number transactionNumber,g.id matchGroupId,SUM(jl.debit_cents-jl.credit_cents) amountCents FROM bank_match_groups g JOIN bank_match_book_items bi ON bi.match_group_id=g.id JOIN journal_entries je ON je.id=bi.journal_entry_id AND je.company_id=g.company_id JOIN bank_accounts ba ON ba.id=g.bank_account_id AND ba.company_id=g.company_id JOIN journal_lines jl ON jl.journal_entry_id=je.id AND jl.account_id=ba.ledger_account_id LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id WHERE g.company_id=? AND g.bank_account_id=? AND g.status='matched' AND je.entry_date BETWEEN ? AND ? GROUP BY je.id,je.entry_date,je.memo,je.source_type,v.voucher_number,g.id ORDER BY je.entry_date,je.id");
    $q->execute([$company['id'],$bankId,$start,$end]);$rows=$q->fetchAll();foreach($rows as &$row){$row['amountCents']=(int)$row['amountCents'];$row['matched']=true;}unset($row);return $rows;
}

function operations_reconciliation_book_evidence(string $companyId,array $rows): array
{
    $invoiceIds=[];$billIds=[];$paymentIds=[];
    foreach($rows as $row){$type=(string)($row['sourceType']??'');$id=trim((string)($row['sourceId']??''));if($id==='')continue;
        if(in_array($type,['invoice','invoice_reversal'],true))$invoiceIds[$id]=true;
        elseif(in_array($type,['bill','bill_reversal'],true))$billIds[$id]=true;
        elseif(in_array($type,['customer_payment','customer_payment_reversal','vendor_payment','vendor_payment_reversal'],true))$paymentIds[$id]=true;
    }
    $evidence=[];
    $load=static function(array $ids,string $sql,string $kind)use($companyId,&$evidence):void{
        if(!$ids)return;$keys=array_keys($ids);$q=db()->prepare(str_replace(':ids',implode(',',array_fill(0,count($keys),'?')),$sql));$q->execute(array_merge([$companyId],$keys));foreach($q->fetchAll() as $row)$evidence[$kind.':'.(string)$row['id']]=['counterparty'=>(string)($row['counterparty']??''),'sourceReference'=>(string)($row['source_reference']??'')];
    };
    $load($invoiceIds,"SELECT i.id,c.name counterparty,i.number source_reference FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.id IN (:ids)",'invoice');
    $load($billIds,"SELECT b.id,v.name counterparty,b.number source_reference FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.id IN (:ids)",'bill');
    $load($paymentIds,"SELECT pp.id,COALESCE(c.name,v.name,'') counterparty,pp.reference source_reference FROM party_payments pp LEFT JOIN customers c ON pp.payment_type='customer' AND c.id=pp.party_id AND c.company_id=pp.company_id LEFT JOIN vendors v ON pp.payment_type='vendor' AND v.id=pp.party_id AND v.company_id=pp.company_id WHERE pp.company_id=? AND pp.id IN (:ids)",'payment');
    foreach($rows as &$row){$type=(string)($row['sourceType']??'');$id=(string)($row['sourceId']??'');$kind=in_array($type,['invoice','invoice_reversal'],true)?'invoice':(in_array($type,['bill','bill_reversal'],true)?'bill':(in_array($type,['customer_payment','customer_payment_reversal','vendor_payment','vendor_payment_reversal'],true)?'payment':''));$hint=$kind!==''?($evidence[$kind.':'.$id]??[]):[];$row['counterparty']=(string)($hint['counterparty']??(str_starts_with($type,'payroll')?'Payroll':''));$row['sourceReference']=(string)($hint['sourceReference']??'');}unset($row);
    return $rows;
}


function operations_reconciliation_workspace_data(array $company, string $bankId, string $start, string $end, bool $enforceUserPermission = true): array
{
    if($enforceUserPermission)require_company_permission($company,'banking.view');
    $companyId=(string)$company['id'];
    if($start>$end)fail('From date cannot be after To date.');
    $q=db()->prepare("SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND account_type IN ('bank','credit_card') AND active=1");
    $q->execute([$bankId,$companyId]);$bank=$q->fetch();if(!$bank)fail('Choose a valid financial account.');

    $q=db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.normalized_merchant,bt.reference,bt.amount_cents,bt.status,bt.import_batch_id,bt.journal_entry_id,
      (SELECT g.id FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id=bt.id AND g.status='matched' ORDER BY g.matched_at DESC LIMIT 1) active_match_group_id,
      CASE WHEN bt.status='posted' AND bt.journal_entry_id IS NOT NULL THEN 1 ELSE 0 END direct_accounting_link,
      CASE WHEN EXISTS(SELECT 1 FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id=bt.id AND r.status='complete') THEN 1 ELSE 0 END reconciled
      FROM bank_transactions bt WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.transaction_date BETWEEN ? AND ? AND bt.status NOT IN ('duplicate','excluded') ORDER BY bt.transaction_date,bt.created_at");
    $q->execute([$companyId,$bankId,$start,$end]);
    $bankSide=array_map(static fn(array $r):array=>[
        'id'=>(string)$r['id'],'date'=>(string)$r['transaction_date'],'description'=>(string)$r['description'],'normalizedMerchant'=>(string)($r['normalized_merchant']??''),'reference'=>$r['reference'],
        'amountCents'=>(int)$r['amount_cents'],'status'=>(string)$r['status'],'matched'=>$r['active_match_group_id']!==null,
        'matchGroupId'=>$r['active_match_group_id'],'directAccountingLink'=>(bool)$r['direct_accounting_link'],
        'linkedJournalEntryId'=>$r['journal_entry_id']!==null?(string)$r['journal_entry_id']:null,'reconciled'=>(bool)$r['reconciled'],
        'indicator'=>$r['import_batch_id']!==null?'imported':'manual'
    ],$q->fetchAll());

    // Book Side is based on accounting eligibility, not source origin. A posted
    // financial-account line created by Bank Review is still a real book entry
    // and must be available for reconciliation against its source statement row.
    $q=db()->prepare("SELECT je.id,je.entry_date,je.source_type,je.source_id,je.memo,SUM(jl.debit_cents-jl.credit_cents) bank_impact_cents,v.voucher_number,
      (SELECT linked_bt.id FROM bank_transactions linked_bt WHERE linked_bt.company_id=je.company_id AND linked_bt.bank_account_id=? AND linked_bt.journal_entry_id=je.id AND linked_bt.status='posted' ORDER BY linked_bt.created_at LIMIT 1) direct_bank_transaction_id
      FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
      LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id
      WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.entry_date BETWEEN ? AND ?
        AND je.source_type NOT IN ('bank_reconciliation_adjustment','bank_reconciliation_adjustment_reversal','opening_balance')
        AND NOT EXISTS(SELECT 1 FROM bank_match_book_items bmi JOIN bank_match_groups g ON g.id=bmi.match_group_id WHERE bmi.journal_entry_id=je.id AND g.company_id=je.company_id AND g.bank_account_id=? AND g.status='matched')
        AND NOT EXISTS(SELECT 1 FROM bank_transactions rbt JOIN reconciliation_items ri ON ri.bank_transaction_id=rbt.id JOIN reconciliations rr ON rr.id=ri.reconciliation_id WHERE rbt.company_id=je.company_id AND rbt.bank_account_id=? AND rbt.journal_entry_id=je.id AND rr.status='complete')
      GROUP BY je.id,je.entry_date,je.source_type,je.source_id,je.memo,v.voucher_number ORDER BY je.entry_date,je.created_at");
    $q->execute([$bankId,$companyId,$bank['ledger_account_id'],$start,$end,$bankId,$bankId]);
    $bookSide=array_map(static fn(array $r):array=>[
        'journalEntryId'=>(string)$r['id'],'date'=>(string)$r['entry_date'],'sourceType'=>(string)$r['source_type'],'sourceId'=>$r['source_id'],
        'description'=>(string)$r['memo'],'transactionNumber'=>$r['voucher_number'],'amountCents'=>(int)$r['bank_impact_cents'],
        'matched'=>false,'matchGroupId'=>null,'directBankTransactionId'=>$r['direct_bank_transaction_id']!==null?(string)$r['direct_bank_transaction_id']:null
    ],$q->fetchAll());
    $bookSide=operations_reconciliation_book_evidence($companyId,$bookSide);

    $bookById=[];foreach($bookSide as $row)$bookById[(string)$row['journalEntryId']]=$row;
    $suggestions=[];$suggestedBank=[];$suggestedBook=[];$reservedBank=[];$reservedBook=[];
    foreach($bankSide as $row){$jid=trim((string)($row['linkedJournalEntryId']??''));if(!empty($row['directAccountingLink'])&&$jid!==''){$reservedBank[(string)$row['id']]=true;$reservedBook[$jid]=true;}}
    foreach($bookSide as $row){$bid=trim((string)($row['directBankTransactionId']??''));if($bid!==''){$reservedBook[(string)$row['journalEntryId']]=true;$reservedBank[$bid]=true;}}
    foreach($bankSide as $bankRow){
        if($bankRow['matched']||$bankRow['reconciled']||!$bankRow['directAccountingLink']||$bankRow['linkedJournalEntryId']===null)continue;
        $jid=(string)$bankRow['linkedJournalEntryId'];$bookRow=$bookById[$jid]??null;if(!$bookRow)continue;
        if((int)$bookRow['amountCents']!==(int)$bankRow['amountCents'])continue;
        $suggestions[]=['kind'=>'exact_source','bankTransactionId'=>(string)$bankRow['id'],'journalEntryId'=>$jid,'score'=>100,'reason'=>'Exact source link · same financial account and amount'];
        $suggestedBank[(string)$bankRow['id']]=true;$suggestedBook[$jid]=true;
    }
    foreach($bankSide as $bankRow){
        if($bankRow['matched']||$bankRow['reconciled']||isset($suggestedBank[(string)$bankRow['id']])||isset($reservedBank[(string)$bankRow['id']]))continue;
        foreach($bookSide as $bookRow){
            $jid=(string)$bookRow['journalEntryId'];if(isset($suggestedBook[$jid])||isset($reservedBook[$jid]))continue;$evidence=operations_reconciliation_pair_evidence($bankRow,$bookRow);if($evidence===null)continue;
            $suggestions[]=['kind'=>$evidence['kind'],'bankTransactionId'=>(string)$bankRow['id'],'journalEntryId'=>$jid,'score'=>$evidence['score'],'reason'=>$evidence['reason']];
            $suggestedBank[(string)$bankRow['id']]=true;$suggestedBook[$jid]=true;break;
        }
    }

    $q=db()->prepare("SELECT g.id,g.status,g.bank_amount_cents,g.book_amount_cents,g.adjustment_journal_entry_id,g.adjustment_reversal_journal_entry_id,g.matched_at,
        g.unreconciled_at,g.unreconcile_reason_code,g.unreconcile_reason,mu.display_name matched_by_name,uu.display_name unreconciled_by_name
      FROM bank_match_groups g LEFT JOIN users mu ON mu.id=g.matched_by LEFT JOIN users uu ON uu.id=g.unreconciled_by
      WHERE g.company_id=? AND g.bank_account_id=? ORDER BY g.matched_at DESC LIMIT 200");
    $q->execute([$companyId,$bankId]);
    $matchGroups=array_map(static fn(array $r):array=>[
        'id'=>(string)$r['id'],'status'=>(string)$r['status'],'bankAmountCents'=>(int)$r['bank_amount_cents'],'bookAmountCents'=>(int)$r['book_amount_cents'],
        'hasAdjustment'=>$r['adjustment_journal_entry_id']!==null,'hasAdjustmentReversal'=>$r['adjustment_reversal_journal_entry_id']!==null,
        'matchedAt'=>$r['matched_at'],'matchedBy'=>$r['matched_by_name'],'unreconciledAt'=>$r['unreconciled_at'],'unreconciledBy'=>$r['unreconciled_by_name'],'unreconcileReasonCode'=>$r['unreconcile_reason_code'],'unreconcileReason'=>$r['unreconcile_reason']
    ],$q->fetchAll());
    $availableBank=count(array_filter($bankSide,static fn($r)=>!$r['matched']&&!$r['reconciled']));
    return ['account'=>['id'=>$bankId,'name'=>(string)$bank['name'],'currency'=>(string)$bank['currency'],'accountType'=>(string)$bank['account_type']],'start'=>$start,'end'=>$end,'bankSide'=>$bankSide,'bookSide'=>$bookSide,'suggestions'=>$suggestions,'matchGroups'=>$matchGroups,'summary'=>['bankItems'=>count($bankSide),'availableBankItems'=>$availableBank,'bookEntries'=>count($bookSide),'matchedGroups'=>count(array_filter($matchGroups,static fn($g)=>$g['status']==='matched')),'suggestedMatches'=>count($suggestions)]];
}

function operations_reconciliation_match_score(array $bankRows,array $bookRows,string $kind,string $reason,?int $evidenceScore=null): array
{
    $bankTotal=array_sum(array_map(static fn(array $r):int=>(int)$r['amountCents'],$bankRows));
    $bookTotal=array_sum(array_map(static fn(array $r):int=>(int)$r['amountCents'],$bookRows));
    $dates=[];
    foreach($bankRows as $r)$dates[]=(string)$r['date'];foreach($bookRows as $r)$dates[]=(string)$r['date'];
    sort($dates);$dateDifferenceDays=count($dates)>1?(int)round((strtotime(end($dates))-strtotime($dates[0]))/86400):0;
    if($evidenceScore!==null)$score=$evidenceScore;
    elseif($kind==='exact_source')$score=100;
    elseif($kind==='reference')$score=max(88,98-min(10,$dateDifferenceDays));
    elseif($kind==='identity')$score=max(82,94-min(12,$dateDifferenceDays));
    elseif($kind==='description')$score=max(76,89-min(14,$dateDifferenceDays));
    elseif($kind==='amount_date')$score=max(70,94-min(18,$dateDifferenceDays*4));
    else $score=max(66,88-min(18,$dateDifferenceDays*3));
    $tiers=operations_reconciliation_score_tiers($kind,$reason,$dateDifferenceDays,$bankTotal===$bookTotal);
    return [
        'id'=>'proposal_'.substr(hash('sha256',implode('|',array_map(static fn($r)=>(string)$r['id'],$bankRows)).'::'.implode('|',array_map(static fn($r)=>(string)$r['journalEntryId'],$bookRows))),0,18),
        'kind'=>$kind,'confidence'=>$score>=96?'exact':($score>=82?'strong':'likely'),'score'=>$score,'reason'=>$reason,
        'bankTransactionIds'=>array_values(array_map(static fn($r)=>(string)$r['id'],$bankRows)),
        'journalEntryIds'=>array_values(array_map(static fn($r)=>(string)$r['journalEntryId'],$bookRows)),
        'bankTotalCents'=>$bankTotal,'bookTotalCents'=>$bookTotal,'differenceCents'=>$bankTotal-$bookTotal,
        'dateDifferenceDays'=>$dateDifferenceDays,'scoreTiers'=>$tiers,'bankRows'=>$bankRows,'bookRows'=>$bookRows,
    ];
}

/** @return array{amount:int,reference:int,identity:int,description:int,dateProximity:int} */
function operations_reconciliation_score_tiers(string $kind,string $reason,int $dateDifferenceDays,bool $exactAmount): array
{
    return [
        'amount'=>$exactAmount?100:0,
        'reference'=>in_array($kind,['exact_source','reference'],true)?100:0,
        'identity'=>$kind==='identity'?100:(str_contains(mb_strtolower($reason),'customer')||str_contains(mb_strtolower($reason),'vendor')?75:0),
        'description'=>$kind==='description'?100:0,
        'dateProximity'=>max(0,100-min(100,$dateDifferenceDays*10)),
    ];
}

function operations_persist_reconciliation_proposals(string $companyId,string $bankId,string $start,string $end,array $proposals): int
{
    if(!schema_table_exists('reconciliation_match_proposals'))return 0;
    $stmt=db()->prepare("INSERT INTO reconciliation_match_proposals (id,company_id,bank_account_id,period_start,period_end,proposal_hash,match_kind,score,score_tiers_json,bank_transaction_ids_json,journal_entry_ids_json,bank_total_cents,book_total_cents,difference_cents,reason,evidence_json,status,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE score=VALUES(score),score_tiers_json=VALUES(score_tiers_json),difference_cents=VALUES(difference_cents),reason=VALUES(reason),evidence_json=VALUES(evidence_json),status='open',last_seen_at=UTC_TIMESTAMP()");
    $persisted=0;
    foreach($proposals as $proposal){
        $hash=hash('sha256',json_encode([$companyId,$bankId,$start,$end,$proposal['bankTransactionIds'],$proposal['journalEntryIds']],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $evidence=['proposalId'=>$proposal['id'],'confidence'=>$proposal['confidence'],'dateDifferenceDays'=>$proposal['dateDifferenceDays'],'conflicts'=>$proposal['conflicts']??[],'selectedByDefault'=>$proposal['selectedByDefault']??false];
        $stmt->execute([new_id('rproposal'),$companyId,$bankId,$start,$end,$hash,$proposal['kind'],(int)$proposal['score'],json_encode($proposal['scoreTiers'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($proposal['bankTransactionIds'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($proposal['journalEntryIds'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$proposal['bankTotalCents'],(int)$proposal['bookTotalCents'],(int)$proposal['differenceCents'],mb_substr((string)$proposal['reason'],0,500),json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);$persisted++;
    }
    return $persisted;
}

function operations_reconciliation_normalize_evidence(string $value): string
{
    $value=mb_strtolower(trim($value));$value=(string)preg_replace('/[^\pL\pN]+/u',' ',$value);
    $value=(string)preg_replace('/\b(?:online|purchase|payment|paid|debit|credit|card|visa|mastercard|interac|etransfer|transfer|deposit|withdrawal|pos|terminal|reference|ref)\b/u',' ',$value);
    return trim((string)preg_replace('/\s+/u',' ',$value));
}

/** @return array<int,string> */
function operations_reconciliation_reference_tokens(string $value): array
{
    preg_match_all('/\b(?=[A-Z0-9-]{4,}\b)(?=[A-Z0-9-]*\d)[A-Z0-9]+(?:-[A-Z0-9]+)*\b/i',$value,$matches);
    $out=[];foreach($matches[0]??[] as $token){$normalized=strtoupper(str_replace('-','',(string)$token));if(strlen($normalized)>=4)$out[$normalized]=true;}return array_keys($out);
}

function operations_reconciliation_text_similarity(string $left,string $right): float
{
    $a=operations_reconciliation_normalize_evidence($left);$b=operations_reconciliation_normalize_evidence($right);if($a===''||$b==='')return 0.0;if($a===$b)return 1.0;
    $at=array_values(array_unique(array_filter(preg_split('/\s+/u',$a)?:[],static fn($v)=>mb_strlen((string)$v)>=3)));$bt=array_values(array_unique(array_filter(preg_split('/\s+/u',$b)?:[],static fn($v)=>mb_strlen((string)$v)>=3)));$union=array_values(array_unique(array_merge($at,$bt)));$jaccard=$union?count(array_intersect($at,$bt))/count($union):0.0;
    $aa=substr($a,0,255);$bb=substr($b,0,255);$max=max(strlen($aa),strlen($bb));$edit=$max?max(0.0,1.0-levenshtein($aa,$bb)/$max):0.0;
    return max($jaccard,$edit*.85);
}

/**
 * Find a bounded, deterministic set of same-direction row groups whose integer-
 * cent total equals the target. This is candidate generation only: the commit
 * service still locks and revalidates every row and exact total.
 *
 * @return array<int,array<int,array<string,mixed>>>
 */
function operations_reconciliation_group_subsets(array $rows,int $target,int $maxSize=4,int $maxRows=24,int $maxResults=12): array
{
    if($target===0)return [];$maxSize=max(2,min(6,$maxSize));$maxRows=max(2,min(40,$maxRows));$maxResults=max(1,min(40,$maxResults));
    $eligible=array_values(array_filter($rows,static function($row)use($target):bool{
        if(!is_array($row))return false;$amount=(int)($row['amountCents']??0);
        return $amount!==0&&(($target<0)===($amount<0))&&abs($amount)<=abs($target);
    }));
    $eligible=array_slice($eligible,0,$maxRows);$results=[];$walk=null;
    $walk=static function(int $start,array $chosen,int $sum)use(&$walk,&$results,$eligible,$target,$maxSize,$maxResults):void{
        if(count($results)>=$maxResults)return;
        for($index=$start,$count=count($eligible);$index<$count;$index++){
            $row=$eligible[$index];$nextSum=$sum+(int)$row['amountCents'];$next=$chosen;$next[]=$row;$size=count($next);
            if($nextSum===$target){if($size>=2)$results[]=$next;if(count($results)>=$maxResults)return;continue;}
            if($size>=$maxSize)continue;
            if(($target>0&&$nextSum>=$target)||($target<0&&$nextSum<=$target))continue;
            $walk($index+1,$next,$nextSum);
            if(count($results)>=$maxResults)return;
        }
    };
    $walk(0,[],0);return $results;
}

/**
 * Deterministic evidence order: amount, external reference, counterparty,
 * normalized description, then date proximity. Amount equality is mandatory.
 *
 * @return array{kind:string,score:int,reason:string,days:int}|null
 */
function operations_reconciliation_pair_evidence(array $bank,array $book): ?array
{
    if((int)($bank['amountCents']??0)!==(int)($book['amountCents']??0))return null;
    $days=abs((int)round((strtotime((string)$book['date'])-strtotime((string)$bank['date']))/86400));
    $bankText=implode(' ',[(string)($bank['reference']??''),(string)($bank['description']??''),(string)($bank['normalizedMerchant']??'')]);
    $bookText=implode(' ',[(string)($book['transactionNumber']??''),(string)($book['sourceReference']??''),(string)($book['description']??''),(string)($book['counterparty']??'')]);
    $references=array_values(array_intersect(operations_reconciliation_reference_tokens($bankText),operations_reconciliation_reference_tokens(implode(' ',[(string)($book['transactionNumber']??''),(string)($book['sourceReference']??'')]))));
    if($references&&$days<=45)return ['kind'=>'reference','score'=>max(88,98-min(10,$days)),'reason'=>'Exact amount · matching invoice/payment reference '.$references[0].($days?' · '.$days.' day'.($days===1?'':'s').' apart':' · same date'),'days'=>$days];
    $party=operations_reconciliation_normalize_evidence((string)($book['counterparty']??''));$bankNormalized=operations_reconciliation_normalize_evidence($bankText);
    if($party!==''&&mb_strlen($party)>=3&&(str_contains(' '.$bankNormalized.' ',' '.$party.' ')||operations_reconciliation_text_similarity($party,$bankNormalized)>=.72)&&$days<=45)return ['kind'=>'identity','score'=>max(82,94-min(12,$days)),'reason'=>'Exact amount · matching customer/vendor identity'.($days?' · '.$days.' day'.($days===1?'':'s').' apart':' · same date'),'days'=>$days];
    $similarity=operations_reconciliation_text_similarity($bankText,$bookText);
    if($similarity>=.55&&$days<=21)return ['kind'=>'description','score'=>max(76,min(92,(int)round(78+$similarity*14-min(10,$days)))),'reason'=>'Exact amount · similar normalized description ('.(int)round($similarity*100).'%)'.($days?' · '.$days.' day'.($days===1?'':'s').' apart':' · same date'),'days'=>$days];
    if($days<=7)return ['kind'=>'amount_date','score'=>max(70,90-$days*3),'reason'=>$days===0?'Exact amount · same date':'Exact amount · '.$days.' day'.($days===1?'':'s').' apart','days'=>$days];
    return null;
}

/**
 * Build candidate groups from the already validated reconciliation workspace.
 * Generated evidence is persisted for audit reconstruction; accounting and
 * reconciliation state remain unchanged.
 *
 * @param array<int,string> $selectedBankIds
 * @param array<int,string> $selectedBookIds
 * @return array<string,mixed>
 */
function operations_reconciliation_suggestions_data(array $company,string $bankId,string $start,string $end,array $selectedBankIds=[],array $selectedBookIds=[],bool $enforceUserPermission=true): array
{
    $workspace=operations_reconciliation_workspace_data($company,$bankId,$start,$end,$enforceUserPermission);
    $bankRows=array_values(array_filter($workspace['bankSide'],static fn(array $r):bool=>!$r['matched']&&!$r['reconciled']));
    $bookRows=array_values($workspace['bookSide']);
    $selectedBankIds=array_values(array_unique(array_filter(array_map('strval',$selectedBankIds))));
    $selectedBookIds=array_values(array_unique(array_filter(array_map('strval',$selectedBookIds))));
    if($selectedBankIds){
        $allowed=array_fill_keys($selectedBankIds,true);
        $bankRows=array_values(array_filter($bankRows,static fn(array $r):bool=>isset($allowed[(string)$r['id']])));
    }
    if($selectedBookIds){
        $allowed=array_fill_keys($selectedBookIds,true);
        $bookRows=array_values(array_filter($bookRows,static fn(array $r):bool=>isset($allowed[(string)$r['journalEntryId']])));
    }
    $bookById=[];foreach($bookRows as $r)$bookById[(string)$r['journalEntryId']]=$r;

    // Exact source relationships are exclusive candidate evidence. If a posted
    // bank row already points to a journal entry, neither side may be offered
    // in a fuzzy amount/date or grouped proposal. The authoritative match
    // service enforces the same rule, so the preview must never suggest a
    // combination that would later be rejected.
    $reservedExactBank=[];$reservedExactBook=[];
    foreach($bankRows as $b){
        $jid=trim((string)($b['linkedJournalEntryId']??''));
        if(!empty($b['directAccountingLink'])&&$jid!==''){
            $reservedExactBank[(string)$b['id']]=true;
            $reservedExactBook[$jid]=true;
        }
    }
    foreach($bookRows as $j){
        $bid=trim((string)($j['directBankTransactionId']??''));
        if($bid!==''){
            $reservedExactBook[(string)$j['journalEntryId']]=true;
            $reservedExactBank[$bid]=true;
        }
    }
    $nonExactBankRows=array_values(array_filter($bankRows,static fn(array $r):bool=>!isset($reservedExactBank[(string)$r['id']])));
    $nonExactBookRows=array_values(array_filter($bookRows,static fn(array $r):bool=>!isset($reservedExactBook[(string)$r['journalEntryId']])));

    $proposals=[];$proposalKeys=[];
    $add=function(array $bankSet,array $bookSet,string $kind,string $reason,?int $evidenceScore=null)use(&$proposals,&$proposalKeys):void{
        if(!$bankSet||!$bookSet)return;
        $bankTotal=array_sum(array_map(static fn($r)=>(int)$r['amountCents'],$bankSet));
        $bookTotal=array_sum(array_map(static fn($r)=>(int)$r['amountCents'],$bookSet));
        if($bankTotal!==$bookTotal)return;
        $key=implode(',',array_map(static fn($r)=>(string)$r['id'],$bankSet)).'|'.implode(',',array_map(static fn($r)=>(string)$r['journalEntryId'],$bookSet));
        if(isset($proposalKeys[$key]))return;$proposalKeys[$key]=true;
        $proposals[]=operations_reconciliation_match_score($bankSet,$bookSet,$kind,$reason,$evidenceScore);
    };
    foreach($bankRows as $b){
        $jid=(string)($b['linkedJournalEntryId']??'');
        if($jid!==''&&isset($bookById[$jid])&&(int)$bookById[$jid]['amountCents']===(int)$b['amountCents']){
            $add([$b],[$bookById[$jid]],'exact_source','Exact source link · same financial account and amount',100);
        }
    }
    foreach($nonExactBankRows as $b){
        foreach($nonExactBookRows as $j){
            $evidence=operations_reconciliation_pair_evidence($b,$j);if($evidence===null)continue;
            $add([$b],[$j],$evidence['kind'],$evidence['reason'],$evidence['score']);
        }
    }
    $maxBook=min(count($nonExactBookRows),80);$maxBank=min(count($nonExactBankRows),80);
    foreach(array_slice($nonExactBankRows,0,$maxBank) as $b){
        foreach(operations_reconciliation_group_subsets($nonExactBookRows,(int)$b['amountCents']) as $bookSet){$count=count($bookSet);$add([$b],$bookSet,'group_total','Exact group total · one bank item equals '.$count.' book entries');}
    }
    foreach(array_slice($nonExactBookRows,0,$maxBook) as $j){
        foreach(operations_reconciliation_group_subsets($nonExactBankRows,(int)$j['amountCents']) as $bankSet){$count=count($bankSet);$add($bankSet,[$j],'group_total','Exact group total · '.$count.' bank items equal one book entry');}
    }
    if(count($nonExactBankRows)>=2&&count($nonExactBookRows)>=2){
        for($bi=0;$bi<min($maxBank,30);$bi++)for($bj=$bi+1;$bj<min($maxBank,30);$bj++){
            $bt=(int)$nonExactBankRows[$bi]['amountCents']+(int)$nonExactBankRows[$bj]['amountCents'];
            for($ji=0;$ji<min($maxBook,30);$ji++)for($jj=$ji+1;$jj<min($maxBook,30);$jj++){
                if($bt===(int)$nonExactBookRows[$ji]['amountCents']+(int)$nonExactBookRows[$jj]['amountCents'])$add([$nonExactBankRows[$bi],$nonExactBankRows[$bj]],[$nonExactBookRows[$ji],$nonExactBookRows[$jj]],'group_total','Exact group total · two bank items equal two book entries');
            }
        }
    }
    usort($proposals,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:($a['dateDifferenceDays']<=>$b['dateDifferenceDays']));
    $proposals=array_slice($proposals,0,80);
    $usedBank=[];$usedBook=[];
    foreach($proposals as &$proposal){
        $conflicts=[];
        foreach($proposal['bankTransactionIds'] as $id)if(isset($usedBank[$id]))$conflicts[]='bank:'.$id;
        foreach($proposal['journalEntryIds'] as $id)if(isset($usedBook[$id]))$conflicts[]='book:'.$id;
        $proposal['conflicts']=$conflicts;
        // Only a canonical one-to-one source link is safe to preselect.
        // Amount/date, fuzzy identity and grouped-total proposals require an
        // explicit user choice even when they do not conflict with another row.
        $proposal['selectedByDefault']=empty($conflicts)
            && (string)$proposal['kind']==='exact_source'
            && count((array)$proposal['bankTransactionIds'])===1
            && count((array)$proposal['journalEntryIds'])===1;
        if($proposal['selectedByDefault']){
            foreach($proposal['bankTransactionIds'] as $id)$usedBank[$id]=true;
            foreach($proposal['journalEntryIds'] as $id)$usedBook[$id]=true;
        }
    }unset($proposal);
    $coveredBank=[];$coveredBook=[];
    foreach($proposals as $proposal){foreach($proposal['bankTransactionIds'] as $id)$coveredBank[$id]=true;foreach($proposal['journalEntryIds'] as $id)$coveredBook[$id]=true;}
    $persisted=operations_persist_reconciliation_proposals((string)$company['id'],$bankId,$start,$end,$proposals);
    return [
        'account'=>$workspace['account'],'start'=>$start,'end'=>$end,'summary'=>$workspace['summary'],
        'proposals'=>$proposals,
        'unmatchedBank'=>array_values(array_filter($bankRows,static fn($r)=>!isset($coveredBank[(string)$r['id']]))),
        'unmatchedBooks'=>array_values(array_filter($bookRows,static fn($r)=>!isset($coveredBook[(string)$r['journalEntryId']]))),
        'analysisSource'=>'deterministic','accountingEntryCreated'=>false,'persistedProposalCount'=>$persisted,
    ];
}

function operations_reconciliation_suggestions(array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.view');
    $input=request_json();
    $bankId=clean_text($input['bankAccountId']??'','Financial account',64);
    $start=safe_date($input['start']??date('Y-m-01'),'From date');$end=safe_date($input['end']??canadian_today(),'To date');
    $bankIds=is_array($input['bankTransactionIds']??null)?array_slice($input['bankTransactionIds'],0,100):[];
    $bookIds=is_array($input['journalEntryIds']??null)?array_slice($input['journalEntryIds'],0,100):[];
    json_response(operations_reconciliation_suggestions_data($company,$bankId,$start,$end,$bankIds,$bookIds));
}

/**
 * Reconciliation metadata cannot be changed across a locked accounting date.
 * Load the relevant locks once so bulk selection does not issue one query per
 * bank or journal row.
 *
 * @param array<int,string> $dates
 */
function operations_assert_reconciliation_dates_open(string $companyId,array $dates): void
{
    $dates=array_values(array_unique(array_filter(array_map('strval',$dates))));
    if(!$dates)return;
    sort($dates,SORT_STRING);$first=$dates[0];$last=$dates[count($dates)-1];$locks=[];
    if(schema_table_exists('period_locks')){
        $stmt=db()->prepare('SELECT id,period_start,period_end,reason FROM period_locks WHERE company_id=? AND locked=1 AND period_start<=? AND period_end>=? ORDER BY period_end DESC');
        $stmt->execute([$companyId,$last,$first]);$locks=$stmt->fetchAll();
    }
    $stmt=db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id=?');$stmt->execute([$companyId]);$closed=$stmt->fetchColumn();
    foreach($dates as $date){
        foreach($locks as $lock)if($date>=(string)$lock['period_start']&&$date<=(string)$lock['period_end'])fail('This reconciliation date is in a locked period. Reopen the period in Settings → Period Locking before matching.',409,'period_locked');
        if($closed!==false&&$closed!==null&&$date<=(string)$closed)fail('This reconciliation date is in a locked period. Reopen the period in Settings → Period Locking before matching.',409,'period_locked');
    }
}

function operations_assert_reconciliation_range_open(string $companyId,string $start,string $end): void
{
    if(schema_table_exists('period_locks')){
        $stmt=db()->prepare('SELECT id,period_start,period_end,reason FROM period_locks WHERE company_id=? AND locked=1 AND period_start<=? AND period_end>=? ORDER BY period_end DESC LIMIT 1');
        $stmt->execute([$companyId,$end,$start]);$lock=$stmt->fetch();
        if($lock)fail('This reconciliation period overlaps a locked period. Reopen it in Settings → Period Locking before completing reconciliation.',409,'period_locked');
    }
    $stmt=db()->prepare('SELECT closed_through_date FROM accounting_controls WHERE company_id=?');$stmt->execute([$companyId]);$closed=$stmt->fetchColumn();
    if($closed!==false&&$closed!==null&&$start<=(string)$closed)fail('This reconciliation period overlaps a locked period. Reopen it in Settings → Period Locking before completing reconciliation.',409,'period_locked');
}

function operations_reconciliation_complete(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.reconcile');$input=request_json();
    $end=safe_date($input['periodEnd']??'','Period end');$start=trim((string)($input['periodStart']??''));$start=$start===''?substr($end,0,8).'01':safe_date($start,'Period start');
    if($start>$end)fail('Period start cannot be after period end.');operations_assert_reconciliation_range_open((string)$company['id'],$start,$end);
    handle_reconciliations();
}


/**
 * Revalidate and create one metadata-only match group. When called by the
 * bulk service, the caller owns the transaction so every proposed group is
 * all-or-nothing.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function operations_reconciliation_match_service(array $user,array $company,array $input,bool $manageTransaction=true): array
{
    $companyId=(string)$company['id'];$bankId=clean_text($input['bankAccountId']??'','Financial account',64);
    $bankIds=array_values(array_unique(array_filter(array_map('strval',is_array($input['bankTransactionIds']??null)?$input['bankTransactionIds']:[]))));
    $journalIds=array_values(array_unique(array_filter(array_map('strval',is_array($input['journalEntryIds']??null)?$input['journalEntryIds']:[]))));
    if(!$bankIds)fail('Select at least one bank-side transaction.');if(!$journalIds)fail('Select at least one existing book-side entry. Record any missing accounting entry through its normal workflow first.',409,'book_entry_required');
    $q=db()->prepare("SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND account_type IN ('bank','credit_card') AND active=1");$q->execute([$bankId,$companyId]);$bank=$q->fetch();if(!$bank)fail('Choose a valid financial account.');
    if($manageTransaction)db()->beginTransaction();try{
        $ph=implode(',',array_fill(0,count($bankIds),'?'));
        $q=db()->prepare("SELECT id,transaction_date,amount_cents,status,journal_entry_id FROM bank_transactions WHERE company_id=? AND bank_account_id=? AND id IN ($ph) AND status NOT IN ('duplicate','excluded') FOR UPDATE");
        $q->execute(array_merge([$companyId,$bankId],$bankIds));$bankRows=$q->fetchAll();if(count($bankRows)!==count($bankIds))fail('One or more bank transactions are unavailable.',409,'bank_match_unavailable');
        $dup=db()->prepare("SELECT COUNT(*) FROM bank_match_bank_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.bank_transaction_id IN ($ph) AND g.status='matched'");$dup->execute($bankIds);if((int)$dup->fetchColumn()>0)fail('One of the selected bank transactions is already matched. Unreconcile it first.',409,'bank_already_matched');
        $reconciled=db()->prepare("SELECT COUNT(*) FROM reconciliation_items ri JOIN reconciliations r ON r.id=ri.reconciliation_id WHERE ri.bank_transaction_id IN ($ph) AND r.company_id=? AND r.status='complete'");$reconciled->execute(array_merge($bankIds,[$companyId]));if((int)$reconciled->fetchColumn()>0)fail('One of the selected bank transactions is already reconciled.',409,'bank_already_reconciled');
        $bankAmount=array_sum(array_map(static fn($r)=>(int)$r['amount_cents'],$bankRows));
        $matchDateInput=trim((string)($input['matchDate']??''));$matchDate=$matchDateInput===''?max(array_map(static fn($r)=>(string)$r['transaction_date'],$bankRows)):safe_date($matchDateInput,'Match date');assert_not_future_date($matchDate,'Match date');

        $jph=implode(',',array_fill(0,count($journalIds),'?'));
        $lockJ=db()->prepare("SELECT id,entry_date,source_type,source_id FROM journal_entries WHERE company_id=? AND status='posted' AND id IN ($jph) FOR UPDATE");$lockJ->execute(array_merge([$companyId],$journalIds));$locked=$lockJ->fetchAll();if(count($locked)!==count($journalIds))fail('One or more book-side entries are unavailable.',409,'book_match_unavailable');
        $q=db()->prepare("SELECT je.id,SUM(jl.debit_cents-jl.credit_cents) amount_cents FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND je.id IN ($jph) GROUP BY je.id");$q->execute(array_merge([$companyId,$bank['ledger_account_id']],$journalIds));$bookRows=$q->fetchAll();if(count($bookRows)!==count($journalIds))fail('One or more book-side entries do not post to this financial account.',409,'book_match_unavailable');
        $dup=db()->prepare("SELECT COUNT(*) FROM bank_match_book_items bi JOIN bank_match_groups g ON g.id=bi.match_group_id WHERE bi.journal_entry_id IN ($jph) AND g.status='matched' AND g.bank_account_id=?");$dup->execute(array_merge($journalIds,[$bankId]));if((int)$dup->fetchColumn()>0)fail('One of the selected accounting entries is already matched. Unreconcile it first.',409,'book_already_matched');

        // A bank-posted journal is not "already reconciled" merely because it is
        // source-linked. It may be matched, but only to the exact source bank row.
        $selectedBankSet=array_fill_keys($bankIds,true);$selectedJournalSet=array_fill_keys($journalIds,true);$hasDirectSource=false;
        foreach($bankRows as $row){if((string)$row['status']==='posted'&&$row['journal_entry_id']!==null){$hasDirectSource=true;if(!isset($selectedJournalSet[(string)$row['journal_entry_id']]))fail('A posted bank transaction must be matched to its exact linked accounting entry.',409,'exact_source_book_entry_required');}}
        $linked=db()->prepare("SELECT id,journal_entry_id FROM bank_transactions WHERE company_id=? AND bank_account_id=? AND status='posted' AND journal_entry_id IN ($jph) FOR UPDATE");$linked->execute(array_merge([$companyId,$bankId],$journalIds));
        foreach($linked->fetchAll() as $row){$hasDirectSource=true;if(!isset($selectedBankSet[(string)$row['id']]))fail('A selected book entry is directly linked to a different bank transaction. Match the exact source pair instead.',409,'book_linked_to_other_bank_transaction');}
        if($hasDirectSource&&(count($bankIds)!==1||count($journalIds)!==1))fail('A directly linked bank transaction and accounting entry must be matched as their exact one-to-one source pair, without additional rows.',409,'exact_source_pair_only');

        $bookAmount=array_sum(array_map(static fn($r)=>(int)$r['amount_cents'],$bookRows));
        $accountingDates=[$matchDate];foreach($bankRows as $row)$accountingDates[]=(string)$row['transaction_date'];foreach($locked as $row)$accountingDates[]=(string)$row['entry_date'];operations_assert_reconciliation_dates_open($companyId,$accountingDates);

        $groupId=new_id('bmatch');
        $difference=$bankAmount-$bookAmount;$adjustmentJournalEntryId=null;
        $tolerance=max(0,min(5000,(int)(config('accounting.reconciliation_tolerance_cents')??500)));
        if($difference!==0){
            if(abs($difference)>$tolerance)fail('Selected bank and book amounts differ by more than the configured reconciliation tolerance. Record the missing transaction through its source workflow.',409,'reconciliation_amount_mismatch');
            $feeAccount=account_by_code($companyId,'6800');
            $adjustmentLines=$difference<0
                ? [['accountId'=>$feeAccount,'debitCents'=>abs($difference),'creditCents'=>0,'memo'=>'Bank fee reconciliation tolerance'],['accountId'=>(string)$bank['ledger_account_id'],'debitCents'=>0,'creditCents'=>abs($difference),'memo'=>'Statement adjustment']]
                : [['accountId'=>(string)$bank['ledger_account_id'],'debitCents'=>$difference,'creditCents'=>0,'memo'=>'Statement adjustment'],['accountId'=>$feeAccount,'debitCents'=>0,'creditCents'=>$difference,'memo'=>'Bank fee/interest reconciliation tolerance']];
            $adjustmentJournalEntryId=add_journal_entry($user,$companyId,$matchDate,'bank_reconciliation_adjustment',$groupId,'Reconciliation tolerance adjustment · '.number_format(abs($difference)/100,2),$adjustmentLines);
            $bookRows[]=['id'=>$adjustmentJournalEntryId,'amount_cents'=>$difference];$bookAmount+=$difference;
            if(function_exists('voucher_register_saved'))voucher_register_saved($user,$companyId,'BJ','BS','bank_reconciliation_adjustment',$groupId,$matchDate,'Reconciliation tolerance adjustment',abs($difference),$adjustmentJournalEntryId,true);
        }
        if($bankAmount!==$bookAmount)throw new RuntimeException('Reconciliation adjustment failed the bank-to-books invariant.');
        db()->prepare("INSERT INTO bank_match_groups (id,company_id,bank_account_id,status,bank_amount_cents,book_amount_cents,adjustment_journal_entry_id,matched_by,matched_at) VALUES (?,?,?,'matched',?,?,?,?,UTC_TIMESTAMP())")->execute([$groupId,$companyId,$bankId,$bankAmount,$bookAmount,$adjustmentJournalEntryId,$user['id']]);
        $bi=db()->prepare('INSERT INTO bank_match_bank_items (match_group_id,bank_transaction_id,amount_cents) VALUES (?,?,?)');foreach($bankRows as $r)$bi->execute([$groupId,$r['id'],$r['amount_cents']]);
        $ji=db()->prepare('INSERT INTO bank_match_book_items (match_group_id,journal_entry_id,amount_cents) VALUES (?,?,?)');foreach($bookRows as $r)$ji->execute([$groupId,$r['id'],$r['amount_cents']]);
        audit_event($user,$companyId,'reconciliation.match_created','bank_match_group',$groupId,['bankTransactionCount'=>count($bankRows),'bookEntryCount'=>count($bookRows),'amountCents'=>$bankAmount,'matchDate'=>$matchDate,'accountingEntryCreated'=>$adjustmentJournalEntryId!==null,'adjustmentJournalEntryId'=>$adjustmentJournalEntryId,'adjustmentCents'=>$difference,'toleranceCents'=>$tolerance]);
        if($manageTransaction)db()->commit();
    }catch(Throwable $e){if($manageTransaction&&db()->inTransaction())db()->rollBack();throw $e;}
    return ['matchGroupId'=>$groupId,'amountCents'=>$bankAmount,'matchDate'=>$matchDate,'adjustmentJournalEntryId'=>$adjustmentJournalEntryId,'accountingEntryCreated'=>$adjustmentJournalEntryId!==null,'adjustmentCents'=>$difference];
}

function operations_reconciliation_match(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.reconcile');
    json_response(operations_reconciliation_match_service($user,$company,request_json()),201);
}

function operations_reconciliation_match_bulk(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.reconcile');$input=request_json();$bankId=clean_text($input['bankAccountId']??'','Financial account',64);$matchDate=trim((string)($input['matchDate']??''));$groups=is_array($input['groups']??null)?array_slice($input['groups'],0,40):[];if(!$groups)fail('Select at least one balanced proposal.',422,'reconciliation_bulk_empty');
    $usedBank=[];$usedBook=[];$prepared=[];
    foreach($groups as $index=>$group){if(!is_array($group))fail('A selected proposal is invalid.',422,'reconciliation_bulk_invalid');$bankIds=array_values(array_unique(array_filter(array_map('strval',is_array($group['bankTransactionIds']??null)?$group['bankTransactionIds']:[]))));$bookIds=array_values(array_unique(array_filter(array_map('strval',is_array($group['journalEntryIds']??null)?$group['journalEntryIds']:[]))));if(!$bankIds||!$bookIds)fail('Every selected proposal needs both bank and book records.',422,'reconciliation_bulk_invalid');foreach($bankIds as $id){if(isset($usedBank[$id]))fail('A bank transaction appears in more than one selected proposal.',409,'reconciliation_bulk_conflict');$usedBank[$id]=true;}foreach($bookIds as $id){if(isset($usedBook[$id]))fail('A book entry appears in more than one selected proposal.',409,'reconciliation_bulk_conflict');$usedBook[$id]=true;}$prepared[]=['bankAccountId'=>$bankId,'bankTransactionIds'=>$bankIds,'journalEntryIds'=>$bookIds,'matchDate'=>$matchDate];}
    $results=[];db()->beginTransaction();try{foreach($prepared as $group)$results[]=operations_reconciliation_match_service($user,$company,$group,false);$adjustmentCount=count(array_filter($results,static fn(array $result):bool=>!empty($result['accountingEntryCreated'])));audit_event($user,(string)$company['id'],'reconciliation.bulk_match_created','bank_account',$bankId,['matchGroupCount'=>count($results),'bankTransactionCount'=>count($usedBank),'bookEntryCount'=>count($usedBook),'accountingEntryCreated'=>$adjustmentCount>0,'adjustmentCount'=>$adjustmentCount]);db()->commit();}catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
    json_response(['matched'=>count($results),'groups'=>$results,'accountingEntryCreated'=>$adjustmentCount>0,'adjustmentCount'=>$adjustmentCount],201);
}

/** @return array{score:int,reasons:array<int,string>} */
function operations_review_document_score(array $transaction,array $document): array
{
    $amount=abs((int)$transaction['foreign_amount_cents']);$balance=(int)$document['foreign_balance_cents'];$score=$amount===$balance?72:56;$reasons=[$amount===$balance?'Exact remaining balance':'Valid partial payment amount'];
    $bankText=implode(' ',[(string)($transaction['reference']??''),(string)$transaction['description'],(string)($transaction['normalized_merchant']??'')]);$documentRef=(string)$document['number'];$refs=array_intersect(operations_reconciliation_reference_tokens($bankText),operations_reconciliation_reference_tokens($documentRef));if($refs){$score+=18;$reasons[]='Matching invoice/payment reference '.array_values($refs)[0];}
    $party=operations_reconciliation_normalize_evidence((string)$document['party_name']);$bankNormalized=operations_reconciliation_normalize_evidence($bankText);if($party!==''&&(str_contains(' '.$bankNormalized.' ',' '.$party.' ')||operations_reconciliation_text_similarity($party,$bankNormalized)>=.72)){$score+=12;$reasons[]='Customer/vendor name appears on the statement';}
    $similarity=operations_reconciliation_text_similarity($bankText,implode(' ',[$documentRef,(string)$document['party_name']]));if($similarity>=.55){$score+=min(8,(int)round($similarity*8));$reasons[]='Similar normalized description';}
    $date=(string)($document['document_date']??'');if($date!==''){$days=(int)round((strtotime((string)$transaction['transaction_date'])-strtotime($date))/86400);if($days>=0&&$days<=60){$score+=4;$reasons[]=$days===0?'Same date':'Statement date is '.$days.' day'.($days===1?'':'s').' after the document';}elseif($days<0){$score-=10;$reasons[]='Statement date precedes the document';}}
    return ['score'=>max(0,min(98,$score)),'reasons'=>$reasons];
}

/** @return array{score:int,reasons:array<int,string>} */
function operations_review_existing_payment_score(array $transaction,array $payment): array
{
    $score=70;$reasons=['Exact amount and financial account'];
    $bankText=implode(' ',[(string)($transaction['reference']??''),(string)($transaction['description']??''),(string)($transaction['normalized_merchant']??'')]);
    $paymentText=implode(' ',[(string)($payment['reference']??''),(string)($payment['document_number']??''),(string)($payment['party_name']??'')]);
    $refs=array_values(array_intersect(operations_reconciliation_reference_tokens($bankText),operations_reconciliation_reference_tokens($paymentText)));
    if($refs){$score+=16;$reasons[]='Matching invoice/payment reference '.$refs[0];}
    $party=operations_reconciliation_normalize_evidence((string)($payment['party_name']??''));$bankNormalized=operations_reconciliation_normalize_evidence($bankText);
    if($party!==''&&mb_strlen($party)>=3&&(str_contains(' '.$bankNormalized.' ',' '.$party.' ')||operations_reconciliation_text_similarity($party,$bankNormalized)>=.72)){$score+=10;$reasons[]='Customer/vendor name appears on the statement';}
    $similarity=operations_reconciliation_text_similarity($bankText,$paymentText);if($similarity>=.55){$score+=min(8,(int)round($similarity*8));$reasons[]='Similar normalized description';}
    $days=abs((int)round((strtotime((string)$payment['payment_date'])-strtotime((string)$transaction['transaction_date']))/86400));$datePoints=max(0,8-intdiv($days,7));$score+=$datePoints;$reasons[]=$days===0?'Same date':'Payment and statement are '.$days.' day'.($days===1?'':'s').' apart';
    return ['score'=>max(0,min(98,$score)),'reasons'=>$reasons];
}

function operations_review_payment_suggestions(array $company): never
{
    require_method('POST');require_csrf();require_company_permission($company,'banking.view');$input=request_json();$ids=array_values(array_unique(array_filter(array_map('strval',is_array($input['transactionIds']??null)?$input['transactionIds']:[]))));$ids=array_slice($ids,0,100);if(!$ids)fail('Select at least one pending bank transaction.',422,'review_suggestion_empty');
    $companyId=(string)$company['id'];$ph=implode(',',array_fill(0,count($ids),'?'));$q=db()->prepare("SELECT bt.id,bt.bank_account_id,bt.transaction_date,bt.description,bt.normalized_merchant,bt.reference,bt.amount_cents,bt.foreign_amount_cents,bt.currency,ba.ledger_account_id FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id WHERE bt.company_id=? AND bt.id IN ($ph) AND bt.status='pending' ORDER BY bt.transaction_date,bt.id");$q->execute(array_merge([$companyId],$ids));$transactions=$q->fetchAll();$suggestions=[];
    foreach($transactions as $transaction){$incoming=(int)$transaction['amount_cents']>0;$foreign=abs((int)$transaction['foreign_amount_cents']);if($foreign<=0)continue;$candidates=[];
        if(($incoming&&company_role_can((string)$company['role'],'invoices.view'))||(!$incoming&&company_role_can((string)$company['role'],'bills.view'))){
            $paymentType=$incoming?'customer':'vendor';$payment=db()->prepare("SELECT pp.id,pp.reference,pp.payment_date,pp.foreign_amount_cents,COALESCE(c.name,v.name,'') party_name,COALESCE(i.number,b.number,'') document_number FROM party_payments pp LEFT JOIN customers c ON pp.payment_type='customer' AND c.id=pp.party_id AND c.company_id=pp.company_id LEFT JOIN vendors v ON pp.payment_type='vendor' AND v.id=pp.party_id AND v.company_id=pp.company_id LEFT JOIN invoices i ON pp.payment_type='customer' AND i.id=pp.document_id AND i.company_id=pp.company_id LEFT JOIN bills b ON pp.payment_type='vendor' AND b.id=pp.document_id AND b.company_id=pp.company_id WHERE pp.company_id=? AND pp.payment_type=? AND pp.status='posted' AND pp.bank_transaction_id IS NULL AND pp.payment_account_id=? AND pp.currency=? AND pp.foreign_amount_cents=? AND ABS(DATEDIFF(pp.payment_date,?))<=60 ORDER BY ABS(DATEDIFF(pp.payment_date,?)),pp.created_at LIMIT 5");$payment->execute([$companyId,$paymentType,$transaction['ledger_account_id'],$transaction['currency'],$foreign,$transaction['transaction_date'],$transaction['transaction_date']]);foreach($payment->fetchAll() as $row){$rank=operations_review_existing_payment_score($transaction,$row);if($rank['score']<72)continue;$reasons=$rank['reasons'];$reasons[]='Payment is already posted; matching will not post it twice';$candidates[]=['kind'=>'posted_payment','targetId'=>(string)$row['id'],'title'=>$incoming?'Match Posted Customer Receipt':'Match Posted Vendor Payment','party'=>(string)$row['party_name'],'reference'=>(string)($row['document_number']?:$row['reference']),'documentDate'=>(string)$row['payment_date'],'amountCents'=>(int)$row['foreign_amount_cents'],'score'=>$rank['score'],'confidence'=>$rank['score']>=90?'High':($rank['score']>=80?'Strong':'Possible'),'reasons'=>$reasons,'proposedDecision'=>['id'=>(string)$transaction['id'],'paymentId'=>(string)$row['id']]];}
            if($incoming){$docs=db()->prepare("SELECT i.id,i.number,i.issue_date document_date,i.due_date,i.foreign_balance_cents,c.name party_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.status='sent' AND i.currency=? AND i.foreign_balance_cents>=? ORDER BY CASE WHEN i.foreign_balance_cents=? THEN 0 ELSE 1 END,ABS(DATEDIFF(i.due_date,?)),i.due_date LIMIT 25");$docs->execute([$companyId,$transaction['currency'],$foreign,$foreign,$transaction['transaction_date']]);}
            else{$docs=db()->prepare("SELECT b.id,b.number,b.bill_date document_date,b.due_date,b.foreign_balance_cents,v.name party_name FROM bills b JOIN vendors v ON v.id=b.vendor_id AND v.company_id=b.company_id WHERE b.company_id=? AND b.status='open' AND b.currency=? AND b.foreign_balance_cents>=? ORDER BY CASE WHEN b.foreign_balance_cents=? THEN 0 ELSE 1 END,ABS(DATEDIFF(b.due_date,?)),b.due_date LIMIT 25");$docs->execute([$companyId,$transaction['currency'],$foreign,$foreign,$transaction['transaction_date']]);}
            foreach($docs->fetchAll() as $document){$rank=operations_review_document_score($transaction,$document);if($rank['score']<68)continue;$candidates[]=['kind'=>$incoming?'invoice_payment':'bill_payment','targetId'=>(string)$document['id'],'title'=>$incoming?'Prepare Customer Invoice Payment':'Prepare Vendor Invoice Payment','party'=>(string)$document['party_name'],'reference'=>(string)$document['number'],'documentDate'=>(string)$document['document_date'],'amountCents'=>$foreign,'openBalanceCents'=>(int)$document['foreign_balance_cents'],'score'=>$rank['score'],'confidence'=>$rank['score']>=90?'High':($rank['score']>=78?'Strong':'Possible'),'reasons'=>$rank['reasons'],'proposedDecision'=>['id'=>(string)$transaction['id'],($incoming?'invoiceId':'billId')=>(string)$document['id']]];}
        }
        if(!$incoming&&company_role_can((string)$company['role'],'payroll.view')){$existing=db()->prepare("SELECT je.id,je.entry_date,je.source_type,je.memo,SUM(jl.debit_cents-jl.credit_cents) bank_impact_cents FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND je.source_type IN ('payroll_payment','payroll_remittance') AND jl.account_id=? AND ABS(DATEDIFF(je.entry_date,?))<=60 AND NOT EXISTS(SELECT 1 FROM bank_match_book_items bmi JOIN bank_match_groups g ON g.id=bmi.match_group_id WHERE bmi.journal_entry_id=je.id AND g.company_id=je.company_id AND g.status='matched') AND NOT EXISTS(SELECT 1 FROM bank_transactions linked WHERE linked.company_id=je.company_id AND linked.journal_entry_id=je.id AND linked.status='posted') GROUP BY je.id,je.entry_date,je.source_type,je.memo HAVING bank_impact_cents=? ORDER BY ABS(DATEDIFF(je.entry_date,?)),je.entry_date LIMIT 5");$existing->execute([$companyId,$transaction['ledger_account_id'],$transaction['transaction_date'],(int)$transaction['amount_cents'],$transaction['transaction_date']]);foreach($existing->fetchAll() as $row){$days=abs((int)round((strtotime((string)$row['entry_date'])-strtotime((string)$transaction['transaction_date']))/86400));$score=max(78,96-min(18,$days*3));$candidates[]=['kind'=>'reconcile_existing','targetId'=>(string)$row['id'],'title'=>'Reconcile Existing '.(str_contains((string)$row['source_type'],'remittance')?'Payroll Remittance':'Payroll Payment'),'party'=>'Payroll','reference'=>(string)$row['memo'],'documentDate'=>(string)$row['entry_date'],'amountCents'=>$foreign,'score'=>$score,'confidence'=>$score>=90?'High':'Strong','reasons'=>['Exact bank-account impact','Existing posted liability payment; do not post it again'],'navigation'=>'bank-reconciliation'];}}
        if(!$incoming&&company_role_can((string)$company['role'],'journals.view')){$liabilities=db()->prepare("SELECT je.id,je.entry_date,je.source_type,je.memo,SUM(jl.debit_cents-jl.credit_cents) bank_impact_cents FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.status='posted' AND jl.account_id=? AND ABS(DATEDIFF(je.entry_date,?))<=60 AND je.source_type NOT IN ('bank_transaction','customer_payment','customer_payment_reversal','vendor_payment','vendor_payment_reversal','payroll_payment','payroll_remittance','bank_reconciliation_adjustment','bank_reconciliation_adjustment_reversal','opening_balance') AND EXISTS(SELECT 1 FROM journal_lines liability_line JOIN accounts liability_account ON liability_account.id=liability_line.account_id AND liability_account.company_id=je.company_id WHERE liability_line.journal_entry_id=je.id AND liability_account.account_type='liability' AND liability_line.debit_cents>0) AND NOT EXISTS(SELECT 1 FROM bank_match_book_items bmi JOIN bank_match_groups g ON g.id=bmi.match_group_id WHERE bmi.journal_entry_id=je.id AND g.company_id=je.company_id AND g.status='matched') AND NOT EXISTS(SELECT 1 FROM bank_transactions linked WHERE linked.company_id=je.company_id AND linked.journal_entry_id=je.id AND linked.status='posted') GROUP BY je.id,je.entry_date,je.source_type,je.memo HAVING bank_impact_cents=? ORDER BY ABS(DATEDIFF(je.entry_date,?)),je.entry_date LIMIT 5");$liabilities->execute([$companyId,$transaction['ledger_account_id'],$transaction['transaction_date'],(int)$transaction['amount_cents'],$transaction['transaction_date']]);foreach($liabilities->fetchAll() as $row){$days=abs((int)round((strtotime((string)$row['entry_date'])-strtotime((string)$transaction['transaction_date']))/86400));$similarity=operations_reconciliation_text_similarity(implode(' ',[(string)($transaction['reference']??''),(string)$transaction['description']]),(string)$row['memo']);$score=min(96,68+max(0,8-intdiv($days,7))+($similarity>=.55?min(14,(int)round($similarity*14)):0));if($score<72)continue;$reasons=['Exact bank-account impact','Existing posted journal settles a liability; do not post it again',$days===0?'Same date':'Entry and statement are '.$days.' day'.($days===1?'':'s').' apart'];if($similarity>=.55)$reasons[]='Similar normalized description';$candidates[]=['kind'=>'reconcile_existing','targetId'=>(string)$row['id'],'title'=>'Reconcile Existing Liability Payment','party'=>'Existing Books','reference'=>(string)$row['memo'],'documentDate'=>(string)$row['entry_date'],'amountCents'=>$foreign,'score'=>$score,'confidence'=>$score>=90?'High':($score>=80?'Strong':'Possible'),'reasons'=>$reasons,'navigation'=>'bank-reconciliation'];}}
        usort($candidates,static fn($a,$b)=>$b['score']<=>$a['score']?:strcmp((string)$a['targetId'],(string)$b['targetId']));if($candidates)$suggestions[]=['transaction'=>['id'=>(string)$transaction['id'],'bankAccountId'=>(string)$transaction['bank_account_id'],'date'=>(string)$transaction['transaction_date'],'description'=>(string)$transaction['description'],'reference'=>(string)($transaction['reference']??''),'amountCents'=>(int)$transaction['foreign_amount_cents'],'currency'=>(string)$transaction['currency'],'direction'=>$incoming?'receipt':'withdrawal'],'best'=>$candidates[0],'alternatives'=>array_slice($candidates,1,3)];
    }
    json_response(['suggestions'=>$suggestions,'selectedCount'=>count($ids),'suggestedCount'=>count($suggestions),'analysisSource'=>'deterministic_company_records','posted'=>0,'canPreparePayment'=>company_role_can((string)$company['role'],'banking.match')]);
}

function operations_unreconcile_reason_code(mixed $value): string
{
    $code=trim((string)$value);if($code==='')$code='other';
    if(!in_array($code,['bank_error','duplicate','wrong_book_entry','wrong_period','reconciliation_reopened','other'],true))fail('Choose a supported unreconcile reason.',422,'unreconcile_reason_invalid');
    return $code;
}

function operations_unreconcile_group(array $user,string $companyId,array $group,string $reason,string $date,string $reasonCode='other'): ?string
{
    $dates=db()->prepare("SELECT bt.transaction_date affected_date FROM bank_match_bank_items bi JOIN bank_transactions bt ON bt.id=bi.bank_transaction_id WHERE bi.match_group_id=? AND bt.company_id=? UNION SELECT je.entry_date affected_date FROM bank_match_book_items bi JOIN journal_entries je ON je.id=bi.journal_entry_id WHERE bi.match_group_id=? AND je.company_id=?");
    $dates->execute([$group['id'],$companyId,$group['id'],$companyId]);
    operations_assert_reconciliation_dates_open($companyId,array_column($dates->fetchAll(),'affected_date'));
    $reversal=$group['adjustment_reversal_journal_entry_id']??null;
    if(($group['adjustment_journal_entry_id']??null)!==null && $reversal===null){
        $reversal=add_reversing_journal_entry($user,$companyId,(string)$group['adjustment_journal_entry_id'],$date,'bank_reconciliation_adjustment_reversal',(string)$group['id'],'Reverse reconciliation adjustment: '.$reason);
        if(function_exists('voucher_register_saved')){
            $amountStmt=db()->prepare('SELECT COALESCE(SUM(debit_cents),0) FROM journal_lines WHERE journal_entry_id=?');$amountStmt->execute([$reversal]);$reversalAmount=(int)$amountStmt->fetchColumn();
            voucher_register_saved($user,$companyId,'BJ','BS','bank_reconciliation_adjustment_reversal',(string)$group['id'],$date,'Reversal · reconciliation adjustment',$reversalAmount,$reversal,true);
        }
    }
    db()->prepare("UPDATE bank_match_groups SET status='unreconciled',unreconciled_by=?,unreconciled_at=UTC_TIMESTAMP(),unreconcile_reason_code=?,unreconcile_reason=?,adjustment_reversal_journal_entry_id=? WHERE id=? AND company_id=?")
        ->execute([$user['id'],operations_unreconcile_reason_code($reasonCode),$reason,$reversal,$group['id'],$companyId]);
    return $reversal!==null?(string)$reversal:null;
}

function operations_reconciliation_unmatch(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();
    $id=clean_text($input['matchGroupId']??'','Match group',64);$reason=clean_text($input['reason']??'','Unreconcile reason',1000);
    $reasonCode=operations_unreconcile_reason_code($input['reasonCode']??'other');
    $date=safe_date($input['unreconcileDate']??canadian_today(),'Unreconcile date');assert_not_future_date($date,'Unreconcile date');
    db()->beginTransaction();try{
        $q=db()->prepare("SELECT * FROM bank_match_groups WHERE id=? AND company_id=? FOR UPDATE");$q->execute([$id,$company['id']]);$g=$q->fetch();if(!$g)fail('Bank match not found.',404,'bank_match_not_found');
        if((string)$g['status']!=='matched'){db()->commit();json_response(['unreconciled'=>true,'alreadyUnreconciled'=>true,'adjustmentReversalJournalEntryId'=>$g['adjustment_reversal_journal_entry_id']]);}
        $q=db()->prepare("SELECT COUNT(*) FROM reconciliation_match_groups rmg JOIN reconciliations r ON r.id=rmg.reconciliation_id WHERE rmg.match_group_id=? AND r.status='complete'");$q->execute([$id]);
        if((int)$q->fetchColumn()>0)fail('This match is inside a completed reconciliation. Reopen that reconciliation first.',409,'reconciliation_reopen_required');
        $reversal=operations_unreconcile_group($user,(string)$company['id'],$g,$reason,$date,$reasonCode);
        audit_event($user,(string)$company['id'],'reconciliation.match_unreconciled','bank_match_group',$id,['reasonCode'=>$reasonCode,'reason'=>$reason,'unreconcileDate'=>$date,'adjustmentJournalEntryId'=>$g['adjustment_journal_entry_id'],'adjustmentReversalJournalEntryId'=>$reversal]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['unreconciled'=>true,'adjustmentReversalJournalEntryId'=>$reversal]);
}

function operations_reconciliation_report(array $user,array $company): never
{
    require_method('GET');require_company_permission($company,'banking.view');require_company_permission($company,'reports.view');$id=clean_text($_GET['reconciliationId']??'','Reconciliation',64);$companyId=(string)$company['id'];$pdo=db();
    if($pdo->inTransaction())throw new LogicException('Reconciliation reports require an independent read transaction.');
    try{
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
        $stmt=$pdo->prepare('SELECT r.*,ba.name AS bank_name,ba.masked_number,ba.currency,ba.ledger_account_id,u.display_name AS prepared_name,rv.display_name AS reviewed_name,ru.display_name AS reopened_name FROM reconciliations r JOIN bank_accounts ba ON ba.id=r.bank_account_id AND ba.company_id=r.company_id LEFT JOIN users u ON u.id=COALESCE(r.prepared_by,r.created_by) LEFT JOIN users rv ON rv.id=r.reviewed_by LEFT JOIN users ru ON ru.id=r.reopened_by WHERE r.id=? AND r.company_id=?');$stmt->execute([$id,$companyId]);$r=$stmt->fetch(PDO::FETCH_ASSOC);if(!$r)fail('Reconciliation not found.',404,'reconciliation_not_found');
        $start=(string)($r['period_start']??substr((string)$r['period_end'],0,8).'01');$end=(string)$r['period_end'];$bankId=(string)$r['bank_account_id'];$ledgerId=(string)$r['ledger_account_id'];

        $statementStmt=$pdo->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.reference,bt.amount_cents,bt.status,bt.journal_entry_id,CASE WHEN ri.bank_transaction_id IS NULL THEN 0 ELSE 1 END AS cleared,(SELECT g.id FROM bank_match_bank_items bbi JOIN bank_match_groups g ON g.id=bbi.match_group_id AND g.company_id=bt.company_id JOIN reconciliation_match_groups rmg ON rmg.match_group_id=g.id AND rmg.reconciliation_id=? WHERE bbi.bank_transaction_id=bt.id ORDER BY g.matched_at,g.id LIMIT 1) selected_match_group_id FROM bank_transactions bt LEFT JOIN reconciliation_items ri ON ri.bank_transaction_id=bt.id AND ri.reconciliation_id=? WHERE bt.company_id=? AND bt.bank_account_id=? AND bt.transaction_date BETWEEN ? AND ? AND bt.status NOT IN ('duplicate','excluded') ORDER BY bt.transaction_date,bt.created_at,bt.id");$statementStmt->execute([$id,$id,$companyId,$bankId,$start,$end]);$statementRows=$statementStmt->fetchAll(PDO::FETCH_ASSOC);

        $bookStmt=$pdo->prepare("SELECT je.id journal_entry_id,je.entry_date,je.memo,je.source_type,je.status journal_status,v.voucher_number,g.id match_group_id,g.status match_status,SUM(jl.debit_cents-jl.credit_cents) amount_cents FROM reconciliation_match_groups rmg JOIN bank_match_groups g ON g.id=rmg.match_group_id AND g.company_id=? JOIN bank_match_book_items bmi ON bmi.match_group_id=g.id JOIN journal_entries je ON je.id=bmi.journal_entry_id AND je.company_id=g.company_id JOIN journal_lines jl ON jl.journal_entry_id=je.id AND jl.account_id=? LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id WHERE rmg.reconciliation_id=? GROUP BY je.id,je.entry_date,je.memo,je.source_type,je.status,v.voucher_number,g.id,g.status ORDER BY je.entry_date,je.id");$bookStmt->execute([$companyId,$ledgerId,$id]);$matchedBooks=$bookStmt->fetchAll(PDO::FETCH_ASSOC);$booksByGroup=[];foreach($matchedBooks as $book){$booksByGroup[(string)$book['match_group_id']][]=$book;}

        $unmatchedBookStmt=$pdo->prepare("SELECT je.id journal_entry_id,je.entry_date,je.memo,je.source_type,v.voucher_number,SUM(jl.debit_cents-jl.credit_cents) amount_cents FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id AND jl.account_id=? LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.source_type=je.source_type AND v.source_id=je.source_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date BETWEEN ? AND ? AND je.source_type NOT IN ('bank_reconciliation_adjustment_reversal','opening_balance') AND NOT EXISTS(SELECT 1 FROM reconciliation_match_groups rmg JOIN bank_match_book_items bmi ON bmi.match_group_id=rmg.match_group_id WHERE rmg.reconciliation_id=? AND bmi.journal_entry_id=je.id) GROUP BY je.id,je.entry_date,je.memo,je.source_type,v.voucher_number ORDER BY je.entry_date,je.id");$unmatchedBookStmt->execute([$ledgerId,$companyId,$start,$end,$id]);$unmatchedBookRows=$unmatchedBookStmt->fetchAll(PDO::FETCH_ASSOC);

        $groupStmt=$pdo->prepare('SELECT g.id,g.status,g.bank_amount_cents,g.book_amount_cents,g.adjustment_journal_entry_id,g.adjustment_reversal_journal_entry_id,g.matched_at,g.unreconciled_at,g.unreconcile_reason_code,g.unreconcile_reason FROM reconciliation_match_groups rmg JOIN bank_match_groups g ON g.id=rmg.match_group_id AND g.company_id=? WHERE rmg.reconciliation_id=? ORDER BY g.matched_at,g.id');$groupStmt->execute([$companyId,$id]);$groups=$groupStmt->fetchAll(PDO::FETCH_ASSOC);
        $eventsStmt=$pdo->prepare('SELECT re.action,re.note,re.created_at,u.display_name actor FROM reconciliation_events re JOIN users u ON u.id=re.actor_user_id WHERE re.reconciliation_id=? AND re.company_id=? ORDER BY re.created_at,re.id');$eventsStmt->execute([$id,$companyId]);$eventRows=$eventsStmt->fetchAll(PDO::FETCH_ASSOC);
        $anchorStmt=$pdo->prepare("SELECT id,anchor_date,balance_cents,currency,provenance,source_checksum,revision,status FROM bank_statement_balance_anchors WHERE company_id=? AND bank_account_id=? AND anchor_date<=? ORDER BY anchor_date DESC,revision DESC LIMIT 1");$anchorStmt->execute([$companyId,$bankId,$end]);$anchor=$anchorStmt->fetch(PDO::FETCH_ASSOC)?:null;
        $receiptStmt=$pdo->prepare('SELECT id,coverage_start,coverage_end,parsed_count,selected_count,existing_count,invalid_count,omitted_count,source_closing_cents,difference_cents,completeness_state,created_at FROM bank_import_control_receipts WHERE company_id=? AND bank_account_id=? AND (coverage_end IS NULL OR coverage_end<=?) ORDER BY created_at DESC,id DESC LIMIT 1');$receiptStmt->execute([$companyId,$bankId,$end]);$receipt=$receiptStmt->fetch(PDO::FETCH_ASSOC)?:null;
        $currentBookCents=operations_bank_balance($companyId,$ledgerId,$end);

        $cleared=[];$unmatchedBank=[];$clearedDeposits=$clearedWithdrawals=0;
        foreach($statementRows as $row){$amount=(int)$row['amount_cents'];$groupId=(string)($row['selected_match_group_id']??'');$bookRefs=[];foreach($booksByGroup[$groupId]??[] as $book)$bookRefs[]=(string)($book['voucher_number']??$book['journal_entry_id']);$item=['id'=>(string)$row['id'],'date'=>(string)$row['transaction_date'],'reference'=>(string)($row['reference']??''),'description'=>(string)$row['description'],'amountCents'=>$amount,'status'=>(string)$row['status'],'matchGroupId'=>$groupId!==''?$groupId:null,'bookEntry'=>$bookRefs?implode(', ',array_values(array_unique($bookRefs))):null,'clearingState'=>(bool)$row['cleared']?'Cleared':'Outstanding'];if((bool)$row['cleared']){$cleared[]=$item;if($amount>=0)$clearedDeposits+=$amount;else$clearedWithdrawals+=abs($amount);}if($groupId===''){$item['exceptionReason']='No match group belongs to this reconciliation snapshot.';$unmatchedBank[]=$item;}}

        $unmatchedBooks=[];$depositsInTransit=[];$outstandingPayments=[];$depositCents=$outstandingPaymentCents=0;
        foreach($unmatchedBookRows as $row){$amount=(int)$row['amount_cents'];$item=['journalEntryId'=>(string)$row['journal_entry_id'],'date'=>(string)$row['entry_date'],'reference'=>(string)($row['voucher_number']??''),'description'=>(string)$row['memo'],'amountCents'=>$amount,'sourceType'=>(string)$row['source_type'],'matchGroupId'=>null,'clearingState'=>'Unmatched','exceptionReason'=>'Posted book movement is not linked to this reconciliation snapshot.'];$unmatchedBooks[]=$item;if($amount>0){$depositsInTransit[]=$item;$depositCents+=$amount;}elseif($amount<0){$outstandingPayments[]=$item;$outstandingPaymentCents+=abs($amount);}}

        $exceptions=[];foreach($groups as $group){if((string)$group['status']!=='matched')$exceptions[]=['code'=>'snapshot_match_changed','severity'=>'high','reference'=>(string)$group['id'],'message'=>'A match group included in this snapshot is now unreconciled.'.(!empty($group['unreconcile_reason'])?' Reason: '.(string)$group['unreconcile_reason']:'')];if((int)$group['bank_amount_cents']!==(int)$group['book_amount_cents'])$exceptions[]=['code'=>'match_control_difference','severity'=>'high','reference'=>(string)$group['id'],'message'=>'A saved match group no longer has equal bank and book totals.'];}
        if($anchor&&$anchor['status']==='disputed')$exceptions[]=['code'=>'statement_evidence_disputed','severity'=>'high','reference'=>(string)$anchor['id'],'message'=>'The statement balance evidence for this period is disputed.'];
        if((string)$r['status']==='complete'&&$currentBookCents!==(int)$r['book_balance_cents'])$exceptions[]=['code'=>'book_evidence_changed','severity'=>'high','reference'=>$id,'message'=>'Posted book activity through this period end differs from the stored completed snapshot. The historical balance has not been silently restated.'];
        if((string)$r['status']==='complete'&&(int)$r['difference_cents']!==0)$exceptions[]=['code'=>'completed_snapshot_difference','severity'=>'high','reference'=>$id,'message'=>'The completed snapshot contains a non-zero stored difference.'];
        foreach($statementRows as $row)if((bool)$row['cleared']&&empty($row['selected_match_group_id']))$exceptions[]=['code'=>'cleared_item_missing_match','severity'=>'high','reference'=>(string)$row['id'],'message'=>'A cleared statement item no longer has its saved match-group evidence.'];

        // R122: the bridge uses the calculated position: bank balance from the opening balance plus imported transactions, reconciling items, book balance.
        $bankRow=['id'=>$bankId,'name'=>(string)$r['bank_name'],'currency'=>(string)$r['currency'],'ledger_account_id'=>$ledgerId];$position=tegh_recon_position_r122($companyId,$bankRow,$start,$end);$groupsR122=$position['reconcilingItems'];
        $depositsInTransit=[];$outstandingPayments=[];$depositCents=0;$outstandingPaymentCents=0;foreach(array_merge($groupsR122['bookOnly']['items'],$groupsR122['bookTiming']['items']) as $item){if((int)$item['amountCents']>0){$depositsInTransit[]=$item;$depositCents+=(int)$item['amountCents'];}else{$outstandingPayments[]=$item;$outstandingPaymentCents+=abs((int)$item['amountCents']);}}
        $outstandingIds=[];foreach(array_merge($groupsR122['unposted']['items'],$groupsR122['bankTiming']['items']) as $item)$outstandingIds[(string)$item['id']]=true;
        $cleared=[];$clearedDeposits=0;$clearedWithdrawals=0;foreach($position['transactions'] as $tx){if(isset($outstandingIds[(string)$tx['id']]))continue;$amount=(int)$tx['amountCents'];$cleared[]=['id'=>(string)$tx['id'],'date'=>(string)$tx['date'],'reference'=>(string)$tx['reference'],'description'=>(string)$tx['description'],'amountCents'=>$amount,'status'=>(string)$tx['status'],'matchGroupId'=>null,'bookEntry'=>null,'clearingState'=>'Cleared'];if($amount>=0)$clearedDeposits+=$amount;else $clearedWithdrawals+=abs($amount);}
        $unmatchedBank=array_map(static fn(array $item):array=>$item+['clearingState'=>'Outstanding','matchGroupId'=>null,'bookEntry'=>null,'status'=>'pending'],array_merge($groupsR122['unposted']['items'],$groupsR122['bankTiming']['items']));
        $unmatchedBooks=array_map(static fn(array $item):array=>$item+['clearingState'=>'Outstanding','matchGroupId'=>null],array_merge($groupsR122['bookOnly']['items'],$groupsR122['bookTiming']['items']));
        $statementEnding=(string)$r['status']==='complete'?(int)$r['statement_balance_cents']:(int)$position['bank']['closingCents'];$bookBalance=(string)$r['status']==='complete'?(int)$r['book_balance_cents']:(int)$position['book']['closingCents'];$bankAdjustments=-((int)$groupsR122['unposted']['totalCents']+(int)$groupsR122['bankTiming']['totalCents']);$bookAdjustments=0;$adjustedBank=$statementEnding+$depositCents-$outstandingPaymentCents+$bankAdjustments;$adjustedBook=$bookBalance+$bookAdjustments;$bridgeDifference=$adjustedBank-$adjustedBook;
        if($bridgeDifference!==(int)$r['difference_cents'])$exceptions[]=['code'=>'bridge_differs_from_stored_snapshot','severity'=>'review','reference'=>$id,'message'=>'The expanded outstanding-item bridge differs from the stored completion difference. The stored snapshot remains unchanged.'];

        $auditEvents=array_map(static fn(array $row):array=>['action'=>(string)$row['action'],'note'=>(string)$row['note'],'createdAt'=>(string)$row['created_at'],'actor'=>(string)$row['actor']],$eventRows);
        $controlTotals=['statementItems'=>count($statementRows),'clearedItems'=>count($cleared),'unmatchedBankItems'=>count($unmatchedBank),'matchedBookEntries'=>count($matchedBooks),'unmatchedBookEntries'=>count($unmatchedBooks),'matchGroups'=>count($groups),'clearedDepositsCents'=>$clearedDeposits,'clearedWithdrawalsCents'=>$clearedWithdrawals,'depositsInTransitCents'=>$depositCents,'outstandingPaymentsCents'=>$outstandingPaymentCents];
        $balances=['bankOpeningCents'=>(int)$position['bank']['openingCents'],'bankDepositsCents'=>(int)$position['bank']['depositsCents'],'bankWithdrawalsCents'=>(int)$position['bank']['withdrawalsCents'],'unpostedBankCents'=>(int)$groupsR122['unposted']['totalCents'],'bankTimingCents'=>(int)$groupsR122['bankTiming']['totalCents'],'statementEndingCents'=>$statementEnding,'depositsInTransitCents'=>$depositCents,'outstandingPaymentsCents'=>$outstandingPaymentCents,'bankAdjustmentsCents'=>$bankAdjustments,'adjustedBankCents'=>$adjustedBank,'bookBalanceCents'=>$bookBalance,'bookAdjustmentsCents'=>$bookAdjustments,'adjustedBookCents'=>$adjustedBook,'differenceCents'=>$bridgeDifference,'storedDifferenceCents'=>(int)$r['difference_cents'],'currentBookEvidenceCents'=>$currentBookCents];
        $statusKey=(string)$r['status']==='complete'?'complete':($r['reopened_at']!==null?'reopened':'draft');$statusLabel=$statusKey==='complete'?'Reconciled':ucfirst($statusKey);$generatedAt=gmdate('Y-m-d\TH:i:s\Z');
        $sourceMaterial=json_encode(['reconciliationId'=>$id,'status'=>$statusKey,'balances'=>$balances,'controlTotals'=>$controlTotals,'groups'=>$groups,'anchor'=>$anchor,'receipt'=>$receipt,'events'=>$auditEvents],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$sourceHash=hash('sha256',$sourceMaterial);$totalsDigest=hash('sha256',json_encode(['balances'=>$balances,'controlTotals'=>$controlTotals],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$reference='BRR-'.strtoupper(substr(secret_hash($id.'|'.$sourceHash),0,24));
        $report=['id'=>$id,'snapshot'=>['reference'=>$reference,'generatedAt'=>$generatedAt,'sourceRevisionHash'=>$sourceHash,'totalsDigest'=>$totalsDigest,'immutable'=>$statusKey==='complete','liveDraft'=>$statusKey!=='complete'],'account'=>['id'=>$bankId,'name'=>(string)$r['bank_name'],'maskedNumber'=>(string)($r['masked_number']??''),'currency'=>(string)$r['currency']],'period'=>['start'=>$start,'end'=>$end],'status'=>['key'=>$statusKey,'label'=>$statusLabel],'preparedBy'=>$r['prepared_name'],'reviewedBy'=>$r['reviewed_name'],'completedAt'=>$r['completed_at'],'reopenedBy'=>$r['reopened_name'],'reopenedAt'=>$r['reopened_at'],'reopenReason'=>$r['reopen_reason'],'notes'=>(string)($r['notes']??''),'balances'=>$balances,'sections'=>['clearedMatched'=>$cleared,'depositsInTransit'=>$depositsInTransit,'outstandingPayments'=>$outstandingPayments,'unmatchedBank'=>$unmatchedBank,'unmatchedBooks'=>$unmatchedBooks,'bookAdjustments'=>[],'bankSideErrors'=>array_values(array_filter($exceptions,static fn(array $x):bool=>in_array($x['code'],['statement_evidence_disputed','snapshot_match_changed','cleared_item_missing_match'],true)))],'exceptions'=>$exceptions,'auditEvents'=>$auditEvents,'controlTotals'=>$controlTotals,'evidence'=>['statementAnchor'=>$anchor,'importControlReceipt'=>$receipt],'disclosure'=>'Read-only management report — unaudited. This report does not post, match, clear, reopen or alter accounting records.','accountingWrites'=>0,
            'reconcilingItems'=>$groupsR122,'bankTransactions'=>$position['transactions'],'bankName'=>(string)$r['bank_name'],'currency'=>(string)$r['currency'],'periodStart'=>$start,'periodEnd'=>$end,'statementBalanceCents'=>$statementEnding,'bookBalanceCents'=>$bookBalance,'differenceCents'=>$bridgeDifference,'transactions'=>$cleared,'events'=>$auditEvents];
        $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $fresh=require_company($user);if((string)$fresh['id']!==$companyId)fail('The company changed while generating the reconciliation report.',409,'report_scope_changed',false);require_company_permission($fresh,'banking.view');require_company_permission($fresh,'reports.view');
    json_response(['contractVersion'=>'2.0','report'=>$report]);
}

/** @return array{attempted:int,accepted:int,failed:int} */
function operations_notify_locked_reconciliation_reopen(array $user,array $company,array $reconciliation,string $reason): array
{
    $stmt=db()->prepare("SELECT u.id,u.email,u.display_name FROM company_members cm JOIN users u ON u.id=cm.user_id AND u.active=1 WHERE cm.company_id=? AND cm.status='active' ORDER BY u.id");
    $stmt->execute([(string)$company['id']]);$members=$stmt->fetchAll();$accepted=0;$failed=0;
    $subject='Locked-period reconciliation reopened · '.(string)$company['name'];
    foreach($members as $member){
        if(function_exists('platform_notification_create'))platform_notification_create((string)$company['id'],(string)$member['id'],'reconciliation_reopened','Locked-period reconciliation reopened','A completed reconciliation through '.(string)$reconciliation['period_end'].' was reopened. Reason: '.$reason,'bank-reconciliation','critical','reconciliation-reopened-'.(string)$reconciliation['id'].'-'.(string)$member['id'],'Review reconciliation');
        try{
            $delivery=sr_mail_send((string)$company['id'],(string)$user['id'],(string)$member['email'],'locked_reconciliation_reopened',$subject,
                "A completed reconciliation for ".(string)$company['name']." was reopened.\n\nPeriod end: ".(string)$reconciliation['period_end']."\nReason: ".$reason."\n\nReview Bank Reconciliation and the audit history promptly.");
            if(!empty($delivery['sent'])||in_array((string)($delivery['outcome']??''),['accepted','sent'],true))$accepted++;else$failed++;
        }catch(Throwable $error){$failed++;error_log('Locked reconciliation reopen email failed class='.$error::class);}
    }
    return ['attempted'=>count($members),'accepted'=>$accepted,'failed'=>$failed];
}

function operations_reconciliation_reopen(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();
    $id=clean_text($input['reconciliationId']??'','Reconciliation',64);$reason=clean_text($input['reason']??'','Reopening reason',1000);
    $date=safe_date($input['reopenDate']??canadian_today(),'Reopen date');assert_not_future_date($date,'Reopen date');
    db()->beginTransaction();try{
        $stmt=db()->prepare("SELECT * FROM reconciliations WHERE id=? AND company_id=? FOR UPDATE");$stmt->execute([$id,$company['id']]);$r=$stmt->fetch();
        if(!$r)fail('Reconciliation not found.',404,'reconciliation_not_found');if($r['status']!=='complete')fail('Only a completed reconciliation can be reopened.',409,'reconciliation_not_complete');
        // Reopen newest-first so later statement balances cannot remain certified
        // against a period whose clearing state has been changed underneath them.
        $latest=db()->prepare("SELECT id FROM reconciliations WHERE company_id=? AND bank_account_id=? AND status='complete' ORDER BY period_end DESC,completed_at DESC,id DESC LIMIT 1");
        $latest->execute([$company['id'],$r['bank_account_id']]);if((string)$latest->fetchColumn()!==$id)fail('Reopen reconciliations newest first for this financial account.',409,'reconciliation_reopen_order');
        $lock=db()->prepare("SELECT COUNT(*) FROM period_locks WHERE company_id=? AND locked=1 AND period_start<=? AND period_end>=?");
        $lock->execute([$company['id'],$r['period_end'],$r['period_start']??$r['period_end']]);$locked=(int)$lock->fetchColumn()>0;
        if($locked&&empty($input['confirmLockedPeriod']))fail('This reconciliation overlaps a locked accounting period. Company Owner confirmation is required before reopening it.',409,'locked_period_confirmation_required');

        db()->prepare("UPDATE reconciliations SET status='draft',completed_at=NULL,reopened_by=?,reopened_at=UTC_TIMESTAMP(),reopen_reason=? WHERE id=?")
            ->execute([$user['id'],$reason,$id]);
        $groups=db()->prepare("SELECT g.* FROM bank_match_groups g JOIN reconciliation_match_groups rmg ON rmg.match_group_id=g.id WHERE rmg.reconciliation_id=? AND g.status='matched' ORDER BY g.matched_at,g.id FOR UPDATE");
        $groups->execute([$id]);$matchRows=$groups->fetchAll();$adjustmentReversals=[];
        foreach($matchRows as $g){$rev=operations_unreconcile_group($user,(string)$company['id'],$g,$reason,$date,'reconciliation_reopened');if($rev!==null)$adjustmentReversals[]=$rev;}

        // Restore the financial-account reconciliation marker to the most recent
        // still-completed statement, rather than leaving the reopened balance visible.
        $prior=db()->prepare("SELECT period_end,statement_balance_cents FROM reconciliations WHERE company_id=? AND bank_account_id=? AND status='complete' AND id<>? ORDER BY period_end DESC,completed_at DESC,id DESC LIMIT 1");
        $prior->execute([$company['id'],$r['bank_account_id'],$id]);$previous=$prior->fetch();
        db()->prepare('UPDATE bank_accounts SET statement_balance_cents=?,last_reconciled_date=? WHERE id=? AND company_id=?')
            ->execute([$previous?(int)$previous['statement_balance_cents']:0,$previous?(string)$previous['period_end']:null,$r['bank_account_id'],$company['id']]);
        db()->prepare("INSERT INTO reconciliation_events (id,reconciliation_id,company_id,action,note,actor_user_id) VALUES (?,?,?,'reopened',?,?)")
            ->execute([new_id('revent'),$id,$company['id'],$reason,$user['id']]);
        audit_event($user,(string)$company['id'],'reconciliation.reopened','reconciliation',$id,['reason'=>$reason,'reopenDate'=>$date,'lockedPeriod'=>$locked,'unreconciledMatchCount'=>count($matchRows), 'adjustmentReversalCount'=>count($adjustmentReversals),'adjustmentReversalJournalEntryIds'=>$adjustmentReversals]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    $notifications=$locked?operations_notify_locked_reconciliation_reopen($user,$company,$r,$reason):['attempted'=>0,'accepted'=>0,'failed'=>0];
    json_response(['reopened'=>true,'adjustmentReversalJournalEntryIds'=>$adjustmentReversals,'memberNotifications'=>$notifications]);
}

function operations_rates(array $user,array $company): never
{
    if(request_method()==='GET'){operations_workspace($user,$company);}require_method('POST','PATCH');require_csrf();require_company_role($company,'owner');$input=request_json();
    if(request_method()==='PATCH'){$id=clean_text($input['rateId']??'','Rate',64);$status=(string)($input['status']??'superseded');if(!in_array($status,['draft','active','superseded'],true))fail('Rate status is invalid.');db()->prepare('UPDATE statutory_rates SET status=?,approved_by=? WHERE id=? AND company_id=?')->execute([$status,$user['id'],$id,$company['id']]);json_response(['updated'=>true]);}
    $rows=$input['rows']??[$input];if(!is_array($rows)||count($rows)<1||count($rows)>1000)fail('Import between 1 and 1,000 rate rows.');$created=0;
    db()->beginTransaction();try{$insert=db()->prepare('INSERT INTO statutory_rates (id,company_id,jurisdiction,person_type,rate_key,label,effective_from,effective_to,value_decimal,value_cents,threshold_min_cents,threshold_max_cents,metadata_json,source_url,source_label,status,created_by,approved_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');foreach($rows as $row){if(!is_array($row))fail('A rate row is invalid.');$jur=strtoupper(clean_text($row['jurisdiction']??'CA','Jurisdiction',20));$person=clean_text($row['personType']??'employee','Person type',40);$key=clean_text($row['rateKey']??'','Rate key',120);$label=clean_text($row['label']??$key,'Rate label',200);$from=safe_date($row['effectiveFrom']??'','Effective-from date');$to=trim((string)($row['effectiveTo']??''));$to=$to===''?null:safe_date($to,'Effective-to date');if($to!==null&&$to<$from)fail('Effective-to date cannot precede the effective-from date.');$decimal=isset($row['valueDecimal'])&&$row['valueDecimal']!==''?(float)$row['valueDecimal']:null;$cents=isset($row['valueCents'])&&$row['valueCents']!==''?safe_cents($row['valueCents'],'Rate amount',true):null;if($decimal===null&&$cents===null)fail('Each rate needs a decimal value or an amount in cents.');$meta=is_array($row['metadata']??null)?$row['metadata']:[];$status=(string)($row['status']??'active');if(!in_array($status,['draft','active','superseded'],true))fail('Rate status is invalid.');$insert->execute([new_id('rate'),$company['id'],$jur,$person,$key,$label,$from,$to,$decimal,$cents,$row['thresholdMinCents']??null,$row['thresholdMaxCents']??null,json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),optional_text($row['sourceUrl']??null,1000),optional_text($row['sourceLabel']??null,200),$status,$user['id'],$status==='active'?$user['id']:null]);$created++;}audit_event($user,(string)$company['id'],'statutory_rates.imported','statutory_rate',new_id('ratebatch'),['created'=>$created]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if($e instanceof PDOException&&(string)$e->getCode()==='23000')fail('A rate with the same key and effective date already exists.',409,'rate_duplicate');throw $e;}json_response(['created'=>$created],201);
}

function operations_levy_profile(array $user,array $company): never
{
    require_method('PUT');require_csrf();require_company_role($company,'owner');$input=request_json();$jur=strtoupper(clean_text($input['jurisdiction']??$company['province'],'Jurisdiction',2));$app=(string)($input['applicability']??'not_applicable');if(!in_array($app,['jurisdiction_default','custom','not_applicable'],true))fail('Levy applicability is invalid.');$post=(string)($input['postingMode']??'report_only');if(!in_array($post,['automatic','draft','report_only','none'],true))fail('Levy posting mode is invalid.');$eligible=!empty($input['eligibleForExemption']);$associated=safe_cents($input['associatedGroupPayrollCents']??0,'Associated-group payroll',true);$expense=trim((string)($input['expenseAccountId']??''))?:null;$payable=trim((string)($input['payableAccountId']??''))?:null;if($expense!==null&&!company_account((string)$company['id'],$expense))fail('Expense GL code is invalid.');if($payable!==null&&!company_account((string)$company['id'],$payable))fail('Payable GL code is invalid.');db()->prepare('INSERT INTO employer_levy_profiles (company_id,jurisdiction,applicability,posting_mode,eligible_for_exemption,associated_group_payroll_cents,expense_account_id,payable_account_id,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE jurisdiction=VALUES(jurisdiction),applicability=VALUES(applicability),posting_mode=VALUES(posting_mode),eligible_for_exemption=VALUES(eligible_for_exemption),associated_group_payroll_cents=VALUES(associated_group_payroll_cents),expense_account_id=VALUES(expense_account_id),payable_account_id=VALUES(payable_account_id),updated_by=VALUES(updated_by)')->execute([$company['id'],$jur,$app,$post,$eligible?1:0,$associated,$expense,$payable,$user['id']]);audit_event($user,(string)$company['id'],'employer_levy.settings_updated','employer_levy_profile',(string)$company['id'],['jurisdiction'=>$jur,'applicability'=>$app,'postingMode'=>$post]);json_response(['updated'=>true]);
}

function operations_levy_rates(array $user,array $company): never
{
    require_method('POST');require_csrf();require_company_role($company,'owner');$input=request_json();$rows=$input['rows']??[$input];if(!is_array($rows)||count($rows)<1||count($rows)>200)fail('Provide between 1 and 200 levy-rate rows.');$created=0;db()->beginTransaction();try{$ins=db()->prepare('INSERT INTO employer_levy_rates (id,company_id,jurisdiction,label,effective_from,effective_to,payroll_min_cents,payroll_max_cents,exemption_cents,exemption_threshold_cents,rate_bps,rate_decimal,source_url,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');foreach($rows as $row){$jur=strtoupper(clean_text($row['jurisdiction']??$company['province'],'Jurisdiction',2));$label=clean_text($row['label']??'Employer payroll tax','Label',200);$from=safe_date($row['effectiveFrom']??'','Effective-from date');$to=trim((string)($row['effectiveTo']??''));$to=$to===''?null:safe_date($to,'Effective-to date');$min=safe_cents($row['payrollMinCents']??0,'Minimum payroll',true);$max=isset($row['payrollMaxCents'])&&$row['payrollMaxCents']!==''?safe_cents($row['payrollMaxCents'],'Maximum payroll',true):null;$ex=safe_cents($row['exemptionCents']??0,'Exemption',true);$threshold=isset($row['exemptionThresholdCents'])&&$row['exemptionThresholdCents']!==''?safe_cents($row['exemptionThresholdCents'],'Exemption threshold',true):null;$decimal=isset($row['rateDecimal'])&&$row['rateDecimal']!==''?(float)$row['rateDecimal']:((float)($row['ratePercent']??0)/100);if($decimal<0||$decimal>1)fail('The employer payroll tax rate must be between 0% and 100%.');$bps=(int)round($decimal*10000);$ins->execute([new_id('levyrate'),$company['id'],$jur,$label,$from,$to,$min,$max,$ex,$threshold,$bps,$decimal,optional_text($row['sourceUrl']??null,1000),1,$user['id']]);$created++;}db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}json_response(['created'=>$created],201);
}

function operations_levy_calculate(array $company): never
{
    require_company_permission($company,'payroll.view');
    require_method('POST');$input=request_json();$date=safe_date($input['effectiveDate']??canadian_today(),'Effective date');$payroll=safe_cents($input['annualPayrollCents']??0,'Annual payroll',true);$companyId=(string)$company['id'];$stmt=db()->prepare('SELECT * FROM employer_levy_profiles WHERE company_id=?');$stmt->execute([$companyId]);$profile=$stmt->fetch();if(!$profile||in_array((string)$profile['applicability'],['not_applicable'],true))json_response(['calculation'=>['applicable'=>false,'annualPayrollCents'=>$payroll,'taxCents'=>0]]);$jur=(string)$profile['jurisdiction'];$stmt=db()->prepare('SELECT * FROM employer_levy_rates WHERE company_id=? AND jurisdiction=? AND active=1 AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?) AND payroll_min_cents<=? AND (payroll_max_cents IS NULL OR payroll_max_cents>=?) ORDER BY effective_from DESC,payroll_min_cents DESC LIMIT 1');$stmt->execute([$companyId,$jur,$date,$date,$payroll,$payroll]);$rate=$stmt->fetch();if(!$rate)fail('No employer payroll tax rate is configured for this payroll amount and date.',409,'levy_rate_missing');$exemption=(bool)$profile['eligible_for_exemption']?(int)$rate['exemption_cents']:0;$threshold=$rate['exemption_threshold_cents']===null?null:(int)$rate['exemption_threshold_cents'];$associated=(int)$profile['associated_group_payroll_cents'];if($threshold!==null&&max($payroll,$associated)>$threshold)$exemption=0;$taxable=max(0,$payroll-$exemption);$rateDecimal=$rate['rate_decimal']===null?((int)$rate['rate_bps']/10000):(float)$rate['rate_decimal'];$tax=(int)round($taxable*$rateDecimal);json_response(['calculation'=>['applicable'=>true,'jurisdiction'=>$jur,'annualPayrollCents'=>$payroll,'exemptionCents'=>$exemption,'taxablePayrollCents'=>$taxable,'rateBps'=>(int)$rate['rate_bps'],'rateDecimal'=>$rateDecimal,'taxCents'=>$tax,'effectiveDate'=>$date,'postingMode'=>(string)$profile['posting_mode']]]);
}

function operations_account_mappings(array $user,array $company): never
{
    require_method('POST');
    require_csrf();
    require_company_role($company,'owner','bookkeeper');
    $input=request_json();
    $rows=$input['rows']??[$input];
    if(!is_array($rows)||count($rows)<1||count($rows)>1000) fail('Select between 1 and 1,000 accounts.');
    $updated=0;
    db()->beginTransaction();
    try{
        foreach($rows as $row){
            if(!is_array($row)) fail('Each mapping row must be an object.');
            $id=clean_text($row['accountId']??'','Account',64);
            $sets=[];$params=[];$changedFields=[];
            if(array_key_exists('gifiCode',$row)){
                $gifi=optional_text($row['gifiCode'],10);
                if($gifi!==null&&!preg_match('/^\d{4}$/',$gifi)) fail('GIFI code must contain exactly four digits.');
                $sets[]='gifi_code=?';$params[]=$gifi;$changedFields[]='gifiCode';
            }
            if(array_key_exists('t2125Line',$row)){
                $sets[]='t2125_line=?';$params[]=optional_text($row['t2125Line'],30);$changedFields[]='t2125Line';
            }
            if(array_key_exists('reportingGroup',$row)){
                $sets[]='reporting_group=?';$params[]=optional_text($row['reportingGroup'],120);$changedFields[]='reportingGroup';
            }
            if(array_key_exists('expenseCategory',$row)){
                $sets[]='expense_category=?';$params[]=optional_text($row['expenseCategory'],120);$changedFields[]='expenseCategory';
            }
            if($sets===[]) continue;
            $params[]=$id;$params[]=$company['id'];
            $stmt=db()->prepare('UPDATE accounts SET '.implode(',',$sets).' WHERE id=? AND company_id=?');
            $stmt->execute($params);$updated+=$stmt->rowCount();
        }
        audit_event($user,(string)$company['id'],'accounts.tax_mappings_updated','account',new_id('mappingbatch'),['updated'=>$updated]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['updated'=>$updated]);
}

function operations_tax_report(array $company): never
{
    require_method('GET');
    $profile = (string)($_GET['profile'] ?? $company['tax_reporting_profile'] ?? 'none');
    if (!in_array($profile, ['gifi','t2125'], true)) fail('Choose a corporate or sole-proprietor reporting profile.');
    $companyId = (string)$company['id'];
    $start = safe_date($_GET['start'] ?? fiscal_period_start(canadian_today(), (string)$company['fiscal_year_end']), 'Start date');
    $end = safe_date($_GET['end'] ?? canadian_today(), 'End date');
    if ($start > $end) fail('Start date must not be after end date.');

    $field = $profile === 'gifi' ? 'gifi_code' : 't2125_line';
    $stmt = db()->prepare("SELECT a.id,a.code,a.name,a.account_type,a.$field AS tax_code,a.reporting_group,
        COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date BETWEEN ? AND ? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) AS period_signed_cents,
        COALESCE(SUM(CASE WHEN je.status='posted' AND je.entry_date<=? THEN jl.debit_cents-jl.credit_cents ELSE 0 END),0) AS asof_signed_cents
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id=a.id
        LEFT JOIN journal_entries je ON je.id=jl.journal_entry_id
        WHERE a.company_id=?
        GROUP BY a.id,a.code,a.name,a.account_type,a.$field,a.reporting_group
        ORDER BY a.code");
    $stmt->execute([$start, $end, $end, $companyId]);

    $groups = [];
    $unmapped = [];
    $balanceCurrentEarningsCents = 0;
    foreach ($stmt->fetchAll() as $r) {
        $type = (string)$r['account_type'];
        $periodSigned = (int)$r['period_signed_cents'];
        $asOfSigned = (int)$r['asof_signed_cents'];
        // GIFI Schedule 100 is an as-of-date balance sheet. Schedule 125 is a
        // period income statement. T2125 remains a period-only report.
        $signed = $profile === 'gifi' && in_array($type, ['asset','liability','equity'], true)
            ? $asOfSigned
            : $periodSigned;
        $amount = in_array($type, ['liability','equity','income'], true) ? -$signed : $signed;
        $row = [
            'accountId'=>(string)$r['id'], 'glCode'=>(string)$r['code'], 'accountName'=>(string)$r['name'],
            'accountType'=>$type, 'taxCode'=>$r['tax_code'], 'reportingGroup'=>$r['reporting_group'], 'amountCents'=>$amount,
        ];
        if ($r['tax_code'] === null || $r['tax_code'] === '') {
            $unmapped[] = $row;
        } else {
            $key = (string)$r['tax_code'];
            if (!isset($groups[$key])) $groups[$key] = ['taxCode'=>$key,'amountCents'=>0,'accounts'=>[]];
            $groups[$key]['amountCents'] += $amount;
            $groups[$key]['accounts'][] = $row;
        }

        // Mirror Tegh's Balance Sheet logic: unclosed income and expense
        // accounts form current earnings so Schedule 100 remains internally
        // consistent even before a formal year-end closing journal is posted.
        if ($profile === 'gifi') {
            if ($type === 'income') $balanceCurrentEarningsCents += -$asOfSigned;
            elseif ($type === 'expense') $balanceCurrentEarningsCents -= $asOfSigned;
        }
    }

    if ($profile === 'gifi' && $balanceCurrentEarningsCents !== 0) {
        $key = '3600';
        if (!isset($groups[$key])) $groups[$key] = ['taxCode'=>$key,'amountCents'=>0,'accounts'=>[]];
        $groups[$key]['amountCents'] += $balanceCurrentEarningsCents;
        $groups[$key]['accounts'][] = [
            'accountId'=>'', 'glCode'=>'', 'accountName'=>'Current earnings through '.$end,
            'accountType'=>'equity', 'taxCode'=>$key, 'reportingGroup'=>'calculated',
            'amountCents'=>$balanceCurrentEarningsCents, 'calculated'=>true,
        ];
    }

    json_response(['report'=>[
        'profile'=>$profile, 'periodStart'=>$start, 'periodEnd'=>$end,
        'groups'=>array_values($groups), 'unmappedAccounts'=>$unmapped,
        'balanceCurrentEarningsCents'=>$profile === 'gifi' ? $balanceCurrentEarningsCents : 0,
        'sourceUrl'=>$profile === 'gifi' ? SR_OFFICIAL_GIFI_URL : SR_OFFICIAL_T2125_URL,
    ]]);
}

function operations_company_settings(array $user,array $company): never
{
    require_method('PUT');require_csrf();require_company_role($company,'owner');$input=request_json();$module=(string)($input['moduleMode']??$company['module_mode']??'both');if(!in_array($module,['accounting','payroll','both'],true))fail('Module selection is invalid.');$posting=(string)($input['payrollPostingMode']??$company['payroll_posting_mode']??'draft');if(!in_array($posting,['automatic','draft','none'],true))fail('Payroll posting selection is invalid.');$profile=(string)($input['taxReportingProfile']??$company['tax_reporting_profile']??'none');if(!in_array($profile,['gifi','t2125','none'],true))fail('Tax reporting profile is invalid.');db()->prepare('UPDATE companies SET module_mode=?,payroll_posting_mode=?,tax_reporting_profile=? WHERE id=?')->execute([$module,$posting,$profile,$company['id']]);audit_event($user,(string)$company['id'],'company.modules_updated','company',(string)$company['id'],['moduleMode'=>$module,'payrollPostingMode'=>$posting,'taxReportingProfile'=>$profile]);json_response(['updated'=>true]);
}

function operations_statement_controls(array $company): never
{
    require_method('GET');tegh_schema45_require();$companyId=(string)$company['id'];$bankId=clean_text($_GET['bankAccountId']??'','Bank / Credit account',64);
    $stmt=db()->prepare('SELECT ba.id,ba.name,ba.account_type,ba.masked_number,ba.currency,ba.ledger_account_id,a.code ledger_code,a.name ledger_name FROM bank_accounts ba JOIN accounts a ON a.id=ba.ledger_account_id AND a.company_id=ba.company_id WHERE ba.id=? AND ba.company_id=? AND ba.active=1');$stmt->execute([$bankId,$companyId]);$bank=$stmt->fetch();if(!$bank)fail('Choose an authorized active Bank / Credit account.',404,'statement_control_account_not_found');
    $from=trim((string)($_GET['start']??''));$to=trim((string)($_GET['end']??''));if($from!=='')$from=safe_date($from,'Statement control start');if($to!=='')$to=safe_date($to,'Statement control end');if($from!==''&&$to!==''&&$from>$to)fail('Statement control start must be on or before end.');
    $where=['company_id=?','bank_account_id=?'];$params=[$companyId,$bankId];if($from!==''){$where[]='transaction_date>=?';$params[]=$from;}if($to!==''){$where[]='transaction_date<=?';$params[]=$to;}
    $rowsStmt=db()->prepare('SELECT id,transaction_date,amount_cents,foreign_amount_cents,source_sequence,source_row_number,source_running_balance_cents,status FROM bank_transactions WHERE '.implode(' AND ',$where).' ORDER BY transaction_date,COALESCE(source_sequence,9223372036854775807),created_at,id');$rowsStmt->execute($params);$rows=$rowsStmt->fetchAll();
    $coverageStart=$rows!==[]?(string)$rows[0]['transaction_date']:($from!==''?$from:null);$coverageEnd=$rows!==[]?(string)$rows[count($rows)-1]['transaction_date']:($to!==''?$to:null);$anchorCutoff=$coverageStart??$to??'9999-12-31';
    $anchorStmt=db()->prepare("SELECT id,anchor_date,balance_cents,currency,provenance,revision,status,evidence_json FROM bank_statement_balance_anchors WHERE company_id=? AND bank_account_id=? AND anchor_date<=? AND status<>'disputed' ORDER BY anchor_date DESC,revision DESC LIMIT 1");$anchorStmt->execute([$companyId,$bankId,$anchorCutoff]);$anchor=$anchorStmt->fetch();
    $opening=$anchor?(int)$anchor['balance_cents']:null;$running=$opening;$moneyIn=0;$moneyOut=0;$mapped=[];$orderVerified=true;
    foreach($rows as $row){$amount=(int)($row['foreign_amount_cents']??$row['amount_cents']);if($amount>0)$moneyIn+=$amount;else$moneyOut+=abs($amount);$source=$row['source_running_balance_cents']===null?null:(int)$row['source_running_balance_cents'];if($running!==null)$running+=$amount;$shown=$source??$running;if($source===null&&$running===null)$orderVerified=false;$mapped[]=['transactionId'=>(string)$row['id'],'date'=>(string)$row['transaction_date'],'amountCents'=>$amount,'runningBalanceCents'=>$shown,'runningBalanceSource'=>$source!==null?'source':($shown!==null?'calculated':'unavailable'),'sourceSequence'=>$row['source_sequence']===null?null:(int)$row['source_sequence'],'sourceRow'=>$row['source_row_number']===null?null:(int)$row['source_row_number'],'status'=>(string)$row['status']];}
    $calculated=$opening===null?null:$opening+$moneyIn-$moneyOut;
    $closingStmt=db()->prepare("SELECT id,anchor_date,balance_cents,currency,provenance,revision,status,evidence_json FROM bank_statement_balance_anchors WHERE company_id=? AND bank_account_id=? AND anchor_date>=? AND status<>'disputed' ORDER BY anchor_date,revision DESC LIMIT 1");$closingStmt->execute([$companyId,$bankId,$coverageEnd??$anchorCutoff]);$closingAnchor=$closingStmt->fetch();$statementClosing=$closingAnchor?(int)$closingAnchor['balance_cents']:null;$difference=$calculated!==null&&$statementClosing!==null?$statementClosing-$calculated:null;
    $bookAsOf=$to!==''?$to:'9999-12-31';$book=operations_bank_balance($companyId,(string)$bank['ledger_account_id'],$bookAsOf);
    $receiptStmt=db()->prepare('SELECT id,import_batch_id,coverage_start,coverage_end,currency,parsed_count,selected_count,existing_count,invalid_count,omitted_count,money_in_cents,money_out_cents,source_opening_cents,calculated_closing_cents,source_closing_cents,difference_cents,prior_statement_balance_cents,projected_statement_balance_cents,book_balance_before_cents,book_balance_after_cents,completeness_state,result_json,created_at FROM bank_import_control_receipts WHERE company_id=? AND bank_account_id=? ORDER BY created_at DESC,id DESC LIMIT 1');$receiptStmt->execute([$companyId,$bankId]);$receipt=$receiptStmt->fetch();
    $receiptOut=$receipt?['id'=>(string)$receipt['id'],'batchId'=>$receipt['import_batch_id'],'coverageStart'=>$receipt['coverage_start'],'coverageEnd'=>$receipt['coverage_end'],'currency'=>(string)$receipt['currency'],'parsedCount'=>(int)$receipt['parsed_count'],'selectedCount'=>(int)$receipt['selected_count'],'existingCount'=>(int)$receipt['existing_count'],'invalidCount'=>(int)$receipt['invalid_count'],'omittedCount'=>(int)$receipt['omitted_count'],'moneyInCents'=>(int)$receipt['money_in_cents'],'moneyOutCents'=>(int)$receipt['money_out_cents'],'sourceOpeningCents'=>$receipt['source_opening_cents']===null?null:(int)$receipt['source_opening_cents'],'calculatedClosingCents'=>$receipt['calculated_closing_cents']===null?null:(int)$receipt['calculated_closing_cents'],'sourceClosingCents'=>$receipt['source_closing_cents']===null?null:(int)$receipt['source_closing_cents'],'differenceCents'=>$receipt['difference_cents']===null?null:(int)$receipt['difference_cents'],'priorStatementBalanceCents'=>$receipt['prior_statement_balance_cents']===null?null:(int)$receipt['prior_statement_balance_cents'],'projectedStatementBalanceCents'=>$receipt['projected_statement_balance_cents']===null?null:(int)$receipt['projected_statement_balance_cents'],'bookBalanceBeforeCents'=>(int)$receipt['book_balance_before_cents'],'bookBalanceAfterCents'=>(int)$receipt['book_balance_after_cents'],'completenessState'=>(string)$receipt['completeness_state'],'createdAt'=>(string)$receipt['created_at']]:null;
    json_response(['account'=>['id'=>(string)$bank['id'],'name'=>(string)$bank['name'],'accountType'=>(string)$bank['account_type'],'maskedNumber'=>(string)($bank['masked_number']??''),'currency'=>(string)$bank['currency'],'ledgerAccountId'=>(string)$bank['ledger_account_id'],'ledgerLabel'=>(string)$bank['ledger_code'].' — '.(string)$bank['ledger_name']],'coverageStart'=>$coverageStart,'coverageEnd'=>$coverageEnd,'statementOpeningCents'=>$opening,'moneyInCents'=>$moneyIn,'moneyOutCents'=>$moneyOut,'calculatedStatementClosingCents'=>$calculated,'statementClosingCents'=>$statementClosing,'differenceCents'=>$difference,'bookBalanceCents'=>$book,'balanceState'=>$opening===null?'history_gap':($statementClosing===null?'balance_check_unavailable':($difference===0?'checks_passed_review_required':'difference_found')),'orderVerified'=>$orderVerified,'openingEvidence'=>$anchor?['anchorDate'=>(string)$anchor['anchor_date'],'provenance'=>(string)$anchor['provenance'],'revision'=>(int)$anchor['revision']]:null,'closingEvidence'=>$closingAnchor?['anchorDate'=>(string)$closingAnchor['anchor_date'],'provenance'=>(string)$closingAnchor['provenance'],'revision'=>(int)$closingAnchor['revision']]:null,'rows'=>$mapped,'lastImportReceipt'=>$receiptOut]);
}

function operations_company_delete_order(): array
{
    // Delete company-scoped tables in dependency-safe order. A single
    // DELETE FROM companies can be rejected when two company tables also
    // reference one another through restrictive foreign keys.
    return [
        // Schema 34-39 successors must remain ahead of every referenced
        // parent. In particular, financial scenarios restrict budget
        // deletion and autonomy runs restrict policy deletion.
        'invoice_attachments',
        'invoice_document_operations',
        'outbound_email_attempts',
        'collection_message_templates',
        'month_end_attestations',
        'native_agent_autonomy_runs',
        'native_agent_collection_drafts',
        'financial_scenario_adjustments',
        'financial_scenarios',
        'ai_agent_plans',
        'ai_agent_memory_history',
        'vendor_recognition_history',
        'vendor_recognition_rules',
        'native_agent_documents',
        'native_agent_findings',
        'native_agent_run_failures',
        'native_agent_runs',
        'native_agent_autonomy_policies',
        'native_agent_policies',
        'ai_agent_conversations',
        'ai_agent_memories',
        'support_requests',
        'client_error_events',
        'outbound_emails',
        'aging_profiles',
        'products_services',
        'backup_restore_log',
        'audit_integrity_runs',
        'notifications',
        'company_invitations',
        // These assignment and operation tables are company-scoped even
        // though their dependent rows cascade. Keep them in the explicit map
        // so the deletion preflight covers the complete live schema.
        'account_invitation_companies',
        'bank_bulk_operations',
        'bank_category_commits',
        'official_rate_releases',
        'data_import_previews',
        'ai_agent_tasks',
        'ai_agent_result_sets',
        'ai_agent_action_authorizations',
        'ai_agent_behavior_versions',
        'ai_agent_improvements',
        'ai_agent_review_runs',
        'ai_agent_learned_rules',
        'ai_agent_learning_events',
        'ai_agent_preferences',
        'vouchers',
        'voucher_draft_payloads',
        'voucher_sequences',
        'transaction_sequences',
        'payroll_verifications',
        'payroll_journal_drafts',
        'reconciliation_events',
        'reconciliation_resume_log',
        'reconciliation_match_proposals',
        // Schema 45 payment/transfer/import-control children precede every
        // restrictive parent they reference.
        'party_payment_applications',
        'party_payment_application_operations',
        'payment_legacy_mapping_reviews',
        'interbank_transfer_legs',
        'interbank_transfer_operations',
        'interbank_transfers',
        'bank_import_control_receipts',
        'bank_statement_balance_anchors',
        // Match groups retain the immutable bank/book relationship. Delete the
        // parent before bank transactions or journals; child/link rows cascade.
        'bank_match_groups',
        'invoice_followups',
        'statement_previews',
        'analytic_allocations',
        'cash_flow_mappings',
        'employer_levy_profiles',
        'category_rules',
        'reconciliations',
        // Payments reference financial accounts without ON DELETE CASCADE and
        // must be removed before bank transactions, journals, and accounts.
        'party_payments',
        'bank_transaction_source_text',
        'bank_transactions',
        'import_batches',
        'payroll_remittances',
        'bills',
        'opening_document_imports',
        'expenses',
        'opening_balance_imports',
        'opening_balance_drafts',
        'recurring_invoice_profiles',
        'recurring_bill_profiles',
        'invoices',
        'payroll_runs',
        'payroll_employees',
        'payroll_settings',
        'budgets',
        'fixed_assets',
        'recurring_journal_templates',
        'analytic_accounts',
        'party_opening_balances',
        'vendors',
        'customers',
        'invoice_templates',
        'bank_accounts',
        'journal_approvals',
        'journal_entries',
        'company_system_accounts',
        'accounts',
        'statutory_rates',
        'employer_levy_rates',
        'period_locks',
        'ai_agent_workflow_sessions',
        'ai_agent_incidents',
        'ai_runs',
        'audit_log',
        'accounting_controls',
        'company_currencies',
        'document_sequences',
        'company_members',
    ];
}

function operations_company_delete_retained_tables(): array
{
    // Platform incident history is intentionally immutable and may retain a
    // deleted company identifier as non-relational diagnostic evidence.
    // Schema 40 entitlement/history and privacy-minimized usage are platform
    // metadata, excluded from portable company-books backup/restore. Their
    // company foreign keys use SET NULL so company deletion retains the audit
    // evidence without blocking deletion or granting book access.
    return ['platform_incident_log','entitlement_subjects','entitlement_requests','feature_usage_daily','signup_feature_intents'];
}

function operations_company_delete_dependency_issues(): array
{
    // Guard the explicit delete order against the live schema. Tegh deletes
    // company rows explicitly so the deletion log can report what was removed;
    // any restrictive child FK must therefore be deleted before its parent.
    $order = operations_company_delete_order();
    $position = array_flip($order);
    $retained = array_fill_keys(operations_company_delete_retained_tables(), true);
    $coverageSql = "SELECT DISTINCT c.TABLE_NAME
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA = c.TABLE_SCHEMA
         AND t.TABLE_NAME = c.TABLE_NAME
        WHERE c.TABLE_SCHEMA = DATABASE()
          AND c.COLUMN_NAME = 'company_id'
          AND t.TABLE_TYPE = 'BASE TABLE'
        ORDER BY c.TABLE_NAME";
    $issues = [];
    foreach (db()->query($coverageSql)->fetchAll() as $row) {
        $table = (string)$row['TABLE_NAME'];
        if (array_key_exists($table, $position) || isset($retained[$table])) continue;
        $issues[] = [
            'issueType'=>'company_table_not_mapped',
            'child'=>$table,
            'parent'=>'companies',
            'deleteRule'=>'UNMAPPED',
        ];
    }
    $sql = "SELECT k.TABLE_NAME AS child_table, k.REFERENCED_TABLE_NAME AS parent_table, rc.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
          ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
         AND rc.TABLE_NAME = k.TABLE_NAME
        WHERE k.CONSTRAINT_SCHEMA = DATABASE()
          AND k.REFERENCED_TABLE_NAME IS NOT NULL
          AND rc.DELETE_RULE IN ('RESTRICT','NO ACTION')
          AND EXISTS (
              SELECT 1 FROM information_schema.COLUMNS c
              WHERE c.TABLE_SCHEMA = k.CONSTRAINT_SCHEMA
                AND c.TABLE_NAME = k.TABLE_NAME
                AND c.COLUMN_NAME = 'company_id'
          )";
    foreach (db()->query($sql)->fetchAll() as $row) {
        $child = (string)$row['child_table'];
        $parent = (string)$row['parent_table'];
        if (!array_key_exists($parent, $position)) continue;
        if (!array_key_exists($child, $position) || $position[$child] > $position[$parent]) {
            $issues[] = [
                'issueType'=>'restrictive_foreign_key_order',
                'child'=>$child,
                'parent'=>$parent,
                'deleteRule'=>(string)$row['DELETE_RULE'],
            ];
        }
    }
    return $issues;
}

function operations_company_delete_assert_schema_safe(array $user,string $companyId): void
{
    $issues = operations_company_delete_dependency_issues();
    if ($issues === []) return;
    record_system_incident('Company deletion is blocked by an internal dependency-map mismatch.',503,'company_delete_dependency_mismatch',null,[
        'source'=>'company_delete_preflight','route'=>'operations/company-delete','companyId'=>$companyId,
        'dependencyCount'=>count($issues),'dependencies'=>array_slice($issues,0,20),
    ]);
    fail('Company deletion cannot continue because the current database structure contains an unsupported dependency. Update Tegh and try again.',503,'company_delete_dependency_mismatch');
}

function operations_company_record_summary(string $companyId): array
{
    $summary = [];
    foreach (operations_company_delete_order() as $table) {
        if (!schema_table_exists($table)) continue;
        $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $summary[$table] = (int)$stmt->fetchColumn();
    }
    return $summary;
}

function operations_delete_company_rows(string $companyId): array
{
    $deleted = [];
    if(schema_table_exists('entitlement_requests')&&schema_column_exists('entitlement_requests','retired_at')){
        $retireRequests=db()->prepare("UPDATE entitlement_requests SET retired_at=UTC_TIMESTAMP(),company_id=NULL,state=CASE WHEN state='pending' THEN 'cancelled' ELSE state END,pending_dedup_key=NULL WHERE company_id=? AND retired_at IS NULL");
        $retireRequests->execute([$companyId]);$deleted['entitlement_requests_retired']=$retireRequests->rowCount();
    }
    if(schema_table_exists('entitlement_subjects')&&schema_column_exists('entitlement_subjects','retired_at')){
        $retire=db()->prepare('UPDATE entitlement_subjects SET retired_at=UTC_TIMESTAMP(),account_user_id=NULL,company_id=NULL,user_id=NULL WHERE company_id=? AND retired_at IS NULL');$retire->execute([$companyId]);$deleted['entitlement_subjects_retired']=$retire->rowCount();
    }
    foreach (operations_company_delete_order() as $table) {
        if (!schema_table_exists($table)) continue;
        $stmt = db()->prepare("DELETE FROM `$table` WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $deleted[$table] = $stmt->rowCount();
    }
    return $deleted;
}

function operations_remove_company_storage(string $storage): array
{
    if (!is_dir($storage)) {
        return ['status' => 'not_present', 'failedPaths' => []];
    }

    $failed = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $ok = $file->isDir() ? @rmdir($path) : @unlink($path);
            if (!$ok && file_exists($path)) $failed[] = $path;
        }
        if (!@rmdir($storage) && is_dir($storage)) $failed[] = $storage;
    } catch (Throwable $error) {
        $failed[] = $storage;
        record_system_incident('Private company storage cleanup did not complete.',500,'company_storage_cleanup_failed',$error,[
            'source'=>'background_error','route'=>'operations/company-storage-cleanup','storagePathHash'=>hash('sha256',$storage),
        ]);
        error_log('Tegh company storage cleanup request=' . request_id() . ' ' . $error::class . ': ' . $error->getMessage());
    }

    if ($failed) {
        record_system_incident('Some private company files still require cleanup.',500,'company_storage_cleanup_pending',null,[
            'source'=>'background_error','route'=>'operations/company-storage-cleanup','failedPathCount'=>count($failed),
            'failedPathHashes'=>array_map(static fn(string $path):string=>hash('sha256',$path),array_slice($failed,0,20)),
        ]);
        error_log('Tegh company storage cleanup pending request=' . request_id() . ' paths=' . count($failed));
        return ['status' => 'pending', 'failedPaths' => array_slice($failed, 0, 20)];
    }
    return ['status' => 'complete', 'failedPaths' => []];
}

function purge_expired_test_companies(?string $userId = null): array
{
    if (!schema_column_exists('companies', 'test_mode')) return [];
    $sql = "SELECT id, name, test_created_by FROM companies
        WHERE test_mode = 1 AND test_expires_at IS NOT NULL AND test_expires_at <= UTC_TIMESTAMP()";
    $params = [];
    if ($userId !== null && $userId !== '') {
        $sql .= " AND EXISTS (SELECT 1 FROM company_members cm WHERE cm.company_id=companies.id AND cm.user_id=?)";
        $params[] = $userId;
    }
    $sql .= " ORDER BY test_expires_at LIMIT 20";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $expired = $stmt->fetchAll();
    $deleted = [];
    foreach ($expired as $row) {
        $companyId = (string)$row['id'];
        $actorId = trim((string)($row['test_created_by'] ?? ''));
        if ($actorId === '') {
            $owner = db()->prepare("SELECT user_id FROM company_members WHERE company_id=? AND role='owner' ORDER BY created_at LIMIT 1");
            $owner->execute([$companyId]);
            $actorId = (string)($owner->fetchColumn() ?: '');
        }
        if ($actorId === '') continue;
        try {
            operations_company_delete_assert_schema_safe([], $companyId);
            $result = db_transaction_retry(function () use ($companyId, $actorId, $row): array {
                $lock = db()->prepare('SELECT id FROM companies WHERE id=? AND test_mode=1 AND test_expires_at<=UTC_TIMESTAMP() FOR UPDATE');
                $lock->execute([$companyId]);
                if (!$lock->fetchColumn()) return [];
                $summary = operations_company_record_summary($companyId);
                operations_delete_company_rows($companyId);
                db()->prepare('DELETE FROM companies WHERE id=?')->execute([$companyId]);
                db()->prepare('INSERT INTO company_deletion_log
                    (id,deleted_company_id,company_name,deleted_by,backup_confirmed,record_summary_json)
                    VALUES (?,?,?,?,0,?)')->execute([
                        new_id('companydelete'), $companyId, (string)$row['name'], $actorId,
                        json_encode(['automaticTestModeExpiry'=>true,'recordsBeforeDeletion'=>$summary,'expiredAfterDays'=>4], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    ]);
                return $summary;
            });
            // A concurrent change can make the locked row ineligible. No confirmed deletion means no transition.
            if ($result === []) continue;
            operations_remove_company_storage(private_storage_root() . '/' . $companyId);
            $deleted[] = ['id'=>$companyId,'name'=>(string)$row['name'],'summary'=>$result];
        } catch (Throwable $error) {
            record_system_incident('An expired Test Mode company could not be removed.',500,'test_company_cleanup_failed',$error,[
                'source'=>'background_error','route'=>'platform/maintenance','companyId'=>$companyId,
            ]);
            error_log('Tegh test company cleanup request=' . request_id() . ' company=' . $companyId . ' ' . $error::class . ': ' . $error->getMessage());
        }
    }
    return $deleted;
}

function operations_company_delete(array $user,array $company): never
{
    require_method('POST');
    require_csrf();
    require_company_permission($company,'company.delete');
    $input = request_json();
    $typed = clean_text($input['companyName'] ?? '', 'Company name', 160);
    if (!hash_equals((string)$company['name'], $typed)) {
        fail('Type the company name exactly as shown.', 409, 'company_name_mismatch');
    }
    operations_owner_password($user, (string)($input['password'] ?? ''));
    if (empty($input['backupConfirmed'])) {
        fail('Confirm that a final company backup has been downloaded.', 409, 'company_backup_required');
    }

    $companyId = (string)$company['id'];
    operations_company_delete_assert_schema_safe($user, $companyId);
    $storage = private_storage_root() . '/' . $companyId;
    $result = db_transaction_retry(function () use ($user, $company, $companyId): array {
        $lock = db()->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
        $lock->execute([$companyId]);
        if (!$lock->fetchColumn()) {
            fail('The selected company is already unavailable.', 409, 'company_already_deleted');
        }

        $summary = operations_company_record_summary($companyId);
        $deletedRows = operations_delete_company_rows($companyId);

        $deleteCompany = db()->prepare('DELETE FROM companies WHERE id = ?');
        $deleteCompany->execute([$companyId]);
        if ($deleteCompany->rowCount() !== 1) {
            throw new RuntimeException('The company row was not deleted.');
        }

        db()->prepare('INSERT INTO company_deletion_log
            (id, deleted_company_id, company_name, deleted_by, backup_confirmed, record_summary_json)
            VALUES (?, ?, ?, ?, 1, ?)')->execute([
                new_id('companydelete'),
                $companyId,
                $company['name'],
                $user['id'],
                json_encode([
                    'recordsBeforeDeletion' => $summary,
                    'rowsDeletedExplicitly' => $deletedRows,
                    'requestId' => request_id(),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);

        return ['summary' => $summary, 'deletedRows' => $deletedRows];
    });

    // Storage cleanup is deliberately best-effort after the database commit.
    // A file-permission problem must not misreport a successful deletion as a
    // failed accounting transaction.
    $storageCleanup = operations_remove_company_storage($storage);
    $transition=tegh_deletion_transition_payload($user,[$companyId]);

    json_response([
        'deleted' => true,
        'companyId' => $companyId,
        'deletedCompanyIds' => $transition['deletedCompanyIds'],
        'replacementCompanyId' => $transition['replacementCompanyId'],
        'replacementCompanyName' => $transition['replacementCompanyName'],
        'storageCleanup' => $storageCleanup['status'],
        'storageCleanupNeedsAttention' => $storageCleanup['status'] === 'pending',
        'recordSummary' => $result['summary'],
    ]);
}

function operations_payroll_verification(array $user,array $company): never
{
    require_company_permission($company,'payroll.view');
    $companyId=(string)$company['id'];
    if(request_method()==='GET'){
        $runId=clean_text($_GET['runId']??'','Payroll run',64);
        $stmt=db()->prepare('SELECT * FROM payroll_runs WHERE id=? AND company_id=?');$stmt->execute([$runId,$companyId]);$run=$stmt->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');
        $stmt=db()->prepare('SELECT pri.*,pe.employee_number,pe.first_name,pe.last_name,pe.province_of_employment FROM payroll_run_items pri JOIN payroll_employees pe ON pe.id=pri.employee_id WHERE pri.payroll_run_id=? ORDER BY pe.last_name,pe.first_name');$stmt->execute([$runId]);
        $items=array_map(static fn(array $r):array=>['id'=>(string)$r['id'],'employeeNumber'=>(string)$r['employee_number'],'employeeName'=>(string)$r['first_name'].' '.(string)$r['last_name'],'province'=>(string)$r['province_of_employment'],'grossPayCents'=>(int)$r['gross_pay_cents'],'pensionablePayCents'=>(int)$r['pensionable_pay_cents'],'insurablePayCents'=>(int)$r['insurable_pay_cents'],'employeeCppCents'=>(int)$r['employee_cpp_cents'],'employeeCpp2Cents'=>(int)$r['employee_cpp2_cents'],'employeeEiCents'=>(int)$r['employee_ei_cents'],'estimatedIncomeTaxCents'=>(int)$r['estimated_income_tax_cents'],'verifiedIncomeTaxCents'=>$r['verified_income_tax_cents']===null?null:(int)$r['verified_income_tax_cents'],'verificationStatus'=>(string)$r['verification_status']],$stmt->fetchAll());
        json_response(['run'=>['id'=>$runId,'periodStart'=>(string)$run['period_start'],'periodEnd'=>(string)$run['period_end'],'payDate'=>(string)$run['pay_date'],'frequency'=>(string)$run['frequency'],'status'=>(string)$run['status'],'calculationVersion'=>(string)$run['calculation_version'],'verificationReference'=>$run['verification_reference']!==null?(string)$run['verification_reference']:null],'items'=>$items,'calculatorUrl'=>SR_OFFICIAL_PAYROLL_CALCULATOR_URL]);
    }
    require_method('POST');require_csrf();require_company_role($company,'owner','bookkeeper');$input=request_json();$runId=clean_text($input['runId']??'','Payroll run',64);$itemId=trim((string)($input['itemId']??''))?:null;$method=(string)($input['method']??'official_calculator');if(!in_array($method,['formula','official_calculator'],true))fail('Verification method is invalid.');
    $stmt=db()->prepare('SELECT id,status FROM payroll_runs WHERE id=? AND company_id=?');$stmt->execute([$runId,$companyId]);$run=$stmt->fetch();if(!$run)fail('Payroll run not found.',404,'payroll_run_not_found');
    if($itemId!==null){$stmt=db()->prepare('SELECT * FROM payroll_run_items WHERE id=? AND payroll_run_id=? FOR UPDATE');$stmt->execute([$itemId,$runId]);$item=$stmt->fetch();if(!$item)fail('Payroll item not found.',404,'payroll_item_not_found');}else $item=null;
    $cpp=isset($input['employeeCppCents'])?safe_cents($input['employeeCppCents'],'CPP',true):null;$cpp2=isset($input['employeeCpp2Cents'])?safe_cents($input['employeeCpp2Cents'],'CPP2',true):null;$ei=isset($input['employeeEiCents'])?safe_cents($input['employeeEiCents'],'EI',true):null;$tax=isset($input['incomeTaxCents'])?safe_cents($input['incomeTaxCents'],'Income tax',true):null;$difference=0;
    if($item){foreach([['employee_cpp_cents',$cpp],['employee_cpp2_cents',$cpp2],['employee_ei_cents',$ei],['estimated_income_tax_cents',$tax]] as [$field,$value])if($value!==null)$difference+=abs((int)$item[$field]-$value);}
    db()->beginTransaction();try{
        db()->prepare('INSERT INTO payroll_verifications (id,company_id,payroll_run_id,payroll_run_item_id,method,calculator_url,employee_cpp_cents,employee_cpp2_cents,employee_ei_cents,income_tax_cents,difference_cents,note,verified_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([new_id('payverify'),$companyId,$runId,$itemId,$method,$method==='official_calculator'?SR_OFFICIAL_PAYROLL_CALCULATOR_URL:null,$cpp,$cpp2,$ei,$tax,$difference,optional_text($input['note']??null,1000)??'',$user['id']]);
        if($itemId!==null){$status=$method==='official_calculator'?($difference<=2?'official_verified':'difference'):'formula_verified';if($tax!==null&&(string)$run['status']==='draft'){$item['verified_income_tax_cents']=$tax;$item['net_pay_cents']=(int)$item['gross_pay_cents']-(int)$item['employee_cpp_cents']-(int)$item['employee_cpp2_cents']-(int)$item['employee_ei_cents']-$tax-(int)$item['other_deductions_cents'];if($item['net_pay_cents']<0)fail('Verified deductions exceed gross pay.');db()->prepare('UPDATE payroll_run_items SET verified_income_tax_cents=?,net_pay_cents=?,verification_status=?,locked_hash=? WHERE id=?')->execute([$tax,$item['net_pay_cents'],$status,payroll_item_integrity_hash($item),$itemId]);payroll_recalculate_run_totals($runId);}else db()->prepare('UPDATE payroll_run_items SET verification_status=? WHERE id=?')->execute([$status,$itemId]);}
        audit_event($user,$companyId,'payroll.calculation_verified','payroll_run',$runId,['method'=>$method,'itemId'=>$itemId,'differenceCents'=>$difference]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    json_response(['verified'=>true,'differenceCents'=>$difference,'status'=>$difference<=2?'verified':'difference']);
}

function handle_operations(string $action): never
{
    $user=require_user();$company=require_company($user);
    match($action){
        'workspace'=>operations_workspace($user,$company),
        'statement-preview'=>request_method()==='GET'?operations_statement_preview_get($company):(request_method()==='PUT'?operations_statement_preview_update($user,$company):(request_method()==='DELETE'?operations_statement_preview_delete($user,$company):operations_statement_preview($user,$company))),
        'statement-approve'=>operations_statement_approve($user,$company),
        'statement-controls'=>operations_statement_controls($company),
        'period-locks'=>operations_period_locks($user,$company),
        'bank-bulk'=>operations_bulk_bank($user,$company),
        'bank-ledger'=>operations_bank_ledger($company),
        'reconciliation-workspace'=>operations_reconciliation_workspace_v2($company),
        'reconciliation-position'=>operations_reconciliation_position_r122($company),
        'control-balances'=>operations_control_balances_r122($company),
        'reconciliation-suggestions'=>operations_reconciliation_suggestions($company),
        'reconciliation-match'=>operations_reconciliation_match($user,$company),
        'reconciliation-match-bulk'=>operations_reconciliation_match_bulk($user,$company),
        'reconciliation-complete'=>operations_reconciliation_complete($user,$company),
        'review-payment-suggestions'=>operations_review_payment_suggestions($company),
        'reconciliation-unmatch'=>operations_reconciliation_unmatch($user,$company),
        'reconciliation-report'=>operations_reconciliation_report($user,$company),
        'reconciliation-reopen'=>operations_reconciliation_reopen($user,$company),
        'rates'=>operations_rates($user,$company),
        'levy-profile'=>operations_levy_profile($user,$company),
        'levy-rates'=>operations_levy_rates($user,$company),
        'levy-calculate'=>operations_levy_calculate($company),
        'account-mappings'=>operations_account_mappings($user,$company),
        'tax-report'=>operations_tax_report($company),
        'company-settings'=>operations_company_settings($user,$company),
        'company-delete'=>operations_company_delete($user,$company),
        'payroll-verification'=>operations_payroll_verification($user,$company),
        default=>fail('Operations route not found.',404,'route_not_found'),
    };
}
