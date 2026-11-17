<?php
declare(strict_types=1);

/**
 * Resolve the posting contract for one current-company GL account.
 *
 * The browser may display this result, but it never supplies the capability
 * back as accounting authority. Preview and commit call this resolver again.
 */
function tegh_account_capability_resolve(string $companyId,string $accountId,bool $lock=false): array
{
    $sql="SELECT a.id,a.company_id,a.code,a.name,a.account_type,a.normal_balance,a.is_control,a.active,
        ba.id AS bank_account_id,ba.name AS bank_account_name,ba.account_type AS financial_account_type,
        ba.masked_number,ba.currency AS financial_currency,ba.active AS financial_active,
        GROUP_CONCAT(CASE WHEN cs.status='active' THEN cs.system_key END ORDER BY cs.system_key SEPARATOR ',') AS system_keys
      FROM accounts a
      LEFT JOIN bank_accounts ba ON ba.company_id=a.company_id AND ba.ledger_account_id=a.id
      LEFT JOIN company_system_accounts cs ON cs.company_id=a.company_id AND cs.account_id=a.id
      WHERE a.company_id=? AND a.id=?
      GROUP BY a.id,a.company_id,a.code,a.name,a.account_type,a.normal_balance,a.is_control,a.active,
        ba.id,ba.name,ba.account_type,ba.masked_number,ba.currency,ba.active".($lock?' FOR UPDATE':'');
    $q=db()->prepare($sql);$q->execute([$companyId,$accountId]);$account=$q->fetch(PDO::FETCH_ASSOC);
    if(!$account)fail('Choose an available current-company GL account.',422,'account_capability_unavailable');

    $keys=array_values(array_filter(explode(',',(string)($account['system_keys']??''))));
    $active=(bool)$account['active'];
    $kind='ordinary_gl';$postingAllowed=$active;$required=[];$reason='';
    if(!$active){$kind='non_posting';$postingAllowed=false;$reason='Inactive account';}
    elseif((string)$account['code']==='9999'||in_array('opening_balance_control',$keys,true)){
        $kind='reserved_system';$postingAllowed=false;$required=['authorized_opening_balance_workflow'];$reason='Opening Balance Control is available only in its authorized workflow.';
    }elseif($account['bank_account_id']!==null){
        $kind='financial_account';$postingAllowed=(bool)$account['financial_active'];$required=['interbank_transfer'];
        if(!$postingAllowed)$reason='The linked financial account is inactive.';
    }elseif(array_intersect($keys,['accounts_receivable','ar_control'])){
        $kind='customer_control';$required=['customer','payment_flow'];
    }elseif(array_intersect($keys,['accounts_payable','ap_control'])){
        $kind='vendor_control';$required=['vendor','payment_flow'];
    }elseif($keys!==[]||!empty($account['is_control'])){
        $kind='module_control';$postingAllowed=false;$required=['authorized_control_workflow'];
        $reason='Choose the settlement or adjustment workflow for this control account.';
    }

    return [
        'account'=>['id'=>(string)$account['id'],'code'=>(string)$account['code'],'name'=>(string)$account['name'],
            'label'=>(string)$account['code'].' — '.(string)$account['name'],'type'=>(string)$account['account_type'],
            'normalBalance'=>(string)$account['normal_balance'],'active'=>$active],
        'kind'=>$kind,'postingAllowed'=>$postingAllowed,'requiredContext'=>$required,'reason'=>$reason,
        'financialAccount'=>$account['bank_account_id']===null?null:[
            'id'=>(string)$account['bank_account_id'],'name'=>(string)$account['bank_account_name'],
            'type'=>(string)$account['financial_account_type'],'maskedNumber'=>(string)($account['masked_number']??''),
            'currency'=>(string)$account['financial_currency'],'active'=>(bool)$account['financial_active']],
        'systemKeys'=>$keys,
    ];
}

function tegh_account_capability_catalogue(string $companyId): array
{
    $q=db()->prepare('SELECT id FROM accounts WHERE company_id=? AND active=1 ORDER BY code,id');$q->execute([$companyId]);
    $out=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$out[]=tegh_account_capability_resolve($companyId,(string)$id,false);
    return $out;
}

function tegh_account_capability_assert_context(array $capability,array $context): void
{
    if(empty($capability['postingAllowed']))fail((string)($capability['reason']?:'This account is not an ordinary posting target.'),422,'account_workflow_required');
    $kind=(string)$capability['kind'];
    if($kind==='customer_control'&&trim((string)($context['partyId']??''))==='')fail('Choose a customer for the Accounts Receivable posting.',422,'customer_required');
    if($kind==='vendor_control'&&trim((string)($context['partyId']??''))==='')fail('Choose a vendor for the Accounts Payable posting.',422,'vendor_required');
    if($kind==='financial_account'&&trim((string)($context['bankAccountId']??''))==='')fail('Choose the counterparty financial account for the interbank transfer.',422,'transfer_account_required');
}
