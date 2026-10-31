<?php
declare(strict_types=1);

/** Tegh 5.1.0 centralized, selectively committed import pipeline. */

function di_import_types(): array
{
    // The two opening-document identifiers remain accepted for sealed-package
    // compatibility, but Build 5100 exposes only the unified customer/vendor
    // invoice importers. Those classify each selected document at Start of Books.
    return ['bank_transaction_categories','customers','vendors','products_services','employees','customer_invoices','vendor_invoices','chart_of_accounts','opening_balances','coa_opening_balances','opening_customer_invoices','opening_vendor_bills'];
}

function di_permission(string $type): string
{
    return match($type){
        'bank_transaction_categories'=>'banking.match','customers'=>'customers.write','vendors'=>'vendors.write','products_services'=>'invoices.write','employees'=>'payroll.manage',
        'customer_invoices'=>'invoices.write','vendor_invoices'=>'bills.write',
        'chart_of_accounts'=>'company.settings','opening_balances'=>'company.settings','coa_opening_balances'=>'company.settings',
        'opening_customer_invoices'=>'invoices.write','opening_vendor_bills'=>'bills.write',default=>'company.settings',
    };
}

function di_str(array $row,string $key,int $max=2000): string
{
    $value=trim((string)($row[$key]??''));
    return mb_substr($value,0,$max);
}

function di_money_cents(mixed $value, ?string &$error=null): int
{
    $error=null;$raw=trim((string)$value);
    if($raw==='')return 0;
    $raw=str_replace([',','$',' '],'',$raw);
    if(!preg_match('/^-?(?:\d+)(?:\.\d{1,2})?$/',$raw)){$error='Enter a valid amount.';return 0;}
    $negative=str_starts_with($raw,'-');$raw=ltrim($raw,'-');
    [$whole,$decimal]=array_pad(explode('.',$raw,2),2,'');$decimal=str_pad($decimal,2,'0');
    $cents=((int)$whole*100)+(int)substr($decimal,0,2);
    return $negative?-$cents:$cents;
}

function di_quantity(mixed $value, ?string &$error=null): float
{
    $error=null;$raw=trim((string)$value);if($raw==='')return 1.0;
    if(!is_numeric($raw)){$error='Enter a numeric quantity.';return 0.0;}
    $n=(float)$raw;if(!is_finite($n)||$n<=0||$n>1000000){$error='Quantity must be greater than zero and no more than 1,000,000.';return 0.0;}
    return $n;
}

function di_bool(mixed $value,bool $default=true,?string &$error=null): bool
{
    $error=null;$raw=strtolower(trim((string)$value));if($raw==='')return $default;
    if(in_array($raw,['1','true','yes','y','active','taxable'],true))return true;
    if(in_array($raw,['0','false','no','n','inactive','non-taxable','nontaxable'],true))return false;
    $error='Use Yes/No or True/False.';return $default;
}

function di_date_value(mixed $value,bool $required,string $label,?string &$error=null): ?string
{
    $error=null;$raw=trim((string)$value);
    if($raw===''){if($required)$error=$label.' is required.';return null;}
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);$errs=DateTimeImmutable::getLastErrors();
    if(!$dt||($errs!==false&&(($errs['warning_count']??0)>0||($errs['error_count']??0)>0))||$dt->format('Y-m-d')!==$raw){$error='Use YYYY-MM-DD.';return null;}
    return $raw;
}

function di_terms_value(mixed $value,mixed $custom, int $default=30, ?string &$error=null): int
{
    $error=null;$raw=trim((string)$value);if($raw==='')return $default;
    $key=strtolower(preg_replace('/\s+/',' ',$raw)??$raw);
    $known=['due on receipt'=>0,'dueonreceipt'=>0,'receipt'=>0,'net 7'=>7,'net7'=>7,'7'=>7,'net 15'=>15,'net15'=>15,'15'=>15,'net 30'=>30,'net30'=>30,'30'=>30,'net 45'=>45,'net45'=>45,'45'=>45,'net 60'=>60,'net60'=>60,'60'=>60];
    if(isset($known[$key]))return $known[$key];
    if($key==='custom')$raw=trim((string)$custom);
    if(!preg_match('/^\d{1,4}$/',$raw)){$error='Use Due on Receipt, Net 7/15/30/45/60, or Custom with days.';return $default;}
    $days=(int)$raw;if($days<0||$days>3650){$error='Payment terms must be between 0 and 3,650 days.';return $default;}
    return $days;
}

function di_status_value(mixed $value,bool $customer,?string &$error=null): string
{
    $error=null;$raw=strtolower(trim((string)$value));$raw=str_replace([' ','-'],'_',$raw);if($raw==='')$raw='active';if($raw==='hold')$raw='on_hold';
    $allowed=$customer?['active','on_hold','inactive']:['active','inactive'];
    if(!in_array($raw,$allowed,true)){$error=$customer?'Use Active, On Hold, or Inactive.':'Use Active or Inactive.';return 'active';}
    return $raw;
}

function di_tax_mode(mixed $value, ?string &$error=null): array
{
    $error=null;$raw=strtoupper(trim((string)$value));
    if($raw===''||in_array($raw,['NO TAX','NONE','EXEMPT','NO','NON-TAXABLE','NONTAXABLE'],true))return ['taxable'=>false,'mode'=>'none'];
    if(in_array($raw,['GST/HST','GST','HST','TAXABLE','YES'],true))return ['taxable'=>true,'mode'=>'exclusive'];
    if(in_array($raw,['GST/HST INCLUSIVE','HST INCLUSIVE','GST INCLUSIVE','TAX INCLUSIVE','INCLUSIVE'],true))return ['taxable'=>true,'mode'=>'inclusive'];
    $error='Use GST/HST, GST/HST Inclusive, or No Tax.';return ['taxable'=>false,'mode'=>'none'];
}

function di_add_issue(array &$issues,int $row,string $reference,string $field,string $message,mixed $value,string $suggested,string $severity='error'): void
{
    $issues[]=['row'=>$row,'reference'=>$reference,'field'=>$field,'error'=>$message,'valueSupplied'=>mb_substr(trim((string)$value),0,240),'suggestedAction'=>$suggested,'severity'=>$severity];
}

function di_lookup_maps(string $companyId): array
{
    $accounts=[];$q=db()->prepare('SELECT id,code,name,description,account_type,is_control,active FROM accounts WHERE company_id=?');$q->execute([$companyId]);
    foreach($q->fetchAll() as $r)$accounts[strtoupper(trim((string)$r['code']))]=$r;
    $customers=[];$q=db()->prepare('SELECT id,name,status,active,default_terms_days FROM customers WHERE company_id=?');$q->execute([$companyId]);
    foreach($q->fetchAll() as $r)$customers[mb_strtolower(trim((string)$r['name']))]=$r;
    $vendors=[];$q=db()->prepare('SELECT id,name,status,active,default_terms_days,default_currency FROM vendors WHERE company_id=?');$q->execute([$companyId]);
    foreach($q->fetchAll() as $r)$vendors[mb_strtolower(trim((string)$r['name']))]=$r;
    $productsByCode=[];$productsByName=[];$q=db()->prepare('SELECT id,code,name,kind,active,income_account_id FROM products_services WHERE company_id=?');$q->execute([$companyId]);
    foreach($q->fetchAll() as $r){if(trim((string)($r['code']??''))!=='')$productsByCode[mb_strtolower(trim((string)$r['code']))]=$r;$productsByName[mb_strtolower(trim((string)$r['name']))]=$r;}
    $invoiceNumbers=[];$q=db()->prepare('SELECT number FROM invoices WHERE company_id=?');$q->execute([$companyId]);foreach($q->fetchAll() as $r)$invoiceNumbers[mb_strtolower(trim((string)$r['number']))]=true;
    $billNumbers=[];$q=db()->prepare('SELECT number FROM bills WHERE company_id=?');$q->execute([$companyId]);foreach($q->fetchAll() as $r)$billNumbers[mb_strtolower(trim((string)$r['number']))]=true;
    $partyOpenings=['customer'=>[],'vendor'=>[]];if(schema_table_exists('party_opening_balances')){$q=db()->prepare('SELECT party_type,party_id,amount_cents FROM party_opening_balances WHERE company_id=?');$q->execute([$companyId]);foreach($q->fetchAll() as $r)$partyOpenings[(string)$r['party_type']][(string)$r['party_id']]=(int)$r['amount_cents'];}
    $employeeNumbers=[];if(schema_table_exists('payroll_employees')){$q=db()->prepare('SELECT employee_number FROM payroll_employees WHERE company_id=?');$q->execute([$companyId]);foreach($q->fetchAll() as $r)$employeeNumbers[mb_strtolower(trim((string)$r['employee_number']))]=true;}
    $openingTrialBalanceControls=['1200'=>0,'2050'=>0];$q=db()->prepare("SELECT a.code,COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) net_cents FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND je.status='posted' AND je.source_type IN ('opening_balance','opening_balance_import') AND a.code IN ('1200','2050') GROUP BY a.code");$q->execute([$companyId]);foreach($q->fetchAll() as $r)$openingTrialBalanceControls[(string)$r['code']]=(int)$r['net_cents'];
    return compact('accounts','customers','vendors','productsByCode','productsByName','invoiceNumbers','billNumbers','partyOpenings','employeeNumbers','openingTrialBalanceControls');
}

function di_validate_customer_documents(array $company,array $rows,array $maps): array
{
    $companyId=(string)$company['id'];$booksStart=trim((string)($company['books_start_date']??''));$issues=[];$prepared=[];$groups=[];$seenNumbers=[];$openingTotals=['originalTotalCents'=>0,'previouslyPaidCents'=>0,'outstandingTotalCents'=>0];
    foreach(array_values($rows) as $i=>$row){if(!is_array($row))continue;$n=$i+2;$ref=di_str($row,'importInvoiceReference',120);if($ref===''){$ref='Row '.$n;di_add_issue($issues,$n,$ref,'Import Invoice Reference','Import Invoice Reference is required.','','Enter one reference shared by all lines of this invoice.');continue;}$groups[$ref][]=['row'=>$n,'data'=>$row];}
    foreach($groups as $ref=>$parts){$first=$parts[0]['data'];$firstRow=(int)$parts[0]['row'];$bad=false;$customerName=di_str($first,'customerName',160);$customer=$maps['customers'][mb_strtolower($customerName)]??null;
        if(!$customer){di_add_issue($issues,$firstRow,$ref,'Customer Name','Customer not found.',$customerName,'Import or create the customer first, then use its exact name.');$bad=true;}elseif(!(bool)$customer['active']||(string)$customer['status']==='inactive'){di_add_issue($issues,$firstRow,$ref,'Customer Name','Customer is inactive.',$customerName,'Reactivate the customer before importing an invoice.');$bad=true;}elseif((string)$customer['status']==='on_hold'){di_add_issue($issues,$firstRow,$ref,'Customer Name','Customer On Hold — a current-period invoice will remain Draft.',$customerName,'Review the hold before issuing the invoice.','warning');}
        $dup=db()->prepare('SELECT 1 FROM invoices WHERE company_id=? AND import_reference=? LIMIT 1');$dup->execute([$companyId,$ref]);if($dup->fetchColumn()){di_add_issue($issues,$firstRow,$ref,'Import Invoice Reference','This import reference has already been used.',$ref,'Use a new unique import reference.','duplicate');$bad=true;}
        $number=di_str($first,'invoiceNumber',60);if($number===''){di_add_issue($issues,$firstRow,$ref,'Invoice Number','Invoice Number is required.','','Enter the original or intended unique invoice number.');$bad=true;}else{$numberKey=mb_strtolower($number);if(isset($maps['invoiceNumbers'][$numberKey])||isset($seenNumbers[$numberKey])){di_add_issue($issues,$firstRow,$ref,'Invoice Number','That invoice number already exists or is duplicated in this file.',$number,'Use the correct unique invoice number once.','duplicate');$bad=true;}$seenNumbers[$numberKey]=true;}
        $dateErr=null;$issueDate=di_date_value($first['invoiceDate']??'',true,'Invoice Date',$dateErr);if($dateErr){di_add_issue($issues,$firstRow,$ref,'Invoice Date',$dateErr,$first['invoiceDate']??'','Use YYYY-MM-DD.');$bad=true;}
        $classification=($booksStart!==''&&$issueDate!==null&&$issueDate<$booksStart)?'opening_document_ready':'regular_draft';
        $termErr=null;$terms=di_terms_value($first['paymentTerms']??'', $first['customTermsDays']??'',(int)($customer['default_terms_days']??30),$termErr);if($termErr){di_add_issue($issues,$firstRow,$ref,'Payment Terms',$termErr,$first['paymentTerms']??'','Correct the payment terms.');$bad=true;}
        $dueErr=null;$dueDate=di_date_value($first['dueDate']??'',false,'Due Date',$dueErr);if($dueErr){di_add_issue($issues,$firstRow,$ref,'Due Date',$dueErr,$first['dueDate']??'','Use YYYY-MM-DD.');$bad=true;}if($dueDate===null&&$issueDate!==null)$dueDate=(new DateTimeImmutable($issueDate))->modify('+'.$terms.' days')->format('Y-m-d');if($issueDate&&$dueDate&&$dueDate<$issueDate){di_add_issue($issues,$firstRow,$ref,'Due Date','Due Date cannot be before Invoice Date.',$dueDate,'Correct the due date.');$bad=true;}
        foreach($parts as $part){$row=$part['data'];$n=(int)$part['row'];if(di_str($row,'customerName',160)!==$customerName){di_add_issue($issues,$n,$ref,'Customer Name','Every line in one invoice group must use the same customer.',$row['customerName']??'','Use a separate Import Invoice Reference for a different customer.');$bad=true;}foreach([['Invoice Number','invoiceNumber'],['Invoice Date','invoiceDate'],['Due Date','dueDate'],['Payment Terms','paymentTerms'],['Custom Terms Days','customTermsDays'],['Customer PO Reference','customerReference']] as [$label,$field]){if(trim((string)($row[$field]??''))!==trim((string)($first[$field]??''))){di_add_issue($issues,$n,$ref,$label,'Every line in one invoice group must use the same '.$label.'.',$row[$field]??'','Use one '.$label.' for the complete group.');$bad=true;}}}
        if($classification==='opening_document_ready'){
            if(count($parts)!==1){foreach($parts as $part)di_add_issue($issues,(int)$part['row'],$ref,'Import Invoice Reference','A pre-start opening invoice is one summarized record, not line detail.',$ref,'Keep one row with Original, Previously Paid, and Outstanding amounts.');$bad=true;}
            if($customer&&abs((int)($maps['partyOpenings']['customer'][(string)$customer['id']]??0))>0){di_add_issue($issues,$firstRow,$ref,'Opening Balance','This customer already has a master opening balance. Detailed opening invoices would duplicate Accounts Receivable.',$customerName,'Use either detailed opening invoices or the customer master opening balance.');$bad=true;}
            if(abs((int)($maps['openingTrialBalanceControls']['1200']??0))>0){di_add_issue($issues,$firstRow,$ref,'Accounts Receivable','Accounts Receivable is already represented by a posted opening trial-balance journal. Detailed opening invoices would count that opening amount twice.',(string)$maps['openingTrialBalanceControls']['1200'],'Remove the AR opening-trial-balance amount or do not import detailed opening invoices.');$bad=true;}
            if($issueDate!==null&&$issueDate>date('Y-m-d')){di_add_issue($issues,$firstRow,$ref,'Invoice Date','A pre-start invoice cannot be future-dated.',$issueDate,'Use the original historical invoice date.');$bad=true;}
            $oErr=null;$pErr=null;$bErr=null;$original=di_money_cents($first['originalAmount']??'',$oErr);$paid=di_money_cents($first['amountPreviouslyPaid']??'',$pErr);$outstanding=di_money_cents($first['outstandingAmount']??'',$bErr);
            if($oErr||$original<=0){di_add_issue($issues,$firstRow,$ref,'Original Amount',$oErr?:'Original Amount must be greater than zero.',$first['originalAmount']??'','Enter the original invoice total.');$bad=true;}if($pErr||$paid<0){di_add_issue($issues,$firstRow,$ref,'Amount Previously Paid',$pErr?:'Amount Previously Paid cannot be negative.',$first['amountPreviouslyPaid']??'','Enter zero or the amount paid before cutover.');$bad=true;}if($bErr||$outstanding<=0){di_add_issue($issues,$firstRow,$ref,'Outstanding Amount',$bErr?:'Outstanding Amount must be greater than zero.',$first['outstandingAmount']??'','Enter the unpaid cutover amount.');$bad=true;}if(!$oErr&&!$pErr&&!$bErr&&$original-$paid!==$outstanding){di_add_issue($issues,$firstRow,$ref,'Outstanding Amount','Original Amount less Amount Previously Paid must equal Outstanding Amount.',$first['outstandingAmount']??'','Correct the three amounts so they reconcile exactly.');$bad=true;}
            if(!$bad&&$customer&&$issueDate&&$dueDate){$openingTotals['originalTotalCents']+=$original;$openingTotals['previouslyPaidCents']+=$paid;$openingTotals['outstandingTotalCents']+=$outstanding;$prepared[]=['row'=>$firstRow,'classification'=>$classification,'input'=>['partyId'=>(string)$customer['id'],'partyName'=>$customerName,'number'=>$number,'documentDate'=>$issueDate,'dueDate'=>$dueDate,'originalAmountCents'=>$original,'previouslyPaidCents'=>$paid,'outstandingAmountCents'=>$outstanding,'reference'=>di_str($first,'customerReference',120),'notes'=>di_str($first,'notes',1000),'importReference'=>$ref]];}
            continue;
        }
        $lines=[];$lineSeen=[];foreach($parts as $part){$row=$part['data'];$n=(int)$part['row'];$lineNo=trim((string)($row['lineNumber']??''));if($lineNo===''||isset($lineSeen[$lineNo])){di_add_issue($issues,$n,$ref,'Line Number',$lineNo===''?'Line Number is required.':'Line Number is duplicated within this invoice.',$lineNo,'Use a unique line number within the invoice.');$bad=true;}$lineSeen[$lineNo]=true;$desc=di_str($row,'description',500);if($desc===''){di_add_issue($issues,$n,$ref,'Description','Description is required.','','Enter a line description.');$bad=true;}$qErr=null;$qty=di_quantity($row['quantity']??1,$qErr);if($qErr){di_add_issue($issues,$n,$ref,'Quantity',$qErr,$row['quantity']??'','Correct the quantity.');$bad=true;}$mErr=null;$price=di_money_cents($row['unitPrice']??'',$mErr);if($mErr||$price<=0){di_add_issue($issues,$n,$ref,'Unit Price',$mErr?:'Unit Price must be greater than zero.',$row['unitPrice']??'','Enter a positive amount.');$bad=true;}$glCode=strtoupper(di_str($row,'incomeGlCode',40));$account=$maps['accounts'][$glCode]??null;if($glCode==='9999'||!$account||!(bool)$account['active']||(string)$account['account_type']!=='income'||(bool)$account['is_control']){di_add_issue($issues,$n,$ref,'Income GL Code','GL Account Code was not found or is not a permitted active income account.',$glCode,'Use an active non-control income GL code.');$bad=true;}$taxErr=null;$tax=di_tax_mode($row['taxTreatment']??'',$taxErr);if($taxErr){di_add_issue($issues,$n,$ref,'Tax Treatment',$taxErr,$row['taxTreatment']??'','Use GST/HST or No Tax.');$bad=true;}$productId=null;$itemCode=di_str($row,'productServiceCode',60);if($itemCode!==''){$item=$maps['productsByCode'][mb_strtolower($itemCode)]??null;if(!$item||!(bool)$item['active']){di_add_issue($issues,$n,$ref,'Product / Service Code','Product/service code was not found or is inactive.',$itemCode,'Use an active Item Code or leave it blank.');$bad=true;}else$productId=(string)$item['id'];}$lines[]=['description'=>$desc,'quantity'=>$qty,'unitPriceCents'=>$price,'taxable'=>$tax['taxable'],'productServiceId'=>$productId,'incomeAccountId'=>$account?(string)$account['id']:null];}
        if(!$bad&&$customer&&$issueDate&&$dueDate)$prepared[]=['row'=>$firstRow,'classification'=>$classification,'input'=>['customerId'=>(string)$customer['id'],'number'=>$number,'issueDate'=>$issueDate,'dueDate'=>$dueDate,'currency'=>(string)$company['currency'],'purchaseOrder'=>di_str($first,'customerReference',80),'message'=>di_str($first,'notes',1000),'lines'=>$lines,'issue'=>false,'importReference'=>$ref]];
    }
    return ['issues'=>$issues,'prepared'=>$prepared,'openingTotals'=>$openingTotals];
}

