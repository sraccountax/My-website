<?php
declare(strict_types=1);

/** Private user/company download archive. No public URLs or schema migration. */
function handle_requested_downloads(): never
{
    $user=require_user();$company=require_company($user);
    require_company_permission($company,'reports.view');
    $companyId=(string)$company['id'];
    $kind='requested-downloads/'.hash('sha256',(string)$user['id']);
    $directory=private_storage_root().'/'.$companyId.'/'.$kind;
    $method=request_method();
    if($method==='POST'){
        require_csrf();require_company_permission($company,'reports.export');
        $saved=save_private_upload($companyId,$kind,['pdf','xlsx','xls','csv','txt','json','zip'],['application/pdf','application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel','text/plain','text/csv','application/csv','application/json','application/octet-stream','text/xml','application/xml','text/html']);
        $id=pathinfo($saved['relativePath'],PATHINFO_FILENAME);
        $record=['id'=>$id,'filename'=>preg_replace('/[^A-Za-z0-9._ -]+/','-',basename($saved['originalName'])),'size'=>$saved['size'],'createdAt'=>gmdate('c'),'path'=>$saved['relativePath'],'sha256'=>hash_file('sha256',$saved['absolutePath'])];
        $metadata=$directory.'/'.$id.'.meta.json';
        try{
            if(file_put_contents($metadata,json_encode($record,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Download could not be saved.');
            chmod($metadata,0600);
            audit_event($user,$companyId,'download.saved','requested_download',$id,['filename'=>$record['filename'],'sha256'=>$record['sha256'],'size'=>$record['size']]);
        }catch(Throwable $error){delete_private_file($saved['relativePath']);if(is_file($metadata))unlink($metadata);throw $error;}
        unset($record['path'],$record['sha256']);json_response(['download'=>$record],201);
    }
    if($method==='DELETE'){require_csrf();$input=request_json();$id=(string)($input['id']??'');}
    else {require_method('GET');$id=(string)($_GET['id']??'');}
    if($id===''){
        if($method!=='GET')fail('Choose a saved download.',422);
        $downloads=[];
        foreach(glob($directory.'/*.meta.json')?:[] as $path){$r=json_decode((string)file_get_contents($path),true);if(!is_array($r)||!isset($r['path'])||!valid_private_storage_reference($companyId,(string)$r['path'],$kind))continue;unset($r['path'],$r['sha256']);$downloads[]=$r;}
        usort($downloads,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']));json_response(['downloads'=>$downloads]);
    }
    if(!preg_match('/^[a-f0-9]{48}$/',$id))fail('Saved download not found.',404);
    $metadata=$directory.'/'.$id.'.meta.json';
    $record=is_file($metadata)?json_decode((string)file_get_contents($metadata),true):null;
    if(!is_array($record)||!valid_private_storage_reference($companyId,(string)($record['path']??''),$kind))fail('Saved download not found.',404);
    if($method==='DELETE'){
        audit_event($user,$companyId,'download.deleted','requested_download',$id,['filename'=>$record['filename']]);
        delete_private_file($record['path']);unlink($metadata);json_response(['deleted'=>true]);
    }
    require_company_permission($company,'reports.export');
    $actual=hash_file('sha256',(string)private_absolute_path($record['path']));
    if(!is_string($actual)||!hash_equals((string)$record['sha256'],$actual))fail('The saved file could not be verified. Generate a new download.',409);
    stream_private_file($record['path'],$record['filename']);
}
