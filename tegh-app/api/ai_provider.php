<?php
declare(strict_types=1);
require_once __DIR__ . '/release_v5980.php';

/**
 * Tegh 4.7.0 Responses API client.
 *
 * This is the only module allowed to open an outbound model connection. It
 * deliberately returns data/errors instead of emitting HTTP responses so each
 * caller can continue down its deterministic degradation ladder.
 */

const TEGH_AI_RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

function tegh_ai_provider_shannon_entropy(string $value): float
{
    $length=strlen($value);if($length===0)return 0.0;$counts=count_chars($value,1);$entropy=0.0;foreach($counts as $count){$p=$count/$length;$entropy-=$p*log($p,2);}return $entropy;
}

function tegh_ai_provider_retry_delay_us(int $attempt,array $headers=[]): int
{
    $retryAfter=trim((string)($headers['retry-after']??''));
    if($retryAfter!==''){$seconds=ctype_digit($retryAfter)?(int)$retryAfter:max(0,(int)ceil((strtotime($retryAfter)?:time())-time()));return max(0,min(30000000,$seconds*1000000));}
    $baseMs=250*(2**max(1,$attempt));$jitter=random_int(750,1250)/1000;return (int)min(30000000,round($baseMs*$jitter*1000));
}

function tegh_ai_provider_output_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }
    foreach ((array)($response['output'] ?? []) as $item) {
        if (!is_array($item) || (string)($item['type'] ?? '') !== 'message') continue;
        foreach ((array)($item['content'] ?? []) as $content) {
            if (is_array($content) && (string)($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                return $content['text'];
            }
        }
    }
    throw new RuntimeException('The provider response did not contain structured output.');
}

if (!function_exists('openai_output_text')) {
    function openai_output_text(array $response): string
    {
        return tegh_ai_provider_output_text($response);
    }
}

function tegh_ai_provider_api_key(): string
{
    if (!tegh_connected_release_enabled()) return '';
    $key = trim((string)(config('openai.api_key') ?? ''));
    if (strlen($key)<40 || tegh_ai_provider_shannon_entropy($key)<3.5 || preg_match('/(?:REPLACE_WITH|YOUR[_-]?(?:OPENAI|API)[_-]?KEY)/i', $key)) {
        return '';
    }
    return $key;
}

function tegh_ai_provider_configured(): bool
{
    if (!tegh_connected_release_enabled()) return false;
    return tegh_ai_provider_api_key() !== '' && function_exists('curl_init');
}

function tegh_ai_provider_company_opted_in(array $company): bool
{
    if (!tegh_connected_release_enabled()) return false;
    if(!function_exists('schema_table_exists')||!schema_table_exists('native_agent_policies'))return false;
    try{$stmt=db()->prepare('SELECT connected_enabled FROM native_agent_policies WHERE company_id=? LIMIT 1');$stmt->execute([(string)$company['id']]);return (bool)$stmt->fetchColumn();}
    catch(Throwable $error){error_log('Tegh Connected Intelligence policy lookup failed class='.$error::class);return false;}
}

function tegh_ai_provider_error_class(int $status, int $curlErrno, string $curlError): string
{
    if ($status === 401 || $status === 403) return 'authentication_failed';
    if ($status === 429) return 'rate_limited';
    if ($status >= 500) return 'provider_error';
    if ($status >= 400) return 'http_'.$status;
    if ($curlErrno === 28 || str_contains(mb_strtolower($curlError), 'timed out')) return 'timeout';
    return 'network_error';
}

