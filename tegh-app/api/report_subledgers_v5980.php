<?php
declare(strict_types=1);

/**
 * Dated subledger projections, not a posting engine.
 *
 * The model uses the present/restated books, as the existing GL does: an ordinary
 * soft-voided source is excluded from every period. Posted reversal journals and
 * opening-document void adjustments remain effective on their recorded dates.
 * A historical as-of date is never implemented by reusing today's balance_cents.
 */
function tegh_subledger_sources_5980(array $company, bool $ar): array
{
    $cid = (string)$company['id'];
    $partyType = $ar ? 'customer' : 'vendor';
    $partyTable = $ar ? 'customers' : 'vendors';
    $documentTable = $ar ? 'invoices' : 'bills';
    $partyKey = $ar ? 'customer_id' : 'vendor_id';
    $dateKey = $ar ? 'issue_date' : 'bill_date';
    $documentType = $ar ? 'invoice' : 'bill';
    $statuses = $ar ? "('sent','paid')" : "('open','paid')";
    $hasDebitNotes=$ar&&schema_table_exists('accounting_notes');
    $debitColumns=$hasDebitNotes?'dn.void_date debitNoteVoidDate,dnr.id debitNoteReversalJournalId,dnr.status debitNoteReversalStatus':'NULL debitNoteVoidDate,NULL debitNoteReversalJournalId,NULL debitNoteReversalStatus';
    $debitJoin=$hasDebitNotes?"LEFT JOIN accounting_notes dn ON dn.company_id=d.company_id AND dn.debit_document_id=d.id AND dn.note_kind='customer_debit' LEFT JOIN journal_entries dnr ON dnr.id=dn.reversal_journal_entry_id AND dnr.company_id=d.company_id":'';
    $debitVoid=$hasDebitNotes?" OR dn.status='void'":'';
    // Identifiers above are application constants; all values are parameterized.
    $parties = tegh_report_query_5980("SELECT id,name,email,active FROM $partyTable WHERE company_id=? ORDER BY name,id", [$cid]);
    $documents = tegh_report_query_5980(
        "SELECT d.id,d.$partyKey partyId,d.number,d.$dateKey documentDate,d.due_date dueDate,
            d.status,d.total_cents totalCents,d.balance_cents currentBalanceCents,
            d.original_paid_cents originalPaidCents,d.is_opening_document isOpening,
            d.currency,d.import_reference reference,d.issued_journal_entry_id journalId,
            ij.status issuedJournalStatus,oi.cutover_date cutoverDate,
            oi.journal_entry_id openingJournalId,oj.status openingJournalStatus,
            vj.id openingVoidJournalId,vj.entry_date openingVoidDate,vj.status openingVoidStatus,
            $debitColumns,
            (SELECT COALESCE(SUM(vl.credit_cents),0) FROM journal_lines vl WHERE vl.journal_entry_id=vj.id) openingVoidCents
         FROM $documentTable d
         LEFT JOIN journal_entries ij ON ij.id=d.issued_journal_entry_id AND ij.company_id=d.company_id
         LEFT JOIN opening_document_imports oi ON oi.id=d.opening_import_id AND oi.company_id=d.company_id
         LEFT JOIN journal_entries oj ON oj.id=oi.journal_entry_id AND oj.company_id=oi.company_id
         LEFT JOIN journal_entries vj ON vj.company_id=d.company_id AND vj.source_type=? AND vj.source_id=d.id
         $debitJoin
         WHERE d.company_id=? AND (d.status IN $statuses OR (d.status='void' AND (d.is_opening_document=1$debitVoid)))
         ORDER BY d.$dateKey,d.id", ['opening_'.$documentType.'_void', $cid]
    );
    $openings = tegh_report_query_5980(
        'SELECT o.id,o.party_id partyId,o.effective_date effectiveDate,o.amount_cents amountCents,
                o.journal_entry_id journalId,je.status journalStatus
         FROM party_opening_balances o
         LEFT JOIN journal_entries je ON je.id=o.journal_entry_id AND je.company_id=o.company_id
         WHERE o.company_id=? AND o.party_type=? ORDER BY o.effective_date,o.id', [$cid,$partyType]
    );
    $payments = tegh_report_query_5980(
        "SELECT pp.id,pp.party_id partyId,pp.document_id documentId,pp.payment_date paymentDate,pp.flow_kind flowKind,
                pp.reference,pp.memo,pp.amount_cents amountCents,pp.applied_cents appliedCents,
                pp.status,pp.journal_entry_id journalId,je.status journalStatus,
                je.source_type journalSourceType,pp.bank_transaction_id bankTransactionId,
                rje.id reversalJournalId,rje.entry_date reversalDate,rje.status reversalStatus,
                aje.id applicationJournalId,aje.entry_date applicationDate,aje.status applicationStatus,
                arje.id applicationReversalJournalId,arje.entry_date applicationReversalDate,arje.status applicationReversalStatus
         FROM party_payments pp
         LEFT JOIN journal_entries je ON je.id=pp.journal_entry_id AND je.company_id=pp.company_id
         LEFT JOIN journal_entries rje ON rje.id=pp.reversal_journal_entry_id AND rje.company_id=pp.company_id
         LEFT JOIN journal_entries aje ON aje.company_id=pp.company_id AND aje.source_type=? AND aje.source_id=pp.id
         LEFT JOIN journal_entries arje ON arje.company_id=pp.company_id AND arje.source_type=? AND arje.source_id=pp.id
         WHERE pp.company_id=? AND pp.payment_type=? ORDER BY pp.payment_date,pp.id",
        [$partyType.'_advance_application',$partyType.'_advance_application_reversal',$cid,$partyType]
    );
    $applications = tegh_report_query_5980(
        "SELECT pa.id,pa.payment_id paymentId,pa.document_type documentType,pa.document_id documentId,
                pa.partner_type partnerType,pa.partner_id partnerId,pa.application_date applicationDate,
                pa.foreign_amount_cents foreignAmountCents,pa.payment_carrying_cents paymentCarryingCents,
                pa.document_carrying_cents documentCarryingCents,pa.recognition_journal_entry_id recognitionJournalId,
                pa.status,pa.reversal_of_id reversalOfId
         FROM party_payment_applications pa JOIN party_payments pp ON pp.id=pa.payment_id AND pp.company_id=pa.company_id
         WHERE pa.company_id=? AND pp.payment_type=? ORDER BY pa.application_date,pa.id", [$cid,$partyType]
    );
    $audit = tegh_report_query_5980(
        'SELECT id,entity_id paymentId,action,metadata_json metadataJson FROM audit_log
         WHERE company_id=? AND action IN (?,?,?) ORDER BY created_at,id',
        [$cid,$partyType.'.advance_applied',$partyType.'.payment_posted',$partyType.'.payment_recorded_from_bank']
    );
    $notes = schema_table_exists('accounting_notes') ? tegh_report_query_5980(
        "SELECT n.id,n.party_id partyId,n.source_id documentId,n.number,n.note_date noteDate,
                n.note_kind noteKind,n.status,n.applied_cents appliedCents,n.total_cents totalCents,
                n.journal_entry_id journalId,je.status journalStatus,
                n.reversal_journal_entry_id reversalJournalId,rje.status reversalStatus,
                n.void_date voidDate
         FROM accounting_notes n
         LEFT JOIN journal_entries je ON je.id=n.journal_entry_id AND je.company_id=n.company_id
         LEFT JOIN journal_entries rje ON rje.id=n.reversal_journal_entry_id AND rje.company_id=n.company_id
         WHERE n.company_id=? AND n.source_type=? AND n.note_kind IN (?,?)
               AND n.status IN ('posted','void')
         ORDER BY n.note_date,n.id",
        [$cid,$documentType,$ar?'customer_credit':'vendor_credit',$ar?'customer_debit':'vendor_debit']
    ) : [];
    $noteSettlements = schema_table_exists('accounting_note_settlements') ? tegh_report_query_5980(
        "SELECT s.id,s.note_id noteId,s.settlement_kind settlementKind,s.document_id documentId,
                s.payment_id paymentId,s.settlement_date settlementDate,s.amount_cents amountCents,
                s.foreign_amount_cents foreignAmountCents,s.status,s.reversal_date reversalDate,
                pp.journal_entry_id journalId,pp.reversal_journal_entry_id reversalJournalId,
                je.status journalStatus,rje.status reversalStatus
         FROM accounting_note_settlements s
         JOIN accounting_notes n ON n.id=s.note_id AND n.company_id=s.company_id
         LEFT JOIN party_payments pp ON pp.id=s.payment_id AND pp.company_id=s.company_id
         LEFT JOIN journal_entries je ON je.id=pp.journal_entry_id AND je.company_id=s.company_id
         LEFT JOIN journal_entries rje ON rje.id=pp.reversal_journal_entry_id AND rje.company_id=s.company_id
         WHERE s.company_id=? AND n.source_type=? AND n.note_kind IN (?,?)
         ORDER BY s.settlement_date,s.id",
        [$cid,$documentType,$ar?'customer_credit':'vendor_credit',$ar?'customer_debit':'vendor_debit']
    ) : [];
    return compact('parties','documents','openings','payments','applications','audit','notes','noteSettlements');
}