function di_validate_vendor_documents(array $company,array $rows,array $maps): array
{
    $companyId=(string)$company['id'];$booksStart=trim((string)($company['books_start_date']??''));$issues=[];$prepared=[];$groups=[];$seenNumbers=[];$openingTotals=['originalTotalCents'=>0,'previouslyPaidCents'=>0,'outstandingTotalCents'=>0];
    foreach(array_values($rows) as $i=>$row){if(!is_array($row))continue;$n=$i+2;$ref=di_str($row,'importBillReference',120);if($ref===''){$ref='Row '.$n;di_add_issue($issues,$n,$ref,'Import Bill Reference','Import Bill Reference is required.','','Enter one reference shared by all lines of this vendor invoice.');continue;}$groups[$ref][]=['row'=>$n,'data'=>$row];}
    foreach($groups as $ref=>$parts){$first=$parts[0]['data'];$firstRow=(int)$parts[0]['row'];$bad=false;$vendorName=di_str($first,'vendorName',200);$vendor=$maps['vendors'][mb_strtolower($vendorName)]??null;if(!$vendor||!(bool)$vendor['active']||(string)$vendor['status']==='inactive'){di_add_issue($issues,$firstRow,$ref,'Vendor Name','Vendor not found or inactive.',$vendorName,'Import or activate the vendor first, then use its exact name.');$bad=true;}
        $dup=db()->prepare('SELECT 1 FROM bills WHERE company_id=? AND import_reference=? LIMIT 1');$dup->execute([$companyId,$ref]);if($dup->fetchColumn()){di_add_issue($issues,$firstRow,$ref,'Import Bill Reference','This import reference has already been used.',$ref,'Use a new unique import reference.','duplicate');$bad=true;}
        $billNo=di_str($first,'vendorInvoiceNumber',60)?:$ref;$billKey=mb_strtolower($billNo);if(isset($maps['billNumbers'][$billKey])||isset($seenNumbers[$billKey])){di_add_issue($issues,$firstRow,$ref,'Vendor Invoice Number','That vendor invoice number already exists or is duplicated in this file.',$billNo,'Use the correct unique vendor invoice number.','duplicate');$bad=true;}$seenNumbers[$billKey]=true;
        $dateErr=null;$billDate=di_date_value($first['billDate']??'',true,'Bill Date',$dateErr);if($dateErr){di_add_issue($issues,$firstRow,$ref,'Bill Date',$dateErr,$first['billDate']??'','Use YYYY-MM-DD.');$bad=true;}$classification=($booksStart!==''&&$billDate!==null&&$billDate<$booksStart)?'opening_document_ready':'regular_draft';
        $termErr=null;$terms=di_terms_value($first['paymentTerms']??'', $first['customTermsDays']??'',(int)($vendor['default_terms_days']??30),$termErr);if($termErr){di_add_issue($issues,$firstRow,$ref,'Payment Terms',$termErr,$first['paymentTerms']??'','Correct the payment terms.');$bad=true;}$dueErr=null;$due=di_date_value($first['dueDate']??'',false,'Due Date',$dueErr);if($dueErr){di_add_issue($issues,$firstRow,$ref,'Due Date',$dueErr,$first['dueDate']??'','Use YYYY-MM-DD.');$bad=true;}if($due===null&&$billDate)$due=(new DateTimeImmutable($billDate))->modify('+'.$terms.' days')->format('Y-m-d');if($billDate&&$due&&$due<$billDate){di_add_issue($issues,$firstRow,$ref,'Due Date','Due Date cannot be before Bill Date.',$due,'Correct the due date.');$bad=true;}
        foreach($parts as $part){$row=$part['data'];$n=(int)$part['row'];if(di_str($row,'vendorName',200)!==$vendorName){di_add_issue($issues,$n,$ref,'Vendor Name','Every line in one vendor invoice group must use the same vendor.',$row['vendorName']??'','Use a separate Import Bill Reference for a different vendor.');$bad=true;}foreach([['Vendor Invoice Number','vendorInvoiceNumber'],['Bill Date','billDate'],['Due Date','dueDate'],['Payment Terms','paymentTerms'],['Custom Terms Days','customTermsDays'],['Vendor Reference','reference']] as [$label,$field]){if(trim((string)($row[$field]??''))!==trim((string)($first[$field]??''))){di_add_issue($issues,$n,$ref,$label,'Every line in one vendor invoice group must use the same '.$label.'.',$row[$field]??'','Use one '.$label.' for the complete group.');$bad=true;}}}
        if($classification==='opening_document_ready'){
            if(count($parts)!==1){foreach($parts as $part)di_add_issue($issues,(int)$part['row'],$ref,'Import Bill Reference','A pre-start opening vendor invoice is one summarized record, not line detail.',$ref,'Keep one row with Original, Previously Paid, and Outstanding amounts.');$bad=true;}if($vendor&&abs((int)($maps['partyOpenings']['vendor'][(string)$vendor['id']]??0))>0){di_add_issue($issues,$firstRow,$ref,'Opening Balance','This vendor already has a master opening balance. Detailed opening invoices would duplicate Accounts Payable.',$vendorName,'Use either detailed opening invoices or the vendor master opening balance.');$bad=true;}if(abs((int)($maps['openingTrialBalanceControls']['2050']??0))>0){di_add_issue($issues,$firstRow,$ref,'Accounts Payable','Accounts Payable is already represented by a posted opening trial-balance journal. Detailed opening vendor invoices would count that opening amount twice.',(string)$maps['openingTrialBalanceControls']['2050'],'Remove the AP opening-trial-balance amount or do not import detailed opening vendor invoices.');$bad=true;}if($billDate!==null&&$billDate>date('Y-m-d')){di_add_issue($issues,$firstRow,$ref,'Bill Date','A pre-start vendor invoice cannot be future-dated.',$billDate,'Use the original historical bill date.');$bad=true;}$oErr=null;$pErr=null;$bErr=null;$original=di_money_cents($first['originalAmount']??'',$oErr);$paid=di_money_cents($first['amountPreviouslyPaid']??'',$pErr);$outstanding=di_money_cents($first['outstandingAmount']??'',$bErr);if($oErr||$original<=0){di_add_issue($issues,$firstRow,$ref,'Original Amount',$oErr?:'Original Amount must be greater than zero.',$first['originalAmount']??'','Enter the original vendor invoice total.');$bad=true;}if($pErr||$paid<0){di_add_issue($issues,$firstRow,$ref,'Amount Previously Paid',$pErr?:'Amount Previously Paid cannot be negative.',$first['amountPreviouslyPaid']??'','Enter zero or the amount paid before cutover.');$bad=true;}if($bErr||$outstanding<=0){di_add_issue($issues,$firstRow,$ref,'Outstanding Amount',$bErr?:'Outstanding Amount must be greater than zero.',$first['outstandingAmount']??'','Enter the unpaid cutover amount.');$bad=true;}if(!$oErr&&!$pErr&&!$bErr&&$original-$paid!==$outstanding){di_add_issue($issues,$firstRow,$ref,'Outstanding Amount','Original Amount less Amount Previously Paid must equal Outstanding Amount.',$first['outstandingAmount']??'','Correct the three amounts so they reconcile exactly.');$bad=true;}if(!$bad&&$vendor&&$billDate&&$due){$openingTotals['originalTotalCents']+=$original;$openingTotals['previouslyPaidCents']+=$paid;$openingTotals['outstandingTotalCents']+=$outstanding;$prepared[]=['row'=>$firstRow,'classification'=>$classification,'input'=>['partyId'=>(string)$vendor['id'],'partyName'=>$vendorName,'number'=>$billNo,'documentDate'=>$billDate,'dueDate'=>$due,'originalAmountCents'=>$original,'previouslyPaidCents'=>$paid,'outstandingAmountCents'=>$outstanding,'reference'=>di_str($first,'reference',120),'notes'=>di_str($first,'notes',1000),'importReference'=>$ref]];}continue;
        }
        $total=0;$categoryId=null;$taxMode=null;$taxable=false;$productId=null;$descriptions=[];foreach($parts as $part){$row=$part['data'];$n=(int)$part['row'];$desc=di_str($row,'description',500);if($desc===''){di_add_issue($issues,$n,$ref,'Description','Description is required.','','Enter a line description.');$bad=true;}$descriptions[]=$desc;$glCode=strtoupper(di_str($row,'expenseAssetGlCode',40));$account=$maps['accounts'][$glCode]??null;if($glCode==='9999'||!$account||!(bool)$account['active']||!in_array((string)$account['account_type'],['expense','asset'],true)||(bool)$account['is_control']){di_add_issue($issues,$n,$ref,'Expense / Asset GL Code','GL Account Code was not found or is not a permitted active expense/asset account.',$glCode,'Use an active non-control expense or asset GL code.');$bad=true;}elseif($categoryId!==null&&$categoryId!==(string)$account['id']){di_add_issue($issues,$n,$ref,'Expense / Asset GL Code','The current vendor-invoice register stores one posting account per draft.',$glCode,'Use one GL account for this grouped import, or enter the multi-account bill manually.');$bad=true;}else$categoryId=(string)$account['id'];$qErr=null;$qty=di_quantity($row['quantity']??1,$qErr);if($qErr){di_add_issue($issues,$n,$ref,'Quantity',$qErr,$row['quantity']??'','Correct the quantity.');$bad=true;}$mErr=null;$unit=di_money_cents($row['unitPriceAmount']??'',$mErr);if($mErr||$unit<=0){di_add_issue($issues,$n,$ref,'Unit Price / Amount',$mErr?:'Amount must be greater than zero.',$row['unitPriceAmount']??'','Enter a positive amount.');$bad=true;}$total+=(int)round($qty*$unit);$taxErr=null;$tax=di_tax_mode($row['taxTreatment']??'',$taxErr);if($taxErr){di_add_issue($issues,$n,$ref,'Tax Treatment',$taxErr,$row['taxTreatment']??'','Use GST/HST, GST/HST Inclusive, or No Tax.');$bad=true;}elseif($taxMode!==null&&$taxMode!==$tax['mode']){di_add_issue($issues,$n,$ref,'Tax Treatment','Every line in this grouped vendor invoice must use the same tax treatment.',$row['taxTreatment']??'','Use one tax treatment for the group, or enter it manually.');$bad=true;}else{$taxMode=$tax['mode'];$taxable=$tax['taxable'];}$itemCode=di_str($row,'productServiceCode',60);if(count($parts)===1&&$itemCode!==''){$item=$maps['productsByCode'][mb_strtolower($itemCode)]??null;if(!$item||!(bool)$item['active']){di_add_issue($issues,$n,$ref,'Product / Service Code','Product/service code was not found or is inactive.',$itemCode,'Use an active Item Code or leave it blank.');$bad=true;}else$productId=(string)$item['id'];}}
        if(!$bad&&$vendor&&$billDate&&$due)$prepared[]=['row'=>$firstRow,'classification'=>$classification,'input'=>['vendorId'=>(string)$vendor['id'],'number'=>$billNo,'billDate'=>$billDate,'dueDate'=>$due,'paymentTermsDays'=>$terms,'categoryAccountId'=>$categoryId,'currency'=>(string)($vendor['default_currency']?:$company['currency']),'productServiceId'=>$productId,'quantity'=>1,'foreignAmountCents'=>$total,'taxEntryMode'=>$taxMode?:'none','applyGstHst'=>$taxable,'applyPst'=>false,'memo'=>mb_substr(implode(' · ',array_filter($descriptions)).(di_str($first,'notes',500)!==''?' · '.di_str($first,'notes',500):''),0,500),'issue'=>false,'importReference'=>$ref]];
    }
    return ['issues'=>$issues,'prepared'=>$prepared,'openingTotals'=>$openingTotals];
}

