<?php

require_once('Logger.php');
require_once('Config.php');

// Загружаем конфигурацию
Config::load();

define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));
define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_REALTY_API_URL'));
define('PROJECT_ID', (int)Config::get('PROJECT_ID'));
define('METRO', [
    66 => 'Rashidiya',
    68 => 'Emirates',
    70 => 'Airport Terminal 3',
    72 => 'Airport Terminal 1',
    74 => 'GGICO',
    76 => 'Deira City Centre',
    78 => 'Al Rigga',
    80 => 'Union',
    82 => 'Bur Juman',
    84 => 'ADCB',
    86 => 'Al Jaffiliya',
    88 => 'World Trade Centre',
    90 => 'Emirates Towers',
    92 => 'Financial Centre',
    94 => 'Burj Khalifa / Dubai Mall',
    96 => 'Business Bay',
    98 => 'Noor Bank',
    100 => 'First Gulf Bank (FGB)',
    102 => 'Mall of the Emirates',
    104 => 'Sharaf DG',
    106 => 'Dubai Internet City',
    108 => 'Nakheel',
    110 => 'Damac Properties',
    112 => 'DMCC',
    114 => 'Nakheel Harbor and Tower',
    116 => 'Ibn Battuta',
    118 => 'Energy',
    120 => 'Danube',
    122 => 'UAE Exchange',
    124 => 'Etisalat',
    126 => 'Al Qusais',
    128 => 'Dubai Airport Free Zone',
    130 => 'Al Nahda',
    132 => 'Stadium',
    134 => 'Al Quiadah',
    136 => 'Abu Hail',
    138 => 'Abu Baker Al Siddique',
    140 => 'Salah Al Din',
    142 => 'Baniyas Square',
    144 => 'Palm Deira',
    146 => 'Al Ras',
    148 => 'Al Ghubaiba',
    150 => 'Al Fahidi',
    152 => 'Oud Metha',
    154 => 'Dubai Healthcare City',
    156 => 'Al Jadaf',
    158 => 'Creek',
    182 => 'Sobha realty',
    184 => 'Al Furjan',
    254 => 'Centrepoint',
    2762 => 'Not selected'
]);

class Bitrix24Rest {
    private $webhookUrl;

