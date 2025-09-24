<?php

require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

// Проект
$projectName   = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping  = ProjectMapping::getFieldMapping($projectName);

define('WEBHOOK_URL',     $projectConfig['webhook_url']);
define('ALMA_API_KEY',    Config::get('ALMA_API_KEY'));
define('ALMA_API_URL',    Config::get('ALMA_REALTY_API_URL'));
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
        $this->apiUrl = rtrim($apiUrl, '/') . '/external_api/realty/';
        $this->actionLogger = new ApartmentActionLogger();
    }

    private function baseHeaders() {
        return [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $url = $this->apiUrl . ltrim($endpoint, '/');
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
        }

        return [
            'code'     => $httpCode,
            'response' => json_decode($response, true)
        ];
    }

    public function getRentalObject($bitrixId) {
        // здесь используем другой базовый путь (без /realty/) для rental_object
        $url = rtrim(ALMA_API_URL, '/') . '/external_api/realty/rental_object/' . rawurlencode($bitrixId) . '/';
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

        if ($code >= 400) {
            Logger::logApiRequest('GET', $url, [], $response, $code);
        }

        $decoded = json_decode($response, true);

        if ($code !== 200) {
            Logger::warning("Rental object not found via rental_object API", ['external_id' => $bitrixId]);
        }

        return ['code' => $code, 'response' => $decoded];
    }

    public function createOrUpdateApartment($data, $bitrixId) {
        // 1) Пытаемся найти rental_object
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200 && is_array($rentalObject['response'])) {
            // Если вернулся room (есть parent_unit), апдейтить надо unit (апартамент)
            $targetId = !empty($rentalObject['response']['parent_unit'])
                ? $rentalObject['response']['parent_unit']
                : $rentalObject['response']['id'];

            $endpoint = 'units/' . $targetId . '/';

            // 2) Проверяем архивность
            $unitDetails = $this->call('GET', $endpoint, [], false);
            if ($unitDetails['code'] === 200 &&
                isset($unitDetails['response']['is_archived']) &&
                $unitDetails['response']['is_archived']) {

                $unitName = $unitDetails['response']['name'] ?? ('Apartment ' . $bitrixId);
                Logger::warning("Apartment $unitName is archived, unarchiving for update", [], 'apartment', $bitrixId);
                $this->unarchiveApartment($targetId);
            }

            // 3) PATCH
            $oldData  = $rentalObject['response'];
            $response = $this->call('PATCH', $endpoint, $data, true);
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

        // 4) Не нашли — создаем
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

    private function unarchiveApartment($almaId) {
        $url  = 'units/' . $almaId . '/archive/';
        $data = ['is_archived' => false];
        return $this->call('PATCH', $url, $data, true);
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

        Logger::error("Failed to create building", ['response' => $response]);
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

    // Проверка статуса стадий (кроме Гонконга, если поле пустое)
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

    // Здание
    $buildingNameField = $fieldMapping['fields']['building_name'];
    $buildingName = trim($apartmentData[$buildingNameField] ?? 'Default Building');
    if ($buildingName === '') { $buildingName = 'Default Building'; }
    $buildingId = $alma->createOrGetBuilding($buildingName);

    // Тип и спальни
    $propertyTypeMap = $fieldMapping['property_type_mapping'];
    $bedroomsMap     = $fieldMapping['bedrooms_mapping'];
    $propertyTypeField = $fieldMapping['fields']['property_type'];
    $apartmentType     = $apartmentData[$propertyTypeField] ?? '52';
    $propertyType      = $propertyTypeMap[$apartmentType] ?? 'apartment';
    $numberOfBedrooms  = $bedroomsMap[$apartmentType]     ?? 1;

    // ID
    $externalId = $apartmentData['id'] ?? $apartmentId;
    if (empty($externalId)) {
        Logger::error('External ID is required', [], 'apartment', $apartmentId);
        throw new Exception('External ID is required');
    }

    // Имя
    $name = $apartmentData['title'] ?? ('Apartment ' . $externalId);
    if ($name === '') { $name = 'Apartment ' . $externalId; }

    // additional_external_id (может быть строкой или массивом в Bitrix)
    $additionalExternalIdField = $fieldMapping['fields']['additional_external_id'];
    $additionalExternalId = null;
    if (array_key_exists($additionalExternalIdField, $apartmentData)) {
        $val = $apartmentData[$additionalExternalIdField];
        $additionalExternalId = is_array($val) ? ($val[0] ?? null) : $val;
        if ($additionalExternalId === '') { $additionalExternalId = null; }
    }

    // Тип аренды (unit / rooms)
    $rentTypeField   = $fieldMapping['fields']['rent_type'];
    $rentTypeValue   = $apartmentData[$rentTypeField] ?? '4598'; // по умолчанию unit
    $rentTypeMapping = $fieldMapping['rent_type_mapping'];
    $rentType        = $rentTypeMapping[$rentTypeValue] ?? 'unit';

    // Если шаринг (по комнатам) — чистим additional_external_id
    if ($rentType !== 'rooms') {
        $additionalExternalId = null;
    }
    $useAdditionalExternalId = ($rentType === 'rooms'); // rooms = шеринговый

    // Поля
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
        'additional_external_id'         => $additionalExternalId,                  // null при шаринге
        'is_used_additional_external_id' => $useAdditionalExternalId,               // true только если unit И есть additional_external_id
        'name'                           => $name,
        'header'                         => $apartmentData['title'] ?? $name,
        'building'                       => $buildingId,
        'property_type'                  => $propertyType,
        'number'                         => ($apartmentData[$numberField] ?? '0') ?: '0',
        'internal_area'                  => (float)($apartmentData[$internalAreaField] ?? 0),
        'photos'                         => [],                                     // загрузка фото отдельной логикой
        'goal_rent_cost'                 => (float)($apartmentData[$goalRentCostField] ?? 0),
        'address'                        => $apartmentData[$addressField] ?? '',
        'number_of_bedrooms'             => (int)$numberOfBedrooms,
        'number_of_baths'                => (int)($apartmentData[$bathsField] ?? 0), // по доке: пусто => 0
        'is_roof_garden'                 => false,
        'parking'                        => 'not_applicable',
        'is_swimming_pool'               => (($apartmentData[$isPoolField] ?? 'N') === 'Y'),
        'total_buildable_area'           => (float)($apartmentData[$internalAreaField] ?? 0),
        'floor'                          => $apartmentData[$floorField] ?? null,
        'internet_login'                 => $apartmentData[$internetLoginField] ?? '',
        'internet_password'              => $apartmentData[$internetPassField] ?? '',
        'subway_station'                 => $subwayValue,
        'parking_number'                 => $apartmentData[$parkingNumberField] ?? '',
        'keybox_code'                    => ($apartmentData[$keyboxField] ?? null) ?: null,            // null вместо 'N/A'
        'electronic_lock_password'       => ($apartmentData[$elLockField] ?? null) ?: null,            // null вместо 'N/A'
    ];

    $response = $alma->createOrUpdateApartment($data, $externalId);

    if ($response['code'] === 200 || $response['code'] === 201) {
        echo json_encode([
            'success' => true,
            'message' => 'Apartment successfully processed',
            'method'  => $response['method'] ?? 'unknown',
            'data'    => $response['response']
        ]);
    } else {
        Logger::error('Failed to process apartment', ['response' => $response], 'apartment', $apartmentId);
        throw new Exception('Failed to process apartment (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
    }

} catch (Exception $e) {
    // best-effort логгер, даже если $alma не создан
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
        // игнор
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
