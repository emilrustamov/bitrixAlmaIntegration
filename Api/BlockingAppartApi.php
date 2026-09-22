<?php

require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/SyncHelpers.php';
require_once __DIR__ . '/TenantContractApi.php';

Config::load();

const BLOCKING_LIST_DEFAULT_END = '2030-12-31';
const BLOCKING_LIST_EVENT_TYPE_BLOCKING = 'Блокировка';

/**
 * @param string $projectName
 * @param array $element
 * @param bool $debugOnly
 * @return array
 */
function syncBlockingByListElement($projectName, array $element, $debugOnly = false) {
    $mapping = ProjectMapping::getFieldMapping($projectName);
    $elementId = (string) ($element['ID'] ?? '');
    $typeOfSmart = firstListPropValue($element, LIST_PROP_TYPE);
    $startDate = normalizeListDate(firstListPropValue($element, LIST_PROP_START));
    $status = firstListPropValue($element, LIST_PROP_STATUS);

    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $apiKey = Config::get('ALMA_API_KEY');
    $apiUrl = Config::get('ALMA_API_URL');
    $tenantContractApi = new TenantContractApi($apiKey, $apiUrl, $projectName, $projectConfig['webhook_url']);

    $externalId = $elementId;

    if (isListElementInactive($element, $projectName)) {
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

    $reasonText = trim((string) (firstListPropValue($element, LIST_PROP_REASON) ?? ''));
    $reasonClientIds = $mapping['blocking_list_reason_alma_client_ids'] ?? [];
    $almaClientId = isset($reasonClientIds[$reasonText]) ? (int) $reasonClientIds[$reasonText] : null;
    if ($almaClientId === null || $almaClientId <= 0) {
        Logger::warning('Blocking sync skipped: no Alma client for reason', ['reason' => $reasonText, 'list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true, 'reason' => 'no Alma client'], 'method' => 'NONE'];
    }

    $endDate = normalizeListDate(firstListPropValue($element, LIST_PROP_END)) ?? BLOCKING_LIST_DEFAULT_END;

    $entityIdRaw = firstListPropValue($element, LIST_PROP_ENTITY_ID);
    $entityId = $entityIdRaw !== null && $entityIdRaw !== '' ? (string) (normalizeBitrixEntityId($entityIdRaw) ?? $entityIdRaw) : '';
    if ($entityId === '') {
        Logger::warning('Blocking sync skipped: no apartment in list element', ['list_id' => $elementId], 'blocking', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    $unitId = $tenantContractApi->findUnitIdByAptExternalId($entityId);
    $syncData = buildSyntheticContractPayload(
        $projectName,
        $externalId,
        'Blocking',
        $unitId,
        $almaClientId,
        $startDate,
        $endDate,
        '0000'
    );

    Logger::info('Blocking sync payload', ['external_id' => $externalId, 'entity_id' => $entityId], 'blocking', $elementId);
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
        $result = $tenantContractApi->upsertSyntheticContract($syncData);
        Logger::info('Blocking contract synced', ['external_id' => $externalId], 'blocking', $elementId);
        return $result;
    } catch (Throwable $e) {
        Logger::error('Blocking sync failed', ['list_id' => $elementId, 'error' => $e->getMessage(), 'payload' => $syncData], 'blocking', $elementId);
        throw $e;
    }
}

/**
 * @param string|int $listElementId
 * @param string $projectName
 * @param bool $debugOnly
 * @return array
 */
function syncBlocking($listElementId, $projectName, $debugOnly = false) {
    Config::load();
    Logger::setProject($projectName);
    $listElementId = trim((string) $listElementId);
    if ($listElementId === '') {
        throw new InvalidArgumentException('id is required (list 300 element ID)');
    }
    $element = fetchListElement($projectName, $listElementId);
    return syncBlockingByListElement($projectName, $element, $debugOnly);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_REQUEST['id'])) {
    header('Content-Type: application/json');
    $projectName = $_REQUEST['project'] ?? 'Dubai';
    $debugOnly = isset($_REQUEST['debug']);
    try {
        BaseApi::sendSuccessResponse(syncBlocking(trim((string) $_REQUEST['id']), $projectName, $debugOnly));
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