    public function __construct($webhookUrl) {
        $this->webhookUrl = $webhookUrl;
    }

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
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ApartmentActionLogger();
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $url = $this->apiUrl . $endpoint;
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        $curl = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ];

        if ($isPost) {
            if ($method === 'PATCH') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
            } else {
                $options[CURLOPT_CUSTOMREQUEST] = 'POST';
                $options[CURLOPT_POSTFIELDS] = json_encode($data);
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

        Logger::logApiRequest($method, $url, $data, $response, $httpCode);

        return [
            'code' => $httpCode,
            'response' => json_decode($response, true)
        ];
    }

    public function getRentalObject($bitrixId) {
        // Сначала ищем по external_id в units (основной поиск)
        $response = $this->call('GET', 'units/external_id/' . $bitrixId . '/', [], false);
        
        if ($response['code'] === 200) {
            return $response;
        }
        
        // Если не найден, ищем через rental_object (резервный поиск)
        $response = $this->call('GET', 'rental_object/' . $bitrixId . '/', [], false);
        return $response;
    }

    public function createOrUpdateApartment($data, $bitrixId) {
        // Проверяем, существует ли уже объект в Alma
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200) {
            // Обновляем существующий апартамент
            $almaId = $rentalObject['response']['id'];
            $endpoint = 'units/' . $almaId . '/';
            
            // Получаем текущие данные для сравнения
            $oldData = $rentalObject['response'];
            
            $response = $this->call('PATCH', $endpoint, $data, true);
            $response['method'] = 'PATCH';
            
            // Логируем обновление апартамента только если запрос успешен
            if ($response['code'] === 200) {
                Logger::info("Updating apartment ID: $almaId", [
                    'old_data' => $oldData,
                    'new_data' => $data
                ], 'apartment', $almaId);
                
                $this->actionLogger->logUpdate(
                    $almaId,
                    $data['name'] ?? 'Apartment ' . $bitrixId,
                    $oldData,
                    $data
                );
            } else {
                Logger::error("Failed to update apartment", [
                    'response' => $response
                ], 'apartment', $almaId);
            }
        } else {
            // Создаем новый апартамент
            $endpoint = 'units/';
            $response = $this->call('POST', $endpoint, $data);
            $response['method'] = 'POST';
            
            // Логируем создание апартамента
            if (isset($response['response']['id'])) {
                $this->actionLogger->logApartmentCreation(
                    $response['response']['id'],
                    $data['name'] ?? 'Apartment ' . $bitrixId,
                    $data['building'] ?? 'Unknown Building',
                    $data['property_type'] ?? 'apartment',
                    [
                        'external_id' => $data['external_id'] ?? $bitrixId,
                        'number' => $data['number'] ?? '',
                        'internal_area' => $data['internal_area'] ?? 0,
                        'goal_rent_cost' => $data['goal_rent_cost'] ?? 0,
                        'address' => $data['address'] ?? '',
                        'number_of_bedrooms' => $data['number_of_bedrooms'] ?? 1,
                        'number_of_baths' => $data['number_of_baths'] ?? 1
                    ]
                );
            }
        }

        return $response;
    }

    public function createOrGetBuilding($buildingName) {
        // Сначала проверяем, существует ли здание в нужном проекте
        $response = $this->call('GET', 'buildings/', ['name' => $buildingName], false);

        if ($response['code'] === 200 && !empty($response['response'])) {
            // Проверяем, что здание принадлежит нужному проекту
            foreach ($response['response'] as $building) {
                if (isset($building['project']) && $building['project']['id'] == PROJECT_ID) {
                    Logger::info("Found building in project " . PROJECT_ID . ": " . $building['id'], [], 'building', $building['id']);
                    return $building['id'];
                }
            }
            
            // Если здание найдено, но в другом проекте, создаем новое
            Logger::warning("Building '$buildingName' found in different project, creating new in project " . PROJECT_ID);
        }
        
        // Создаем новое здание в нужном проекте
        $data = [
            'name' => $buildingName,
            'project' => PROJECT_ID
        ];

        Logger::info("Creating building '$buildingName' in project " . PROJECT_ID);
        $response = $this->call('POST', 'buildings/', $data);

        if ($response['code'] === 201) {
            Logger::info("Building created successfully: " . $response['response']['id'], [], 'building', $response['response']['id']);
            return $response['response']['id'];
        } else {
            Logger::error("Failed to create building", ['response' => $response]);
            throw new Exception('Failed to create building: ' . json_encode($response));
        }
    }
    
    public function getActionLogger() {
        return $this->actionLogger;
    }
}

