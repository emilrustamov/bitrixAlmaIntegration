<?php

require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/SyncHelpers.php';
require_once __DIR__ . '/TenantContractApi.php';

Config::load();

/**
 * @param string $projectName
 * @param string $apartmentBitrixId
 * @param array $apartmentData
 * @return void
 */
function syncTerminationForApt($projectName, $apartmentBitrixId, array $apartmentData) {
    $dateField = ProjectMapping::getApartmentField('date_of_refused', $projectName);
    if ($dateField === null) {
        return;
    }

    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $api = new TenantContractApi(
        Config::get('ALMA_API_KEY'),
        Config::get('ALMA_API_URL'),
        $projectName,
        $projectConfig['webhook_url']
    );

    $raw = $apartmentData[$dateField] ?? null;
    if ($raw === null || trim((string) $raw) === '') {
        $externalId = 'termination_' . $apartmentBitrixId;
        $existing = $api->getContractByExternalId($externalId);
        if ($existing !== null) {
            $wasArchived = isAlmaContractArchived($existing);
            try {
                $api->archiveContractByExternalId($externalId);
            } catch (Throwable $e) {
                addApartmentAlmaSyncErrorComment($projectName, $apartmentBitrixId, $e->getMessage());
                throw $e;
            }
            Logger::info('Termination contract archived (no date)', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
            if (!$wasArchived) {
                try {
                    addApartmentAlmaTimelineComment(
                        $projectName,
                        $apartmentBitrixId,
                        formatAlmaTerminationCancelledForBitrixComment(
                            $existing['unit_usage']['unit_id'] ?? null,
                            $existing['start_date'] ?? null,
                            $existing['end_date'] ?? null
                        )
                    );
                } catch (Throwable $commentError) {
                    Logger::warning('Failed to add Bitrix termination-cancelled comment on apartment', [
                        'apartment_id' => $apartmentBitrixId,
                        'error' => $commentError->getMessage(),
                    ], 'termination', $apartmentBitrixId);
                }
            }
        }
        return;
    }

    $startDate = normalizeListDate($raw);
    if ($startDate === null) {
        return;
    }
    $endDate = (new DateTime($startDate))->modify('+5 days')->format('Y-m-d');
    $clientId = ProjectMapping::getTerminationAlmaClientId($projectName);
    if ($clientId === null || $clientId <= 0) {
        Logger::warning('Termination sync skipped: termination_alma_client_id not set', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
        return;
    }
    $almaUnitId = null;
    try {
        $almaUnitId = BaseApi::getUnitIdByAptExternalId(Config::get('ALMA_API_KEY'), Config::get('ALMA_API_URL'), $apartmentBitrixId);
    } catch (Throwable $e) {
        Logger::warning('Termination sync skipped: apartment not in Alma', ['apartment_id' => $apartmentBitrixId, 'error' => $e->getMessage()], 'termination', $apartmentBitrixId);
        addApartmentAlmaSyncErrorComment($projectName, $apartmentBitrixId, $e->getMessage());
        return;
    }
    $contractTypeId = ProjectMapping::getTerminationContractTypeId($projectName);
    if ($contractTypeId === null) {
        Logger::warning('Termination sync skipped: Termination contract type not mapped', ['apartment_id' => $apartmentBitrixId], 'termination', $apartmentBitrixId);
        return;
    }
    $syncData = buildSyntheticContractPayload(
        $projectName,
        'termination_' . $apartmentBitrixId,
        'Termination',
        $almaUnitId,
        $clientId,
        $startDate,
        $endDate,
        $contractTypeId
    );
    try {
        $result = $api->upsertSyntheticContract($syncData);
    } catch (Throwable $e) {
        Logger::error('Termination sync failed', [
            'apartment_id' => $apartmentBitrixId,
            'error' => $e->getMessage(),
        ], 'termination', $apartmentBitrixId);
        addApartmentAlmaSyncErrorComment($projectName, $apartmentBitrixId, $e->getMessage(), $almaUnitId);
        throw $e;
    }
    Logger::info('Termination contract synced', ['apartment_id' => $apartmentBitrixId, 'start_date' => $startDate, 'end_date' => $endDate], 'termination', $apartmentBitrixId);
    if (($result['method'] ?? '') === 'POST') {
        try {
            addApartmentAlmaTimelineComment(
                $projectName,
                $apartmentBitrixId,
                formatAlmaTerminationAddedForBitrixComment($almaUnitId, $startDate, $endDate)
            );
        } catch (Throwable $commentError) {
            Logger::warning('Failed to add Bitrix termination-added comment on apartment', [
                'apartment_id' => $apartmentBitrixId,
                'error' => $commentError->getMessage(),
            ], 'termination', $apartmentBitrixId);
        }
    }
}

/**
 * @param string $projectName
 * @param array $element
 * @return array
 */
function syncTerminationElement($projectName, array $element) {
    $elementId = (string) ($element['ID'] ?? '');
    $eventType = trim((string) firstListPropValue($element, LIST_PROP_STATUS));
    $expectedEventType = ProjectMapping::getTerminationListEventType($projectName);
    $apartmentBitrixId = firstListPropValue($element, LIST_PROP_ENTITY_ID);
    $apartmentIdForApi = $apartmentBitrixId !== null && $apartmentBitrixId !== ''
        ? normalizeBitrixEntityId($apartmentBitrixId)
        : null;

    if (isListElementInactive($element, $projectName)) {
        if ($apartmentIdForApi === null) {
            Logger::warning('Termination archive skipped: no apartment in list element', ['list_id' => $elementId], 'termination', $elementId);
            return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
        }
        $projectConfig = ProjectMapping::getProjectConfig($projectName);
        $api = new TenantContractApi(
            Config::get('ALMA_API_KEY'),
            Config::get('ALMA_API_URL'),
            $projectName,
            $projectConfig['webhook_url']
        );
        $externalId = 'termination_' . $apartmentIdForApi;
        $existing = $api->getContractByExternalId($externalId);
        if ($existing !== null) {
            $wasArchived = isAlmaContractArchived($existing);
            try {
                $result = $api->archiveContractByExternalId($externalId);
            } catch (Throwable $e) {
                addApartmentAlmaSyncErrorComment($projectName, $apartmentIdForApi, $e->getMessage());
                throw $e;
            }
            Logger::info('Termination contract archived (inactive status)', ['external_id' => $externalId], 'termination', $elementId);
            if (!$wasArchived) {
                try {
                    addApartmentAlmaTimelineComment(
                        $projectName,
                        $apartmentIdForApi,
                        formatAlmaTerminationCancelledForBitrixComment(
                            $existing['unit_usage']['unit_id'] ?? null,
                            $existing['start_date'] ?? null,
                            $existing['end_date'] ?? null
                        )
                    );
                } catch (Throwable $commentError) {
                    Logger::warning('Failed to add Bitrix termination-cancelled comment on apartment', [
                        'apartment_id' => $apartmentIdForApi,
                        'list_id' => $elementId,
                        'error' => $commentError->getMessage(),
                    ], 'termination', $elementId);
                }
            }
            return $result;
        }
        return ['code' => 200, 'response' => [], 'method' => 'NONE'];
    }

    if ($eventType !== $expectedEventType) {
        Logger::info('Termination sync skipped: event type is not ' . $expectedEventType, ['event_type' => $eventType, 'list_id' => $elementId], 'termination', $elementId);
        return ['code' => 200, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
    if ($apartmentBitrixId === null || $apartmentBitrixId === '') {
        Logger::warning('Termination sync skipped: no apartment in list element', ['list_id' => $elementId], 'termination', $elementId);
        return ['code' => 400, 'response' => ['skipped' => true], 'method' => 'NONE'];
    }
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

/**
 * @param string|int $listElementId
 * @param string $projectName
 * @return array
 */
function syncTermination($listElementId, $projectName) {
    Config::load();
    Logger::setProject($projectName);
    $listElementId = trim((string) $listElementId);
    if ($listElementId === '') {
        throw new InvalidArgumentException('id is required (list element ID)');
    }
    $element = fetchListElement($projectName, $listElementId);
    return syncTerminationElement($projectName, $element);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_REQUEST['id'])) {
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
