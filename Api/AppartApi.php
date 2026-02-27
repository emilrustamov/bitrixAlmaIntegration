<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/UnitApi.php';

Config::load();

class AppartApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->actionLogger = new ApartmentActionLogger();
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $url = rtrim($this->apiUrl, '/') . '/realty/' . ltrim($endpoint, '/');

        if (!$isPost && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        return BaseApi::sendRequest($this->apiKey, $method, $url, $isPost ? $data : null, false);
    }

    public function getRentalObject($bitrixId) {
        $url = rtrim($this->apiUrl, '/') . '/realty/rental_object/' . rawurlencode((string)$bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    public function createOrUpdateApartment($data, $bitrixId) {
        if (isset($data['building']) && is_array($data['building'])) {
            $data['building'] = $data['building']['id'];
        }
        $isSharing = ($data['is_used_additional_external_id'] ?? true) === false;
        $unitResp = $this->getUnitByExternalId($bitrixId);
        $targetId = null;
        $oldData = null;
        if (($unitResp['code'] ?? 0) === 200 && !empty($unitResp['response']['id'])) {
            $unit = $unitResp['response'];
            if ($isSharing && isset($unit['parent_unit'])) {
                Logger::error('Found room instead of apartment for sharing apartment', [
                    'bitrix_id' => $bitrixId,
                    'found_id' => $unit['id'],
                    'parent_unit' => $unit['parent_unit']
                ], 'apartment', $bitrixId);
            } else {
                $targetId = $unit['id'];
                $oldData = $unit;
            }
        }
        if ($targetId) {
            $patchData = $data;
            if (!isset($patchData['name']) || (string)$patchData['name'] === '') {
                $patchData['name'] = $data['name'] ?? ('Apartment ' . $bitrixId);
            }
            $patchData['building'] = $patchData['building'] ?? $data['building'];
            if (array_key_exists('is_used_additional_external_id', $data)) {
                $patchData['is_used_additional_external_id'] = $data['is_used_additional_external_id'];
            }
            if (isset($data['additional_external_id'])) {
                $patchData['additional_external_id'] = $data['additional_external_id'];
            }
            $response = $this->call('PATCH', 'units/' . $targetId . '/', $patchData, true);
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
        $response = $this->call('POST', 'units/', $data, true);
        $response['method'] = 'POST';
        if (($response['code'] ?? 0) >= 400) {
            Logger::error('Failed to create apartment', [
                'response' => $response,
                'data' => $data
            ], 'apartment', $bitrixId);
        }

        if (isset($response['response']['id'])) {
            $this->actionLogger->logApartmentCreation(
                $response['response']['id'],
                $data['name'] ?? ('Apartment ' . $bitrixId),
                $data['building'] ?? 'Unknown Building',
                [
                    'property_type'       => $data['property_type'] ?? 'apartment',
                    'external_id'         => $data['external_id'] ?? $bitrixId,
                    'number'              => $data['number'] ?? '',
                    'internal_area'       => $data['internal_area'] ?? 0,
                    'goal_rent_cost'      => $data['goal_rent_cost'] ?? 0,
                    'address'             => $data['address'] ?? '',
                    'number_of_bedrooms'  => $data['number_of_bedrooms'] ?? 1,
                    'number_of_baths'     => $data['number_of_baths'] ?? 0,
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

    public function getActionLogger() {
        return $this->actionLogger;
    }

    public function getUnitByExternalId($bitrixId) {
        return BaseApi::fetchUnitByExternalId($this->apiKey, $this->apiUrl, (string)$bitrixId);
    }

    public function setUnitArchived($externalId, $isArchived) {
        $url = rtrim($this->apiUrl, '/') . '/realty/units/external_id/' . rawurlencode($externalId) . '/archive/';
        BaseApi::sendRequest($this->apiKey, 'PATCH', $url, ['is_archived' => $isArchived]);
    }

    public function setBuildingArchived($buildingId, $isArchived) {
        $url = rtrim($this->apiUrl, '/') . '/realty/buildings/' . $buildingId . '/archive/';
        BaseApi::sendRequest($this->apiKey, 'PATCH', $url, ['is_archived' => $isArchived]);
    }

    public function getBuilding($buildingId) {
        $response = $this->call('GET', 'buildings/' . $buildingId . '/', [], false);
        return (($response['code'] ?? 0) === 200) ? ($response['response'] ?? null) : null;
    }

    public function tryArchiveBuildingIfAllUnitsArchived($buildingId) {
        $building = $this->getBuilding($buildingId);
        if ($building !== null && (int)($building['number_of_units'] ?? -1) === 0) {
            $this->setBuildingArchived($buildingId, true);
        }
    }

    public function syncRoomsForApartment($apartmentId, $bitrix, $projectName = 'Dubai') {
        $apartmentUnit = $this->getUnitByExternalId($apartmentId);
        if ($apartmentUnit['code'] !== 200 || !isset($apartmentUnit['response']['id'])) {
            Logger::error('Apartment not found in Alma for room sync', [
                'apartment_id_bitrix' => $apartmentId,
                'response_code' => $apartmentUnit['code'] ?? null
            ], 'apartment', $apartmentId);
            return;
        }
        $almaApartmentId = $apartmentUnit['response']['id'];
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
        if (!isset($unitsResponse['result']['items'])) {
            return;
        }
        $unitApi = new UnitApi($this->apiKey, $this->apiUrl);
        foreach ($unitsResponse['result']['items'] as $unitData) {
            try {
                $unitExternalId = $unitData['id'] ?? null;
                if (!$unitExternalId) {
                    continue;
                }
                $name = $unitData['title'] ?? ('Room ' . $unitExternalId);
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
                $response = $unitApi->createRoom($roomData);
                
                if (!isset($response['response']['id']) && !(isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
                    Logger::error('Failed to create room', [
                        'unit_id' => $unitExternalId,
                        'apartment_id' => $apartmentId,
                        'response' => $response
                    ], 'room', $unitExternalId);
                }
            } catch (Exception $e) {
                Logger::error('Error syncing room: ' . $e->getMessage(), [
                    'unit_id' => $unitData['id'] ?? 'unknown',
                    'apartment_id' => $apartmentId
                ], 'room', $unitData['id'] ?? 'unknown');
            }
        }
    }
}

function syncApartmentById($id, $projectName, $entityTypeId = null) {
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $fieldMapping = ProjectMapping::getFieldMapping($projectName);
    $METRO = $fieldMapping['metro_mapping'] ?? [];
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
    $entityTypeId = $entityTypeId ?? ProjectMapping::getApartmentEntityTypeIds($projectName)[0];
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma = new AppartApi(ALMA_API_KEY, ALMA_API_URL);
    $bitrixApartment = $bitrix->call('crm.item.get', [
        'entityTypeId' => $entityTypeId,
        'id' => $id
    ]);
    if (!isset($bitrixApartment['result']['item'])) {
        throw new RuntimeException('Failed to get apartment data from Bitrix24');
    }
    $apartmentData = $bitrixApartment['result']['item'];
    $stageField = $fieldMapping['fields']['stage'];
    $stageRawValue = (string)($apartmentData[$stageField] ?? '');
    $archiveStageIds = ProjectMapping::getArchiveStageIds($projectName);
    $allowedStageIds = ProjectMapping::getAllowedStageIds($projectName);
    $isArchiveStage = $archiveStageIds !== [] && in_array($stageRawValue, $archiveStageIds, true);

    if ($isArchiveStage) {
        $unitResp = $alma->getUnitByExternalId($id);
        if (($unitResp['code'] ?? 0) === 200 && isset($unitResp['response']['id'])) {
            $alma->setUnitArchived((string)$id, true);
            $building = $unitResp['response']['building'] ?? null;
            $buildingId = is_array($building) ? ($building['id'] ?? null) : $building;
            if ($buildingId !== null) {
                $alma->tryArchiveBuildingIfAllUnitsArchived((int)$buildingId);
            }
        }
        return [
            'code' => 200,
            'response' => ['operation' => 'archived'],
            'method' => 'ARCHIVE'
        ];
    }

    if ($allowedStageIds !== [] && !in_array($stageRawValue, $allowedStageIds, true)) {
        return [
            'code' => 400,
            'response' => [
                'message' => 'Apartment stage not allowed for sync. Current stage_id: ' . $stageRawValue,
                'stage_id' => $stageRawValue,
                'allowed_stage_ids' => $allowedStageIds
            ],
            'method' => 'NONE'
        ];
    }

    $buildingNameField = $fieldMapping['fields']['building_name'];
    $buildingName = trim($apartmentData[$buildingNameField] ?? 'Default Building');
    if ($buildingName === '') {
        $buildingName = 'Default Building';
    }
    try {
        $buildingId = $alma->createOrGetBuilding($buildingName);
    } catch (Exception $e) {
        Logger::error("Failed to get building", ['building_name' => $buildingName, 'error' => $e->getMessage()], 'apartment', $id);
        throw new RuntimeException("Failed to get building '$buildingName': " . $e->getMessage());
    }

    $building = $alma->getBuilding((int)$buildingId);
    if ($building !== null && ($building['is_archived'] ?? false)) {
        $alma->setBuildingArchived((int)$buildingId, false);
    }

    $propertyTypeMap = $fieldMapping['property_type_mapping'];
    $bedroomsMap     = $fieldMapping['bedrooms_mapping'];
    $propertyTypeField = $fieldMapping['fields']['property_type'];
    $apartmentType     = $apartmentData[$propertyTypeField] ?? '52';
    $propertyType      = $propertyTypeMap[$apartmentType] ?? 'apartment';
    $numberOfBedrooms  = $bedroomsMap[$apartmentType]     ?? 1;

    $externalId = $apartmentData['id'] ?? $id;
    if (empty($externalId)) {
        throw new InvalidArgumentException('External ID is required');
    }

    $name = $apartmentData['title'] ?? ('Apartment ' . $externalId);

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
        $defaultRentTypeValue = ProjectMapping::getDefaultRentTypeValue($projectName);
        $rentTypeValue = $apartmentData[$rentTypeField] ?? $defaultRentTypeValue;
        $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
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
    
    $response = $alma->createOrUpdateApartment($data, $externalId);
    $code = $response['code'] ?? 0;
    $isSuccess = isset($response['response']['id']) || ($code >= 200 && $code < 300);
    if ($isSuccess) {
        if ($rentType === 'rooms' && $id) {
            $alma->syncRoomsForApartment($id, $bitrix, $projectName);
        }
        if (ProjectMapping::getApartmentField('date_of_refused', $projectName) !== null) {
            if (!defined('TERMINATION_SKIP_ENTRY')) {
                define('TERMINATION_SKIP_ENTRY', true);
            }
            require_once __DIR__ . '/TerminationAppartApi.php';
            syncTerminationForApartment($projectName, (string) $id, $apartmentData);
        }
        return [
            'code' => $response['code'],
            'response' => $response['response'] ?? $response,
            'method' => $response['method'] ?? 'unknown'
        ];
    }
    Logger::error('Failed to process apartment', [
        'response' => $response,
        'external_id' => $externalId,
        'method' => $response['method'] ?? 'unknown'
    ], 'apartment', $id);
    throw new RuntimeException('Failed to process apartment (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
}

if (isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        BaseApi::sendSuccessResponse(syncApartmentById($_GET['id'], $projectName, isset($_GET['entity_type_id']) ? (int)$_GET['entity_type_id'] : null));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: Apartment synchronization failed', 'apartment', $_GET['id'] ?? null);
    }
    exit;
}