function tegh_subledger_date_5980(mixed $value, string $reference): string
{
    $date = (string)($value ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        fail('A dated source required for this subledger is missing or invalid ('.$reference.'). No historical date was guessed.',409,'report_source_date_incomplete',false);
    }
    return $date;
}

/** Exact signed integer cents. Never accept floating-point monetary source data. */
function tegh_report_integer_5980(mixed $value, string $label='Amount'): int
{
    if (is_int($value)) return $value;
    if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
        fail($label.' is not an exact integer value.',409,'report_source_amount_invalid',false);
    }
    $integer = filter_var($value, FILTER_VALIDATE_INT);
    if ($integer === false) fail($label.' exceeds the server integer range.',413,'report_integer_overflow',false);
    return $integer;
}
function tegh_report_add_5980(int $left, int $right): int
{
    if (($right > 0 && $left > PHP_INT_MAX-$right) || ($right < 0 && $left < PHP_INT_MIN-$right)) {
        fail('Report totals exceed the exact integer range. No rounded output was generated.',413,'report_integer_overflow',false);
    }
    return $left+$right;
}

/**
 * Pure event construction. Its inputs are bounded, authenticated source rows.
 * deltaCents is positive for a receivable owed or a payable owed; the displayed
 * Debit/Credit sides are derived separately for AR versus AP.
 */
