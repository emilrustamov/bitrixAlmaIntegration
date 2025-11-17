<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);

class AlmaLandlordContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $bitrix;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new LandlordContractActionLogger();
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
    }

    public function syncContract(array $bitrixData) {
        $this->validateBitrixData($bitrixData);

        Logger::info("syncContract called", [
            'contract_id' => $bitrixData['id'],
            'apartment_id' => $bitrixData['apartment_id'] ?? 'not_set',
            'landlord_id' => $bitrixData['landlord_data']['id'] ?? 'not_set'
        ], 'landlord_contract', $bitrixData['id']);

        try {
            $landlordId = $this->ensureLandlordExists($bitrixData['landlord_data']);
            $apartmentBitrixId = $bitrixData['apartment_id'];
            $this->validateApartment($apartmentBitrixId, $bitrixData);
            $apartmentId = $this->findApartmentInAlma($apartmentBitrixId);
            
            $contractData = $this->prepareContractData($bitrixData, $landlordId, $apartmentId);
            
            return $this->createOrUpdateContract($contractData, $bitrixData['id']);
        } catch (Exception $e) {
            Logger::error("Landlord contract synchronization failed: " . $e->getMessage(), [], 'landlord_contract', $bitrixData['id'] ?? 'unknown');
            throw new Exception("Landlord contract synchronization failed: " . $e->getMessage());
        }
    }

    public function createOrUpdateContract($contractData, $bitrixId) {
        $externalId = $contractData['external_id'];
        
        // Пытаемся найти существующий контракт
        $existingContract = $this->getContractByExternalId($externalId);
        
        if ($existingContract) {
            // Контракт найден - проверяем архивность использования
            $existingFull = $this->getContract($existingContract['id']);
            $isArchivedUsage = isset($existingFull['unit_usage']['is_archived']) ? (bool)$existingFull['unit_usage']['is_archived'] : false;

            if ($isArchivedUsage) {
                // Нельзя редактировать архивное использование — создаём НОВЫЙ контракт
                Logger::info("Existing contract has archived usage; creating a new contract with the same external_id instead of updating", [
                    'old_contract_id' => $existingContract['id'],
                    'external_id' => $externalId
                ], 'landlord_contract', $bitrixId);

                return $this->createContract($contractData);
            } else {
                // Можно обновлять
                Logger::info("Updating existing contract", [
                    'contract_id' => $existingContract['id'],
                    'external_id' => $externalId
                ], 'landlord_contract', $bitrixId);
                
                return $this->updateContract($existingContract['id'], $contractData);
            }
        } else {
            // Контракт не найден - создаем новый
            Logger::info("Creating new contract", [
                'external_id' => $externalId
            ], 'landlord_contract', $bitrixId);
            
            return $this->createContract($contractData);
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/owner_contracts/';
        
        Logger::info("Creating landlord contract with data", [
            'url' => $url,
            'contract_data' => $contractData
        ], 'landlord_contract', $contractData['external_id'] ?? 'unknown');
        
        try {
            $response = $this->sendRequest('POST', $url, $contractData);
            
            Logger::info("Landlord contract created successfully", [
                'contract_id' => $response['id'] ?? 'unknown',
                'external_id' => $contractData['external_id'] ?? 'unknown'
            ], 'landlord_contract', $contractData['external_id'] ?? 'unknown');
            
            $this->actionLogger->logContractCreation(
                $response['id'],
                $contractData['name'] ?? '',
                $contractData['client_id'] ?? '',
                $contractData['unit_id'] ?? '',
                [
                    'external_id' => $contractData['external_id'] ?? '',
                    'start_date' => $contractData['start_date'] ?? '',
                    'end_date' => $contractData['end_date'] ?? '',
                    'work_model' => $contractData['work_model'] ?? ''
                ]
            );
            
            return ['code' => 201, 'response' => $response, 'method' => 'POST'];
        } catch (Exception $e) {
            Logger::error("Failed to create landlord contract: " . $e->getMessage(), [
                'contract_data' => $contractData,
                'url' => $url
            ], 'landlord_contract', $contractData['external_id'] ?? 'unknown');
            
            return ['code' => 400, 'response' => ['error' => $e->getMessage()], 'method' => 'POST'];
        }
    }

    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContract($contractId);

        // Формируем данные для PATCH отдельно, чтобы сохранить исходные для возможного POST-фолбэка
        $patchData = $contractData;
        unset($patchData['client_id']); // Alma не позволяет менять клиента при PATCH
        
        try {
            $url = $this->apiUrl . 'realty/contracts/owner_contracts/' . $contractId . '/';
            $response = $this->sendRequest('PATCH', $url, $patchData);
            
            $this->actionLogger->logUpdate(
                $contractId,
                $contractData['name'] ?? '',
                $oldContractData,
                $patchData
            );

            return ['code' => 200, 'response' => $response, 'method' => 'PATCH'];
        } catch (Exception $e) {
            $message = $e->getMessage();
            // Если Alma запрещает редактировать архивное использование — пробуем создать новый контракт с теми же данными
            if (stripos($message, 'forbidden to edit the archive usage') !== false) {
                Logger::warning("PATCH forbidden on archived usage; falling back to POST create", [
                    'contract_id' => $contractId,
                    'error' => $message
                ], 'landlord_contract', $contractId);
                try {
                    $createResult = $this->createContract($contractData);
                    return $createResult;
                } catch (Exception $postEx) {
                    return ['code' => 400, 'response' => ['error' => $postEx->getMessage()], 'method' => 'POST'];
                }
            }
            return ['code' => 400, 'response' => ['error' => $message], 'method' => 'PATCH'];
        }
    }

    public function getContract($contractId) {
        $url = $this->apiUrl . 'realty/contracts/owner_contracts/' . $contractId . '/';
        return $this->sendRequest('GET', $url);
    }

    public function getContractByExternalId($externalId) {
        try {
            $url = $this->apiUrl . 'realty/contracts/owner_contracts/';
            $contracts = $this->sendRequest('GET', $url);
            
            if (is_array($contracts)) {
                foreach ($contracts as $contract) {
                    if (isset($contract['external_id']) && $contract['external_id'] == $externalId) {
                        return $contract;
                    }
                }
            }
            return null;
        } catch (Exception $e) {
            Logger::error("Error searching contracts by external_id: $externalId - " . $e->getMessage());
            return null;
        }
    }

    private function validateApartment($apartmentId, array $bitrixData) {
        try {
            // Получаем детали апартамента из Bitrix24 для проверки типа аренды
            $bitrixApartment = $this->bitrix->call('crm.item.get', [
                'entityTypeId' => 144, // Apartments
                'id' => $apartmentId
            ]);
            
            if (!isset($bitrixApartment['result']['item'])) {
                throw new Exception("Apartment not found in Bitrix24: $apartmentId");
            }
            
            $apartmentData = $bitrixApartment['result']['item'];
            $rentalType = $apartmentData['ufCrm6_1753278068179'] ?? null;
            
            // Проверяем, что это не Ejari (8638)
            if ($rentalType == '8638') {
                throw new Exception("Ejari contracts are not synchronized to Alma");
            }
            
            Logger::info("Apartment validation passed", [
                'apartment_id' => $apartmentId,
                'apartment_name' => $apartmentData['title'] ?? 'Unknown',
                'rental_type' => $rentalType
            ], 'landlord_contract', $bitrixData['id'] ?? 'unknown');
            
        } catch (Exception $e) {
            Logger::error("Error validating apartment: " . $e->getMessage(), [], 'landlord_contract', $bitrixData['id'] ?? 'unknown');
            throw $e;
        }
    }

    public function ensureLandlordExists(array $landlordData) {
        Logger::info("ensureLandlordExists called with landlordData", [
            'landlord_id' => $landlordData['id'],
            'email' => $landlordData['email'],
            'first_name' => $landlordData['first_name'],
            'last_name' => $landlordData['last_name']
        ], 'landlord_contract', $landlordData['id']);
        
        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $landlordData['id'] . '/';
            $landlord = $this->sendRequest('GET', $url);
            
            return $landlord['id'];
        } catch (Exception $e) {
            if (!empty($landlordData['email'])) {
                try {
                    // Согласно документации, используем правильный API для получения списка клиентов
                    $searchUrl = $this->apiUrl . 'users/clients/';
                    $searchResponse = $this->sendRequest('GET', $searchUrl);
                    
                    if (is_array($searchResponse) && !empty($searchResponse)) {
                        $existingLandlord = null;
                        foreach ($searchResponse as $client) {
                            if (isset($client['email']) && $client['email'] === $landlordData['email']) {
                                $existingLandlord = $client;
                                break;
                            }
                        }
                        
                        if ($existingLandlord) {
                            Logger::info("Found existing landlord by email, updating external_id", [
                                'existing_landlord_id' => $existingLandlord['id'],
                                'new_external_id' => $landlordData['id'],
                                'email' => $landlordData['email']
                            ]);
                            
                            $updateUrl = $this->apiUrl . 'users/clients/' . $existingLandlord['id'] . '/';
                            $this->sendRequest('PATCH', $updateUrl, ['external_id' => $landlordData['id']]);
                            
                            return $existingLandlord['id'];
                        }
                    }
                } catch (Exception $searchException) {
                    Logger::warning("Failed to search landlord by email: " . $searchException->getMessage());
                }
            }
            
            try {
                $url = $this->apiUrl . 'users/clients/';
                $birthday = '1999-06-03T00:00:00Z';
                $newLandlord = $this->sendRequest('POST', $url, [
                    'external_id' => $landlordData['id'],
                    'first_name' => $landlordData['first_name'],
                    'last_name' => $landlordData['last_name'],
                    'email' => $landlordData['email'],
                    'phone' => $landlordData['phone'],
                    'country' => 4,
                    'birthday' => $birthday
                ]);
                return $newLandlord['id'];
            } catch (Exception $createException) {
                Logger::warning("Failed to create landlord: " . $createException->getMessage());
                throw $createException;
            }
        }
    }

    private function formatDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Date is required");
        }
        
        try {
            $date = new DateTime($dateStr);
            return $date->format('Y-m-d\T00:00:00\Z');
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid date format: $dateStr");
        }
    }

    private function prepareContractData(array $bitrixData, $landlordId, $apartmentId) {
        $startDate = $this->formatDate($bitrixData['ufCrm10_1693823247516']);
        $endDate = $this->formatDate($bitrixData['ufCrm10_1693823282826']);
        
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $apartmentId,
            'client_id' => $landlordId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'work_model' => $this->mapWorkModel($bitrixData['ufCrm10_1708955821'] ?? ''),
        ];

        // Загружаем файлы если есть
        $contractScan = $this->uploadFile($bitrixData['ufCrm10_1709042143']['urlMachine'] ?? null);
        if ($contractScan) {
            $contractData['contract_with_client_scan'] = $contractScan;
        }

        $titleDeedScan = $this->uploadFile($bitrixData['ufCrm10_1694000636731']['urlMachine'] ?? null);
        if ($titleDeedScan) {
            $contractData['property_title_deed_scan'] = $titleDeedScan;
        }

        $pmlScan = $this->uploadFile($bitrixData['ufCrm10_1694000391518']['urlMachine'] ?? null);
        if ($pmlScan) {
            $contractData['property_management_letter_scan'] = $pmlScan;
        }

        $dtcmScan = $this->uploadFile($bitrixData['ufCrm10_1694000558852']['urlMachine'] ?? null);
        if ($dtcmScan) {
            $contractData['dtcm_permit_scan'] = $dtcmScan;
        }

        // Даты PML если есть
        if (!empty($bitrixData['ufCrm10_1708956056'])) {
            $contractData['pml_start_date'] = $this->formatDate($bitrixData['ufCrm10_1708956056']);
        }
        if (!empty($bitrixData['ufCrm10_1708955996'])) {
            $contractData['pml_end_date'] = $this->formatDate($bitrixData['ufCrm10_1708955996']);
        }

        return $contractData;
    }

    private function validateBitrixData(array $bitrixData) {
        $requiredFields = [
            'id', 'title', 'apartment_id', 'landlord_data',
            'ufCrm10_1693823247516', 'ufCrm10_1693823282826'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($bitrixData[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }

            if (empty($bitrixData[$field]) && $bitrixData[$field] !== '0') {
                throw new InvalidArgumentException("Empty value for required field: $field");
            }
        }
    }

    private function mapWorkModel($bitrixType) {
        $mappedType = ProjectMapping::mapWorkModel($bitrixType, $GLOBALS['projectName']);
        
        if ($mappedType === null) {
            $bitrixTypeStr = (string)$bitrixType;
            throw new InvalidArgumentException("Unknown work model type: $bitrixType");
        }
        
        return $mappedType;
    }

    private function findApartmentInAlma($apartmentBitrixId) {
        if (empty($apartmentBitrixId)) {
            throw new InvalidArgumentException("Apartment ID is empty");
        }

        try {
            // Используем прямой поиск по external_id для получения полного апартамента
            $url = $this->apiUrl . 'realty/units/external_id/' . $apartmentBitrixId . '/';
            
            Logger::info("Searching for apartment in Alma by external_id", [
                'bitrix_id' => $apartmentBitrixId,
                'url' => $url
            ], 'landlord_contract', $apartmentBitrixId);
            
            $response = $this->sendRequest('GET', $url);
            
            if (isset($response['id'])) {
                Logger::info("Found apartment in Alma", [
                    'bitrix_id' => $apartmentBitrixId,
                    'alma_id' => $response['id'],
                    'external_id' => $response['external_id'] ?? 'not_set',
                    'name' => $response['name'] ?? 'not_set'
                ], 'landlord_contract', $apartmentBitrixId);
                
                return $response['id'];
            }
            
            Logger::warning("Apartment not found - no ID in response", [
                'bitrix_id' => $apartmentBitrixId,
                'response' => $response
            ], 'landlord_contract', $apartmentBitrixId);
            
        } catch (Exception $e) {
            Logger::warning("Apartment not found in Alma: " . $e->getMessage(), [
                'bitrix_id' => $apartmentBitrixId,
                'url' => $url ?? 'not_set'
            ], 'landlord_contract', $apartmentBitrixId);
        }
        
        throw new InvalidArgumentException("Apartment not found in Alma for Bitrix ID: $apartmentBitrixId");
    }

    private function uploadFile($fileUrl) {
        if (empty($fileUrl)) {
            return null;
        }

        $uploadUrl = $this->apiUrl . 'external-image/';
        $fileData = [
            'url' => $fileUrl,
            'description' => 'Landlord contract document'
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
    $almaApi = new AlmaLandlordContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    
    // Устанавливаем глобальную переменную для использования в mapWorkModel
    $GLOBALS['projectName'] = $projectName;

    $contractResponse = $bitrix->call('crm.item.get', [
        'entityTypeId' => 148,
        'id' => $_GET['id'],
    ]);

    if (!isset($contractResponse['result']['item'])) {
        throw new Exception("LL Contract not found in Bitrix24 with ID: " . $_GET['id']);
    }
    
    $contractData = $contractResponse['result']['item'];

    $contactResponse = $bitrix->call('crm.contact.get', [
        'id' => $contractData['contactId']
    ]);
    
    if (!isset($contactResponse['result'])) {
        throw new Exception("Contact not found in Bitrix24 with ID: " . $contractData['contactId']);
    }
    
    $contact = $contactResponse['result'];

    $syncData = [
        'id' => $contractData['id'],
        'title' => $contractData['title'],
        'apartment_id' => $contractData['ufCrm10_1693823301'] ?? '',
        'landlord_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $contact['UF_CRM_1727788747'] ?? '',
            'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
            'birthday' => $contact['BIRTHDATE'] ?? '',
        ],
        'ufCrm10_1693823247516' => $contractData['ufCrm10_1693823247516'] ?? '',
        'ufCrm10_1693823282826' => $contractData['ufCrm10_1693823282826'] ?? '',
        'ufCrm10_1694000435068' => $contractData['ufCrm10_1694000435068'] ?? null,
        'ufCrm10_1694000558852' => $contractData['ufCrm10_1694000558852'] ?? null,
        'ufCrm10_1694000391518' => $contractData['ufCrm10_1694000391518'] ?? null,
        'ufCrm10_1694000636731' => $contractData['ufCrm10_1694000636731'] ?? null,
        'ufCrm10_1709042143' => $contractData['ufCrm10_1709042143'] ?? null,
        'ufCrm10_1708955821' => $contractData['ufCrm10_1708955821'] ?? '',
        'ufCrm10_1708956056' => $contractData['ufCrm10_1708956056'] ?? '',
        'ufCrm10_1708955996' => $contractData['ufCrm10_1708955996'] ?? '',
    ];

    $result = $almaApi->syncContract($syncData);

    if (isset($result['response']['id']) || (isset($result['code']) && $result['code'] >= 200 && $result['code'] < 300)) {
        $almaId = $result['response']['id'] ?? $result['id'] ?? null;
        $method = $result['method'] ?? 'unknown';
        $message = $method === 'POST' 
            ? 'Landlord contract successfully created in Alma' 
            : 'Landlord contract successfully updated in Alma';
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'alma_id' => $almaId,
            'method' => $method,
            'operation' => $method === 'POST' ? 'created' : 'updated',
            'data' => $result['response'] ?? $result
        ]);
    } else {
        $errorMessage = 'Landlord contract synchronization failed';
        if (isset($result['response']['error'])) {
            $errorMessage .= ': ' . $result['response']['error'];
        }
        throw new Exception($errorMessage);
    }

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
    Logger::error("Unexpected error in landlord contract synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'landlord_contract', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Landlord contract synchronization failed'
    ]);
}
