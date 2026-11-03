<?php
declare(strict_types=1);

/** Platform-wide, immutable payroll releases. No company data is read by update checks. */
const PAYROLL_RATE_TARGET = 'payroll_rate_catalogue';
const PAYROLL_RATE_INDEX = 'https://www.canada.ca/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas.html';
const PAYROLL_RATE_CHECK_COOLDOWN_SECONDS = 60;

function payroll_valid_rate_date(string $date): void
{
    if (!preg_match('/\A(20\d{2})-(\d{2})-(\d{2})\z/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        throw new InvalidArgumentException('A valid payroll pay date is required.');
    }
}

function payroll_canonical_value(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = payroll_canonical_value($item);
    return $value;
}

function payroll_rate_digest(array $rates): string
{
    unset($rates['digest']);
    return hash('sha256', json_encode(payroll_canonical_value($rates), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function payroll_rate_numeric(mixed $value, float $minimum = 0, float $maximum = 10000000): void
{
    if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value) || $value < $minimum || $value > $maximum) {
        throw new InvalidArgumentException('The payroll release contains a missing or invalid numeric value.');
    }
}

function payroll_validate_rate_release(array $rates): array
{
    foreach (['id','version','formulaVersion','effectiveFrom','effectiveTo','label','digest'] as $key) {
        if (!is_string($rates[$key] ?? null) || $rates[$key] === '') throw new InvalidArgumentException('The payroll release is incomplete: '.$key.'.');
    }
    if (!preg_match('/\A[a-zA-Z0-9._-]{6,100}\z/', $rates['id'])) throw new InvalidArgumentException('Invalid payroll release identifier.');
    if ($rates['formulaVersion'] !== 'tegh-t4127-option1-estimate-v1') throw new InvalidArgumentException('This payroll formula needs a software update.');
    payroll_valid_rate_date($rates['effectiveFrom']); payroll_valid_rate_date($rates['effectiveTo']);
    if ($rates['effectiveFrom'] > $rates['effectiveTo'] || !is_int($rates['year'] ?? null)
        || (int)substr($rates['effectiveFrom'],0,4) !== $rates['year'] || (int)substr($rates['effectiveTo'],0,4) !== $rates['year']) {
        throw new InvalidArgumentException('The payroll release has an invalid effective-date range.');
    }
    if (!in_array($rates['half'] ?? '', ['H1','H2'], true) || ($rates['requiresIncomeTaxVerification'] ?? null) !== true) {
        throw new InvalidArgumentException('The supported payroll calculation safeguards are missing.');
    }
    $provinces = ['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','SK','YT'];
    $actual = array_keys($rates['provinces'] ?? []); sort($actual); sort($provinces);
    if ($actual !== $provinces) throw new InvalidArgumentException('A complete provincial payroll catalogue is required.');
    foreach (array_merge(['CA'=>$rates['federal'] ?? []],$rates['provinces']) as $jurisdiction=>$schedule) {
        $count = count($schedule['thresholds'] ?? []);
        if ($count < 1 || $count > 12) throw new InvalidArgumentException('Invalid payroll bracket count.');
        foreach (['thresholds','rates','constants'] as $key) {
            if (!isset($schedule[$key]) || !array_is_list($schedule[$key]) || count($schedule[$key]) !== $count) throw new InvalidArgumentException('Payroll bracket columns do not align.');
            foreach ($schedule[$key] as $v) payroll_rate_numeric($v,0,$key==='rates'?1:10000000);
        }
        if ((float)$schedule['thresholds'][0] !== 0.0) throw new InvalidArgumentException('Payroll brackets must begin at zero.');
        for ($i=1;$i<$count;$i++) if ($schedule['thresholds'][$i] <= $schedule['thresholds'][$i-1]) throw new InvalidArgumentException('Payroll thresholds are not increasing.');
        $basic=$schedule['basic'] ?? null;
        if (!in_array($basic,['dynamic_federal','dynamic_manitoba'],true)) payroll_rate_numeric($basic);
    }
    foreach (['cpp'=>['ympe','yampe','basicExemption','rate','baseRate','firstAdditionalRate','max','baseMax','cpp2Rate','cpp2Max'],
              'ei'=>['mie','employeeRate','employeeMax','employerRate','employerMax']] as $group=>$fields) {
        foreach ($fields as $key) payroll_rate_numeric($rates[$group][$key] ?? null,0,str_contains(strtolower($key),'rate')?1:10000000);
    }
    $cpp=$rates['cpp']; $ei=$rates['ei'];
    if ($cpp['rate'] <= 0 || $cpp['ympe'] <= $cpp['basicExemption'] || $cpp['yampe'] < $cpp['ympe']
        || abs($cpp['baseRate']+$cpp['firstAdditionalRate']-$cpp['rate']) > 0.0000001) throw new InvalidArgumentException('CPP release values are inconsistent.');
    foreach ([[$cpp['max'],($cpp['ympe']-$cpp['basicExemption'])*$cpp['rate']],
              [$cpp['baseMax'],($cpp['ympe']-$cpp['basicExemption'])*$cpp['baseRate']],
              [$cpp['cpp2Max'],($cpp['yampe']-$cpp['ympe'])*$cpp['cpp2Rate']],
              [$ei['employeeMax'],$ei['mie']*$ei['employeeRate']],[$ei['employerMax'],$ei['mie']*$ei['employerRate']]] as [$a,$b]) {
        if (abs(round($a,2)-round($b,2)) > 0.005) throw new InvalidArgumentException('Annual contribution caps do not match the release.');
    }
    $f=$rates['formulaConstants'] ?? [];
    foreach (['maximum','minimum','reductionStart','reductionEnd'] as $key) payroll_rate_numeric($f['federalBasic'][$key] ?? null);
    if ($f['federalBasic']['maximum'] < $f['federalBasic']['minimum'] || $f['federalBasic']['reductionStart'] >= $f['federalBasic']['reductionEnd']) throw new InvalidArgumentException('Federal basic amount range is invalid.');
    payroll_rate_numeric($f['employmentAmount'] ?? null); payroll_rate_numeric($f['manitobaBasic'] ?? null);
    $on=$f['ontario'] ?? [];
    foreach (['surtaxRates','surtaxThresholds'] as $key) {
        if (!isset($on[$key]) || !array_is_list($on[$key]) || count($on[$key]) !== 2) throw new InvalidArgumentException('Ontario surtax fields are incomplete.');
        foreach ($on[$key] as $v) payroll_rate_numeric($v,0,$key==='surtaxRates'?1:10000000);
    }
    payroll_rate_numeric($on['reductionBase'] ?? null);
    if (!is_array($on['healthBands'] ?? null) || count($on['healthBands']) !== 5) throw new InvalidArgumentException('Ontario health premium fields are incomplete.');
    $previous=-1;
    foreach ($on['healthBands'] as $band) {
        foreach (['threshold','base','rate','maximum'] as $key) payroll_rate_numeric($band[$key] ?? null,0,$key==='rate'?1:10000000);
        if ($band['threshold'] <= $previous || $band['base'] > $band['maximum']) throw new InvalidArgumentException('Ontario health premium bands are invalid.');
        $previous=$band['threshold'];
    }
    if (!preg_match('/\A[0-9a-f]{64}\z/', $rates['digest']) || !hash_equals(payroll_rate_digest($rates),$rates['digest'])) {
        throw new InvalidArgumentException('The payroll rate release failed its integrity check.');
    }
    return $rates;
}

function payroll_assert_rate_date(array $rates, string $payDate): void
{
    payroll_valid_rate_date($payDate);
    if ($payDate < $rates['effectiveFrom'] || $payDate > $rates['effectiveTo'] || (int)substr($payDate,0,4) !== $rates['year']) {
        throw new InvalidArgumentException('The selected payroll rates do not cover this pay date.');
    }
}

function payroll_select_rate_release(array $releases, string $payDate): array
{
    payroll_valid_rate_date($payDate);
    $selected=null;
    foreach ($releases as $rates) {
        $rates=payroll_validate_rate_release($rates);
        if ($payDate < $rates['effectiveFrom'] || $payDate > $rates['effectiveTo']) continue;
        // Later activation replaces only the same effective interval. A future
        // release never changes calculations for pay dates before it starts.
        if ($selected===null || $rates['effectiveFrom'] >= $selected['effectiveFrom']) $selected=$rates;
    }
    if ($selected===null) throw new InvalidArgumentException('Payroll rates are not ready for this pay date. The Platform Owner must check Payroll updates and activate a supported release.');
    return $selected;
}

function payroll_bundled_rate_releases(): array
{
    return [payroll_builtin_rate_release('2026-01-01'),payroll_builtin_rate_release('2026-07-01')];
}

/** Future reviewed packs ship as data with compatible formulas and pinned CRA sources.
 * Downloading a new edition alone cannot authorize new executable tax logic.
 */
function payroll_supported_rate_releases(): array
{
    $releases=payroll_bundled_rate_releases();
    foreach (glob(__DIR__.'/payroll-catalogue/release-*.json') ?: [] as $file) {
        $pack=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        $releases[]=payroll_validate_rate_release($pack);
    }
    $seen=[];
    foreach ($releases as $r) {
        if (isset($seen[$r['id']])) throw new RuntimeException('Duplicate payroll release identifier.');
        $seen[$r['id']]=true;
    }
    return $releases;
}

function payroll_rate_audit_rows(string $action, int $limit=100): array
{
    // Pure unit tests can use bundled rates without bootstrapping a database.
    if (!function_exists('db')) return [];
    if (!schema_table_exists('platform_audit_log')) throw new RuntimeException('Platform payroll update history is unavailable.');
    $q=db()->prepare('SELECT id,metadata_json,created_at FROM platform_audit_log WHERE target_type=? AND target_id=? AND action=? ORDER BY created_at DESC,id DESC'.($limit===0?'':' LIMIT '.max(1,min(500,$limit))));
    $q->execute([PAYROLL_RATE_TARGET,'global',$action]);
    return $q->fetchAll();
}

function payroll_available_rate_releases(bool $refresh=false): array
{
    static $cached=null;
    if ($cached!==null && !$refresh) return $cached;
    $releases=payroll_bundled_rate_releases();
    foreach (array_reverse(payroll_rate_audit_rows('payroll.rates_activated',0)) as $row) {
        $metadata=json_decode((string)$row['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        $releases[]=payroll_validate_rate_release($metadata['release'] ?? []);
    }
    return $cached=$releases;
}

function payroll_rate_revision(array $releases): string
{
    return hash('sha256',implode('|',array_column($releases,'digest')));
}

function payroll_rate_source_catalogue(): array
{
    $editions=[];
    foreach (glob(__DIR__.'/payroll-catalogue/sources-*.json') ?: [] as $file) {
        $value=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($value['editions'] ?? null)) throw new RuntimeException('The payroll source catalogue is unavailable.');
        foreach ($value['editions'] as $edition) {
            payroll_valid_rate_date((string)($edition['effectiveFrom'] ?? ''));
            if (!is_string($edition['id'] ?? null) || !payroll_rate_source_url_allowed((string)($edition['sourceUrl'] ?? ''))
                || !is_array($edition['resources'] ?? null) || count($edition['resources'])<22 || count($edition['resources'])>64) throw new RuntimeException('The complete CRA source set is missing.');
            $urls=[];
            foreach ($edition['resources'] as $r) {
                if (!payroll_rate_source_url_allowed((string)($r['url'] ?? '')) || !preg_match('/\A[0-9a-f]{64}\z/',(string)($r['sha256'] ?? ''))
                    || !is_int($r['bytes'] ?? null) || $r['bytes']<1 || $r['bytes']>2000000 || isset($urls[$r['url']])) throw new RuntimeException('A CRA source record is invalid.');
                $urls[$r['url']]=true;
            }
            if (!isset($urls[$edition['sourceUrl']])) throw new RuntimeException('The CRA formula source is missing.');
            if (isset($editions[$edition['effectiveFrom']])) throw new RuntimeException('Duplicate CRA source edition.');
            $editions[$edition['effectiveFrom']]=$edition;
        }
    }
    if (!$editions) throw new RuntimeException('The payroll source catalogue is unavailable.');
    return array_values($editions);
}

function payroll_rate_public_release(array $release): array
{
    return array_intersect_key($release,array_flip(['id','label','effectiveFrom','effectiveTo','version','digest'])) + [
        'status'=>$release['effectiveFrom'] > canadian_today()?'scheduled':($release['effectiveTo'] < canadian_today()?'historical':'active'),
        'sourceUrl'=>$release['sources']['t4127'] ?? PAYROLL_RATE_INDEX,
    ];
}

function payroll_rate_state(): array
{
    $releases=payroll_available_rate_releases(true);
    $rows=payroll_rate_audit_rows('payroll.rates_checked',1);
    $last=null;
    if ($rows) $last=json_decode((string)$rows[0]['metadata_json'],true,512,JSON_THROW_ON_ERROR);
    // Activation may have changed the revision since the last check.
    $activeIds=array_column($releases,'id');
    if (is_array($last)) foreach ($last['releases'] as &$candidate) {
        if (in_array($candidate['id'],$activeIds,true)) { $candidate['canActivate']=false; if ($candidate['status']==='ready') $candidate['status']='active'; }
        if (($last['revision'] ?? '')!==payroll_rate_revision($releases) || strtotime((string)($last['checkedAt'] ?? ''))<time()-3600) $candidate['canActivate']=false;
    }
    $effective=[];
    foreach ($releases as $release) $effective[$release['effectiveFrom']]=payroll_rate_public_release($release);
    ksort($effective);
    return ['revision'=>payroll_rate_revision($releases),'selectionRule'=>'pay_date','serverTime'=>gmdate('c'),'lastCheck'=>$last,
        'checkAvailability'=>payroll_rate_check_availability($rows[0] ?? null),'releases'=>array_values($effective)];
}

/** A repeated owner click reuses the fresh result instead of becoming an error. */
function payroll_rate_check_availability(?array $lastRow, ?int $now=null): array
{
    $now ??= time();
    $createdAt=is_array($lastRow)?trim((string)($lastRow['created_at'] ?? '')):'';
    $checkedAt=$createdAt!==''?strtotime($createdAt.' UTC'):false;
    if ($checkedAt===false) return ['status'=>'available','canCheck'=>true,'retryAfterSeconds'=>0,'nextCheckAt'=>null];
    $sourceWait=0;
    try {
        $metadata=json_decode((string)($lastRow['metadata_json'] ?? ''),true,512,JSON_THROW_ON_ERROR);
        $sourceWait=max(0,min(86400,(int)($metadata['sourceRetryAfterSeconds'] ?? 0)));
    } catch (Throwable) {}
    $wait=max(PAYROLL_RATE_CHECK_COOLDOWN_SECONDS,$sourceWait);
    $retry=max(0,$checkedAt+$wait-$now);
    $status=$retry===0?'available':($sourceWait>PAYROLL_RATE_CHECK_COOLDOWN_SECONDS?'source_wait':'recent');
    return ['status'=>$status,'canCheck'=>$retry===0,'retryAfterSeconds'=>$retry,
        'nextCheckAt'=>$retry>0?gmdate('c',$checkedAt+$wait):null];
}

/** Classify transport/content failures without guessing that CRA used an anti-bot challenge. */
function payroll_rate_source_failure_status(Throwable $error): string
{
    $message=strtolower($error->getMessage());
    if (preg_match('/\bhttp\s*(?:403|429)\b|forbidden|too many requests|access denied/',$message)) return 'restricted';
    if (str_contains($message,'timed out') || str_contains($message,'timeout') || str_contains($message,'deadline')) return 'timeout';
    if (str_contains($message,'publication dates could not be read') || str_contains($message,'response was incomplete or too large')) return 'format_changed';
    return 'unavailable';
}

function payroll_rate_source_failure_message(string $status): string
{
    return match($status) {
        'restricted'=>'CRA temporarily declined or limited the automated source request. Existing approved rates were retained.',
        'timeout'=>'CRA did not answer within the safe update-check time limit. Existing approved rates were retained.',
        'format_changed'=>'The official CRA publication could not be verified in its expected format. Existing approved rates were retained.',
        default=>'The CRA update check could not finish. Existing approved rates were retained.',
    };
}

function payroll_rate_retry_after_seconds(string $value, ?int $now=null): ?int
{
    $value=trim($value);$now??=time();
    if (preg_match('/\A\d+\z/',$value)) return min(86400,(int)$value);
    $at=strtotime($value);
    return $at===false?null:min(86400,max(0,$at-$now));
}

function payroll_rate_error_retry_after(Throwable $error): ?int
{
    return preg_match('/Retry-After\s+(\d+)\s+seconds/i',$error->getMessage(),$match)?min(86400,(int)$match[1]):null;
}

/** Only known CRA publication hosts/paths; user-supplied URLs are never fetched. */
function payroll_rate_source_url_allowed(string $url): bool
{
    $p=parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '')!=='https' || ($p['host'] ?? '')!=='www.canada.ca'
        || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['query']) || isset($p['fragment'])) return false;
    $path=$p['path'] ?? '';
    return !str_contains($path,'..') && !str_contains($path,'%') && (
        str_starts_with($path,'/en/revenue-agency/services/forms-publications/payroll/t4127-payroll-deductions-formulas')
        || str_starts_with($path,'/content/dam/cra-arc/formspubs/pub/t4127-'));
}

