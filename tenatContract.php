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
            $clientId = $this->ensureClientExists($bitrixData['client_data']);
            $unitId = $this->findCorrectAlmaObject($bitrixData);
            $this->validateRentalObject($unitId, $bitrixData);
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            
            return $this->createOrUpdateContract($contractData, $bitrixData['id']);
        } catch (Exception $e) {
            Logger::error("Contract synchronization failed: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
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
            Logger::error("Failed to create contract: " . $e->getMessage(), [
                'contract_data' => $contractData,
                'url' => $url
            ], 'contract', $contractData['external_id'] ?? 'unknown');
            
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
            // Если Alma запрещает редактировать архивное использование — пробуем создать новый контракт с теми же данными
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


    public function ensureClientExists(array $clientData) {
        Logger::info("ensureClientExists called with clientData", [
            'client_id' => $clientData['id'],
            'email' => $clientData['email'],
            'first_name' => $clientData['first_name'],
            'last_name' => $clientData['last_name']
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
        $startDate = $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']);
        $endDate = $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']);
        
        
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

    // Удалено: генерация нового external_id больше не используется

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

    }

    private function mapContractType($bitrixType) {
        $mappedType = ProjectMapping::mapContractType($bitrixType, $GLOBALS['projectName']);
        
        if ($mappedType === null) {
            $bitrixTypeStr = (string)$bitrixType;
            
            if ($bitrixTypeStr === '882') {
                throw new InvalidArgumentException("Airbnb contracts are not synchronized to Alma");
            }
            
            if ($bitrixTypeStr === '8672') {
                throw new InvalidArgumentException("Ejari contracts are not synchronized to Alma");
            }
            
            throw new InvalidArgumentException("Unknown contract type: $bitrixType");
        }
        
        return $mappedType;
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
     * DEPRECATED: Устаревший метод, не используется в tenatContract.php
     * Заменён на findAlmaObjectById() который возвращает только ID объекта
     * 
     * Этот метод возвращает полный ответ с HTTP кодом,
     * но не вызывается ни в одном месте этого файла
     */
    /*
    public function getRentalObject($bitrixId) {
        $url = $this->apiUrl . 'realty/rental_object/' . rawurlencode($bitrixId) . '/';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'GET'
        ]);
        $response = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($code >= 400 && $code !== 404) {
            Logger::logApiRequest('GET', $url, [], $response, $code);
        }

        $decoded = json_decode($response, true);
        return ['code' => $code, 'response' => $decoded];
    }
    */

    /**
     * Найти правильный объект в Alma согласно логике шеринга
     * 
     * Используется rental_object API с системой приоритетов:
     * - Приоритет 3: Полный юнит по additional_external_id (is_used = true)
     * - Приоритет 2: Дочерние объекты (parent_unit > 0) 
     * - Приоритет 1: Обычные объекты
     * - Приоритет 0: Специальный случай (additional = "", is_used = true)
     */
    public function findCorrectAlmaObject($bitrixData) {
        $unitId = $bitrixData['unit_external_id'];
        
        // Получаем данные юнита из Bitrix24
        $unitData = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 167,
            'id' => $unitId
        ]);
        
        if (!$unitData) {
            throw new Exception("Unit not found in Bitrix24: $unitId");
        }
        
        // Извлекаем данные из структуры result.item
        $unitItem = $unitData['result']['item'] ?? $unitData;
        
        // Получаем ID апартамента из юнита
        $apartmentId = $unitItem['ufCrm8_1684429208'][0] ?? null;
        
        Logger::info("Unit data analysis", [
            'unit_id' => $unitId,
            'apartment_id' => $apartmentId
        ], 'contract', $bitrixData['id']);
        
        if (!$apartmentId) {
            throw new Exception("Apartment ID not found in unit: $unitId");
        }
        
        // Получаем данные апартамента из Bitrix24
        $apartmentData = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 144,
            'id' => $apartmentId
        ]);
        
        if (!$apartmentData) {
            throw new Exception("Apartment not found in Bitrix24: $apartmentId");
        }
        
        // Извлекаем данные из структуры result.item
        $apartmentItem = $apartmentData['result']['item'] ?? $apartmentData;
        
        // Определяем тип аренды
        $rentType = $apartmentItem['ufCrm6_1736951470242'] ?? null;
        
        Logger::info("Determined rental type", [
            'unit_id' => $unitId,
            'apartment_id' => $apartmentId,
            'rent_type' => $rentType,
            'is_sharing' => ($rentType === 4600)
        ], 'contract', $bitrixData['id']);
        
        // Всегда ищем по ID комнаты через rental_object
        // rental_object API автоматически определит правильный объект по приоритетам
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

    /**
     * DEPRECATED: Устаревший метод, не используется
     * Заменён на findAlmaObjectById() который использует rental_object API
     * 
     * Найти апартамент в Alma по Bitrix ID
     * GET: external_api/realty/units/ (ищем по external_id)
     * 
     * Проблемы этого метода:
     * - Неэффективен (получает ВСЕ апартаменты из базы)
     * - Не учитывает систему приоритетов rental_object API
     * - Не работает с комнатами (rooms)
     * - Игнорирует additional_external_id и is_used_additional_external_id
     */
    /*
    public function findAlmaApartmentById($bitrixId) {
        if (empty($bitrixId)) {
            throw new InvalidArgumentException("Bitrix ID is empty");
        }

        try {
            $url = $this->apiUrl . 'realty/units/';
            
            Logger::info("Searching for apartment in Alma", [
                'bitrix_id' => $bitrixId,
                'url' => $url
            ], 'contract', $bitrixId);
            
            $response = $this->sendRequest('GET', $url);
            
            Logger::info("Apartments API response received", [
                'bitrix_id' => $bitrixId,
                'apartments_count' => is_array($response) ? count($response) : 0
            ], 'contract', $bitrixId);
            
            if (is_array($response)) {
                foreach ($response as $apartment) {
                    if (isset($apartment['external_id']) && $apartment['external_id'] == $bitrixId) {
                        Logger::info("Found apartment in Alma", [
                            'bitrix_id' => $bitrixId,
                            'alma_id' => $apartment['id'],
                            'external_id' => $apartment['external_id'],
                            'name' => $apartment['name'] ?? 'not_set'
                        ], 'contract', $bitrixId);
                        
                        return $apartment['id'];
                    }
                }
            }
            
            Logger::warning("Apartment not found in Alma", [
                'bitrix_id' => $bitrixId,
                'searched_in' => is_array($response) ? count($response) : 0
            ], 'contract', $bitrixId);
            
        } catch (Exception $e) {
            Logger::warning("Error searching apartment in Alma: " . $e->getMessage(), [
                'bitrix_id' => $bitrixId
            ], 'contract', $bitrixId);
        }
        
        throw new InvalidArgumentException("Apartment not found in Alma for Bitrix ID: $bitrixId");
    }
    */
}


try {
    $almaApi = new AlmaTenantContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);

    $contractResponse = $bitrix->call('crm.item.get', [
        'entityTypeId' => 183,
        'id' => $_GET['id'],
    ]);

    if (!isset($contractResponse['result']['item'])) {
        throw new Exception("Contract not found in Bitrix24 with ID: " . $_GET['id']);
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
        'unit_external_id' => $contractData['ufCrm20_1693919019'] ?? '',
        'client_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $contact['UF_CRM_1727788747'] ?? '',
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