function tegh_ai_provider_limits(array $company): array
{
    if (!tegh_connected_release_enabled()) return ['allowed'=>false,'storageReady'=>false,'requestLimit'=>0,'tokenLimit'=>0,'usedRequests'=>0,'usedTokens'=>0,'error'=>'connected_unavailable_in_release'];
    $requestLimit=max(10,min(2000,(int)(config('openai.company_hourly_request_limit') ?? 120)));
    $tokenLimit=max(10000,min(10000000,(int)(config('openai.company_hourly_token_limit') ?? 250000)));
    $usedRequests=0;$usedTokens=0;$storageReady=false;
    if (function_exists('schema_table_exists') && schema_table_exists('ai_runs')) {
        try {
            $q=db()->prepare('SELECT COUNT(*),COALESCE(SUM(input_count+output_count),0) FROM ai_runs WHERE company_id=? AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)');
            $q->execute([(string)$company['id']]);$row=$q->fetch(PDO::FETCH_NUM)?:[0,0];
            $usedRequests=(int)$row[0];$usedTokens=(int)$row[1];$storageReady=true;
        } catch (Throwable $error) {
            error_log('Tegh AI budget lookup skipped request='.(function_exists('request_id')?request_id():'unknown').' '.$error::class);
        }
    }
    return [
        // If spend cannot be measured, connected interpretation fails closed
        // and callers continue down the native/template/clarification ladder.
        'allowed'=>$storageReady && $usedRequests<$requestLimit && $usedTokens<$tokenLimit,
        'requestLimit'=>$requestLimit,'tokenLimit'=>$tokenLimit,
        'usedRequests'=>$usedRequests,'usedTokens'=>$usedTokens,'storageReady'=>$storageReady,
    ];
}

/**
 * Explain every prerequisite for a real provider request without making one.
 * This intentionally returns booleans and safe codes only; the API key and
 * database identifiers never leave the server.
 */
function tegh_ai_provider_readiness(array $company): array
{
    if (!tegh_connected_release_enabled()) return ['readyForRequest'=>false,'provider'=>null,'apiKeyConfigured'=>false,'apiKeyExposed'=>false,'companyOptedIn'=>false,'budgetAllowed'=>false,'blockers'=>['connected_unavailable_in_release'],'nextStep'=>'Use native Tegh assistance.'];
    $apiKeyConfigured = tegh_ai_provider_api_key() !== '';
    $curlAvailable = function_exists('curl_init');
    $policyStorageReady = function_exists('schema_table_exists') && schema_table_exists('native_agent_policies');
    $budgetStorageReady = function_exists('schema_table_exists') && schema_table_exists('ai_runs');
    $companyOptedIn = $policyStorageReady && tegh_ai_provider_company_opted_in($company);
    $budget = $budgetStorageReady ? tegh_ai_provider_limits($company) : [
        'allowed' => false,
        'storageReady' => false,
        'requestLimit' => 0,
        'tokenLimit' => 0,
        'usedRequests' => 0,
        'usedTokens' => 0,
    ];
    $blockers = [];
    if (!$apiKeyConfigured) $blockers[] = 'openai_api_key_missing';
    if (!$curlAvailable) $blockers[] = 'php_curl_missing';
    if (!$policyStorageReady) $blockers[] = 'native_agent_policy_storage_missing';
    elseif (!$companyOptedIn) $blockers[] = 'connected_intelligence_disabled';
    if (!$budgetStorageReady) $blockers[] = 'ai_run_budget_storage_missing';
    elseif (empty($budget['allowed'])) $blockers[] = 'company_ai_budget_exceeded';
    $ready = $blockers === [];
    $nextStep = $ready
        ? 'Run the read-only Test Connection diagnostic.'
        : match ($blockers[0]) {
            'openai_api_key_missing' => 'Set openai.api_key in the private configuration or SR_ACCOUNTAX_OPENAI_API_KEY on the host.',
            'php_curl_missing' => 'Enable the PHP cURL extension for this application runtime.',
            'native_agent_policy_storage_missing', 'ai_run_budget_storage_missing' => 'Complete the protected Schema 41 migration and rerun startup preflight.',
            'connected_intelligence_disabled' => 'A Company Owner or Admin must enable Connected Intelligence in Native Agent Settings.',
            'company_ai_budget_exceeded' => 'Wait for the hourly window or raise the company AI limits in private configuration.',
            default => 'Review the protected incident log using the returned request reference.',
        };
    return [
        'readyForRequest' => $ready,
        'provider' => 'OpenAI',
        'endpointHost' => 'api.openai.com',
        'apiKeyConfigured' => $apiKeyConfigured,
        'apiKeyExposed' => false,
        'curlAvailable' => $curlAvailable,
        'policyStorageReady' => $policyStorageReady,
        'companyOptedIn' => $companyOptedIn,
        'budgetStorageReady' => $budgetStorageReady,
        'budgetAllowed' => (bool)($budget['allowed'] ?? false),
        'blockers' => $blockers,
        'nextStep' => $nextStep,
    ];
}

