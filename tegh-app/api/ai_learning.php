<?php
declare(strict_types=1);

/**
 * Tegh 4.2 controlled learning + self-improvement layer.
 *
 * This module may change interpretation, ranking, recommendations and user/company
 * preferences. It must never bypass the Action Registry, company permissions,
 * posting confirmations, accounting services, period locks or tenant isolation.
 */

function tegh_ai_learning_schema_ready(): void
{
    if(function_exists('ai_additive_schema_ready') && ai_additive_schema_ready()) return;
    $required=['ai_agent_learning_events','ai_agent_learned_rules','ai_agent_improvements','ai_agent_behavior_versions','ai_agent_review_runs'];
    foreach($required as $table) if(!schema_table_exists($table)) fail('Tegh AI learning storage needs maintenance. Your accounting data is unaffected.',503,'tegh_ai_storage_maintenance_required');
    if(function_exists('ai_additive_schema_ready') && !ai_additive_schema_ready()) fail('Tegh AI durable memory storage needs maintenance. Your accounting data is unaffected.',503,'tegh_ai_storage_maintenance_required');
}

function tegh_ai_learning_json(mixed $value): string
{
    return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

/** Store only a generalized command sample. Raw accounting names/amounts are not needed for global diagnostics. */
function tegh_ai_generalize_text(string $text): string
{
    $value=tegh_normalize_action_text($text);
    $value=(string)preg_replace('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i','[email]',$value);
    $value=(string)preg_replace('/\$?\s*\d[\d,]*(?:\.\d{1,2})?/','[amount]',$value);
    $value=(string)preg_replace('/\b20\d{2}-\d{2}-\d{2}\b/','[date]',$value);
    $value=(string)preg_replace('/["“”\']([^"“”\']{2,80})["“”\']/','[entity]',$value);
    $value=(string)preg_replace('/\s+/',' ',$value);
    return mb_substr(trim($value),0,300);
}

function tegh_ai_command_fingerprint(string $text): string
{
    return hash('sha256',tegh_ai_generalize_text($text));
}

function tegh_ai_confidence_label(int $basisPoints): string
{
    if($basisPoints>=8500)return 'High';
    if($basisPoints>=6000)return 'Medium';
    return 'Low';
}

/**
 * Canonical local interpretation pass. This is deliberately deterministic and
 * conservative. A high score can automate read/navigation only; financial or
 * destructive actions still require their registered safeguards.
 */
function tegh_ai_interpret_request(string $text): array
{
    $q=tegh_normalize_action_text($text);$categories=[];$risk='read';$confidence=5200;
    $add=static function(string $name)use(&$categories):void{if(!in_array($name,$categories,true))$categories[]=$name;};
    if(preg_match('/\b(go to|open|take me to|navigate)\b/',$q)){$add('Navigation');$confidence+=900;}
    if(preg_match('/\b(find|search|show|list|which|what)\b/',$q))$add('Search');
    if(preg_match('/\b(balance|outstanding|unpaid|overdue|how much|total)\b/',$q))$add('Query');
    if(preg_match('/\b(why|analy[sz]e|review|check|inspect|compare|trend|unusual|diagnos)\b/',$q))$add('Analysis');
    if(preg_match('/\b(create|add|new)\b/',$q)){$add('Create');$risk='prepare';}
    if(preg_match('/\b(update|edit|change)\b/',$q)){$add('Update');$risk='prepare';}
    if(preg_match('/\b(prepare|draft)\b/',$q)){$add('Prepare');$risk='prepare';}
    if(preg_match('/\bcategori[sz]e\b/',$q)){$add('Categorize');$risk='prepare';}
    if(preg_match('/\b(match|allocate)\b/',$q)){$add('Match');$risk='prepare';}
    if(preg_match('/\breconcil(?:e|iation)\b/',$q)){$add('Reconcile');$risk='financial';}
    if(preg_match('/\b(post|finali[sz]e|issue)\b/',$q)){$add('Post');$risk='financial';}
    if(preg_match('/\b(reverse|void)\b/',$q)){$add('Reverse');$risk='destructive';}
    if(preg_match('/\b(delete|remove permanently)\b/',$q)){$add('Delete');$risk='destructive';}
    if(preg_match('/\bimport\b/',$q)){$add('Import');$risk='prepare';}
    if(preg_match('/\b(export|download|print)\b/',$q))$add('Export');
    if(preg_match('/\b(report|p\s*&\s*l|profit|balance sheet|cash flow|trial balance|aging|ageing|ledger)\b/',$q))$add('Report');
    if(preg_match('/\b(explain|why did|why is)\b/',$q))$add('Explain');
    if(preg_match('/\b(why doesn.t|why does .{0,80} not agree|does not agree|doesn.t agree|not agree with|duplicate|unreconciled|books look complete|ready to close|month.?end review)\b/',$q)){$add('Diagnose');$confidence+=1000;}
    if(preg_match('/\b(compare|versus|vs\.?|difference between)\b/',$q))$add('Compare');
    if(preg_match('/\b(forecast|predict|projection|project)\b/',$q))$add('Forecast');
    if(preg_match('/\b(setup|configure|settings)\b/',$q))$add('Setup');
    if(preg_match('/\b(help|how do i|how can i)\b/',$q))$add('Help');
    if(!$categories)$categories=['Query'];
    if(count($categories)>1)$confidence+=min(900,(count($categories)-1)*180);
    if(preg_match('/\b(and then|then show|and show|, then| after that)\b/',$q))$confidence+=500;
    $confidence=max(2500,min(9800,$confidence));
    return [
        'normalized'=>$q,
        'categories'=>$categories,
        'primary'=>$categories[0],
        'compound'=>count($categories)>1 || (bool)preg_match('/\b(and then|then|and show|after that)\b/',$q),
        'risk'=>$risk,
        'confidenceBps'=>$confidence,
        'confidence'=>tegh_ai_confidence_label($confidence),
    ];
}

function tegh_ai_safe_action_score(array $action,string $query,array $interpretation): int
{
    $q=' '.tegh_normalize_action_text($query).' ';$score=0;
    $fields=[(string)($action['name']??''),(string)($action['description']??''),(string)($action['module']??''),implode(' ',(array)($action['keywords']??[]))];
    $hay=' '.tegh_normalize_action_text(implode(' ',$fields)).' ';
    $tokens=array_values(array_unique(array_filter(preg_split('/[^a-z0-9&]+/i',trim($q))?:[],static fn(string $v):bool=>mb_strlen($v)>=3)));
    foreach($tokens as $token)if(str_contains($hay,' '.$token) || str_contains($hay,$token))$score+=240;
    $name=tegh_normalize_action_text((string)($action['name']??''));
    if($name!==''&&str_contains($q,' '.$name.' '))$score+=1800;
    foreach((array)($action['keywords']??[]) as $kw){$kw=tegh_normalize_action_text((string)$kw);if($kw!==''&&str_contains($q,$kw))$score+=1200;}
    if(($interpretation['primary']??'')==='Navigation'&&!empty($action['supports_navigation']))$score+=500;
    if(in_array('Report',$interpretation['categories']??[],true)&&($action['module']??'')==='Reports')$score+=700;
    if(in_array('Import',$interpretation['categories']??[],true)&&str_contains((string)($action['action_id']??''),'import'))$score+=700;
    // Never let generic lexical scoring become an authorization mechanism.
    if(!empty($action['financial_commit'])||!empty($action['destructive']))$score-=100000;
    if(!in_array((string)($action['action_type']??''),['navigation','read','prepare'],true))$score-=1200;
    return $score;
}

function tegh_ai_resolve_safe_action(array $company,string $query,array $interpretation): array
{
    $rows=[];foreach(tegh_action_registry() as $action){
        if(!company_role_can((string)$company['role'],(string)($action['required_permission']??'')))continue;
        $score=tegh_ai_safe_action_score($action,$query,$interpretation);if($score<=0)continue;
        $rows[]=['actionId'=>(string)$action['action_id'],'name'=>(string)$action['name'],'route'=>(string)$action['route'],'score'=>$score,'type'=>(string)$action['action_type']];
    }
    usort($rows,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);$top=$rows[0]??null;$runner=$rows[1]??null;
    $resolved=$top!==null&&$top['score']>=1700&&($runner===null||($top['score']-$runner['score'])>=350);
    return ['status'=>$resolved?'resolved':($top?'ambiguous':'missing'),'top'=>$top,'candidates'=>array_slice($rows,0,5)];
}

function tegh_ai_build_plan(string $query,array $interpretation,?string $actionId=null,?string $resultSetId=null): array
{
    $steps=[];$cats=$interpretation['categories']??[];
    $steps[]=['phase'=>'Understand','detail'=>'Interpret the request, active company, period, entities and requested output.'];
    $steps[]=['phase'=>'Retrieve','detail'=>'Load only company-scoped records required for this request and the current user permissions.'];
    if($resultSetId)$steps[]=['phase'=>'Scope','detail'=>'Bind any follow-up reference such as “these” to the current server-side result set.'];
    if(in_array('Analysis',$cats,true)||in_array('Diagnose',$cats,true)||in_array('Query',$cats,true))$steps[]=['phase'=>'Analyze','detail'=>'Evaluate the relevant accounting records without changing them.'];
    if(array_intersect($cats,['Create','Update','Prepare','Categorize','Match','Reconcile','Post','Reverse','Delete']))$steps[]=['phase'=>'Preview','detail'=>'Resolve required inputs and show the exact proposed action before any protected commit.'];
    if(in_array(($interpretation['risk']??'read'),['financial','destructive'],true))$steps[]=['phase'=>'Confirm','detail'=>'Use the registered confirmation policy; approval is bound to company, user and exact payload.'];
    if(array_intersect($cats,['Create','Update','Categorize','Match','Reconcile','Post','Reverse','Delete']))$steps[]=['phase'=>'Execute','detail'=>'Use the existing Tegh service registered for the action; never a parallel accounting engine.'];
    if(array_intersect($cats,['Create','Update','Categorize','Match','Reconcile','Post','Reverse','Delete']))$steps[]=['phase'=>'Verify','detail'=>'Re-read source and ledger state and reject a false-success result if invariants do not hold.'];
    $steps[]=['phase'=>'Explain','detail'=>'Return a concise result, accounting impact or reason the request cannot safely proceed.'];
    $steps[]=['phase'=>'Learn','detail'=>'Record only safe, tenant-scoped performance/correction signals for future recommendations.'];
    return ['requestFingerprint'=>tegh_ai_command_fingerprint($query),'actionId'=>$actionId,'steps'=>$steps];
}

function tegh_ai_record_event(array $user,array $company,string $eventType,array $data=[]): ?string
{
    if(!schema_table_exists('ai_agent_learning_events'))return null;
    $id=new_id('ailearn');$question=(string)($data['question']??'');$sample=$question!==''?tegh_ai_generalize_text($question):null;
    $signal=max(-1,min(1,(int)($data['signal']??0)));$confidence=max(0,min(10000,(int)($data['confidenceBps']??0)));
    $safeContext=$data;unset($safeContext['question']);
    // Performance telemetry does not need raw merchant/customer/vendor descriptions.
    // Preserve correlation as a one-way hash while the actual learned rule remains
    // isolated in the company-scoped rules table where the user can inspect/delete it.
    foreach(['pattern','merchant','description','customer','vendor','entity'] as $field){if(isset($safeContext[$field])&&is_scalar($safeContext[$field])){$safeContext[$field.'Hash']=hash('sha256',(string)$safeContext[$field]);unset($safeContext[$field]);}}
    $stmt=db()->prepare("INSERT INTO ai_agent_learning_events (id,company_id,user_id,event_type,module,action_id,intent_category,confidence_bps,`signal`,outcome,command_fingerprint,command_sample,context_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$id,(string)$company['id'],(string)$user['id'],mb_substr($eventType,0,50),mb_substr((string)($data['module']??''),0,80)?:null,mb_substr((string)($data['actionId']??''),0,120)?:null,mb_substr((string)($data['intentCategory']??''),0,50)?:null,$confidence,$signal,mb_substr((string)($data['outcome']??'observed'),0,30),$question!==''?tegh_ai_command_fingerprint($question):null,$sample,tegh_ai_learning_json(agent_sanitize($safeContext))]);
    return $id;
}

function tegh_ai_normalized_pattern(string $text): string
{
    $merchant=function_exists('normalize_merchant')?normalize_merchant($text):mb_strtoupper(trim($text));
    return mb_substr(trim($merchant),0,200);
}

function tegh_ai_rule_confidence(int $positive,int $negative,string $source='inferred'): int
{
    if($source==='explicit')return max(8500,min(9900,9000+$positive*60-$negative*900));
    $n=$positive+$negative;if($n<=0)return 0;
    $ratio=$positive/$n;$evidence=min(1.0,$n/12);$confidence=(int)round((0.45+0.5*$ratio)*$evidence*10000);
    if($negative>=2)$confidence-=min(3000,$negative*650);
    return max(0,min(9800,$confidence));
}

function tegh_ai_upsert_rule(array $user,array $company,array $rule,int $positiveDelta=0,int $negativeDelta=0): array
{
    tegh_ai_learning_schema_ready();$companyId=(string)$company['id'];$userId=$rule['userId']??null;$scopeType=(string)($rule['scopeType']??'company');$scopeKey=mb_substr((string)($rule['scopeKey']??''),0,128);$patternType=(string)($rule['patternType']??'merchant_contains');$patternValue=mb_substr((string)($rule['patternValue']??''),0,255);$suggestionType=(string)($rule['suggestionType']??'account_category');$source=(string)($rule['source']??'inferred');
    if($patternValue==='')fail('A learned rule pattern is required.');
    $stmt=db()->prepare("SELECT * FROM ai_agent_learned_rules WHERE company_id=? AND ((user_id IS NULL AND ? IS NULL) OR user_id=?) AND scope_type=? AND scope_key=? AND pattern_type=? AND pattern_value=? AND suggestion_type=? AND status<>'retired' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$companyId,$userId,$userId,$scopeType,$scopeKey,$patternType,$patternValue,$suggestionType]);$row=$stmt->fetch();$suggestion=agent_sanitize($rule['suggestion']??[]);
    if($row){$positive=max(0,(int)$row['positive_count']+$positiveDelta);$negative=max(0,(int)$row['negative_count']+$negativeDelta);$confidence=tegh_ai_rule_confidence($positive,$negative,$source==='explicit'||(string)$row['source']==='explicit'?'explicit':'inferred');$status=$negative>=4&&$negative>$positive?'retired':((string)$row['status']==='disabled'?'disabled':'enabled');db()->prepare("UPDATE ai_agent_learned_rules SET suggestion_json=?,positive_count=?,negative_count=?,confidence_bps=?,source=?,status=?,last_used_at=IF(?<>0,UTC_TIMESTAMP(),last_used_at),updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([tegh_ai_learning_json($suggestion),$positive,$negative,$confidence,$source,$status,$positiveDelta+$negativeDelta,(string)$row['id'],$companyId]);$id=(string)$row['id'];}
    else{$id=new_id('airule');$positive=max(0,$positiveDelta);$negative=max(0,$negativeDelta);$confidence=tegh_ai_rule_confidence($positive,$negative,$source);db()->prepare("INSERT INTO ai_agent_learned_rules (id,company_id,user_id,scope_type,scope_key,pattern_type,pattern_value,suggestion_type,suggestion_json,positive_count,negative_count,confidence_bps,status,source,last_used_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")->execute([$id,$companyId,$userId,$scopeType,$scopeKey,$patternType,$patternValue,$suggestionType,tegh_ai_learning_json($suggestion),$positive,$negative,$confidence,'enabled',$source]);}
    $out=db()->prepare('SELECT * FROM ai_agent_learned_rules WHERE id=? AND company_id=? LIMIT 1');$out->execute([$id,$companyId]);return $out->fetch()?:[];
}

function tegh_ai_decay_rules_for_config_change(array $user,array $company,string $reason,?string $accountId=null): array
{
    $result=['affected'=>0,'disabled'=>0,'decayed'=>0,'reason'=>$reason];
    try {
        if(!schema_table_exists('ai_agent_learned_rules'))return $result;
        $companyId=(string)($company['id']??'');if($companyId==='')return $result;
        $stmt=db()->prepare("SELECT id,source,status,confidence_bps,suggestion_json FROM ai_agent_learned_rules WHERE company_id=? AND status='enabled' AND suggestion_type='account_category'");
        $stmt->execute([$companyId]);
        foreach($stmt->fetchAll() as $row){
            $suggestion=json_decode((string)$row['suggestion_json'],true)?:[];
            $targetAccount=(string)($suggestion['accountId']??'');
            $taxCode=trim((string)($suggestion['taxCode']??''));
            $matches=false;$disable=false;$drop=0;
            if(in_array($reason,['account_deleted','account_deactivated'],true)){$matches=$accountId!==null&&$targetAccount===$accountId;$disable=$matches;}
            elseif($reason==='account_updated'){$matches=$accountId!==null&&$targetAccount===$accountId;$drop=1500;}
            elseif($reason==='tax_configuration_changed'){$matches=$taxCode!=='';$drop=2000;}
            elseif(in_array($reason,['fiscal_configuration_changed','reporting_framework_changed','accounting_basis_changed'],true)){$matches=true;$drop=1000;}
            if(!$matches)continue;
            if($disable){db()->prepare("UPDATE ai_agent_learned_rules SET status='disabled',confidence_bps=LEAST(confidence_bps,3000),updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([(string)$row['id'],$companyId]);$result['disabled']++;}
            else{$next=max(0,(int)$row['confidence_bps']-$drop);db()->prepare('UPDATE ai_agent_learned_rules SET confidence_bps=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$next,(string)$row['id'],$companyId]);$result['decayed']++;}
            $result['affected']++;
        }
        if(schema_table_exists('ai_agent_memories')){
            $mem=db()->prepare("SELECT * FROM ai_agent_memories WHERE company_id=? AND memory_type='company_semantic' AND status='enabled'");$mem->execute([$companyId]);
            foreach($mem->fetchAll() as $mr){$value=json_decode((string)$mr['value_json'],true)?:[];$target=(string)($value['accountId']??'');$tax=trim((string)($value['taxCode']??''));$matches=false;$disable=false;$drop=0;
                if(in_array($reason,['account_deleted','account_deactivated'],true)){$matches=$accountId!==null&&$target===$accountId;$disable=$matches;}
                elseif($reason==='account_updated'){$matches=$accountId!==null&&$target===$accountId;$drop=1500;}
                elseif($reason==='tax_configuration_changed'){$matches=$tax!=='';$drop=2000;}
                elseif(in_array($reason,['fiscal_configuration_changed','reporting_framework_changed','accounting_basis_changed'],true)){$matches=true;$drop=1000;}
                if(!$matches)continue;$before=function_exists('tegh_human_memory_public')?tegh_human_memory_public($mr):[];
                if($disable)db()->prepare("UPDATE ai_agent_memories SET status='disabled',confidence_bps=LEAST(confidence_bps,3000),reason=?,version=version+1 WHERE id=? AND company_id=?")->execute(['Disabled after '.$reason,(string)$mr['id'],$companyId]);
                else db()->prepare("UPDATE ai_agent_memories SET confidence_bps=GREATEST(0,confidence_bps-?),reason=?,version=version+1 WHERE id=? AND company_id=?")->execute([$drop,'Recalibrated after '.$reason,(string)$mr['id'],$companyId]);
                if(function_exists('tegh_human_memory_history')){$check=db()->prepare('SELECT * FROM ai_agent_memories WHERE id=?');$check->execute([(string)$mr['id']]);$after=$check->fetch();if($after)tegh_human_memory_history((string)$mr['id'],$companyId,(string)$user['id'],$disable?'disabled':'decayed',$before,tegh_human_memory_public($after),'Accounting configuration changed: '.$reason);}
            }
        }
        if($result['affected']>0)tegh_ai_record_event($user,$company,'learning_rules_recalibrated',['module'=>'Settings','intentCategory'=>'Setup','signal'=>0,'outcome'=>'completed','confidenceBps'=>10000,'reason'=>$reason,'affected'=>$result['affected'],'disabled'=>$result['disabled'],'decayed'=>$result['decayed'],'accountIdHash'=>$accountId!==null?hash('sha256',$accountId):null]);
    } catch(Throwable $ignored) {
        // Learning is advisory. A telemetry/rule-maintenance failure must never block
        // a valid company or Chart of Accounts settings change.
    }
    return $result;
}

function tegh_ai_observe_bank_post_batch(array $user,array $company,array $ids,string $source='manual'): void
{
    if(!schema_table_exists('ai_agent_learned_rules')||!$ids)return;$companyId=(string)$company['id'];$ids=array_slice(array_values(array_unique(array_map('strval',$ids))),0,100);$ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT bt.id,bt.description,bt.normalized_merchant,bt.bank_account_id,bt.decided_account_id,bt.tax_code,bt.suggested_account_id,a.code,a.name,a.is_control FROM bank_transactions bt JOIN accounts a ON a.id=bt.decided_account_id AND a.company_id=bt.company_id WHERE bt.company_id=? AND bt.id IN ($ph) AND bt.status='posted'");$stmt->execute(array_merge([$companyId],$ids));
    foreach($stmt->fetchAll() as $row){if((bool)$row['is_control'])continue;$pattern=trim((string)($row['normalized_merchant']??''));if($pattern==='')$pattern=tegh_ai_normalized_pattern((string)$row['description']);if($pattern==='')continue;
        $rule=tegh_ai_upsert_rule($user,$company,['scopeType'=>'company','scopeKey'=>'','patternType'=>'merchant_contains','patternValue'=>$pattern,'suggestionType'=>'account_category','suggestion'=>['accountId'=>(string)$row['decided_account_id'],'accountCode'=>(string)$row['code'],'accountName'=>(string)$row['name'],'taxCode'=>(string)$row['tax_code']],'source'=>'inferred'],1,0);
        if($row['suggested_account_id']!==null&&(string)$row['suggested_account_id']!==(string)$row['decided_account_id']){
            tegh_ai_record_event($user,$company,'suggestion_corrected',['module'=>'Banking','actionId'=>'bank.transactions.post','signal'=>-1,'outcome'=>'corrected','confidenceBps'=>(int)($rule['confidence_bps']??0),'pattern'=>$pattern]);
        }else tegh_ai_record_event($user,$company,'posting_pattern_accepted',['module'=>'Banking','actionId'=>'bank.transactions.post','signal'=>1,'outcome'=>'completed','confidenceBps'=>(int)($rule['confidence_bps']??0),'pattern'=>$pattern,'source'=>$source]);
    }
}

function tegh_ai_observe_bank_categorization(array $user,array $company,array $ids,string $accountId,string $source='manual'): void
{
    if(!schema_table_exists('ai_agent_learning_events')||!$ids)return;
    tegh_ai_record_event($user,$company,'bank_categorization',['module'=>'Banking','actionId'=>'bank.transactions.categorize','signal'=>1,'outcome'=>'prepared','confidenceBps'=>9000,'count'=>count($ids),'accountIdHash'=>hash('sha256',$accountId),'source'=>$source]);
}

function tegh_ai_best_rule(array $company,string $description,?string $bankAccountId=null): ?array
{
    if(!schema_table_exists('ai_agent_learned_rules'))return null;$pattern=tegh_ai_normalized_pattern($description);if($pattern==='')return null;
    $stmt=db()->prepare("SELECT * FROM ai_agent_learned_rules WHERE company_id=? AND status='enabled' AND pattern_type='merchant_contains' AND ? LIKE CONCAT('%',pattern_value,'%') AND (scope_type='company' OR (scope_type='bank_account' AND scope_key=?)) ORDER BY CASE WHEN scope_type='bank_account' THEN 0 ELSE 1 END,CHAR_LENGTH(pattern_value) DESC,confidence_bps DESC,positive_count DESC LIMIT 1");$stmt->execute([(string)$company['id'],$pattern,$bankAccountId??'']);$row=$stmt->fetch();if(!$row)return null;$row['suggestion']=json_decode((string)$row['suggestion_json'],true)?:[];return $row;
}

function tegh_ai_explicit_rule_from_command(array $user,array $company,string $question,string $resultSetId=''): ?array
{
    $q=trim($question);$patternText=null;$accountQuery=null;$source='explicit';
    if(preg_match('/\b(?:whenever|when)\s+(?:you\s+)?(?:see|find)\s+(.+?),?\s+(?:categorize|categorise|classify)\s+(?:it|them)?\s*(?:as|to)\s+(.+?)[.!?]*$/i',$q,$m)){$patternText=trim($m[1]);$accountQuery=trim($m[2]);}
    // Resolve pronoun corrections against the selected/result-set transaction BEFORE
    // the generic correction parser. This prevents accidental rules such as pattern=THIS.
    elseif($resultSetId!==''&&preg_match('/^(?:no[,\s]+)?(?:this|that|it)\s+(?:should|must)\s+(?:be\s+)?(?:categorized|categorised|classified)?\s*(?:as|to)\s+(.+?)[.!?]*$/i',$q,$m)){
        $accountQuery=trim($m[1]);$result=tegh_agent_result_get($resultSetId,$user,$company);$ids=tegh_extract_target_ids($result);if($ids){$stmt=db()->prepare('SELECT description FROM bank_transactions WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$ids[0],(string)$company['id']]);$patternText=(string)($stmt->fetchColumn()?:'');$source='correction';}
    }
    elseif(preg_match('/^(?:no[,\s]+)?(.+?)\s+(?:should|must)\s+(?:be\s+)?(?:categorized|categorised|classified)?\s*(?:as|to)\s+(.+?)[.!?]*$/i',$q,$m)){$patternText=trim($m[1]);$accountQuery=trim($m[2]);$source='correction';}
    if($patternText!==null&&$accountQuery!==null){
        require_company_permission($company,'banking.match');
        if($resultSetId===''&&in_array(tegh_normalize_action_text($patternText),['this','that','it'],true))return ['recognized'=>true,'kind'=>'needs_input','message'=>'Show or select the transaction first so I can bind that correction to its actual merchant instead of learning an ambiguous rule.'];
        $pattern=tegh_ai_normalized_pattern($patternText);$accountQuery=trim((string)preg_replace('/\s+automatically\s*$/i','',$accountQuery));$resolved=tegh_resolve_account($company,$accountQuery,null);
        if($resolved['status']!=='resolved')return ['recognized'=>true,'kind'=>'needs_account','accountQuery'=>$accountQuery,'candidates'=>$resolved['candidates'],'message'=>$resolved['status']==='missing'?'No active non-control account matches that rule.':'More than one account matches. Choose one before I save the rule.'];
        $a=$resolved['account'];$row=tegh_ai_upsert_rule($user,$company,['scopeType'=>'company','scopeKey'=>'','patternType'=>'merchant_contains','patternValue'=>$pattern,'suggestionType'=>'account_category','suggestion'=>['accountId'=>(string)$a['id'],'accountCode'=>(string)$a['code'],'accountName'=>(string)$a['name']],'source'=>$source],1,0);
        audit_event($user,(string)$company['id'],'ai_agent.learned_rule_created','ai_agent_rule',(string)$row['id'],['patternHash'=>hash('sha256',$pattern),'accountId'=>(string)$a['id'],'source'=>$source]);
        tegh_ai_record_event($user,$company,$source==='correction'?'user_correction':'explicit_rule_created',['question'=>$question,'module'=>'Learning','actionId'=>'bank.transactions.categorize','intentCategory'=>'Categorize','signal'=>1,'outcome'=>'accepted','confidenceBps'=>(int)$row['confidence_bps'],'ruleIdHash'=>hash('sha256',(string)$row['id'])]);
        return ['recognized'=>true,'kind'=>'learned_rule','rule'=>tegh_ai_rule_public($row),'message'=>"I’ll suggest {$a['name']} for matching {$pattern} transactions in this company. Posting still requires Tegh’s normal controls."];
    }
    if(preg_match('/\b(?:don.t|do not|never)\s+(?:automatically\s+)?(?:classify|categorize|categorise|suggest)\s+(.+?)[.!?]*$/i',$q,$m)){
        require_company_permission($company,'banking.match');
        $raw=(string)preg_replace('/\s+automatically\s*$/i','',trim($m[1]));$pattern=tegh_ai_normalized_pattern($raw);if($pattern==='')return null;
        $stmt=db()->prepare("UPDATE ai_agent_learned_rules SET status='disabled',updated_at=UTC_TIMESTAMP() WHERE company_id=? AND pattern_type='merchant_contains' AND pattern_value=? AND status='enabled'");$stmt->execute([(string)$company['id'],$pattern]);
        tegh_ai_record_event($user,$company,'rule_disabled',['question'=>$question,'signal'=>-1,'outcome'=>'user_disabled','pattern'=>$pattern]);
        return ['recognized'=>true,'kind'=>'learned_rule','message'=>"Automatic Tegh AI suggestions for {$pattern} are disabled for this company."];
    }
    return null;
}

function tegh_ai_rule_public(array $row): array
{
    $suggestion=is_array($row['suggestion']??null)?$row['suggestion']:(json_decode((string)($row['suggestion_json']??'{}'),true)?:[]);
    return ['id'=>(string)$row['id'],'scope'=>(string)$row['scope_type'],'scopeKey'=>(string)$row['scope_key'],'patternType'=>(string)$row['pattern_type'],'pattern'=>(string)$row['pattern_value'],'suggestion'=>$suggestion,'confidenceBps'=>(int)$row['confidence_bps'],'confidence'=>tegh_ai_confidence_label((int)$row['confidence_bps']),'positiveCount'=>(int)$row['positive_count'],'negativeCount'=>(int)$row['negative_count'],'status'=>(string)$row['status'],'source'=>(string)$row['source'],'lastUsedAt'=>$row['last_used_at']??null,'updatedAt'=>$row['updated_at']??null];
}

function tegh_ai_explain_suggestion(array $user,array $company,string $resultSetId=''): array
{
    $companyId=(string)$company['id'];$row=null;
    if($resultSetId!==''){$result=tegh_agent_result_get($resultSetId,$user,$company);$ids=tegh_extract_target_ids($result);if($ids){$stmt=db()->prepare('SELECT id,description,bank_account_id,decided_account_id,suggested_account_id,tax_code FROM bank_transactions WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$ids[0],$companyId]);$row=$stmt->fetch()?:null;}}
    if(!$row)return ['recognized'=>true,'kind'=>'explanation','message'=>'Select or show the transaction you want me to explain, then ask why I suggested its category.'];
    $rule=tegh_ai_best_rule($company,(string)$row['description'],(string)$row['bank_account_id']);
    if($rule){$s=$rule['suggestion']??[];$name=(string)($s['accountName']??'that account');return ['recognized'=>true,'kind'=>'explanation','message'=>"I suggested {$name} because this company has {$rule['positive_count']} accepted matching pattern(s) and {$rule['negative_count']} correction(s). The learned rule is ".tegh_ai_confidence_label((int)$rule['confidence_bps']).' confidence. It is a recommendation only; posting still uses Tegh’s accounting validation and confirmation controls.','rule'=>tegh_ai_rule_public($rule)];}
    return ['recognized'=>true,'kind'=>'explanation','message'=>'I do not have a strong company-specific learned rule for this transaction yet. Any current category is coming from Tegh’s existing transaction history/default suggestion logic, not an autonomous posting rule.'];
}

function tegh_ai_period_from_text(string $question): array
{
    $filters=tegh_parse_bank_filters($question,[]);if(isset($filters['dateFrom'])||isset($filters['dateTo']))return ['from'=>$filters['dateFrom']??null,'to'=>$filters['dateTo']??null];
    $q=tegh_normalize_action_text($question);$months=['january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12];
    foreach($months as $name=>$month)if(str_contains($q,$name)){[$from,$to]=tegh_month_range((int)date('Y'),$month);return compact('from','to');}
    return ['from'=>date('Y-m-01'),'to'=>date('Y-m-d')];
}

function tegh_ai_close_review(array $user,array $company,string $question): array
{
    require_company_permission($company,'reports.view');$companyId=(string)$company['id'];$period=tegh_ai_period_from_text($question);$from=$period['from'];$to=$period['to'];$items=[];
    $scalar=static function(string $sql,array $params):int{$s=db()->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();};
    $pendingUncat=$scalar("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='pending' AND decided_account_id IS NULL AND transaction_date BETWEEN ? AND ?",[$companyId,$from,$to]);
    if($pendingUncat)$items[]=['severity'=>'High','area'=>'Banking','count'=>$pendingUncat,'message'=>"{$pendingUncat} pending bank transaction(s) are uncategorized.",'actionId'=>'nav.bank_review'];
    $duplicates=$scalar("SELECT COUNT(*) FROM bank_transactions WHERE company_id=? AND status='duplicate' AND transaction_date BETWEEN ? AND ?",[$companyId,$from,$to]);if($duplicates)$items[]=['severity'=>'Medium','area'=>'Banking','count'=>$duplicates,'message'=>"{$duplicates} imported transaction(s) are marked duplicate and should be reviewed.",'actionId'=>'nav.bank_review'];
    $draftInvoices=$scalar("SELECT COUNT(*) FROM invoices WHERE company_id=? AND status='draft' AND issue_date BETWEEN ? AND ?",[$companyId,$from,$to]);if($draftInvoices)$items[]=['severity'=>'Medium','area'=>'Receivables','count'=>$draftInvoices,'message'=>"{$draftInvoices} customer invoice draft(s) remain unissued.",'actionId'=>'nav.customer_invoices'];
    $draftBills=$scalar("SELECT COUNT(*) FROM bills WHERE company_id=? AND status='draft' AND bill_date BETWEEN ? AND ?",[$companyId,$from,$to]);if($draftBills)$items[]=['severity'=>'Medium','area'=>'Payables','count'=>$draftBills,'message'=>"{$draftBills} vendor invoice draft(s) remain unposted.",'actionId'=>'nav.vendor_invoices'];
    $imbalanced=$scalar("SELECT COUNT(*) FROM (SELECT je.id,SUM(jl.debit_cents) d,SUM(jl.credit_cents) c FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.company_id=? AND je.entry_date BETWEEN ? AND ? GROUP BY je.id HAVING d<>c) x",[$companyId,$from,$to]);if($imbalanced)$items[]=['severity'=>'Critical','area'=>'General Ledger','count'=>$imbalanced,'message'=>"{$imbalanced} journal entry/entries are not balanced.",'actionId'=>'nav.day_book'];
    $reconcile=$scalar("SELECT COUNT(*) FROM bank_accounts ba WHERE ba.company_id=? AND ba.active=1 AND NOT EXISTS(SELECT 1 FROM reconciliations r WHERE r.company_id=ba.company_id AND r.bank_account_id=ba.id AND r.status='complete' AND r.period_end>=?)",[$companyId,$to]);if($reconcile)$items[]=['severity'=>'Medium','area'=>'Reconciliation','count'=>$reconcile,'message'=>"{$reconcile} active bank account(s) do not have a completed reconciliation through the review date.",'actionId'=>'nav.reconciliation'];
    $arControl=account_by_code($companyId,'1200');$apControl=account_by_code($companyId,'2000');
    $balanceFor=static function(string $accountId,string $cid,string $to):int{$s=db()->prepare("SELECT COALESCE(SUM(jl.debit_cents-jl.credit_cents),0) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id WHERE je.company_id=? AND jl.account_id=? AND je.entry_date<=?");$s->execute([$cid,$accountId,$to]);return (int)$s->fetchColumn();};
    $arSub=$scalar("SELECT COALESCE(SUM(balance_cents),0) FROM invoices WHERE company_id=? AND status IN ('sent','paid') AND issue_date<=?",[$companyId,$to]);$arGl=$balanceFor($arControl,$companyId,$to);$arDiff=$arSub-$arGl;if(abs($arDiff)>1)$items[]=['severity'=>'High','area'=>'Receivables','count'=>1,'message'=>'AR subsidiary balances differ from the AR control account by $'.number_format(abs($arDiff)/100,2).'.','actionId'=>'report.ar_aging'];
    $apSub=$scalar("SELECT COALESCE(SUM(balance_cents),0) FROM bills WHERE company_id=? AND status IN ('open','paid') AND bill_date<=?",[$companyId,$to]);$apGlCredit=-$balanceFor($apControl,$companyId,$to);$apDiff=$apSub-$apGlCredit;if(abs($apDiff)>1)$items[]=['severity'=>'High','area'=>'Payables','count'=>1,'message'=>'AP subsidiary balances differ from the AP control account by $'.number_format(abs($apDiff)/100,2).'.','actionId'=>'report.ap_aging'];
    $taxProblem=((bool)$company['tax_registered']&&(int)$company['tax_rate_bps']<=0);if($taxProblem)$items[]=['severity'=>'High','area'=>'Tax','count'=>1,'message'=>'GST/HST is enabled but the company tax rate is not configured correctly.','actionId'=>'nav.tax_settings'];
    $rank=['Critical'=>0,'High'=>1,'Medium'=>2,'Low'=>3];usort($items,static fn($a,$b)=>($rank[$a['severity']]??9)<=>($rank[$b['severity']]??9));
    tegh_ai_record_event($user,$company,'close_review',['question'=>$question,'module'=>'Diagnostics','actionId'=>'diagnostic.close_review','intentCategory'=>'Diagnose','signal'=>0,'outcome'=>'completed','confidenceBps'=>9400,'issueCount'=>count($items)]);
    return ['recognized'=>true,'kind'=>'diagnostic','actionId'=>'diagnostic.close_review','period'=>$period,'items'=>$items,'message'=>$items?count($items).' review item(s) need attention before this period looks complete.':'No exception was found in the automated close-review checks for this period. This is not a substitute for professional review.'];
}

function tegh_ai_bank_impact_preview(array $company,array $rows,string $overrideAccountId='',bool $taxExplicit=false,bool $applyGstHst=false,bool $applyPst=false): array
{
    $companyId=(string)$company['id'];$accountCache=[];$bankCache=[];$totals=[];$pnlExpense=0;$pnlIncome=0;
    $account=function(string $id)use($companyId,&$accountCache):array{if(isset($accountCache[$id]))return $accountCache[$id];$stmt=db()->prepare('SELECT id,code,name,account_type,is_control FROM accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$stmt->execute([$id,$companyId]);$row=$stmt->fetch();if(!$row)fail('A posting account in the preview is no longer available.',409,'account_unavailable');return $accountCache[$id]=$row;};
    $bank=function(string $bankId)use($companyId,&$bankCache,$account):array{if(isset($bankCache[$bankId]))return $bankCache[$bankId];$stmt=db()->prepare('SELECT ledger_account_id FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$stmt->execute([$bankId,$companyId]);$ledger=(string)($stmt->fetchColumn()?:'');if($ledger==='')fail('A bank account in the preview is unavailable.',409,'bank_account_unavailable');return $bankCache[$bankId]=$account($ledger);};
    $add=function(array $acct,int $debit,int $credit)use(&$totals,&$pnlExpense,&$pnlIncome):void{$id=(string)$acct['id'];if(!isset($totals[$id]))$totals[$id]=['accountId'=>$id,'code'=>(string)$acct['code'],'name'=>(string)$acct['name'],'accountType'=>(string)$acct['account_type'],'debitCents'=>0,'creditCents'=>0];$totals[$id]['debitCents']+=$debit;$totals[$id]['creditCents']+=$credit;if((string)$acct['account_type']==='expense')$pnlExpense+=$debit-$credit;if((string)$acct['account_type']==='income')$pnlIncome+=$credit-$debit;};
    foreach($rows as $row){$amount=(int)$row['amount_cents'];$categoryId=$overrideAccountId!==''?$overrideAccountId:(string)($row['decided_account_id']??'');if($categoryId==='')fail('A transaction needs a category before its accounting impact can be previewed.',409,'posting_category_required');$category=$account($categoryId);$bankAcct=$bank((string)$row['bank_account_id']);$storedTax=(string)($row['tax_code']??'NO_TAX');$gst=$taxExplicit?$applyGstHst:(str_contains($storedTax,'GST_HST')||$storedTax==='HST13');$pst=$taxExplicit?$applyPst:str_contains($storedTax,'PST');
        if($amount>0){$total=$amount;$gst=$gst&&(bool)$company['tax_registered']&&(string)$category['account_type']==='income';$pst=$pst&&(bool)($company['pst_registered']??false)&&(string)$category['account_type']==='income';if($gst||$pst){$parts=calculate_tax_components($total,'inclusive',$gst?(int)$company['tax_rate_bps']:0,$pst?(int)($company['pst_rate_bps']??0):0);$add($bankAcct,$total,0);$add($category,0,(int)$parts['netCents']);if((int)$parts['gstHstCents']>0)$add($account(account_by_code($companyId,'2100')),0,(int)$parts['gstHstCents']);if((int)$parts['pstCents']>0)$add($account(account_by_code($companyId,'2110')),0,(int)$parts['pstCents']);}else{$add($bankAcct,$total,0);$add($category,0,$total);}}
        else{$total=abs($amount);$gst=$gst&&(bool)$company['tax_registered']&&in_array((string)$category['account_type'],['expense','asset'],true);$pst=$pst&&(bool)($company['pst_registered']??false)&&in_array((string)$category['account_type'],['expense','asset'],true);$parts=calculate_tax_components($total,($gst||$pst)?'inclusive':'none',$gst?(int)$company['tax_rate_bps']:0,$pst?(int)($company['pst_rate_bps']??0):0);$pstRecoverable=(bool)($company['pst_recoverable']??false);$categoryDebit=(int)$parts['netCents']+($pstRecoverable?0:(int)$parts['pstCents']);$add($category,$categoryDebit,0);if((int)$parts['gstHstCents']>0)$add($account(account_by_code($companyId,'1100')),(int)$parts['gstHstCents'],0);if((int)$parts['pstCents']>0&&$pstRecoverable)$add($account(account_by_code($companyId,'1110')),(int)$parts['pstCents'],0);$add($bankAcct,0,$total);}
    }
    $lines=array_values($totals);usort($lines,static fn(array $a,array $b):int=>strcmp((string)$a['code'],(string)$b['code']));$debit=array_sum(array_column($lines,'debitCents'));$credit=array_sum(array_column($lines,'creditCents'));
    return ['lines'=>$lines,'totalDebitsCents'=>$debit,'totalCreditsCents'=>$credit,'balanced'=>$debit===$credit,'pnlExpenseIncreaseCents'=>$pnlExpense,'pnlIncomeIncreaseCents'=>$pnlIncome,'netIncomeImpactCents'=>$pnlIncome-$pnlExpense];
}

function tegh_ai_verify_bank_post(array $company,array $ids): array
{
    $companyId=(string)$company['id'];if(!$ids)return ['ok'=>false,'reason'=>'no_targets'];$ids=array_values(array_unique(array_map('strval',$ids)));$ph=implode(',',array_fill(0,count($ids),'?'));
    $stmt=db()->prepare("SELECT bt.id,bt.status,bt.journal_entry_id,COALESCE(SUM(jl.debit_cents),0) debit_total,COALESCE(SUM(jl.credit_cents),0) credit_total FROM bank_transactions bt LEFT JOIN journal_lines jl ON jl.journal_entry_id=bt.journal_entry_id WHERE bt.company_id=? AND bt.id IN ($ph) GROUP BY bt.id,bt.status,bt.journal_entry_id");$stmt->execute(array_merge([$companyId],$ids));$rows=$stmt->fetchAll();$failures=[];
    $seen=[];foreach($rows as $r){$seen[(string)$r['id']]=true;if((string)$r['status']!=='posted'||trim((string)$r['journal_entry_id'])===''||(int)$r['debit_total']!==(int)$r['credit_total']||(int)$r['debit_total']<=0)$failures[]=['id'=>(string)$r['id'],'status'=>(string)$r['status'],'balanced'=>(int)$r['debit_total']===(int)$r['credit_total']];}
    foreach($ids as $id)if(!isset($seen[$id]))$failures[]=['id'=>$id,'status'=>'missing','balanced'=>false];
    return ['ok'=>!$failures,'checked'=>count($ids),'failures'=>$failures];
}

function tegh_ai_verify_created_record(array $company,string $type,string $id): array
{
    $table=$type==='vendor'?'vendors':'customers';$stmt=db()->prepare("SELECT id,name,active FROM {$table} WHERE id=? AND company_id=? LIMIT 1");$stmt->execute([$id,(string)$company['id']]);$row=$stmt->fetch();return ['ok'=>(bool)$row&&((int)($row['active']??0)===1),'record'=>$row?:null];
}

function tegh_ai_metrics(array $user,array $company,int $days=90): array
{
    tegh_ai_learning_schema_ready();$days=max(7,min(365,$days));$companyId=(string)$company['id'];
    $stmt=db()->prepare("SELECT SUM(event_type='command_received') commands,SUM(event_type='clarification') clarifications,SUM(`signal`<0) corrections,SUM(event_type='suggestion_accepted') suggestions_accepted,SUM(event_type='suggestion_offered') suggestions_offered,SUM(event_type='action_completed') actions_completed,SUM(event_type='action_failed') actions_failed,SUM(event_type='safety_violation') safety_violations FROM ai_agent_learning_events WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY)");$stmt->execute([$companyId]);$m=$stmt->fetch()?:[];
    $commands=(int)($m['commands']??0);$clar=(int)($m['clarifications']??0);$corr=(int)($m['corrections']??0);$offered=(int)($m['suggestions_offered']??0);$accepted=(int)($m['suggestions_accepted']??0);$actionDone=(int)($m['actions_completed']??0);$actionFail=(int)($m['actions_failed']??0);$actionAttempts=$actionDone+$actionFail;
    // These percentages are measured proxies from actual interaction outcomes;
    // they are deliberately null until there is evidence instead of fabricated.
    $intentSuccess=$commands?round(100*max(0,$commands-min($commands,$corr))/$commands,1):null;
    $firstAttempt=$commands?round(100*max(0,$commands-min($commands,$corr+$clar))/$commands,1):null;
    return ['periodDays'=>$days,'sampleSize'=>$commands,'intentSuccessPct'=>$intentSuccess,'firstAttemptCompletionPct'=>$firstAttempt,'clarificationPct'=>$commands?round(100*min($commands,$clar)/$commands,1):null,'correctionPct'=>$commands?round(100*min($commands,$corr)/$commands,1):null,'actionSuccessPct'=>$actionAttempts?round(100*$actionDone/$actionAttempts,1):null,'suggestionAcceptancePct'=>$offered?round(100*$accepted/$offered,1):null,'safetyViolations'=>(int)($m['safety_violations']??0),'sufficientData'=>$commands>=20];
}

function tegh_ai_security_metrics(array $company,int $days=90): array
{
    tegh_ai_learning_schema_ready();$days=max(7,min(365,$days));
    $stmt=db()->prepare("SELECT COUNT(*) research_events,SUM(event_type='ai_mutation_blocked') mutation_blocks,SUM(event_type='prompt_injection_blocked') injection_blocks,SUM(event_type='research_rate_limited') rate_limit_blocks,SUM(event_type='internet_research' AND outcome='failed') research_failures FROM ai_agent_learning_events WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY) AND event_type IN ('internet_research','ai_mutation_blocked','prompt_injection_blocked','research_rate_limited')");
    $stmt->execute([(string)$company['id']]);$r=$stmt->fetch()?:[];
    return ['periodDays'=>$days,'researchEvents'=>(int)($r['research_events']??0),'mutationBlocks'=>(int)($r['mutation_blocks']??0),'injectionBlocks'=>(int)($r['injection_blocks']??0),'rateLimitBlocks'=>(int)($r['rate_limit_blocks']??0),'researchFailures'=>(int)($r['research_failures']??0)];
}

function tegh_ai_performance_command(array $user,array $company): array
{
    $metrics=tegh_ai_metrics($user,$company);$areas=[];
    $stmt=db()->prepare("SELECT intent_category,COUNT(*) n,SUM(`signal`<0) corrections FROM ai_agent_learning_events WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) GROUP BY intent_category HAVING n>=3 ORDER BY corrections DESC,n DESC LIMIT 3");$stmt->execute([(string)$company['id']]);foreach($stmt->fetchAll() as $r)if((int)$r['corrections']>0)$areas[]=(string)($r['intent_category']?:'General interpretation');
    $message=$metrics['sufficientData']?'Here are measured Tegh AI results for this company.':'There is not enough interaction history yet for reliable percentages. I’ll show the available sample without inventing performance claims.';
    return ['recognized'=>true,'kind'=>'performance','metrics'=>$metrics,'areas'=>$areas,'message'=>$message];
}

function tegh_ai_failure_clusters(array $company,int $days=90): array
{
    $days=max(7,min(365,$days));
    $stmt=db()->prepare("SELECT command_fingerprint,MAX(command_sample) command_sample,MAX(intent_category) intent_category,COUNT(*) occurrences,SUM(`signal`<0 OR outcome IN ('failed','corrected','cancelled')) failures FROM ai_agent_learning_events WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY) AND command_fingerprint IS NOT NULL GROUP BY command_fingerprint HAVING failures>=2 ORDER BY failures DESC,occurrences DESC LIMIT 20");
    $stmt->execute([(string)$company['id']]);
    return $stmt->fetchAll();
}

function tegh_ai_candidate_patch_for_cluster(array $cluster): array
{
    $sample=(string)($cluster['command_sample']??'');$patch=['type'=>'phrase_synonym','phrase'=>$sample,'targetAction'=>null,'protected'=>false];
    if(preg_match('/outstanding.*customer|customer.*owe|money.*customer.*owe|receivable.*outstanding/i',$sample))$patch['targetAction']='report.ar_aging';
    elseif(preg_match('/outstanding.*vendor|vendor.*owe|money.*owe.*vendor|payable.*outstanding/i',$sample))$patch['targetAction']='report.ap_aging';
    elseif(preg_match('/bank.*review|uncategorized.*transaction/i',$sample))$patch['targetAction']='nav.bank_review';
    else{$patch['type']='review_only';$patch['protected']=true;}
    $patch['generatedTest']=['input'=>$sample,'expected'=>$patch['targetAction'],'sanitized'=>true,'source'=>'failure_cluster'];
    return $patch;
}

/**
 * Small deterministic golden suite used as a hard safety gate for behavior-only
 * synonym patches. This suite intentionally includes financial/destructive
 * phrases that must NEVER become auto-routed by the learning engine.
 */
function tegh_ai_golden_suite(): array
{
    return [
        ['question'=>'show profit and loss','expected'=>'report.profit_loss'],
        ['question'=>'open trial balance','expected'=>'report.trial_balance'],
        ['question'=>'show outstanding customer balances','expected'=>'report.ar_aging'],
        ['question'=>'show money customers owe me','expected'=>'report.ar_aging'],
        ['question'=>'show outstanding vendor balances','expected'=>'report.ap_aging'],
        ['question'=>'open bank review','expected'=>'nav.bank_review'],
        ['question'=>'post these transactions','expected'=>null,'protected'=>true],
        ['question'=>'delete these transactions','expected'=>null,'protected'=>true],
        ['question'=>'reverse this journal','expected'=>null,'protected'=>true],
    ];
}

function tegh_ai_static_safe_route(string $question,array $config=[]): ?string
{
    $q=tegh_normalize_action_text($question);
    foreach((array)($config['synonyms']??[]) as $row){
        $phrase=tegh_normalize_action_text((string)($row['phrase']??''));
        $actionId=(string)($row['actionId']??'');
        if($phrase!==''&&$actionId!==''&&str_contains($q,$phrase))return $actionId;
    }
    $report=tegh_parse_report($question);if($report)return (string)$report['actionId'];
    $nav=tegh_navigation_intent($question);if($nav)return (string)$nav['actionId'];
    return null;
}

function tegh_ai_evaluate_behavior_patch(array $company,array $patch): array
{
    $failures=[];$checks=0;
    if(!empty($patch['protected']))$failures[]='Candidate is marked protected.';
    if(($patch['type']??'')!=='phrase_synonym')$failures[]='Only phrase-synonym behavior patches are eligible for automatic promotion.';
    $phrase=trim((string)($patch['phrase']??''));$target=(string)($patch['targetAction']??'');
    if($phrase==='')$failures[]='Candidate phrase is empty.';
    if($target==='')$failures[]='Candidate target action is empty.';
    $action=null;
    if($target!==''){
        try{$action=tegh_action_get($target);}catch(Throwable $e){$failures[]='Target action is not registered.';}
    }
    if($action){
        if(!empty($action['financial_commit'])||!empty($action['destructive']))$failures[]='Target action is financial or destructive.';
        if(!in_array((string)($action['action_type']??''),['navigation','read','prepare'],true))$failures[]='Target action type is not eligible for automatic behavior promotion.';
    }
    $candidate=['synonyms'=>[['phrase'=>$phrase,'actionId'=>$target]]];
    foreach(tegh_ai_golden_suite() as $test){
        $checks++;$actual=tegh_ai_static_safe_route((string)$test['question'],$candidate);
        if(!empty($test['protected'])){
            // A generic synonym must never make a protected command resolve to a
            // behavior patch. Existing explicit protected handlers remain separate.
            if($actual!==null)$failures[]='Protected golden command was captured: '.$test['question'];
            continue;
        }
        if($actual!==(string)$test['expected'])$failures[]='Golden mismatch for "'.$test['question'].'": expected '.(string)$test['expected'].' got '.($actual??'none').'.';
    }
    // Candidate must actually improve its own sanitized failure sample.
    if($phrase!==''&&$target!==''&&tegh_ai_static_safe_route($phrase,$candidate)!==$target)$failures[]='Candidate does not resolve its own evidence phrase to the proposed action.';
    return ['passed'=>!$failures,'checks'=>$checks,'failures'=>$failures,'hardGates'=>['financialCommit'=>true,'destructive'=>true,'permissions'=>true,'tenantIsolation'=>true,'confirmation'=>true,'duplicatePrevention'=>true]];
}

function tegh_ai_active_behavior_config(array $company): array
{
    if(!schema_table_exists('ai_agent_behavior_versions'))return ['synonyms'=>[],'intentModel'=>'2.0','agentPolicy'=>'2.0'];
    $stmt=db()->prepare("SELECT config_json FROM ai_agent_behavior_versions WHERE company_id=? AND active=1 ORDER BY version_number DESC LIMIT 1");$stmt->execute([(string)$company['id']]);
    $config=json_decode((string)($stmt->fetchColumn()?:'{}'),true)?:[];
    $config['synonyms']=array_values((array)($config['synonyms']??[]));
    $config['intentModel']=$config['intentModel']??'2.0';$config['agentPolicy']=$config['agentPolicy']??'2.0';
    return $config;
}

function tegh_ai_write_behavior_version(array $user,array $company,array $config,string $improvementId,array $metrics): int
{
    $companyId=(string)$company['id'];
    $last=db()->prepare('SELECT COALESCE(MAX(version_number),0) FROM ai_agent_behavior_versions WHERE company_id=?');$last->execute([$companyId]);$version=(int)$last->fetchColumn()+1;
    db()->prepare('UPDATE ai_agent_behavior_versions SET active=0 WHERE company_id=? AND active=1')->execute([$companyId]);
    $vid=new_id('aiver');
    db()->prepare("INSERT INTO ai_agent_behavior_versions (id,company_id,version_number,config_json,source_improvement_id,metrics_json,active,created_by) VALUES (?,?,?,?,?,?,1,?)")
        ->execute([$vid,$companyId,$version,tegh_ai_learning_json($config),$improvementId,tegh_ai_learning_json($metrics),(string)$user['id']]);
    return $version;
}

function tegh_ai_activate_behavior_patch(array $user,array $company,string $improvementId,array $patch,bool $automatic=false): array
{
    $evaluation=tegh_ai_evaluate_behavior_patch($company,$patch);if(!$evaluation['passed'])return ['ok'=>false,'evaluation'=>$evaluation];
    $config=tegh_ai_active_behavior_config($company);$phrase=(string)$patch['phrase'];$target=(string)$patch['targetAction'];
    $syn=[];foreach((array)$config['synonyms'] as $row){if(tegh_normalize_action_text((string)($row['phrase']??''))===tegh_normalize_action_text($phrase))continue;$syn[]=$row;}
    $syn[]=['phrase'=>$phrase,'actionId'=>$target];$config['synonyms']=$syn;$config['intentModel']='2.0';$config['agentPolicy']='2.0';
    $metrics=tegh_ai_metrics($user,$company);$version=tegh_ai_write_behavior_version($user,$company,$config,$improvementId,$metrics);
    db()->prepare("UPDATE ai_agent_improvements SET status='enabled',safety_status='passed',candidate_metrics_json=?,regression_json=?,updated_at=UTC_TIMESTAMP(),approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")
        ->execute([tegh_ai_learning_json($metrics),tegh_ai_learning_json($evaluation),(string)$user['id'],$improvementId,(string)$company['id']]);
    audit_event($user,(string)$company['id'],$automatic?'ai_agent.improvement_auto_enabled':'ai_agent.improvement_enabled','ai_agent_improvement',$improvementId,['behaviorVersion'=>$version,'targetAction'=>$target,'evaluation'=>$evaluation]);
    return ['ok'=>true,'status'=>'enabled','behaviorVersion'=>$version,'evaluation'=>$evaluation,'automatic'=>$automatic];
}

function tegh_ai_auto_promotion_enabled(): bool
{
    if(!schema_table_exists('app_meta'))return false;
    $stmt=db()->prepare("SELECT meta_value FROM app_meta WHERE meta_key='ai_safe_auto_promotion_enabled' LIMIT 1");$stmt->execute();
    return (string)($stmt->fetchColumn()?:'0')==='1';
}

function tegh_ai_set_auto_promotion(bool $enabled): void
{
    db()->prepare("INSERT INTO app_meta (meta_key,meta_value) VALUES ('ai_safe_auto_promotion_enabled',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([$enabled?'1':'0']);
}

function tegh_ai_run_self_review(array $user,array $company,bool $automatic=false): array
{
    tegh_ai_learning_schema_ready();$memoryReview=function_exists('tegh_human_apply_due_memory_decay')?tegh_human_apply_due_memory_decay($user,$company):['reviewed'=>0,'retired'=>0,'decayed'=>0];$clusters=tegh_ai_failure_clusters($company);$created=[];$autoEnabled=[];$companyId=(string)$company['id'];
    foreach($clusters as $cluster){
        $patch=tegh_ai_candidate_patch_for_cluster($cluster);$finger=(string)$cluster['command_fingerprint'];
        // A rejected or rolled-back candidate is user intent too: do not silently recreate it.
        $exists=db()->prepare("SELECT id FROM ai_agent_improvements WHERE company_id=? AND evidence_fingerprint=? LIMIT 1");$exists->execute([$companyId,$finger]);if($exists->fetchColumn())continue;
        $id=new_id('aiimp');$protected=!empty($patch['protected']);$evaluation=$protected?['passed'=>false,'checks'=>0,'failures'=>['Protected change requires normal release review.']]:tegh_ai_evaluate_behavior_patch($company,$patch);
        $status=$protected?'review_required':($evaluation['passed']?'evaluated':'review_required');$safety=$protected?'protected_change':($evaluation['passed']?'passed':'failed');
        $title=$patch['targetAction']?'Improve routing for “'.mb_substr((string)$cluster['command_sample'],0,90).'”':'Review repeated Ask Tegh failure pattern';
        db()->prepare("INSERT INTO ai_agent_improvements (id,company_id,created_by,category,title,problem_summary,current_behavior,proposed_change_json,evidence_count,evidence_fingerprint,baseline_metrics_json,candidate_metrics_json,regression_json,safety_status,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id,$companyId,(string)$user['id'],'intent_routing',$title,'Repeated corrections or failed completion for a sanitized command pattern.','Current behavior generated repeated negative signals.',tegh_ai_learning_json($patch),(int)$cluster['failures'],$finger,tegh_ai_learning_json(tegh_ai_metrics($user,$company)),tegh_ai_learning_json([]),tegh_ai_learning_json($evaluation),$safety,$status]);
        $created[]=$id;
        // On its own, Tegh may promote ONLY a known safe behavior patch after
        // repeated evidence and a passing deterministic safety/golden evaluation.
        if($automatic && tegh_ai_auto_promotion_enabled() && !$protected && $evaluation['passed'] && (int)$cluster['failures']>=5){$enabled=tegh_ai_activate_behavior_patch($user,$company,$id,$patch,true);if(!empty($enabled['ok']))$autoEnabled[]=$id;}
    }
    $runId=new_id('aireview');db()->prepare("INSERT INTO ai_agent_review_runs (id,company_id,run_by,run_mode,event_count,cluster_count,candidate_count,status,summary_json) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$runId,$companyId,(string)$user['id'],$automatic?'automatic':'manual',(int)(tegh_ai_metrics($user,$company)['sampleSize']??0),count($clusters),count($created),'completed',tegh_ai_learning_json(['created'=>$created,'autoEnabled'=>$autoEnabled,'memoryReview'=>$memoryReview])]);
    return ['runId'=>$runId,'clusters'=>$clusters,'createdCandidateIds'=>$created,'autoEnabledCandidateIds'=>$autoEnabled,'autoPromotionEnabled'=>tegh_ai_auto_promotion_enabled(),'memoryReview'=>$memoryReview,'message'=>count($created).' new improvement candidate(s) created; '.count($autoEnabled).' safe behavior candidate(s) automatically enabled after regression gates.'];
}

function tegh_ai_maybe_self_review(array $user,array $company): void
{
    if(platform_role_for_user((string)$user['id'])!=='platform_owner'||!schema_table_exists('ai_agent_review_runs'))return;
    $stmt=db()->prepare("SELECT MAX(created_at) FROM ai_agent_review_runs WHERE company_id=? AND run_mode='automatic'");$stmt->execute([(string)$company['id']]);$last=$stmt->fetchColumn();if($last&&strtotime((string)$last)>time()-86400)return;
    try{tegh_ai_run_self_review($user,$company,true);}catch(Throwable $ignored){}
}

function tegh_ai_learning_rules_endpoint(array $user,array $company): never
{
    tegh_ai_learning_schema_ready();require_company_permission($company,'company.view');$companyId=(string)$company['id'];
    if(request_method()==='GET'){$stmt=db()->prepare("SELECT * FROM ai_agent_learned_rules WHERE company_id=? ORDER BY status='enabled' DESC,confidence_bps DESC,updated_at DESC LIMIT 300");$stmt->execute([$companyId]);$rows=array_map('tegh_ai_rule_public',$stmt->fetchAll());json_response(['rules'=>$rows]);}
    require_method('POST','PATCH','DELETE');require_csrf();require_company_permission($company,'banking.match');$input=request_json();
    if(request_method()==='POST'&&($input['action']??'')==='reset'){
        $stmt=db()->prepare("UPDATE ai_agent_learned_rules SET status='retired',updated_at=UTC_TIMESTAMP() WHERE company_id=? AND status<>'retired'");$stmt->execute([$companyId]);$count=$stmt->rowCount();
        tegh_ai_record_event($user,$company,'learning_reset',['intentCategory'=>'Preference','signal'=>-1,'outcome'=>'retired','module'=>'Learning']);audit_event($user,$companyId,'ai_agent.learned_rules_reset','ai_agent_rule','company',['retiredCount'=>$count]);json_response(['ok'=>true,'retired'=>$count]);
    }
    $id=clean_text($input['ruleId']??'','Rule',64);$stmt=db()->prepare('SELECT * FROM ai_agent_learned_rules WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$id,$companyId]);$row=$stmt->fetch();if(!$row)fail('That learned rule is unavailable.',404,'rule_not_found');
    if(request_method()==='DELETE'){db()->prepare("UPDATE ai_agent_learned_rules SET status='retired',updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$id,$companyId]);audit_event($user,$companyId,'ai_agent.learned_rule_retired','ai_agent_rule',$id,[]);json_response(['ok'=>true,'status'=>'retired']);}
    $action=(string)($input['action']??'');
    if($action==='edit'){
        $pattern=array_key_exists('pattern',$input)?tegh_ai_normalized_pattern((string)$input['pattern']):(string)$row['pattern_value'];if($pattern==='')fail('Enter a rule pattern.');
        $suggestion=json_decode((string)$row['suggestion_json'],true)?:[];$accountQuery=trim((string)($input['accountQuery']??''));
        if($accountQuery!==''){$resolved=tegh_resolve_account($company,$accountQuery,null);if($resolved['status']!=='resolved')fail($resolved['status']==='missing'?'No active non-control account matches that category.':'More than one account matches that category.',409,'account_not_resolved',['candidates'=>$resolved['candidates']??[]]);$a=$resolved['account'];$suggestion=['accountId'=>(string)$a['id'],'accountCode'=>(string)$a['code'],'accountName'=>(string)$a['name']];}
        db()->prepare('UPDATE ai_agent_learned_rules SET pattern_value=?,suggestion_json=?,source=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$pattern,tegh_ai_learning_json($suggestion),'explicit',$id,$companyId]);
        audit_event($user,$companyId,'ai_agent.learned_rule_edited','ai_agent_rule',$id,['patternHash'=>hash('sha256',$pattern),'accountId'=>$suggestion['accountId']??null]);$stmt=db()->prepare('SELECT * FROM ai_agent_learned_rules WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$id,$companyId]);json_response(['ok'=>true,'rule'=>tegh_ai_rule_public($stmt->fetch()?:$row)]);
    }
    if(!in_array($action,['enable','disable'],true))fail('Choose edit, enable or disable.');$status=$action==='enable'?'enabled':'disabled';db()->prepare('UPDATE ai_agent_learned_rules SET status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?')->execute([$status,$id,$companyId]);audit_event($user,$companyId,'ai_agent.learned_rule_'.$status,'ai_agent_rule',$id,[]);json_response(['ok'=>true,'status'=>$status]);
}

function tegh_ai_feedback_endpoint(array $user,array $company): never
{
    tegh_ai_learning_schema_ready();require_method('POST');require_csrf();require_company_permission($company,'banking.match');$input=request_json();$positive=!empty($input['positive']);$ruleId=optional_text($input['ruleId']??null,64);$actionId=optional_text($input['actionId']??null,120);$question=optional_text($input['question']??null,500)??'';
    if($ruleId){$stmt=db()->prepare('SELECT * FROM ai_agent_learned_rules WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$ruleId,(string)$company['id']]);$row=$stmt->fetch();if(!$row)fail('That learned suggestion is no longer available.',404,'rule_not_found');$suggestion=json_decode((string)$row['suggestion_json'],true)?:[];tegh_ai_upsert_rule($user,$company,['scopeType'=>(string)$row['scope_type'],'scopeKey'=>(string)$row['scope_key'],'patternType'=>(string)$row['pattern_type'],'patternValue'=>(string)$row['pattern_value'],'suggestionType'=>(string)$row['suggestion_type'],'suggestion'=>$suggestion,'source'=>(string)$row['source']],$positive?1:0,$positive?0:1);}
    tegh_ai_record_event($user,$company,$positive?'suggestion_accepted':'suggestion_rejected',['question'=>$question,'actionId'=>$actionId,'signal'=>$positive?1:-1,'outcome'=>$positive?'accepted':'corrected','confidenceBps'=>$positive?9000:3000]);json_response(['ok'=>true]);
}

function tegh_ai_improvements_endpoint(array $user,array $company): never
{
    tegh_ai_learning_schema_ready();if(platform_role_for_user((string)$user['id'])!=='platform_owner')fail('Only the Platform Owner can manage the Tegh AI Improvement Lab.',403,'platform_owner_required');$companyId=(string)$company['id'];
    if(request_method()==='GET'){$stmt=db()->prepare("SELECT * FROM ai_agent_improvements WHERE company_id=? ORDER BY created_at DESC LIMIT 200");$stmt->execute([$companyId]);$rows=[];foreach($stmt->fetchAll() as $r){$rows[]=['id'=>$r['id'],'category'=>$r['category'],'title'=>$r['title'],'problem'=>$r['problem_summary'],'evidenceCount'=>(int)$r['evidence_count'],'proposedChange'=>json_decode((string)$r['proposed_change_json'],true)?:[],'baselineMetrics'=>json_decode((string)$r['baseline_metrics_json'],true)?:[],'candidateMetrics'=>json_decode((string)$r['candidate_metrics_json'],true)?:[],'regression'=>json_decode((string)$r['regression_json'],true)?:[],'safetyStatus'=>$r['safety_status'],'status'=>$r['status'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at']];}json_response(['improvements'=>$rows,'metrics'=>tegh_ai_metrics($user,$company),'securityMetrics'=>tegh_ai_security_metrics($company),'clusters'=>tegh_ai_failure_clusters($company),'activeBehavior'=>tegh_ai_active_behavior_config($company),'autoPromotionEnabled'=>tegh_ai_auto_promotion_enabled()]);}
    require_method('POST');require_csrf();$input=request_json();$action=(string)($input['action']??'');if($action==='set_auto_promotion'){$enabled=($input['enabled']??false)===true;tegh_ai_set_auto_promotion($enabled);audit_event($user,$companyId,'ai_agent.auto_promotion_setting','ai_agent_behavior','global',['enabled'=>$enabled]);json_response(['ok'=>true,'autoPromotionEnabled'=>$enabled]);}if($action==='self_review')json_response(tegh_ai_run_self_review($user,$company,false),201);$id=clean_text($input['improvementId']??'','Improvement',64);$stmt=db()->prepare('SELECT * FROM ai_agent_improvements WHERE id=? AND company_id=? LIMIT 1');$stmt->execute([$id,$companyId]);$row=$stmt->fetch();if(!$row)fail('That improvement candidate is unavailable.',404,'improvement_not_found');$patch=json_decode((string)$row['proposed_change_json'],true)?:[];
    if($action==='reject'){db()->prepare("UPDATE ai_agent_improvements SET status='rejected',updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$id,$companyId]);audit_event($user,$companyId,'ai_agent.improvement_rejected','ai_agent_improvement',$id,[]);json_response(['ok'=>true,'status'=>'rejected']);}
    if($action==='rollback'){
        if(($patch['type']??'')!=='phrase_synonym')fail('This improvement does not have an automatically reversible behavior patch.',409,'rollback_unavailable');
        $config=tegh_ai_active_behavior_config($company);$phrase=tegh_normalize_action_text((string)($patch['phrase']??''));$target=(string)($patch['targetAction']??'');$before=count((array)$config['synonyms']);
        $config['synonyms']=array_values(array_filter((array)$config['synonyms'],static function(array $r)use($phrase,$target):bool{return !(tegh_normalize_action_text((string)($r['phrase']??''))===$phrase && (string)($r['actionId']??'')===$target);}));
        if(count($config['synonyms'])===$before)fail('That behavior patch is not active, so there is nothing to roll back.',409,'rollback_unavailable');
        $version=tegh_ai_write_behavior_version($user,$company,$config,$id,tegh_ai_metrics($user,$company));db()->prepare("UPDATE ai_agent_improvements SET status='rolled_back',updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([$id,$companyId]);audit_event($user,$companyId,'ai_agent.improvement_rolled_back','ai_agent_improvement',$id,['behaviorVersion'=>$version]);json_response(['ok'=>true,'status'=>'rolled_back','behaviorVersion'=>$version]);
    }
    if($action!=='approve')fail('Choose approve, reject, rollback or self_review.');
    if(!empty($patch['protected'])||($row['safety_status']??'')==='protected_change')fail('This candidate touches behavior that requires a normal code/release review and cannot be auto-promoted.',409,'protected_change_review_required');
    $enabled=tegh_ai_activate_behavior_patch($user,$company,$id,$patch,false);if(empty($enabled['ok'])){db()->prepare("UPDATE ai_agent_improvements SET status='review_required',safety_status='failed',regression_json=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND company_id=?")->execute([tegh_ai_learning_json($enabled['evaluation']??[]),$id,$companyId]);fail('The candidate failed the current safety/regression gate and was not enabled.',409,'candidate_regression_failed',['evaluation'=>$enabled['evaluation']??[]]);}
    json_response($enabled);
}

function tegh_ai_behavior_synonym(array $company,string $question): ?string
{
    $config=tegh_ai_active_behavior_config($company);$q=tegh_normalize_action_text($question);foreach((array)($config['synonyms']??[]) as $row){$phrase=tegh_normalize_action_text((string)($row['phrase']??''));if($phrase!==''&&str_contains($q,$phrase))return (string)($row['actionId']??'');}return null;
}


function tegh_ai_learning_signal_endpoint(array $user,array $company): never
{
    tegh_ai_learning_schema_ready();require_method('POST');require_csrf();$input=request_json();$type=(string)($input['type']??'');$allowed=['quick_action_used','report_opened','navigation_used','workflow_completed'];if(!in_array($type,$allowed,true))fail('That learning signal is not accepted.',400,'learning_signal_invalid');
    $actionId=optional_text($input['actionId']??null,120);$module=optional_text($input['module']??null,80);
    if($actionId){$action=tegh_action_get($actionId);tegh_action_assert_permission($company,$action);if(!empty($action['financial_commit'])||!empty($action['destructive']))fail('Protected actions are not eligible for automatic preference-learning signals.',409,'protected_learning_signal');$module=(string)$action['module'];}
    tegh_ai_record_event($user,$company,$type,['module'=>$module,'actionId'=>$actionId,'intentCategory'=>'Preference','signal'=>1,'outcome'=>'completed','confidenceBps'=>10000,'source'=>'explicit_usage']);json_response(['ok'=>true]);
}

function tegh_ai_action_usage_counts(array $user,array $company,int $days=90): array
{
    if(!schema_table_exists('ai_agent_learning_events'))return [];$days=max(7,min(365,$days));$stmt=db()->prepare("SELECT action_id,COUNT(*) n FROM ai_agent_learning_events WHERE company_id=? AND user_id=? AND event_type IN ('quick_action_used','report_opened','navigation_used') AND action_id IS NOT NULL AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY) GROUP BY action_id");$stmt->execute([(string)$company['id'],(string)$user['id']]);$out=[];foreach($stmt->fetchAll() as $r)$out[(string)$r['action_id']]=(int)$r['n'];return $out;
}

function tegh_ai_repeated_routines(array $user,array $company,int $days=60): array
{
    if(!schema_table_exists('ai_agent_learning_events'))return [];$days=max(14,min(180,$days));
    $stmt=db()->prepare("SELECT DATE(created_at) day_key,action_id,event_type,created_at FROM ai_agent_learning_events WHERE company_id=? AND user_id=? AND event_type IN ('quick_action_used','report_opened','navigation_used') AND action_id IS NOT NULL AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$days} DAY) ORDER BY created_at ASC LIMIT 800");
    $stmt->execute([(string)$company['id'],(string)$user['id']]);$byDay=[];
    foreach($stmt->fetchAll() as $row){$id=(string)$row['action_id'];try{$action=tegh_action_get($id);tegh_action_assert_permission($company,$action);}catch(Throwable $ignored){continue;}if(!empty($action['financial_commit'])||!empty($action['destructive'])||!in_array((string)$action['action_type'],['navigation','read','prepare'],true))continue;$day=(string)$row['day_key'];$last=$byDay[$day][count($byDay[$day]??[])-1]??null;if($last!==$id)$byDay[$day][]=$id;}
    $counts=[];foreach($byDay as $day=>$sequence){if(count($sequence)<2)continue;$sequence=array_slice($sequence,0,6);$key=implode('>', $sequence);if(!isset($counts[$key]))$counts[$key]=['actionIds'=>$sequence,'count'=>0,'lastDay'=>$day];$counts[$key]['count']++;$counts[$key]['lastDay']=$day;}
    uasort($counts,static fn(array $a,array $b):int=>$b['count']<=>$a['count']);$out=[];
    foreach($counts as $row){if((int)$row['count']<3)continue;$steps=[];foreach($row['actionIds'] as $id){try{$a=tegh_action_get((string)$id);$steps[]=['actionId'=>$id,'name'=>(string)$a['name'],'module'=>(string)$a['module']];}catch(Throwable $ignored){}}if(count($steps)<2)continue;$out[]=['occurrences'=>(int)$row['count'],'steps'=>$steps,'lastObserved'=>$row['lastDay'],'suggestion'=>'Prepare your usual '.implode(' → ',array_column($steps,'name')).' routine'];if(count($out)>=3)break;}
    return $out;
}

function tegh_ai_learning_dashboard_endpoint(array $user,array $company): never
{
    tegh_ai_learning_schema_ready();require_method('GET');require_company_permission($company,'company.view');$metrics=tegh_ai_metrics($user,$company);$stmt=db()->prepare("SELECT event_type,COUNT(*) count FROM ai_agent_learning_events WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) GROUP BY event_type ORDER BY count DESC LIMIT 12");$stmt->execute([(string)$company['id']]);json_response(['metrics'=>$metrics,'signals'=>$stmt->fetchAll(),'routines'=>tegh_ai_repeated_routines($user,$company),'agentVersion'=>['application'=>SR_ACCOUNTAX_VERSION,'build'=>SR_ACCOUNTAX_BUILD,'policy'=>'2.0','intentModel'=>'2.0']]);
}
