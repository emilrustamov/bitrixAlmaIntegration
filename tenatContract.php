<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

// Получаем проект из параметра или определяем автоматически
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

        try {
            $clientId = $this->ensureClientExists($bitrixData['client_data']);
            $unitId = $this->getCorrectAlmaUnitId($bitrixData['unit_external_id']);
            $this->validateRentalObject($unitId, $bitrixData);
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            $externalId = $contractData['external_id'];
            
            $existingContract = $this->getContractByExternalId($externalId);

            if ($existingContract) {
                // Проверяем, изменился ли клиент
                if ($existingContract['unit_usage']['client_id'] != $clientId) {
                    Logger::info("Client changed in contract, creating new contract", [
                        'old_client_id' => $existingContract['unit_usage']['client_id'],
                        'new_client_id' => $clientId,
                        'contract_external_id' => $externalId
                    ]);
                    return $this->createContract($contractData);
                } else {
                    return $this->updateContract($existingContract['id'], $contractData);
                }
            } else {
                // Проверяем, есть ли другие контракты на этом объекте
                $this->checkExistingContractsOnUnit($unitId, $externalId);
                return $this->createContract($contractData);
            }
        } catch (Exception $e) {
            Logger::error("Contract synchronization failed: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw new Exception("Contract synchronization failed: " . $e->getMessage());
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
        
        // Логируем данные контракта перед созданием
        Logger::info("Creating contract", [
            'contract_data' => $contractData,
            'url' => $url
        ], 'contract', $contractData['external_id'] ?? 'unknown');
        
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
            return $result;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            Logger::error("Error getting contract by external_id: $externalId - " . $e->getMessage());
            throw $e;
        }
    }



    public function getRentalObjectId($externalId) {
        $url = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
        
        // Логируем запрос к rental_object API
        Logger::info("Getting rental object", [
            'external_id' => $externalId,
            'url' => $url
        ], 'contract', $externalId);
        
        $response = $this->sendRequest('GET', $url);

        if (!isset($response['id'])) {
            Logger::warning("Rental object not found via rental_object API", ['external_id' => $externalId]);
            throw new Exception("Rental object not found for external_id: $externalId");
        }

        // Сохраняем информацию о типе объекта для дальнейшего использования
        $this->isRoom = isset($response['parent_unit']) && $response['parent_unit'] !== null;

        // Логируем найденный объект
        Logger::info("Rental object found", [
            'external_id' => $externalId,
            'alma_id' => $response['id'],
            'is_room' => $this->isRoom,
            'parent_unit' => $response['parent_unit'] ?? null,
            'additional_external_id' => $response['additional_external_id'] ?? null
        ], 'contract', $externalId);

        return $response['id'];
    }

    private function validateRentalObject($unitId, array $bitrixData) {
        try {
            // Сначала пробуем как юнит (room)
            $unitUrl = $this->apiUrl . 'realty/rooms/' . $unitId . '/';
            try {
                $unitDetails = $this->sendRequest('GET', $unitUrl);
                $this->isRoom = true;
            } catch (Exception $e) {
                // Если не найден как юнит, пробуем как апартамент (unit)
                $unitUrl = $this->apiUrl . 'realty/units/' . $unitId . '/';
                $unitDetails = $this->sendRequest('GET', $unitUrl);
                $this->isRoom = false;
            }
            
            $unitName = $unitDetails['name'] ?? 'Unknown';
            $contractId = $bitrixData['id'] ?? 'unknown';
            $objectType = $this->isRoom ? 'room' : 'unit';
            $unitStatus = $unitDetails['status'] ?? 'unknown';
            
            // Логируем статус объекта для отладки
            Logger::info("Unit status check", [
                'unit_id' => $unitId,
                'unit_name' => $unitName,
                'status' => $unitStatus,
                'contract_id' => $contractId
            ], 'contract', $contractId);
            
            // Закомментировано для тестирования - разрешаем создание контрактов на заблокированных объектах
            // if (isset($unitDetails['status']) && $unitDetails['status'] === 'blocked') {
            //     throw new Exception("Cannot create contract on blocked $objectType: $unitName");
            // }
            
            // Если объект заархивирован - разархивируем его
            if (isset($unitDetails['is_archived']) && $unitDetails['is_archived']) {
                Logger::warning("$objectType $unitName is archived, unarchiving for new contract", [], 'contract', $contractId);
                $this->unarchiveUnit($unitId);
                
                // Получаем обновленные данные после разархивирования
                $unitDetails = $this->sendRequest('GET', $unitUrl);
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
            // Сначала пытаемся найти по external_id
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = $this->sendRequest('GET', $url);
            
            // Проверяем, не заархивирован ли клиент
            if (isset($client['status']) && $client['status'] === 'archived') {
                Logger::warning("Client is archived, unarchiving for new contract", ['client_id' => $client['id']]);
                $this->unarchiveClient($client['id']);
                
                // Получаем обновленные данные клиента после разархивирования
                $client = $this->sendRequest('GET', $url);
            }
            
            return $client['id'];
        } catch (Exception $e) {
            // Если не нашли по external_id, пытаемся найти по email
            if (!empty($clientData['email'])) {
                try {
                    $searchUrl = $this->apiUrl . 'users/clients/?email=' . urlencode($clientData['email']);
                    $searchResponse = $this->sendRequest('GET', $searchUrl);
                    
                    if (is_array($searchResponse) && !empty($searchResponse)) {
                        // Ищем клиента с нужным email
                        $existingClient = null;
                        foreach ($searchResponse as $client) {
                            if (isset($client['email']) && $client['email'] === $clientData['email']) {
                                $existingClient = $client;
                                break;
                            }
                        }
                        
                        if (!$existingClient) {
                            Logger::warning("Client with email not found in search results", ['email' => $clientData['email']]);
                            throw new Exception("Client with email not found: " . $clientData['email']);
                        }
                        Logger::warning("Found existing client by email, updating external_id", [
                            'existing_client_id' => $existingClient['id'],
                            'new_external_id' => $clientData['id'],
                            'email' => $clientData['email']
                        ]);
                        
                        // Обновляем external_id существующего клиента
                        $updateUrl = $this->apiUrl . 'users/clients/' . $existingClient['id'] . '/';
                        $this->sendRequest('PATCH', $updateUrl, ['external_id' => $clientData['id']]);
                        
                        return $existingClient['id'];
                    }
                } catch (Exception $searchException) {
                    Logger::warning("Failed to search client by email: " . $searchException->getMessage());
                }
            }
            
            // Если не нашли ни по external_id, ни по email, создаем нового
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

    private function checkExistingContractsOnUnit($unitId, $externalId) {
        try {
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/?unit_id=' . $unitId;
            $contracts = $this->sendRequest('GET', $url);
            
            Logger::info("Existing contracts on unit", [
                'unit_id' => $unitId,
                'contracts_count' => is_array($contracts) ? count($contracts) : 0,
                'contracts' => $contracts,
                'external_id' => $externalId
            ], 'contract', $externalId);
            
            // Если есть заархивированные контракты, попробуем их разархивировать
            if (is_array($contracts)) {
                foreach ($contracts as $contract) {
                    if (isset($contract['unit_usage']['is_archived']) && $contract['unit_usage']['is_archived']) {
                        Logger::warning("Found archived contract, attempting to unarchive", [
                            'contract_id' => $contract['id'],
                            'unit_id' => $unitId
                        ], 'contract', $externalId);
                        
                        try {
                            $this->unarchiveContract($contract['id']);
                        } catch (Exception $e) {
                            Logger::warning("Could not unarchive contract: " . $e->getMessage(), [
                                'contract_id' => $contract['id']
                            ], 'contract', $externalId);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            Logger::warning("Could not check existing contracts: " . $e->getMessage(), [
                'unit_id' => $unitId
            ], 'contract', $externalId);
        }
    }

    private function unarchiveContract($contractId) {
        try {
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
            $data = ['unit_usage' => ['is_archived' => false]];
            return $this->sendRequest('PATCH', $url, $data);
        } catch (Exception $e) {
            Logger::warning("Could not unarchive contract directly: " . $e->getMessage(), ['contract_id' => $contractId]);
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
        $startDate = $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']);
        $endDate = $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']);
        
        // Логируем даты для отладки
        Logger::info("Contract dates processing", [
            'contract_id' => $bitrixData['id'],
            'raw_start_date' => $bitrixData['UF_CRM_20_CONTRACT_START_DATE'],
            'raw_end_date' => $bitrixData['UF_CRM_20_CONTRACT_END_DATE'],
            'formatted_start_date' => $startDate,
            'formatted_end_date' => $endDate,
            'unit_id' => $unitId
        ], 'contract', $bitrixData['id']);
        
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
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

    /**
     * Получить alma_id для объекта на основе ID из Bitrix24
     */
    public function getCorrectAlmaUnitId($unitId) {
        if (empty($unitId)) {
            throw new InvalidArgumentException("Unit ID is empty");
        }

        // Определяем тип объекта и получаем данные
        $bitrixData = $this->getBitrixUnitData($unitId);
        
        // Проверяем статус "Stage for alma" для апартаментов
        if ($bitrixData['type'] === 'apartment' && $bitrixData['stage_for_alma'] === 'Ex-Apartments') {
            throw new InvalidArgumentException("Apartment has 'Ex-Apartments' status - cannot synchronize");
        }

        // Ищем объект в Alma
        $almaId = $this->findAlmaObject($bitrixData);
        
        if (!$almaId) {
            throw new InvalidArgumentException("Object not found in Alma for unit ID: $unitId");
        }

        return $almaId;
    }

    /**
     * Получить данные объекта из Bitrix24
     */
    private function getBitrixUnitData($unitId) {
        // Получаем маппинг полей для проекта
        $projectMapping = ProjectMapping::getFieldMapping('Dubai');
        
        // Сначала пробуем как юнит
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 167, // Юниты
            'id' => $unitId,
        ]);

        if (isset($response['result']['item'])) {
            $unit = $response['result']['item'];
            $apartmentId = $unit['parentId144'] ?? null;
            
            // Получаем данные апартамента для определения типа (шеринговый или нет)
            $rentType = 'unit'; // по умолчанию
            if ($apartmentId) {
                try {
                    $apartmentResponse = $this->bitrix->call('crm.item.get', [
                        'entityTypeId' => $projectMapping['entity_type_id'],
                        'id' => $apartmentId,
                    ]);
                    
                    if (isset($apartmentResponse['result']['item'])) {
                        $apartment = $apartmentResponse['result']['item'];
                        $rentTypeField = $projectMapping['fields']['rent_type'];
                        $rentTypeValue = $apartment[$rentTypeField] ?? '4598'; // По умолчанию unit
                        $rentTypeMapping = $projectMapping['rent_type_mapping'];
                        $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
                    }
                } catch (Exception $e) {
                    Logger::warning("Could not get apartment data for rent_type", ['apartment_id' => $apartmentId, 'error' => $e->getMessage()]);
                }
            }
            
            return [
                'type' => 'unit',
                'id' => $unit['id'],
                'apartment_id' => $apartmentId,
                'stage_for_alma' => null,
                'rent_type' => $rentType,
                'is_sharing' => ($rentType === 'rooms')
            ];
        }

        // Если не найден как юнит, пробуем как апартамент
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => $projectMapping['entity_type_id'], // Используем маппинг
            'id' => $unitId,
        ]);

        if (isset($response['result']['item'])) {
            $apartment = $response['result']['item'];
            $rentTypeField = $projectMapping['fields']['rent_type'];
            $rentTypeValue = $apartment[$rentTypeField] ?? '4598'; // По умолчанию unit
            $rentTypeMapping = $projectMapping['rent_type_mapping'];
            $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
            
            return [
                'type' => 'apartment',
                'id' => $apartment['id'],
                'apartment_id' => $apartment['id'],
                'stage_for_alma' => $apartment[$projectMapping['fields']['stage']] ?? null,
                'rent_type' => $rentType,
                'is_sharing' => ($rentType === 'rooms')
            ];
        }

        throw new InvalidArgumentException("Unit not found in Bitrix24 with ID: $unitId");
    }

    /**
     * Найти объект в Alma (правильная логика для шеринговых и обычных апартаментов)
     */
    private function findAlmaObject($bitrixData) {
        try {
            // Для шеринговых апартаментов ищем комнату по unit_id
            if ($bitrixData['is_sharing'] && $bitrixData['type'] === 'unit') {
                $url = $this->apiUrl . 'realty/rental_object/' . $bitrixData['id'] . '/';
                $response = $this->sendRequest('GET', $url);
                
                if (isset($response['id'])) {
                    // Проверяем, что это комната (есть parent_unit)
                    if (isset($response['parent_unit']) && $response['parent_unit']) {
                        $this->isRoom = true; // Это комната
                        Logger::info("Found sharing room in Alma", [
                            'unit_id' => $bitrixData['id'],
                            'room_id' => $response['id'],
                            'parent_unit' => $response['parent_unit']
                        ], 'contract', $bitrixData['id']);
                        return $response['id'];
                    } else {
                        Logger::warning("Expected room but found apartment for sharing unit", [
                            'unit_id' => $bitrixData['id'],
                            'alma_id' => $response['id']
                        ], 'contract', $bitrixData['id']);
                    }
                }
            } else {
                // Для обычных апартаментов ищем апартамент по apartment_id
                $url = $this->apiUrl . 'realty/rental_object/' . $bitrixData['apartment_id'] . '/';
                $response = $this->sendRequest('GET', $url);
                
                if (isset($response['id'])) {
                    // Проверяем, что это апартамент (нет parent_unit)
                    if (!isset($response['parent_unit']) || !$response['parent_unit']) {
                        $this->isRoom = false; // Это апартамент
                        Logger::info("Found regular apartment in Alma", [
                            'apartment_id' => $bitrixData['apartment_id'],
                            'alma_id' => $response['id']
                        ], 'contract', $bitrixData['id']);
                        return $response['id'];
                    } else {
                        Logger::warning("Expected apartment but found room for regular unit", [
                            'apartment_id' => $bitrixData['apartment_id'],
                            'alma_id' => $response['id'],
                            'parent_unit' => $response['parent_unit']
                        ], 'contract', $bitrixData['id']);
                    }
                }
            }
        } catch (Exception $e) {
            Logger::warning("Failed to find Alma object via rental_object API", [
                'bitrix_data' => $bitrixData,
                'error' => $e->getMessage()
            ], 'contract', $bitrixData['id']);
        }

        return null;
    }

}



try {
    $almaApi = new AlmaTenantContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);

    // Получаем данные контракта
    $contractResponse = $bitrix->call('crm.item.get', [
        'entityTypeId' => 183,
        'id' => $_GET['id'],
    ]);

    if (!isset($contractResponse['result']['item'])) {
        throw new Exception("Contract not found in Bitrix24 with ID: " . $_GET['id']);
    }
    
    $contractData = $contractResponse['result']['item'];

    // Получаем данные контакта
    $contactResponse = $bitrix->call('crm.contact.get', [
        'id' => $contractData['contactId']
    ]);
    
    if (!isset($contactResponse['result'])) {
        throw new Exception("Contact not found in Bitrix24 with ID: " . $contractData['contactId']);
    }
    
    $contact = $contactResponse['result'];

    // Подготавливаем данные для синхронизации
    $syncData = [
        'id' => $contractData['id'],
        'title' => $contractData['title'],
        'unit_external_id' => $contractData['ufCrm20_1693919019'] ?? '',
        'client_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $contact['EMAIL'][0]['VALUE'] ?? '',
            'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
            'birthday' => $contact['BIRTHDATE'] ?? '',
        ],
        'UF_CRM_20_CONTRACT_START_DATE' => $contractData['ufCrm20ContractStartDate'] ?? '',
        'UF_CRM_20_CONTRACT_END_DATE' => $contractData['ufCrm20ContractEndDate'] ?? '',
        'opportunity' => $contractData['opportunity'] ?? 0,
        'UF_CRM_20_CONTRACT_HISTORY' => $contractData['ufCrm20ContractHistory'] ?? '',
        'UF_CRM_20_1693561495' => $contractData['ufCrm20_1693561495'] ?? '',
        'ufCrm20Contract' => $contractData['ufCrm20Contract'] ?? null,
    ];

    // Синхронизируем контракт
    $result = $almaApi->syncContract($syncData);

    // Возвращаем результат
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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}