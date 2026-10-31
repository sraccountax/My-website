<?php
declare(strict_types=1);

/** Optional invoice rate choices. Statutory jurisdiction defaults are never edited here. */
function handle_company_invoice_tax_presets(): never
{
    require_method('GET','POST','PUT','DELETE');
    $user=require_user();$company=require_company($user);$companyId=(string)$company['id'];
    if(!tegh_tax_r71_presets_ready())fail('Complete the protected tax preset upgrade before editing company rates.',503,'tax_presets_upgrade_required');
    if(request_method()==='GET'){
        $q=db()->prepare("SELECT id,label,province,rate_bps rateBps,reason,effective_from effectiveFrom,status FROM company_invoice_tax_presets WHERE company_id=? AND status='active' ORDER BY province,effective_from,label,id");
        $q->execute([$companyId]);$rows=array_map(static function(array $r):array{$r['rateBps']=(int)$r['rateBps'];return $r;},$q->fetchAll(PDO::FETCH_ASSOC));
        json_response(['presets'=>$rows,'shared'=>true]);
    }
    require_csrf();require_company_role($company,'owner','admin');$input=request_json();
    $method=request_method();
    $result=db_transaction_retry(static function()use($user,$company,$companyId,$input,$method):array{
        $company=tegh_bank_reauthorize_mutation($user,$company,'');require_company_role($company,'owner','admin');
        $id=$method==='POST'?new_id('taxpreset'):clean_text($input['presetId']??'','Tax preset',64);
        $prior=null;
        if($method!=='POST'){
            $q=db()->prepare('SELECT * FROM company_invoice_tax_presets WHERE id=? AND company_id=? FOR UPDATE');$q->execute([$id,$companyId]);$prior=$q->fetch(PDO::FETCH_ASSOC);
            if(!$prior||$prior['status']!=='active')fail('Choose an active company tax preset.',404,'tax_preset_unavailable');
        }
        if($method==='DELETE'){
            db()->prepare("UPDATE company_invoice_tax_presets SET status='retired',updated_by=? WHERE id=? AND company_id=? AND status='active'")->execute([$user['id'],$id,$companyId]);
            audit_event($user,$companyId,'tax_preset.retired','company_invoice_tax_preset',$id,['previousRateBps'=>(int)$prior['rate_bps'],'province'=>$prior['province']]);
            return ['id'=>$id,'status'=>'retired'];
        }
        $label=clean_text($input['label']??'','Preset name',80);
        $province=strtoupper(clean_text($input['province']??'','Province',2));
        if(!in_array($province,['AB','BC','MB','NB','NL','NS','NT','NU','ON','PE','QC','SK','YT'],true))fail('Choose a Canadian province or territory.',422,'tax_preset_province_invalid');
        $rate=$input['rateBps']??null;
        if(!is_int($rate)||$rate<0||$rate>3000)fail('Enter a tax rate from 0% to 30%.',422,'tax_preset_rate_invalid');
        $reason=clean_text($input['reason']??'','Rate reason',500);
        if(mb_strlen($reason)<12)fail('Explain the rate in at least 12 characters.',422,'tax_preset_reason_required');
        $effective=safe_date($input['effectiveFrom']??canadian_today(),'Effective date');
        if($method==='POST'){
            $existing=db()->prepare("SELECT id FROM company_invoice_tax_presets WHERE company_id=? AND status='active' AND label=? AND province=? AND rate_bps=? AND reason=? AND effective_from=? LIMIT 1");
            $existing->execute([$companyId,$label,$province,$rate,$reason,$effective]);$matching=$existing->fetchColumn();
            if($matching!==false)return ['id'=>(string)$matching,'label'=>$label,'province'=>$province,'rateBps'=>$rate,'reason'=>$reason,'effectiveFrom'=>$effective,'status'=>'active','idempotent'=>true];
            $count=db()->prepare("SELECT COUNT(*) FROM company_invoice_tax_presets WHERE company_id=? AND status='active'");$count->execute([$companyId]);
            if((int)$count->fetchColumn()>=50)fail('This company has reached 50 active invoice tax presets.',409,'tax_preset_limit');
            db()->prepare('INSERT INTO company_invoice_tax_presets(id,company_id,label,province,rate_bps,reason,effective_from,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)')
                ->execute([$id,$companyId,$label,$province,$rate,$reason,$effective,$user['id'],$user['id']]);
        }else{
            db()->prepare("UPDATE company_invoice_tax_presets SET label=?,province=?,rate_bps=?,reason=?,effective_from=?,updated_by=? WHERE id=? AND company_id=? AND status='active'")
                ->execute([$label,$province,$rate,$reason,$effective,$user['id'],$id,$companyId]);
        }
        audit_event($user,$companyId,$method==='POST'?'tax_preset.created':'tax_preset.updated','company_invoice_tax_preset',$id,['province'=>$province,'rateBps'=>$rate,'effectiveFrom'=>$effective,'previousRateBps'=>$prior?(int)$prior['rate_bps']:null]);
        return ['id'=>$id,'label'=>$label,'province'=>$province,'rateBps'=>$rate,'reason'=>$reason,'effectiveFrom'=>$effective,'status'=>'active'];
    });json_response(['preset'=>$result],$method==='POST'?201:200);
}