/**
 * Conservatively estimate the provider input before the call. The estimate is
 * intentionally higher than a simple four-characters-per-token approximation
 * so a large registry/schema request cannot slip past the company token cap.
 */
function tegh_ai_provider_estimated_input_tokens(string $encodedBody): int
{
    return max(1,(int)ceil(strlen($encodedBody)/3));
}

/**
 * Atomically reserve one logical provider request and its maximum token budget.
 * A failed/crashed request keeps the conservative reservation for the current
 * hour; a completed request replaces it with provider-reported usage.
 */
function tegh_ai_provider_reserve(array $user,array $company,string $model,string $purpose,string $requestId,int $inputTokens,int $outputTokens): array
{
    if (!tegh_connected_release_enabled()) return ['ok'=>false,'error'=>'connected_unavailable_in_release'];
    if (!function_exists('schema_table_exists') || !schema_table_exists('ai_runs')) {
        return ['ok'=>false,'error'=>'company_budget_unavailable','budget'=>tegh_ai_provider_limits($company)];
    }
    $lockName='tegh_ai_budget_'.substr(hash('sha256',(string)$company['id']),0,40);$locked=false;
    try {
        $lock=db()->prepare('SELECT GET_LOCK(?,2)');$lock->execute([$lockName]);$locked=(int)$lock->fetchColumn()===1;
        if (!$locked) return ['ok'=>false,'error'=>'company_budget_unavailable','budget'=>tegh_ai_provider_limits($company)];
        $budget=tegh_ai_provider_limits($company);$reservedTokens=max(1,$inputTokens)+max(1,$outputTokens);
        if (!$budget['storageReady']) return ['ok'=>false,'error'=>'company_budget_unavailable','budget'=>$budget];
        if ($budget['usedRequests'] >= $budget['requestLimit'] || $budget['usedTokens']+$reservedTokens > $budget['tokenLimit']) {
            $budget['allowed']=false;$budget['requestedTokens']=$reservedTokens;
            return ['ok'=>false,'error'=>'company_budget_exceeded','budget'=>$budget];
        }
        db()->prepare("INSERT INTO ai_runs (id,company_id,user_id,model,purpose,input_count,output_count,status,error_code) VALUES (?,?,?,?,?,?,?,'failed','budget_reserved')")
            ->execute([$requestId,(string)$company['id'],(string)$user['id'],$model,$purpose,max(1,$inputTokens),max(1,$outputTokens)]);
        $budget['allowed']=true;$budget['reservedTokens']=$reservedTokens;$budget['usedRequestsAfterReservation']=$budget['usedRequests']+1;$budget['usedTokensAfterReservation']=$budget['usedTokens']+$reservedTokens;
        return ['ok'=>true,'budget'=>$budget];
    } catch (Throwable $error) {
        error_log('Tegh AI budget reservation failed request='.$requestId.' '.$error::class);
        return ['ok'=>false,'error'=>'company_budget_unavailable','budget'=>tegh_ai_provider_limits($company)];
    } finally {
        if ($locked) { try {$release=db()->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);} catch (Throwable) {} }
    }
}

