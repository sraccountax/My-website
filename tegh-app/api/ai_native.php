<?php
declare(strict_types=1);

/**
 * Tegh 4.5.0 Native Intelligence server trust boundary.
 * Browser-generated intents and semantic scores are advisory/untrusted input.
 * This module never accepts SQL and never performs accounting commits.
 */
const TEGH_NATIVE_ENGINE_VERSION='1.0.0';
const TEGH_NATIVE_PREF_KEY='native_ai_user';
const TEGH_NATIVE_POLICY_KEY='native_ai_policy';

function tegh_native_default_flags(): array
{
    return [
        'native_ai_enabled'=>(bool)(config('ai.native_ai_enabled')??true),
        'native_ai_embeddings_enabled'=>(bool)(config('ai.native_ai_embeddings_enabled')??true),
        'native_ai_learning_enabled'=>(bool)(config('ai.native_ai_learning_enabled')??true),
        'native_ai_reconciliation_enabled'=>(bool)(config('ai.native_ai_reconciliation_enabled')??true),
    ];
}

function tegh_native_policy(array $company): array
{
    $flags=tegh_native_default_flags();
    if(!schema_table_exists('ai_agent_preferences')||!schema_column_exists('users','platform_role'))return $flags;
    try{
        $stmt=db()->prepare("SELECT p.preference_json FROM ai_agent_preferences p JOIN users u ON u.id=p.user_id WHERE p.company_id=? AND p.preference_key=? AND p.active=1 AND u.platform_role='platform_owner' ORDER BY p.updated_at DESC LIMIT 1");
        $stmt->execute([(string)$company['id'],TEGH_NATIVE_POLICY_KEY]);$raw=$stmt->fetchColumn();
        if(is_string($raw)&&$raw!==''){$j=json_decode($raw,true,32,JSON_THROW_ON_ERROR);foreach($flags as $k=>$v)if(array_key_exists($k,$j))$flags[$k]=(bool)$j[$k];}
    }catch(Throwable $e){error_log('Tegh Native policy read skipped: '.$e->getMessage());}
    return $flags;
}

function tegh_native_user_preference(array $user,array $company): array
{
    $default=true;if(!schema_table_exists('ai_agent_preferences'))return ['enabled'=>$default,'storageReady'=>false,'source'=>'default'];
    try{$stmt=db()->prepare('SELECT preference_json FROM ai_agent_preferences WHERE company_id=? AND user_id=? AND preference_key=? AND active=1 LIMIT 1');$stmt->execute([(string)$company['id'],(string)$user['id'],TEGH_NATIVE_PREF_KEY]);$raw=$stmt->fetchColumn();if(!is_string($raw)||$raw==='')return ['enabled'=>$default,'storageReady'=>true,'source'=>'default'];$j=json_decode($raw,true,16,JSON_THROW_ON_ERROR);return ['enabled'=>(bool)($j['enabled']??$default),'storageReady'=>true,'source'=>'user'];}catch(Throwable $e){return ['enabled'=>$default,'storageReady'=>false,'source'=>'default'];}
}

function tegh_native_save_user_preference(array $user,array $company,bool $enabled): array
{
    if(!schema_table_exists('ai_agent_preferences'))fail('Tegh AI preference storage is temporarily unavailable. Accounting remains available.',503,'ai_storage_maintenance_required');
    $json=json_encode(['enabled'=>$enabled],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);db()->prepare("INSERT INTO ai_agent_preferences (id,company_id,user_id,preference_key,preference_json,source,active) VALUES (?,?,?,?,?,'user_approved',1) ON DUPLICATE KEY UPDATE preference_json=VALUES(preference_json),source='user_approved',active=1,updated_at=CURRENT_TIMESTAMP")->execute([new_id('aipref'),(string)$company['id'],(string)$user['id'],TEGH_NATIVE_PREF_KEY,$json]);
    try{audit_event($user,(string)$company['id'],'ai_agent.native_preference','ai_agent_preference',TEGH_NATIVE_PREF_KEY,['enabled'=>$enabled]);}catch(Throwable $e){}
    return ['enabled'=>$enabled,'storageReady'=>true,'source'=>'user'];
}