try {
    Logger::info('Starting apartment processing script');

    $apartmentId = $_GET['id'] ?? null;
    Logger::info("Received apartmentId: $apartmentId");

    if (!$apartmentId) {
        Logger::error('Apartment ID is required');
        throw new Exception('Apartment ID is required');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma = new AlmaApi(ALMA_API_KEY, ALMA_API_URL);


    Logger::info('Requesting apartment data from Bitrix24', [], 'apartment', $apartmentId);
    $bitrixApartment = $bitrix->call('crm.item.get', [
        'entityTypeId' => 144,
        'id' => $apartmentId
    ]);
    Logger::debug('Bitrix24 response', ['response' => $bitrixApartment], 'apartment', $apartmentId);

    if (!isset($bitrixApartment['result'])) {
        Logger::error('Failed to get data from Bitrix24', ['response' => $bitrixApartment], 'apartment', $apartmentId);
        throw new Exception('Failed to get apartment data from Bitrix24');
    }

    $apartmentData = $bitrixApartment['result']['item'];
    Logger::debug('Apartment data received', ['data' => $apartmentData], 'apartment', $apartmentId);


    $buildingName = $apartmentData['ufCrm6_1682232363193'] ?? 'Default Building';
    if (empty($buildingName)) {
        $buildingName = 'Default Building';
    }
    Logger::info("Checking/creating building: $buildingName", [], 'apartment', $apartmentId);

    $buildingId = $alma->createOrGetBuilding($buildingName);
    Logger::info("Building ID in Alma: $buildingId", [], 'apartment', $apartmentId);

    Logger::info('Preparing data for Alma', [], 'apartment', $apartmentId);
    $propertyTypeMap = [
        '54' => 'apartment',
        '56' => 'apartment',
        '58' => 'apartment',
        '60' => 'apartment',
        '62' => 'apartment',
        '64' => 'apartment',
        '52' => 'studio'
    ];

    $apartmentType = $apartmentData['ufCrm6_1682232863625'] ?? '52';
    $propertyType = $propertyTypeMap[$apartmentType] ?? 'apartment';

    $bedroomsMap = [
        '54' => 1,
        '56' => 2,
        '58' => 3,
        '60' => 4,
        '62' => 5,
        '64' => 6,
        '52' => 1
    ];

    $numberOfBedrooms = $bedroomsMap[$apartmentType] ?? 1;


    $externalId = $apartmentData['id'] ?? $apartmentId;
    if (empty($externalId)) {
        Logger::error('External ID is required', [], 'apartment', $apartmentId);
        throw new Exception('External ID is required');
    }

    $name = $apartmentData['title'] ?? 'Apartment ' . $externalId;
    if (empty($name)) {
        $name = 'Apartment ' . $externalId;
    }

    // Проверяем additional_external_id на уникальность
    $additionalExternalId = '';
    $useAdditionalExternalId = false;
    if (!empty($apartmentData['ufCrm6_1736951470242'])) {
        $checkResponse = $alma->call('GET', 'units/', ['additional_external_id' => $apartmentData['ufCrm6_1736951470242']], false);
        if ($checkResponse['code'] === 200 && empty($checkResponse['response'])) {
            $additionalExternalId = $apartmentData['ufCrm6_1736951470242'];
            $useAdditionalExternalId = true;
        }
    }

    $data = [
        'external_id' => (string)$externalId,
        'additional_external_id' => $additionalExternalId,
        'is_used_additional_external_id' => $useAdditionalExternalId,
        'name' => $name,
        'header' => $apartmentData['title'] ?? $name,
        'building' => $buildingId,
        'property_type' => $propertyType,
        'number' => $apartmentData['ufCrm6_1682232396330'] ?? '0',
        'internal_area' => (float)($apartmentData['ufCrm6_1682232424142'] ?? 0),
        'photos' => [],
        'goal_rent_cost' => (float)($apartmentData['ufCrm6_1682232447205'] ?? 0),
        'address' => $apartmentData['ufCrm6_1718821717'] ?? '',
        'number_of_bedrooms' => $numberOfBedrooms,
        'number_of_baths' => (int)($apartmentData['ufCrm6_1682232465964'] ?? 1),
        'is_roof_garden' => false,
        'parking' => 'not_applicable',
        'is_swimming_pool' => (bool)($apartmentData['ufCrm6_1697622591377'] ?? false),
        'total_buildable_area' => (float)($apartmentData['ufCrm6_1682232424142'] ?? 0),
        'floor' => $apartmentData['ufCrm6_1682232312628'] ?? null,
        'internet_login' => $apartmentData['ufCrm6_1682235809295'] ?? '',
        'internet_password' => $apartmentData['ufCrm6_1686728251990'] ?? '',
        'subway_station' => METRO[$apartmentData['ufCrm6_1682233481671']] ?? '',
        'parking_number' => $apartmentData['ufCrm6_1683299159437'] ?? '',
        'keybox_code' => $apartmentData['ufCrm6_1720794204'] ?? 'N/A',
        'electronic_lock_password' => $apartmentData['ufCrm6_1715777670'] ?? 'N/A'
    ];
    Logger::debug('Data for Alma', ['data' => $data], 'apartment', $apartmentId);


    Logger::info('Requesting apartment creation/update in Alma', [], 'apartment', $apartmentId);
    $response = $alma->createOrUpdateApartment($data, $externalId);
    Logger::debug('Alma response', ['response' => $response], 'apartment', $apartmentId);

    if ($response['code'] === 200 || $response['code'] === 201) {
        Logger::info('Apartment successfully processed', [], 'apartment', $apartmentId);
        echo json_encode([
            'success' => true,
            'message' => 'Apartment successfully processed',
            'method' => $response['method'] ?? 'unknown',
            'data' => $response['response']
        ]);
    } else {
        Logger::error('Failed to process apartment', ['response' => $response], 'apartment', $apartmentId);
        throw new Exception('Failed to process apartment (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
    }

} catch (Exception $e) {
    Logger::error('Exception occurred: ' . $e->getMessage(), [
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'apartment', $apartmentId ?? 'unknown');
    

    $alma->getActionLogger()->logError(
        $apartmentId ?? 'unknown',
        'Apartment Processing Error',
        $e->getMessage(),
        ['apartment_data' => $apartmentData ?? []]
    );
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}