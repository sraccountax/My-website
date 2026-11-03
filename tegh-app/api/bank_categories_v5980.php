<?php
declare(strict_types=1);

/** Category-only workbook import. Upload/preview are stateless reads. Only
 * explicit commit creates a durable receipt, audit event and category decisions.
 * No voucher, journal, bank status, tax, source or reconciliation service is used. */
const TEGH_CATEGORY_COLUMNS = ['Transaction ID','State Token','Import Batch','Bank Account','Date','Description','Reference','Money Out','Money In','Currency','Current GL Code','Current GL Name','GL Code','GL Account Name/Description','Notes'];

function tegh_category_json_request(int $limit=16777216): array
{
    $stream=fopen('php://input','rb');$raw=$stream?stream_get_contents($stream,$limit+1):false;if(is_resource($stream))fclose($stream);
    if($raw===false||strlen($raw)>$limit)fail('This category request exceeds the supported size.',413,'category_request_limit');
    try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){fail('Invalid category request.',422,'category_request_invalid');}
    if(!is_array($value)||array_is_list($value))fail('A category request object is required.',422,'category_request_invalid');return $value;
}
function tegh_category_sign(array $payload,string $purpose): string
{
    $body=rtrim(strtr(base64_encode(tegh_json_canonical($payload)),'+/','-_'),'=');
    return $body.'.'.hash_hmac('sha256','tegh.category.v1|'.$purpose.'|'.$body,(string)config('app.secret'));
}
function tegh_category_verify(string $token,string $purpose,int $max=16777216): array
{
    if(strlen($token)>$max||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$token,$m)||!hash_equals(hash_hmac('sha256','tegh.category.v1|'.$purpose.'|'.$m[1],(string)config('app.secret')),$m[2]))throw new TeghServiceFailure('The workbook reference is invalid or has been changed.',422,'category_token_invalid');
    $raw=base64_decode(strtr($m[1],'-_','+/'),true);try{$p=$raw===false?null:json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){$p=null;}
    if(!is_array($p)||($p['v']??null)!==1||!is_int($p['exp']??null)||$p['exp']<time())throw new TeghServiceFailure('The workbook or preview has expired. Download a fresh workbook.',409,'category_token_expired');return $p;
}
function tegh_category_state(array $row): string
{
    $keys=['id','company_id','bank_account_id','import_batch_id','transaction_date','description','reference','amount_cents','foreign_amount_cents','currency','exchange_rate_micros','source_hash','status','journal_entry_id','decided_account_id','suggested_account_id','tax_code','suggestion_source','confidence','updated_at'];$state=[];
    foreach($keys as $key)$state[$key]=isset($row[$key])?(string)$row[$key]:null;
    return hash('sha256',tegh_json_canonical($state));
}
function tegh_category_display_fields(array $r): array
{
    $amount=(int)$r['foreign_amount_cents'];if($amount===0)$amount=(int)$r['amount_cents'];
    return [(string)$r['id'],(string)($r['import_batch_id']??''),(string)$r['bank_name'],(string)$r['transaction_date'],(string)($r['full_description']??$r['description']),(string)($r['reference']??''),max(0,-$amount),max(0,$amount),(string)$r['currency'],(string)($r['current_code']??''),(string)($r['current_name']??'')];
}
function tegh_category_row_select(): string
{
    return 'SELECT bt.*,ba.name bank_name,ba.active bank_active,ba.ledger_account_id bank_ledger_id,ba.account_type bank_type,ca.code current_code,ca.name current_name,st.full_description FROM bank_transactions bt JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id LEFT JOIN accounts ca ON ca.id=bt.decided_account_id AND ca.company_id=bt.company_id LEFT JOIN bank_transaction_source_text st ON st.transaction_id=bt.id AND st.company_id=bt.company_id';
}
function tegh_category_fetch_rows(string $companyId,array $ids,bool $lock=false): array
{
    if(!$ids)return [];$out=[];
    // Deterministic global ID order across bounded query chunks.
    $ids=array_values(array_unique(array_map('strval',$ids)));sort($ids,SORT_STRING);
    foreach(array_chunk($ids,500) as $chunk){$q=db()->prepare(tegh_category_row_select().' WHERE bt.company_id=? AND bt.id IN ('.implode(',',array_fill(0,count($chunk),'?')).') ORDER BY bt.id'.($lock?' FOR UPDATE':''));$q->execute([$companyId,...$chunk]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['id']]=$r;}
    return $out;
}
function tegh_category_export(array $user,array $company,array $input): array
{
    $ids=$input['transactionIds']??null;$filters=$input['filters']??[];
    if($ids!==null){if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>TEGH_CATEGORY_WORKBOOK_LIMIT||count(array_unique($ids,SORT_STRING))!==count($ids))fail('Select between 1 and 5,000 unique pending transactions.',422,'category_row_limit');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))fail('Invalid transaction selection.',422,'category_selection_invalid');}
    elseif(!is_array($filters)||array_is_list($filters)&&$filters!==[])fail('Invalid pending selection filters.',422,'category_filters_invalid');
    $pdo=db();$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    try{
        if($ids===null){
            // Reuse the bank register's typed/parameterized filter predicates.
            unset($filters['cursor'],$filters['limit'],$filters['ids']);$filters['status']='pending';$f=tegh_bank_list_filters($filters)['filters'];[$where,$params]=tegh_bank_list_where((string)$company['id'],$f);
            $joins=" FROM bank_transactions bt JOIN companies review_company ON review_company.id=bt.company_id JOIN bank_accounts ba ON ba.id=bt.bank_account_id AND ba.company_id=bt.company_id LEFT JOIN import_batches ib ON ib.id=bt.import_batch_id AND ib.company_id=bt.company_id LEFT JOIN accounts suggested ON suggested.id=bt.suggested_account_id AND suggested.company_id=bt.company_id LEFT JOIN accounts decided ON decided.id=bt.decided_account_id AND decided.company_id=bt.company_id LEFT JOIN accounts review_account ON review_account.id=COALESCE(bt.decided_account_id,bt.suggested_account_id) AND review_account.company_id=bt.company_id LEFT JOIN accounts bank_ledger ON bank_ledger.id=ba.ledger_account_id AND bank_ledger.company_id=bt.company_id LEFT JOIN accounts gst_recoverable ON gst_recoverable.company_id=bt.company_id AND gst_recoverable.code='1100' LEFT JOIN accounts pst_recoverable ON pst_recoverable.company_id=bt.company_id AND pst_recoverable.code='1110' LEFT JOIN accounts gst_collected ON gst_collected.company_id=bt.company_id AND gst_collected.code='2100' LEFT JOIN accounts pst_collected ON pst_collected.company_id=bt.company_id AND pst_collected.code='2110'";
            $q=$pdo->prepare('SELECT bt.id'.$joins.' WHERE '.$where.' ORDER BY bt.transaction_date,bt.id LIMIT '.(TEGH_CATEGORY_WORKBOOK_LIMIT+1));tegh_bank_list_execute($q,$params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)>TEGH_CATEGORY_WORKBOOK_LIMIT)fail('More than 5,000 pending rows match. Narrow the filters; nothing was exported.',413,'category_row_limit');
        }
        if(!$ids)fail('No pending transactions match this selection.',422,'category_empty');$records=tegh_category_fetch_rows((string)$company['id'],$ids);
        if(count($records)!==count($ids))fail('One or more selected transactions are unavailable.',409,'category_selection_stale');
        $exportId=new_id('catexport');$expiry=time()+7*86400;$rows=[];
        foreach($records as $r){if($r['status']!=='pending'||$r['journal_entry_id']!==null)fail('Only unposted pending transactions can be exported.',409,'category_selection_stale');
            $fields=tegh_category_display_fields($r);$token=tegh_category_sign(['v'=>1,'c'=>(string)$company['id'],'u'=>(string)$user['id'],'id'=>(string)$r['id'],'s'=>tegh_category_state($r),'m'=>hash('sha256',tegh_json_canonical($fields)),'rev'=>$exportId,'exp'=>$expiry],'state');
            $cells=[$fields[0],$token,...array_slice($fields,1),'','',''];$rows[]=$cells;
        }
        $accounts=[];foreach(tegh_bank_category_catalogue((string)$company['id']) as $a){$in=tegh_bank_account_reason($a,1);$out=tegh_bank_account_reason($a,-1);if($in!==''&&$out!=='')continue;$accounts[]=[(string)$a['code'],(string)$a['name'],(string)$a['account_type'],$out===''?'Yes':'No',$in===''?'Yes':'No'];}
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $result=['kind'=>'category_workbook','contractVersion'=>1,'importType'=>'bank_transaction_categories','companyId'=>(string)$company['id'],'companyName'=>(string)($company['legal_name']?:$company['name']),'exportReference'=>$exportId,'expiresAt'=>gmdate('c',$expiry),'generatedAt'=>gmdate('c'),'columns'=>TEGH_CATEGORY_COLUMNS,'rows'=>$rows,'accounts'=>$accounts,'rowCount'=>count($rows),'immutableColumns'=>12,'editableColumns'=>['GL Code','GL Account Name/Description','Notes'],'accountingWrites'=>0,'databaseWrites'=>0,'instructions'=>[
        'Category only — 0 journals, 0 postings. Upload and preview are read-only. An explicit selected-row commit changes categorization only.',
        'Edit GL Code or an exact GL Account Name/Description. When both are filled, both must identify the same active current-company account. Blank category instructions skip a row; they never clear a category.',
        'Do not edit Transaction ID, State Token, Import Batch, financial account, date, description, reference, amounts, currency or current category. Do not add duplicate transaction IDs or formulas/macros.',
        'Tax treatment is preserved. Transfers, customer/vendor matches, protected controls, linked financial ledgers and opposite-direction contra entries cannot be changed by this workbook. Use Review Transactions for those workflows.',
        'Notes are preview-only and are not posted or retained as statement evidence. The protected sheets are usability aids, not security; all decisions are revalidated by the server.',
        'This workbook is bound to the exporting user and company. It expires after seven days. A signed preview expires after one hour. Re-export after a transaction or its category/tax changes.',
        'All selected valid rows commit atomically or none do. Unselected, invalid and blank rows stay unchanged. After a lost response, use the same operation reference to recover the exact receipt.'
    ]];
    if(strlen(tegh_json_canonical($result))>24000000)fail('The complete workbook exceeds the safe output-size limit. Narrow the selection.',413,'category_output_limit');return $result;
}

