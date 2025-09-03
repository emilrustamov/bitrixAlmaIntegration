<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');

// Загружаем конфигурацию
Config::load();

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', (int)Config::get('PROJECT_ID'));
define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));
class AlmaTenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ContractActionLogger();
    }

    public function syncContract(array $bitrixData) {
        $this->validateBitrixData($bitrixData);

        try {
            Logger::info("Starting contract sync for external_id: " . ($bitrixData['id'] ?? 'unknown'), [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            $clientId = $this->ensureClientExists($bitrixData['client_data']);
            Logger::debug("Client ID: $clientId", [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            $unitId = $this->getRentalObjectId($bitrixData['unit_external_id']);
            Logger::debug("Unit ID: $unitId", [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            $externalId = $contractData['external_id'];
            Logger::debug("Prepared contract data with external_id: $externalId", [], 'contract', $externalId);
            
            $existingContract = $this->getContractByExternalId($externalId);

            if ($existingContract) {
                Logger::info("Found existing contract by external_id, updating...", [], 'contract', $externalId);
                return $this->updateContract($existingContract['id'], $contractData);
            } else {
                Logger::info("No existing contract found by external_id, creating new contract...", [], 'contract', $externalId);
                try {
                    return $this->createContract($contractData);
                } catch (Exception $createError) {
                    Logger::error("Error creating contract: " . $createError->getMessage(), [], 'contract', $externalId);
                    

                    if (strpos($createError->getMessage(), 'intersections in the use of the unit') !== false || 
                        strpos($createError->getMessage(), 'active usage record already exists') !== false) {
                        
                        Logger::warning("Unit usage conflict detected, trying to find existing contract...", [], 'contract', $externalId);
                        

                        if (preg_match('/usage id:(\d+)/', $createError->getMessage(), $matches)) {
                            $existingUsageId = $matches[1];
                            Logger::debug("Found existing usage ID: $existingUsageId", [], 'contract', $externalId);
                            
                            $existingContract = $this->findContractByUsageId($existingUsageId);
                            if ($existingContract) {
                                Logger::info("Found existing contract by usage ID, updating...", [], 'contract', $externalId);
                                return $this->updateContract($existingContract['id'], $contractData);
                            }
                        }
                        

                        $foundContract = $this->findActiveContractByClientAndUnit($clientId, $unitId);
                        if ($foundContract) {
                            Logger::info("Found existing contract by client and unit, updating...", [], 'contract', $externalId);
                            return $this->updateContract($foundContract['id'], $contractData);
                        }
                    }
                    
                    throw $createError;
                }
            }
        } catch (Exception $e) {
            Logger::error("Contract synchronization failed: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw new Exception("Contract synchronization failed: " . $e->getMessage());
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
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
        
        return $response;
    }

    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContract($contractId);
        
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        $response = $this->sendRequest('PATCH', $url, $contractData);
        
        $this->actionLogger->logUpdate(
            $contractId,
            $contractData['name'] ?? '',
            $oldContractData,
            $contractData
        );
        
        return $response;
    }



    public function getContract($contractId) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        return $this->sendRequest('GET', $url);
    }

    public function getContractByExternalId($externalId) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/';

        try {
            $result = $this->sendRequest('GET', $url);
            Logger::debug("Successfully found contract by external_id: $externalId");
            return $result;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                Logger::debug("Contract not found by external_id: $externalId (404)");
                return null;
            }
            Logger::error("Error getting contract by external_id: $externalId - " . $e->getMessage());
            throw $e;
        }
    }

    public function findContractByUsageId($usageId) {
        try {

            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
            $contracts = $this->sendRequest('GET', $url);
            $contractsToCheck = array_slice($contracts, -100);
            
            Logger::debug("Searching for contract with usage_id: $usageId in " . count($contractsToCheck) . " contracts");
            

            foreach ($contractsToCheck as $contract) {
                try {
                    $contractDetails = $this->getContract($contract['id']);
                    
                    if (isset($contractDetails['unit_usage']) && 
                        $contractDetails['unit_usage']['usage_id'] == $usageId) {
                        
                        Logger::debug("Found contract with usage_id $usageId: " . $contract['id']);
                        return $contractDetails;
                    }
                } catch (Exception $e) {
                    Logger::warning("Error getting contract details for ID {$contract['id']}: " . $e->getMessage());
                    continue;
                }
            }
            
            Logger::debug("No contract found with usage_id: $usageId");
            return null;
        } catch (Exception $e) {
            Logger::error("Error getting contracts list: " . $e->getMessage());
            return null;
        }
    }

    public function findActiveContractByClientAndUnit($clientId, $unitId) {
        try {

            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
            $contracts = $this->sendRequest('GET', $url);
            $contractsToCheck = array_slice($contracts, -50);
            
            Logger::debug("Checking " . count($contractsToCheck) . " contracts out of " . count($contracts) . " total");
            

            foreach ($contractsToCheck as $contract) {
                try {
                    $contractDetails = $this->getContract($contract['id']);
                    
                    if (isset($contractDetails['unit_usage']) && 
                        $contractDetails['unit_usage']['client_id'] == $clientId && 
                        $contractDetails['unit_usage']['unit_id'] == $unitId &&
                        !$contractDetails['unit_usage']['is_archived']) {
                        
                        Logger::debug("Found active contract: " . $contract['id']);
                        return $contractDetails;
                    }
                } catch (Exception $e) {

                    Logger::warning("Error getting contract details for ID {$contract['id']}: " . $e->getMessage());
                    continue;
                }
            }
            
            Logger::debug("No active contract found for client $clientId and unit $unitId");
            return null;
        } catch (Exception $e) {
            Logger::error("Error getting contracts list: " . $e->getMessage());
            return null;
        }
    }

    public function getRentalObjectId($externalId) {
        $url = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
        $response = $this->sendRequest('GET', $url);

        if (!isset($response['id'])) {
            throw new Exception("Rental object not found in ALMA");
        }

        return $response['id'];
    }

    public function ensureClientExists(array $clientData) {
        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = $this->sendRequest('GET', $url);
            return $client['id'];
        } catch (Exception $e) {
            try {
                $url = $this->apiUrl . 'users/clients/';
                $birthday = $clientData['birthday'];
                $newClient = $this->sendRequest('POST', $url, [
                    'external_id' => $clientData['id'],
                    'first_name' => $clientData['first_name'],
                    'last_name' => $clientData['last_name'],
                    'email' => $clientData['email'],
                    'phone' => $clientData['phone'],
                    'country' => 4,
                    'birthday' => $birthday
                ]);
                return $newClient['id'];
            } catch (Exception $createException) {
                if (strpos($createException->getMessage(), 'email') !== false && 
                    strpos($createException->getMessage(), 'already exists') !== false) {
                    
                    try {
                        $allClientsUrl = $this->apiUrl . 'users/clients/';
                        $allClients = $this->sendRequest('GET', $allClientsUrl);
                        
                        foreach ($allClients as $client) {
                            if ($client['email'] === $clientData['email'] || 
                                $client['external_id'] === $clientData['id']) {
                                return $client['id'];
                            }
                        }
                    } catch (Exception $searchException) {
                    }
                }
                
                throw $createException;
            }
        }
    }
    private function formatDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Invalid date format: пустая строка");
        }
        $date = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        $date = DateTime::createFromFormat(DateTime::ATOM, $dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr, $matches)) {
            return $matches[0] . 'T00:00:00Z';
        }
        throw new InvalidArgumentException("Invalid date format: $dateStr");
    }

    private function formatBirthday($dateStr) {
        $date = DateTime::createFromFormat('Y-m-d', $dateStr);
        if (!$date) {
            throw new InvalidArgumentException("Invalid birthday format: $dateStr");
        }
        return $date->format('Y-m-d\T00:00:00\Z');
    }

    private function prepareContractData(array $bitrixData, $clientId, $unitId) {
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $this->formatDate($bitrixData['ufCrm20ContractStartDate']),
            'end_date' => $this->formatDate($bitrixData['ufCrm20ContractEndDate']),
            'price' => number_format($bitrixData['opportunity'], 2, '.', ''),
            'type_contract' => $this->mapContractType($bitrixData['ufCrm20_1693561495'] ?? ''),
        ];

        if (!empty($bitrixData['ufCrm20ContractHistory'])) {
            $contractData['history'] = $bitrixData['ufCrm20ContractHistory'];
        }

        $contractScan = $this->uploadFile($bitrixData['ufCrm20Contract']['urlMachine'] ?? null);
        if ($contractScan) {
            $contractData['contract_scan'] = $contractScan;
        }

        return $contractData;
    }

    private function validateBitrixData(array $bitrixData) {
        $requiredFields = [
            'id', 'title', 'unit_external_id', 'client_data',
            'ufCrm20ContractStartDate', 'ufCrm20ContractEndDate',
            'opportunity'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($bitrixData[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }

            if (empty($bitrixData[$field]) && $bitrixData[$field] !== '0') {
                throw new InvalidArgumentException("Empty value for required field: $field");
            }
        }

        $this->validateDate($bitrixData['ufCrm20ContractStartDate']);
        $this->validateDate($bitrixData['ufCrm20ContractEndDate']);
    }

    private function validateDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Invalid date format: пустая строка");
        }
        if (DateTime::createFromFormat('Y-m-d', $dateStr)) {
            return;
        }
        if (DateTime::createFromFormat(DateTime::ATOM, $dateStr)) {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
            return;
        }
        throw new InvalidArgumentException("Invalid date format: $dateStr. Expected Y-m-d or ISO8601");
    }

    private function mapContractType($bitrixType) {
        $mapping = [
            '882' => 'Airbnb',
            '884' => 'Short term from 1 to 3 months',
            '886' => 'Long-term 3+ months',
            '1304' => 'Booking',
            '1306' => 'Short contract up to 1 month'
        ];

        $bitrixTypeStr = (string)$bitrixType;

        if (!isset($mapping[$bitrixTypeStr])) {
            throw new InvalidArgumentException("Unknown contract type: $bitrixType");
        }

        return $mapping[$bitrixTypeStr];
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
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        Logger::logApiRequest($method, $url, $data, $response, $httpCode);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL error: $error");
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            $errorMessage = "API request failed ($httpCode)";
            if ($response) {
                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['detail'])) {
                    $errorMessage .= ": " . $decoded['detail'];
                } else {
                    $errorMessage .= ": " . substr($response, 0, 200);
                }
            }
            throw new RuntimeException($errorMessage, $httpCode);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
        }

        return $decodedResponse;
    }
    
    public function getActionLogger()
    {
        return $this->actionLogger;
    }
}



