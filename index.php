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
    $idEl = $_REQUEST['data']['FIELDS']['ID'];
    $idItem = $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'];

    $requestDomain = $_SERVER['HTTP_HOST'] ?? '';
    $projectName = 'Dubai';
    
    if (strpos($requestDomain, 'colifepacific') !== false) {
        $projectName = 'HongKong';
    }
    
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $fieldMapping = ProjectMapping::getFieldMapping($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    
    if ($idItem != 3) {
        Logger::info("Detected project: $projectName", [
            'project_id' => $projectConfig['id'],
            'entity_type_id' => $idItem,
            'request_domain' => $requestDomain
        ], 'webhook', $idEl);
    }

    $bitrix = new Bitrix24Rest($webhookUrl);

    if ($idItem == $fieldMapping['entity_type_id'] || $idItem == 1048) {
        $entityTypeId = ($idItem == 1048) ? 1048 : $fieldMapping['entity_type_id'];
        
        try {
            $bitrixApartment = $bitrix->call('crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $idEl
            ]);
        } catch (Exception $e) {
            Logger::error("Failed to get apartment from Bitrix24", [
                'entity_type_id' => $entityTypeId,
                'id' => $idEl,
                'error' => $e->getMessage()
            ], 'webhook', $idEl);
            exit;
        }

        $shouldSync = true;
        
        Logger::info("Apartment sync check", [
            'should_sync' => $shouldSync,
            'field_value' => $bitrixApartment['result']['item']['ufCrm6_1753278068179'] ?? 'not_set'
        ], 'webhook', $idEl);
        
        if ($shouldSync) {
            $url = Config::get('APP_BASE_URL') . 'appart.php?id=' . $idEl . '&project=' . $projectName;
            Logger::info("Calling apartment sync", ['url' => $url], 'webhook', $idEl);
            $result = file_get_contents($url);
            if ($result === false) {
                Logger::error("Failed to call apartment sync", ['url' => $url], 'webhook', $idEl);
            }
        }

    } elseif ($idItem == 3 || $_REQUEST['event'] == 'ONCRMCONTACTUPDATE') {
        try {
            $bitrixContact = $bitrix->call('crm.contact.get', [
                'id' => $idEl
            ]);
        } catch (Exception $e) {
            Logger::error("Failed to get contact from Bitrix24", [
                'id' => $idEl,
                'error' => $e->getMessage()
            ], 'webhook', $idEl);
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
                    Logger::warning("Failed to check LL contracts", [
                        'contact_id' => $idEl,
                        'error' => $e->getMessage()
                    ], 'webhook', $idEl);
                }
            }
            
            if ($isTenant) {
                file_get_contents(Config::get('APP_BASE_URL') . 'tenant.php?id=' . $idEl . '&project=' . $projectName);
            } elseif ($isLandlord) {
                file_get_contents(Config::get('APP_BASE_URL') . 'landlord.php?id=' . $idEl . '&project=' . $projectName);
            }
        }
    } elseif ($idItem == 183) {
        try {
            $bitrixTenantContract = $bitrix->call('crm.item.get', [
                'entityTypeId' => 183,
                'id' => $idEl
            ]);

            if (isset($bitrixTenantContract['result'])) {
                file_get_contents(Config::get('APP_BASE_URL') . 'tenatContract.php?id=' . $idEl . '&project=' . $projectName);
            }
        } catch (Exception $e) {
            Logger::error("Failed to get tenant contract from Bitrix24", [
                'id' => $idEl,
                'error' => $e->getMessage()
            ], 'webhook', $idEl);
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
            Logger::error("Failed to get landlord contract from Bitrix24", [
                'id' => $idEl,
                'error' => $e->getMessage()
            ], 'webhook', $idEl);
        }
    }
}