function tegh_ai_provider_record(array $user,array $company,string $model,string $purpose,array $usage,string $status,?string $errorCode,string $requestId): void
{
    if (!tegh_connected_release_enabled()) return;
    if (!function_exists('schema_table_exists') || !schema_table_exists('ai_runs')) return;
    $input=max(0,(int)($usage['input_tokens']??$usage['input_count']??0));
    $output=max(0,(int)($usage['output_tokens']??$usage['output_count']??0));
    try {
        db()->prepare('INSERT INTO ai_runs (id,company_id,user_id,model,purpose,input_count,output_count,status,error_code) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE model=VALUES(model),purpose=VALUES(purpose),input_count=IF(VALUES(input_count)>0,VALUES(input_count),input_count),output_count=IF(VALUES(output_count)>0,VALUES(output_count),output_count),status=VALUES(status),error_code=VALUES(error_code)')
            ->execute([$requestId,(string)$company['id'],(string)$user['id'],mb_substr($model,0,100),mb_substr($purpose,0,80),$input,$output,$status,$errorCode]);
    } catch (Throwable $error) {
        error_log('Tegh AI provider telemetry skipped request='.$requestId.' '.$error::class);
    }
}

function tegh_ai_provider_discard(array $user,array $company,string $purpose,string $requestId,string $reason,?Throwable $error=null): void
{
    $detail=mb_substr($reason.($error?' · '.$error->getMessage():''),0,300);
    error_log('Tegh AI structured output discarded request='.$requestId.' purpose='.$purpose.' reason='.$detail);
    try {
        if (function_exists('tegh_ai_record_event')) tegh_ai_record_event($user,$company,'model_invalid_output',[
            'module'=>'Agent','intentCategory'=>'Interpretation','signal'=>-1,'outcome'=>'discarded',
            'confidenceBps'=>0,'providerRequestIdHash'=>hash('sha256',$requestId),'reason'=>$detail,
        ]);
    } catch (Throwable) {}
}

/**
 * @return array{ok:bool,error?:string,status?:int,response?:array,text?:string,model:string,requestId:string,latencyMs:int,attempts:int,usage?:array,budget?:array}
 */
function tegh_ai_provider_attempt_count(): int
{
    return (int)($GLOBALS['tegh_ai_provider_attempt_count']??0);
}

function tegh_ai_provider_call_count(): int
{
    return (int)($GLOBALS['tegh_ai_provider_call_count']??0);
}

function tegh_ai_provider_attempt_count_reset(): void
{
    $GLOBALS['tegh_ai_provider_attempt_count']=0;
    $GLOBALS['tegh_ai_provider_call_count']=0;
}

/** A Platform Owner diagnostic: one-token input, strict output, no accounting tool. */
function tegh_ai_provider_smoke_test(array $user,array $company): array
{
    if (!tegh_connected_release_enabled()) return ['ok'=>false,'error'=>'connected_unavailable_in_release','attempts'=>0];
    $model=trim((string)(config('openai.interpretation_model')??config('openai.model')??'gpt-5.6-luna'))?:'gpt-5.6-luna';
    $schema=['type'=>'object','additionalProperties'=>false,'properties'=>['ok'=>['type'=>'boolean']],'required'=>['ok']];
    $body=['model'=>$model,'store'=>false,'max_output_tokens'=>64,'reasoning'=>['effort'=>'minimal'],'input'=>[['role'=>'developer','content'=>[['type'=>'input_text','text'=>'Return {"ok":true}. No tools.']]],['role'=>'user','content'=>[['type'=>'input_text','text'=>'ping']]]],'text'=>['format'=>['type'=>'json_schema','name'=>'tegh_provider_startup_smoke_v5900','strict'=>true,'schema'=>$schema]]];
    $result=tegh_ai_provider_request($user,$company,$body,['purpose'=>'startup_smoke','maxAttempts'=>1,'defaultTimeout'=>20]);
    if(empty($result['ok']))return ['ok'=>false,'error'=>(string)($result['error']??'provider_unavailable'),'attempts'=>(int)($result['attempts']??0),'latencyMs'=>(int)($result['latencyMs']??0),'model'=>$model];
    try{$decoded=json_decode((string)$result['text'],true,16,JSON_THROW_ON_ERROR);$ok=is_array($decoded)&&($decoded['ok']??null)===true;}catch(Throwable){$ok=false;}
    return ['ok'=>$ok,'error'=>$ok?'':'startup_smoke_invalid_output','attempts'=>(int)$result['attempts'],'latencyMs'=>(int)$result['latencyMs'],'model'=>$model];
}

