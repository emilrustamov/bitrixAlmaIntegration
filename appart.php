<?php

require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

$projectName   = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping  = ProjectMapping::getFieldMapping($projectName);

define('WEBHOOK_URL',     $projectConfig['webhook_url']);
define('ALMA_API_KEY',    Config::get('ALMA_API_KEY'));
define('ALMA_API_URL',    Config::get('ALMA_API_URL'));
define('PROJECT_ID',      $projectConfig['id']);

$METRO = $fieldMapping['metro_mapping'];

class Bitrix24Rest {
    private $webhookUrl;
    public function __construct($webhookUrl) { $this->webhookUrl = $webhookUrl; }

    public function call($method, $params = []) {
        $url = $this->webhookUrl . $method;
        $query = http_build_query($params);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $query,
        ]);

        $result = curl_exec($curl);
        curl_close($curl);

        return json_decode($result, true);
    }
}

class AlmaApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->actionLogger = new ApartmentActionLogger();
    }

    private function baseHeaders() {
        return [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $url = $this->apiUrl . '/realty/' . ltrim($endpoint, '/');
        $headers = $this->baseHeaders();
        

        $curl = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($isPost) {
            if ($method === 'PATCH') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $options[CURLOPT_POSTFIELDS]   = json_encode($data, JSON_UNESCAPED_UNICODE);
            } else {
                $options[CURLOPT_CUSTOMREQUEST] = 'POST';
                $options[CURLOPT_POSTFIELDS]   = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = 'GET';
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
                $options[CURLOPT_URL] = $url;
            }
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode >= 400) {
            Logger::logApiRequest($method, $url, $data, $response, $httpCode);
            
            // Логируем подробности ошибки для отладки
            $errorDetails = json_decode($response, true);
            if ($errorDetails) {
                Logger::error("API Error Details", [
                    'method' => $method,
                    'url' => $url,
                    'http_code' => $httpCode,
                    'error' => $errorDetails
                ]);
            } else {
                Logger::error("API Error (Non-JSON)", [
                    'method' => $method,
                    'url' => $url,
                    'http_code' => $httpCode,
                    'response_preview' => substr($response, 0, 200)
                ]);
            }
        }

        return [
            'code'     => $httpCode,
            'response' => json_decode($response, true)
        ];
    }

    public function getRentalObject($bitrixId) {
        $url = rtrim(ALMA_API_URL, '/') . '/realty/rental_object/' . rawurlencode($bitrixId) . '/';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->baseHeaders(),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'GET'
        ]);
        $response = curl_exec($curl);
        $code     = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($code >= 400 && $code !== 404) {
            Logger::logApiRequest('GET', $url, [], $response, $code);
        }

        $decoded = json_decode($response, true);


        return ['code' => $code, 'response' => $decoded];
    }

    public function createOrUpdateApartment($data, $bitrixId) {
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200 && is_array($rentalObject['response'])) {
            $targetId = !empty($rentalObject['response']['parent_unit'])
                ? $rentalObject['response']['parent_unit']
                : $rentalObject['response']['id'];

            $endpoint = 'units/' . $targetId . '/';


            $oldData  = $rentalObject['response'];
            
            $patchData = $data;
            if (isset($patchData['building']) && is_array($patchData['building'])) {
                $patchData['building'] = $patchData['building']['id'];
            }
            
            // API Alma требует name и building при обновлении
            if (!isset($patchData['name']) || empty($patchData['name'])) {
                $patchData['name'] = $data['name'] ?? ('Apartment ' . $bitrixId);
            }
            if (!isset($patchData['building']) || empty($patchData['building'])) {
                $patchData['building'] = $data['building'];
            }
            
            $response = $this->call('PATCH', $endpoint, $patchData, true);
            $response['method'] = 'PATCH';

            if ($response['code'] === 200) {
                $this->actionLogger->logUpdate(
                    $targetId,
                    $data['name'] ?? ('Apartment ' . $bitrixId),
                    $oldData,
                    $data
                );
            }
            return $response;
        }

        $endpoint = 'units/';
        $response = $this->call('POST', $endpoint, $data, true);
        $response['method'] = 'POST';

        if (isset($response['response']['id'])) {
            $this->actionLogger->logApartmentCreation(
                $response['response']['id'],
                $data['name'] ?? ('Apartment ' . $bitrixId),
                $data['building'] ?? 'Unknown Building',
                $data['property_type'] ?? 'apartment',
                [
                    'external_id'        => $data['external_id'] ?? $bitrixId,
                    'number'             => $data['number'] ?? '',
                    'internal_area'      => $data['internal_area'] ?? 0,
                    'goal_rent_cost'     => $data['goal_rent_cost'] ?? 0,
                    'address'            => $data['address'] ?? '',
                    'number_of_bedrooms' => $data['number_of_bedrooms'] ?? 1,
                    'number_of_baths'    => $data['number_of_baths'] ?? 0,
                ]
            );
        }

        return $response;
    }


    public function createOrGetBuilding($buildingName) {
        $response = $this->call('GET', 'buildings/', [], false);

        if ($response['code'] === 200 && !empty($response['response'])) {
            foreach ($response['response'] as $building) {
                if (trim($building['name']) === trim($buildingName)) {
                    return $building['id'];
                }
            }
        }

        $data = ['name' => $buildingName, 'project' => PROJECT_ID];
        $response = $this->call('POST', 'buildings/', $data, true);

        if ($response['code'] === 201 && !empty($response['response']['id'])) {
            return $response['response']['id'];
        }

        throw new Exception('Failed to create building: ' . json_encode($response));
    }

    public function getActionLogger() { return $this->actionLogger; }
}

