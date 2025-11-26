<?php
require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

$isWebhookRequest = isset($_REQUEST['data']['FIELDS']['ID']) && 
                   isset($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID']) ||
                   isset($_REQUEST['event']);

if ($isWebhookRequest) {
    // Для событий ONCRMDEALADD/ONCRMDEALUPDATE структура может отличаться
    if (isset($_REQUEST['event']) && ($_REQUEST['event'] == 'ONCRMDEALADD' || $_REQUEST['event'] == 'ONCRMDEALUPDATE')) {
        $idEl = $_REQUEST['data']['FIELDS']['ID'] ?? $_REQUEST['data']['ID'] ?? null;
        $idItem = 2; // Для deals entityTypeId всегда 2
    } elseif (isset($_REQUEST['event']) && $_REQUEST['event'] == 'ONCRMDYNAMICITEMUPDATE') {
        // Для ONCRMDYNAMICITEMUPDATE структура: data[FIELDS][ID] и data[FIELDS][ENTITY_TYPE_ID]
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
    $projectName = 'Dubai';
    
    // Определяем проект по домену запроса
    if (strpos($requestDomain, 'colifepacific') !== false) {
        $projectName = 'HongKong';
    }
    
    // Если это событие ONCRMDEALADD или ONCRMDEALUPDATE и $idItem == 2, это точно Гонконг
    if (isset($_REQUEST['event']) && ($_REQUEST['event'] == 'ONCRMDEALADD' || $_REQUEST['event'] == 'ONCRMDEALUPDATE') && $idItem == 2) {
        $projectName = 'HongKong';
    }
    
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $fieldMapping = ProjectMapping::getFieldMapping($projectName);
    $webhookUrl = $projectConfig['webhook_url'];

    $bitrix = new Bitrix24Rest($webhookUrl);

    if ($idItem == $fieldMapping['entity_type_id'] || $idItem == 1048 || 
        ($_REQUEST['event'] == 'ONCRMDYNAMICITEMUPDATE' && $idItem == $fieldMapping['entity_type_id'])) {
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
            $url = Config::get('APP_BASE_URL') . 'appart.php?id=' . $idEl . '&project=' . $projectName;
            file_get_contents($url);
        }
    } elseif (($projectName === 'HongKong' && $idItem == ProjectMapping::getUnitEntityTypeId($projectName)) ||
              ($_REQUEST['event'] == 'ONCRMDYNAMICITEMUPDATE' && $projectName === 'HongKong' && 
               $idItem == ProjectMapping::getUnitEntityTypeId($projectName))) {
        // Обработка юнитов (rooms) для HongKong
        $url = Config::get('APP_BASE_URL') . 'unit.php?id=' . $idEl . '&project=' . $projectName;
        file_get_contents($url);

    } elseif ($idItem == 3 || $_REQUEST['event'] == 'ONCRMCONTACTUPDATE') {
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
                file_get_contents(Config::get('APP_BASE_URL') . 'tenant.php?id=' . $idEl . '&project=' . $projectName);
            } elseif ($isLandlord) {
                file_get_contents(Config::get('APP_BASE_URL') . 'landlord.php?id=' . $idEl . '&project=' . $projectName);
            }
        }
    } elseif ($idItem == 183 || ($projectName === 'HongKong' && $idItem == 2)) {
        // Для Дубая: $idItem == 183 (smart process)
        // Для Гонконга: $idItem == 2 (deals) - обрабатываем как контракты
        $contractEntityTypeId = ProjectMapping::getContractEntityTypeId($projectName);
        $isDealBased = ProjectMapping::isDealBasedContract($projectName);
        $contractStageId = ProjectMapping::getContractStageId($projectName);
        
        try {
            if ($isDealBased) {
                // Для Гонконга используем crm.deal.get
                $bitrixDeal = $bitrix->call('crm.deal.get', [
                    'id' => $idEl
                ]);
                
                if (isset($bitrixDeal['result'])) {
                    $dealData = $bitrixDeal['result'];
                    
                    if ($contractStageId !== null && $dealData['STAGE_ID'] !== $contractStageId) {
                        exit;
                    }
                    
                    file_get_contents(Config::get('APP_BASE_URL') . 'tenatContract.php?id=' . $idEl . '&project=' . $projectName);
                }
            } else {
                // Для Дубая используем crm.item.get для smart process
                $bitrixTenantContract = $bitrix->call('crm.item.get', [
                    'entityTypeId' => $contractEntityTypeId,
                    'id' => $idEl
                ]);

                if (isset($bitrixTenantContract['result'])) {
                    file_get_contents(Config::get('APP_BASE_URL') . 'tenatContract.php?id=' . $idEl . '&project=' . $projectName);
                }
            }
        } catch (Exception $e) {
        }
    } elseif ($idItem == 148) {
        try {
            $bitrixLandlordContract = $bitrix->call('crm.item.get', [
                'entityTypeId' => 148,
                'id' => $idEl
            ]);

            if (isset($bitrixLandlordContract['result'])) {
                file_get_contents(Config::get('APP_BASE_URL') . 'landlordContract.php?id=' . $idEl . '&project=' . $projectName);
            }
        } catch (Exception $e) {
        }
    }
}