<?php

require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/BaseApi.php';
define('SKIP_TENANT_CONTRACT_ENTRY', true);
require_once __DIR__ . '/TenantContractApi.php';

Config::load();

const BLOCKING_LIST_DEFAULT_END = '2030-12-31';
const BLOCKING_LIST_ENTITY_ID = 'PROPERTY_1626';
const BLOCKING_LIST_TYPE = 'PROPERTY_1624';
const BLOCKING_LIST_START = 'PROPERTY_1630';
const BLOCKING_LIST_END = 'PROPERTY_1632';
const BLOCKING_LIST_REASON = 'PROPERTY_1636';
const BLOCKING_LIST_STATUS = 'PROPERTY_1628';
const BLOCKING_LIST_EVENT_TYPE_BLOCKING = 'Блокировка';

function normalizeBitrixEntityId($value) {
    if ($value === null || $value === '') {
        return null;
    }
    $s = trim((string) $value);
    if (preg_match('/^T\d+_(\d+)$/', $s, $m)) {
        return (int) $m[1];
    }
    return is_numeric($s) ? (int) $s : null;
}

function firstListPropValue(array $element, $key) {
    $prop = $element[$key] ?? null;
    if (!is_array($prop) || $prop === []) {
        return null;
    }
    $v = reset($prop);
    return $v === false ? null : (string) $v;
}

function normalizeListDate($value) {
    if ($value === null || trim($value) === '') {
        return null;
    }
    $dt = date_create_from_format('d.m.Y', trim($value)) ?: date_create(trim($value));
    return $dt ? $dt->format('Y-m-d') : null;
}