try {
    $apartmentId = $_GET['id'] ?? null;
    if (!$apartmentId) { throw new Exception('Apartment ID is required'); }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma   = new AlmaApi(ALMA_API_KEY, ALMA_API_URL);

    $bitrixApartment = $bitrix->call('crm.item.get', [
        'entityTypeId' => $fieldMapping['entity_type_id'],
        'id' => $apartmentId
    ]);

    if (!isset($bitrixApartment['result']['item'])) {
        throw new Exception('Failed to get apartment data from Bitrix24');
    }

    $apartmentData = $bitrixApartment['result']['item'];

    $stageField    = $fieldMapping['fields']['stage'];
    $stageForAlma  = $apartmentData[$stageField] ?? '';
    $allowedStages = ['Available', 'Rented'];
    if ($projectName !== 'HongKong' || !empty($stageForAlma)) {
        if (!in_array($stageForAlma, $allowedStages, true)) {
            echo json_encode([
                'success' => false,
                'message' => 'Apartment stage not allowed for sync. Current stage: ' . $stageForAlma,
                'stage'   => $stageForAlma,
                'allowed_stages' => $allowedStages
            ]);
            exit;
        }
    }

    $buildingNameField = $fieldMapping['fields']['building_name'];
    $buildingName = trim($apartmentData[$buildingNameField] ?? 'Default Building');
    if ($buildingName === '') { $buildingName = 'Default Building'; }
    
    
    try {
        $buildingId = $alma->createOrGetBuilding($buildingName);
    } catch (Exception $e) {
        Logger::error("Failed to get building", ['building_name' => $buildingName, 'error' => $e->getMessage()], 'apartment', $apartmentId);
        throw new Exception("Failed to get building '$buildingName': " . $e->getMessage());
    }

    $propertyTypeMap = $fieldMapping['property_type_mapping'];
    $bedroomsMap     = $fieldMapping['bedrooms_mapping'];
    $propertyTypeField = $fieldMapping['fields']['property_type'];
    $apartmentType     = $apartmentData[$propertyTypeField] ?? '52';
    $propertyType      = $propertyTypeMap[$apartmentType] ?? 'apartment';
    $numberOfBedrooms  = $bedroomsMap[$apartmentType]     ?? 1;

    $externalId = $apartmentData['id'] ?? $apartmentId;
    if (empty($externalId)) {
        throw new Exception('External ID is required');
    }

    $name = $apartmentData['title'] ?? ('Apartment ' . $externalId);
    if ($name === '') { $name = 'Apartment ' . $externalId; }

    $additionalExternalIdField = $fieldMapping['fields']['additional_external_id'];
    $additionalExternalId = null;
    if (array_key_exists($additionalExternalIdField, $apartmentData)) {
        $val = $apartmentData[$additionalExternalIdField];
        $additionalExternalId = is_array($val) ? ($val[0] ?? null) : $val;
        if ($additionalExternalId === '') { $additionalExternalId = null; }
    }

    $rentTypeField   = $fieldMapping['fields']['rent_type'];
    $rentTypeValue   = $apartmentData[$rentTypeField] ?? '4598';
    $rentTypeMapping = $fieldMapping['rent_type_mapping'];
    $rentType        = $rentTypeMapping[$rentTypeValue] ?? 'unit';

    // Определяем логику внешних ID согласно документации:
    // Для шеринговых (rooms): additional_external_id = "", is_used = false  
    // Для НЕ-шеринговых (unit): additional_external_id = ID юнита, is_used = true
    
    if ($rentType === 'rooms') {  // Шеринговый апартамент 
        $additionalExternalId = null; // Пустой для шеринговых
    }
    // Для НЕ-шеринговых $additionalExternalId остается как есть (ID юнита)
    
    // is_used_additional_external_id: true для unit (не-шеринговых), false для rooms (шеринговых)
    $useAdditionalExternalId = ($rentTypeValue === '4598');

    $numberField         = $fieldMapping['fields']['apartment_number'];
    $internalAreaField   = $fieldMapping['fields']['internal_area'];
    $goalRentCostField   = $fieldMapping['fields']['goal_rent_cost'];
    $addressField        = $fieldMapping['fields']['address'];
    $bathsField          = $fieldMapping['fields']['number_of_baths'];
    $floorField          = $fieldMapping['fields']['floor'];
    $internetLoginField  = $fieldMapping['fields']['internet_login'];
    $internetPassField   = $fieldMapping['fields']['internet_password'];
    $subwayField         = $fieldMapping['fields']['subway_station'];
    $parkingNumberField  = $fieldMapping['fields']['parking_number'];
    $keyboxField         = $fieldMapping['fields']['keybox_code'];
    $elLockField         = $fieldMapping['fields']['electronic_lock_password'];
    $isPoolField         = $fieldMapping['fields']['is_swimming_pool'];

    $subwayKey   = $apartmentData[$subwayField] ?? '';
    $subwayValue = $METRO[$subwayKey] ?? '';

    $data = [
        'external_id'                    => (string)$externalId,
        'is_used_additional_external_id' => $useAdditionalExternalId,
        'name'                           => $name,
        'header'                         => $apartmentData['title'] ?? $name,
        'building'                       => $buildingId,
        'property_type'                  => $propertyType,
        'number'                         => ($apartmentData[$numberField] ?? '0') ?: '0',
        'internal_area'                  => (float)($apartmentData[$internalAreaField] ?? 0),
        'photos'                         => [], // Обязательное поле
        'goal_rent_cost'                 => (float)($apartmentData[$goalRentCostField] ?? 0),
        'address'                        => $apartmentData[$addressField] ?? '',
        'number_of_bedrooms'             => (int)$numberOfBedrooms, // Обязательное поле
        'number_of_baths'                => (int)($apartmentData[$bathsField] ?? 0), // Обязательное поле
        'is_roof_garden'                 => false, // Обязательное поле
        'parking'                        => 'not_applicable', // Обязательное поле
        'is_swimming_pool'               => (($apartmentData[$isPoolField] ?? 'N') === 'Y'), // Обязательное поле
        'total_buildable_area'           => (float)($apartmentData[$internalAreaField] ?? 0), // Обязательное поле
        'floor'                          => $apartmentData[$floorField] ?? null,
        'internet_login'                 => $apartmentData[$internetLoginField] ?? '',
        'internet_password'              => $apartmentData[$internetPassField] ?? '',
        'subway_station'                 => $subwayValue,
        'parking_number'                 => $apartmentData[$parkingNumberField] ?? '',
        'keybox_code'                    => ($apartmentData[$keyboxField] ?? null) ?: null,
        'electronic_lock_password'       => !empty($apartmentData[$elLockField]) ? substr($apartmentData[$elLockField], 0, 8) : null,
    ];

    // Добавляем additional_external_id только если это не шеринговый апартамент
    if ($rentType !== 'rooms' && $additionalExternalId !== null) {
        $data['additional_external_id'] = $additionalExternalId;
    }

    // Проверяем обязательные поля согласно документации
    if (empty($data['name'])) {
        throw new Exception("Missing required field: name");
    }
    if (empty($data['building'])) {
        throw new Exception("Missing required field: building");
    }
    if (empty($data['property_type'])) {
        throw new Exception("Missing required field: property_type");
    }
    
    // Временное логирование для отладки
    Logger::info("Sending apartment data to Alma", [
        'apartment_id' => $apartmentId,
        'external_id' => $externalId,
        'data' => $data
    ], 'apartment', $apartmentId);

    $response = $alma->createOrUpdateApartment($data, $externalId);

    if (isset($response['response']['id']) || (isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
        $almaId = $response['response']['id'] ?? $response['id'] ?? null;
        $method = $response['method'] ?? 'unknown';
        $message = $method === 'POST' 
            ? 'Apartment successfully created in Alma' 
            : 'Apartment successfully updated in Alma';
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'alma_id' => $almaId,
            'method' => $method,
            'operation' => $method === 'POST' ? 'created' : 'updated',
            'data' => $response['response'] ?? $response
        ]);
    } else {
        Logger::error('Failed to process apartment', ['response' => $response], 'apartment', $apartmentId);
        throw new Exception('Failed to process apartment (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
    }

} catch (Exception $e) {
    try {
        if (isset($alma)) {
            $alma->getActionLogger()->logError(
                $_GET['id'] ?? 'unknown',
                'Apartment Processing Error',
                $e->getMessage(),
                ['apartment_data' => $apartmentData ?? []]
            );
        }
    } catch (\Throwable $t) {
    }

    Logger::error('Exception occurred: ' . $e->getMessage(), [
        'exception' => $e->getMessage(),
        'trace'     => $e->getTraceAsString()
    ], 'apartment', $_GET['id'] ?? 'unknown');

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
