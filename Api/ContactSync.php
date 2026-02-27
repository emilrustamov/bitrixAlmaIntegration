<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';
require_once __DIR__ . '/BaseApi.php';

function syncContactById($id, $projectName, $contactType = 'tenant') {
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $apiKey = Config::get('ALMA_API_KEY');
    $apiUrl = Config::get('ALMA_API_URL');
    $bitrix = new Bitrix24Rest($webhookUrl);
    $clientApi = new ClientApi($apiKey, $apiUrl);
    $res = $bitrix->call('crm.contact.get', ['id' => $id]);
    if (!isset($res['result'])) {
        throw new RuntimeException('Failed to get contact data from Bitrix24');
    }
    $contact = $res['result'];
    $email = $contactType === 'landlord'
        ? ($contact[ProjectMapping::getLandlordField('email', $projectName)] ?? '')
        : '';
    if ($email === '' && !empty($contact['EMAIL'])) {
        $email = is_array($contact['EMAIL']) ? ($contact['EMAIL'][0]['VALUE'] ?? '') : (string)$contact['EMAIL'];
    }
    $clientData = [
        'id' => $contact['ID'],
        'first_name' => $contact['NAME'],
        'last_name' => $contact['LAST_NAME'],
        'email' => $email,
        'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
    ];
    $almaClientId = $clientApi->updateClient($clientData);
    return [
        'code' => 200,
        'response' => ['id' => $almaClientId],
        'method' => 'PATCH'
    ];
}

if (isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    $type = $_GET['type'] ?? 'tenant';
    if (!in_array($type, ['tenant', 'landlord'], true)) {
        $type = 'tenant';
    }
    $typeLabel = $type === 'landlord' ? 'Landlord' : 'Tenant';
    try {
        BaseApi::sendSuccessResponse(syncContactById($_GET['id'], $projectName, $type));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, "Unexpected error: {$typeLabel} contact synchronization failed", 'contact', $_GET['id'] ?? null);
    }
    exit;
}
