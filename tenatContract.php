<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');

Config::load();

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', (int)Config::get('PROJECT_ID'));
define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));
class AlmaTenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $isRoom = false;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ContractActionLogger();
    }

    public function syncContract(array $bitrixData) {
        $this->validateBitrixData($bitrixData);

        try {
            $clientId = $this->ensureClientExists($bitrixData['client_data']);
            $unitId = $this->getRentalObjectId($bitrixData['unit_external_id']);
            $this->validateRentalObject($unitId, $bitrixData);
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            $externalId = $contractData['external_id'];
            
            $existingContract = $this->getContractByExternalId($externalId);

            if ($existingContract) {
                return $this->updateContract($existingContract['id'], $contractData);
            } else {
                return $this->createContract($contractData);
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



    public function getRentalObjectId($externalId) {
        $url = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
        $response = $this->sendRequest('GET', $url);

        if (!isset($response['id'])) {
            Logger::warning("Rental object not found via rental_object API", ['external_id' => $externalId]);
            throw new Exception("Rental object not found for external_id: $externalId");
        }

        // Сохраняем информацию о типе объекта для дальнейшего использования
        $this->isRoom = isset($response['parent_unit']) && $response['parent_unit'] !== null;

        return $response['id'];
    }

    private function validateRentalObject($unitId, array $bitrixData) {
        try {
            // Определяем правильный endpoint в зависимости от типа объекта
            $endpoint = $this->isRoom ? 'realty/rooms/' : 'realty/units/';
            $unitUrl = $this->apiUrl . $endpoint . $unitId . '/';
            $unitDetails = $this->sendRequest('GET', $unitUrl);
            
            $unitName = $unitDetails['name'] ?? 'Unknown';
            $contractId = $bitrixData['id'] ?? 'unknown';
            $objectType = $this->isRoom ? 'room' : 'unit';
            
            if (isset($unitDetails['status']) && $unitDetails['status'] === 'blocked') {
                throw new Exception("Cannot create contract on blocked $objectType: $unitName");
            }
            
            // Если объект заархивирован - разархивируем его
            if (isset($unitDetails['is_archived']) && $unitDetails['is_archived']) {
                Logger::info("$objectType $unitName is archived, unarchiving for new contract", [], 'contract', $contractId);
                $this->unarchiveUnit($unitId);
                Logger::info("$objectType $unitName successfully unarchived", [], 'contract', $contractId);
                
                // Получаем обновленные данные после разархивирования
                $unitDetails = $this->sendRequest('GET', $unitUrl);
                Logger::debug("$objectType data refreshed after unarchiving", [], 'contract', $contractId);
            }
            
        } catch (Exception $e) {
            Logger::error("Error validating rental object: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw $e;
        }
    }

    private function unarchiveUnit($unitId) {
        $endpoint = $this->isRoom ? 'realty/rooms/' : 'realty/units/';
        $url = $this->apiUrl . $endpoint . $unitId . '/archive/';
        $data = ['is_archived' => false];
        return $this->sendRequest('PATCH', $url, $data);
    }

    public function ensureClientExists(array $clientData) {
        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = $this->sendRequest('GET', $url);
            
            // Проверяем, не заархивирован ли клиент
            if (isset($client['status']) && $client['status'] === 'archived') {
                Logger::info("Client is archived, unarchiving for new contract", ['client_id' => $client['id']]);
                $this->unarchiveClient($client['id']);
                Logger::info("Client successfully unarchived", ['client_id' => $client['id']]);
                
                // Получаем обновленные данные клиента после разархивирования
                $client = $this->sendRequest('GET', $url);
                Logger::debug("Client data refreshed after unarchiving", ['client_id' => $client['id']]);
            }
            
            return $client['id'];
        } catch (Exception $e) {
            try {
                $url = $this->apiUrl . 'users/clients/';
                $birthday = !empty($clientData['birthday']) ? $this->formatBirthday($clientData['birthday']) : null;
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
                Logger::warning("Failed to create client: " . $createException->getMessage());
                throw $createException;
            }
        }
    }

    private function unarchiveClient($clientId) {
        try {
            $url = $this->apiUrl . 'users/clients/' . $clientId . '/';
            $data = ['status' => 'active'];
            return $this->sendRequest('PATCH', $url, $data);
        } catch (Exception $e) {
            Logger::warning("Could not unarchive client directly: " . $e->getMessage(), ['client_id' => $clientId]);
            return true;
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
        if (empty($dateStr)) {
            return null;
        }
        
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s+00:00'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateStr);
            if ($date) {
                return $date->format('Y-m-d\T00:00:00\Z');
            }
        }
        
        $date = new DateTime($dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        
        throw new InvalidArgumentException("Invalid birthday format: $dateStr");
    }

    private function prepareContractData(array $bitrixData, $clientId, $unitId) {
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']),
            'end_date' => $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']),
            'price' => number_format($bitrixData['opportunity'], 2, '.', ''),
            'type_contract' => $this->mapContractType($bitrixData['UF_CRM_20_1693561495'] ?? ''),
        ];

        if (!empty($bitrixData['UF_CRM_20_CONTRACT_HISTORY'])) {
            $history = $bitrixData['UF_CRM_20_CONTRACT_HISTORY'];
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

        $contractScan = $this->uploadFile($bitrixData['ufCrm20Contract']['urlMachine'] ?? null);
        if ($contractScan) {
            $contractData['contract_scan'] = $contractScan;
        }

        return $contractData;
    }

    private function validateBitrixData(array $bitrixData) {
        $requiredFields = [
            'id', 'title', 'unit_external_id', 'client_data',
            'UF_CRM_20_CONTRACT_START_DATE', 'UF_CRM_20_CONTRACT_END_DATE',
            'opportunity'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($bitrixData[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }

            if ($field === 'opportunity') {
                if (!is_numeric($bitrixData[$field]) || $bitrixData[$field] < 0) {
                    throw new InvalidArgumentException("opportunity must be a non-negative number");
                }
            } else {
                if (empty($bitrixData[$field]) && $bitrixData[$field] !== '0') {
                    throw new InvalidArgumentException("Empty value for required field: $field");
                }
            }
        }

        $this->validateDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']);
        $this->validateDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']);
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
            '884' => 'Short term from 1 to 3 months',
            '886' => 'Long-term 3+ months', 
            '1304' => 'Booking',
            '1306' => 'Short contract up to 1 month',
            '6578' => 'Less than a month'
        ];

        $bitrixTypeStr = (string)$bitrixType;

        // Исключаем Airbnb и Ejari контракты из синхронизации
        if ($bitrixTypeStr === '882') {
            throw new InvalidArgumentException("Airbnb contracts are not synchronized to Alma");
        }
        
        if ($bitrixTypeStr === '8672') {
            throw new InvalidArgumentException("Ejari contracts are not synchronized to Alma");
        }

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
            'unit_external_id' => $bitrixData['ufCrm20_1693919019'] ?? '',
            'client_data' => [
                'id' => $contactData['ID'],
                'first_name' => $contactData['NAME'],
                'last_name' => $contactData['LAST_NAME'],
                'email' => $contactData['EMAIL'][0]['VALUE'] ?? '',
                'phone' => $contactData['PHONE'][0]['VALUE'] ?? '',
                'birthday' => $contactData['BIRTHDATE'] ?? '',
            ],
            'UF_CRM_20_CONTRACT_START_DATE' => $bitrixData['ufCrm20ContractStartDate'] ?? '',
            'UF_CRM_20_CONTRACT_END_DATE' => $bitrixData['ufCrm20ContractEndDate'] ?? '',
            'opportunity' => $bitrixData['opportunity'] ?? 0,
            'UF_CRM_20_CONTRACT_HISTORY' => $bitrixData['ufCrm20ContractHistory'] ?? '',
            'UF_CRM_20_1693561495' => $bitrixData['ufCrm20_1693561495'] ?? '',
            'ufCrm20Contract' => $bitrixData['ufCrm20Contract'] ?? null,
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