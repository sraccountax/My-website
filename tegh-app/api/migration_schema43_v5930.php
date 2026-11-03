<?php
declare(strict_types=1);

/** Additive profile storage only. Called under the protected global upgrade lock. */
function tegh_schema43_contract_material(): array
{
    return ['version'=>43,'step'=>'schema43.5930.bank_profile','table'=>'bank_accounts','column'=>'profile_json',
        'definition'=>'LONGTEXT NULL','cashFlowActivity'=>['operating','investing','financing','exchange_effects'],'legacyFamily'=>['bank','credit_card'],'profileVersion'=>1];
}

function tegh_schema43_classify_column(?array $column): array
{
    if($column===null)return ['ready'=>false,'repairable'=>true,'code'=>'bank_profile_column_missing'];
    if(strtolower((string)($column['DATA_TYPE']??''))!=='longtext' || strtoupper((string)($column['IS_NULLABLE']??''))!=='YES')
        return ['ready'=>false,'repairable'=>false,'code'=>'bank_profile_column_malformed'];
    if(!empty($column['EXTRA']) || ($column['COLUMN_DEFAULT']??null)!==null)
        return ['ready'=>false,'repairable'=>false,'code'=>'bank_profile_column_malformed'];
    return ['ready'=>true,'repairable'=>true,'code'=>'bank_profile_ready'];
}

function tegh_schema43_classify_cash_flow(?array $column): array
{
    if($column===null)return ['ready'=>false,'repairable'=>false,'code'=>'cash_flow_activity_missing'];
    $expected="enum('operating','investing','financing','exchange_effects')";
    $old="enum('operating','investing','financing')";
    $type=strtolower((string)($column['COLUMN_TYPE']??''));
    if(strtoupper((string)($column['IS_NULLABLE']??''))!=='NO'||!in_array($type,[$expected,$old],true))
        return ['ready'=>false,'repairable'=>false,'code'=>'cash_flow_activity_malformed'];
    return ['ready'=>$type===$expected,'repairable'=>true,'code'=>$type===$expected?'cash_flow_activity_ready':'cash_flow_exchange_effects_missing'];
}

function tegh_schema43_status(): array
{
    $q=db()->prepare("SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute(['bank_accounts','profile_json']);$row=$q->fetch();
    // MariaDB reports nullable LONGTEXT's SQL NULL default as a literal NULL string.
    if($row && ($row['COLUMN_DEFAULT']??null)==='NULL')$row['COLUMN_DEFAULT']=null;
    $profile=tegh_schema43_classify_column($row?:null);
    $q->execute(['cash_flow_mappings','activity']);$row=$q->fetch();$cash=tegh_schema43_classify_cash_flow($row?:null);
    $issues=[];
    foreach([['bank_accounts.profile_json',$profile,'LONGTEXT NULL'],['cash_flow_mappings.activity',$cash,"ENUM('operating','investing','financing','exchange_effects') NOT NULL"]] as [$object,$result,$expected])
        if(!$result['ready'])$issues[]=['object'=>$object,'code'=>$result['code'],'repairable'=>$result['repairable'],'expected'=>$expected];
    return ['ready'=>$profile['ready']&&$cash['ready'],'repairable'=>$profile['repairable']&&$cash['repairable'],'issues'=>$issues,'expectedSchemaVersion'=>43];
}

function tegh_schema43_prepare_columns(): void
{
    if(!schema_table_exists('bank_accounts'))throw new RuntimeException('Schema 43 requires the retained bank_accounts table.');
    $before=tegh_schema43_status();
    if($before['ready'])return;
    if(!$before['repairable'])throw new RuntimeException('The existing bank profile column does not match the supported schema. No data was changed.');
    schema_add_column('bank_accounts','profile_json',"LONGTEXT NULL COMMENT 'Schema 43: versioned operational subtype and reporting terms; legacy posting family is unchanged'");
    $q=db()->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_flow_mappings' AND column_name='activity'");
    if(!tegh_schema43_classify_cash_flow($q->fetch()?:null)['ready'])db()->exec("ALTER TABLE cash_flow_mappings MODIFY activity ENUM('operating','investing','financing','exchange_effects') NOT NULL");
    if(!tegh_schema43_status()['ready'])throw new RuntimeException('The bank profile column could not be verified after preparation.');
}

/** Manual rollback support only: the upgrade orchestrator owns schema markers. */
function tegh_schema43_remove_unused_column(): void
{
    // Accept a partly completed DOWN only after validating every remaining object.
    // Both data-loss guards run before any auto-committing DDL; no accounting rows change.
    $q=db()->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='bank_accounts' AND column_name='profile_json'");
    $profile=$q->fetch();if($profile && ($profile['COLUMN_DEFAULT']??null)==='NULL')$profile['COLUMN_DEFAULT']=null;
    if($profile && !tegh_schema43_classify_column($profile)['ready'])throw new RuntimeException('Cannot roll back an unrecognized bank profile column.');
    $q=db()->query("SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cash_flow_mappings' AND column_name='activity'");
    $cash=tegh_schema43_classify_cash_flow($q->fetch()?:null);
    if(!$cash['repairable'])throw new RuntimeException('Cannot roll back an unrecognized cash-flow mapping column.');
    if($profile && (int)db()->query('SELECT COUNT(*) FROM bank_accounts WHERE profile_json IS NOT NULL')->fetchColumn()>0)
        throw new RuntimeException('Rollback would discard bank account profiles. Keep Schema 43 and restore compatible application files instead.');
    if((int)db()->query("SELECT COUNT(*) FROM cash_flow_mappings WHERE activity='exchange_effects'")->fetchColumn()>0)
        throw new RuntimeException('Rollback would discard cash-flow exchange-effect mappings. Keep Schema 43.');
    if($cash['ready'])db()->exec("ALTER TABLE cash_flow_mappings MODIFY activity ENUM('operating','investing','financing') NOT NULL");
    if($profile)db()->exec('ALTER TABLE bank_accounts DROP COLUMN profile_json');
}
