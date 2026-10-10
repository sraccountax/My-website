<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

try{
    require_once __DIR__.'/bootstrap.php';
    require_once __DIR__.'/migrations.php';
    require_once __DIR__.'/auth.php';
    require_once __DIR__.'/companies.php';
    require_once __DIR__.'/imports.php';
    require_once __DIR__.'/invoicing.php';
    require_once __DIR__.'/master_data.php';
    require_once __DIR__.'/accounting.php';
    require_once __DIR__.'/payments.php';
    require_once __DIR__.'/advanced.php';
    require_once __DIR__.'/backup.php';
    require_once __DIR__.'/books.php';
    require_once __DIR__.'/payroll_rates.php';
    require_once __DIR__.'/payroll.php';
    require_once __DIR__.'/operations.php';
    require_once __DIR__.'/voids.php';
    require_once __DIR__.'/ai_provider.php';
    require_once __DIR__.'/ai.php';
    require_once __DIR__.'/ai_agent.php';
    require_once __DIR__.'/ai_research.php';
    require_once __DIR__.'/portal.php';
    require_once __DIR__.'/platform.php';
    require_once __DIR__.'/native_agents.php';

    $limit=2;
    foreach(array_slice($argv,1) as $argument){
        if(preg_match('/^--limit=([1-5])$/',(string)$argument,$match))$limit=(int)$match[1];
        else throw new InvalidArgumentException('Usage: php api/native-agent-cron.php [--limit=1..5]');
    }
    $result=native_agent_scheduler_execute($limit,'scheduled');
    if(!empty($result['failedCompanyIds'])){
        fwrite(STDERR,json_encode(['ok'=>false,'errorCode'=>'native_agent_companies_failed','failedCompanyIds'=>$result['failedCompanyIds'],'result'=>$result],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
}catch(Throwable $error){
    error_log('Tegh Native Agent CLI scheduler failed class='.$error::class);
    fwrite(STDERR,json_encode(['ok'=>false,'errorCode'=>'native_agent_scheduler_failed','failedCompanyIds'=>$GLOBALS['tegh_native_agent_failed_company_ids']??[],'message'=>'Native Agent scheduling did not complete safely. Review the private server log.'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
