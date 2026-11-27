<?php
// Compares the R185 two-query foreign-key snapshot with the original joined query, table by table.
$_GET['route']='startup/prepare'; $_SERVER['REQUEST_METHOD']='POST';
require '/srv/gate/www/api/bootstrap.php'; require '/srv/gate/www/api/migrations.php';
$old=[];foreach(db()->query("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,rc.UPDATE_RULE,rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND rc.TABLE_NAME=k.TABLE_NAME AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC) as $r){$r=array_change_key_case($r,CASE_UPPER);$old[$r['TABLE_NAME']][]=$r;}
$tables=db()->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
$t=microtime(true);$diff=0;$rows=0;
foreach($tables as $tb){$new=schema_startup_contract_rows('foreignKeys',$tb);$rows+=count($new);if($new!==($old[$tb]??[])){$diff++;echo "DIFF $tb\n";}}
printf("tables %d, rows %d, tables differing %d, new snapshot %.0f ms\n",count($tables),$rows,$diff,(microtime(true)-$t)*1000);
