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
    $event = $_REQUEST['event'] ?? null;
    $eventDealAdd = Config::get('BITRIX_EVENT_DEAL_ADD', 'ONCRMDEALADD');
    $eventDealUpdate = Config::get('BITRIX_EVENT_DEAL_UPDATE', 'ONCRMDEALUPDATE');
    $eventDynamicUpdate = Config::get('BITRIX_EVENT_DYNAMIC_UPDATE', 'ONCRMDYNAMICITEMUPDATE');
    $eventContactUpdate = Config::get('BITRIX_EVENT_CONTACT_UPDATE', 'ONCRMCONTACTUPDATE');
    
    if (isset($_REQUEST['event']) && ($_REQUEST['event'] == $eventDealAdd || $_REQUEST['event'] == $eventDealUpdate)) {
        $idEl = $_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['data']['ID'] ?? null;
        $idItem = 2;
    } elseif (isset($_REQUEST['event']) && $_REQUEST['event'] == $eventDynamicUpdate) {
        $idEl = $_REQUEST['data']['FIELDS']['ID'] ?? null;
        $idItem = $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'] ?? null;
    } else {
        $idEl = $_REQUEST['data']['FIELDS']['ID'];
        $idItem = $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'];
    }
    
    if (!$idEl) {
        exit;
    }

    $requestDomain = $_SERVER['HTTP_HOST'] ?? '';
    $projectName = Config::get('PROJECT_DEFAULT_NAME', 'Dubai');
    
    $hongKongDomain = Config::get('PROJECT_HONGKONG_DOMAIN', 'colifepacific');
    if ($hongKongDomain && strpos($requestDomain, $hongKongDomain) !== false) {
        $projectName = Config::get('PROJECT_HONGKONG_NAME', 'HongKong');
    }
    
    if (isset($_REQUEST['event']) && ($_REQUEST['event'] == $eventDealAdd || $_REQUEST['event'] == $eventDealUpdate) && $idItem == 2) {
        $projectName = Config::get('PROJECT_HONGKONG_NAME', 'HongKong');
    }
    
    Logger::setProject($projectName);
    
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $fieldMapping = ProjectMapping::getFieldMapping($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $baseUrl = rtrim(Config::get('APP_BASE_URL'), '/') . '/';

    $bitrix = new Bitrix24Rest($webhookUrl);

    if ($idItem == $fieldMapping['entity_type_id'] || $idItem == 1048 || 
        ($_REQUEST['event'] == $eventDynamicUpdate && $idItem == $fieldMapping['entity_type_id'])) {
        $entityTypeId = ($idItem == 1048) ? 1048 : $fieldMapping['entity_type_id'];
        
        try {
            $bitrixApartment = $bitrix->call('crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $idEl
            ]);
        } catch (Exception $e) {
            exit;
        }

        $shouldSync = true;
        
        if ($shouldSync) {
            $url = $baseUrl . 'Api/AppartApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName);
            file_get_contents($url);
        }
    } elseif (($projectName === 'HongKong' && $idItem == ProjectMapping::getUnitEntityTypeId($projectName)) ||
              ($_REQUEST['event'] == $eventDynamicUpdate && $projectName === 'HongKong' && 
               $idItem == ProjectMapping::getUnitEntityTypeId($projectName))) {
        $url = $baseUrl . 'Api/UnitApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName);
        file_get_contents($url);

    } elseif ($idItem == 3 || $_REQUEST['event'] == $eventContactUpdate) {
        try {
            $bitrixContact = $bitrix->call('crm.contact.get', [
                'id' => $idEl
            ]);
        } catch (Exception $e) {
            exit;
        }

        if (isset($bitrixContact['result'])) {
            $contactData = $bitrixContact['result'];
            $contactType = $contactData['TYPE_ID'] ?? '';
            $isTenant = ($contactType === 'TENANT' || $contactType === 'CLIENT');
            $isLandlord = ($contactType === 'LANDLORD' || $contactType === '1');
            
            if (!$isTenant && !$isLandlord) {
                try {
                    $llContracts = $bitrix->call('crm.item.list', [
                        'entityTypeId' => 148,
                        'filter' => [
                            'contactId' => $idEl
                        ],
                        'select' => ['id']
                    ]);
                    
                    if (isset($llContracts['result']['items']) && !empty($llContracts['result']['items'])) {
                        $isLandlord = true;
                    }
                } catch (Exception $e) {
                }
            }
            
            if ($isTenant) {
                file_get_contents($baseUrl . 'Api/TenantApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName));
            } elseif ($isLandlord) {
                file_get_contents($baseUrl . 'Api/LandlordApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName));
            }
        }
    } elseif ($idItem == 183 || ($projectName === 'HongKong' && $idItem == 2)) {
        $contractEntityTypeId = ProjectMapping::getContractEntityTypeId($projectName);
        $isDealBased = ProjectMapping::isDealBasedContract($projectName);
        $contractStageId = ProjectMapping::getContractStageId($projectName);
        
        try {
            if ($isDealBased) {
                $bitrixDeal = $bitrix->call('crm.deal.get', [
                    'id' => $idEl
                ]);
                
                if (isset($bitrixDeal['result'])) {
                    $dealData = $bitrixDeal['result'];
                    
                    if ($contractStageId !== null && $dealData['STAGE_ID'] !== $contractStageId) {
                        exit;
                    }
                    
                    file_get_contents($baseUrl . 'Api/TenantContractApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName));
                } else {
                    Logger::warning('Contract webhook: Bitrix deal returned no result', ['contract_id' => $idEl, 'project' => $projectName], 'contract', $idEl);
                }
            } else {
                $bitrixTenantContract = $bitrix->call('crm.item.get', [
                    'entityTypeId' => $contractEntityTypeId,
                    'id' => $idEl
                ]);

                if (isset($bitrixTenantContract['result'])) {
                    file_get_contents($baseUrl . 'Api/TenantContractApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName));
                } else {
                    Logger::warning('Contract webhook: Bitrix returned no result', ['contract_id' => $idEl, 'project' => $projectName], 'contract', $idEl);
                }
            }
        } catch (Exception $e) {
            Logger::error('Contract webhook failed before sync', [
                'contract_id' => $idEl,
                'project' => $projectName,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ], 'contract', $idEl);
        }
    } elseif ($idItem == 148) {
        try {
            $bitrixLandlordContract = $bitrix->call('crm.item.get', [
                'entityTypeId' => 148,
                'id' => $idEl
            ]);

            if (isset($bitrixLandlordContract['result'])) {
                file_get_contents($baseUrl . 'Api/LandlordContractApi.php?id=' . urlencode($idEl) . '&project=' . urlencode($projectName));
            }
        } catch (Exception $e) {
        }
    }
}