function tegh_subledger_events_5980(array $source, string $basis, bool $ar): array
{
    $items=[]; $events=[]; $exceptions=[]; $parties=[]; $current=0;
    foreach ($source['parties'] as $party) $parties[(string)$party['id']]=$party;
    $audit=[];
    foreach ($source['audit'] ?? [] as $row) {
        $meta=is_array($row['metadataJson']??null)?$row['metadataJson']:json_decode((string)($row['metadataJson']??''),true);
        if (!is_array($meta)) { $exceptions[]=['code'=>'subledger_audit_metadata_invalid','reference'=>(string)$row['id'],'message'=>'A retained payment audit record cannot be decoded.']; continue; }
        $audit[(string)$row['paymentId']][]=['action'=>(string)$row['action'],'meta'=>$meta];
    }
    $applicationsByPayment=[];foreach($source['applications']??[] as $application)$applicationsByPayment[(string)$application['paymentId']][]=$application;
    $putItem = static function(string $key,array $item) use (&$items,&$parties): void {
        $partyId=(string)$item['partyId'];
        if (!isset($parties[$partyId])) fail('A subledger source has no authorized current-company party. No cross-company party was substituted.',409,'report_source_party_invalid',false);
        $item['id']=$key; $item['party']=(string)$parties[$partyId]['name']; $items[$key]=$item;
    };
    $add = static function(string $key,string $date,int $delta,string $type,string $reference,?string $journalId,int $order=20) use (&$events,&$items): void {
        if (!isset($items[$key])) throw new LogicException('Missing subledger item.');
        $date=tegh_subledger_date_5980($date,$reference);
        $events[]=['id'=>$key.':'.$type.':'.count($events),'itemId'=>$key,'partyId'=>$items[$key]['partyId'],
            'date'=>$date,'order'=>$order,'type'=>$type,'reference'=>$reference,'description'=>$items[$key]['description'],
            'deltaCents'=>$delta,'affectsControl'=>$items[$key]['affectsControl'],'journalId'=>$journalId,'status'=>'Recorded'];
    };
    foreach ($source['documents'] as $d) {
        $id=(string)$d['id']; $key='document:'.$id; $opening=!empty($d['isOpening']);
        $current=tegh_report_add_5980($current,tegh_report_integer_5980($d['currentBalanceCents']));
        $amount=tegh_report_integer_5980($d['totalCents'])-($opening?tegh_report_integer_5980($d['originalPaidCents']):0);
        if ($amount<0) fail('An opening document has a negative original outstanding amount.',409,'report_source_amount_invalid',false);
        $effective=tegh_subledger_date_5980($opening?($d['cutoverDate']??null):$d['documentDate'],$id);
        $due=tegh_subledger_date_5980($d['dueDate'],$id);
        $journalId=$opening?($d['openingJournalId']??null):($d['journalId']??null);
        $journalStatus=$opening?($d['openingJournalStatus']??null):($d['issuedJournalStatus']??null);
        $affectsControl=$basis==='accrual'||$opening;
        if ($affectsControl && $journalStatus!=='posted') {
            $exceptions[]=['code'=>'document_posting_link_unavailable','reference'=>$d['number'],
                'message'=>'An issued/opening document has no active posted journal link. Its operational amount is retained and the control difference is disclosed.'];
        }
        $putItem($key,['partyId'=>(string)$d['partyId'],'documentId'=>$id,'number'=>(string)$d['number'],
            'documentDate'=>(string)$d['documentDate'],'effectiveDate'=>$effective,'dueDate'=>$due,
            'description'=>(string)($d['reference']??$d['number']),'kind'=>$opening?'Opening document':'Issued document',
            'affectsControl'=>$affectsControl,'isOpening'=>$opening]);
        $add($key,$effective,$amount,$opening?'Opening document':'Issued document',(string)$d['number'],$journalId,10);
        // Opening batches are immutable; a document void has its own dated adjustment.
        if ($opening && ($d['openingVoidStatus']??null)==='posted') {
            $voidAmount=tegh_report_integer_5980($d['openingVoidCents']);
            $add($key,tegh_subledger_date_5980($d['openingVoidDate']??null,$id),-$voidAmount,'Opening document void',(string)$d['number'],$d['openingVoidJournalId']??null,40);
        } elseif ($opening && ($d['status']??'')==='void') {
            fail('A void opening document has no dated active adjustment. Resolve its retained posting evidence before producing a complete subledger.',409,'report_opening_void_evidence_missing',false);
        } elseif (!$opening && ($d['status']??'')==='void') {
            if(($d['debitNoteReversalStatus']??null)!=='posted')fail('A void debit note has no dated active reversal journal.',409,'report_debit_note_void_evidence_missing',false);
            $add($key,tegh_subledger_date_5980($d['debitNoteVoidDate']??null,$id),-$amount,'Debit note void',(string)$d['number'],$d['debitNoteReversalJournalId']??null,40);
        }
    }
    foreach ($source['openings'] as $o) {
        if (($o['journalStatus']??'')!=='posted') {
            $exceptions[]=['code'=>'opening_posting_unavailable','reference'=>(string)$o['id'],'message'=>'An explicit party opening has no active posted journal; it is excluded from posted control totals.']; continue;
        }
        $amount=tegh_report_integer_5980($o['amountCents']); $current=tegh_report_add_5980($current,$amount);
        $effective=tegh_subledger_date_5980($o['effectiveDate'],(string)$o['id']); $key='opening:'.$o['id'];
        $putItem($key,['partyId'=>(string)$o['partyId'],'documentId'=>null,'number'=>'Opening Balance',
            'documentDate'=>$effective,'effectiveDate'=>$effective,'dueDate'=>$effective,'description'=>'Explicit party opening balance',
            'kind'=>'Opening balance','affectsControl'=>true,'isOpening'=>true]);
        $add($key,$effective,$amount,'Opening balance','Opening Balance',$o['journalId'],5);
    }
    $settlementsByNote=[];$linkedRefundPayments=[];
    foreach($source['noteSettlements']??[] as $s){
        $settlementsByNote[(string)$s['noteId']][]=$s;
        if((string)$s['settlementKind']==='refund')$linkedRefundPayments[(string)$s['paymentId']]=true;
    }
    // Reducing notes create a party credit on their posting date. A later
    // application transfers it to an invoice; a refund clears it with cash.
    // The customer debit invoice is already included among issued documents.
    foreach ($source['notes']??[] as $note) {
        if ((string)$note['noteKind']==='customer_debit') continue;
        $id=(string)$note['id'];
        if ($note['journalId']===null) continue; // a draft void has no posting
        $sourceKey='document:'.(string)$note['documentId'];
        if (!isset($items[$sourceKey]) || (string)$items[$sourceKey]['partyId']!==(string)$note['partyId'])
            fail('A linked note points to an unavailable or different-party document.',409,'report_note_source_invalid',false);
        if ((string)$note['journalStatus']!=='posted')
            fail('A linked note has no active original journal.',409,'report_note_posting_missing',false);
        $nominal=tegh_report_integer_5980($note['totalCents'],'Note total');
        $applied=tegh_report_integer_5980($note['appliedCents'],'Note applied amount');
        $hasApplications=false;foreach($settlementsByNote[$id]??[] as $s)if((string)$s['settlementKind']==='application'&&$s['status']==='posted')$hasApplications=true;
        $legacy=$applied>0&&!$hasApplications;
        $total=$legacy?$applied:$nominal;
        if($total<=0||$applied<0||$applied>$total)fail('A note has invalid retained amounts.',409,'report_note_amount_invalid',false);
        $date=tegh_subledger_date_5980($note['noteDate'],$id);$key='note:'.$id;
        $putItem($key,['partyId'=>(string)$note['partyId'],'documentId'=>null,'number'=>(string)$note['number'],
            'documentDate'=>$date,'effectiveDate'=>$date,'dueDate'=>$date,
            'description'=>'Linked note for '.(string)$items[$sourceKey]['number'],
            'kind'=>'Open credit / debit note','affectsControl'=>true,'isOpening'=>false]);
        $add($key,$date,-$total,'Note posted',(string)$note['number'],(string)$note['journalId'],20);
        $refundTotal=0;$applicationTotal=0;
        foreach($settlementsByNote[$id]??[] as $s){
            $amount=tegh_report_integer_5980($s['amountCents'],'Note settlement');
            if($amount<=0)fail('A note settlement has an invalid amount.',409,'report_note_amount_invalid',false);
            $settlementDate=tegh_subledger_date_5980($s['settlementDate'],(string)$s['id']);
            if((string)$s['settlementKind']==='application'){
                $docKey='document:'.(string)$s['documentId'];
                if(!isset($items[$docKey])||(string)$items[$docKey]['partyId']!==(string)$note['partyId'])
                    fail('A note application points to an unavailable or different-party document.',409,'report_note_target_invalid',false);
                $add($key,$settlementDate,$amount,'Note credit applied',(string)$note['number'],null,25);
                $add($docKey,$settlementDate,-$amount,'Note application',(string)$note['number'],null,26);
                if($s['status']==='reversed'){
                    $reversalDate=tegh_subledger_date_5980($s['reversalDate'],(string)$s['id']);
                    $add($key,$reversalDate,-$amount,'Note application reversed',(string)$note['number'],null,35);
                    $add($docKey,$reversalDate,$amount,'Note application reversed',(string)$note['number'],null,36);
                }else $applicationTotal=tegh_report_add_5980($applicationTotal,$amount);
            }elseif((string)$s['settlementKind']==='refund'){
                if((string)$s['journalStatus']!=='posted')fail('A linked note refund has no active payment journal.',409,'report_note_refund_missing',false);
                $add($key,$settlementDate,$amount,'Note refunded',(string)$note['number'],(string)$s['journalId'],30);
                if($s['status']==='reversed'){
                    if((string)$s['reversalStatus']!=='posted')fail('A linked refund reversal has no active journal.',409,'report_note_refund_reversal_missing',false);
                    $add($key,tegh_subledger_date_5980($s['reversalDate'],(string)$s['id']),-$amount,'Note refund reversed',(string)$note['number'],(string)$s['reversalJournalId'],35);
                }else $refundTotal=tegh_report_add_5980($refundTotal,$amount);
            }else fail('A note settlement has an unknown kind.',409,'report_note_settlement_invalid',false);
        }
        if($applicationTotal!==$applied){
            if($applicationTotal!==0||!$legacy)
                fail('A note application does not match the retained note balance.',409,'report_note_application_incomplete',false);
            // R67 notes were applied in full to the original on posting. Keep
            // their original effective date without fabricating a new row.
            $add($key,$date,$applied,'Original note application',(string)$note['number'],null,25);
            $add($sourceKey,$date,-$applied,'Note applied to original',(string)$note['number'],(string)$note['journalId'],26);
        }
        $remaining=$total-$applied-$refundTotal;
        if($remaining<0)fail('A note is oversettled.',409,'report_note_amount_invalid',false);
        if((string)$note['status']==='posted')$current=tegh_report_add_5980($current,-$remaining);
        if ((string)$note['status']==='void') {
            if ((string)$note['reversalStatus']!=='posted')
                fail('A void linked note has no active reversal journal.',409,'report_note_reversal_missing',false);
            if($refundTotal>0||$applicationTotal>0)fail('A void note retained active settlements.',409,'report_note_void_conflict',false);
            $voidDate=tegh_subledger_date_5980($note['voidDate'],$id);
            $add($key,$voidDate,$total,'Note reversal',(string)$note['number'],(string)$note['reversalJournalId'],40);
            if($applied>0){
                $add($key,$voidDate,-$applied,'Original application reversed',(string)$note['number'],null,41);
                $add($sourceKey,$voidDate,$applied,'Note application reversed',(string)$note['number'],(string)$note['reversalJournalId'],42);
            }
        }
    }
    foreach ($source['payments'] as $p) {
        $id=(string)$p['id']; $date=tegh_subledger_date_5980($p['paymentDate'],$id);
        if (($p['journalStatus']??'')==='reversed') continue; // soft void, restated just like GL
        if (($p['journalStatus']??'')!=='posted') {
            $exceptions[]=['code'=>'payment_posting_unavailable','reference'=>$id,'message'=>'A retained payment has no active original posting; it is not treated as a proved settlement.']; continue;
        }
        $actual=tegh_report_integer_5980($p['amountCents']); $carrying=tegh_report_integer_5980($p['appliedCents']);
        if ($actual<=0) fail('A payment has an invalid stored amount.',409,'report_source_amount_invalid',false);
        $flow=(string)($p['flowKind']??($ar?'customer_receipt':'vendor_payment'));
        if(str_ends_with($flow,'_refund')){
            if(isset($linkedRefundPayments[$id]))continue; // shown on its note, once
            $key='refund:'.$id;$putItem($key,['partyId'=>(string)$p['partyId'],'documentId'=>null,'number'=>(string)$p['reference'],'documentDate'=>$date,'effectiveDate'=>$date,'dueDate'=>$date,'description'=>(string)($p['memo']?:($ar?'Customer refund':'Vendor refund')),'kind'=>$ar?'Customer refund':'Vendor refund','affectsControl'=>true,'isOpening'=>false]);$add($key,$date,$actual,$ar?'Customer refund':'Vendor refund',(string)$p['reference'],$p['journalId'],20);$current=tegh_report_add_5980($current,$actual);
            if(($p['status']??'')==='reversed'){if(($p['reversalStatus']??'')!=='posted')fail('A reversed refund has no active dated reversal journal.',409,'report_reversal_evidence_missing',false);$add($key,tegh_subledger_date_5980($p['reversalDate']??null,$id),-$actual,'Refund reversal',(string)$p['reference'],$p['reversalJournalId'],30);}continue;
        }
        $normalized=$applicationsByPayment[$id]??[];
        if($carrying<=0&&$normalized===[]&&($p['documentId']??null)!==null)fail('A payment has invalid application evidence.',409,'report_source_amount_invalid',false);
        $docId=(string)($p['documentId']??''); $docKey='document:'.$docId;
        $applicationDates=[]; $wasUnapplied=null; $directEvidence=!empty($p['bankTransactionId']);
        foreach ($audit[$id]??[] as $record) {
            if (str_ends_with($record['action'],'.advance_applied')) {
                $meta=$record['meta'];
                if ((string)($meta['documentId']??'')!==$docId) fail('A payment application conflicts with its retained document identity.',409,'report_payment_application_conflict',false);
                $applicationDates[]=tegh_subledger_date_5980($meta['applicationDate']??null,$id);
            }
            if (str_ends_with($record['action'],'.payment_posted')) {
                $wasUnapplied=(bool)($record['meta']['unapplied']??false);
                if (!$wasUnapplied && (string)($record['meta']['documentId']??'')===$docId) $directEvidence=true;
            }
            if (str_ends_with($record['action'],'.payment_recorded_from_bank')) $directEvidence=true;
        }
        if (!empty($p['applicationJournalId']) && ($p['applicationStatus']??'')==='posted') {
            $applicationDates[]=tegh_subledger_date_5980($p['applicationDate']??null,$id);
        }
        $applicationDates=array_values(array_unique($applicationDates));
        if (count($applicationDates)>1) fail('Payment application dates disagree. No date was selected arbitrarily.',409,'report_payment_application_conflict',false);
        $appDate=$applicationDates[0]??null;
        if ($docId!=='' && $appDate===null && $wasUnapplied===true) fail('An applied advance is missing its effective application date. Restore or correct its source evidence; a historical date cannot be guessed.',409,'report_application_date_missing',false);
        if ($docId!=='' && $appDate===null && !$directEvidence && $wasUnapplied===null) {
            // Same-day inference is not sufficient for a legacy advance. A proved direct
            // bank link or original payment audit is required for historical calculation.
            fail('A legacy payment lacks evidence distinguishing direct settlement from later advance application.',409,'report_payment_origin_evidence_missing',false);
        }
        if ($docId!=='' && !isset($items[$docKey])) {
            $exceptions[]=['code'=>'payment_document_outside_scope','reference'=>$id,'message'=>'A posted payment points to an unavailable or soft-voided document. Its actual payment is shown separately, not discarded or forced into balance.'];
            $docKey='unassigned:'.$id;
            $putItem($docKey,['partyId'=>(string)$p['partyId'],'documentId'=>$docId,'number'=>(string)$p['reference'],
                'documentDate'=>$date,'effectiveDate'=>$date,'dueDate'=>$date,'description'=>'Payment with unavailable document',
                'kind'=>'Unassigned payment','affectsControl'=>$basis==='accrual','isOpening'=>false]);
        }
        $isAdvance=$docId===''||$appDate!==null;
        if($normalized!==[]){$wasOriginallyOpen=$wasUnapplied===true||$docId==='';$isAdvance=$wasOriginallyOpen;
            if($wasOriginallyOpen){$advanceKey='advance:'.$id;$putItem($advanceKey,['partyId'=>(string)$p['partyId'],'documentId'=>null,'number'=>(string)$p['reference'],'documentDate'=>$date,'effectiveDate'=>$date,'dueDate'=>$date,'description'=>(string)($p['memo']?:'Unapplied advance / credit'),'kind'=>'Unapplied advance / credit','affectsControl'=>true,'isOpening'=>false]);$add($advanceKey,$date,-$actual,'Advance payment',(string)$p['reference'],$p['journalId'],20);$activeApplied=0;foreach($normalized as $candidate)if((string)($candidate['status']??'')==='posted'&&($candidate['reversalOfId']??null)===null)$activeApplied=tegh_report_add_5980($activeApplied,tegh_report_integer_5980($candidate['paymentCarryingCents']));if(($p['status']??'')==='posted')$current=tegh_report_add_5980($current,-max(0,$actual-$activeApplied));}
            $byId=[];$reversals=[];foreach($normalized as $application){if(($application['reversalOfId']??null)!==null)$reversals[(string)$application['reversalOfId']][]=$application;else $byId[(string)$application['id']]=$application;}
            foreach($byId as $applicationId=>$application){$applicationDoc='document:'.(string)$application['documentId'];if(!isset($items[$applicationDoc])){$exceptions[]=['code'=>'payment_document_outside_scope','reference'=>$applicationId,'message'=>'A retained application points to an unavailable document.'];continue;}$applicationDate=tegh_subledger_date_5980($application['applicationDate'],$applicationId);$paymentPart=tegh_report_integer_5980($application['paymentCarryingCents']);$documentPart=tegh_report_integer_5980($application['documentCarryingCents']);if($wasOriginallyOpen)$add($advanceKey,$applicationDate,$paymentPart,'Advance released to document',(string)$p['reference'],$application['recognitionJournalId']??null,25);$add($applicationDoc,$applicationDate,-$documentPart,$wasOriginallyOpen?'Advance applied':'Payment',(string)$p['reference'],$application['recognitionJournalId']??$p['journalId'],26);foreach($reversals[$applicationId]??[] as $reversal){$reversalDate=tegh_subledger_date_5980($reversal['applicationDate'],(string)$reversal['id']);if($wasOriginallyOpen)$add($advanceKey,$reversalDate,-$paymentPart,'Advance release reversed',(string)$p['reference'],$reversal['recognitionJournalId']??null,31);$add($applicationDoc,$reversalDate,$documentPart,'Payment application reversed',(string)$p['reference'],$reversal['recognitionJournalId']??null,32);}}
            if(($p['status']??'')==='reversed'){if(($p['reversalStatus']??'')!=='posted')fail('A reversed payment has no active dated reversal journal.',409,'report_reversal_evidence_missing',false);$revDate=tegh_subledger_date_5980($p['reversalDate']??null,$id);if($wasOriginallyOpen)$add($advanceKey,$revDate,$actual,'Payment reversal',(string)$p['reference'],$p['reversalJournalId'],40);}
            continue;
        }
        $advanceKey='advance:'.$id;
        if ($isAdvance) {
            $putItem($advanceKey,['partyId'=>(string)$p['partyId'],'documentId'=>null,'number'=>(string)$p['reference'],
                'documentDate'=>$date,'effectiveDate'=>$date,'dueDate'=>$date,'description'=>(string)($p['memo']?:'Unapplied advance / credit'),
                'kind'=>'Unapplied advance / credit','affectsControl'=>true,'isOpening'=>false]);
            $add($advanceKey,$date,-$actual,'Advance payment',(string)$p['reference'],$p['journalId'],20);
            if ($docId==='' && ($p['status']??'')==='posted') $current=tegh_report_add_5980($current,-$actual);
            if ($appDate!==null) {
                if ($appDate<$date) $exceptions[]=['code'=>'application_predates_payment','reference'=>$id,'message'=>'A stored application predates its payment; the recorded dates are shown without alteration.'];
                $add($advanceKey,$appDate,$actual,'Advance released to document',(string)$p['reference'],$p['applicationJournalId']??null,25);
                $add($docKey,$appDate,-$carrying,'Advance applied',(string)$p['reference'],$p['applicationJournalId']??null,26);
            }
        } else {
            $add($docKey,$date,-$carrying,'Payment',(string)$p['reference'],$p['journalId'],20);
        }
        if (($p['status']??'')==='reversed') {
            if (($p['reversalStatus']??'')!=='posted') fail('A reversed payment has no active dated reversal journal. No timestamp was substituted for its accounting date.',409,'report_reversal_evidence_missing',false);
            $revDate=tegh_subledger_date_5980($p['reversalDate']??null,$id);
            if ($revDate<$date) $exceptions[]=['code'=>'reversal_predates_payment','reference'=>$id,'message'=>'A reversal predates its payment; the recorded dates are retained and must be reviewed.'];
            if ($isAdvance) {
                $add($advanceKey,$revDate,$actual,'Payment reversal',(string)$p['reference'],$p['reversalJournalId'],30);
                if ($appDate!==null) {
                    $appRevDate=!empty($p['applicationReversalJournalId'])
                        ?tegh_subledger_date_5980($p['applicationReversalDate']??null,$id):$revDate;
                    if (!empty($p['applicationJournalId']) && ($p['applicationReversalStatus']??'')!=='posted') fail('The advance application reversal is missing or inactive.',409,'report_reversal_evidence_missing',false);
                    if ($appRevDate<$appDate) $exceptions[]=['code'=>'reversal_predates_application','reference'=>$id,'message'=>'A dated reversal precedes an advance application. The source dates require review.'];
                    $add($advanceKey,$appRevDate,-$actual,'Advance release reversed',(string)$p['reference'],$p['applicationReversalJournalId']??$p['reversalJournalId'],31);
                    $add($docKey,$appRevDate,$carrying,'Advance application reversed',(string)$p['reference'],$p['applicationReversalJournalId']??$p['reversalJournalId'],32);
                }
            } else $add($docKey,$revDate,$carrying,'Payment reversal',(string)$p['reference'],$p['reversalJournalId'],30);
        }
    }
    usort($events,static fn(array $a,array $b):int=>[$a['date'],$a['order'],$a['id']]<=>[$b['date'],$b['order'],$b['id']]);
    tegh_report_check_rows_5980($events);
    foreach ($events as &$event) {
        $signed=$ar?$event['deltaCents']:-$event['deltaCents'];
        $event['debitCents']=max(0,$signed); $event['creditCents']=max(0,-$signed);
    }
    unset($event);
    return ['items'=>$items,'events'=>$events,'parties'=>$parties,'exceptions'=>$exceptions,'currentStoredOperationalCents'=>$current];
}

