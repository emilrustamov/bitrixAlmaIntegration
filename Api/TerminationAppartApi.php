<?php

require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/ListSyncHelpers.php';
define('SKIP_TENANT_CONTRACT_ENTRY', true);
require_once __DIR__ . '/TenantContractApi.php';

Config::load();

const TERMINATION_LIST_ENTITY_ID = 'PROPERTY_1626';
const TERMINATION_LIST_STATUS = 'PROPERTY_1628';

function syncTerminationForApt($projectName, $apartmentBitrixId, array $apartmentData) {
    $dateField = ProjectMapping::getApartmentField('date_of_refused', $projectName);
    if ($dateField === null) {
        return;
    }
    $raw = $apartmentData[$dateField] ?? null;
    if ($raw === null || trim((string) $raw) === '') {
        $projectConfig = ProjectMapping::getProjectConfig($projectName);
        $api = new TenantContractApi(
            Config::get('ALMA_API_KEY'),
            Config::get('ALMA_API_URL'),
            $projectName,
            $projectConfig['webhook_url']
        );
        $externalId = 'termination_' . $apartmentBitrixId;
        $existing = $api->getContractByExternalId($externalId);
        if ($existing !== null) {
            $api->archiveContractByExternalId($externalId);
            Logger::info('Termination contract archived (no date)', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
        }
        return;
    }
    $dt = date_create_from_format('Y-m-d', trim($raw)) ?: (date_create_from_format('d.m.Y', trim($raw)) ?: date_create(trim($raw)));
    if (!$dt) {
        return;
    }
    $startDate = $dt->format('Y-m-d');
    $endDate = (clone $dt)->modify('+5 days')->format('Y-m-d');
    $clientId = ProjectMapping::getTerminationAlmaClientId($projectName);
    if ($clientId === null || $clientId <= 0) {
        Logger::warning('Termination sync skipped: termination_alma_client_id not set', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
        return;
    }
    try {
        $almaUnitId = BaseApi::getUnitIdByAptExternalId(Config::get('ALMA_API_KEY'), Config::get('ALMA_API_URL'), $apartmentBitrixId);
    } catch (Throwable $e) {
        Logger::warning('Termination sync skipped: apartment not in Alma', ['apartment_id' => $apartmentBitrixId, 'error' => $e->getMessage()], 'termination', $apartmentBitrixId);
        return;
    }
    $contractTypeId = ProjectMapping::getTerminationContractTypeId($projectName);
    if ($contractTypeId === null) {
        Logger::warning('Termination sync skipped: Termination contract type not mapped', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
        return;
    }
    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
    $syncData = [
        'id' => 'termination_' . $apartmentBitrixId,
        'title' => 'Termination',
        'unit_id' => $almaUnitId,
        'client_id' => $clientId,
        'opportunity' => 0,
        $startDateField => $startDate,
        $endDateField => $endDate,
        $contractTypeField => $contractTypeId,
    ];
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $api = new TenantContractApi(
        Config::get('ALMA_API_KEY'),
        Config::get('ALMA_API_URL'),
        $projectName,
        $projectConfig['webhook_url']
    );
    $api->upsertBlockingContract($syncData);
    Logger::info('Termination contract synced', ['apartment_id' => $apartmentBitrixId, 'start_date' => $startDate, 'end_date' => $endDate], 'termination', $apartmentBitrixId);
}

function syncTerminationElement($projectName, array $element) {
    $elementId = (string) ($element['ID'] ?? '');
    $eventType = trim((string) firstListPropValue($element, TERMINATION_LIST_STATUS));
    $expectedEventType = ProjectMapping::getTerminationListEventType($projectName);
    if ($eventType !== $expectedEventType) {
        Logger::info('Termination sync skipped: event type is not ' . $expectedEventType, ['event_type' => $eventType, 'list_id' => $elementId], 'termination', $elementId);
        return ['code' => 200, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    $apartmentBitrixId = firstListPropValue($element, TERMINATION_LIST_ENTITY_ID);
    if ($apartmentBitrixId === null || $apartmentBitrixId === '') {
        Logger::warning('Termination sync skipped: no apartment in list element', ['list_id' => $elementId], 'termination', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    $apartmentIdForApi = normalizeBitrixEntityId($apartmentBitrixId);
    if ($apartmentIdForApi === null) {
        Logger::warning('Termination sync skipped: invalid apartment id in list', ['list_id' => $elementId, 'value' => $apartmentBitrixId], 'termination', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $entityTypeId = ProjectMapping::getApartmentEntityTypeIds($projectName)[0];
    $bitrix = new Bitrix24Rest($projectConfig['webhook_url']);
    $res = $bitrix->call('crm.item.get', [
        'entityTypeId' => (int) $entityTypeId,
        'id' => $apartmentIdForApi,
    ]);
    $apartmentData = $res['result']['item'] ?? null;
    if ($apartmentData === null) {
        throw new RuntimeException('Apartment not found: ' . $apartmentBitrixId);
    }
    syncTerminationForApt($projectName, (string) $apartmentIdForApi, $apartmentData);
    return ['code' => 200, 'response' => [], 'method' => 'NONE'];
}

function syncTermination($listElementId, $projectName) {
    Config::load();
    Logger::setProject($projectName);
    $listElementId = trim($listElementId);
    if ($listElementId === '') {
        throw new InvalidArgumentException('id is required (list element ID)');
    }
    $mapping = ProjectMapping::getFieldMapping($projectName);
    $listIblockId = isset($mapping['blocking_list_iblock_id']) ? (int) $mapping['blocking_list_iblock_id'] : 0;
    if ($listIblockId <= 0) {
        throw new RuntimeException('Termination list not configured');
    }
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $bitrix = new Bitrix24Rest($projectConfig['webhook_url']);
    $elRes = $bitrix->call('lists.element.get', [
        'IBLOCK_TYPE_ID' => 'lists',
        'IBLOCK_ID' => $listIblockId,
        'ELEMENT_ID' => $listElementId,
    ]);
    $raw = $elRes['result'] ?? [];
    $element = isset($raw['ID']) ? $raw : (is_array($raw) && isset($raw[0]) ? $raw[0] : null);
    if (empty($element)) {
        throw new RuntimeException('List element not found');
    }
    return syncTerminationElement($projectName, $element);
}

if (isset($_REQUEST['id']) && !defined('TERMINATION_SKIP_ENTRY')) {
    header('Content-Type: application/json');
    $projectName = $_REQUEST['project'] ?? 'Dubai';
    try {
        $result = syncTermination(trim((string) $_REQUEST['id']), $projectName);
        BaseApi::sendSuccessResponse($result);
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Termination sync failed', 'termination', $_REQUEST['id'] ?? null);
    }
    exit;
}
