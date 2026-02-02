<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';

Config::load();

$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
Logger::setProject($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('WEBHOOK_URL', $projectConfig['webhook_url']);

try {
    $contactId = $_GET['id'] ?? null;
    if (!$contactId) {
        throw new InvalidArgumentException('Contact ID is required');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $clientApi = new ClientApi(ALMA_API_KEY, ALMA_API_URL);

    $contactResponse = $bitrix->call('crm.contact.get', ['id' => $contactId]);
    
    if (!isset($contactResponse['result'])) {
        throw new RuntimeException('Failed to get contact data from Bitrix24');
    }

    $contact = $contactResponse['result'];
    $emailField = ProjectMapping::getLandlordField('email', $projectName);
    $email = $contact[$emailField] ?? '';
    if (empty($email)) {
        $email = is_array($contact['EMAIL']) ? ($contact['EMAIL'][0]['VALUE'] ?? '') : '';
    }

    $clientData = [
        'id' => $contact['ID'],
        'first_name' => $contact['NAME'],
        'last_name' => $contact['LAST_NAME'],
        'email' => $email,
        'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
    ];

    $almaClientId = $clientApi->updateClient($clientData);

    Logger::info('Landlord contact sync succeeded', [
        'contact_id' => $contactId,
        'alma_client_id' => $almaClientId
    ], 'contact', $contactId);

    echo json_encode([
        'success' => true,
        'alma_client_id' => $almaClientId,
        'message' => 'Contact successfully updated in Alma'
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    Logger::error("Unexpected error in landlord contact synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'contact', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Landlord contact synchronization failed'
    ]);
}