function payroll_rate_user_agent(): string
{
    $version=defined('SR_ACCOUNTAX_VERSION')?(string)SR_ACCOUNTAX_VERSION:'current';
    return 'Tegh-Payroll-Updates/'.preg_replace('/[^0-9A-Za-z._-]/','',$version);
}

function payroll_rate_fetch(string $url, float $deadline): string
{
    if (!payroll_rate_source_url_allowed($url)) throw new RuntimeException('An unexpected CRA source address was rejected.');
    $seconds=min(8,max(0,(int)ceil($deadline-microtime(true))));
    if ($seconds<1) throw new RuntimeException('CRA did not respond before the update check timed out. Try again.');
    if (function_exists('curl_init')) {
        $body='';$retryAfter=null;$curl=curl_init($url);
        if ($curl===false) throw new RuntimeException('The CRA connection could not start.');
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>$seconds,CURLOPT_TIMEOUT=>$seconds,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_USERAGENT=>payroll_rate_user_agent(),CURLOPT_HTTPHEADER=>['Accept: text/html,text/csv,*/*;q=0.5'],
            CURLOPT_HEADERFUNCTION=>static function($handle,string $header) use (&$retryAfter):int {
                if (preg_match('/\ARetry-After:\s*(.+?)\s*\z/i',$header,$match)) $retryAfter=payroll_rate_retry_after_seconds($match[1]);
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,$deadline):int {
                if (strlen($body)+strlen($chunk)>2000000 || microtime(true)>$deadline) return 0;
                $body.=$chunk;return strlen($chunk);
            }]);
        try {
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==200 || $body==='' || microtime(true)>$deadline) throw new RuntimeException('The CRA download did not complete (HTTP '.$status.($retryAfter!==null?'; Retry-After '.$retryAfter.' seconds':'').').');
            return $body;
        } finally { curl_close($curl); }
    }
    $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>$seconds,'follow_location'=>0,'ignore_errors'=>true,
        'header'=>"User-Agent: ".payroll_rate_user_agent()."\r\nAccept: text/html,text/csv,*/*;q=0.5\r\nConnection: close\r\n"],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $stream=@fopen($url,'rb',false,$context);
    if ($stream===false) throw new RuntimeException('CRA could not be reached. Try the check again later.');
    try {
        $meta=stream_get_meta_data($stream);$status=0;
        $retryAfter=null;foreach ($meta['wrapper_data'] ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$m)) $status=(int)$m[1];
            if (preg_match('/\ARetry-After:\s*(.+?)\s*\z/i',$header,$m)) $retryAfter=payroll_rate_retry_after_seconds($m[1]);
        }
        if ($status!==200) throw new RuntimeException('CRA returned HTTP '.$status.($retryAfter!==null?'; Retry-After '.$retryAfter.' seconds':'').'. Existing payroll rates were retained.');
        $raw=stream_get_contents($stream,2000001);
        $meta=stream_get_meta_data($stream);
        if (!is_string($raw) || $raw==='' || strlen($raw)>2000000 || !empty($meta['timed_out']) || microtime(true)>$deadline) throw new RuntimeException('The CRA response was incomplete or too large.');
        return $raw;
    } finally { fclose($stream); }
}

