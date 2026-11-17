<?php
declare(strict_types=1);

/**
 * Privacy operations for the private beta.
 *
 * These records are deliberately separate from accounting data. The routes
 * do not send email, delete data, or make a legal determination. They provide
 * a protected queue for the operator to document requests and incidents.
 */

const TEGH_PRIVACY_REQUEST_CATEGORIES = ['access','correction','withdrawal','closure','complaint','security','other'];
const TEGH_PRIVACY_REQUEST_STATUSES = ['received','identity_check','in_progress','waiting','completed','declined'];
const TEGH_BREACH_STATUSES = ['open','contained','assessing','notifying','closed'];
const TEGH_BREACH_RISK_STATES = ['unassessed','no','yes'];

function privacy_operations_require_schema(): void
{
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS privacy_requests (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      requestor_user_id VARCHAR(64) NULL,
      requestor_email VARCHAR(254) NOT NULL,
      category ENUM('access','correction','withdrawal','closure','complaint','security','other') NOT NULL,
      subject VARCHAR(180) NOT NULL,
      details TEXT NOT NULL,
      identity_method ENUM('signed_in','account_email','callback','other') NOT NULL DEFAULT 'signed_in',
      status ENUM('received','identity_check','in_progress','waiting','completed','declined') NOT NULL DEFAULT 'received',
      due_at DATETIME NOT NULL,
      assigned_to VARCHAR(64) NULL,
      resolution_note TEXT NULL,
      completed_at DATETIME NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY privacy_requests_status_due (status,due_at),
      KEY privacy_requests_user_time (requestor_user_id,created_at),
      CONSTRAINT privacy_requests_user_fk FOREIGN KEY (requestor_user_id) REFERENCES users(id) ON DELETE SET NULL,
      CONSTRAINT privacy_requests_assignee_fk FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS privacy_breach_register (
      id VARCHAR(64) NOT NULL PRIMARY KEY,
      title VARCHAR(180) NOT NULL,
      discovered_at DATETIME NOT NULL,
      occurred_from DATETIME NULL,
      occurred_to DATETIME NULL,
      data_categories_json TEXT NOT NULL,
      affected_count INT UNSIGNED NULL,
      circumstances TEXT NOT NULL,
      containment TEXT NOT NULL,
      risk_assessment TEXT NOT NULL,
      real_risk_significant_harm ENUM('unassessed','no','yes') NOT NULL DEFAULT 'unassessed',
      status ENUM('open','contained','assessing','notifying','closed') NOT NULL DEFAULT 'open',
      regulator_reported_at DATETIME NULL,
      individuals_notified_at DATETIME NULL,
      created_by VARCHAR(64) NOT NULL,
      updated_by VARCHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY privacy_breach_status_time (status,discovered_at),
      CONSTRAINT privacy_breach_creator_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
      CONSTRAINT privacy_breach_updater_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function privacy_operations_platform_owner(): array
{
    $user = require_user();
    if (platform_role_for_user((string)$user['id']) !== 'platform_owner') {
        fail('Privacy operations are restricted to the Platform Owner.', 403, 'platform_owner_required');
    }
    return $user;
}

function privacy_request_public(array $row, bool $operator = false): array
{
    $result = [
        'id'=>(string)$row['id'],
        'category'=>(string)$row['category'],
        'subject'=>(string)$row['subject'],
        'status'=>(string)$row['status'],
        'dueAt'=>(string)$row['due_at'],
        'createdAt'=>(string)$row['created_at'],
        'updatedAt'=>(string)$row['updated_at'],
        'completedAt'=>$row['completed_at'] !== null ? (string)$row['completed_at'] : null,
    ];
    if ($operator) $result += [
        'requestorUserId'=>$row['requestor_user_id'],
        'requestorEmail'=>(string)$row['requestor_email'],
        'details'=>(string)$row['details'],
        'identityMethod'=>(string)$row['identity_method'],
        'assignedTo'=>$row['assigned_to'],
        'resolutionNote'=>$row['resolution_note'],
    ];
    return $result;
}

function privacy_breach_public(array $row): array
{
    $categories = json_decode((string)$row['data_categories_json'], true);
    return [
        'id'=>(string)$row['id'],'title'=>(string)$row['title'],'discoveredAt'=>(string)$row['discovered_at'],
        'occurredFrom'=>$row['occurred_from'],'occurredTo'=>$row['occurred_to'],
        'dataCategories'=>is_array($categories) ? array_values($categories) : [],
        'affectedCount'=>$row['affected_count'] === null ? null : (int)$row['affected_count'],
        'circumstances'=>(string)$row['circumstances'],'containment'=>(string)$row['containment'],
        'riskAssessment'=>(string)$row['risk_assessment'],'realRiskSignificantHarm'=>(string)$row['real_risk_significant_harm'],
        'status'=>(string)$row['status'],'regulatorReportedAt'=>$row['regulator_reported_at'],
        'individualsNotifiedAt'=>$row['individuals_notified_at'],'createdBy'=>(string)$row['created_by'],
        'updatedBy'=>(string)$row['updated_by'],'createdAt'=>(string)$row['created_at'],'updatedAt'=>(string)$row['updated_at'],
    ];
}

function privacy_optional_datetime(mixed $value, string $label): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    $time = strtotime($value);
    if ($time === false) fail($label . ' is invalid.', 422, 'privacy_datetime_invalid');
    return gmdate('Y-m-d H:i:s', $time);
}

function privacy_data_categories(mixed $value): array
{
    if (!is_array($value)) fail('List the affected data categories.', 422, 'privacy_categories_required');
    $out = [];
    foreach ($value as $item) {
        $item = trim((string)$item);
        if ($item === '' || mb_strlen($item) > 100) fail('An affected data category is invalid.', 422, 'privacy_category_invalid');
        $out[$item] = true;
    }
    if (!$out) fail('List the affected data categories.', 422, 'privacy_categories_required');
    return array_keys($out);
}

function handle_privacy_operations(string $action): never
{
    privacy_operations_require_schema();
    $action = trim($action, '/');

    if ($action === 'requests' && request_method() === 'POST') {
        $user = require_user(); require_csrf(); $input = request_json();
        $category = strtolower(trim((string)($input['category'] ?? '')));
        if (!in_array($category, TEGH_PRIVACY_REQUEST_CATEGORIES, true)) fail('Choose a privacy request type.', 422, 'privacy_category_invalid');
        $subject = clean_text($input['subject'] ?? '', 'Subject', 180);
        $details = clean_text($input['details'] ?? '', 'Request details', 3000);
        $id = new_id('privacy');
        $dueAt = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
        db()->prepare("INSERT INTO privacy_requests (id,requestor_user_id,requestor_email,category,subject,details,identity_method,status,due_at) VALUES (?,?,?,?,?,?,'signed_in','received',?)")
            ->execute([$id,(string)$user['id'],(string)$user['email'],$category,$subject,$details,$dueAt]);
        platform_audit_event($user, 'privacy.request_created', 'privacy_request', $id, ['category'=>$category,'dueAt'=>$dueAt]);
        $stmt = db()->prepare('SELECT * FROM privacy_requests WHERE id=?'); $stmt->execute([$id]);
        json_response(['request'=>privacy_request_public($stmt->fetch())], 201);
    }

    if ($action === 'requests/mine') {
        require_method('GET'); $user = require_user();
        $stmt = db()->prepare('SELECT * FROM privacy_requests WHERE requestor_user_id=? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(string)$user['id']]);
        json_response(['requests'=>array_map('privacy_request_public',$stmt->fetchAll())]);
    }

    if ($action === 'requests') {
        require_method('GET'); privacy_operations_platform_owner();
        $stmt = db()->query('SELECT * FROM privacy_requests ORDER BY FIELD(status,\'received\',\'identity_check\',\'in_progress\',\'waiting\',\'completed\',\'declined\'),due_at,created_at LIMIT 500');
        json_response(['requests'=>array_map(static fn(array $row):array=>privacy_request_public($row,true),$stmt->fetchAll())]);
    }

    if ($action === 'requests/update') {
        require_method('POST'); $user = privacy_operations_platform_owner(); require_csrf(); $input = request_json();
        $id = clean_text($input['requestId'] ?? '', 'Request', 64);
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, TEGH_PRIVACY_REQUEST_STATUSES, true)) fail('Choose a valid request status.', 422, 'privacy_status_invalid');
        $identity = strtolower(trim((string)($input['identityMethod'] ?? 'signed_in')));
        if (!in_array($identity, ['signed_in','account_email','callback','other'], true)) fail('Choose a valid identity-verification method.', 422, 'privacy_identity_invalid');
        $note = optional_text($input['resolutionNote'] ?? null, 4000);
        $stmt = db()->prepare('UPDATE privacy_requests SET status=?,identity_method=?,assigned_to=?,resolution_note=?,completed_at=? WHERE id=?');
        $stmt->execute([$status,$identity,(string)$user['id'],$note,in_array($status,['completed','declined'],true)?gmdate('Y-m-d H:i:s'):null,$id]);
        if ($stmt->rowCount() !== 1) fail('Privacy request not found.', 404, 'privacy_request_not_found');
        platform_audit_event($user, 'privacy.request_updated', 'privacy_request', $id, ['status'=>$status,'identityMethod'=>$identity]);
        json_response(['updated'=>true]);
    }

    if ($action === 'breaches' && request_method() === 'GET') {
        privacy_operations_platform_owner();
        $stmt = db()->query('SELECT * FROM privacy_breach_register ORDER BY discovered_at DESC,id DESC LIMIT 500');
        json_response(['incidents'=>array_map('privacy_breach_public',$stmt->fetchAll()),'minimumRecordRetentionMonths'=>24]);
    }

    if ($action === 'breaches' && request_method() === 'POST') {
        $user = privacy_operations_platform_owner(); require_csrf(); $input = request_json();
        $id = new_id('breach'); $title = clean_text($input['title'] ?? '', 'Incident title', 180);
        $discovered = privacy_optional_datetime($input['discoveredAt'] ?? gmdate('c'), 'Discovery time') ?? gmdate('Y-m-d H:i:s');
        $from = privacy_optional_datetime($input['occurredFrom'] ?? null, 'Occurrence start');
        $to = privacy_optional_datetime($input['occurredTo'] ?? null, 'Occurrence end');
        $categories = privacy_data_categories($input['dataCategories'] ?? null);
        $count = ($input['affectedCount'] ?? '') === '' ? null : filter_var($input['affectedCount'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($count === false) fail('Affected count must be zero or more.', 422, 'privacy_affected_count_invalid');
        $circumstances = clean_text($input['circumstances'] ?? '', 'Circumstances', 6000);
        $containment = clean_text($input['containment'] ?? '', 'Containment', 6000);
        $risk = clean_text($input['riskAssessment'] ?? '', 'Risk assessment', 6000);
        $rrsh = strtolower(trim((string)($input['realRiskSignificantHarm'] ?? 'unassessed')));
        if (!in_array($rrsh, TEGH_BREACH_RISK_STATES, true)) fail('Choose a valid significant-harm assessment.', 422, 'privacy_risk_state_invalid');
        $status = strtolower(trim((string)($input['status'] ?? 'open')));
        if (!in_array($status, TEGH_BREACH_STATUSES, true)) fail('Choose a valid incident status.', 422, 'privacy_breach_status_invalid');
        db()->prepare('INSERT INTO privacy_breach_register (id,title,discovered_at,occurred_from,occurred_to,data_categories_json,affected_count,circumstances,containment,risk_assessment,real_risk_significant_harm,status,regulator_reported_at,individuals_notified_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$title,$discovered,$from,$to,json_encode($categories,JSON_THROW_ON_ERROR),$count,$circumstances,$containment,$risk,$rrsh,$status,privacy_optional_datetime($input['regulatorReportedAt']??null,'Regulator report time'),privacy_optional_datetime($input['individualsNotifiedAt']??null,'Individual notification time'),(string)$user['id'],(string)$user['id']]);
        platform_audit_event($user, 'privacy.breach_record_created', 'privacy_breach', $id, ['status'=>$status,'riskState'=>$rrsh]);
        json_response(['id'=>$id,'created'=>true], 201);
    }

    if ($action === 'retention/preview') {
        require_method('GET'); privacy_operations_platform_owner();
        json_response(['enabled'=>false,'mode'=>'preview_only','legalHoldRequired'=>true,'scheduleVersion'=>'proposal-2026-10-21','message'=>'No automatic deletion job is enabled. Review and approve the retention schedule before implementing destructive actions.']);
    }

    fail('Privacy operations route not found.', 404, 'privacy_route_not_found');
}
