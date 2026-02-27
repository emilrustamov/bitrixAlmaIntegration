<?php
require_once __DIR__ . '/Core/Bitrix24Rest.php';
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
        default => [$_REQUEST['data']['FIELDS']['ID'] ?? null, $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? null]
    };

    if (!$id) {
        Logger::warning('Webhook: missing entity ID', ['request' => array_diff_key($_REQUEST, ['data' => 1])], 'webhook', null);
        exit;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hkDomain = Config::get('PROJECT_HONGKONG_DOMAIN', 'colifepacific');
    $isDealWithType2 = ($eventName === $eventDealAdd || $eventName === $eventDealUpdate) && (int)$typeId === 2;
    $projectName = ($hkDomain && str_contains($host, $hkDomain)) || $isDealWithType2
        ? Config::get('PROJECT_HONGKONG_NAME', 'HongKong')
        : Config::get('PROJECT_DEFAULT_NAME', 'Dubai');

    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $bitrix = new Bitrix24Rest($webhookUrl);

    $aptTypeIds = ProjectMapping::getApartmentEntityTypeIds($projectName);
    if (in_array((int)$typeId, $aptTypeIds, true)) {
        try {
            $bitrix->call('crm.item.get', [
                'entityTypeId' => (int)$typeId,
                'id' => $id
            ]);
        } catch (Exception $e) {
            Logger::warning('Webhook: failed to get apartment from Bitrix', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'apartment', $id);
            exit;
        }
        require_once __DIR__ . '/Api/AppartApi.php';
        try {
            syncApartmentById($id, $projectName, (int)$typeId);
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
        try {
            $res = $bitrix->call('crm.contact.get', ['id' => $id]);
        } catch (Exception $e) {
            Logger::warning('Webhook: failed to get contact from Bitrix', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'contact', $id);
            exit;
        }
        if (isset($res['result'])) {
            $contact = $res['result'];
            $typeIdContact = $contact['TYPE_ID'] ?? '';
            $isTenant = ($typeIdContact === 'TENANT' || $typeIdContact === 'CLIENT');
            $isLandlord = ($typeIdContact === 'LANDLORD' || $typeIdContact === '1');
            if (!$isTenant && !$isLandlord) {
                try {
                    $llTypeId = ProjectMapping::getLandlordContractEntityTypeId($projectName);
                    if ($llTypeId) {
                        $contracts = $bitrix->call('crm.item.list', [
                            'entityTypeId' => $llTypeId,
                            'filter' => ['contactId' => $id],
                            'select' => ['id']
                        ]);
                        if (!empty($contracts['result']['items'] ?? [])) {
                            $isLandlord = true;
                        }
                    }
                } catch (Exception $e) {
                    Logger::warning('Webhook: failed to check landlord contracts', [
                        'contact_id' => $id,
                        'error' => $e->getMessage()
                    ], 'contact', $id);
                }
            }
            if ($isTenant || $isLandlord) {
                require_once __DIR__ . '/Api/ContactSync.php';
                try {
                    syncContactById($id, $projectName, $isTenant ? 'tenant' : 'landlord');
                } catch (Throwable $e) {
                    Logger::error('Webhook: contact sync failed', [
                        'entity_id' => $id,
                        'project' => $projectName,
                        'type' => $isTenant ? 'tenant' : 'landlord',
                        'error' => $e->getMessage()
                    ], 'contact', $id);
                }
            }
        }
        exit;
    }

    $contractTypeId = ProjectMapping::getContractEntityTypeId($projectName);
    if ((int)$typeId === (int)$contractTypeId) {
        try {
            $contract = $bitrix->call('crm.item.get', [
                'entityTypeId' => $contractTypeId,
                'id' => $id
            ]);
            if (isset($contract['result'])) {
                require_once __DIR__ . '/Api/TenantContractApi.php';
                try {
                    syncTenantContractById($id, $projectName);
                } catch (Throwable $e) {
                    Logger::error('Webhook: tenant contract sync failed', [
                        'entity_id' => $id,
                        'project' => $projectName,
                        'error' => $e->getMessage()
                    ], 'contract', $id);
                }
            } else {
                Logger::warning('Webhook: Bitrix returned no result for contract', ['contract_id' => $id, 'project' => $projectName], 'contract', $id);
            }
        } catch (Exception $e) {
            Logger::error('Webhook: contract failed before sync', [
                'contract_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ], 'contract', $id);
        }
        exit;
    }

    $llTypeId = ProjectMapping::getLandlordContractEntityTypeId($projectName);
    if ($llTypeId && (int)$typeId === (int)$llTypeId) {
        try {
            $contract = $bitrix->call('crm.item.get', [
                'entityTypeId' => $llTypeId,
                'id' => $id
            ]);
            if (isset($contract['result'])) {
                require_once __DIR__ . '/Api/LandlordContractApi.php';
                try {
                    syncLandlordContractById($id, $projectName);
                } catch (Throwable $e) {
                    Logger::error('Webhook: landlord contract sync failed', [
                        'entity_id' => $id,
                        'project' => $projectName,
                        'error' => $e->getMessage()
                    ], 'landlord_contract', $id);
                }
            }
        } catch (Exception $e) {
            Logger::warning('Webhook: failed to get landlord contract from Bitrix', [
                'entity_id' => $id,
                'project' => $projectName,
                'error' => $e->getMessage()
            ], 'landlord_contract', $id);
        }
        exit;
    }
}

