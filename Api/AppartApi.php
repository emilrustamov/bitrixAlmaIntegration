<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once(__DIR__ . '/BaseApi.php');
require_once(__DIR__ . '/UnitApi.php');

Config::load();

$projectName   = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping  = ProjectMapping::getFieldMapping($projectName);

if (!defined('WEBHOOK_URL')) {
    define('WEBHOOK_URL', $projectConfig['webhook_url']);
}
if (!defined('ALMA_API_KEY')) {
    define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
}
if (!defined('ALMA_API_URL')) {
    define('ALMA_API_URL', Config::get('ALMA_API_URL'));
}
if (!defined('PROJECT_ID')) {
    define('PROJECT_ID', $projectConfig['id']);
}

$METRO = $fieldMapping['metro_mapping'];

/**
 * Alma API client for apartment synchronization.
 */
class AppartApi {
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

    /**
     * Send a request to Alma realty endpoints.
     *
     * @param string $method HTTP verb (POST, PATCH, GET)
     * @param string $endpoint relative endpoint (without /realty)
     * @param array  $data payload or query parameters
     * @param bool   $isPost determines POST/PATCH vs GET
     */
    public function call($method, $endpoint, $data = [], $isPost = true) {
        $url = $this->apiUrl . '/realty/' . ltrim($endpoint, '/');

        if (!$isPost && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        return BaseApi::sendRequest($this->apiKey, $method, $url, $isPost ? $data : null, false);
    }

    /**
     * Fetch rental object by Bitrix ID.
     */
    public function getRentalObject($bitrixId) {
        $url = rtrim(ALMA_API_URL, '/') . '/realty/rental_object/' . rawurlencode($bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    /**
     * Create or update Alma apartment using Bitrix payload.
     */
    public function createOrUpdateApartment($data, $bitrixId) {
        $isSharing = ($data['is_used_additional_external_id'] ?? true) === false;
        
        $unitByExternalId = $this->getUnitByExternalId($bitrixId);
        $targetId = null;
        $oldData = null;
        
        if ($unitByExternalId['code'] === 200 && is_array($unitByExternalId['response'])) {
            $foundUnit = $unitByExternalId['response'];
            
            if ($isSharing && isset($foundUnit['parent_unit'])) {
                Logger::error('Found room instead of apartment for sharing apartment', [
                    'bitrix_id' => $bitrixId,
                    'found_id' => $foundUnit['id'],
                    'parent_unit' => $foundUnit['parent_unit']
                ], 'apartment', $bitrixId);
            } else {
                $targetId = $foundUnit['id'];
                $oldData = $foundUnit;
            }
        }
        
        if ($targetId) {
            $endpoint = 'units/' . $targetId . '/';
            
            $patchData = $data;
            if (isset($patchData['building']) && is_array($patchData['building'])) {
                $patchData['building'] = $patchData['building']['id'];
            }
            
            if (!isset($patchData['name']) || empty($patchData['name'])) {
                $patchData['name'] = $data['name'] ?? ('Apartment ' . $bitrixId);
            }
            if (!isset($patchData['building']) || empty($patchData['building'])) {
                $patchData['building'] = $data['building'];
            }
            
            if (isset($data['is_used_additional_external_id'])) {
                $patchData['is_used_additional_external_id'] = $data['is_used_additional_external_id'];
            }
            
            if (isset($data['additional_external_id'])) {
                $patchData['additional_external_id'] = $data['additional_external_id'];
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

        $postData = $data;
        if (isset($postData['building']) && is_array($postData['building'])) {
            $postData['building'] = $postData['building']['id'];
        }
        
        $endpoint = 'units/';
        $response = $this->call('POST', $endpoint, $postData, true);
        $response['method'] = 'POST';

        if (isset($response['code']) && $response['code'] >= 400) {
            Logger::error('Failed to create apartment', [
                'response' => $response,
                'data' => $postData
            ], 'apartment', $bitrixId);
        }

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

        throw new RuntimeException('Failed to create building: ' . json_encode($response));
    }

    public function getActionLogger() { return $this->actionLogger; }

    /**
     * Получить юнит (апартамент) из Alma по external_id из Bitrix.
     * Возвращает внутренний ID юнита из Alma.
     */
    public function getUnitByExternalId($bitrixId) {
        $realtyApiUrl = rtrim(Config::get('ALMA_REALTY_API_URL'), '/');
        $url = $realtyApiUrl . '/units/external_id/' . rawurlencode($bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    /**
     * Synchronize rooms for sharing apartment.
     */
    public function syncRoomsForApartment($apartmentId, $bitrix) {
        global $projectName, $fieldMapping;
        
        // Logger::info('syncRoomsForApartment called', [
        //     'apartment_id_bitrix' => $apartmentId,
        //     'project' => $projectName
        // ], 'apartment', $apartmentId);
        
        $apartmentUnit = $this->getUnitByExternalId($apartmentId);
        if ($apartmentUnit['code'] !== 200 || !isset($apartmentUnit['response']['id'])) {
            Logger::error('Apartment not found in Alma for room sync', [
                'apartment_id_bitrix' => $apartmentId,
                'response_code' => $apartmentUnit['code'] ?? null
            ], 'apartment', $apartmentId);
            return;
        }
        
        $almaApartmentId = $apartmentUnit['response']['id'];
        // Logger::info('Found apartment in Alma', [
        //     'apartment_id_bitrix' => $apartmentId,
        //     'alma_apartment_id' => $almaApartmentId
        // ], 'apartment', $apartmentId);
        
        $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
        $apartmentField = ProjectMapping::getUnitApartmentField($projectName);
        
        $selectFields = ['id', 'title', 'opportunity'];
        $unitInternalAreaField = ProjectMapping::getUnitField('internal_area', $projectName);
        $unitGoalRentCostField = ProjectMapping::getUnitField('goal_rent_cost', $projectName);
        $unitGoalRentCostAltField = ProjectMapping::getUnitField('goal_rent_cost_alt', $projectName);
        $unitNumberField = ProjectMapping::getUnitField('number', $projectName);
        
        if ($unitInternalAreaField) {
            $selectFields[] = $unitInternalAreaField;
        }
        if ($unitGoalRentCostAltField) {
            $selectFields[] = $unitGoalRentCostAltField;
        }
        if ($unitNumberField) {
            $selectFields[] = $unitNumberField;
        }
        
        $unitsResponse = $bitrix->call('crm.item.list', [
            'entityTypeId' => $unitEntityTypeId,
            'filter' => [$apartmentField => $apartmentId],
            'select' => $selectFields
        ]);
        
        // Logger::info('Units fetched from Bitrix', [
        //     'apartment_id_bitrix' => $apartmentId,
        //     'unit_entity_type_id' => $unitEntityTypeId,
        //     'apartment_field' => $apartmentField,
        //     'units_count' => isset($unitsResponse['result']['items']) ? count($unitsResponse['result']['items']) : 0
        // ], 'apartment', $apartmentId);
        
        if (!isset($unitsResponse['result']['items'])) {
            // Logger::info('No units found for apartment', [
            //     'apartment_id_bitrix' => $apartmentId
            // ], 'apartment', $apartmentId);
            return;
        }
        
        $unitApi = new UnitApi($this->apiKey, $this->apiUrl);
        
        // Logger::info('Starting to process units', [
        //     'apartment_id_bitrix' => $apartmentId,
        //     'units_count' => count($unitsResponse['result']['items'])
        // ], 'apartment', $apartmentId);
        
        foreach ($unitsResponse['result']['items'] as $unitData) {
            try {
                $unitExternalId = $unitData['id'] ?? null;
                if (!$unitExternalId) {
                    // Logger::info('Skipping unit without ID', [
                    //     'apartment_id_bitrix' => $apartmentId,
                    //     'unit_data' => $unitData
                    // ], 'apartment', $apartmentId);
                    continue;
                }
                
                // Logger::info('Processing unit', [
                //     'apartment_id_bitrix' => $apartmentId,
                //     'unit_id' => $unitExternalId
                // ], 'apartment', $apartmentId);
                
                $name = $unitData['title'] ?? ('Room ' . $unitExternalId);
                if ($name === '') {
                    $name = 'Room ' . $unitExternalId;
                }
                
                $internalArea = $unitInternalAreaField ? (float)($unitData[$unitInternalAreaField] ?? 0) : 0;
                
                if ($unitGoalRentCostField) {
                    $goalRentCost = (float)($unitData[$unitGoalRentCostField] ?? 0);
                    if ($unitGoalRentCostAltField && $goalRentCost == 0) {
                        $goalRentCost = (float)($unitData[$unitGoalRentCostAltField] ?? 0);
                    }
                } else {
                    $goalRentCost = 0;
                }
                
                $number = '0';
                if ($unitNumberField && isset($unitData[$unitNumberField])) {
                    $numberValue = $unitData[$unitNumberField];
                    if (is_array($numberValue)) {
                        $numberValue = $numberValue[0] ?? null;
                    }
                    
                    if ($numberValue !== null) {
                        $numberMapping = ProjectMapping::getUnitNumberMapping($projectName);
                        $number = $numberMapping[(string)$numberValue] ?? (string)$numberValue;
                    }
                }
                if ($number === '') {
                    $number = '0';
                }
                
                $existingRoom = $unitApi->getRentalObject($unitExternalId);
                if ($existingRoom['code'] === 200 && isset($existingRoom['response']['id'])) {
                    // Logger::info('Room already exists, skipping', [
                    //     'apartment_id_bitrix' => $apartmentId,
                    //     'unit_id' => $unitExternalId,
                    //     'existing_room_id' => $existingRoom['response']['id']
                    // ], 'apartment', $apartmentId);
                    continue;
                }
                
                $roomData = [
                    'external_id' => (string)$unitExternalId,
                    'name' => $name,
                    'header' => $unitData['title'] ?? $name,
                    'goal_rent_cost' => $goalRentCost,
                    'internal_area' => $internalArea,
                    'photos' => [],
                    'parent_unit' => $almaApartmentId,
                    'property_type' => 'room',
                    'number' => $number,
                ];
                
                // Logger::info('Creating room', [
                //     'apartment_id_bitrix' => $apartmentId,
                //     'unit_id' => $unitExternalId,
                //     'alma_apartment_id' => $almaApartmentId
                // ], 'apartment', $apartmentId);
                
                $response = $unitApi->createRoom($roomData);
                
                if (!isset($response['response']['id']) && !(isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
                    Logger::error('Failed to create room', [
                        'unit_id' => $unitExternalId,
                        'apartment_id' => $apartmentId,
                        'response' => $response
                    ], 'room', $unitExternalId);
                }
                // else {
                //     Logger::info('Room created successfully', [
                //         'apartment_id_bitrix' => $apartmentId,
                //         'unit_id' => $unitExternalId,
                //         'room_id' => $response['response']['id'] ?? null
                //     ], 'apartment', $apartmentId);
                // }
            } catch (Exception $e) {
                Logger::error('Error syncing room: ' . $e->getMessage(), [
                    'unit_id' => $unitData['id'] ?? 'unknown',
                    'apartment_id' => $apartmentId
                ], 'room', $unitData['id'] ?? 'unknown');
            }
        }
    }
}

try {
    $apartmentId = $_GET['id'] ?? null;
    if (!$apartmentId) {
        throw new InvalidArgumentException('Apartment ID is required');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma   = new AppartApi(ALMA_API_KEY, ALMA_API_URL);

    $bitrixApartment = $bitrix->call('crm.item.get', [
        'entityTypeId' => $fieldMapping['entity_type_id'],
        'id' => $apartmentId
    ]);

    if (!isset($bitrixApartment['result']['item'])) {
        throw new RuntimeException('Failed to get apartment data from Bitrix24');
    }

    $apartmentData = $bitrixApartment['result']['item'];

    $stageField    = $fieldMapping['fields']['stage'];
    $stageRawValue = $apartmentData[$stageField] ?? '';
    
    if ($projectName === 'HongKong' && !empty($stageRawValue)) {
        $stageMapping = $fieldMapping['stage_mapping'] ?? [];
        $stageForAlma = $stageMapping[(string)$stageRawValue] ?? $stageRawValue;
    } else {
        $stageForAlma = $stageRawValue;
    }
    
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
        throw new InvalidArgumentException('External ID is required');
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

    $rentTypeField   = ProjectMapping::getApartmentRentTypeField($projectName);
    $rentTypeValue   = null;
    $rentType        = 'unit';
    $useAdditionalExternalId = true;
    
    if ($rentTypeField !== null) {
        $rentTypeMapping = $fieldMapping['rent_type_mapping'] ?? [];
        $defaultRentTypeValue = ($projectName === 'HongKong') ? '1069' : '4598';
        $rentTypeValue = $apartmentData[$rentTypeField] ?? $defaultRentTypeValue;
        $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
        
        // Logger::info('Rent type determined', [
        //     'apartment_id' => $apartmentId,
        //     'rent_type_field' => $rentTypeField,
        //     'rent_type_value' => $rentTypeValue,
        //     'rent_type' => $rentType,
        //     'mapping' => $rentTypeMapping
        // ], 'apartment', $apartmentId);
        
        $useAdditionalExternalId = ($rentType === 'unit');
        
        if ($rentType === 'rooms') {
            $additionalExternalId = null;
        }
    }

    $numberField         = $fieldMapping['fields']['apartment_number'];
    $internalAreaField   = $fieldMapping['fields']['internal_area'];
    $goalRentCostField   = $fieldMapping['fields']['goal_rent_cost'];
    $addressField        = $fieldMapping['fields']['address'];
    $bathsField          = $fieldMapping['fields']['number_of_baths'];
    $floorField          = $fieldMapping['fields']['floor'];
    $internetLoginField  = $fieldMapping['fields']['internet_login'];
    $internetPassField   = $fieldMapping['fields']['internet_password'];
    $subwayField         = $fieldMapping['fields']['subway_station'] ?? null;
    $parkingNumberField  = $fieldMapping['fields']['parking_number'];
    $keyboxField         = $fieldMapping['fields']['keybox_code'];
    $elLockField         = $fieldMapping['fields']['electronic_lock_password'];
    $isPoolField         = $fieldMapping['fields']['is_swimming_pool'];

    $subwayValue = '';
    if ($subwayField !== null) {
        $subwayKey = $apartmentData[$subwayField] ?? '';
        $subwayValue = $METRO[$subwayKey] ?? '';
    }

    $data = [
        'external_id'                    => (string)$externalId,
        'is_used_additional_external_id' => $useAdditionalExternalId,
        'name'                           => $name,
        'header'                         => $apartmentData['title'] ?? $name,
        'building'                       => $buildingId,
        'property_type'                  => $propertyType,
        'number'                         => ($apartmentData[$numberField] ?? '0') ?: '0',
        'internal_area'                  => (float)($apartmentData[$internalAreaField] ?? 0),
        'photos'                         => [],
        'goal_rent_cost'                 => (float)($apartmentData[$goalRentCostField] ?? 0),
        'address'                        => $apartmentData[$addressField] ?? '',
        'number_of_bedrooms'             => (int)$numberOfBedrooms,
        'number_of_baths'                => (int)($apartmentData[$bathsField] ?? 0),
        'is_roof_garden'                 => false,
        'parking'                        => 'not_applicable',
        'is_swimming_pool'               => (($apartmentData[$isPoolField] ?? 'N') === 'Y'),
        'total_buildable_area'           => (float)($apartmentData[$internalAreaField] ?? 0),
        'floor'                          => $apartmentData[$floorField] ?? null,
        'internet_login'                 => $apartmentData[$internetLoginField] ?? '',
        'internet_password'              => $apartmentData[$internetPassField] ?? '',
        'subway_station'                 => $subwayValue,
        'parking_number'                 => $apartmentData[$parkingNumberField] ?? '',
        'keybox_code'                    => !empty($apartmentData[$keyboxField]) ? substr($apartmentData[$keyboxField], 0, 50) : null,
        'electronic_lock_password'       => !empty($apartmentData[$elLockField]) ? substr($apartmentData[$elLockField], 0, 8) : null,
    ];

    if ($rentType === 'rooms') {
        $data['additional_external_id'] = '';
    } elseif ($additionalExternalId !== null) {
        $data['additional_external_id'] = $additionalExternalId;
    }
    
    if (empty($data['name'])) {
        throw new InvalidArgumentException("Missing required field: name");
    }
    if (empty($data['building'])) {
        throw new InvalidArgumentException("Missing required field: building");
    }
    if (empty($data['property_type'])) {
        throw new InvalidArgumentException("Missing required field: property_type");
    }

    $response = $alma->createOrUpdateApartment($data, $externalId);

    $isSuccess = false;
    if (isset($response['response']['id'])) {
        $isSuccess = true;
    } elseif (isset($response['code'])) {
        $isSuccess = ($response['code'] >= 200 && $response['code'] < 300);
    }

    if ($isSuccess) {
        $almaId = $response['response']['id'] ?? $response['id'] ?? null;
        $method = $response['method'] ?? 'unknown';
        $message = $method === 'POST' 
            ? 'Apartment successfully created in Alma' 
            : 'Apartment successfully updated in Alma';
        
        if ($rentType === 'rooms' && $apartmentId) {
            // Logger::info('Starting room sync for sharing apartment', [
            //     'apartment_id' => $apartmentId,
            //     'rent_type' => $rentType
            // ], 'apartment', $apartmentId);
            $alma->syncRoomsForApartment($apartmentId, $bitrix);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'alma_id' => $almaId,
            'method' => $method,
            'operation' => $method === 'POST' ? 'created' : 'updated',
            'data' => $response['response'] ?? $response
        ]);
    } else {
        Logger::error('Failed to process apartment', [
            'response' => $response,
            'external_id' => $externalId,
            'method' => $response['method'] ?? 'unknown'
        ], 'apartment', $apartmentId);
        throw new RuntimeException('Failed to process apartment (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
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
    Logger::error("Unexpected error in apartment synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'apartment', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Apartment synchronization failed'
    ]);
}
