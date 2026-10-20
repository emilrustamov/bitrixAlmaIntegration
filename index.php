<?php
require_once __DIR__ . '/Core/Logger.php';
require_once __DIR__ . '/Core/Config/Config.php';
require_once __DIR__ . '/Core/Config/ProjectMapping.php';

Config::load();

$isWebhookRequest = (isset($_REQUEST['data']['FIELDS']['ID']) &&
                   isset($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'])) ||
                   isset($_REQUEST['event']);

if ($isWebhookRequest) {
    $eventDealAdd = Config::get('BITRIX_EVENT_DEAL_ADD', 'ONCRMDEALADD');
    $eventDealUpdate = Config::get('BITRIX_EVENT_DEAL_UPDATE', 'ONCRMDEALUPDATE');
    $eventDynamicUpdate = Config::get('BITRIX_EVENT_DYNAMIC_UPDATE', 'ONCRMDYNAMICITEMUPDATE');
    $eventContactUpdate = Config::get('BITRIX_EVENT_CONTACT_UPDATE', 'ONCRMCONTACTUPDATE');

    $eventName = $_REQUEST['event'] ?? null;
    [$id, $typeId] = match ($eventName) {
        $eventDealAdd => [$_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['data']['ID'] ?? null, 2],
        $eventDealUpdate => [$_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['data']['ID'] ?? null, 2],
        $eventDynamicUpdate => [$_REQUEST['data']['FIELDS']['ID'] ?? null, $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? null],
        $eventContactUpdate => [$_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['data']['ID'] ?? null, 3],
        default => [$_REQUEST['data']['FIELDS']['ID'] ?? null, $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? null]
    };

    if (!$id) {
        Logger::warning('Webhook: missing entity ID', ['request' => array_diff_key($_REQUEST, ['data' => 1])], 'webhook', null);
        exit;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hkDomain = Config::get('PROJECT_HONGKONG_DOMAIN', 'colifepacific');
    $isDealWithType2 = ($eventName === $eventDealAdd || $eventName === $eventDealUpdate) && (int)$typeId === 2;
    $hkTypeIds = array_merge(
        ProjectMapping::getApartmentEntityTypeIds('HongKong'),
        [
            ProjectMapping::getUnitEntityTypeId('HongKong'),
            ProjectMapping::getContractEntityTypeId('HongKong'),
        ]
    );
    $projectName = ($hkDomain && str_contains($host, $hkDomain)) || $isDealWithType2 || in_array((int)$typeId, $hkTypeIds, true)
        ? Config::get('PROJECT_HONGKONG_NAME', 'HongKong')
        : Config::get('PROJECT_DEFAULT_NAME', 'Dubai');

    Logger::setProject($projectName);

    $aptTypeIds = ProjectMapping::getApartmentEntityTypeIds($projectName);
    if (in_array((int)$typeId, $aptTypeIds, true)) {
        require_once __DIR__ . '/Api/AppartApi.php';
        try {
            syncApartment($id, $projectName, (int)$typeId);
        } catch (Throwable $e) {
            Logger::error('Webhook: apartment sync failed', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'apartment', $id);
        }
        exit;
    }

    $unitTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
    if ($projectName === 'Dubai' && (int)$typeId === $unitTypeId) {
        exit;
    }
    if ($projectName === 'HongKong' && (int)$typeId === $unitTypeId) {
        require_once __DIR__ . '/Api/UnitApi.php';
        try {
            syncUnitById($id, $projectName);
        } catch (Throwable $e) {
            Logger::error('Webhook: unit sync failed', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'room', $id);
        }
        exit;
    }

    if ((int)$typeId === 3 || $eventName === $eventContactUpdate) {
        require_once __DIR__ . '/Api/ContactSync.php';
        try {
            syncContactIfExists($id, $projectName);
        } catch (Throwable $e) {
            Logger::error('Webhook: contact sync failed', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'contact', $id);
        }
        exit;
    }

    $contractTypeId = ProjectMapping::getContractEntityTypeId($projectName);
    if ((int)$typeId === (int)$contractTypeId) {
        require_once __DIR__ . '/Api/TenantContractApi.php';
        try {
            syncTenantContract($id, $projectName, ['skip_if_integration_update' => true]);
        } catch (Throwable $e) {
            Logger::error('Webhook: tenant contract sync failed', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'contract', $id);
        }
        exit;
    }

    $llTypeId = ProjectMapping::getLandlordContractEntityTypeId($projectName);
    if ($llTypeId && (int)$typeId === (int)$llTypeId) {
        require_once __DIR__ . '/Api/LandlordContractApi.php';
        try {
            syncLandlordContract($id, $projectName, ['skip_if_integration_update' => true]);
        } catch (Throwable $e) {
            Logger::error('Webhook: landlord contract sync failed', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'landlord_contract', $id);
        }
        exit;
    }
}