function syncBlockingByListElement($projectName, array $element, array &$debugPayload, $debugOnly = false) {
    $mapping = ProjectMapping::getFieldMapping($projectName);
    $elementId = (string) ($element['ID'] ?? '');
    $typeOfSmart = firstListPropValue($element, BLOCKING_LIST_TYPE);
    $entityId = firstListPropValue($element, BLOCKING_LIST_ENTITY_ID);
    $startDate = normalizeListDate(firstListPropValue($element, BLOCKING_LIST_START));
    $status = firstListPropValue($element, BLOCKING_LIST_STATUS);
    $statusCanceled = $mapping['blocking_list_status_canceled'] ?? null;

    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $apiKey = Config::get('ALMA_API_KEY');
    $apiUrl = Config::get('ALMA_API_URL');
    $tenantContractApi = new TenantContractApi($apiKey, $apiUrl, $projectName, $projectConfig['webhook_url']);

    $externalId = $elementId;
    $isCanceled = $statusCanceled !== null && $status === $statusCanceled;

    if ($isCanceled) {
        if ($tenantContractApi->getContractByExternalId($externalId) !== null) {
            $result = $tenantContractApi->archiveContractByExternalId($externalId);
            Logger::info('Blocking contract archived', ['external_id' => $externalId], 'blocking', $elementId);
            return $result;
        }
        return ['code' => 200, 'response' => [], 'method' => 'NONE'];
    }

    $eventType = trim((string) $status);
    if ($eventType !== BLOCKING_LIST_EVENT_TYPE_BLOCKING) {
        Logger::info('Blocking sync skipped: event type is not Блокировка', ['event_type' => $eventType, 'list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 200, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }

    if (strtolower(trim((string) $typeOfSmart)) !== 'apartment') {
        Logger::info('Blocking sync skipped: type of smart is not Apartment', ['type' => $typeOfSmart, 'list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 200, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }

    if ($startDate === null) {
        return ['code' => 200, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }

    $reasonText = trim((string) (firstListPropValue($element, BLOCKING_LIST_REASON) ?? ''));
    $reasonClientIds = $mapping['blocking_list_reason_alma_client_ids'] ?? [];
    $almaClientId = isset($reasonClientIds[$reasonText]) ? (int) $reasonClientIds[$reasonText] : null;
    if ($almaClientId === null || $almaClientId <= 0) {
        Logger::warning('Blocking sync skipped: no Alma client for reason', ['reason' => $reasonText, 'list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true, 'reason' => 'no Alma client'], 'method' => 'NONE'];
    }

    $endDate = normalizeListDate(firstListPropValue($element, BLOCKING_LIST_END)) ?? BLOCKING_LIST_DEFAULT_END;
    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);

    $entityIdRaw = firstListPropValue($element, BLOCKING_LIST_ENTITY_ID);
    $entityId = $entityIdRaw !== null && $entityIdRaw !== '' ? (string) (normalizeBitrixEntityId($entityIdRaw) ?? $entityIdRaw) : '';
    if ($entityId === '') {
        Logger::warning('Blocking sync skipped: no apartment in list element', ['list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    $unitId = $tenantContractApi->findAlmaUnitIdByApartmentExternalId($entityId);
    $syncData = [
        'id' => $externalId,
        'title' => 'Blocking',
        'unit_id' => $unitId,
        'client_id' => $almaClientId,
        'opportunity' => 0,
        $startDateField => $startDate,
        $endDateField => $endDate,
        $contractTypeField => '0000',
    ];

    Logger::info('Blocking sync payload', ['external_id' => $externalId, 'entity_id' => $entityId], 'blocking', $elementId);
    $debugPayload = $syncData;
    if ($debugOnly) {
        return ['code' => 200, 'response' => $syncData, 'method' => 'DEBUG'];
    }
    try {
        $GLOBALS['_blocking_last_sync_payload'] = [
            'sync_data' => $syncData,
            'unit_external_id_from_bitrix' => $entityId ?? '',
            'fields_used' => [
                'id' => 'element ID (list 300)',
                'unit_external_id' => 'PROPERTY_1626 (Объект Апарт/Юнит), only when type=Apartment',
                'client_id' => 'from blocking_list_reason_alma_client_ids by reason text PROPERTY_1636',
                'start_date' => 'PROPERTY_1630 normalized to Y-m-d',
                'end_date' => 'PROPERTY_1632 or default 2030-12-31',
                'contract_type' => '0000 → mapContractType → Blocking',
            ],
        ];
        $result = $tenantContractApi->createOrUpdateBlockingContract($syncData);
        Logger::info('Blocking contract synced', ['external_id' => $externalId], 'blocking', $elementId);
        return $result;
    } catch (Throwable $e) {
        Logger::error('Blocking sync failed', ['list_id' => $elementId, 'error' => $e->getMessage(), 'payload' => $syncData], 'blocking', $elementId);
        throw $e;
    }
}

function syncBlockingById($listElementId, $projectName, $debugOnly = false) {
    Config::load();
    Logger::setProject($projectName);
    $listElementId = trim($listElementId);
    if ($listElementId === '') {
        throw new InvalidArgumentException('id is required (list 300 element ID)');
    }
    $mapping = ProjectMapping::getFieldMapping($projectName);
    $listIblockId = isset($mapping['blocking_list_iblock_id']) ? (int) $mapping['blocking_list_iblock_id'] : 0;
    if ($listIblockId <= 0) {
        throw new RuntimeException('Blocking list not configured');
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
    $debugPayload = [];
    return syncBlockingByListElement($projectName, $element, $debugPayload, $debugOnly);
}

if (isset($_REQUEST['id'])) {
    header('Content-Type: application/json');
    $projectName = $_REQUEST['project'] ?? 'Dubai';
    $debugOnly = isset($_REQUEST['debug']);
    try {
        BaseApi::sendSuccessResponse(syncBlockingById(trim((string) $_REQUEST['id']), $projectName, $debugOnly));
    } catch (Throwable $e) {
        $extra = [];
        if (isset($GLOBALS['_blocking_last_sync_payload'])) {
            $extra['payload'] = $GLOBALS['_blocking_last_sync_payload'];
            unset($GLOBALS['_blocking_last_sync_payload']);
        }
        BaseApi::sendErrorResponse($e, 'Blocking sync failed', 'blocking', $_REQUEST['id'] ?? null, $extra);
    }
    exit;
}
