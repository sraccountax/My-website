<?php
declare(strict_types=1);

/**
 * Tegh 5.7.0 contextual assistance catalogue.
 *
 * This surface is deliberately read-only and provider-free. It projects the
 * canonical server action registry for the current company, role, permission,
 * and entitlement state. It never accepts a client-owned command catalogue and
 * never grants access to an unavailable action.
 */

/** @return array<string,mixed> */
function tegh_contextual_assistance_action(array $action,array $resolved,bool $available): array
{
    return [
        'actionId'=>(string)$action['action_id'],
        'label'=>(string)($action['plain_label']??$action['name']??'Tegh action'),
        'description'=>(string)($action['plain_description']??$action['description']??''),
        'riskLabel'=>(string)($action['risk_label']??'Read only'),
        'route'=>(string)($action['route']??''),
        'requiredFeatureKey'=>(string)($action['required_feature_key']??'core.accounting'),
        'requiredFeatureKeys'=>(array)($resolved['requiredFeatureKeys']??tegh_action_continuation_features($action)),
        'missingFeatureKeys'=>(array)($resolved['missingFeatureKeys']??[]),
        'requestFeatureKey'=>(string)($resolved['missingFeatureKeys'][0]??$action['required_feature_key']??'core.accounting'),
        'unavailableReason'=>$available?'':(string)($resolved['unavailableReason']??'Request access before running this action.'),
        'advanced'=>(bool)($resolved['billable']??false),
        'available'=>$available,
        'requestAccess'=>!$available,
        'entitlementRevision'=>(int)($resolved['revision']??0),
        'parameterSchema'=>(array)($action['parameter_schema']??['type'=>'object','properties'=>[]]),
    ];
}

/** @return array<string,mixed> */
function tegh_contextual_assistance_catalog(array $user,array $company,string $screen): array
{
    $screen=mb_substr(trim($screen),0,120);
    if($screen!==''){
        $allowed=['dashboard','settings','diagnostics','command-centre','agent-center','qa-guardian'];
        if(function_exists('agent_report_page_registry'))$allowed=array_merge($allowed,array_keys(agent_report_page_registry()));
        foreach(tegh_action_registry() as $registered)$allowed=array_merge($allowed,array_map('strval',(array)($registered['recommended_screens']??[])));
        $allowed=array_values(array_unique(array_filter($allowed,static fn(string $item):bool=>$item!=='')));
        if(!in_array($screen,$allowed,true))fail('That assistance screen is not registered.',422,'contextual_screen_invalid',false);
    }
    $platformOwner=platform_role_for_user((string)$user['id'])==='platform_owner';
    $recommended=[];
    foreach(tegh_action_registry() as $action){
        if(empty($action['command_eligible']))continue;
        if($screen===''||!in_array($screen,(array)($action['recommended_screens']??[]),true))continue;
        if(!company_role_can((string)$company['role'],(string)$action['required_permission']))continue;
        if(!empty($action['owner_only'])&&!$platformOwner)continue;
        $feature=(string)($action['required_feature_key']??'core.accounting');
        $resolved=tegh_resolve_feature($user,$company,$feature);
        $required=tegh_action_continuation_features($action);$missing=[];
        foreach($required as $requiredFeature){$check=$requiredFeature===$feature?$resolved:tegh_resolve_feature($user,$company,$requiredFeature);if(empty($check['allowed']))$missing[]=$requiredFeature;}
        $resolved['requiredFeatureKeys']=$required;$resolved['missingFeatureKeys']=$missing;
        $resolved['allowed']=$missing===[];
        if($missing)$resolved['unavailableReason']=in_array('tegh.command_centre',$missing,true)?'This action needs Tegh Assist command access. Request access in Features & requests.':'This feature is not enabled. Check Features & requests for access or a pending request.';
        $recommended[]=tegh_contextual_assistance_action($action,$resolved,(bool)$resolved['allowed']);
    }
    usort($recommended,static function(array $left,array $right):int{
        if($left['available']!==$right['available'])return $left['available']?-1:1;
        return strcmp((string)$left['label'],(string)$right['label']);
    });
    return [
        'screen'=>$screen,
        'recommended'=>$recommended,
        'count'=>count($recommended),
        'providerAttempts'=>0,
        'accountingWrites'=>0,
        'registrySource'=>'tegh_action_registry',
        'requestAccessRoute'=>'modules-access',
    ];
}

function handle_contextual_assistance(string $action): never
{
    $user=require_user();
    $company=require_company($user);
    require_company_permission($company,'company.view');
    if($action===''||$action==='catalog'){
        require_method('GET');
        json_response(tegh_contextual_assistance_catalog($user,$company,(string)($_GET['screen']??'')));
    }
    fail('Contextual assistance route not found.',404,'route_not_found');
}