/* R130: Tegh never stores Social Insurance Numbers. Any SIN column value, or
   any field that looks like a SIN, is replaced before rows are validated or
   saved as an import preview; the row is then reported as needing correction. */
function di_strip_sins(array $rows): array
{
    foreach($rows as $i=>$row){
        if(!is_array($row))continue;$found=[];
        foreach($row as $key=>$value){
            if(!is_scalar($value))continue;$text=trim((string)$value);if($text==='')continue;
            $isSinKey=preg_match('/^(sin|sinlastfour|sin_last_four|socialinsurancenumber|social_insurance_number|social insurance number)$/i',(string)$key)===1;
            if($isSinKey||(function_exists('payroll_looks_like_sin')&&payroll_looks_like_sin($text))){$row[$key]='';$found[]=(string)$key;}
        }
        if($found){$row['__sinRemoved']=$found;$rows[$i]=$row;}
    }
    return $rows;
}

function di_validate_rows(array $company,string $type,array $rows): array
{
    $companyId=(string)$company['id'];$maps=di_lookup_maps($companyId);$issues=[];$prepared=[];$seen=[];$warnings=0;$duplicates=0;
    if(count($rows)<1)fail('The import file did not contain any data rows.',422,'import_empty');
    $limits=['customers'=>1000,'vendors'=>1000,'products_services'=>2000,'employees'=>1000,'customer_invoices'=>2000,'vendor_invoices'=>2000,'chart_of_accounts'=>1000,'opening_balances'=>1000,'coa_opening_balances'=>1000,'opening_customer_invoices'=>2000,'opening_vendor_bills'=>2000];
    if(count($rows)>$limits[$type])fail('This import contains too many rows for one preview.',422,'import_row_limit');

    if($type==='customers'){
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$name=di_str($row,'customerName',160);$ref=$name!==''?$name:'Row '.$n;$bad=false;
            if($name===''){di_add_issue($issues,$n,$ref,'Customer Name','Customer Name is required.','','Enter a customer name.');$bad=true;}
            $key=mb_strtolower($name);if($name!==''&&(isset($maps['customers'][$key])||isset($seen[$key]))){di_add_issue($issues,$n,$ref,'Customer Name','Customer already exists or is duplicated in this file.',$name,'Use a unique customer name or remove the duplicate.','duplicate');$bad=true;$duplicates++;}$seen[$key]=true;
            $email=di_str($row,'email',254);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){di_add_issue($issues,$n,$ref,'Email','Email format is invalid.',$email,'Correct the email address.');$bad=true;}
            $province=strtoupper(di_str($row,'province',2));if(!in_array($province,tegh_province_codes(),true)){di_add_issue($issues,$n,$ref,'Province','Province/territory is invalid.',$province,'Use a Canadian two-letter province or territory code.');$bad=true;}
            $termErr=null;$terms=di_terms_value($row['paymentTerms']??'', $row['customTermsDays']??'',30,$termErr);if($termErr){di_add_issue($issues,$n,$ref,'Payment Terms',$termErr,$row['paymentTerms']??'','Correct the payment terms.');$bad=true;}
            $moneyErr=null;$opening=di_money_cents($row['openingBalance']??'',$moneyErr);if($moneyErr){di_add_issue($issues,$n,$ref,'Opening Balance',$moneyErr,$row['openingBalance']??'','Enter a valid dollar amount.');$bad=true;}
            $dateErr=null;$openingDate=di_date_value($row['openingBalanceDate']??'',false,'Opening Balance Date',$dateErr);if($opening!==0&&$openingDate===null){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr?:'Opening Balance Date is required for a non-zero opening balance.',$row['openingBalanceDate']??'','Enter YYYY-MM-DD.');$bad=true;}elseif($dateErr){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr,$row['openingBalanceDate']??'','Enter YYYY-MM-DD.');$bad=true;}
            $statusErr=null;$status=di_status_value($row['status']??'',true,$statusErr);if($statusErr){di_add_issue($issues,$n,$ref,'Status',$statusErr,$row['status']??'','Use Active, On Hold, or Inactive.');$bad=true;}
            $hold=di_str($row,'holdRemarks',1000);if($status==='on_hold'&&$hold===''){di_add_issue($issues,$n,$ref,'Hold Remarks','Hold Remarks are required when Status is On Hold.','','Enter the reason for the hold.');$bad=true;}
            if(!$bad)$prepared[]=['row'=>$n,'input'=>['name'=>$name,'contactName'=>di_str($row,'contactName',160),'email'=>$email,'phone'=>di_str($row,'phone',60),'addressLine1'=>di_str($row,'addressLine1',180),'addressLine2'=>di_str($row,'addressLine2',180),'city'=>di_str($row,'city',100),'province'=>$province,'postalCode'=>di_str($row,'postalCode',20),'country'=>di_str($row,'country',80)?:'Canada','defaultTermsDays'=>$terms,'notes'=>di_str($row,'notes',2000),'status'=>$status,'holdRemarks'=>$hold,'openingBalanceCents'=>$opening,'openingBalanceDate'=>$openingDate]];
        }
    }elseif($type==='vendors'){
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$name=di_str($row,'vendorName',200);$ref=$name!==''?$name:'Row '.$n;$bad=false;
            if($name===''){di_add_issue($issues,$n,$ref,'Vendor Name','Vendor Name is required.','','Enter a vendor name.');$bad=true;}
            $key=mb_strtolower($name);if($name!==''&&(isset($maps['vendors'][$key])||isset($seen[$key]))){di_add_issue($issues,$n,$ref,'Vendor Name','Vendor already exists or is duplicated in this file.',$name,'Use a unique vendor name or remove the duplicate.','duplicate');$bad=true;$duplicates++;}$seen[$key]=true;
            $email=di_str($row,'email',254);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){di_add_issue($issues,$n,$ref,'Email','Email format is invalid.',$email,'Correct the email address.');$bad=true;}
            $province=strtoupper(di_str($row,'province',2));if($province!==''&&!in_array($province,tegh_province_codes(),true)){di_add_issue($issues,$n,$ref,'Province','Province/territory is invalid.',$province,'Use a Canadian two-letter province or territory code.');$bad=true;}
            $termErr=null;$terms=di_terms_value($row['paymentTerms']??'', $row['customTermsDays']??'',30,$termErr);if($termErr){di_add_issue($issues,$n,$ref,'Payment Terms',$termErr,$row['paymentTerms']??'','Correct the payment terms.');$bad=true;}
            $moneyErr=null;$opening=di_money_cents($row['openingBalance']??'',$moneyErr);if($moneyErr){di_add_issue($issues,$n,$ref,'Opening Balance',$moneyErr,$row['openingBalance']??'','Enter a valid dollar amount.');$bad=true;}
            $dateErr=null;$openingDate=di_date_value($row['openingBalanceDate']??'',false,'Opening Balance Date',$dateErr);if($opening!==0&&$openingDate===null){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr?:'Opening Balance Date is required for a non-zero opening balance.',$row['openingBalanceDate']??'','Enter YYYY-MM-DD.');$bad=true;}elseif($dateErr){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr,$row['openingBalanceDate']??'','Enter YYYY-MM-DD.');$bad=true;}
            $statusErr=null;$status=di_status_value($row['status']??'',false,$statusErr);if($statusErr){di_add_issue($issues,$n,$ref,'Status',$statusErr,$row['status']??'','Use Active or Inactive.');$bad=true;}
            $accountId=null;$accountCode=strtoupper(di_str($row,'defaultExpenseAccountCode',40));if($accountCode!==''){$a=$maps['accounts'][$accountCode]??null;if($accountCode==='9999'){di_add_issue($issues,$n,$ref,'Default Expense Account Code','Opening Balance Control account 9999 cannot be used for this transaction.',$accountCode,'Choose a permitted expense or asset account.');$bad=true;}elseif(!$a||!(bool)$a['active']||!in_array((string)$a['account_type'],['expense','asset'],true)||(bool)$a['is_control']){di_add_issue($issues,$n,$ref,'Default Expense Account Code','GL account code is not a valid active non-control expense or asset account.',$accountCode,'Use an existing permitted current-company GL code.');$bad=true;}else$accountId=(string)$a['id'];}
            if(!$bad)$prepared[]=['row'=>$n,'input'=>['name'=>$name,'contactName'=>di_str($row,'contactName',160),'email'=>$email,'phone'=>di_str($row,'phone',60),'addressLine1'=>di_str($row,'addressLine1',180),'addressLine2'=>di_str($row,'addressLine2',180),'city'=>di_str($row,'city',100),'province'=>$province,'postalCode'=>di_str($row,'postalCode',20),'country'=>di_str($row,'country',80)?:'Canada','defaultTermsDays'=>$terms,'defaultExpenseAccountId'=>$accountId,'currency'=>strtoupper(di_str($row,'currency',3))?:strtoupper((string)$company['currency']),'notes'=>di_str($row,'notes',2000),'status'=>$status,'openingBalanceCents'=>$opening,'openingBalanceDate'=>$openingDate]];
        }
    }elseif($type==='products_services'){
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$name=di_str($row,'name',180);$code=di_str($row,'itemCode',60);$ref=$code!==''?$code:($name?:'Row '.$n);$bad=false;$kind=strtolower(di_str($row,'type',20)?:'service');if(!in_array($kind,['product','service'],true)){di_add_issue($issues,$n,$ref,'Type','Type must be Product or Service.',$row['type']??'','Use Product or Service.');$bad=true;}
            if($name===''){di_add_issue($issues,$n,$ref,'Name','Name is required.','','Enter a product/service name.');$bad=true;}
            $nameKey=mb_strtolower($name);$codeKey=mb_strtolower($code);if($name!==''&&(isset($maps['productsByName'][$nameKey])||isset($seen['name:'.$nameKey]))){di_add_issue($issues,$n,$ref,'Name','Product/service name already exists or is duplicated in this file.',$name,'Use a unique name.','duplicate');$bad=true;$duplicates++;}$seen['name:'.$nameKey]=true;
            if($code!==''&&(isset($maps['productsByCode'][$codeKey])||isset($seen['code:'.$codeKey]))){di_add_issue($issues,$n,$ref,'Item Code','Item Code already exists or is duplicated in this file.',$code,'Use a unique Item Code.','duplicate');$bad=true;$duplicates++;}$seen['code:'.$codeKey]=true;
            $moneyErr=null;$price=di_money_cents($row['salesPrice']??'',$moneyErr);if($moneyErr||$price<0){di_add_issue($issues,$n,$ref,'Sales Price',$moneyErr?:'Sales Price cannot be negative.',$row['salesPrice']??'','Enter a valid non-negative amount.');$bad=true;}
            $accountCode=strtoupper(di_str($row,'incomeGlCode',40));$accountId=null;if($accountCode!==''){$a=$maps['accounts'][$accountCode]??null;if($accountCode==='9999'){di_add_issue($issues,$n,$ref,'Income GL Code','Opening Balance Control account 9999 cannot be used for this transaction.',$accountCode,'Choose an active income account.');$bad=true;}elseif(!$a||!(bool)$a['active']||(string)$a['account_type']!=='income'||(bool)$a['is_control']){di_add_issue($issues,$n,$ref,'Income GL Code','Income GL code is not a valid active non-control income account.',$accountCode,'Use an existing current-company income GL code.');$bad=true;}else$accountId=(string)$a['id'];}
            $boolErr=null;$taxable=di_bool($row['taxable']??'',true,$boolErr);if($boolErr){di_add_issue($issues,$n,$ref,'Taxable',$boolErr,$row['taxable']??'','Use Yes or No.');$bad=true;}$activeErr=null;$active=di_bool($row['active']??'',true,$activeErr);if($activeErr){di_add_issue($issues,$n,$ref,'Active',$activeErr,$row['active']??'','Use Yes or No.');$bad=true;}
            if(!$bad)$prepared[]=['row'=>$n,'input'=>['code'=>$code!==''?$code:null,'name'=>$name,'description'=>di_str($row,'description',500),'kind'=>$kind,'unitPriceCents'=>$price,'incomeAccountId'=>$accountId,'taxable'=>$taxable,'active'=>$active]];
        }
    }elseif($type==='employees'){
        $payrollReady=payroll_settings_row($companyId)!==null;
        if(!$payrollReady)di_add_issue($issues,2,'Payroll Setup','Payroll','Enable payroll before importing employees.','','Complete Payroll Setup, then validate this file again.');
        $allowedProvinces=['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','SK','YT'];
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$number=di_str($row,'employeeNumber',30);$first=di_str($row,'firstName',100);$last=di_str($row,'lastName',100);$ref=$number!==''?$number:($first.' '.$last?:'Row '.$n);$bad=!$payrollReady;
            if($number===''){di_add_issue($issues,$n,$ref,'Employee Number','Employee Number is required.','','Enter a unique employee number.');$bad=true;}
            // R130: Tegh never stores Social Insurance Numbers.
            if(!empty($row['__sinRemoved'])){di_add_issue($issues,$n,$ref,'SIN','A Social Insurance Number (SIN) was found and removed. Tegh does not store SINs.','','Remove SINs from the file and use an Employee ID such as EMP-0001.');$bad=true;}else{$key=mb_strtolower($number);if(isset($maps['employeeNumbers'][$key])||isset($seen[$key])){di_add_issue($issues,$n,$ref,'Employee Number','Employee Number already exists or is duplicated in this file.',$number,'Use a unique employee number.','duplicate');$duplicates++;$bad=true;}$seen[$key]=true;}
            if($first===''){di_add_issue($issues,$n,$ref,'First Name','First Name is required.','','Enter the employee first name.');$bad=true;}if($last===''){di_add_issue($issues,$n,$ref,'Last Name','Last Name is required.','','Enter the employee last name.');$bad=true;}
            $email=di_str($row,'email',254);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)){di_add_issue($issues,$n,$ref,'Email','Email format is invalid.',$email,'Correct the email address.');$bad=true;}
            $employment=strtoupper(di_str($row,'provinceOfEmployment',2)?:((string)$company['province']));$residence=strtoupper(di_str($row,'provinceOfResidence',2)?:$employment);foreach([['Province of Employment',$employment],['Province of Residence',$residence]] as [$label,$province]){if($province==='QC'){di_add_issue($issues,$n,$ref,$label,'Quebec payroll is not supported in this release.',$province,'Use a supported province or manage Quebec payroll outside Tegh.');$bad=true;}elseif(!in_array($province,$allowedProvinces,true)){di_add_issue($issues,$n,$ref,$label,'Province is invalid.',$province,'Use a supported Canadian two-letter code.');$bad=true;}}
            $dobErr=null;$dob=di_date_value($row['dateOfBirth']??'',false,'Date of Birth',$dobErr);if($dobErr){di_add_issue($issues,$n,$ref,'Date of Birth',$dobErr,$row['dateOfBirth']??'','Use YYYY-MM-DD.');$bad=true;}
            $hireErr=null;$hire=di_date_value($row['hireDate']??'',true,'Hire Date',$hireErr);if($hireErr){di_add_issue($issues,$n,$ref,'Hire Date',$hireErr,$row['hireDate']??'','Use YYYY-MM-DD.');$bad=true;}
            $terminationErr=null;$termination=di_date_value($row['terminationDate']??'',false,'Termination Date',$terminationErr);if($terminationErr){di_add_issue($issues,$n,$ref,'Termination Date',$terminationErr,$row['terminationDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($hire&&$termination&&$termination<$hire){di_add_issue($issues,$n,$ref,'Termination Date','Termination Date cannot precede Hire Date.',$termination,'Correct the employment dates.');$bad=true;}
            $frequency=strtolower(di_str($row,'payFrequency',20)?:'biweekly');if(!in_array($frequency,['weekly','biweekly','semimonthly','monthly'],true)){di_add_issue($issues,$n,$ref,'Pay Frequency','Pay Frequency is invalid.',$row['payFrequency']??'','Use Weekly, Biweekly, Semimonthly, or Monthly.');$bad=true;}
            $payType=strtolower(di_str($row,'payType',20)?:'salary');if(!in_array($payType,['salary','hourly'],true)){di_add_issue($issues,$n,$ref,'Pay Type','Pay Type is invalid.',$row['payType']??'','Use Salary or Hourly.');$bad=true;}
            $salaryErr=null;$hourlyErr=null;$salary=di_money_cents($row['annualSalary']??'',$salaryErr);$hourly=di_money_cents($row['hourlyRate']??'',$hourlyErr);if($salaryErr||$salary<0){di_add_issue($issues,$n,$ref,'Annual Salary',$salaryErr?:'Annual Salary cannot be negative.',$row['annualSalary']??'','Enter a valid non-negative amount.');$bad=true;}if($hourlyErr||$hourly<0){di_add_issue($issues,$n,$ref,'Hourly Rate',$hourlyErr?:'Hourly Rate cannot be negative.',$row['hourlyRate']??'','Enter a valid non-negative amount.');$bad=true;}if(($payType==='salary'&&$salary<=0)||($payType==='hourly'&&$hourly<=0)){di_add_issue($issues,$n,$ref,$payType==='salary'?'Annual Salary':'Hourly Rate','Enter compensation for the selected Pay Type.','',$payType==='salary'?'Enter a positive annual salary.':'Enter a positive hourly rate.');$bad=true;}
            $hoursRaw=trim((string)($row['standardHours']??''));$hours=$hoursRaw===''?($payType==='hourly'?80.0:0.0):(is_numeric($hoursRaw)?(float)$hoursRaw:-1.0);if(!is_finite($hours)||$hours<0||$hours>744){di_add_issue($issues,$n,$ref,'Standard Hours','Standard Hours must be between 0 and 744.',$hoursRaw,'Enter the normal hours for one pay period.');$bad=true;}
            $vacationRaw=trim((string)($row['vacationRatePercent']??''));$vacation=$vacationRaw===''?400:(is_numeric(rtrim($vacationRaw," %"))?(int)round((float)rtrim($vacationRaw," %")*100):-1);if($vacation<0||$vacation>2000){di_add_issue($issues,$n,$ref,'Vacation Rate','Vacation Rate must be between 0% and 20%.',$vacationRaw,'Enter a percentage such as 4.00.');$bad=true;}
            $vacPayErr=null;$vacPay=di_bool($row['vacationPaidEachPay']??'',false,$vacPayErr);if($vacPayErr){di_add_issue($issues,$n,$ref,'Vacation Paid Each Pay',$vacPayErr,$row['vacationPaidEachPay']??'','Use Yes or No.');$bad=true;}
            $cppErr=null;$cppExempt=di_bool($row['cppExempt']??'',false,$cppErr);$eiErr=null;$eiExempt=di_bool($row['eiExempt']??'',false,$eiErr);$activeErr=null;$active=di_bool($row['active']??'',true,$activeErr);foreach([['CPP Exempt',$cppErr,$row['cppExempt']??''],['EI Exempt',$eiErr,$row['eiExempt']??''],['Active',$activeErr,$row['active']??'']] as [$label,$err,$supplied])if($err){di_add_issue($issues,$n,$ref,$label,$err,$supplied,'Use Yes or No.');$bad=true;}
            $fErr=null;$pErr=null;$aErr=null;$federal=di_money_cents($row['federalTd1']??'',$fErr);$provincial=di_money_cents($row['provincialTd1']??'',$pErr);$additional=di_money_cents($row['additionalTax']??'',$aErr);foreach([['Federal TD1',$fErr,$federal,$row['federalTd1']??''],['Provincial TD1',$pErr,$provincial,$row['provincialTd1']??''],['Additional Tax',$aErr,$additional,$row['additionalTax']??'']] as [$label,$err,$amount,$supplied])if($err||$amount<0){di_add_issue($issues,$n,$ref,$label,$err?:$label.' cannot be negative.',$supplied,'Enter a valid non-negative amount.');$bad=true;}
            if(!$bad)$prepared[]=['row'=>$n,'input'=>['employeeNumber'=>$number,'firstName'=>$first,'lastName'=>$last,'email'=>$email,'provinceOfEmployment'=>$employment,'provinceOfResidence'=>$residence,'dateOfBirth'=>$dob,'hireDate'=>$hire,'terminationDate'=>$termination,'payFrequency'=>$frequency,'payType'=>$payType,'annualSalaryCents'=>$salary,'hourlyRateCents'=>$hourly,'standardHours'=>$hours,'vacationRateBps'=>$vacation,'vacationPaidEachPay'=>$vacPay,'federalTd1Cents'=>$federal?:null,'provincialTd1Cents'=>$provincial?:null,'additionalTaxCents'=>$additional,'cppExempt'=>$cppExempt,'eiExempt'=>$eiExempt,'active'=>$active]];
        }
    }elseif($type==='chart_of_accounts'){
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$code=strtoupper(di_str($row,'accountCode',20));$name=di_str($row,'accountName',160);$ref=$code?:'Row '.$n;$bad=false;
            if($code===''){di_add_issue($issues,$n,$ref,'Account Code','Account Code is required.','','Enter a unique account code.');$bad=true;}
            elseif(!preg_match('/^[A-Z0-9.-]+$/',$code)){di_add_issue($issues,$n,$ref,'Account Code','Account Code may contain letters, numbers, dots, and hyphens only.',$code,'Correct the account code.');$bad=true;}
            elseif($code==='9999'){di_add_issue($issues,$n,$ref,'Account Code','Account code 9999 is reserved for Tegh Opening Balance Control.',$code,'Remove this row; Tegh manages the system control account.');$bad=true;}
            elseif(isset($seen[$code])){di_add_issue($issues,$n,$ref,'Account Code','Account Code is duplicated in this file.',$code,'Keep one row for each account code.','duplicate');$bad=true;$duplicates++;}
            elseif(isset($maps['accounts'][$code])){di_add_issue($issues,$n,$ref,'Account Code','That account code already exists in the current company.',$code,'Remove the row or use the combined importer to match existing accounts.','duplicate');$bad=true;$duplicates++;}
            $seen[$code]=true;
            if($name===''){di_add_issue($issues,$n,$ref,'Account Name','Account Name is required.','','Enter an account name.');$bad=true;}
            $typeValue=strtolower(di_str($row,'accountType',20));if(!in_array($typeValue,['asset','liability','equity','income','expense'],true)){di_add_issue($issues,$n,$ref,'Account Type','Account Type is not supported.',$row['accountType']??'','Use Asset, Liability, Equity, Income, or Expense.');$bad=true;}
            $activeErr=null;$active=di_bool($row['activeStatus']??'',true,$activeErr);if($activeErr){di_add_issue($issues,$n,$ref,'Active Status','Active Status is invalid.',$row['activeStatus']??'','Use Active/Inactive or Yes/No.');$bad=true;}
            if(!$bad)$prepared[]=['row'=>$n,'classification'=>'new_account','input'=>['code'=>$code,'name'=>$name,'description'=>di_str($row,'description',500),'type'=>$typeValue,'active'=>$active]];
        }
    }elseif($type==='opening_balances'){
        $commonDate=null;
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$code=strtoupper(di_str($row,'accountCode',20));$ref=$code?:'Row '.$n;$bad=false;
            if($code===''){di_add_issue($issues,$n,$ref,'Account Code','Account Code is required.','','Enter an existing current-company account code.');$bad=true;}
            elseif(isset($seen[$code])){di_add_issue($issues,$n,$ref,'Account Code','Opening balance account is duplicated in this file.',$code,'Combine the amount into one row for this account.','duplicate');$bad=true;$duplicates++;}$seen[$code]=true;
            $account=$maps['accounts'][$code]??null;if($code==='9999'){di_add_issue($issues,$n,$ref,'Account Code','Opening Balance Control account 9999 cannot be supplied directly.',$code,'Remove this row; Tegh calculates any balancing offset.');$bad=true;}
            elseif(!$account){di_add_issue($issues,$n,$ref,'Account Code','Account Code was not found in the current company.',$code,'Use an exact current-company account code.');$bad=true;}
            elseif($account){[$allowed,$why]=tegh_opening_balance_account_allowed($companyId,$account);if(!$allowed){di_add_issue($issues,$n,$ref,'Account Code',$why,$code,'Use the correct source-module opening-balance import or a permitted account.');$bad=true;}}
            $dateErr=null;$date=di_date_value($row['openingBalanceDate']??'',true,'Opening Balance Date',$dateErr);if($dateErr){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr,$row['openingBalanceDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($date!==null){if($date>date('Y-m-d')){di_add_issue($issues,$n,$ref,'Opening Balance Date','Opening Balance Date cannot be in the future.',$date,'Use today or an earlier date.');$bad=true;}if($commonDate===null)$commonDate=$date;elseif($commonDate!==$date){di_add_issue($issues,$n,$ref,'Opening Balance Date','All rows in one opening-balance file must use the same date.',$date,'Use '.$commonDate.' on every row.');$bad=true;}}
            $dErr=null;$cErr=null;$debit=di_money_cents($row['debit']??'',$dErr);$credit=di_money_cents($row['credit']??'',$cErr);if($dErr||$debit<0){di_add_issue($issues,$n,$ref,'Debit',$dErr?:'Debit cannot be negative.',$row['debit']??'','Enter a non-negative amount.');$bad=true;}if($cErr||$credit<0){di_add_issue($issues,$n,$ref,'Credit',$cErr?:'Credit cannot be negative.',$row['credit']??'','Enter a non-negative amount.');$bad=true;}if($debit>0&&$credit>0){di_add_issue($issues,$n,$ref,'Debit / Credit','A row cannot contain both a Debit and a Credit.','','Enter an amount on only one side.');$bad=true;}elseif($debit===0&&$credit===0){di_add_issue($issues,$n,$ref,'Debit / Credit','Enter a Debit or Credit amount.','','Enter one positive amount.');$bad=true;}
            if(!$bad&&$account&&$date)$prepared[]=['row'=>$n,'classification'=>'opening_balance_ready','input'=>['accountId'=>(string)$account['id'],'accountCode'=>$code,'date'=>$date,'debitCents'=>$debit,'creditCents'=>$credit,'memo'=>di_str($row,'memoDescription',500)]];
        }
        $control=opening_balance_control_account($companyId,false);if(!$control){di_add_issue($issues,2,'Opening Balance Control','Account 9999','Opening Balance Control account 9999 is not safely configured for this company.','','Resolve the 9999 configuration conflict before posting opening balances.');}
    }elseif($type==='coa_opening_balances'){
        $commonDate=null;$matched=0;$newAccounts=0;$balanceReady=0;
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$code=strtoupper(di_str($row,'accountCode',20));$ref=$code?:'Row '.$n;$bad=false;
            if($code===''){di_add_issue($issues,$n,$ref,'Account Code','Account Code is required.','','Enter an account code.');$bad=true;}
            elseif(!preg_match('/^[A-Z0-9.-]+$/',$code)){di_add_issue($issues,$n,$ref,'Account Code','Account Code may contain letters, numbers, dots, and hyphens only.',$code,'Correct the account code.');$bad=true;}
            elseif($code==='9999'){di_add_issue($issues,$n,$ref,'Account Code','Account code 9999 is reserved for Tegh Opening Balance Control.',$code,'Remove this row; Tegh manages the control account.');$bad=true;}
            elseif(isset($seen[$code])){di_add_issue($issues,$n,$ref,'Account Code','Account Code is duplicated in this file.',$code,'Keep one row for each account code.','duplicate');$bad=true;$duplicates++;}$seen[$code]=true;
            $existing=$maps['accounts'][$code]??null;$name=di_str($row,'accountName',160);$typeValue=strtolower(di_str($row,'accountType',20));$desc=di_str($row,'description',500);$activeErr=null;$active=di_bool($row['activeStatus']??'',true,$activeErr);
            if($existing){
                $matched++;
                if($activeErr){di_add_issue($issues,$n,$ref,'Active Status','Active Status is invalid.',$row['activeStatus']??'','Use Active/Inactive or Yes/No.');$bad=true;}
                if($name!==''&&$name!==(string)$existing['name']){di_add_issue($issues,$n,$ref,'Account Name','Existing account matched by code; its name will not be overwritten.',$name,'Leave the field blank or accept the existing name.','warning');$warnings++;}
                if($typeValue!==''&&$typeValue!==(string)$existing['account_type']){di_add_issue($issues,$n,$ref,'Account Type','Existing account matched by code; its type will not be overwritten.',$typeValue,'Leave the field blank or use the existing type.','warning');$warnings++;}
                if($desc!==''&&$desc!==trim((string)($existing['description']??''))){di_add_issue($issues,$n,$ref,'Description','Existing account matched by code; its description will not be overwritten.',$desc,'Leave the field blank or keep the existing description.','warning');$warnings++;}
                $activeRaw=trim((string)($row['activeStatus']??''));if(!$activeErr&&$activeRaw!==''&&$active!==(bool)$existing['active']){di_add_issue($issues,$n,$ref,'Active Status','Existing account matched by code; its active status will not be overwritten.',$activeRaw,'Leave the field blank or keep the existing status.','warning');$warnings++;}
            }
            else{$newAccounts++;if($name===''){di_add_issue($issues,$n,$ref,'Account Name','Account Name is required for a new account.','','Enter an account name.');$bad=true;}if(!in_array($typeValue,['asset','liability','equity','income','expense'],true)){di_add_issue($issues,$n,$ref,'Account Type','A supported Account Type is required for a new account.',$row['accountType']??'','Use Asset, Liability, Equity, Income, or Expense.');$bad=true;}if($activeErr){di_add_issue($issues,$n,$ref,'Active Status','Active Status is invalid.',$row['activeStatus']??'','Use Active/Inactive or Yes/No.');$bad=true;}}
            $dateRaw=trim((string)($row['openingBalanceDate']??''));$dErr=null;$cErr=null;$debit=di_money_cents($row['debit']??'',$dErr);$credit=di_money_cents($row['credit']??'',$cErr);$hasBalance=($debit!==0||$credit!==0||trim((string)($row['debit']??''))!==''||trim((string)($row['credit']??''))!=='');$date=null;
            if($dErr||$debit<0){di_add_issue($issues,$n,$ref,'Debit',$dErr?:'Debit cannot be negative.',$row['debit']??'','Enter a non-negative amount.');$bad=true;}if($cErr||$credit<0){di_add_issue($issues,$n,$ref,'Credit',$cErr?:'Credit cannot be negative.',$row['credit']??'','Enter a non-negative amount.');$bad=true;}if($debit>0&&$credit>0){di_add_issue($issues,$n,$ref,'Debit / Credit','A row cannot contain both Debit and Credit.','','Enter one side only.');$bad=true;}
            if($hasBalance&&($debit>0||$credit>0)){if(!$existing&&!$active){di_add_issue($issues,$n,$ref,'Active Status','An inactive new account cannot receive an opening balance.',$row['activeStatus']??'Inactive','Create it Active or leave the balance blank/zero.');$bad=true;}$dateErr=null;$date=di_date_value($dateRaw,true,'Opening Balance Date',$dateErr);if($dateErr){di_add_issue($issues,$n,$ref,'Opening Balance Date',$dateErr,$dateRaw,'Use YYYY-MM-DD.');$bad=true;}elseif($date>date('Y-m-d')){di_add_issue($issues,$n,$ref,'Opening Balance Date','Opening Balance Date cannot be in the future.',$date,'Use today or an earlier date.');$bad=true;}else{if($commonDate===null)$commonDate=$date;elseif($commonDate!==$date){di_add_issue($issues,$n,$ref,'Opening Balance Date','All non-zero balance rows must use the same date.',$date,'Use '.$commonDate.' on every balance row.');$bad=true;}}if(in_array($code,['1200','2050'],true)){di_add_issue($issues,$n,$ref,'Account Code',$code==='1200'?'Accounts Receivable opening balances must be imported by customer.':'Accounts Payable opening balances must be imported by vendor.',$code,'Use Customer or Vendor import opening balances.');$bad=true;}if($existing){[$allowed,$why]=tegh_opening_balance_account_allowed($companyId,$existing);if(!$allowed){di_add_issue($issues,$n,$ref,'Account Code',$why,$code,'Use the appropriate source-module opening balance.');$bad=true;}}$balanceReady++;}
            if(!$bad)$prepared[]=['row'=>$n,'classification'=>$existing?'existing_account_matched':'new_account','input'=>['existingAccountId'=>$existing?(string)$existing['id']:null,'code'=>$code,'name'=>$name,'description'=>$desc,'type'=>$typeValue,'active'=>$existing?(bool)$existing['active']:$active,'date'=>$date,'debitCents'=>$debit,'creditCents'=>$credit,'memo'=>di_str($row,'memoDescription',500)]];
        }
        $control=opening_balance_control_account($companyId,false);if(!$control){di_add_issue($issues,2,'Opening Balance Control','Account 9999','Opening Balance Control account 9999 is not safely configured for this company.','','Resolve the 9999 configuration conflict before importing.');}
    }elseif(in_array($type,['opening_customer_invoices','opening_vendor_bills'],true)){
        $customerMode=$type==='opening_customer_invoices';$booksStart=$company['books_start_date']!==null?(string)$company['books_start_date']:'';$today=date('Y-m-d');$originalTotalAll=0;$paidAll=0;$outstandingAll=0;
        if($booksStart==='')di_add_issue($issues,2,'Cutover Setup','Start of Books','Set Start of Books before importing opening documents.','','Open Company Details and set the Tegh Start of Books date.');
        $control=opening_balance_control_account($companyId,false);if(!$control)di_add_issue($issues,2,'Opening Balance Control','Account 9999','Opening Balance Control is not safely configured for this company.','','Resolve the 9999 configuration conflict before importing opening documents.');
        foreach(array_values($rows) as $i=>$row){$n=$i+2;if(!is_array($row))continue;$number=di_str($row,$customerMode?'invoiceNumber':'vendorInvoiceNumber',60);$partyName=di_str($row,$customerMode?'customerName':'vendorName',160);$ref=$number!==''?$number:'Row '.$n;$bad=false;
            if($number===''){di_add_issue($issues,$n,$ref,$customerMode?'Original Invoice Number':'Original Vendor Bill Number','Original document number is required.','','Enter the original unpaid document number.');$bad=true;}
            $numberKey=mb_strtolower($number);$existingMap=$customerMode?$maps['invoiceNumbers']:$maps['billNumbers'];if($number!==''&&(isset($seen[$numberKey])||isset($existingMap[$numberKey]))){di_add_issue($issues,$n,$ref,'Document Number','That document number already exists or is duplicated in this file.',$number,'Use the original unique document number once.','duplicate');$duplicates++;$bad=true;}$seen[$numberKey]=true;
            $partyKey=mb_strtolower($partyName);$party=$customerMode?($maps['customers'][$partyKey]??null):($maps['vendors'][$partyKey]??null);if($partyName===''){di_add_issue($issues,$n,$ref,$customerMode?'Customer Name':'Vendor Name',($customerMode?'Customer':'Vendor').' Name is required.','','Enter the exact current-company name.');$bad=true;}elseif(!$party){di_add_issue($issues,$n,$ref,$customerMode?'Customer Name':'Vendor Name',($customerMode?'Customer':'Vendor').' was not found in the current company.',$partyName,'Create/import the master record first, then use its exact name.');$bad=true;}elseif(!(bool)$party['active'] || (!$customerMode && (string)($party['status']??'active')!=='active') || ($customerMode && (string)($party['status']??'active')==='inactive')){di_add_issue($issues,$n,$ref,$customerMode?'Customer Name':'Vendor Name',($customerMode?'Customer':'Vendor').' is inactive.',$partyName,'Reactivate the master record before importing an outstanding cutover document.');$bad=true;}
            if($party&&abs((int)($maps['partyOpenings'][$customerMode?'customer':'vendor'][(string)$party['id']]??0))>0){di_add_issue($issues,$n,$ref,'Opening Balance','This '.($customerMode?'customer':'vendor').' already has a master opening balance. Importing open documents would duplicate the AR/AP opening balance.',$partyName,'Use either detailed opening documents or the master opening balance, not both.');$bad=true;}
            $dateErr=null;$docDate=di_date_value($row[$customerMode?'invoiceDate':'billDate']??'',true,$customerMode?'Original Invoice Date':'Original Bill Date',$dateErr);if($dateErr){di_add_issue($issues,$n,$ref,$customerMode?'Original Invoice Date':'Original Bill Date',$dateErr,$row[$customerMode?'invoiceDate':'billDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($docDate!==null){if($docDate>$today){di_add_issue($issues,$n,$ref,$customerMode?'Original Invoice Date':'Original Bill Date','Original document date cannot be in the future.',$docDate,'Use the actual historical document date.');$bad=true;}if($booksStart!==''&&$docDate>=$booksStart){di_add_issue($issues,$n,$ref,$customerMode?'Original Invoice Date':'Original Bill Date','Current-Period Document Not Allowed. This importer is only for documents dated before '.$booksStart.'.',$docDate,$customerMode?'Use Customer Invoices for current-period invoices.':'Use Vendor Invoices for current-period bills.');$bad=true;}}
            $dueErr=null;$dueDate=di_date_value($row['dueDate']??'',true,'Original Due Date',$dueErr);if($dueErr){di_add_issue($issues,$n,$ref,'Original Due Date',$dueErr,$row['dueDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($docDate&&$dueDate&&$dueDate<$docDate){di_add_issue($issues,$n,$ref,'Original Due Date','Due date cannot be before the original document date.',$dueDate,'Correct the original due date.');$bad=true;}
            $oErr=null;$pErr=null;$bErr=null;$original=di_money_cents($row['originalAmount']??'',$oErr);$paid=di_money_cents($row['amountPreviouslyPaid']??'',$pErr);$outstanding=di_money_cents($row['outstandingAmount']??'',$bErr);
            if($oErr||$original<=0){di_add_issue($issues,$n,$ref,'Original Amount',$oErr?:'Original Amount must be greater than zero.',$row['originalAmount']??'','Enter the original document total.');$bad=true;}if($pErr||$paid<0){di_add_issue($issues,$n,$ref,'Amount Previously Paid',$pErr?:'Amount Previously Paid cannot be negative.',$row['amountPreviouslyPaid']??'','Enter zero or the amount already paid before cutover.');$bad=true;}if($bErr||$outstanding<=0){di_add_issue($issues,$n,$ref,'Outstanding Amount',$bErr?:'Outstanding Amount must be greater than zero.',$row['outstandingAmount']??'','Only unpaid/open documents belong in this cutover importer.');$bad=true;}if(!$oErr&&!$pErr&&!$bErr&&$original-$paid!==$outstanding){di_add_issue($issues,$n,$ref,'Outstanding Amount','Original Amount less Amount Previously Paid must equal Outstanding Amount.',$row['outstandingAmount']??'','Correct the three amounts so they reconcile exactly.');$bad=true;}
            if(!$bad&&$party&&$docDate&&$dueDate){$originalTotalAll+=$original;$paidAll+=$paid;$outstandingAll+=$outstanding;$prepared[]=['row'=>$n,'classification'=>'opening_document_ready','input'=>['partyId'=>(string)$party['id'],'partyName'=>$partyName,'number'=>$number,'documentDate'=>$docDate,'dueDate'=>$dueDate,'originalAmountCents'=>$original,'previouslyPaidCents'=>$paid,'outstandingAmountCents'=>$outstanding,'reference'=>di_str($row,'reference',120),'notes'=>di_str($row,'notes',1000)]];}
        }
        $cutover=['booksStartDate'=>$booksStart!==''?$booksStart:null,'originalTotalCents'=>$originalTotalAll,'previouslyPaidCents'=>$paidAll,'outstandingTotalCents'=>$outstandingAll];
    }elseif($type==='customer_invoices'){
        if(empty($company['books_start_date']))di_add_issue($issues,2,'Cutover Setup','Start of Books','Set Start of Books before Tegh can classify customer invoices as Opening or Regular.','','Open Company Details and set Start of Books.');
        $unified=di_validate_customer_documents($company,$rows,$maps);$issues=array_merge($issues,$unified['issues']);$prepared=$unified['prepared'];$cutover=['booksStartDate'=>$company['books_start_date']!==null?(string)$company['books_start_date']:null]+$unified['openingTotals'];
    }elseif($type==='__legacy_customer_invoices'){
        $groups=[];foreach(array_values($rows) as $i=>$row){if(!is_array($row))continue;$n=$i+2;$ref=di_str($row,'importInvoiceReference',120);if($ref===''){$ref='Row '.$n;di_add_issue($issues,$n,$ref,'Import Invoice Reference','Import Invoice Reference is required.','','Enter a reference shared by all lines of one invoice.');continue;}$groups[$ref][]=['row'=>$n,'data'=>$row];}
        foreach($groups as $ref=>$parts){$bad=false;$customerName=di_str($parts[0]['data'],'customerName',160);$customer=$maps['customers'][mb_strtolower($customerName)]??null;if(!$customer){di_add_issue($issues,$parts[0]['row'],$ref,'Customer Name','Customer not found.',$customerName,'Use the exact name of an existing current-company customer.');$bad=true;}elseif(!(bool)$customer['active']||(string)$customer['status']==='inactive'){di_add_issue($issues,$parts[0]['row'],$ref,'Customer Name','Customer is inactive.',$customerName,'Reactivate the customer before importing an invoice.');$bad=true;}elseif((string)$customer['status']==='on_hold'){di_add_issue($issues,$parts[0]['row'],$ref,'Customer Name','Customer On Hold — invoice will be imported as Draft only.',$customerName,'Review or release the customer hold before posting.','warning');$warnings++;}
            $dup=db()->prepare('SELECT 1 FROM invoices WHERE company_id=? AND import_reference=? LIMIT 1');$dup->execute([$companyId,$ref]);if($dup->fetchColumn()){di_add_issue($issues,$parts[0]['row'],$ref,'Import Invoice Reference','This import reference has already been used.',$ref,'Use a new unique import reference.','duplicate');$bad=true;$duplicates++;}
            $issueErr=null;$issueDate=di_date_value($parts[0]['data']['invoiceDate']??'',true,'Invoice Date',$issueErr);if($issueErr){di_add_issue($issues,$parts[0]['row'],$ref,'Invoice Date',$issueErr,$parts[0]['data']['invoiceDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($issueDate!==null&&!empty($company['books_start_date'])&&$issueDate<(string)$company['books_start_date']){di_add_issue($issues,$parts[0]['row'],$ref,'Invoice Date','Pre-Start Document Not Allowed. Current-period invoice import cannot be used for documents dated before '.(string)$company['books_start_date'].'.',$issueDate,'Use Opening Customer Invoices in Data Import for unpaid cutover documents.');$bad=true;}
            $termErr=null;$terms=di_terms_value($parts[0]['data']['paymentTerms']??'', $parts[0]['data']['customTermsDays']??'',(int)($customer['default_terms_days']??30),$termErr);if($termErr){di_add_issue($issues,$parts[0]['row'],$ref,'Payment Terms',$termErr,$parts[0]['data']['paymentTerms']??'','Correct the payment terms.');$bad=true;}
            $dueErr=null;$dueDate=di_date_value($parts[0]['data']['dueDate']??'',false,'Due Date',$dueErr);if($dueErr){di_add_issue($issues,$parts[0]['row'],$ref,'Due Date',$dueErr,$parts[0]['data']['dueDate']??'','Use YYYY-MM-DD.');$bad=true;}if($dueDate===null&&$issueDate!==null)$dueDate=(new DateTimeImmutable($issueDate))->modify('+'.$terms.' days')->format('Y-m-d');if($issueDate&&$dueDate&&$dueDate<$issueDate){di_add_issue($issues,$parts[0]['row'],$ref,'Due Date','Due Date cannot be before Invoice Date.',$dueDate,'Correct the due date.');$bad=true;}
            $lines=[];$lineSeen=[];foreach($parts as $part){$row=$part['data'];$n=$part['row'];
                if(di_str($row,'customerName',160)!==$customerName){di_add_issue($issues,$n,$ref,'Customer Name','All rows grouped under one Import Invoice Reference must use the same customer.',$row['customerName']??'','Use a separate import reference for a different customer.');$bad=true;}
                if(trim((string)($row['invoiceDate']??''))!==trim((string)($parts[0]['data']['invoiceDate']??''))){di_add_issue($issues,$n,$ref,'Invoice Date','All lines of one imported invoice must use the same Invoice Date.',$row['invoiceDate']??'','Use the same Invoice Date on every line.');$bad=true;}
                $lineNo=trim((string)($row['lineNumber']??''));if($lineNo===''||isset($lineSeen[$lineNo])){di_add_issue($issues,$n,$ref,'Line Number',$lineNo===''?'Line Number is required.':'Line Number is duplicated within this invoice.',$lineNo,'Use a unique line number within each invoice.');$bad=true;}$lineSeen[$lineNo]=true;
                $desc=di_str($row,'description',500);if($desc===''){di_add_issue($issues,$n,$ref,'Description','Description is required.','','Enter a line description.');$bad=true;}
                $qErr=null;$qty=di_quantity($row['quantity']??1,$qErr);if($qErr){di_add_issue($issues,$n,$ref,'Quantity',$qErr,$row['quantity']??'','Correct the quantity.');$bad=true;}
                $mErr=null;$price=di_money_cents($row['unitPrice']??'',$mErr);if($mErr||$price<=0){di_add_issue($issues,$n,$ref,'Unit Price',$mErr?:'Unit Price must be greater than zero.',$row['unitPrice']??'','Enter a positive amount.');$bad=true;}
                $glCode=strtoupper(di_str($row,'incomeGlCode',40));$a=$maps['accounts'][$glCode]??null;if($glCode==='9999'){di_add_issue($issues,$n,$ref,'Income GL Code','Opening Balance Control account 9999 cannot be used for this transaction.',$glCode,'Choose an active income account.');$bad=true;}elseif(!$a||!(bool)$a['active']||(string)$a['account_type']!=='income'||(bool)$a['is_control']){di_add_issue($issues,$n,$ref,'Income GL Code','GL Account Code Not Found or not permitted for invoice revenue.',$glCode,'Use an existing active non-control income GL code.');$bad=true;}$accountId=$a?(string)$a['id']:null;
                $taxErr=null;$tax=di_tax_mode($row['taxTreatment']??'',$taxErr);if($taxErr){di_add_issue($issues,$n,$ref,'Tax Code / Tax Treatment',$taxErr,$row['taxTreatment']??'','Use GST/HST or No Tax.');$bad=true;}
                $productId=null;$itemCode=di_str($row,'productServiceCode',60);if($itemCode!==''){$item=$maps['productsByCode'][mb_strtolower($itemCode)]??null;if(!$item||!(bool)$item['active']){di_add_issue($issues,$n,$ref,'Product / Service Code','Product/service code was not found or is inactive.',$itemCode,'Use an active Item Code or leave it blank.');$bad=true;}else$productId=(string)$item['id'];}
                $lines[]=['description'=>$desc,'quantity'=>$qty,'unitPriceCents'=>$price,'taxable'=>$tax['taxable'],'productServiceId'=>$productId,'incomeAccountId'=>$accountId];
            }
            if(!$bad&&$customer&&$issueDate&&$dueDate)$prepared[]=['row'=>$parts[0]['row'],'input'=>['customerId'=>(string)$customer['id'],'issueDate'=>$issueDate,'dueDate'=>$dueDate,'currency'=>(string)$company['currency'],'purchaseOrder'=>di_str($parts[0]['data'],'customerReference',80),'message'=>di_str($parts[0]['data'],'notes',1000),'lines'=>$lines,'issue'=>false,'importReference'=>$ref]];
        }
    }elseif($type==='vendor_invoices'){
        if(empty($company['books_start_date']))di_add_issue($issues,2,'Cutover Setup','Start of Books','Set Start of Books before Tegh can classify vendor invoices as Opening or Regular.','','Open Company Details and set Start of Books.');
        $unified=di_validate_vendor_documents($company,$rows,$maps);$issues=array_merge($issues,$unified['issues']);$prepared=$unified['prepared'];$cutover=['booksStartDate'=>$company['books_start_date']!==null?(string)$company['books_start_date']:null]+$unified['openingTotals'];
    }elseif($type==='__legacy_vendor_invoices'){
        $groups=[];foreach(array_values($rows) as $i=>$row){if(!is_array($row))continue;$n=$i+2;$ref=di_str($row,'importBillReference',120);if($ref===''){$ref='Row '.$n;di_add_issue($issues,$n,$ref,'Import Bill Reference','Import Bill Reference is required.','','Enter a unique import reference.');continue;}$groups[$ref][]=['row'=>$n,'data'=>$row];}
        $seenBillNumbers=[];
        foreach($groups as $ref=>$parts){$bad=false;if(count($parts)>1){foreach($parts as $part)di_add_issue($issues,$part['row'],$ref,'Import Bill Reference','This Tegh build uses the existing single-line Vendor Invoice engine; multi-line vendor invoices cannot be imported without rebuilding that engine.',$ref,'Split the bill into one supported line or enter the multi-line bill manually.');continue;}
            $row=$parts[0]['data'];$n=$parts[0]['row'];$vendorName=di_str($row,'vendorName',200);$vendor=$maps['vendors'][mb_strtolower($vendorName)]??null;if(!$vendor||!(bool)$vendor['active']||(string)$vendor['status']==='inactive'){di_add_issue($issues,$n,$ref,'Vendor Name','Vendor not found or inactive.',$vendorName,'Use the exact name of an active current-company vendor.');$bad=true;}
            $dup=db()->prepare('SELECT 1 FROM bills WHERE company_id=? AND import_reference=? LIMIT 1');$dup->execute([$companyId,$ref]);if($dup->fetchColumn()){di_add_issue($issues,$n,$ref,'Import Bill Reference','This import reference has already been used.',$ref,'Use a new unique import reference.','duplicate');$bad=true;$duplicates++;}
            $billNo=di_str($row,'vendorInvoiceNumber',60);if($billNo===''){di_add_issue($issues,$n,$ref,'Invoice Number / Vendor Reference','Vendor invoice number/reference is required.','','Enter the vendor invoice number.');$bad=true;}else{$billKey=mb_strtolower($billNo);$q=db()->prepare('SELECT 1 FROM bills WHERE company_id=? AND number=? LIMIT 1');$q->execute([$companyId,$billNo]);if(isset($seenBillNumbers[$billKey])||$q->fetchColumn()){di_add_issue($issues,$n,$ref,'Invoice Number / Vendor Reference','That vendor invoice number already exists or is duplicated in this file.',$billNo,'Use the correct unique vendor invoice number.','duplicate');$bad=true;$duplicates++;}$seenBillNumbers[$billKey]=true;}
            $dateErr=null;$billDate=di_date_value($row['billDate']??'',true,'Bill Date',$dateErr);if($dateErr){di_add_issue($issues,$n,$ref,'Bill Date',$dateErr,$row['billDate']??'','Use YYYY-MM-DD.');$bad=true;}elseif($billDate!==null&&!empty($company['books_start_date'])&&$billDate<(string)$company['books_start_date']){di_add_issue($issues,$n,$ref,'Bill Date','Pre-Start Document Not Allowed. Current-period vendor invoice import cannot be used for documents dated before '.(string)$company['books_start_date'].'.',$billDate,'Use Opening Vendor Bills in Data Import for unpaid cutover documents.');$bad=true;}
            $termErr=null;$terms=di_terms_value($row['paymentTerms']??'', $row['customTermsDays']??'',(int)($vendor['default_terms_days']??30),$termErr);if($termErr){di_add_issue($issues,$n,$ref,'Payment Terms',$termErr,$row['paymentTerms']??'','Correct the payment terms.');$bad=true;}
            $dueErr=null;$due=di_date_value($row['dueDate']??'',false,'Due Date',$dueErr);if($dueErr){di_add_issue($issues,$n,$ref,'Due Date',$dueErr,$row['dueDate']??'','Use YYYY-MM-DD.');$bad=true;}if($due===null&&$billDate)$due=(new DateTimeImmutable($billDate))->modify('+'.$terms.' days')->format('Y-m-d');if($billDate&&$due&&$due<$billDate){di_add_issue($issues,$n,$ref,'Due Date','Due Date cannot be before Bill Date.',$due,'Correct the due date.');$bad=true;}
            $glCode=strtoupper(di_str($row,'expenseAssetGlCode',40));$a=$maps['accounts'][$glCode]??null;if($glCode==='9999'){di_add_issue($issues,$n,$ref,'Expense / Asset GL Code','Opening Balance Control account 9999 cannot be used for this transaction.',$glCode,'Choose an active expense or asset account.');$bad=true;}elseif(!$a||!(bool)$a['active']||!in_array((string)$a['account_type'],['expense','asset'],true)||(bool)$a['is_control']){di_add_issue($issues,$n,$ref,'Expense / Asset GL Code','GL Account Code Not Found or not permitted for a vendor invoice.',$glCode,'Use an existing active non-control expense or asset GL code.');$bad=true;}$accountId=$a?(string)$a['id']:null;
            $qErr=null;$qty=di_quantity($row['quantity']??1,$qErr);if($qErr){di_add_issue($issues,$n,$ref,'Quantity',$qErr,$row['quantity']??'','Correct the quantity.');$bad=true;}
            $mErr=null;$unit=di_money_cents($row['unitPriceAmount']??'',$mErr);if($mErr||$unit<=0){di_add_issue($issues,$n,$ref,'Unit Price / Amount',$mErr?:'Amount must be greater than zero.',$row['unitPriceAmount']??'','Enter a positive amount.');$bad=true;}$amount=(int)round($qty*$unit);
            $taxErr=null;$tax=di_tax_mode($row['taxTreatment']??'',$taxErr);if($taxErr){di_add_issue($issues,$n,$ref,'Tax Code / Tax Treatment',$taxErr,$row['taxTreatment']??'','Use GST/HST, GST/HST Inclusive, or No Tax.');$bad=true;}
            $productId=null;$itemCode=di_str($row,'productServiceCode',60);if($itemCode!==''){$item=$maps['productsByCode'][mb_strtolower($itemCode)]??null;if(!$item||!(bool)$item['active']){di_add_issue($issues,$n,$ref,'Product / Service Code','Product/service code was not found or is inactive.',$itemCode,'Use an active Item Code or leave it blank.');$bad=true;}else$productId=(string)$item['id'];}
            if(!$bad&&$vendor&&$billDate&&$due)$prepared[]=['row'=>$n,'input'=>['vendorId'=>(string)$vendor['id'],'number'=>$billNo,'billDate'=>$billDate,'dueDate'=>$due,'paymentTermsDays'=>$terms,'categoryAccountId'=>$accountId,'currency'=>(string)($vendor['default_currency']?:$company['currency']),'productServiceId'=>$productId,'quantity'=>$qty,'foreignAmountCents'=>$amount,'taxEntryMode'=>$tax['mode'],'applyGstHst'=>$tax['taxable'],'applyPst'=>false,'memo'=>di_str($row,'notes',500),'issue'=>false,'importReference'=>$ref]];
        }
    }
    $errors=count(array_filter($issues,static fn(array $x):bool=>$x['severity']==='error'));
    $duplicates=count(array_filter($issues,static fn(array $x):bool=>$x['severity']==='duplicate'));
    $warnings=count(array_filter($issues,static fn(array $x):bool=>$x['severity']==='warning'));
    $summary=['rowsRead'=>count($rows),'ready'=>count($prepared),'warnings'=>$warnings,'errors'=>count(array_filter($issues,static fn(array $x):bool=>($x['severity']??'error')==='error')),'duplicates'=>$duplicates];if(isset($cutover)&&is_array($cutover))$summary=array_merge($summary,$cutover);
    if($type==='coa_opening_balances'){$summary['accountsCreated']=count(array_filter($prepared,static fn(array $x):bool=>($x['classification']??'')==='new_account'));$summary['existingAccountsMatched']=count(array_filter($prepared,static fn(array $x):bool=>($x['classification']??'')==='existing_account_matched'));$summary['balanceRowsReady']=count(array_filter($prepared,static fn(array $x):bool=>(int)($x['input']['debitCents']??0)>0||(int)($x['input']['creditCents']??0)>0));}
    if(in_array($type,['customer_invoices','vendor_invoices'],true)){$summary['openingDocuments']=count(array_filter($prepared,static fn(array $x):bool=>($x['classification']??'')==='opening_document_ready'));$summary['regularDrafts']=count(array_filter($prepared,static fn(array $x):bool=>($x['classification']??'')==='regular_draft'));}
    return ['summary'=>$summary,'issues'=>$issues,'prepared'=>$prepared];
}

function di_source_identity(string $type,array $row,int $sourceRow): string
{
    if($type==='customer_invoices')return 'document:'.mb_strtolower(di_str($row,'importInvoiceReference',120)?:'row-'.$sourceRow);
    if($type==='vendor_invoices')return 'document:'.mb_strtolower(di_str($row,'importBillReference',120)?:'row-'.$sourceRow);
    if($type==='opening_customer_invoices')return 'document:'.mb_strtolower(di_str($row,'invoiceNumber',60)?:'row-'.$sourceRow);
    if($type==='opening_vendor_bills')return 'document:'.mb_strtolower(di_str($row,'vendorInvoiceNumber',60)?:'row-'.$sourceRow);
    return 'row:'.$sourceRow;
}

function di_prepared_identity(string $type,array $record): string
{
    $input=is_array($record['input']??null)?$record['input']:[];
    if(in_array($type,['customer_invoices','vendor_invoices'],true))return 'document:'.mb_strtolower(trim((string)($input['importReference']??'')));
    if(in_array($type,['opening_customer_invoices','opening_vendor_bills'],true))return 'document:'.mb_strtolower(trim((string)($input['number']??'')));
    return 'row:'.(int)($record['row']??0);
}

/**
 * Add opaque server-issued keys for logical records. A key covers the exact
 * source rows in that record, so a browser cannot turn a selected invoice line
 * into an independently committable document or change the reviewed payload.
 */
function di_attach_selection(string $type,array $rows,array $result): array
{
    $groups=[];
    foreach(array_values($rows) as $index=>$source){
        if(!is_array($source))continue;$sourceRow=$index+2;$identity=di_source_identity($type,$source,$sourceRow);
        if(!isset($groups[$identity]))$groups[$identity]=['sourceRows'=>[],'payload'=>[]];
        $groups[$identity]['sourceRows'][]=$sourceRow;$groups[$identity]['payload'][]=$source;
    }
    $preparedByIdentity=[];
    foreach($result['prepared'] as $index=>$record){$identity=di_prepared_identity($type,$record);$preparedByIdentity[$identity]=$index;}
    $issuesByRow=[];
    foreach($result['issues'] as $issue){$row=(int)($issue['row']??0);$issuesByRow[$row][]=$issue;}
    $globalBlocking=(bool)array_filter($result['issues'],static fn(array $issue):bool=>in_array((string)($issue['reference']??''),['Opening Balance Control','Cutover Setup','Payroll Setup'],true)&&($issue['severity']??'error')==='error');$records=[];
    foreach($groups as $identity=>$group){
        $canonical=json_encode($group['payload'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $key='rec_'.substr(hash('sha256',$type.'|'.$identity.'|'.$canonical),0,40);
        $preparedIndex=$preparedByIdentity[$identity]??null;$recordIssues=[];
        foreach($group['sourceRows'] as $sourceRow)foreach($issuesByRow[$sourceRow]??[] as $issue)$recordIssues[]=$issue;
        $blocking=(bool)array_filter($recordIssues,static fn(array $issue):bool=>in_array((string)($issue['severity']??'error'),['error','duplicate'],true));$eligible=$preparedIndex!==null&&!$blocking&&!$globalBlocking;
        $classification=$eligible?(string)($result['prepared'][$preparedIndex]['classification']??'ready'):'invalid';
        if($eligible){$result['prepared'][$preparedIndex]['selectionKey']=$key;$result['prepared'][$preparedIndex]['sourceRows']=$group['sourceRows'];}
        $label=str_starts_with($identity,'document:')?substr($identity,9):'Source row '.(string)$group['sourceRows'][0];
        $records[]=['key'=>$key,'sourceRows'=>$group['sourceRows'],'eligible'=>$eligible,'status'=>$eligible?'ready':(array_filter($recordIssues,static fn(array $x):bool=>($x['severity']??'error')==='duplicate')?'duplicate':'invalid'),'classification'=>$classification,'label'=>$label,'issueCount'=>count($recordIssues)];
    }
    $result['records']=$records;$result['summary']['recordsRead']=count($records);$result['summary']['readyRecords']=count(array_filter($records,static fn(array $record):bool=>$record['eligible']));
    return $result;
}

function di_selected_result(array $result,mixed $selectedKeys): array
{
    if(!is_array($selectedKeys))fail('Choose at least one eligible record from the import preview.',422,'import_selection_required');
    $keys=array_values(array_unique(array_map(static fn(mixed $key):string=>trim((string)$key),$selectedKeys)));
    if(count($keys)<1||count($keys)>5000)fail('Choose between 1 and 5,000 eligible records.',422,'import_selection_required');
    $recordsByKey=[];foreach($result['records']??[] as $record)$recordsByKey[(string)$record['key']]=$record;
    foreach($keys as $key){$record=$recordsByKey[$key]??null;if(!$record||empty($record['eligible']))fail('The selected import records no longer match this validated preview.',409,'import_selection_changed');}
    $selected=array_fill_keys($keys,true);$result['prepared']=array_values(array_filter($result['prepared'],static fn(array $record):bool=>isset($selected[(string)($record['selectionKey']??'')])));
    if(count($result['prepared'])!==count($keys))fail('The selected import records could not be revalidated as complete logical records.',409,'import_selection_changed');
    $result['selectedKeys']=$keys;$result['summary']['selected']=count($keys);return $result;
}

function di_create_account_record(array $user,array $company,array $input,string $source='import'): array
{
    $companyId=(string)$company['id'];$code=strtoupper(trim((string)$input['code']));
    if($code==='9999')fail('Account code 9999 is reserved for Tegh Opening Balance Control.',409,'system_account_code_reserved');
    $type=(string)$input['type'];$normal=in_array($type,['asset','expense'],true)?'debit':'credit';$id=new_id('account');
    db()->prepare('INSERT INTO accounts (id,company_id,code,name,description,account_type,normal_balance,is_control,active) VALUES (?,?,?,?,?,?,?,0,?)')
      ->execute([$id,$companyId,$code,(string)$input['name'],optional_text($input['description']??null,500),$type,$normal,!empty($input['active'])?1:0]);
    audit_event($user,$companyId,'account.imported','account',$id,['code'=>$code,'name'=>(string)$input['name'],'type'=>$type,'source'=>$source]);
    return ['id'=>$id,'code'=>$code,'name'=>(string)$input['name']];
}

function di_create_employee_record(array $user,array $company,array $input): array
{
    require_company_permission($company,'payroll.manage');$companyId=(string)$company['id'];
    if(!payroll_settings_row($companyId))fail('Enable payroll for this company before importing employees.',409,'payroll_not_enabled');
    $id=new_id('employee');
    try{
        db()->prepare('INSERT INTO payroll_employees (id,company_id,employee_number,first_name,last_name,email,sin_ciphertext,sin_last_four,province_of_employment,province_of_residence,date_of_birth,hire_date,termination_date,pay_frequency,pay_type,annual_salary_cents,hourly_rate_cents,standard_hours_milli,vacation_rate_bps,vacation_paid_each_pay,federal_td1_cents,provincial_td1_cents,additional_tax_cents,cpp_exempt,ei_exempt,active,created_by) VALUES (?,?,?,?,?,?,NULL,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([$id,$companyId,(string)$input['employeeNumber'],(string)$input['firstName'],(string)$input['lastName'],optional_text($input['email']??null,254),(string)$input['provinceOfEmployment'],(string)$input['provinceOfResidence'],$input['dateOfBirth']?:null,(string)$input['hireDate'],$input['terminationDate']?:null,(string)$input['payFrequency'],(string)$input['payType'],(int)$input['annualSalaryCents'],(int)$input['hourlyRateCents'],(int)round((float)$input['standardHours']*1000),(int)$input['vacationRateBps'],!empty($input['vacationPaidEachPay'])?1:0,$input['federalTd1Cents']!==null?(int)$input['federalTd1Cents']:null,$input['provincialTd1Cents']!==null?(int)$input['provincialTd1Cents']:null,(int)$input['additionalTaxCents'],!empty($input['cppExempt'])?1:0,!empty($input['eiExempt'])?1:0,!empty($input['active'])?1:0,$user['id']]);
    }catch(PDOException $error){if((string)$error->getCode()==='23000')fail('That employee number already exists.',409,'duplicate_employee_number');throw $error;}
    audit_event($user,$companyId,'payroll.employee_created','payroll_employee',$id,['employeeNumber'=>(string)$input['employeeNumber'],'province'=>(string)$input['provinceOfEmployment'],'payType'=>(string)$input['payType'],'initiatedVia'=>'Data Import']);
    return ['id'=>$id,'employeeNumber'=>(string)$input['employeeNumber'],'name'=>trim((string)$input['firstName'].' '.(string)$input['lastName']),'status'=>!empty($input['active'])?'active':'inactive'];
}

function di_post_opening_balance_batch(array $user,array $company,array $balanceRows,string $date,string $filename,string $previewId): array
{
    $companyId=(string)$company['id'];$control=opening_balance_control_account($companyId,false);
    if(!$control){audit_event($user,$companyId,'opening_balance_control.conflict','company',$companyId,['configuredCode'=>'9999','previewId'=>$previewId]);opening_balance_control_account($companyId,true);}
    $lines=[];$debits=0;$credits=0;$hashRows=[];
    foreach($balanceRows as $row){$debit=(int)$row['debitCents'];$credit=(int)$row['creditCents'];if($debit<=0&&$credit<=0)continue;$lines[]=['accountId'=>(string)$row['accountId'],'debitCents'=>$debit,'creditCents'=>$credit,'memo'=>(string)($row['memo']??'')];$debits+=$debit;$credits+=$credit;$hashRows[]=[(string)$row['accountCode'],$debit,$credit,(string)($row['memo']??'')];}
    if(!$lines)return ['posted'=>false,'rowCount'=>0,'voucherNumber'=>null,'journalEntryId'=>null,'openingBalanceImportId'=>null,'offsetCents'=>0];
    $difference=$debits-$credits;$offset=abs($difference);
    if($difference>0){$lines[]=['accountId'=>(string)$control['id'],'debitCents'=>0,'creditCents'=>$offset,'memo'=>'Automatic opening-balance offset'];$credits+=$offset;}
    elseif($difference<0){$lines[]=['accountId'=>(string)$control['id'],'debitCents'=>$offset,'creditCents'=>0,'memo'=>'Automatic opening-balance offset'];$debits+=$offset;}
    $sourceHash=hash('sha256',json_encode([$companyId,$date,$hashRows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $dup=db()->prepare('SELECT id FROM opening_balance_imports WHERE company_id=? AND source_hash=? LIMIT 1');$dup->execute([$companyId,$sourceHash]);if($dup->fetchColumn()!==false)fail('This opening-balance batch was already posted.',409,'opening_balance_duplicate');
    $importId=new_id('opening');$memo='Opening balances · '.($filename!==''?$filename:'Data Import');$entryId=add_journal_entry($user,$companyId,$date,'opening_balance_import',$importId,$memo,$lines);
    $voucherId=function_exists('voucher_register_saved')?voucher_register_saved($user,$companyId,'OB','GL','opening_balance_import',$importId,$date,$memo,$debits,$entryId,true):'';
    db()->prepare('INSERT INTO opening_balance_imports (id,company_id,effective_date,filename,source_hash,row_count,total_debit_cents,total_credit_cents,journal_entry_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$importId,$companyId,$date,$filename,$sourceHash,count($balanceRows),$debits,$credits,$entryId,$user['id']]);
    $voucherNumber=null;if($voucherId!==''){$q=db()->prepare('SELECT voucher_number FROM vouchers WHERE id=? LIMIT 1');$q->execute([$voucherId]);$v=$q->fetchColumn();if($v!==false)$voucherNumber=(string)$v;}
    audit_event($user,$companyId,'opening_balance.journal_posted','opening_balance_import',$importId,['previewId'=>$previewId,'effectiveDate'=>$date,'rowCount'=>count($balanceRows),'debitCents'=>$debits,'creditCents'=>$credits,'offsetCents'=>$offset,'voucherNumber'=>$voucherNumber]);
    return ['posted'=>true,'rowCount'=>count($balanceRows),'voucherNumber'=>$voucherNumber,'journalEntryId'=>$entryId,'openingBalanceImportId'=>$importId,'offsetCents'=>$offset];
}

function di_commit_accounting_import(array $user,array $company,string $type,array $result,string $filename,string $previewId): array
{
    $created=[];$matched=0;$balanceRows=[];$date=null;
    $ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
    try{
        if($type==='chart_of_accounts'){
            foreach($result['prepared'] as $record)$created[]=di_create_account_record($user,$company,$record['input'],'data_import');
        }elseif($type==='opening_balances'){
            foreach($result['prepared'] as $record){$i=$record['input'];$date=$date??(string)$i['date'];$balanceRows[]=$i;}
        }else{
            $accountIds=[];
            foreach($result['prepared'] as $record){$i=$record['input'];if(!empty($i['existingAccountId'])){$accountIds[(string)$i['code']]=(string)$i['existingAccountId'];$matched++;}else{$a=di_create_account_record($user,$company,$i,'combined_data_import');$created[]=$a;$accountIds[(string)$i['code']]=(string)$a['id'];}}
            foreach($result['prepared'] as $record){$i=$record['input'];if((int)$i['debitCents']<=0&&(int)$i['creditCents']<=0)continue;$date=$date??(string)$i['date'];$balanceRows[]=['accountId'=>$accountIds[(string)$i['code']],'accountCode'=>(string)$i['code'],'date'=>$i['date'],'debitCents'=>(int)$i['debitCents'],'creditCents'=>(int)$i['creditCents'],'memo'=>(string)$i['memo']];}
        }
        $opening=['posted'=>false,'rowCount'=>0,'voucherNumber'=>null,'journalEntryId'=>null,'openingBalanceImportId'=>null,'offsetCents'=>0];if($balanceRows)$opening=di_post_opening_balance_batch($user,$company,$balanceRows,(string)$date,$filename,$previewId);
        if($ownsTransaction)db()->commit();
    }catch(Throwable $e){if($ownsTransaction&&db()->inTransaction())db()->rollBack();throw $e;}
    return ['created'=>$created,'accountsCreated'=>count($created),'existingAccountsMatched'=>$matched,'balanceRowsPosted'=>(int)$opening['rowCount'],'openingBalance'=>$opening];
}

function di_commit_opening_documents(array $user,array $company,string $type,array $result,string $filename,string $previewId): array
{
    $companyId=(string)$company['id'];$customerMode=$type==='opening_customer_invoices';$cutover=$company['books_start_date']!==null?(string)$company['books_start_date']:'';
    if($cutover==='')fail('Set Start of Books before importing opening documents.',422,'books_start_date_required');
    $control=opening_balance_control_account($companyId,true);$controlId=(string)$control['id'];$controlCode=(string)$control['code'];
    $controlId=$controlId!==''?$controlId:account_by_code($companyId,$controlCode);
    $controlAccount=company_account($companyId,$controlId);if(!$controlAccount)fail('Opening Balance Control is unavailable.',409,'opening_balance_control_missing');
    $arApId=account_by_code($companyId,$customerMode?'1200':'2050');$documentType=$customerMode?'customer_invoice':'vendor_bill';
    $hashRows=[];$originalTotal=0;$paidTotal=0;$outstandingTotal=0;foreach($result['prepared'] as $record){$i=$record['input'];$hashRows[]=[(string)$i['partyId'],(string)$i['number'],(string)$i['documentDate'],(int)$i['originalAmountCents'],(int)$i['previouslyPaidCents'],(int)$i['outstandingAmountCents']];$originalTotal+=(int)$i['originalAmountCents'];$paidTotal+=(int)$i['previouslyPaidCents'];$outstandingTotal+=(int)$i['outstandingAmountCents'];}
    if($outstandingTotal<=0)fail('The cutover file has no outstanding balance to import.',422,'opening_documents_empty');
    $sourceHash=hash('sha256',json_encode([$companyId,$documentType,$cutover,$hashRows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$dup=db()->prepare('SELECT id FROM opening_document_imports WHERE company_id=? AND source_hash=? LIMIT 1');$dup->execute([$companyId,$sourceHash]);if($dup->fetchColumn()!==false)fail('This opening-document cutover batch was already imported.',409,'opening_document_duplicate');
    $importId=new_id($customerMode?'openar':'openap');$sourceType=$customerMode?'opening_customer_invoices':'opening_vendor_bills';$memo=($customerMode?'Opening customer invoices':'Opening vendor bills').' · '.($filename!==''?$filename:'Data Import');
    $ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();try{
        $created=[];
        foreach($result['prepared'] as $record){$i=$record['input'];$id=new_id($customerMode?'invoice':'bill');$importRef=mb_substr('cutover:'.$importId.':'.(string)$i['number'],0,120);$original=(int)$i['originalAmountCents'];$paid=(int)$i['previouslyPaidCents'];$open=(int)$i['outstandingAmountCents'];$notes=mb_substr((string)($i['notes']??''),0,1000);$reference=mb_substr((string)($i['reference']??''),0,120);
            if($customerMode){
                $snapshot=json_encode(['name'=>(string)$i['partyName']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                db()->prepare("INSERT INTO invoices (id,company_id,customer_id,number,issue_date,due_date,status,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,message,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,purchase_order,import_reference,customer_snapshot_json,issued_journal_entry_id,is_recurring,is_opening_document,opening_import_id,original_paid_cents) VALUES (?,?,?,?,?,?,'sent',?,0,0,0,'none',?,?,?, ?,1000000,?,0,?,?, ?,?,?,NULL,0,1,?,?)")
                  ->execute([$id,$companyId,$i['partyId'],$i['number'],$i['documentDate'],$i['dueDate'],$original,$original,$open,$notes,(string)$company['currency'],$original,$original,$open,$reference!==''?$reference:null,$importRef,$snapshot,$importId,$paid]);
            }else{
                $days=max(0,min(3650,(int)round((strtotime((string)$i['dueDate'])-strtotime((string)$i['documentDate']))/86400)));
                db()->prepare("INSERT INTO bills (id,company_id,vendor_id,product_service_id,quantity_milli,number,bill_date,due_date,status,category_account_id,payment_terms_days,subtotal_cents,gst_hst_cents,pst_cents,tax_cents,tax_entry_mode,total_cents,balance_cents,currency,exchange_rate_micros,foreign_subtotal_cents,foreign_gst_hst_cents,foreign_pst_cents,foreign_tax_cents,foreign_total_cents,foreign_balance_cents,memo,import_reference,issued_journal_entry_id,is_recurring,is_opening_document,opening_import_id,original_paid_cents) VALUES (?,?,?,NULL,1000,?, ?,?,'open',?,?,?,0,0,0,'none',?,?,?,1000000,?,0,0,0,?,?,?, ?,NULL,0,1,?,?)")
                  ->execute([$id,$companyId,$i['partyId'],$i['number'],$i['documentDate'],$i['dueDate'],$controlId,$days,$original,$original,$open,(string)$company['currency'],$original,$original,$open,$notes,$importRef,$importId,$paid]);
            }
            $created[]=['id'=>$id,'number'=>(string)$i['number'],'partyName'=>(string)$i['partyName'],'originalAmountCents'=>$original,'previouslyPaidCents'=>$paid,'outstandingAmountCents'=>$open,'isOpeningDocument'=>true];
        }
        $lines=$customerMode?[
            ['accountId'=>$arApId,'debitCents'=>$outstandingTotal,'creditCents'=>0,'memo'=>'Opening customer invoices'],
            ['accountId'=>$controlId,'debitCents'=>0,'creditCents'=>$outstandingTotal,'memo'=>'Opening customer invoices offset'],
        ]:[
            ['accountId'=>$controlId,'debitCents'=>$outstandingTotal,'creditCents'=>0,'memo'=>'Opening vendor bills offset'],
            ['accountId'=>$arApId,'debitCents'=>0,'creditCents'=>$outstandingTotal,'memo'=>'Opening vendor bills'],
        ];
        $entryId=add_journal_entry($user,$companyId,$cutover,$sourceType,$importId,$memo,$lines);$voucherId=function_exists('voucher_register_saved')?voucher_register_saved($user,$companyId,$customerMode?'OC':'OV',$customerMode?'AR':'AP',$sourceType,$importId,$cutover,$memo,$outstandingTotal,$entryId,true):'';$voucherNumber=null;if($voucherId!==''){$q=db()->prepare('SELECT voucher_number FROM vouchers WHERE id=?');$q->execute([$voucherId]);$v=$q->fetchColumn();if($v!==false)$voucherNumber=(string)$v;}
        db()->prepare('INSERT INTO opening_document_imports (id,company_id,document_type,cutover_date,filename,source_hash,document_count,original_total_cents,previously_paid_cents,outstanding_total_cents,journal_entry_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$importId,$companyId,$documentType,$cutover,$filename,$sourceHash,count($created),$originalTotal,$paidTotal,$outstandingTotal,$entryId,$user['id']]);
        audit_event($user,$companyId,$sourceType.'.imported','opening_document_import',$importId,['previewId'=>$previewId,'cutoverDate'=>$cutover,'documentCount'=>count($created),'originalTotalCents'=>$originalTotal,'previouslyPaidCents'=>$paidTotal,'outstandingTotalCents'=>$outstandingTotal,'journalEntryId'=>$entryId,'voucherNumber'=>$voucherNumber]);if($ownsTransaction)db()->commit();
    }catch(Throwable $e){if($ownsTransaction&&db()->inTransaction())db()->rollBack();if($e instanceof PDOException&&(string)$e->getCode()==='23000')fail('A document number or cutover reference already exists. Validate a fresh file.',409,'opening_document_duplicate');throw $e;}
    return ['created'=>$created,'documentsCreated'=>count($created),'cutoverDate'=>$cutover,'originalTotalCents'=>$originalTotal,'previouslyPaidCents'=>$paidTotal,'outstandingTotalCents'=>$outstandingTotal,'openingDocumentImportId'=>$importId,'journalEntryId'=>$entryId,'voucherNumber'=>$voucherNumber,'sourceType'=>$sourceType];
}

function di_commit_unified_documents(array $user,array $company,string $type,array $result,string $filename,string $previewId): array
{
    $customerMode=$type==='customer_invoices';$opening=array_values(array_filter($result['prepared'],static fn(array $record):bool=>($record['classification']??'')==='opening_document_ready'));$regular=array_values(array_filter($result['prepared'],static fn(array $record):bool=>($record['classification']??'')==='regular_draft'));
    $created=[];$openingResult=null;$regularCreated=[];
    $ownsTransaction=!db()->inTransaction();if($ownsTransaction)db()->beginTransaction();
    try{
        if($opening){$openingResult=di_commit_opening_documents($user,$company,$customerMode?'opening_customer_invoices':'opening_vendor_bills',['prepared'=>$opening],$filename,$previewId);$created=array_merge($created,$openingResult['created']);}
        foreach($regular as $record){$item=$customerMode?create_invoice_record($user,$company,$record['input'],'import'):create_bill_record($user,$company,$record['input'],'import');$item['isOpeningDocument']=false;$regularCreated[]=$item;$created[]=$item;}
        if($ownsTransaction)db()->commit();
    }catch(Throwable $error){if($ownsTransaction&&db()->inTransaction())db()->rollBack();throw $error;}
    $openingDefaults=['documentsCreated'=>0,'originalTotalCents'=>0,'previouslyPaidCents'=>0,'outstandingTotalCents'=>0,'openingDocumentImportId'=>null,'journalEntryId'=>null,'voucherNumber'=>null,'sourceType'=>$customerMode?'opening_customer_invoices':'opening_vendor_bills'];
    return array_merge($openingDefaults,$openingResult??[],['created'=>$created,'documentsCreated'=>count($created),'openingDocumentsCreated'=>count($opening),'regularDraftsCreated'=>count($regularCreated),'regularDrafts'=>$regularCreated,'classification'=>['opening'=>count($opening),'regular'=>count($regular)]]);
}

function di_preview_row(string $previewId,array $user,array $company,bool $forUpdate=false): array
{
    $sql='SELECT * FROM data_import_previews WHERE id=? AND company_id=? AND user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=db()->prepare($sql);$stmt->execute([$previewId,(string)$company['id'],(string)$user['id']]);$row=$stmt->fetch();
    if(!$row)fail('That import preview is no longer available.',404,'import_preview_not_found');
    if((string)$row['status']!=='validated')fail('That import preview has already been completed or cancelled.',409,'import_preview_closed');
    if(strtotime((string)$row['expires_at'])<time())fail('That import preview expired. Upload and validate the file again.',409,'import_preview_expired');
    return $row;
}

/**
 * Schema 38's preview enum predates Employee Import. Keep Schema 38 unchanged
 * by recording the server-issued logical type in the protected validation
 * envelope; older previews safely fall back to their physical enum value.
 * This value is never accepted from the commit request.
 */
function di_preview_import_type(array $row): string
{
    $stored=(string)($row['import_type']??'');$logical=$stored;
    try{
        $validation=json_decode((string)($row['validation_json']??''),true,32,JSON_THROW_ON_ERROR);
        if(is_array($validation)&&is_string($validation['logicalImportType']??null))$logical=(string)$validation['logicalImportType'];
    }catch(Throwable){}
    if(!in_array($logical,di_import_types(),true))fail('The stored import preview type is invalid.',500,'import_preview_corrupt');
    return $logical;
}

function handle_data_imports(string $suffix): never
{
    $user=require_user();$company=require_company($user);$companyId=(string)$company['id'];
    if($suffix==='validate'){
        require_method('POST');require_csrf();$input=request_json();$type=strtolower(trim((string)($input['importType']??'')));if($type==='bank_transaction_categories')fail('Use the signed category workbook preview/commit workflow.',409,'category_signed_workflow_required');if(!in_array($type,di_import_types(),true))fail('Choose a supported import type.');require_company_permission($company,di_permission($type));
        $rows=$input['rows']??null;if(!is_array($rows))fail('The import rows are invalid.');$filename=mb_substr(trim((string)($input['filename']??'')),0,240);
        if($type==='employees')$rows=di_strip_sins($rows); // R130: SIN values are removed before the preview is stored.
        $result=di_attach_selection($type,$rows,di_validate_rows($company,$type,$rows));$previewId=new_id('importpreview');$expires=gmdate('Y-m-d H:i:s',time()+1800);$storageType=$type==='employees'?'products_services':$type;
        db()->prepare('INSERT INTO data_import_previews (id,company_id,user_id,import_type,filename,rows_json,validation_json,status,expires_at) VALUES (?,?,?,?,?,?,?,\'validated\',?)')
            ->execute([$previewId,$companyId,$user['id'],$storageType,$filename,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode(['logicalImportType'=>$type,'summary'=>$result['summary'],'issues'=>$result['issues']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$expires]);
        audit_event($user,$companyId,'data_import.validated','data_import',$previewId,['importType'=>$type,'filename'=>$filename,'summary'=>$result['summary']]);
        json_response(['previewId'=>$previewId,'importType'=>$type,'summary'=>$result['summary'],'issues'=>$result['issues'],'records'=>$result['records'],'expiresAt'=>$expires]);
    }
    if($suffix==='cancel'){
        require_method('POST');require_csrf();$input=request_json();$previewId=clean_text($input['previewId']??'','Import preview',64);$row=di_preview_row($previewId,$user,$company);$type=di_preview_import_type($row);require_company_permission($company,di_permission($type));db()->prepare("UPDATE data_import_previews SET status='cancelled' WHERE id=? AND company_id=? AND user_id=? AND status='validated'")->execute([$previewId,$companyId,$user['id']]);audit_event($user,$companyId,'data_import.cancelled','data_import',$previewId,['importType'=>$type]);json_response(['cancelled'=>true]);
    }
    if($suffix==='commit'){
        require_method('POST');require_csrf();$input=request_json();$previewId=clean_text($input['previewId']??'','Import preview',64);$created=[];$accountingResult=null;$result=[];$row=[];$type='';$importedCount=0;$skippedCount=0;
        // The preview row is the idempotency record. Lock it and keep that lock in
        // the same database transaction as every selected record, financial entry,
        // completion state and audit event. A concurrent/replayed request blocks,
        // then observes the committed state and cannot execute the import twice.
        db()->beginTransaction();
        try{
            $row=di_preview_row($previewId,$user,$company,true);$type=di_preview_import_type($row);require_company_permission($company,di_permission($type));
            db()->prepare("UPDATE data_import_previews SET status='committing' WHERE id=? AND company_id=? AND user_id=? AND status='validated'")->execute([$previewId,$companyId,$user['id']]);
            audit_event($user,$companyId,'data_import.started','data_import',$previewId,['importType'=>$type,'filename'=>(string)$row['filename']]);
            try{$rows=json_decode((string)$row['rows_json'],true,64,JSON_THROW_ON_ERROR);}catch(JsonException){fail('The stored import preview failed its integrity check. Cancel it and validate the source file again.',500,'import_preview_corrupt',false);}if(!is_array($rows))fail('The stored import preview failed its integrity check. Cancel it and validate the source file again.',500,'import_preview_corrupt',false);$result=di_attach_selection($type,$rows,di_validate_rows($company,$type,$rows));$requestedKeys=$input['selectedKeys']??array_values(array_map(static fn(array $record):string=>(string)$record['key'],array_filter($result['records'],static fn(array $record):bool=>!empty($record['eligible']))));$result=di_selected_result($result,$requestedKeys);
            $hasSelectedOpening=(bool)array_filter($result['prepared'],static fn(array $record):bool=>($record['classification']??'')==='opening_document_ready');
            if((in_array($type,['opening_balances','coa_opening_balances','opening_customer_invoices','opening_vendor_bills'],true)||$hasSelectedOpening)&&empty($input['financialConfirmed']))fail('Confirm the financial opening-balance posting before importing.',422,'financial_confirmation_required');
            if(in_array($type,['customer_invoices','vendor_invoices'],true)){$accountingResult=di_commit_unified_documents($user,$company,$type,$result,(string)$row['filename'],$previewId);$created=$accountingResult['created'];}
            elseif(in_array($type,['opening_customer_invoices','opening_vendor_bills'],true)){$accountingResult=di_commit_opening_documents($user,$company,$type,$result,(string)$row['filename'],$previewId);$created=$accountingResult['created'];}
            elseif(in_array($type,['chart_of_accounts','opening_balances','coa_opening_balances'],true)){$accountingResult=di_commit_accounting_import($user,$company,$type,$result,(string)$row['filename'],$previewId);$created=$accountingResult['created'];}
            else foreach($result['prepared'] as $record){$payload=$record['input'];$created[] = match($type){
                'customers'=>create_customer_record($user,$company,$payload,'import'),
                'vendors'=>create_vendor_record($user,$company,$payload,'import'),
                'products_services'=>create_product_service_record($user,$company,$payload,'import'),
                'employees'=>di_create_employee_record($user,$company,$payload),
            };}
            $importedCount=in_array($type,['chart_of_accounts','opening_balances','coa_opening_balances'],true)?(int)(($accountingResult['accountsCreated']??0)+($accountingResult['balanceRowsPosted']??0)):count($created);
            $skippedCount=max(0,(int)($result['summary']['recordsRead']??$result['summary']['rowsRead'])-count($result['selectedKeys']??[]));
            $finalValidation=json_encode(['logicalImportType'=>$type,'summary'=>$result['summary'],'issues'=>$result['issues'],'importedCount'=>$importedCount,'accountingResult'=>$accountingResult],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $done=db()->prepare("UPDATE data_import_previews SET status='imported',validation_json=? WHERE id=? AND company_id=? AND user_id=? AND status='committing'");$done->execute([$finalValidation,$previewId,$companyId,$user['id']]);if($done->rowCount()!==1)fail('The import completion state could not be recorded safely.',500,'import_completion_state_failed');
            audit_event($user,$companyId,'data_import.completed','data_import',$previewId,['importType'=>$type,'filename'=>(string)$row['filename'],'rowsRead'=>$result['summary']['rowsRead'],'selectedCount'=>count($result['selectedKeys']??[]),'selectedKeys'=>$result['selectedKeys']??[],'importedCount'=>$importedCount,'skippedCount'=>$skippedCount,'errorCount'=>$result['summary']['errors'],'duplicateCount'=>$result['summary']['duplicates'],'warningCount'=>$result['summary']['warnings']]);
            db()->commit();
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
        json_response(['importType'=>$type,'importedCount'=>$importedCount,'skippedCount'=>$skippedCount,'selectedCount'=>count($result['selectedKeys']??[]),'selectedKeys'=>$result['selectedKeys']??[],'summary'=>$result['summary'],'issues'=>$result['issues'],'created'=>$created,'accountingResult'=>$accountingResult],201);
    }
    fail('Data Import action was not found.',404,'data_import_action_not_found');
}