function tegh_ai_provider_request(array $user,array $company,array $body,array $options=[]): array
{
    if (!tegh_connected_release_enabled()) return ['ok'=>false,'error'=>'connected_unavailable_in_release','model'=>null,'requestId'=>null,'latencyMs'=>0,'attempts'=>0];
    $GLOBALS['tegh_ai_provider_call_count']=tegh_ai_provider_call_count()+1;
    $purpose=mb_substr(trim((string)($options['purpose']??'ai_request')),0,80)?:'ai_request';
    $model=mb_substr(trim((string)($body['model']??config('openai.model')??'')),0,100);
    $requestId=new_id('request');$started=microtime(true);
    // Sole outbound boundary: company-admin opt-in is checked before key,
    // budget, cURL initialization or the network-spy counter. A stale or
    // incomplete caller therefore still produces zero provider attempts.
    if(!tegh_ai_provider_company_opted_in($company))return ['ok'=>false,'error'=>'connected_intelligence_disabled','model'=>$model,'requestId'=>$requestId,'latencyMs'=>0,'attempts'=>0];
    $apiKey=tegh_ai_provider_api_key();
    if ($apiKey==='') return ['ok'=>false,'error'=>'not_configured','model'=>$model,'requestId'=>$requestId,'latencyMs'=>0,'attempts'=>0];
    if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'client_unavailable','model'=>$model,'requestId'=>$requestId,'latencyMs'=>0,'attempts'=>0];

    // Provider persistence is never enabled by an individual caller. Every
    // request also receives a real output ceiling before it is encoded and
    // reserved; a caller cannot accidentally leave remote spend unbounded.
    $body['store']=false;
    $defaultOutput=max(128,min(16000,(int)($options['defaultMaxOutputTokens']??1200)));
    $requestedOutput=(int)($body['max_output_tokens']??$defaultOutput);
    $body['max_output_tokens']=max(64,min(16000,$requestedOutput));
    try {$encodedBody=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    catch (Throwable $error) {tegh_ai_provider_discard($user,$company,$purpose,$requestId,'invalid_provider_request',$error);return ['ok'=>false,'error'=>'invalid_provider_request','model'=>$model,'requestId'=>$requestId,'latencyMs'=>0,'attempts'=>0];}
    $connectTimeout=max(3,min(15,(int)($options['connectTimeout']??8)));
    $configuredTimeout=(int)(config((string)($options['timeoutConfigKey']??'openai.timeout_seconds'))??($options['defaultTimeout']??45));
    $timeout=max(12,min(90,$configuredTimeout));
    $idempotent=(bool)($options['idempotent']??true);
    $maxAttempts=$idempotent?max(1,min(4,(int)($options['maxAttempts']??4))):1;
    $estimatedInput=tegh_ai_provider_estimated_input_tokens($encodedBody);$maximumOutput=(int)$body['max_output_tokens'];
    $reservation=tegh_ai_provider_reserve($user,$company,$model,$purpose,$requestId,$estimatedInput*$maxAttempts,$maximumOutput*$maxAttempts);
    $budget=(array)($reservation['budget']??[]);
    if (empty($reservation['ok'])) return ['ok'=>false,'error'=>(string)($reservation['error']??'company_budget_unavailable'),'model'=>$model,'requestId'=>$requestId,'latencyMs'=>0,'attempts'=>0,'budget'=>$budget];
    $attempt=0;$last=['error'=>'provider_unavailable','status'=>0,'curlErrno'=>0,'curlError'=>''];

    while ($attempt<$maxAttempts) {
        $attempt++;
        $GLOBALS['tegh_ai_provider_attempt_count']=tegh_ai_provider_attempt_count()+1;
        $curl=curl_init(TEGH_AI_RESPONSES_ENDPOINT);
        if ($curl===false) {
            $last=['error'=>'client_unavailable','status'=>0,'curlErrno'=>0,'curlError'=>''];
            break;
        }
        $responseHeaders=[];curl_setopt_array($curl,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>$connectTimeout,CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Bearer '.$apiKey,'Content-Type: application/json',
                'X-Client-Request-Id: '.$requestId,
            ],
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$responseHeaders):int{$length=strlen($line);$position=strpos($line,':');if($position!==false){$name=mb_strtolower(trim(substr($line,0,$position)));if($name!=='')$responseHeaders[$name]=trim(substr($line,$position+1));}return $length;},
            CURLOPT_POSTFIELDS=>$encodedBody,
        ]);
        $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        $curlErrno=(int)curl_errno($curl);$curlError=(string)curl_error($curl);curl_close($curl);
        if (is_string($raw) && $raw!=='' && $status>=200 && $status<300) {
            try {
                $decoded=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) throw new RuntimeException('Provider response was not an object.');
                if ((string)($decoded['status']??'completed')==='incomplete') {
                    $reason=(string)($decoded['incomplete_details']['reason']??'unknown');
                    $usage=(array)($decoded['usage']??[]);if($attempt>1){$usage['input_tokens']=(int)($usage['input_tokens']??0)+$estimatedInput*($attempt-1);$usage['output_tokens']=(int)($usage['output_tokens']??0)+$maximumOutput*($attempt-1);}
                    tegh_ai_provider_record($user,$company,$model,$purpose,$usage,'failed','incomplete_'.$reason,$requestId);
                    return ['ok'=>false,'error'=>'incomplete_output','status'=>$status,'model'=>$model,'requestId'=>$requestId,'latencyMs'=>(int)round((microtime(true)-$started)*1000),'attempts'=>$attempt,'usage'=>$usage,'budget'=>$budget];
                }
                $text=tegh_ai_provider_output_text($decoded);
                $usage=(array)($decoded['usage']??[]);if($attempt>1){$usage['input_tokens']=(int)($usage['input_tokens']??0)+$estimatedInput*($attempt-1);$usage['output_tokens']=(int)($usage['output_tokens']??0)+$maximumOutput*($attempt-1);}
                tegh_ai_provider_record($user,$company,$model,$purpose,$usage,'completed',null,$requestId);
                return ['ok'=>true,'status'=>$status,'response'=>$decoded,'text'=>$text,'model'=>$model,'requestId'=>$requestId,'latencyMs'=>(int)round((microtime(true)-$started)*1000),'attempts'=>$attempt,'usage'=>$usage,'budget'=>$budget];
            } catch (Throwable $error) {
                tegh_ai_provider_record($user,$company,$model,$purpose,[],'failed','invalid_provider_json',$requestId);
                tegh_ai_provider_discard($user,$company,$purpose,$requestId,'invalid_provider_json',$error);
                return ['ok'=>false,'error'=>'invalid_provider_json','status'=>$status,'model'=>$model,'requestId'=>$requestId,'latencyMs'=>(int)round((microtime(true)-$started)*1000),'attempts'=>$attempt,'budget'=>$budget];
            }
        }
        $class=tegh_ai_provider_error_class($status,$curlErrno,$curlError);
        $last=['error'=>$class,'status'=>$status,'curlErrno'=>$curlErrno,'curlError'=>$curlError,'headers'=>$responseHeaders];
        $retryable=$idempotent&&in_array($class,['timeout','network_error','rate_limited','provider_error'],true);
        if (!$retryable || $attempt>=$maxAttempts) break;
        usleep(tegh_ai_provider_retry_delay_us($attempt,$responseHeaders));
    }

    $latency=(int)round((microtime(true)-$started)*1000);
    tegh_ai_provider_record($user,$company,$model,$purpose,[],'failed',(string)$last['error'],$requestId);
    error_log('Tegh AI provider unavailable request='.$requestId.' purpose='.$purpose.' status='.(int)$last['status'].' error='.(string)$last['error']);
    return ['ok'=>false,'error'=>(string)$last['error'],'status'=>(int)$last['status'],'model'=>$model,'requestId'=>$requestId,'latencyMs'=>$latency,'attempts'=>$attempt,'budget'=>$budget];
}