/** Bounded parallel downloads keep a whole source check inside one HTTP request. */
function payroll_rate_fetch_many(array $urls,float $deadline): array
{
    $urls=array_values(array_unique($urls));
    if (count($urls)>64) throw new RuntimeException('Too many CRA source files were requested.');
    foreach ($urls as $url) if (!payroll_rate_source_url_allowed($url)) throw new RuntimeException('An unexpected CRA source address was rejected.');
    if (!function_exists('curl_multi_init')) {
        $out=[];foreach ($urls as $url) $out[$url]=payroll_rate_fetch($url,$deadline);return $out;
    }
    $multi=curl_multi_init();$handles=[];$bodies=[];$retryAfter=[];
    curl_multi_setopt($multi,CURLMOPT_MAX_TOTAL_CONNECTIONS,6);
    curl_multi_setopt($multi,CURLMOPT_MAX_HOST_CONNECTIONS,6);
    try {
        foreach ($urls as $url) {
            $seconds=max(1,(int)ceil($deadline-microtime(true)));$handle=curl_init($url);$bodies[$url]='';$retryAfter[$url]=null;
            curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>min(8,$seconds),CURLOPT_TIMEOUT=>$seconds,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_USERAGENT=>payroll_rate_user_agent(),CURLOPT_HTTPHEADER=>['Accept: text/html,text/csv,*/*;q=0.5'],
                CURLOPT_HEADERFUNCTION=>static function($handle,string $header) use (&$retryAfter,$url):int {
                    if (preg_match('/\ARetry-After:\s*(.+?)\s*\z/i',$header,$match)) $retryAfter[$url]=payroll_rate_retry_after_seconds($match[1]);
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use (&$bodies,$url,$deadline):int {
                    if (strlen($bodies[$url])+strlen($chunk)>2000000 || microtime(true)>$deadline) return 0;
                    $bodies[$url].=$chunk;return strlen($chunk);
                }]);
            $handles[$url]=$handle;curl_multi_add_handle($multi,$handle);
        }
        do {
            $status=curl_multi_exec($multi,$running);
            if ($status!==CURLM_OK || microtime(true)>$deadline) throw new RuntimeException('The CRA source check timed out. Existing rates were retained.');
            if ($running && curl_multi_select($multi,0.2)===-1) usleep(10000);
        } while ($running);
        foreach ($handles as $url=>$handle) {
            $httpStatus=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if (curl_errno($handle)!==0 || $httpStatus!==200 || $bodies[$url]==='') {
                throw new RuntimeException('A CRA source file could not be downloaded: '.basename((string)parse_url($url,PHP_URL_PATH)).' (HTTP '.$httpStatus.($retryAfter[$url]!==null?'; Retry-After '.$retryAfter[$url].' seconds':'').').');
            }
        }
        return $bodies;
    } finally {
        foreach ($handles as $handle) { curl_multi_remove_handle($multi,$handle);curl_close($handle); }
        curl_multi_close($multi);
    }
}

