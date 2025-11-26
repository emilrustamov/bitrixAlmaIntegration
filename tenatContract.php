<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('EnhancedLogger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);
class AlmaTenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $isRoom = false;
    private $bitrix;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ContractActionLogger();
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
    }

    public function syncContract(array $bitrixData) {
        $this->validateBitrixData($bitrixData);

        Logger::info("syncContract called", [
            'contract_id' => $bitrixData['id'],
            'unit_external_id' => $bitrixData['unit_external_id'] ?? 'not_set',
            'client_id' => $bitrixData['client_data']['id'] ?? 'not_set'
        ], 'contract', $bitrixData['id']);

        try {
            $projectName = $GLOBALS['projectName'] ?? 'Dubai';
            $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
            $contractType = $bitrixData[$contractTypeField] ?? '';
            $this->mapContractType($contractType);
            
            $clientId = $this->ensureClientExists($bitrixData['client_data'], $contractType);
            $unitId = $this->findCorrectAlmaObject($bitrixData);
            $this->validateRentalObject($unitId, $bitrixData);
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            
            return $this->createOrUpdateContract($contractData, $bitrixData['id']);
        } catch (Exception $e) {
            EnhancedLogger::logContractError($bitrixData['id'], "Contract synchronization failed: " . $e->getMessage(), [
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new Exception("Contract synchronization failed: " . $e->getMessage());
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
                // Используем тот же external_id; если бэкенд Alma допускает дубликаты по external_id для новых usage, контракт создастся
                Logger::info("Existing contract has archived usage; creating a new contract with the same external_id instead of updating", [
                    'old_contract_id' => $existingContract['id'],
                    'external_id' => $externalId
                ], 'contract', $bitrixId);

                return $this->createContract($contractData);
            } else {
                // Можно обновлять
                Logger::info("Updating existing contract", [
                    'contract_id' => $existingContract['id'],
                    'external_id' => $externalId
                ], 'contract', $bitrixId);
                
                return $this->updateContract($existingContract['id'], $contractData);
            }
        } else {
            // Контракт не найден - создаем новый
            Logger::info("Creating new contract", [
                'external_id' => $externalId
            ], 'contract', $bitrixId);
            
            return $this->createContract($contractData);
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
        
        Logger::info("Creating contract with data", [
            'url' => $url,
            'contract_data' => $contractData
        ], 'contract', $contractData['external_id'] ?? 'unknown');
        
        try {
            $response = $this->sendRequest('POST', $url, $contractData);
            
            Logger::info("Contract created successfully", [
                'contract_id' => $response['id'] ?? 'unknown',
                'external_id' => $contractData['external_id'] ?? 'unknown'
            ], 'contract', $contractData['external_id'] ?? 'unknown');
            
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
        } catch (Exception $e) {
            EnhancedLogger::logContractError($contractData['external_id'] ?? 'unknown', "Failed to create contract: " . $e->getMessage(), [
                'contract_data' => $contractData,
                'url' => $url,
                'method' => 'POST'
            ]);
            
            return ['code' => 400, 'response' => ['error' => $e->getMessage()], 'method' => 'POST'];
        }
    }


    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContract($contractId);

        // Формируем данные для PATCH отдельно, чтобы сохранить исходные для возможного POST-фолбэка
        $patchData = $contractData;
        unset($patchData['client_id']); // Alma не позволяет менять клиента при PATCH
        
        try {
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
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
            if (stripos($message, 'forbidden to edit the archive usage') !== false) {
                Logger::warning("PATCH forbidden on archived usage; falling back to POST create", [
                    'contract_id' => $contractId,
                    'error' => $message
                ], 'contract', $contractId);
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
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        return $this->sendRequest('GET', $url);
    }

    public function getContractByExternalId($externalId) {
        // Согласно документации, API external_id/ для GET не существует
        // Ищем в общем списке контрактов
        try {
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
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


    private function validateRentalObject($unitId, array $bitrixData) {
        try {
            // Получаем детали объекта для проверки архивации
            $endpoint = $this->isRoom ? 'realty/rooms/' : 'realty/units/';
            $url = $this->apiUrl . $endpoint . $unitId . '/';
            $unitDetails = $this->sendRequest('GET', $url);
            
            $unitName = $unitDetails['name'] ?? 'Unknown';
            $objectType = $this->isRoom ? 'room' : 'unit';
            
            // Проверяем, что объект не заархивирован
            if (isset($unitDetails['is_archived']) && $unitDetails['is_archived']) {
                throw new Exception("Cannot create contract on archived $objectType: $unitName");
            }
            
        } catch (Exception $e) {
            Logger::error("Error validating rental object: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw $e;
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
        
        Logger::info("ensureClientExists called with clientData", [
            'client_id' => $clientData['id'],
            'email' => $clientData['email'],
            'first_name' => $clientData['first_name'],
            'last_name' => $clientData['last_name'],
            'is_airbnb' => $isAirbnb
        ], 'contract', $clientData['id']);
        
        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = $this->sendRequest('GET', $url);
            
            
            return $client['id'];
        } catch (Exception $e) {
            if (!empty($clientData['email'])) {
                try {
                    // Согласно документации, используем правильный API для получения списка клиентов
                    $searchUrl = $this->apiUrl . 'users/clients/';
                    $searchResponse = $this->sendRequest('GET', $searchUrl);
                    
                    if (is_array($searchResponse) && !empty($searchResponse)) {
                        $existingClient = null;
                        foreach ($searchResponse as $client) {
                            if (isset($client['email']) && $client['email'] === $clientData['email']) {
                                $existingClient = $client;
                                break;
                            }
                        }
                        
                        if ($existingClient) {
                            Logger::info("Found existing client by email, updating external_id", [
                                'existing_client_id' => $existingClient['id'],
                                'new_external_id' => $clientData['id'],
                                'email' => $clientData['email']
                            ]);
                            
                            $updateUrl = $this->apiUrl . 'users/clients/' . $existingClient['id'] . '/';
                            $this->sendRequest('PATCH', $updateUrl, ['external_id' => $clientData['id']]);
                            
                            return $existingClient['id'];
                        }
                    }
                } catch (Exception $searchException) {
                    Logger::warning("Failed to search client by email: " . $searchException->getMessage());
                }
            }
            
            try {
                $url = $this->apiUrl . 'users/clients/';
                $birthday = '1999-06-03T00:00:00Z';
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


    private function prepareContractData(array $bitrixData, $clientId, $unitId) {
        $projectName = $GLOBALS['projectName'] ?? 'Dubai';
        $startDateField = ProjectMapping::getContractField('start_date', $projectName);
        $endDateField = ProjectMapping::getContractField('end_date', $projectName);
        $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
        $historyField = ProjectMapping::getContractField('history', $projectName);
        $contractScanField = ProjectMapping::getContractField('contract_scan', $projectName);
        
        $startDate = $this->formatDate($bitrixData[$startDateField] ?? '');
        $endDate = $this->formatDate($bitrixData[$endDateField] ?? '');
        
        // Проверка, что дата окончания позже даты начала
        $startDateTime = new DateTime($startDate);
        $endDateTime = new DateTime($endDate);
        if ($endDateTime <= $startDateTime) {
            throw new InvalidArgumentException("End date must be after start date. Start: $startDate, End: $endDate");
        }
        
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

    // Удалено: генерация нового external_id больше не используется

    private function validateBitrixData(array $bitrixData) {
        $projectName = $GLOBALS['projectName'] ?? 'Dubai';
        $startDateField = ProjectMapping::getContractField('start_date', $projectName);
        $endDateField = ProjectMapping::getContractField('end_date', $projectName);
        
        $requiredFields = [
            'id', 'title', 'unit_external_id', 'client_data', 'opportunity'
        ];
        
        if ($startDateField) {
            $requiredFields[] = $startDateField;
        }
        
        if ($endDateField) {
            $requiredFields[] = $endDateField;
        }

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

    }

    private function mapContractType($bitrixType) {
        $mappedType = ProjectMapping::mapContractType($bitrixType, $GLOBALS['projectName']);
        
        if ($mappedType === null) {
            $bitrixTypeStr = (string)$bitrixType;
            
            if ($bitrixTypeStr === '8672') {
                throw new InvalidArgumentException("Ejari contracts are not synchronized to Alma");
            }
            
            throw new InvalidArgumentException("Unknown contract type: $bitrixType");
        }
        
        return $mappedType;
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
    
    

  
    public function findCorrectAlmaObject($bitrixData) {
        $unitId = $bitrixData['unit_external_id'];
        $projectName = $GLOBALS['projectName'] ?? 'Dubai';
        
        $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
        $apartmentEntityTypeId = ProjectMapping::getFieldMapping($projectName)['entity_type_id'];
        $unitApartmentField = ProjectMapping::getUnitApartmentField($projectName);
        $apartmentRentTypeField = ProjectMapping::getApartmentRentTypeField($projectName);
        
        $unitData = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => $unitEntityTypeId,
            'id' => $unitId
        ]);
        
        if (!$unitData) {
            throw new Exception("Unit not found in Bitrix24: $unitId");
        }
        
        $unitItem = $unitData['result']['item'] ?? $unitData;
        
        $apartmentId = null;
        if (isset($unitItem[$unitApartmentField])) {
            $apartmentFieldValue = $unitItem[$unitApartmentField];
            $apartmentId = is_array($apartmentFieldValue) ? ($apartmentFieldValue[0] ?? null) : $apartmentFieldValue;
        }
        
        Logger::info("Unit data analysis", [
            'unit_id' => $unitId,
            'apartment_id' => $apartmentId,
            'project' => $projectName
        ], 'contract', $bitrixData['id']);
        
        if (!$apartmentId) {
            throw new Exception("Apartment ID not found in unit: $unitId");
        }
        
        $apartmentData = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => $apartmentEntityTypeId,
            'id' => $apartmentId
        ]);
        
        if (!$apartmentData) {
            throw new Exception("Apartment not found in Bitrix24: $apartmentId");
        }
        
        $apartmentItem = $apartmentData['result']['item'] ?? $apartmentData;
        
        $rentType = null;
        if ($apartmentRentTypeField !== null) {
            $rentType = $apartmentItem[$apartmentRentTypeField] ?? null;
        }
        
        Logger::info("Determined rental type", [
            'unit_id' => $unitId,
            'apartment_id' => $apartmentId,
            'rent_type' => $rentType,
            'is_sharing' => ($rentType === 4600),
            'project' => $projectName
        ], 'contract', $bitrixData['id']);
        
        return $this->findAlmaObjectById($unitId);
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
        if (empty($bitrixId)) {
            throw new InvalidArgumentException("Bitrix ID is empty");
        }

        try {
            $url = $this->apiUrl . 'realty/rental_object/' . $bitrixId . '/';
            
            Logger::info("Searching for object in Alma", [
                'bitrix_id' => $bitrixId,
                'url' => $url
            ], 'contract', $bitrixId);
            
            $response = $this->sendRequest('GET', $url);
            
            if (isset($response['id'])) {
                $this->isRoom = !empty($response['parent_unit']);
                
                Logger::info("Found object in Alma", [
                    'bitrix_id' => $bitrixId,
                    'alma_id' => $response['id'],
                    'external_id' => $response['external_id'] ?? 'not_set',
                    'is_room' => $this->isRoom,
                    'parent_unit' => $response['parent_unit'] ?? null
                ], 'contract', $bitrixId);
                
                return $response['id'];
            }
            
            Logger::warning("Object not found - no ID in response", [
                'bitrix_id' => $bitrixId,
                'response' => $response
            ], 'contract', $bitrixId);
            
        } catch (Exception $e) {
            Logger::warning("Object not found in Alma: " . $e->getMessage(), [
                'bitrix_id' => $bitrixId,
                'url' => $url ?? 'not_set'
            ], 'contract', $bitrixId);
        }
        
        throw new InvalidArgumentException("Object not found in Alma for Bitrix ID: $bitrixId");
    }

}


try {
    $GLOBALS['projectName'] = $projectName;
    $almaApi = new AlmaTenantContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    
    $isDealBased = ProjectMapping::isDealBasedContract($projectName);
    $contractEntityTypeId = ProjectMapping::getContractEntityTypeId($projectName);
    $contractStageId = ProjectMapping::getContractStageId($projectName);

    try {
        if ($isDealBased) {
            $contractResponse = $bitrix->call('crm.deal.get', [
                'id' => $_GET['id'],
            ]);
            
            if (!isset($contractResponse['result'])) {
                throw new Exception("Deal not found in Bitrix24 with ID: " . $_GET['id']);
            }
            
            $dealData = $contractResponse['result'];
            
            if ($isDealBased && $contractStageId !== null && $dealData['STAGE_ID'] !== $contractStageId) {
                throw new Exception("Deal stage is not 'Contract signed'. Current stage: " . $dealData['STAGE_ID'] . ", required: " . $contractStageId);
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
            
            if (!isset($contractResponse['result']['item'])) {
                throw new Exception("Contract not found in Bitrix24 with ID: " . $_GET['id']);
            }
            
            $contractData = $contractResponse['result']['item'];
        }
    } catch (Exception $e) {
        Logger::error("Failed to get contract from Bitrix24", [
            'contract_id' => $_GET['id'],
            'is_deal_based' => $isDealBased,
            'error' => $e->getMessage()
        ], 'contract', $_GET['id']);
        throw new Exception("Failed to get contract from Bitrix24: " . $e->getMessage());
    }

    $contactId = $contractData['contactId'] ?? null;
    if (!$contactId && isset($contractData['CONTACT_ID'])) {
        $contactId = $contractData['CONTACT_ID'];
    }
    
    if (!$contactId) {
        throw new Exception("Contact ID not found in contract/deal data");
    }
    
    try {
        $contactResponse = $bitrix->call('crm.contact.get', [
            'id' => $contactId
        ]);
    } catch (Exception $e) {
        Logger::error("Failed to get contact from Bitrix24", [
            'contact_id' => $contactId,
            'error' => $e->getMessage()
        ], 'contract', $_GET['id']);
        throw new Exception("Failed to get contact from Bitrix24: " . $e->getMessage());
    }
    
    if (!isset($contactResponse['result'])) {
        throw new Exception("Contact not found in Bitrix24 with ID: " . $contactId);
    }
    
    $contact = $contactResponse['result'];

    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $historyField = ProjectMapping::getContractField('history', $projectName);
    $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
    $contractScanField = ProjectMapping::getContractField('contract_scan', $projectName);
    
    // Получаем email из стандартного поля контакта
    $email = '';
    if (!empty($contact['EMAIL']) && is_array($contact['EMAIL'])) {
        $email = $contact['EMAIL'][0]['VALUE'] ?? '';
    }
    
    $syncData = [
        'id' => $contractData['id'],
        'title' => $contractData['title'],
        'unit_external_id' => $contractData[ProjectMapping::getContractField('unit_id', $projectName)] ?? '',
        'client_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $email,
            'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
            'birthday' => $contact['BIRTHDATE'] ?? '',
        ],
        'opportunity' => $contractData['opportunity'] ?? 0,
    ];
    
    // Добавляем поля с правильными именами из маппера
    if ($startDateField) {
        $syncData[$startDateField] = $contractData[$startDateField] ?? '';
    }
    
    if ($endDateField) {
        $syncData[$endDateField] = $contractData[$endDateField] ?? '';
    }
    
    if ($historyField) {
        $syncData[$historyField] = $contractData[$historyField] ?? '';
    }
    
    if ($contractTypeField) {
        $syncData[$contractTypeField] = $contractData[$contractTypeField] ?? '';
    }
    
    if ($contractScanField) {
        $syncData[$contractScanField] = $contractData[$contractScanField] ?? null;
    }

    $result = $almaApi->syncContract($syncData);

    if (isset($result['response']['id']) || (isset($result['code']) && $result['code'] >= 200 && $result['code'] < 300)) {
        $almaId = $result['response']['id'] ?? $result['id'] ?? null;
        $method = $result['method'] ?? 'unknown';
        $message = $method === 'POST' 
            ? 'Contract successfully created in Alma' 
            : 'Contract successfully updated in Alma';
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'alma_id' => $almaId,
            'method' => $method,
            'operation' => $method === 'POST' ? 'created' : 'updated',
            'data' => $result['response'] ?? $result
        ]);
    } else {
        $errorMessage = 'Contract synchronization failed';
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
    EnhancedLogger::logContractError($_GET['id'] ?? 'unknown', "Unexpected error in contract synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Contract synchronization failed'
    ]);
}