/** Safe OOXML parsing. No browser-decided rows are accepted as authoritative. */
function tegh_category_xml(string $xml): DOMDocument
{
    if(strlen($xml)>48000000||preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i',$xml))throw new TeghServiceFailure('Workbook XML declarations or size are unsafe.',422,'category_workbook_unsafe');
    $previous=libxml_use_internal_errors(true);try{$doc=new DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;if(!$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS))throw new TeghServiceFailure('Workbook XML is malformed.',422,'category_workbook_invalid');return $doc;}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
}
function tegh_category_xlsx_rows(string $path): array
{
    if(!class_exists('ZipArchive')||!class_exists('DOMDocument'))fail('Category workbook upload requires the PHP zip and XML extensions. No data has changed.',503,'category_parser_unavailable');
    if(filesize($path)>12000000)fail('Workbook upload is limited to 12 MB.',413,'category_workbook_size');
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::RDONLY)!==true)fail('Choose an unencrypted .xlsx workbook.',422,'category_workbook_invalid');
    try{
        if($zip->numFiles>200)throw new TeghServiceFailure('The workbook contains too many parts.',422,'category_workbook_unsafe');$names=[];$size=0;
        for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=(string)$s['name'];$lower=strtolower($name);if(isset($names[$lower])||str_contains($name,'\\')||preg_match('~(?:^/|(?:^|/)\.\.(?:/|$)|\x00)~',$name)||preg_match('~(?:vba|macros|embeddings/|externalLinks/|connections\.xml|queryTables/|activeX/)~i',$name))throw new TeghServiceFailure('Macros, external content, duplicate or unsafe workbook parts are not permitted.',422,'category_workbook_unsafe');$names[$lower]=true;$size+=(int)$s['size'];if($size>64000000||(int)$s['size']>48000000)throw new TeghServiceFailure('The expanded workbook exceeds its safe limit.',413,'category_workbook_size');
            if(str_ends_with($lower,'.rels')){$doc=tegh_category_xml((string)$zip->getFromIndex($i));foreach($doc->getElementsByTagNameNS('*','Relationship') as $rel)if(strtolower($rel->getAttribute('TargetMode'))==='external')throw new TeghServiceFailure('External workbook links are not permitted.',422,'category_workbook_unsafe');}
            if(preg_match('~^xl/worksheets/[^/]+\.xml$~i',$name)){$doc=tegh_category_xml((string)$zip->getFromIndex($i));if($doc->getElementsByTagNameNS('*','f')->length)throw new TeghServiceFailure('Formula cells are not permitted. Use values only.',422,'category_workbook_formulas');}
        }
        $workbook=$zip->getFromName('xl/workbook.xml');$rels=$zip->getFromName('xl/_rels/workbook.xml.rels');if($workbook===false||$rels===false)throw new TeghServiceFailure('Required workbook parts are missing.',422,'category_workbook_invalid');
        $doc=tegh_category_xml($workbook);$date1904=$doc->getElementsByTagNameNS('*','workbookPr')->item(0)?->getAttribute('date1904');if(in_array($date1904,['1','true'],true))throw new TeghServiceFailure('The workbook uses an unsupported date system. Start with the Tegh workbook.',422,'category_date_system');
        $relationshipId=null;$sheetNames=[];foreach($doc->getElementsByTagNameNS('*','sheet') as $sheet){$sheetNames[]=$sheet->getAttribute('name');if($sheet->getAttribute('name')==='Transactions')$relationshipId=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');}
        sort($sheetNames);if($sheetNames!==['Accounts','Instructions','Transactions']||!$relationshipId)throw new TeghServiceFailure('Use the Instructions, Transactions and Accounts sheets from the exported workbook.',422,'category_workbook_sheets');
        $target=null;foreach(tegh_category_xml($rels)->getElementsByTagNameNS('*','Relationship') as $r)if($r->getAttribute('Id')===$relationshipId)$target=$r->getAttribute('Target');if(!$target||!preg_match('~^(?:/xl/)?(?:worksheets/)[A-Za-z0-9_-]+\.xml$~D',$target))throw new TeghServiceFailure('Invalid Transactions sheet relationship.',422,'category_workbook_invalid');$target=str_starts_with($target,'/xl/')?ltrim($target,'/'):'xl/'.$target;
        $strings=[];$shared=$zip->getFromName('xl/sharedStrings.xml');if($shared!==false){$doc=tegh_category_xml($shared);foreach($doc->getElementsByTagNameNS('*','si') as $si){$value='';foreach($si->getElementsByTagNameNS('*','t') as $t)$value.=$t->textContent;$strings[]=$value;if(count($strings)>100000)throw new TeghServiceFailure('Too many workbook strings.',413,'category_workbook_size');}}
        $xml=$zip->getFromName($target);if($xml===false)throw new TeghServiceFailure('Transactions sheet is missing.',422,'category_workbook_invalid');$doc=tegh_category_xml($xml);$rows=[];$seenRows=[];
        foreach($doc->getElementsByTagNameNS('*','row') as $row){$rowNo=(int)$row->getAttribute('r');if($rowNo<1||isset($seenRows[$rowNo]))throw new TeghServiceFailure('Duplicate or invalid worksheet row.',422,'category_workbook_invalid');$seenRows[$rowNo]=true;$cells=array_fill(0,15,'');$seen=[];
            foreach($row->getElementsByTagNameNS('*','c') as $cell){$ref=$cell->getAttribute('r');if(!preg_match('/^([A-O])([1-9][0-9]*)$/D',$ref,$m)||(int)$m[2]!==$rowNo)throw new TeghServiceFailure('Unsupported or misplaced worksheet cell.',422,'category_workbook_invalid');$col=ord($m[1])-65;if(isset($seen[$col]))throw new TeghServiceFailure('Duplicate worksheet cell.',422,'category_workbook_invalid');$seen[$col]=true;$type=$cell->getAttribute('t');$v=$cell->getElementsByTagNameNS('*','v')->item(0)?->textContent??'';
                if($type==='s'){if(!ctype_digit($v)||!array_key_exists((int)$v,$strings))throw new TeghServiceFailure('Invalid shared string reference.',422,'category_workbook_invalid');$v=$strings[(int)$v];}
                elseif($type==='inlineStr'){$v='';foreach($cell->getElementsByTagNameNS('*','t') as $t)$v.=$t->textContent;}
                elseif(!in_array($type,['','n','str','d'],true))throw new TeghServiceFailure('Unsupported cell value type.',422,'category_workbook_invalid');
                if(strlen($v)>60000)throw new TeghServiceFailure('A workbook cell is too large.',413,'category_workbook_size');$cells[$col]=$v;
            }
            if($rowNo===1){if($cells!==TEGH_CATEGORY_COLUMNS)throw new TeghServiceFailure('Workbook headers have changed. Use a fresh Tegh workbook.',422,'category_workbook_headers');continue;}
            if(!array_filter($cells,static fn($v)=>$v!==''))continue;$rows[]=['sourceRow'=>$rowNo,'cells'=>$cells];if(count($rows)>TEGH_CATEGORY_WORKBOOK_LIMIT)throw new TeghServiceFailure('The workbook exceeds 5,000 transaction rows.',413,'category_row_limit');
        }
        if(!isset($seenRows[1])||!$rows)throw new TeghServiceFailure('The workbook has no transaction rows.',422,'category_empty');return $rows;
    }finally{$zip->close();}
}
function tegh_category_normalized_name(string $name): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u',' ',str_replace("\xC2\xA0",' ',$name))??''),'UTF-8');
}
function tegh_category_cell_text(string $value): string
{
    // Reverse only our export's formula-injection escape, not arbitrary apostrophes.
    return preg_match('/^\'[\s\x{FEFF}]*[=+\-@]/u',$value)?substr($value,1):$value;
}
function tegh_category_immutable(array $cells): array
{
    $date=$cells[4];if(preg_match('/^\d+(?:\.0+)?$/D',$date)){$serial=(int)$date;if($serial<61||$serial>2958465)throw new TeghServiceFailure('Invalid Excel date.',422,'category_immutable_changed');$date=(new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify('+'.$serial.' days')->format('Y-m-d');}
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))throw new TeghServiceFailure('Immutable dates must remain real Excel date cells.',422,'category_immutable_changed');
    $error=null;$out=di_money_cents($cells[7],$error);if($error!==null)throw new TeghServiceFailure('Immutable Money Out changed.',422,'category_immutable_changed');$in=di_money_cents($cells[8],$error);if($error!==null)throw new TeghServiceFailure('Immutable Money In changed.',422,'category_immutable_changed');
    return [tegh_category_cell_text($cells[0]),tegh_category_cell_text($cells[2]),tegh_category_cell_text($cells[3]),$date,tegh_category_cell_text($cells[5]),tegh_category_cell_text($cells[6]),$out,$in,tegh_category_cell_text($cells[9]),tegh_category_cell_text($cells[10]),tegh_category_cell_text($cells[11])];
}
function tegh_category_resolve_instruction(string $code,string $name,array $catalogue): ?array
{
    $code=trim($code);$name=trim($name);if($code===''&&$name==='')return null;
    foreach([$code,$name] as $text)if(preg_match('/^[\s\x{FEFF}]*[=+\-@]/u',$text))throw new TeghServiceFailure('Formula-like category instructions are not permitted.',422,'category_formula_instruction');
    $byCode=[];$byName=[];foreach($catalogue as $a){if($code!==''&&(string)$a['code']===$code)$byCode[]=$a;if($name!==''&&tegh_category_normalized_name((string)$a['name'])===tegh_category_normalized_name($name))$byName[]=$a;}
    if($code!==''&&count($byCode)!==1)throw new TeghServiceFailure('GL Code must match exactly one current-company account.',422,'category_code_invalid');
    if($name!==''&&count($byName)!==1)throw new TeghServiceFailure('GL Account Name must match one unique normalized exact name.',422,'category_name_invalid');
    if($code!==''&&$name!==''&&$byCode[0]['id']!==$byName[0]['id'])throw new TeghServiceFailure('GL Code and name identify different accounts.',422,'category_instruction_conflict');return $code!==''?$byCode[0]:$byName[0];
}
function tegh_category_preview(array $user,array $company,array $fileRows): array
{
    $companyId=(string)$company['id'];$catalogue=tegh_bank_category_catalogue($companyId);$ids=[];$counts=[];
    foreach($fileRows as $row){$id=(string)$row['cells'][0];$counts[$id]=($counts[$id]??0)+1;if(preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))$ids[]=$id;}
    $records=tegh_category_fetch_rows($companyId,$ids);$rows=[];$decisions=[];$summary=['valid'=>0,'invalid'=>0,'stale'=>0,'skipped'=>0,'unchanged'=>0];
    foreach($fileRows as $source){$cells=$source['cells'];$id=(string)$cells[0];$record=$records[$id]??null;$row=['transactionId'=>$id,'sourceRow'=>$source['sourceRow'],'description'=>'','date'=>'','currentCode'=>'','currentName'=>'','proposedCode'=>trim($cells[12]),'proposedName'=>trim($cells[13]),'notes'=>mb_substr($cells[14],0,1000),'status'=>'invalid','issue'=>'','selectable'=>false];
        try{
            if(($counts[$id]??0)!==1)throw new TeghServiceFailure('Duplicate Transaction ID: every duplicate occurrence is invalid.',422,'category_duplicate_id');
            $state=tegh_category_verify((string)$cells[1],'state',4096);if(($state['c']??null)!==$companyId||($state['u']??null)!==(string)$user['id']||($state['id']??null)!==$id)throw new TeghServiceFailure('This transaction or workbook is unavailable in your current scope.',403,'category_scope_invalid');
            if(!hash_equals((string)$state['m'],hash('sha256',tegh_json_canonical(tegh_category_immutable($cells)))))throw new TeghServiceFailure('One or more protected display fields have changed.',422,'category_immutable_changed');
            if(!$record||$record['status']!=='pending'||$record['journal_entry_id']!==null||!hash_equals((string)$state['s'],tegh_category_state($record)))throw new TeghServiceFailure('This transaction changed after export. Download a fresh workbook.',409,'category_state_stale');
            if(!empty($record['decided_account_id'])){foreach($catalogue as $current)if((string)$current['id']===(string)$record['decided_account_id']&&!empty($current['linked_bank_id']))throw new TeghServiceFailure('Transfer decisions must be reviewed in the Transfer workflow.',422,'category_transfer_unavailable');}
            $row['description']=(string)$record['description'];$row['date']=(string)$record['transaction_date'];$row['currentCode']=(string)($record['current_code']??'');$row['currentName']=(string)($record['current_name']??'');
            foreach([$cells[12],$cells[13],$cells[14]] as $value)if(preg_match('/^[\s\x{FEFF}]*[=+\-@]/u',$value))throw new TeghServiceFailure('Formula-like editable values are not permitted.',422,'category_formula_instruction');
            $a=tegh_category_resolve_instruction($cells[12],$cells[13],$catalogue);if($a===null){$row['status']='skipped';$row['issue']='Blank proposed category — no change.';$summary['skipped']++;$rows[]=$row;continue;}
            $reason=tegh_bank_account_reason($a,(int)$record['amount_cents']);if($reason!=='')throw new TeghServiceFailure($reason,422,'category_account_invalid');
            if(empty($record['bank_active'])||!in_array((string)$record['bank_type'],['bank','credit_card'],true))throw new TeghServiceFailure('The statement financial account is inactive or unavailable.',409,'category_state_stale');
            $unchanged=(string)($record['decided_account_id']??'')===(string)$a['id'];$row['proposedCode']=(string)$a['code'];$row['proposedName']=(string)$a['name'];$row['status']=$unchanged?'unchanged':'valid';$row['selectable']=true;$row['issue']=$unchanged?'Already categorized to this account.':'Category only — tax and posting state preserved.';$summary[$row['status']]++;
            $decisions[$id]=['token'=>(string)$cells[1],'accountId'=>(string)$a['id'],'accountHash'=>hash('sha256',tegh_json_canonical([$a['id'],$a['code'],$a['name'],$a['account_type'],(bool)$a['active']])),'notesHash'=>hash('sha256',(string)$cells[14])];
        }catch(TeghServiceFailure $e){$row['status']=$e->errorCode==='category_state_stale'||$e->errorCode==='category_token_expired'?'stale':'invalid';$row['issue']=$e->getMessage();$summary[$row['status']]++;}
        $rows[]=$row;
    }
    $payload=['v'=>1,'c'=>$companyId,'u'=>(string)$user['id'],'exp'=>time()+3600,'previewId'=>new_id('catpreview'),'rows'=>$decisions,'summary'=>$summary,'rowCount'=>count($rows)];
    return ['importType'=>'bank_transaction_categories','companyId'=>$companyId,'previewReference'=>$payload['previewId'],'previewToken'=>tegh_category_sign($payload,'preview'),'expiresAt'=>gmdate('c',$payload['exp']),'rows'=>$rows,'summary'=>$summary,'rowCount'=>count($rows),'accountingWrites'=>0,'databaseWrites'=>0,'disclosure'=>'Category only — 0 journals, 0 postings.'];
}
function tegh_category_commit(array $user,array $company,array $input): array
{
    if(($input['confirmed']??false)!==true)fail('Explicitly confirm category-only changes.',422,'confirmation_required');$key=tegh_bank_operation_key($input['operationKey']??null);$token=(string)($input['previewToken']??'');$ids=$input['selectedTransactionIds']??null;
    if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>TEGH_CATEGORY_WORKBOOK_LIMIT)fail('Choose between 1 and 5,000 valid preview rows.',422,'category_selection_invalid');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id))fail('Invalid transaction selection.',422,'category_selection_invalid');if(count(array_unique($ids))!==count($ids))fail('Duplicate selected IDs are not permitted.',422,'category_duplicate_id');sort($ids,SORT_STRING);
    $previewHash=hash('sha256',$token);$payloadHash=hash('sha256',tegh_json_canonical(['previewHash'=>$previewHash,'ids'=>$ids,'confirmed'=>true]));
    return tegh_service_boundary(fn()=>db_transaction_retry(function()use($user,$company,$key,$token,$ids,$previewHash,$payloadHash):array{
        $fresh=tegh_bank_reauthorize_mutation($user,$company);$q=db()->prepare('SELECT payload_hash,result_json FROM bank_category_commits WHERE company_id=? AND user_id=? AND operation_key=? FOR UPDATE');$q->execute([$fresh['id'],$user['id'],$key]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if($old){if(!hash_equals((string)$old['payload_hash'],$payloadHash))fail('This operation reference belongs to different category decisions.',409,'idempotency_payload_conflict');$result=json_decode((string)$old['result_json'],true,32,JSON_THROW_ON_ERROR);$result['idempotentReplay']=true;return $result;}
        // Replay lookup deliberately precedes expiry checks; a committed receipt remains recoverable.
        $preview=tegh_category_verify($token,'preview');if(($preview['c']??null)!==(string)$fresh['id']||($preview['u']??null)!==(string)$user['id'])fail('This preview is unavailable in your current scope.',403,'category_scope_invalid');
        foreach($ids as $id)if(!isset($preview['rows'][$id]))fail('Only selectable rows from this preview may be committed.',422,'category_selection_invalid');
        // Parent company/membership and receipt-key locks serialize the preview's commits.
        $q=db()->prepare('SELECT id FROM bank_category_commits WHERE company_id=? AND user_id=? AND preview_hash=? FOR UPDATE');$q->execute([$fresh['id'],$user['id'],$previewHash]);if($q->fetchColumn())fail('This preview has already been committed. Recover its original receipt or create a fresh preview.',409,'category_preview_consumed');
        $records=tegh_category_fetch_rows((string)$fresh['id'],$ids,true);if(count($records)!==count($ids))fail('A selected transaction is no longer available. Nothing was applied.',409,'category_state_stale');$validated=[];$auditRows=[];$unchanged=0;
        foreach($ids as $id){$d=$preview['rows'][$id];$state=tegh_category_verify((string)$d['token'],'state',4096);$r=$records[$id];
            if(($state['c']??null)!==(string)$fresh['id']||($state['u']??null)!==(string)$user['id']||($state['id']??null)!==$id||$r['status']!=='pending'||$r['journal_entry_id']!==null||!hash_equals((string)$state['s'],tegh_category_state($r)))fail('A selected transaction changed. Nothing was applied; upload a fresh workbook.',409,'category_state_stale');
            if(empty($r['bank_active'])||!in_array((string)$r['bank_type'],['bank','credit_card'],true))fail('A selected financial account is unavailable.',409,'category_state_stale');assert_period_open((string)$fresh['id'],(string)$r['transaction_date']);$a=tegh_bank_category((string)$fresh['id'],(string)$d['accountId'],(int)$r['amount_cents'],false,true);
            if(!hash_equals((string)$d['accountHash'],hash('sha256',tegh_json_canonical([$a['id'],$a['code'],$a['name'],$a['account_type'],(bool)$a['active']]))))fail('An account changed after preview. Nothing was applied.',409,'category_account_stale');
            if((string)($r['decided_account_id']??'')===(string)$a['id']){$unchanged++;continue;}$validated[]=['id'=>$id,'accountId'=>(string)$a['id']];$auditRows[]=['transactionIdHash'=>hash('sha256',$id),'beforeAccountId'=>$r['decided_account_id'],'afterAccountId'=>$a['id'],'beforeStateHash'=>tegh_category_state($r),'taxHash'=>hash('sha256',(string)$r['tax_code'])];
        }
        $q=db()->prepare("UPDATE bank_transactions SET decided_account_id=?,suggestion_source='manual',confidence=100,ai_explanation=NULL WHERE company_id=? AND id=? AND status='pending' AND journal_entry_id IS NULL");foreach($validated as $d){$q->execute([$d['accountId'],$fresh['id'],$d['id']]);if($q->rowCount()!==1)throw new RuntimeException('Category mutation affected an unexpected row count.');}
        $id=new_id('catreceipt');$result=['receiptId'=>$id,'operationKey'=>$key,'previewReference'=>$preview['previewId'],'companyId'=>(string)$fresh['id'],'importType'=>'bank_transaction_categories','applied'=>count($validated),'unchanged'=>$unchanged,'skipped'=>(int)$preview['rowCount']-count($ids)-(int)$preview['summary']['invalid']-(int)$preview['summary']['stale'],'invalid'=>(int)$preview['summary']['invalid'],'stale'=>(int)$preview['summary']['stale'],'selected'=>count($ids),'journalsCreated'=>0,'vouchersCreated'=>0,'postingsCreated'=>0,'taxChanged'=>false,'statusesChanged'=>false,'idempotentReplay'=>false,'committedAt'=>gmdate('c'),'reviewRoute'=>'/app.html#tegh=bank-review'];
        db()->prepare('INSERT INTO bank_category_commits(id,company_id,user_id,operation_key,payload_hash,preview_hash,result_json) VALUES(?,?,?,?,?,?,?)')->execute([$id,$fresh['id'],$user['id'],$key,$payloadHash,$previewHash,tegh_json_canonical($result)]);
        audit_event($user,(string)$fresh['id'],'bank_transaction_categories.committed','bank_category_commit',$id,['selectedCount'=>count($ids),'applied'=>count($validated),'unchanged'=>$unchanged,'payloadHash'=>$payloadHash,'decisions'=>$auditRows,'journals'=>0,'postings'=>0]);return $result;
    }));
}
function handle_tegh_bank_categories(string $action): never
{
    $user=require_user();$company=require_company($user);require_company_permission($company,'banking.match');tegh_schema44_require();header('Cache-Control: private, no-store');
    try{
        if($action==='receipt'){require_method('GET');$key=tegh_bank_operation_key($_GET['operationKey']??null);$q=db()->prepare('SELECT result_json FROM bank_category_commits WHERE company_id=? AND user_id=? AND operation_key=?');$q->execute([$company['id'],$user['id'],$key]);$json=$q->fetchColumn();if($json===false)fail('No category commit is confirmed for this reference.',404,'category_receipt_not_found');$result=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);$result['idempotentReplay']=true;json_response($result);}
        require_method('POST');require_csrf();
        if($action==='workbook')json_response(tegh_service_boundary(fn()=>tegh_category_export($user,$company,tegh_category_json_request())));
        if($action==='preview'){$file=$_FILES['file']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION))!=='xlsx')fail('Upload the completed .xlsx categorization workbook.',422,'category_file_required');json_response(tegh_service_boundary(fn()=>tegh_category_preview($user,$company,tegh_category_xlsx_rows((string)$file['tmp_name']))));}
        if($action==='commit')json_response(tegh_category_commit($user,$company,tegh_category_json_request()));
        fail('Category workflow action not found.',404,'category_action_not_found');
    }catch(TeghServiceFailure $e){tegh_fail_service($e);}catch(InvalidArgumentException $e){fail($e->getMessage(),422,'category_filters_invalid');}
}
