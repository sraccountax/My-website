<?php
declare(strict_types=1);

const TEGH_OUTPUT_CONTRACT_V5710 = '1.0';
const TEGH_OUTPUT_DEFINITION_V5710 = '5710.1';

/** @return array<string,mixed> */
function tegh_output_json_object(mixed $raw): array
{
    if(!is_string($raw)||trim($raw)==='')return [];
    try{$value=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($value)?$value:[];}catch(Throwable){return [];}
}

function tegh_output_safe_logo(mixed $value): ?string
{
    $logo=trim((string)$value);
    if($logo===''||strlen($logo)>2_500_000)return null;
    return preg_match('#^data:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\r\n]+$#',$logo)?$logo:null;
}

function tegh_output_quantity(int $milli): string
{
    $whole=intdiv($milli,1000);$fraction=$milli%1000;
    return $fraction===0?(string)$whole:rtrim(rtrim($whole.'.'.str_pad((string)$fraction,3,'0',STR_PAD_LEFT),'0'),'.');
}

/** @param array<string,mixed> $model @return array<string,mixed> */
function tegh_output_seal(array $user,array $company,array $model,string $definitionKey,array $parameters): array
{
    if(!empty($GLOBALS['tegh_report_building_5980']))return $model;

    $generatedAt=gmdate('Y-m-d\TH:i:s\Z');
    $rowCount=count((array)($model['rows']??[]));
    $dataMaterial=['definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5710,'parameters'=>$parameters,
        'columns'=>$model['columns']??[],'rows'=>$model['rows']??[],'totals'=>$model['totals']??[]];
    $sourceRevisionHash=hash('sha256',json_encode($dataMaterial,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $totalsDigest=hash('sha256',json_encode($model['totals']??[],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $companyScope=substr(hash('sha256',(string)$company['id']),0,16);
    $referenceMaterial=json_encode(['companyScope'=>$companyScope,'definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5710,
        'parameters'=>$parameters,'basis'=>(string)$company['accounting_basis'],'currency'=>(string)($model['currency']??$company['currency']),
        'generatedAt'=>$generatedAt,'actorId'=>(string)$user['id'],'sourceRevisionHash'=>$sourceRevisionHash,'rowCount'=>$rowCount,'totalsDigest'=>$totalsDigest],
        JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $digest=function_exists('secret_hash')?secret_hash($referenceMaterial):hash('sha256',$referenceMaterial);
    $reference='TVO-'.strtoupper(substr($digest,0,20));
    $model['contractVersion']=TEGH_OUTPUT_CONTRACT_V5710;
    $model['definitionVersion']=TEGH_OUTPUT_DEFINITION_V5710;
    $model['parameters']=$parameters;
    $model['rowCount']=$rowCount;
    $model['verifiedOutput']=[
        'reference'=>$reference,'generatedAt'=>$generatedAt,'companyTimezone'=>'America/Toronto',
        'sourceRevisionHash'=>$sourceRevisionHash,'totalsDigest'=>$totalsDigest,'rowCount'=>$rowCount,
        'statement'=>'Reproducible Tegh output reference; this is not an audit opinion or legal certification.',
    ];
    $model['accountingWrites']=0;$model['providerAttempts']=0;
    audit_event($user,(string)$company['id'],'professional_output.generated','professional_output',$reference,[
        'definitionKey'=>$definitionKey,'definitionVersion'=>TEGH_OUTPUT_DEFINITION_V5710,'parameters'=>$parameters,
        'sourceRevisionHash'=>$sourceRevisionHash,'totalsDigest'=>$totalsDigest,'rowCount'=>$rowCount,
        'accountingWrites'=>0,'providerAttempts'=>0,
    ]);
    return $model;
}

/** @return array<string,mixed> */
function tegh_output_invoice_model(array $user,array $company,string $invoiceId): array
{
    $companyId=(string)$company['id'];
    $stmt=db()->prepare("SELECT i.* FROM invoices i WHERE i.id=? AND i.company_id=? LIMIT 1");
    $stmt->execute([$invoiceId,$companyId]);$invoice=$stmt->fetch();
    if(!$invoice)fail('That invoice output is unavailable.',404,'invoice_output_unavailable',false);
    $template=tegh_output_json_object($invoice['template_snapshot_json']??null);
    $customer=tegh_output_json_object($invoice['customer_snapshot_json']??null);
    // Debit-note invoices posted before snapshots were copied keep their original
    // invoice's recorded identity. Do not substitute a mutable customer master.
    if($customer===[] && function_exists('schema_table_exists') && schema_table_exists('accounting_notes')){
        $original=db()->prepare("SELECT src.customer_snapshot_json,src.template_snapshot_json
            FROM accounting_notes n JOIN invoices src ON src.company_id=n.company_id AND src.id=n.source_id
            WHERE n.company_id=? AND n.debit_document_id=? AND n.note_kind='customer_debit' AND n.source_type='invoice' LIMIT 1");
        $original->execute([$companyId,$invoiceId]);$source=$original->fetch();
        if($source){
            $customer=tegh_output_json_object($source['customer_snapshot_json']??null);
            if($template===[])$template=tegh_output_json_object($source['template_snapshot_json']??null);
        }
    }
    $lineStmt=db()->prepare('SELECT id,description,quantity_milli,unit_price_cents,tax_rate_bps,amount_cents,tax_cents,
        foreign_unit_price_cents,foreign_amount_cents,foreign_tax_cents,sort_order
        FROM invoice_lines WHERE invoice_id=? ORDER BY sort_order,id');
    $lineStmt->execute([$invoiceId]);$rows=[];
    $invoiceCurrency=(string)$invoice['currency'];$baseCurrency=(string)$company['currency'];
    foreach($lineStmt->fetchAll() as $line){
        $amount=$invoiceCurrency===$baseCurrency&&(int)$line['foreign_amount_cents']===0&&(int)$line['amount_cents']!==0?(int)$line['amount_cents']:(int)$line['foreign_amount_cents'];
        $unitPrice=$invoiceCurrency===$baseCurrency&&(int)$line['foreign_unit_price_cents']===0&&(int)$line['unit_price_cents']!==0?(int)$line['unit_price_cents']:(int)$line['foreign_unit_price_cents'];
        $tax=$invoiceCurrency===$baseCurrency&&(int)$line['foreign_tax_cents']===0&&(int)$line['tax_cents']!==0?(int)$line['tax_cents']:(int)$line['foreign_tax_cents'];
        $rows[]=['lineId'=>(string)$line['id'],'description'=>(string)$line['description'],'quantityMilli'=>(int)$line['quantity_milli'],
            'quantity'=>tegh_output_quantity((int)$line['quantity_milli']),'unit'=>'Each','unitPriceCents'=>$unitPrice,
            'taxRateBps'=>(int)$line['tax_rate_bps'],'amountCents'=>$amount,'taxCents'=>$tax,'lineTotalCents'=>$amount+$tax];
    }
    $foreignTotal=$invoiceCurrency===$baseCurrency&&(int)$invoice['foreign_total_cents']===0&&(int)$invoice['total_cents']!==0?(int)$invoice['total_cents']:(int)$invoice['foreign_total_cents'];
    $foreignSubtotal=$invoiceCurrency===$baseCurrency&&(int)$invoice['foreign_subtotal_cents']===0&&(int)$invoice['subtotal_cents']!==0?(int)$invoice['subtotal_cents']:(int)$invoice['foreign_subtotal_cents'];
    $foreignTax=$invoiceCurrency===$baseCurrency&&(int)$invoice['foreign_tax_cents']===0&&(int)$invoice['tax_cents']!==0?(int)$invoice['tax_cents']:(int)$invoice['foreign_tax_cents'];
    $foreignBalance=$invoiceCurrency===$baseCurrency&&(int)$invoice['foreign_balance_cents']===0&&(int)$invoice['balance_cents']!==0?(int)$invoice['balance_cents']:(int)$invoice['foreign_balance_cents'];
    $void=(string)$invoice['status']==='void';$paid=$void?0:max(0,$foreignTotal-$foreignBalance);$balanceDue=$void?0:$foreignBalance;
    $statusLabel=match((string)$invoice['status']){
        'draft'=>'Draft',
        'sent'=>$foreignBalance>0&&$foreignBalance<$foreignTotal
            ? ((string)$invoice['due_date']<canadian_today()?'Partially Paid · Overdue':'Partially Paid')
            : ((string)$invoice['due_date']<canadian_today()&&$foreignBalance>0?'Overdue':'Issued / Sent'),
        'paid'=>'Paid','void'=>'Void / Cancelled',default=>'Not recorded'
    };
    $businessName=trim((string)($template['businessName']??''));if($businessName==='')$businessName='Not recorded';
    $customerName=trim((string)($customer['name']??''));if($customerName==='')$customerName='Not recorded';
    $model=[
        'kind'=>'invoice','definitionKey'=>'customer_invoice','title'=>trim((string)($template['documentTitle']??''))?:'INVOICE',
        'orientation'=>'portrait','paper'=>'letter','currency'=>$invoiceCurrency,'accountingBasis'=>(string)$company['accounting_basis'],
        'document'=>[
            'invoiceId'=>(string)$invoice['id'],'number'=>(string)$invoice['number'],'status'=>(string)$invoice['status'],'statusLabel'=>$statusLabel,
            'watermark'=>$void?'VOID':((string)$invoice['status']==='draft'?'DRAFT':((string)$invoice['status']==='paid'?'PAID':null)),
            'issueDate'=>(string)$invoice['issue_date'],'dueDate'=>(string)$invoice['due_date'],'purchaseOrder'=>$invoice['purchase_order']!==null?(string)$invoice['purchase_order']:null,
            'importReference'=>$invoice['import_reference']!==null?(string)$invoice['import_reference']:null,'message'=>(string)$invoice['message'],
            'snapshotState'=>['template'=>$template!==[]?'recorded':'not_recorded','customer'=>$customer!==[]?'recorded':'not_recorded'],
        ],
        'issuer'=>[
            'fontFamily'=>in_array((string)($template['fontFamily']??''),['Arial','Inter','Georgia','Helvetica','Verdana'],true)?(string)$template['fontFamily']:'Inter',
            'layoutStyle'=>in_array((string)($template['layoutStyle']??''),['classic','modern','minimal','professional','compact'],true)?(string)$template['layoutStyle']:'classic',
            'businessName'=>$businessName,'legalName'=>trim((string)($template['legalName']??''))?:null,
            'address'=>trim((string)($template['businessAddress']??''))?:null,'email'=>trim((string)($template['businessEmail']??''))?:null,
            'phone'=>trim((string)($template['businessPhone']??''))?:null,'taxNumber'=>!empty($template['showTaxNumber'])?(trim((string)($template['taxNumber']??''))?:null):null,
            'logoData'=>tegh_output_safe_logo($template['logoData']??null),'accentColor'=>preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($template['accentColor']??''))?(string)$template['accentColor']:'#0F766E',
        ],
        'billTo'=>['name'=>$customerName,'contactName'=>trim((string)($customer['contactName']??''))?:null,
            'address'=>trim((string)($customer['billingAddress']??''))?:null,'email'=>trim((string)($customer['email']??''))?:null,'phone'=>trim((string)($customer['phone']??''))?:null],
        'shipTo'=>is_array($customer['shipTo']??null)?$customer['shipTo']:null,
        'columns'=>[
            ['key'=>'description','label'=>'Description','type'=>'text','width'=>36],['key'=>'quantity','label'=>'Qty','type'=>'quantity','width'=>8],
            ['key'=>'unit','label'=>'Unit','type'=>'text','width'=>8],['key'=>'unitPriceCents','label'=>'Unit price','type'=>'money','width'=>12],
            ['key'=>'taxRateBps','label'=>'Tax','type'=>'rate_bps','width'=>8],['key'=>'lineTotalCents','label'=>'Line total','type'=>'money','width'=>14],
        ],
        'rows'=>$rows,
        'totals'=>['subtotalCents'=>$foreignSubtotal,'discountCents'=>0,'taxCents'=>$foreignTax,'totalCents'=>$foreignTotal,
            'paymentsCreditsCents'=>$paid,'balanceDueCents'=>$balanceDue],
        'terms'=>!empty($template['showPaymentInstructions'])?(trim((string)($template['paymentInstructions']??''))?:null):null,
        'notes'=>trim((string)($template['footer']??''))?:null,
    ];
    return tegh_output_seal($user,$company,$model,'customer_invoice',['invoiceNumber'=>(string)$invoice['number'],'documentState'=>(string)$invoice['status'],'currency'=>$invoiceCurrency]);
}

/** @return array<string,mixed> */
function tegh_output_day_book_model(array $user,array $company,string $start,string $end,string $status): array
{
    $companyId=(string)$company['id'];$start=safe_date($start,'From date');$end=safe_date($end,'To date');
    if($start>$end)fail('The From date must be on or before the To date.',422,'output_period_invalid',false);
    if(!in_array($status,['all','posted','void'],true))fail('Day Book status is invalid.',422,'output_status_invalid',false);
    $sql="SELECT je.id,je.entry_date,je.source_type,je.source_id,je.memo,je.status,je.reversal_of_id,
        jl.id line_id,a.code account_code,a.name account_name,jl.memo line_memo,jl.debit_cents,jl.credit_cents,
        COALESCE(v.voucher_number,'Journal entry') document_number
        FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id
        JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id
        LEFT JOIN vouchers v ON v.company_id=je.company_id AND v.journal_entry_id=je.id
        WHERE je.company_id=? AND je.entry_date BETWEEN ? AND ?";$params=[$companyId,$start,$end];
    if($status==='posted')$sql.=" AND je.status='posted'";elseif($status==='void')$sql.=" AND je.status<>'posted'";
    $sql.=' ORDER BY je.entry_date,je.created_at,je.id,jl.id LIMIT 25000';$stmt=db()->prepare($sql);$stmt->execute($params);
    $rows=[];$debits=0;$credits=0;
    foreach($stmt->fetchAll() as $row){$debit=(int)$row['debit_cents'];$credit=(int)$row['credit_cents'];$debits+=$debit;$credits+=$credit;
        $rows[]=['date'=>(string)$row['entry_date'],'documentNumber'=>(string)$row['document_number'],'journalId'=>(string)$row['id'],
            'sourceType'=>(string)$row['source_type'],'sourceReference'=>(string)$row['source_id'],'status'=>(string)$row['status'],
            'description'=>trim((string)$row['line_memo'])!==''?(string)$row['line_memo']:(string)$row['memo'],
            'accountCode'=>(string)$row['account_code'],'accountName'=>(string)$row['account_name'],'debitCents'=>$debit,'creditCents'=>$credit,
            'reversalOfJournalId'=>$row['reversal_of_id']!==null?(string)$row['reversal_of_id']:null];
    }
    $model=['kind'=>'report','definitionKey'=>'day_book','title'=>'Day Book','orientation'=>'landscape','paper'=>'letter',
        'currency'=>(string)$company['currency'],'accountingBasis'=>(string)$company['accounting_basis'],
        'company'=>['name'=>(string)$company['name'],'legalName'=>(string)$company['legal_name']],
        'columns'=>[
            ['key'=>'date','label'=>'Date','type'=>'date','width'=>10],['key'=>'documentNumber','label'=>'Document','type'=>'text','width'=>12],
            ['key'=>'journalId','label'=>'Journal ID','type'=>'identifier','width'=>15],['key'=>'sourceType','label'=>'Source','type'=>'text','width'=>12],
            ['key'=>'description','label'=>'Description','type'=>'text','width'=>24],['key'=>'accountCode','label'=>'Account','type'=>'identifier','width'=>9],
            ['key'=>'accountName','label'=>'Account name','type'=>'text','width'=>18],['key'=>'debitCents','label'=>'Debit','type'=>'money','width'=>12],
            ['key'=>'creditCents','label'=>'Credit','type'=>'money','width'=>12],
        ],'rows'=>$rows,'totals'=>['debitCents'=>$debits,'creditCents'=>$credits,'differenceCents'=>$debits-$credits]];
    return tegh_output_seal($user,$company,$model,'day_book',['start'=>$start,'end'=>$end,'inclusive'=>true,'status'=>$status,'currency'=>(string)$company['currency']]);
}

/** Legacy URL compatibility: no independent source, sealing, permissions or totals. */
function handle_professional_output_v5710(string $action): never
{
    handle_report_output_5980(trim($action,'/'));
}