/** Parse publication dates, not webpage modification dates. Unknown month/layout fails closed. */
function payroll_rate_discover_editions(string $html): array
{
    $editions=[];
    preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',$html,$matches,PREG_SET_ORDER);
    foreach ($matches as $match) {
        $text=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($match[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')) ?? '');
        if (!str_contains($text,'Payroll Deductions Formulas') || !preg_match('/Effective\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\s*,?\s*(20\d{2})/i',$text,$m)) continue;
        $month=array_search(strtolower($m[1]),['january','february','march','april','may','june','july','august','september','october','november','december'],true)+1;
        $date=sprintf('%04d-%02d-%02d',(int)$m[3],$month,(int)$m[2]);payroll_valid_rate_date($date);
        $url=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (str_starts_with($url,'/')) $url='https://www.canada.ca'.$url;
        if (!payroll_rate_source_url_allowed($url)) continue;
        $edition=preg_match('/(\d+)(?:st|nd|rd|th)\s+Edition/i',$text,$e)?(int)$e[1]:null;
        $editions[$date]=['effectiveFrom'=>$date,'label'=>$text,'sourceUrl'=>$url,'edition'=>$edition];
    }
    if (!$editions) throw new RuntimeException('CRA publication dates could not be read. Existing rates were retained.');
    ksort($editions);return array_values($editions);
}

function payroll_rate_check_sources(callable $fetch,array $sourceCatalogue,array $activeReleases,?callable $prefetch=null): array
{
    $editions=payroll_rate_discover_editions($fetch(PAYROLL_RATE_INDEX));
    $activeIds=array_column($activeReleases,'id');$result=[];$cache=[];
    if ($prefetch!==null) {
        $urls=[];
        foreach ($editions as $edition) foreach ($sourceCatalogue as $source) {
            if ($source['effectiveFrom']===$edition['effectiveFrom'] && ($source['edition'] ?? null)===$edition['edition'] && ($source['discoveryUrl'] ?? '')===$edition['sourceUrl']) {
                foreach ($source['resources'] as $r) $urls[]=$r['url'];
            }
        }
        foreach ($prefetch(array_values(array_unique($urls))) as $url=>$raw) $cache[$url]=hash('sha256',$raw);
    }
    foreach ($editions as $edition) {
        $source=null;
        foreach ($sourceCatalogue as $known) if ($known['effectiveFrom']===$edition['effectiveFrom']) { $source=$known;break; }
        $candidate=$edition+['id'=>'cra-detected-'.$edition['effectiveFrom'],'effectiveTo'=>substr($edition['effectiveFrom'],0,4).'-12-31','canActivate'=>false,'changes'=>[]];
        if ($source===null || ($source['edition'] ?? null)!==$edition['edition'] || ($source['discoveryUrl'] ?? '')!==$edition['sourceUrl']) {
            $candidate['status']='software_update_needed';
            $candidate['reason']='A new CRA edition was found. A compatible, tested Tegh rate release is needed before these rates can be used.';
        } else {
            $candidate['id']=$source['id'];$changed=[];
            foreach ($source['resources'] as $resource) {
                $url=$resource['url'];
                if (!isset($cache[$url])) $cache[$url]=hash('sha256',$fetch($url));
                if (!hash_equals($resource['sha256'],$cache[$url])) $changed[]=$resource['label'];
            }
            $candidate['sourceDigest']=hash('sha256',json_encode($source['resources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
            if ($changed) {
                $candidate['status']='software_update_needed';
                $candidate['reason']='CRA changed published content: '.implode(', ',$changed).'. A reviewed software release is needed; existing rates were retained.';
                $candidate['changedSources']=$changed;
            } else {
                $candidate['status']=in_array($source['id'],$activeIds,true)?'active':'ready';
                $candidate['canActivate']=$candidate['status']==='ready';
                $candidate['reason']='Published source files match the supported Tegh rate release.';
            }
            foreach (payroll_supported_rate_releases() as $pack) if ($pack['id']===$candidate['id']) {
                $candidate['effectiveTo']=$pack['effectiveTo'];
                $candidate['releaseDigest']=$pack['digest'];
                $candidate['changes']=payroll_rate_changes($activeReleases,$pack);
            }
            if (!in_array($candidate['id'],array_column(payroll_supported_rate_releases(),'id'),true)) {
                $candidate['canActivate']=false;$candidate['status']='software_update_needed';
                $candidate['reason']='This source edition has no compatible installed rate release.';
            }
        }
        $result[]=$candidate;
    }
    $needsUpdate=count(array_filter($result,static fn(array $r):bool=>$r['status']==='software_update_needed'))>0;
    $ready=count(array_filter($result,static fn(array $r):bool=>$r['canActivate']))>0;
    return ['status'=>$needsUpdate?'software_update_needed':($ready?'ready':'unchanged'),
        'message'=>$needsUpdate?'CRA published changes that need a compatible Tegh release. Existing rates were retained.':($ready?'A supported payroll update is ready for your review.':'CRA source files match the installed payroll rate releases.'),
        'releases'=>$result];
}

function payroll_rate_changes(array $current, array $candidate): array
{
    $prior=null;
    foreach ($current as $r) if ($r['effectiveFrom'] <= $candidate['effectiveFrom'] && ($prior===null || $r['effectiveFrom'] >= $prior['effectiveFrom'])) $prior=$r;
    if ($prior===null) return [['label'=>'Coverage','before'=>'No supported release','after'=>$candidate['effectiveFrom'].' to '.$candidate['effectiveTo']]];
    $flatten=static function(array $v,string $prefix='') use (&$flatten):array {
        $out=[];foreach ($v as $k=>$x) {
            $key=$prefix===''?(string)$k:$prefix.'.'.$k;
            if (is_array($x)) $out+= $flatten($x,$key); else $out[$key]=$x;
        }return $out;
    };
    $old=$flatten(array_intersect_key($prior,array_flip(['federal','provinces','cpp','ei','formulaConstants'])));
    $new=$flatten(array_intersect_key($candidate,array_flip(['federal','provinces','cpp','ei','formulaConstants'])));
    $changes=[];
    foreach (array_unique(array_merge(array_keys($old),array_keys($new))) as $key) {
        if (array_key_exists($key,$old) && array_key_exists($key,$new) && $old[$key]===$new[$key]) continue;
        $changes[]=['label'=>$key,'before'=>$old[$key] ?? 'Not present','after'=>$new[$key] ?? 'Removed'];
    }
    return $changes;
}

function payroll_rate_require_owner(array $user): void
{
    admin_require_platform_owner($user);
    if (!schema_table_exists('platform_audit_log')) fail('Platform payroll history is unavailable. Complete the protected database upgrade first.',503,'payroll_rate_history_unavailable');
}

function payroll_rate_with_lock(callable $work): mixed
{
    $q=db()->query("SELECT GET_LOCK('tegh_payroll_rates_global',2)");
    if ((int)$q->fetchColumn()!==1) fail('Another payroll update check is running. Try again shortly.',409,'payroll_rate_check_busy');
    try { return $work(); }
    finally { db()->query("SELECT RELEASE_LOCK('tegh_payroll_rates_global')"); }
}

function payroll_rate_check(array $user, ?callable $sourceCheck=null): array
{
    payroll_rate_require_owner($user);
    return payroll_rate_with_lock(static function() use($user,$sourceCheck):array {
        $rows=payroll_rate_audit_rows('payroll.rates_checked',1);
        $availability=payroll_rate_check_availability($rows[0] ?? null);
        if (!$availability['canCheck']) {
            $state=payroll_rate_state();
            $message=$availability['status']==='source_wait'
                ?'CRA asked Tegh to wait before another source request. Tegh reused the saved result; no new CRA request was sent.'
                :'CRA was already checked moments ago. Tegh reused the saved result; no new CRA request was sent.';
            $state['checkRequest']=['status'=>$availability['status'],'performed'=>false,'accountingWrites'=>0,'ratesChanged'=>false,
                'message'=>$message]+$availability;
            return $state;
        }
        try {
            $deadline=microtime(true)+25;
            $result=$sourceCheck!==null?$sourceCheck():payroll_rate_check_sources(static fn(string $url):string=>payroll_rate_fetch($url,$deadline),payroll_rate_source_catalogue(),payroll_available_rate_releases(true),static fn(array $urls):array=>payroll_rate_fetch_many($urls,$deadline));
            $result['sourceStatus']='verified';
        } catch (Throwable $error) {
            $sourceStatus=payroll_rate_source_failure_status($error);
            $result=['status'=>'source_unavailable','sourceStatus'=>$sourceStatus,'message'=>payroll_rate_source_failure_message($sourceStatus),
                'detail'=>substr($error->getMessage(),0,400),'releases'=>[]];
            $sourceRetryAfter=payroll_rate_error_retry_after($error);
            if ($sourceRetryAfter!==null) $result['sourceRetryAfterSeconds']=$sourceRetryAfter;
        }
        $result['id']=new_id('payratecheck');$result['checkedAt']=gmdate('c');
        $result['revision']=payroll_rate_revision(payroll_available_rate_releases());
        $result['accountingWrites']=0;$result['ratesChanged']=false;
        platform_audit_event($user,'payroll.rates_checked',PAYROLL_RATE_TARGET,'global',$result);
        $state=payroll_rate_state();
        $state['checkRequest']=['status'=>$result['status'],'sourceStatus'=>$result['sourceStatus'],'performed'=>true,
            'accountingWrites'=>0,'ratesChanged'=>false,'message'=>$result['message']];
        return $state;
    });
}

function payroll_rate_activate(array $user,array $input): array
{
    payroll_rate_require_owner($user);
    if (($input['confirmed'] ?? null)!==true) fail('Review the effective date and confirm the payroll update.',422,'payroll_rate_confirmation_required');
    return payroll_rate_with_lock(static function() use($user,$input):array {
        $state=payroll_rate_state();$check=$state['lastCheck'];
        $pack=payroll_rate_activation_plan($state,$input,payroll_supported_rate_releases(),time());
        $pins=null;foreach(payroll_rate_source_catalogue() as $source) if($source['id']===$pack['id']) $pins=$source;
        $candidate=null;foreach($check['releases'] as $r) if($r['id']===$pack['id']) $candidate=$r;
        if($pins===null || !hash_equals(hash('sha256',json_encode($pins['resources'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),(string)($candidate['sourceDigest']??''))) {
            fail('The supported CRA sources changed after review. Check CRA updates again.',409,'payroll_rate_review_stale');
        }
        db()->beginTransaction();
        try {
            platform_audit_event($user,'payroll.rates_activated',PAYROLL_RATE_TARGET,'global',['release'=>$pack,'checkId'=>$check['id'],'previousRevision'=>$state['revision'],'activatedAt'=>gmdate('c'),'accountingWrites'=>0]);
            db()->commit();
        } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack();throw $error; }
        return payroll_rate_state();
    });
}

/** Pure validation shared by the mutation boundary and adversarial tests. */
function payroll_rate_activation_plan(array $state,array $input,array $supported,int $now): array
{
    if (($input['confirmed'] ?? null)!==true) fail('Review and confirm the payroll update.',422,'payroll_rate_confirmation_required');
    $check=$state['lastCheck'] ?? null;
    $checked=is_array($check)?strtotime((string)($check['checkedAt'] ?? '')):false;
    if (!is_array($check) || ($input['checkId'] ?? '')!==($check['id'] ?? null) || $checked===false || $checked<$now-3600 || $checked>$now+60
        || !is_string($state['revision'] ?? null) || ($input['expectedRevision'] ?? '')!==$state['revision'] || ($check['revision'] ?? '')!==$state['revision']) {
        fail('The payroll update review is stale. Check CRA updates again.',409,'payroll_rate_review_stale');
    }
    $candidate=null;
    foreach ($check['releases'] ?? [] as $r) if ($r['id']===($input['releaseId'] ?? '')) $candidate=$r;
    if ($candidate===null || ($candidate['canActivate'] ?? false)!==true || ($candidate['status'] ?? '')!=='ready') fail('This CRA release is not ready to activate.',409,'payroll_rate_release_not_ready');
    foreach ($supported as $pack) if ($pack['id']===$candidate['id']) {
        $pack=payroll_validate_rate_release($pack);
        if ($pack['effectiveFrom']!==$candidate['effectiveFrom'] || $pack['effectiveTo']!==$candidate['effectiveTo']
            || !hash_equals($pack['digest'],(string)($candidate['releaseDigest']??''))) fail('The reviewed payroll release changed. Check CRA updates again.',409,'payroll_rate_review_stale');
        return $pack;
    }
    fail('Install a compatible Tegh payroll release before activating these rates.',409,'payroll_rate_software_update_needed');
}

function handle_platform_payroll_rates(string $action): never
{
    $user=require_user();payroll_rate_require_owner($user);
    if ($action==='payroll-rates') { require_method('GET');json_response(payroll_rate_state()); }
    require_method('POST'); require_csrf();
    if ($action==='payroll-rates/check') json_response(payroll_rate_check($user));
    if ($action==='payroll-rates/activate') json_response(payroll_rate_activate($user,request_json()));
    fail('Payroll update action not found.',404,'route_not_found');
}