/** Snapshot every known item; totals are exact and never rely on mounted UI rows. */
function tegh_subledger_snapshot_5980(array $ledger,string $asOf): array
{
    $balances=array_fill_keys(array_keys($ledger['items']),0); $control=0; $total=0;
    foreach ($ledger['events'] as $event) {
        if ($event['date']>$asOf) continue;
        $key=$event['itemId']; $balances[$key]=tegh_report_add_5980($balances[$key],$event['deltaCents']);
        $total=tegh_report_add_5980($total,$event['deltaCents']);
        if ($event['affectsControl']) $control=tegh_report_add_5980($control,$event['deltaCents']);
    }
    return ['balances'=>$balances,'totalCents'=>$total,'controlApplicableCents'=>$control];
}
function tegh_subledger_disclosure_5980(): string
{
    return '';
}
function tegh_subledger_control_5980(array $company,bool $ar,string $asOf,int $sub): array
{
    $account=tegh_report_control_account_5980($company,$ar);
    if (!$account) return ['scope'=>'Whole company','accountCode'=>null,'applicableSubledgerCents'=>$sub,'ledgerCents'=>null,'differenceCents'=>null,'status'=>'mapping_unavailable'];
    $side=$ar?'jl.debit_cents-jl.credit_cents':'jl.credit_cents-jl.debit_cents';
    $q=db()->prepare("SELECT COALESCE(SUM($side),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id WHERE je.company_id=? AND je.status='posted' AND je.entry_date<=? AND a.id=?");
    $q->execute([$company['id'],$asOf,$account['id']]); $gl=tegh_report_integer_5980((string)$q->fetchColumn());
    return ['scope'=>'Whole company; not restricted by report search or party filters','asOf'=>$asOf,'accountCode'=>$account['code'],'ledgerCents'=>$gl,'applicableSubledgerCents'=>$sub,'differenceCents'=>$gl-$sub,'status'=>$gl===$sub?'reconciled':'difference'];
}
function tegh_subledger_add_controls_5980(array $model,array $company,bool $ar,array $ledger,string $asOf): array
{
    $snapshot=tegh_subledger_snapshot_5980($ledger,$asOf);
    $control=tegh_subledger_control_5980($company,$ar,$asOf,$snapshot['controlApplicableCents']);
    $model['controlTotals']=array_merge($model['controlTotals']??[],['companyControlReconciliation'=>$control]);
    $model['exceptions']=array_merge($model['exceptions']??[],$ledger['exceptions']);
    if ($control['status']!=='reconciled') $model['exceptions'][]=['code'=>'subledger_control_'.$control['status'],
        'differenceCents'=>$control['differenceCents'],'message'=>$control['status']==='mapping_unavailable'?'The configured AR/AP control account is missing or ambiguous.':'The applicable dated subledger does not reconcile to the configured GL control. Review the explicit difference; no plug or adjustment was created.'];
    $current=tegh_subledger_snapshot_5980($ledger,canadian_today());
    if ($current['totalCents']!==$ledger['currentStoredOperationalCents']) $model['exceptions'][]=['code'=>'subledger_stored_balance_difference',
        'differenceCents'=>$current['totalCents']-$ledger['currentStoredOperationalCents'],
        'message'=>'Dated events do not match current stored operational balances. The source difference is disclosed, not hidden by using today’s balance for historical reports.'];
    $model['postingScope']=tegh_subledger_disclosure_5980();
    $model['definitions']['cashBasisQualification']=$company['accounting_basis']==='accrual'
        ?'Issued documents, explicit openings and unapplied advances enter the accrual control comparison.'
        :'Ordinary unpaid invoices/bills are operational on cash basis. Only opening documents, explicit party openings and unapplied advances enter the cash-basis control comparison.';
    return $model;
}
function tegh_subledger_item_matches_5980(array $item,array $p): bool
{
    if ($p['partyId']!=='' && $item['partyId']!==$p['partyId']) return false;
    if ($p['q']==='') return true;
    $haystack=implode(' ',[$item['party'],$item['number'],$item['description']]);
    return mb_stripos($haystack,$p['q'],0,'UTF-8')!==false;
}
function tegh_subledger_ageing_output_5980(array $company,array $d,array $p): array
{
    $ar=$d['key']==='ar-ageing';
    $ledger=tegh_subledger_events_5980(tegh_subledger_sources_5980($company,$ar),(string)$company['accounting_basis'],$ar);
    if($p['partyId']!==''&&!isset($ledger['parties'][$p['partyId']]))fail('This party is unavailable in the current company.',404,'report_party_unavailable',false);
    $snapshot=tegh_subledger_snapshot_5980($ledger,$p['asOf']); $profile=portal_aging_profile((string)$company['id']); $prefix=$ar?'ar':'ap';
    $cut=isset($p['c1'])?[$p['c1'],$p['c2'],$p['c3']]:[(int)$profile[$prefix.'_cutoff_1'],(int)$profile[$prefix.'_cutoff_2'],(int)$profile[$prefix.'_cutoff_3']];
    if (!($cut[0]>=1&&$cut[0]<$cut[1]&&$cut[1]<$cut[2]&&$cut[2]<=3650)) fail('The configured ageing bands are invalid.',409,'report_ageing_profile_invalid',false);
    $labels=['Current / credits','1–'.$cut[0],($cut[0]+1).'–'.$cut[1],($cut[1]+1).'–'.$cut[2],($cut[2]+1).'+'];
    $rows=[]; $total=0; $groupTotals=array_fill_keys($labels,0);
    foreach ($snapshot['balances'] as $key=>$balance) {
        $item=$ledger['items'][$key]; if ($balance===0 || !tegh_subledger_item_matches_5980($item,$p)) continue;
        $days=max(0,(int)(new DateTimeImmutable($item['dueDate'],new DateTimeZone('UTC')))->diff(new DateTimeImmutable($p['asOf'],new DateTimeZone('UTC')))->format('%r%a'));
        $bucket=$balance<0||$days===0?0:($days<=$cut[0]?1:($days<=$cut[1]?2:($days<=$cut[2]?3:4)));
        $item['balanceCents']=$balance;$item['daysOverdue']=$days;$item['bucket']=$labels[$bucket];$rows[]=$item;
        $total=tegh_report_add_5980($total,$balance);$groupTotals[$item['bucket']]=tegh_report_add_5980($groupTotals[$item['bucket']],$balance);
    }
    usort($rows,static fn($a,$b)=>[$a['dueDate'],$a['party'],$a['id']]<=>[$b['dueDate'],$b['party'],$b['id']]);
    $model=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([
        ['party',$ar?'Customer':'Vendor','text',26],['number','Document / reference','identifier',20],['kind','Source','text',18],
        ['documentDate','Original date','date',14],['effectiveDate','Effective date','date',14],['dueDate','Due date','date',14],
        ['daysOverdue','Days overdue','integer',12],['bucket','Age band','text',16],['balanceCents','As-of outstanding','money',20]
    ]),$rows,['balanceCents'=>$total]);
    $model['groupBy']='bucket';$model['groupOrder']=$labels;$model['groupLabels']=array_combine($labels,$labels);$model['groupTotals']=$groupTotals;
    return tegh_subledger_add_controls_5980($model,$company,$ar,$ledger,$p['asOf']);
}
function tegh_subledger_party_balances_output_5980(array $company,array $d,array $p): array
{
    $ar=$d['key']==='customer-balances';
    $ledger=tegh_subledger_events_5980(tegh_subledger_sources_5980($company,$ar),(string)$company['accounting_basis'],$ar);
    if($p['partyId']!==''&&!isset($ledger['parties'][$p['partyId']]))fail('This party is unavailable in the current company.',404,'report_party_unavailable',false);
    $snapshot=tegh_subledger_snapshot_5980($ledger,$p['asOf']);$by=[];
    foreach($ledger['parties'] as $id=>$party)$by[$id]=['partyId'=>$id,'party'=>(string)$party['name'],'openDocuments'=>0,'positiveCents'=>0,'creditsCents'=>0,'balanceCents'=>0];
    foreach($snapshot['balances'] as $key=>$balance){
        if($balance===0)continue;$item=$ledger['items'][$key];$row=&$by[(string)$item['partyId']];
        $row['balanceCents']=tegh_report_add_5980($row['balanceCents'],$balance);
        if($balance>0){$row['positiveCents']=tegh_report_add_5980($row['positiveCents'],$balance);if($item['documentId']!==null)$row['openDocuments']++;}
        else $row['creditsCents']=tegh_report_add_5980($row['creditsCents'],$balance);
        unset($row);
    }
    $rows=[];$totals=['openDocuments'=>0,'positiveCents'=>0,'creditsCents'=>0,'balanceCents'=>0];
    foreach($by as $row){
        if($row['balanceCents']===0&&$row['positiveCents']===0)continue;
        if($p['partyId']!==''&&$row['partyId']!==$p['partyId'])continue;
        if($p['q']!==''&&mb_stripos($row['party'],$p['q'],0,'UTF-8')===false)continue;
        $rows[]=$row;foreach($totals as $key=>$unused)$totals[$key]=tegh_report_add_5980($totals[$key],$row[$key]);
    }
    usort($rows,static fn($a,$b):int=>[$a['party'],$a['partyId']]<=>[$b['party'],$b['partyId']]);
    $model=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([
        ['party',$ar?'Customer':'Vendor','text',30],['openDocuments','Open documents','integer',15],
        ['positiveCents','Documents / openings','money',21],['creditsCents','Open credits','money',20],
        ['balanceCents','Net as-of balance','money',23]
    ]),$rows,$totals);
    $model=tegh_subledger_add_controls_5980($model,$company,$ar,$ledger,$p['asOf']);
    $model['postingScope']='One row per party. Open credits are negative and reduce the net balance; documents and explicit openings remain dated.';
    return $model;
}
function tegh_subledger_party_output_5980(array $company,array $d,array $p): array
{
    $ar=$d['key']==='customer-ledger';
    $ledger=tegh_subledger_events_5980(tegh_subledger_sources_5980($company,$ar),(string)$company['accounting_basis'],$ar);
    $party=$ledger['parties'][$p['partyId']]??null;
    if (!$party) fail('This party is unavailable in the current company.',404,'report_party_unavailable',false);
    $opening=0;$running=0;$dr=0;$cr=0;$rows=[];
    foreach ($ledger['events'] as $e) {
        if ($e['partyId']!==$p['partyId'] || $e['date']>$p['end']) continue;
        if ($e['date']<$p['start']) {$opening=tegh_report_add_5980($opening,$e['deltaCents']);$running=$opening;continue;}
        $running=tegh_report_add_5980($running,$e['deltaCents']);$dr=tegh_report_add_5980($dr,$e['debitCents']);$cr=tegh_report_add_5980($cr,$e['creditCents']);
        $e['balanceCents']=$running;$rows[]=$e;
    }
    array_unshift($rows,['id'=>'opening:'.$party['id'],'date'=>$p['start'],'type'=>'Opening balance','reference'=>'Before period','description'=>$party['name'],'debitCents'=>0,'creditCents'=>0,'balanceCents'=>$opening,'status'=>'Opening']);
    $model=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([
        ['date','Effective date','date',14],['type','Source','text',22],['reference','Reference','identifier',23],['description','Description','text',30],
        ['debitCents','Debit','money',18],['creditCents','Credit','money',18],['balanceCents',$ar?'AR balance (DR +)':'AP balance (CR +)','money',22]
    ]),$rows,['debitCents'=>$dr,'creditCents'=>$cr]);
    $model['subtitle']=$party['name'];$model['party']=['id'=>$party['id'],'name'=>$party['name']];
    $model['controlTotals']=['openingCents'=>$opening,'movementDebitCents'=>$dr,'movementCreditCents'=>$cr,'closingCents'=>$running];
    return tegh_subledger_add_controls_5980($model,$company,$ar,$ledger,$p['end']);
}
function tegh_subledger_trial_output_5980(array $company,array $d,array $p): array
{
    $ar=$d['key']==='ar-trial-balance';
    $ledger=tegh_subledger_events_5980(tegh_subledger_sources_5980($company,$ar),(string)$company['accounting_basis'],$ar);
    if($p['partyId']!==''&&!isset($ledger['parties'][$p['partyId']]))fail('This party is unavailable in the current company.',404,'report_party_unavailable',false);
    $by=[];
    foreach ($ledger['parties'] as $id=>$party) $by[$id]=['id'=>$id,'partyId'=>$id,'party'=>$party['name'],'openingSigned'=>0,'debitCents'=>0,'creditCents'=>0];
    foreach ($ledger['events'] as $e) {
        if ($e['date']>$p['end']) continue; $r=&$by[$e['partyId']];
        if ($e['date']<$p['start']) $r['openingSigned']=tegh_report_add_5980($r['openingSigned'],$ar?$e['deltaCents']:-$e['deltaCents']);
        else {$r['debitCents']=tegh_report_add_5980($r['debitCents'],$e['debitCents']);$r['creditCents']=tegh_report_add_5980($r['creditCents'],$e['creditCents']);}
        unset($r);
    }
    $keys=['openingDebitCents','openingCreditCents','debitCents','creditCents','closingDebitCents','closingCreditCents'];$totals=array_fill_keys($keys,0);$rows=[];
    foreach ($by as $r) {
        if (!tegh_subledger_item_matches_5980($r+['number'=>'','description'=>''],$p)) continue;
        $close=tegh_report_add_5980($r['openingSigned'],$r['debitCents']-$r['creditCents']);
        $r['openingDebitCents']=max(0,$r['openingSigned']);$r['openingCreditCents']=max(0,-$r['openingSigned']);
        $r['closingDebitCents']=max(0,$close);$r['closingCreditCents']=max(0,-$close);unset($r['openingSigned']);
        if ($r['openingDebitCents']===0&&$r['openingCreditCents']===0&&$r['debitCents']===0&&$r['creditCents']===0) continue;
        foreach ($keys as $key) $totals[$key]=tegh_report_add_5980($totals[$key],$r[$key]);$rows[]=$r;
    }
    $model=tegh_report_model_5980($d,$company,$p,tegh_report_columns_5980([
        ['party',$ar?'Customer':'Vendor','text',28],['openingDebitCents','Opening Debit','money',19],['openingCreditCents','Opening Credit','money',19],
        ['debitCents','Period Debit','money',18],['creditCents','Period Credit','money',18],['closingDebitCents','Closing Debit','money',19],['closingCreditCents','Closing Credit','money',19]
    ]),$rows,$totals);
    $model['controlTotals']=['openingNetDebitCents'=>$totals['openingDebitCents']-$totals['openingCreditCents'],
        'movementNetDebitCents'=>$totals['debitCents']-$totals['creditCents'],'closingNetDebitCents'=>$totals['closingDebitCents']-$totals['closingCreditCents']];
    $model['totalsLabel']='Subledger totals; its balancing control account is reconciled separately, not inserted as a fictional party.';
    return tegh_subledger_add_controls_5980($model,$company,$ar,$ledger,$p['end']);
}