function tegh_native_save_policy(array $user,array $company,array $input): array
{
    if(platform_role_for_user((string)$user['id'])!=='platform_owner')fail('Native Intelligence platform controls are restricted to the Platform Owner.',403,'platform_owner_required');
    if(!schema_table_exists('ai_agent_preferences'))fail('Tegh AI preference storage is temporarily unavailable.',503,'ai_storage_maintenance_required');
    $flags=tegh_native_policy($company);foreach(array_keys($flags) as $k)if(array_key_exists($k,$input))$flags[$k]=(bool)$input[$k];
    $json=json_encode($flags,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);db()->prepare("INSERT INTO ai_agent_preferences (id,company_id,user_id,preference_key,preference_json,source,active) VALUES (?,?,?,?,?,'user_approved',1) ON DUPLICATE KEY UPDATE preference_json=VALUES(preference_json),source='user_approved',active=1,updated_at=CURRENT_TIMESTAMP")->execute([new_id('aipref'),(string)$company['id'],(string)$user['id'],TEGH_NATIVE_POLICY_KEY,$json]);
    audit_event($user,(string)$company['id'],'ai_agent.native_policy','ai_agent_preference',TEGH_NATIVE_POLICY_KEY,$flags);return $flags;
}

function tegh_native_state(array $user,array $company): array
{
    $policy=tegh_native_policy($company);$pref=tegh_native_user_preference($user,$company);$enabled=$policy['native_ai_enabled']&&$pref['enabled'];
    return ['feature'=>'Tegh Native Intelligence','engineVersion'=>TEGH_NATIVE_ENGINE_VERSION,'enabled'=>$enabled,'userEnabled'=>(bool)$pref['enabled'],'policy'=>$policy,'storageReady'=>(bool)$pref['storageReady'],'mode'=>$enabled?'native':'core','statusLabel'=>$enabled?'Ready':'Tegh Core Intelligence','schema'=>SR_ACCOUNTAX_SCHEMA_VERSION,'serverLearningPersistent'=>schema_table_exists('ai_agent_learning_events')&&schema_table_exists('ai_agent_learned_rules'),'externalApiRequired'=>false];
}

function tegh_native_validate_browser_intent(mixed $intent,array $company): array
{
    if(!is_array($intent))throw new RuntimeException('Native intent is not an object.');
    $allowedFields=['actionId','mode','confidence','companyScope','mutationRequested','query','description','category','status','module','accountName','clarification','direction','reconciled','amountMin','amountMax','amountExact','dateFrom','dateTo'];
    foreach(array_keys($intent) as $field)if(!in_array((string)$field,$allowedFields,true))throw new RuntimeException('Unknown Native intent field.');
    foreach(['query','description','category','status','module','accountName','clarification'] as $field){if(isset($intent[$field])&&!is_scalar($intent[$field]))throw new RuntimeException('Invalid Native intent parameter type.');}
    foreach(['amountMin','amountMax','amountExact','confidence'] as $field){if(isset($intent[$field])&&!is_numeric($intent[$field]))throw new RuntimeException('Invalid Native numeric parameter.');}
    // Browser output can only enter the same safe read/navigation/research registry used by Connected Intelligence.
    $allowed=tegh_connected_safe_registry($company);$validated=tegh_connected_validate_intent($intent,$allowed);
    if(!in_array((string)$validated['mode'],['read','navigation','research','clarify'],true))throw new RuntimeException('Native action is not read-safe.');
    return $validated;
}