try {
    $almaApi = new AlmaTenantContractApi();

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $bitrixTenats = $bitrix->call('crm.item.get', [
        'entityTypeId' => 183,
        'id' => $_GET['id'],
    ]);

    if (!isset($bitrixTenats['result']['item'])) {
        throw new Exception("Contract not found in Bitrix24 with ID: " . $_GET['id']);
    }
    
    $bitrixData = $bitrixTenats['result']['item'];

    $contactResponse = $bitrix->call('crm.contact.get', [
        'id' => $bitrixData['contactId']
    ]);
    
    if (!isset($contactResponse['result'])) {
        throw new Exception("Contact not found in Bitrix24 with ID: " . $bitrixData['contactId']);
    }
    
    $contactData = $contactResponse['result'];

    $bitrixContractData = [
        'id' => $bitrixData['id'],
        'title' => $bitrixData['title'],
        'unit_external_id' => $bitrixData['ufCrm20_1693919019'],
        'client_data' => [
            'id' => $contactData['ID'],
            'first_name' => $contactData['NAME'],
            'last_name' => $contactData['LAST_NAME'],
            'email' => $contactData['EMAIL'][0]['VALUE'] ?? '',
            'phone' => $contactData['PHONE'][0]['VALUE'] ?? '',
            'birthday' => $contactData['BIRTHDATE'],
        ],
        'ufCrm20ContractStartDate' => $bitrixData['ufCrm20ContractStartDate'],
        'ufCrm20ContractEndDate' => $bitrixData['ufCrm20ContractEndDate'],
        'opportunity' => $bitrixData['opportunity'],
        'ufCrm20ContractHistory' => $bitrixData['ufCrm20ContractHistory'],
        'ufCrm20_1693561495' => $bitrixData['ufCrm20_1693561495'],
    ];

    $result = $almaApi->syncContract($bitrixContractData);

    if (isset($result['id'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Contract successfully synchronized',
            'alma_id' => $result['id'],
            'data' => $result
        ]);
    } else {
        throw new Exception('Contract synchronization failed');
    }

} catch (InvalidArgumentException $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'Contract Validation Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Data validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'API Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'Unexpected Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}