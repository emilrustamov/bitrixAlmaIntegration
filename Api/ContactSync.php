<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/SyncHelpers.php';

/**
 * @param string|int $id
 * @param string $projectName
 * @return array|null
 */
function syncContactIfExists($id, $projectName)
{
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $bitrix = new Bitrix24Rest($projectConfig['webhook_url']);
    $clientApi = new ClientApi(Config::get('ALMA_API_KEY'), Config::get('ALMA_API_URL'));

    $res = $bitrix->call('crm.contact.get', ['id' => $id]);
    $contact = $res['result'] ?? null;
    if (!is_array($contact)) {
        throw new RuntimeException('Failed to get contact data from Bitrix24');
    }

    $emailField = ProjectMapping::getTenantField('email', $projectName)
        ?: ProjectMapping::getLandlordField('email', $projectName);
    $clientData = mapBitrixContactToClientData($contact, $emailField, $projectName);

    $almaId = $clientApi->updateExistingClient($clientData);
    if ($almaId === null) {
        Logger::info('Contact skipped: not found in Alma', [
            'contact_id' => $id,
            'project' => $projectName,
        ], 'contact', $id);
        return null;
    }

    Logger::info('Contact updated in Alma', [
        'contact_id' => $id,
        'alma_id' => $almaId,
        'project' => $projectName,
    ], 'contact', $id);

    return [
        'code' => 200,
        'response' => ['id' => $almaId],
        'method' => 'PATCH',
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        $result = syncContactIfExists($_GET['id'], $projectName);
        if ($result === null) {
            BaseApi::sendSuccessResponse(['skipped' => true, 'reason' => 'client_not_in_alma']);
        } else {
            BaseApi::sendSuccessResponse($result);
        }
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: contact synchronization failed', 'contact', $_GET['id'] ?? null);
    }
}
