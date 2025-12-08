<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';
require_once(__DIR__ . '/BaseApi.php');

Config::load();

$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
Logger::setProject($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);


class AlmaTenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $bitrix;
    private $clientApi;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ContractActionLogger();
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->clientApi = new ClientApi($apiKey, $apiUrl);
    }

    public function syncContract(array $bitrixData) {
        $projectName = $GLOBALS['projectName'] ?? 'Dubai';
        
        if ($this->shouldArchiveContract($bitrixData, $projectName)) {
            return $this->archiveContractByExternalId(
                $bitrixData['id'] ?? $bitrixData['external_id'] ?? $bitrixData['ID'] ?? null
            );
        }
        
        $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
        $contractType = $bitrixData[$contractTypeField] ?? '';
        
        $mappedType = ProjectMapping::mapContractType($contractType, $projectName);
        if ($mappedType === null) {
            if ((string)$contractType === '8672') {
                throw new InvalidArgumentException("Ejari contracts are not synchronized to Alma");
            }
            throw new InvalidArgumentException("Unknown contract type: $contractType");
        }
        
        $clientId = $this->ensureClientExists($bitrixData['client_data'], $contractType);
        $unitId = $this->findAlmaObjectById($bitrixData['unit_external_id']);
        
        $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
        
        $existingContract = $this->getContractByExternalId($contractData['external_id']);
        
        if ($existingContract && !($existingContract['unit_usage']['is_archived'] ?? false)) {
            return $this->updateContract($existingContract['id'], $contractData);
        } else {
            return $this->createContract($contractData);
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
        
        try {
            $response = $this->sendRequest('POST', $url, $contractData);
            
            $this->actionLogger->logContractCreation(
                $response['id'],
                $contractData['name'] ?? '',
                $contractData['client_id'] ?? '',
                $contractData['unit_id'] ?? '',
                [
                    'external_id' => $contractData['external_id'] ?? '',
                    'start_date' => $contractData['start_date'] ?? '',
                    'end_date' => $contractData['end_date'] ?? '',
                    'price' => $contractData['price'] ?? ''
                ]
            );
            
            return ['code' => 201, 'response' => $response, 'method' => 'POST'];
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'intersections') !== false || strpos($e->getMessage(), 'intersection') !== false) {
                $existingContract = $this->getContractByExternalId($contractData['external_id']);
                if ($existingContract) {
                    Logger::info('Contract already exists, updating instead of creating', [
                        'external_id' => $contractData['external_id'],
                        'alma_id' => $existingContract['id'] ?? null
                    ], 'contract', $contractData['external_id']);
                    return $this->updateContract($existingContract['id'], $contractData);
                }
            }
            throw $e;
        }
    }


    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContractByExternalId($contractData['external_id']);
        $patchData = $contractData;
        unset($patchData['client_id']);
        
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        $response = $this->sendRequest('PATCH', $url, $patchData);
        
        $this->actionLogger->logUpdate(
            $contractId,
            $contractData['name'] ?? '',
            $oldContractData,
            $patchData
        );

        return ['code' => 200, 'response' => $response, 'method' => 'PATCH'];
    }

    public function getContractByExternalId($externalId) {
        try {
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/';
            return $this->sendRequest('GET', $url);
        } catch (Exception $e) {
            return null;
        }
    }


    public function ensureClientExists(array $clientData, $contractType = '') {
        $isAirbnb = ((string)$contractType === '882');
        
        if (empty($clientData['email']) && $isAirbnb) {
            $clientData['email'] = $this->generateAirbnbEmail($clientData['first_name'] ?? '', $clientData['last_name'] ?? '');
        }
        
        if ($isAirbnb && !empty($clientData['last_name'])) {
            $lastName = trim($clientData['last_name']);
            $suffix = ' airbnb';
            if (strlen($lastName) < strlen($suffix) || substr($lastName, -strlen($suffix)) !== $suffix) {
                $clientData['last_name'] = $lastName . $suffix;
            }
        }

        return $this->clientApi->ensureClientExists($clientData);
    }

    private function formatDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Date is required");
        }
        
        $date = DateTime::createFromFormat('Y-m-d', $dateStr) ?: new DateTime($dateStr);
        return $date->format('Y-m-d\T00:00:00\Z');
    }


    private function prepareContractData(array $bitrixData, $clientId, $unitId) {
        $projectName = $GLOBALS['projectName'] ?? 'Dubai';
        $startDateField = ProjectMapping::getContractField('start_date', $projectName);
        $endDateField = ProjectMapping::getContractField('end_date', $projectName);
        $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
        $historyField = ProjectMapping::getContractField('history', $projectName);
        $contractScanField = ProjectMapping::getContractField('contract_scan', $projectName);
        
        $startDate = $this->formatDate($bitrixData[$startDateField] ?? '');
        $endDate = $this->formatDate($bitrixData[$endDateField] ?? '');
        
        $contractType = $bitrixData[$contractTypeField] ?? '';
        $isAirbnb = ((string)$contractType === '882');
        $price = $isAirbnb ? '0.00' : number_format($bitrixData['opportunity'], 2, '.', '');
        
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'price' => $price,
            'type_contract' => ProjectMapping::mapContractType($contractType, $projectName),
        ];

        if ($historyField && !empty($bitrixData[$historyField])) {
            $history = $bitrixData[$historyField];
            if (is_array($history)) {
                $filteredHistory = array_filter($history, function($item) {
                    return !empty(trim($item));
                });
                if (!empty($filteredHistory)) {
                    $contractData['history'] = implode('; ', $filteredHistory);
                }
            } else {
                $contractData['history'] = trim($history);
            }
        }

        if ($contractScanField && !empty($bitrixData[$contractScanField])) {
            $scanData = $bitrixData[$contractScanField];
            $scanUrl = is_array($scanData) ? ($scanData['urlMachine'] ?? null) : $scanData;
            $contractScan = $this->uploadFile($scanUrl);
            if ($contractScan) {
                $contractData['contract_scan'] = $contractScan;
            }
        }

        return $contractData;
    }


    private function generateAirbnbEmail($firstName, $lastName) {
        $first = mb_strtolower(trim($firstName));
        $last = mb_strtolower(trim($lastName));
        
        $firstClean = preg_replace('/[^a-z0-9]/', '', $first);
        $lastClean = preg_replace('/[^a-z0-9]/', '', $last);
        
        if (empty($firstClean) && empty($lastClean)) {
            $username = 'airbnb' . bin2hex(random_bytes(2));
        } elseif (empty($firstClean)) {
            $username = $lastClean;
        } elseif (empty($lastClean)) {
            $username = $firstClean;
        } else {
            $username = $firstClean . '.' . $lastClean;
        }
        
        return "{$username}@airbnb.colife";
    }

    private function uploadFile($fileUrl) {
        if (empty($fileUrl)) {
            return null;
        }

        $uploadUrl = $this->apiUrl . 'external-image/';
        $fileData = [
            'url' => $fileUrl,
            'description' => 'Contract document'
        ];

        try {
            $response = $this->sendRequest('POST', $uploadUrl, $fileData);
            return $response['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function sendRequest($method, $url, $data = null) {
        return BaseApi::sendRequest($this->apiKey, $method, $url, $data, true);
    }
    
    /**
     * Найти объект в Alma по Bitrix ID согласно документации
     * GET: external_api/realty/rental_object/{{bitrix_id}}/
     * 
     * Использует систему приоритетов rental_object API:
     * - Сначала ищутся все объекты по external_id и additional_external_id
     * - Затем сортируются по приоритетам и возвращается первый
     */
    public function findAlmaObjectById($bitrixId) {
        $url = $this->apiUrl . 'realty/rental_object/' . $bitrixId . '/';
        $response = $this->sendRequest('GET', $url);
        return $response['id'];
    }
    
    private function shouldArchiveContract(array $bitrixData, $projectName) {
        if ($projectName !== 'Dubai') {
            return false;
        }
        $failureStage = ProjectMapping::getFailureStageId($projectName);
        return $failureStage && (($bitrixData['stageId'] ?? null) === $failureStage);
    }
    
    private function archiveContractByExternalId($externalId) {
        if (!$externalId) {
            throw new InvalidArgumentException('Contract external id is required for archiving');
        }
        
        $response = $this->sendRequest(
            'PATCH',
            $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/archive/',
            ['is_archived' => true]
        );
        
        Logger::info('Contract archived', ['external_id' => $externalId], 'contract', $externalId);
        
        return ['code' => 200, 'response' => $response, 'method' => 'ARCHIVE'];
    }

}


try {
    $GLOBALS['projectName'] = $projectName;
    $almaApi = new AlmaTenantContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    
    $isDealBased = ProjectMapping::isDealBasedContract($projectName);
    $contractEntityTypeId = ProjectMapping::getContractEntityTypeId($projectName);
    $contractStageId = ProjectMapping::getContractStageId($projectName);

    if ($isDealBased) {
        $contractResponse = $bitrix->call('crm.deal.get', ['id' => $_GET['id']]);
        $dealData = $contractResponse['result'];
        
        if ($contractStageId !== null && $dealData['STAGE_ID'] !== $contractStageId) {
            throw new Exception("Deal stage is not 'Contract signed'. Current stage: " . $dealData['STAGE_ID']);
        }
        
        $contractData = [
            'id' => $dealData['ID'],
            'title' => $dealData['TITLE'] ?? '',
            ProjectMapping::getContractField('unit_id', $projectName) => $dealData[ProjectMapping::getContractField('unit_id', $projectName)] ?? '',
            ProjectMapping::getContractField('start_date', $projectName) => $dealData[ProjectMapping::getContractField('start_date', $projectName)] ?? '',
            ProjectMapping::getContractField('end_date', $projectName) => $dealData[ProjectMapping::getContractField('end_date', $projectName)] ?? '',
            'opportunity' => $dealData['OPPORTUNITY'] ?? 0,
            ProjectMapping::getContractField('history', $projectName) => $dealData[ProjectMapping::getContractField('history', $projectName)] ?? '',
            ProjectMapping::getContractField('contract_type', $projectName) => $dealData[ProjectMapping::getContractField('contract_type', $projectName)] ?? '',
            ProjectMapping::getContractField('contract_scan', $projectName) => $dealData[ProjectMapping::getContractField('contract_scan', $projectName)] ?? null,
            'contactId' => $dealData['CONTACT_ID'] ?? null,
        ];
    } else {
        $contractResponse = $bitrix->call('crm.item.get', [
            'entityTypeId' => $contractEntityTypeId,
            'id' => $_GET['id'],
        ]);
        $contractData = $contractResponse['result']['item'];
    }

    $contactId = $contractData['contactId'] ?? $contractData['CONTACT_ID'] ?? null;
    $contactResponse = $bitrix->call('crm.contact.get', ['id' => $contactId]);
    $contact = $contactResponse['result'];

    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $historyField = ProjectMapping::getContractField('history', $projectName);
    $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
    $contractScanField = ProjectMapping::getContractField('contract_scan', $projectName);
    
    $email = is_array($contact['EMAIL']) ? ($contact['EMAIL'][0]['VALUE'] ?? '') : '';
    
    $syncData = [
        'id' => $contractData['id'],
        'title' => $contractData['title'],
        'unit_external_id' => $contractData[ProjectMapping::getContractField('unit_id', $projectName)] ?? '',
        'stageId' => $contractData['stageId'] ?? $contractData['stage_id'] ?? $contractData['STAGE_ID'] ?? null,
        'client_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $email,
            'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
        ],
        'opportunity' => $contractData['opportunity'] ?? 0,
    ];
    
    if ($startDateField) $syncData[$startDateField] = $contractData[$startDateField] ?? '';
    if ($endDateField) $syncData[$endDateField] = $contractData[$endDateField] ?? '';
    if ($historyField) $syncData[$historyField] = $contractData[$historyField] ?? '';
    if ($contractTypeField) $syncData[$contractTypeField] = $contractData[$contractTypeField] ?? '';
    if ($contractScanField) $syncData[$contractScanField] = $contractData[$contractScanField] ?? null;

    $result = $almaApi->syncContract($syncData);
    $almaId = $result['response']['id'] ?? null;
    $method = $result['method'] ?? 'unknown';
    
    Logger::info('Contract sync succeeded', [
        'contract_id' => $_GET['id'],
        'alma_id' => $almaId,
        'method' => $method
    ], 'contract', $_GET['id']);
    
    echo json_encode([
        'success' => true,
        'alma_id' => $almaId,
        'method' => $method,
        'operation' => $method === 'POST' ? 'created' : 'updated',
        'data' => $result['response'] ?? $result
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    Logger::error("Unexpected error in contract synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'contract', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Contract synchronization failed'
    ]);
}