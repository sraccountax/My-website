<?php
declare(strict_types=1);

/** Read-only verification output. Deliberately does not call report audit/seal
 * services: generating a preview must make zero database writes. */
function tegh_bank_preview_output(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.view');
    $id=clean_text($input['previewId']??'','Statement preview',64);
    $stmt=db()->prepare('SELECT sp.*,ba.name bank_name,ba.active bank_active FROM statement_previews sp JOIN bank_accounts ba ON ba.id=sp.bank_account_id AND ba.company_id=sp.company_id WHERE sp.id=? AND sp.company_id=?');
    $stmt->execute([$id,$company['id']]);$preview=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$preview||$preview['status']!=='draft'||!(bool)$preview['bank_active'])fail('An active, unapproved statement preview is required.',409,'statement_preview_unavailable');
    $rows=operations_json_decode((string)$preview['rows_json'],'Statement preview rows');
    if(!is_array($rows)||count($rows)<1||count($rows)>5000)fail('The complete preview is unavailable or exceeds 5,000 rows.',422,'statement_preview_incomplete');
    $rows=operations_statement_mark_duplicates((string)$company['id'],operations_statement_rows_with_keys((string)$company['id'],(string)$preview['bank_account_id'],(string)$preview['currency'],$rows));
    $selectedKeys=$input['selectedKeys']??null;if(!is_array($selectedKeys)||count($selectedKeys)>5000)fail('Supply the complete reviewed selection.',422,'preview_selection_invalid');
    $selected=[];foreach($selectedKeys as $key){if(!is_string($key)||isset($selected[$key]))fail('Selection keys must be unique.',422,'preview_selection_invalid');$selected[$key]=true;}
    $known=[];$typed=[];$totals=['rowCount'=>count($rows),'selectedCount'=>0,'eligibleCount'=>0,'duplicateCount'=>0,'excludedCount'=>0,'debitCents'=>0,'creditCents'=>0,'selectedDebitCents'=>0,'selectedCreditCents'=>0];$dates=[];
    foreach($rows as $index=>$row){
        $key=(string)($row['selectionKey']??'');$known[$key]=true;$eligible=!empty($key)&&($row['eligible']??true)!==false&&empty($row['duplicate'])&&empty($row['userExcluded']);$chosen=isset($selected[$key]);
        if($chosen&&!$eligible)fail('The selection changed or contains an ineligible row. Refresh the preview.',409,'preview_selection_stale');
        $date=safe_date($row['date']??'','Preview date');assert_not_future_date($date,'Preview date');$dates[]=$date;
        $amount=safe_cents($row['foreignAmountCents']??$row['amountCents']??0,'Preview amount',true);$debit=max(0,-$amount);$credit=max(0,$amount);
        $duplicate=!empty($row['duplicate']);$excluded=!empty($row['userExcluded']);
        $totals['debitCents']+=$debit;$totals['creditCents']+=$credit;$totals['eligibleCount']+=(int)$eligible;$totals['selectedCount']+=(int)$chosen;$totals['duplicateCount']+=(int)$duplicate;$totals['excludedCount']+=(int)$excluded;
        if($chosen){$totals['selectedDebitCents']+=$debit;$totals['selectedCreditCents']+=$credit;}
        $typed[]=['rowNumber'=>$index+1,'selectionKey'=>$key,'selection'=>$chosen?'Selected':'Not selected','eligibility'=>$eligible?'Eligible':($duplicate?'Duplicate':($excluded?'Excluded':'Unavailable')),
            'validation'=>(string)($row['validationReason']??(!empty($row['requiresDuplicateReview'])?'Possible duplicate — explicit review required':'Server validated')),
            'date'=>$date,'description'=>(string)($row['fullDescription']??$row['description']??''),'reference'=>(string)($row['reference']??''),
            'debitCents'=>$debit,'creditCents'=>$credit,'balanceCents'=>array_key_exists('runningBalanceCents',$row)&&$row['runningBalanceCents']!==null?(int)$row['runningBalanceCents']:null];
    }
    foreach($selected as $key=>$_)if(!isset($known[$key]))fail('The selection includes an unknown preview row.',422,'preview_selection_invalid');
    if((strtotime(max($dates))-strtotime(min($dates)))>366*86400)fail('Preview dates span more than 366 days.',422,'statement_period_invalid');
    $parameters=['previewId'=>$id,'bankAccount'=>(string)$preview['bank_name'],'sourceFilename'=>(string)$preview['filename'],'start'=>min($dates),'end'=>max($dates),'currency'=>(string)$preview['currency'],'selectionScope'=>'Complete preview; search does not limit output'];
    $model=['contractVersion'=>'tegh.report-output/2','definitionKey'=>'bank_statement_import_preview','definitionVersion'=>'5990.1','kind'=>'report','title'=>'Bank Statement Import Preview',
        'subtitle'=>'Not Imported / Not Posted','presentationStatus'=>'Bank Statement Import Preview — Not Imported / Not Posted','orientation'=>'landscape','paper'=>'letter','currency'=>(string)$preview['currency'],
        'company'=>['id'=>(string)$company['id'],'name'=>(string)$company['name'],'legalName'=>(string)($company['legal_name']??$company['name'])],
        'accountingBasis'=>'Statement evidence — not accounting entries','reportingFramework'=>(string)($company['reporting_framework']??'not_set'),
        'parameters'=>$parameters,'period'=>['mode'=>'range','start'=>min($dates),'end'=>max($dates),'inclusive'=>true],'columns'=>[
            ['key'=>'rowNumber','label'=>'Row','type'=>'number','width'=>4],['key'=>'date','label'=>'Date','type'=>'date','width'=>9],
            ['key'=>'description','label'=>'Full Description','type'=>'text','width'=>28],['key'=>'reference','label'=>'Reference','type'=>'text','width'=>9],
            ['key'=>'debitCents','label'=>'Debit / Money Out','type'=>'money','width'=>10],['key'=>'creditCents','label'=>'Credit / Money In','type'=>'money','width'=>10],['key'=>'balanceCents','label'=>'Running Balance','type'=>'money','width'=>10],
            ['key'=>'selection','label'=>'Selection','type'=>'text','width'=>8],['key'=>'eligibility','label'=>'Eligibility','type'=>'text','width'=>8],['key'=>'validation','label'=>'Validation','type'=>'text','width'=>14]],
        'rows'=>$typed,'totals'=>$totals,'rowCount'=>count($typed),'completeness'=>['complete'=>true,'rowCount'=>count($typed),'limit'=>5000],
        'disclosures'=>['Every server-validated preview row is included, including duplicates and excluded/unselected rows.','Running balances refer to the original statement sequence, not a selected subset.','Management verification only; no import, posting or accounting assurance is represented.'],
        'accountingWrites'=>0,'databaseWrites'=>0,'providerAttempts'=>0];
    $hash=hash('sha256',tegh_json_canonical($model));$generated=gmdate('Y-m-d\TH:i:s\Z');
    $model['verifiedOutput']=['reference'=>'BSP-'.strtoupper(substr($hash,0,20)),'generatedAt'=>$generated,'generatedBy'=>(string)($user['name']??$user['email']??$user['id']),'companyTimezone'=>'America/Toronto','sourceRevisionHash'=>$hash,'totalsDigest'=>hash('sha256',tegh_json_canonical($totals)),'rowCount'=>count($typed),'statement'=>'Preview only — Not Imported / Not Posted'];
    return $model;
}
function handle_bank_preview_output_v5980(): never
{
    require_method('POST');require_csrf();$user=require_user();$company=require_company($user);tegh_schema44_require();header('Cache-Control: no-store, private');json_response(['output'=>tegh_bank_preview_output($user,$company,request_json())]);
}