function tegh_native_try_orchestrate(array $user,array $company,mixed $browserIntent,string $question,array $context,string $resultSetId=''): ?array
{
    $state=tegh_native_state($user,$company);if(!$state['enabled']||!is_array($browserIntent))return null;
    try{$intent=tegh_native_validate_browser_intent($browserIntent,$company);$out=tegh_connected_execute_intent($user,$company,$intent,$question,$context,$resultSetId);if($out===null)return null;$out['interpreter']='native_browser';$out['nativeIntelligence']=true;$out['nativeEngineVersion']=TEGH_NATIVE_ENGINE_VERSION;try{tegh_ai_record_event($user,$company,'native_interpretation',['question'=>$question,'module'=>'Agent','actionId'=>(string)$intent['actionId'],'intentCategory'=>'Interpretation','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)round(((float)($intent['confidence']??0))*10000),'source'=>'native_browser']);}catch(Throwable $ignored){}return $out;}
    catch(Throwable $e){try{tegh_ai_record_event($user,$company,'safety_violation',['question'=>$question,'module'=>'Agent','actionId'=>'native_intent_rejected','intentCategory'=>'Safety','signal'=>-1,'outcome'=>'blocked','confidenceBps'=>10000,'reason'=>mb_substr($e->getMessage(),0,160)]);}catch(Throwable $ignored){}return null;}
}

function tegh_native_bank_transactions(array $company,array $ids): array
{
    require_company_permission($company,'banking.view');$ids=array_values(array_unique(array_filter(array_map('strval',$ids))));$ids=array_slice($ids,0,100);if(!$ids)return [];$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=db()->prepare("SELECT bt.id,bt.transaction_date,bt.description,bt.normalized_merchant,bt.amount_cents,bt.status,bt.bank_account_id,bt.decided_account_id,a.code account_code,a.name account_name FROM bank_transactions bt LEFT JOIN accounts a ON a.id=bt.decided_account_id AND a.company_id=bt.company_id WHERE bt.company_id=? AND bt.id IN ($marks) ORDER BY bt.transaction_date,bt.id");$stmt->execute(array_merge([(string)$company['id']],$ids));return $stmt->fetchAll();
}

function tegh_native_rule_candidates(array $company,int $limit=75): array
{
    if(!schema_table_exists('ai_agent_learned_rules'))return [];$limit=max(1,min(100,$limit));$stmt=db()->prepare("SELECT id,scope_type,scope_key,pattern_type,pattern_value,suggestion_json,positive_count,negative_count,confidence_bps,source FROM ai_agent_learned_rules WHERE company_id=? AND status='enabled' AND suggestion_type='account_category' ORDER BY confidence_bps DESC,positive_count DESC,updated_at DESC LIMIT $limit");$stmt->execute([(string)$company['id']]);$out=[];foreach($stmt->fetchAll() as $r){$s=json_decode((string)$r['suggestion_json'],true)?:[];$accountId=(string)($s['accountId']??'');if($accountId==='')continue;$a=db()->prepare('SELECT id,code,name,account_type,is_control FROM accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$a->execute([$accountId,(string)$company['id']]);$acct=$a->fetch();if(!$acct)continue;$out[]=['ruleId'=>(string)$r['id'],'patternValue'=>(string)$r['pattern_value'],'scopeType'=>(string)$r['scope_type'],'scopeKey'=>(string)$r['scope_key'],'accountId'=>$accountId,'accountCode'=>(string)$acct['code'],'accountName'=>(string)$acct['name'],'positiveCount'=>(int)$r['positive_count'],'negativeCount'=>(int)$r['negative_count'],'confidenceBps'=>(int)$r['confidence_bps'],'source'=>(string)$r['source']];}return $out;
}

function tegh_native_categorization_candidates(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.view');$state=tegh_native_state($user,$company);if(!$state['enabled'])fail('Native Intelligence is disabled. Tegh Core Intelligence remains available.',409,'native_ai_disabled');$ids=(array)($input['transactionIds']??[]);$rows=tegh_native_bank_transactions($company,$ids);$rules=tegh_native_rule_candidates($company,75);$suggestions=[];
    foreach($rows as $row){$rule=null;try{$rule=tegh_ai_best_rule($company,(string)$row['description'],(string)$row['bank_account_id']);}catch(Throwable $e){}$evidence=null;if($rule){$s=json_decode((string)($rule['suggestion_json']??''),true)?:[];$aid=(string)($s['accountId']??'');if($aid!==''){$a=db()->prepare('SELECT id,code,name FROM accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$a->execute([$aid,(string)$company['id']]);$acct=$a->fetch();if($acct)$evidence=['accountId'=>$aid,'accountCode'=>(string)$acct['code'],'accountName'=>(string)$acct['name'],'confidenceBps'=>(int)($rule['confidence_bps']??0),'positiveCount'=>(int)($rule['positive_count']??0),'negativeCount'=>(int)($rule['negative_count']??0),'ruleId'=>(string)($rule['id']??''),'source'=>(string)($rule['source']??'inferred')];}}
        $suggestions[]=['transactionId'=>(string)$row['id'],'description'=>(string)$row['description'],'normalizedMerchant'=>(string)($row['normalized_merchant']??''),'amountCents'=>(int)$row['amount_cents'],'bankAccountId'=>(string)$row['bank_account_id'],'deterministicEvidence'=>$evidence];
    }
    return ['transactions'=>$suggestions,'memoryCandidates'=>$rules,'bounded'=>true,'maxCandidates'=>75,'companyScoped'=>true,'learningEnabled'=>(bool)$state['policy']['native_ai_learning_enabled']];
}

function tegh_native_feedback(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.match');$state=tegh_native_state($user,$company);if(!$state['policy']['native_ai_learning_enabled'])return ['ok'=>true,'recorded'=>false,'reason'=>'learning_disabled'];$txId=clean_text($input['transactionId']??'','Transaction',64);$decision=clean_text($input['decision']??'','Decision',20);if(!in_array($decision,['accept','reject','correct'],true))fail('Native learning decision is invalid.');$rows=tegh_native_bank_transactions($company,[$txId]);if(!$rows)fail('That bank transaction is not available in the current company.',404,'transaction_not_found');$tx=$rows[0];$pattern=tegh_ai_normalized_pattern((string)$tx['description']);$accountId=trim((string)($input['accountId']??''));if(in_array($decision,['accept','correct'],true)){if($accountId==='')fail('Choose an account for this learning decision.');$stmt=db()->prepare("SELECT id,code,name,is_control FROM accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1");$stmt->execute([$accountId,(string)$company['id']]);$account=$stmt->fetch();if(!$account||(int)$account['is_control']===1)fail('That category is not available for bank categorization.',409,'account_not_eligible');$rule=tegh_ai_upsert_rule($user,$company,['scopeType'=>'company','scopeKey'=>'','patternType'=>'merchant_contains','patternValue'=>$pattern,'suggestionType'=>'account_category','suggestion'=>['accountId'=>$accountId,'accountCode'=>(string)$account['code'],'accountName'=>(string)$account['name']],'source'=>$decision==='correct'?'correction':'explicit'],$decision==='accept'?1:2,0);tegh_ai_record_event($user,$company,$decision==='correct'?'suggestion_corrected':'suggestion_accepted',['module'=>'Banking','recordId'=>$txId,'actionId'=>'native.category_feedback','signal'=>1,'outcome'=>$decision,'confidenceBps'=>(int)($rule['confidence_bps']??0),'pattern'=>$pattern,'accountId'=>$accountId]);return ['ok'=>true,'recorded'=>true,'decision'=>$decision,'ruleId'=>(string)($rule['id']??''),'confidenceBps'=>(int)($rule['confidence_bps']??0)];}
    $rules=tegh_native_rule_candidates($company,100);$matched=null;foreach($rules as $r)if(tegh_ai_normalized_pattern((string)$r['patternValue'])===$pattern){$matched=$r;break;}if($matched){$row=tegh_ai_upsert_rule($user,$company,['scopeType'=>$matched['scopeType'],'scopeKey'=>$matched['scopeKey'],'patternType'=>'merchant_contains','patternValue'=>$matched['patternValue'],'suggestionType'=>'account_category','suggestion'=>['accountId'=>$matched['accountId'],'accountCode'=>$matched['accountCode'],'accountName'=>$matched['accountName']],'source'=>'correction'],0,1);$confidence=(int)($row['confidence_bps']??0);}else{$confidence=0;}tegh_ai_record_event($user,$company,'suggestion_rejected',['module'=>'Banking','recordId'=>$txId,'actionId'=>'native.category_feedback','signal'=>-1,'outcome'=>'rejected','confidenceBps'=>$confidence,'pattern'=>$pattern]);return ['ok'=>true,'recorded'=>true,'decision'=>'reject','confidenceBps'=>$confidence];
}

function tegh_native_apply_categories(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.match');$decisions=is_array($input['decisions']??null)?$input['decisions']:[];if(count($decisions)<1||count($decisions)>100)fail('Choose between 1 and 100 Native category suggestions.');$groups=[];
    foreach($decisions as $d){if(!is_array($d))fail('A category decision is invalid.');$id=clean_text($d['transactionId']??'','Transaction',64);$accountId=clean_text($d['accountId']??'','Account',64);$groups[$accountId][]=$id;}
    $updated=0;$failed=[];foreach($groups as $accountId=>$ids){try{$r=bank_transaction_categorize_service($user,$company,$ids,$accountId,null,'native_ai');$updated+=(int)($r['updated']??0);foreach($ids as $id){try{tegh_native_feedback($user,$company,['transactionId'=>$id,'decision'=>'accept','accountId'=>$accountId]);}catch(Throwable $ignored){}}}catch(Throwable $e){foreach($ids as $id)$failed[]=['transactionId'=>$id,'error'=>mb_substr($e->getMessage(),0,300)];}}
    return ['ok'=>!$failed,'updated'=>$updated,'failed'=>$failed,'posted'=>0,'accountingEntriesCreated'=>0,'message'=>$failed?"{$updated} category decision(s) applied; ".count($failed).' need review.':"{$updated} category decision(s) applied. Nothing was posted."];
}

function tegh_native_reconciliation_feedback(array $user,array $company,array $input): array
{
    require_company_permission($company,'banking.reconcile');$state=tegh_native_state($user,$company);if(!$state['policy']['native_ai_learning_enabled']||!$state['policy']['native_ai_reconciliation_enabled'])return ['ok'=>true,'recorded'=>false];$outcome=clean_text($input['outcome']??'','Outcome',20);if(!in_array($outcome,['accepted','rejected'],true))fail('Reconciliation learning outcome is invalid.');$bankIds=array_slice(array_values(array_filter(array_map('strval',(array)($input['bankTransactionIds']??[])))),0,25);$bookIds=array_slice(array_values(array_filter(array_map('strval',(array)($input['journalEntryIds']??[])))),0,25);if(!$bankIds&&!$bookIds)fail('Choose reconciliation items first.');tegh_ai_record_event($user,$company,$outcome==='accepted'?'reconciliation_suggestion_accepted':'reconciliation_suggestion_rejected',['module'=>'Banking','actionId'=>'native.reconciliation_feedback','signal'=>$outcome==='accepted'?1:-1,'outcome'=>$outcome,'confidenceBps'=>0,'bankCount'=>count($bankIds),'bookCount'=>count($bookIds)]);return ['ok'=>true,'recorded'=>true,'outcome'=>$outcome];
}

function tegh_native_endpoint(array $user,array $company): never
{
    if(request_method()==='PUT'){require_csrf();$input=request_json();if(isset($input['policy'])){$flags=tegh_native_save_policy($user,$company,(array)$input['policy']);json_response(['ok'=>true,'policy'=>$flags,'state'=>tegh_native_state($user,$company)]);}if(!array_key_exists('enabled',$input))fail('Choose whether Native Intelligence should be enabled on this device.');tegh_native_save_user_preference($user,$company,(bool)$input['enabled']);json_response(['ok'=>true,'state'=>tegh_native_state($user,$company)]);}
    if(request_method()==='POST'){require_csrf();$input=request_json();$action=(string)($input['action']??'');if($action==='categorization_candidates')json_response(['ok'=>true,'data'=>tegh_native_categorization_candidates($user,$company,$input)]);if($action==='learning_feedback')json_response(tegh_native_feedback($user,$company,$input));if($action==='apply_categories')json_response(tegh_native_apply_categories($user,$company,$input));if($action==='reconciliation_feedback')json_response(tegh_native_reconciliation_feedback($user,$company,$input));if($action==='validate_intent'){if(platform_role_for_user((string)$user['id'])!=='platform_owner')fail('Native Intelligence diagnostics are restricted to the Platform Owner.',403,'platform_owner_required');try{$validated=tegh_native_validate_browser_intent($input['intent']??null,$company);json_response(['ok'=>true,'valid'=>true,'intent'=>$validated,'readOnly'=>true]);}catch(Throwable $e){json_response(['ok'=>false,'valid'=>false,'error'=>'intent_rejected','message'=>'The browser-generated action was rejected by Tegh server validation.'],422);}}fail('Native Intelligence action is not supported.',404,'native_action_not_found');}
    require_method('GET');json_response(tegh_native_state($user,$company));
}
