<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once(__DIR__ . '/BaseApi.php');

Config::load();

$projectName   = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping  = ProjectMapping::getFieldMapping($projectName);
Logger::setProject($projectName);

define('WEBHOOK_URL',     $projectConfig['webhook_url']);
define('ALMA_API_KEY',    Config::get('ALMA_API_KEY'));
define('ALMA_API_URL',    Config::get('ALMA_API_URL'));
define('PROJECT_ID',      $projectConfig['id']);

class UnitApi {
    private $apiKey;
    private $apiUrl;
    private $bitrix;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
    }

    private function baseHeaders() {
        return [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];
    }

    public function getRentalObject($bitrixId) {
        $url = rtrim(ALMA_API_URL, '/') . '/realty/rental_object/' . rawurlencode($bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    public function createOrUpdateRoom($data, $bitrixId) {
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200 && is_array($rentalObject['response'])) {
            $targetId = $rentalObject['response']['id'];
            $endpoint = 'rooms/' . $targetId . '/';
            
            $oldData = $rentalObject['response'];
            
            $patchData = $data;
            if (isset($patchData['parent_unit']) && is_array($patchData['parent_unit'])) {
                $patchData['parent_unit'] = $patchData['parent_unit']['id'];
            }
            
            if (!isset($patchData['name']) || empty($patchData['name'])) {
                $patchData['name'] = $data['name'] ?? ('Room ' . $bitrixId);
            }
            if (!isset($patchData['parent_unit']) || empty($patchData['parent_unit'])) {
                $patchData['parent_unit'] = $data['parent_unit'];
            }
            
            $response = $this->call('PATCH', $endpoint, $patchData, true);
            $response['method'] = 'PATCH';
            return $response;
        }

        $endpoint = 'rooms/';
        $response = $this->call('POST', $endpoint, $data, true);
        $response['method'] = 'POST';

        return $response;
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $realtyApiUrl = rtrim(Config::get('ALMA_REALTY_API_URL'), '/');
        $url = $realtyApiUrl . '/' . ltrim($endpoint, '/');

        if (!$isPost && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        return BaseApi::sendRequest($this->apiKey, $method, $url, $isPost ? $data : null, false);
    }
}

try {
    $unitId = $_GET['id'] ?? null;
    if (!$unitId) {
        throw new InvalidArgumentException('Unit ID is required');
    }

    if ($projectName !== 'HongKong') {
        throw new InvalidArgumentException('This script is only for HongKong project');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma   = new UnitApi(ALMA_API_KEY, ALMA_API_URL);

    $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
    
    $bitrixUnit = $bitrix->call('crm.item.get', [
        'entityTypeId' => $unitEntityTypeId,
        'id' => $unitId
    ]);

    if (!isset($bitrixUnit['result']['item'])) {
        throw new RuntimeException('Failed to get unit data from Bitrix24');
    }

    $unitData = $bitrixUnit['result']['item'];

    $apartmentField = ProjectMapping::getUnitApartmentField($projectName);
    $apartmentId = null;
    if (isset($unitData[$apartmentField])) {
        $apartmentFieldValue = $unitData[$apartmentField];
        $apartmentId = is_array($apartmentFieldValue) ? ($apartmentFieldValue[0] ?? null) : $apartmentFieldValue;
    }

    if (!$apartmentId) {
        throw new InvalidArgumentException('Apartment ID not found in unit');
    }

    $apartmentRentalObject = $alma->getRentalObject($apartmentId);
    if ($apartmentRentalObject['code'] !== 200 || !isset($apartmentRentalObject['response']['id'])) {
        throw new RuntimeException("Apartment $apartmentId not found in Alma. Please sync apartment first.");
    }
    
    $parentUnitId = $apartmentRentalObject['response']['id'];

    $externalId = $unitData['id'] ?? $unitId;
    $name = $unitData['title'] ?? ('Room ' . $externalId);
    if ($name === '') { $name = 'Room ' . $externalId; }

    $internalArea = (float)($unitData['ufCrm13TotalAreaOfTheRoom'] ?? 0);
    $goalRentCost = (float)($unitData['opportunity'] ?? ($unitData['ufCrm13RentAmount'] ?? 0));
    
    $propertyType = 'room';

    $data = [
        'external_id' => (string)$externalId,
        'name' => $name,
        'header' => $unitData['title'] ?? $name,
        'goal_rent_cost' => $goalRentCost,
        'internal_area' => $internalArea,
        'photos' => [],
        'parent_unit' => $parentUnitId,
        'property_type' => $propertyType,
    ];

    $response = $alma->createOrUpdateRoom($data, $externalId);

    if (isset($response['response']['id']) || (isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
        $almaId = $response['response']['id'] ?? $response['id'] ?? null;
        $method = $response['method'] ?? 'unknown';
        $message = $method === 'POST' 
            ? 'Room successfully created in Alma' 
            : 'Room successfully updated in Alma';
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'alma_id' => $almaId,
            'method' => $method,
            'operation' => $method === 'POST' ? 'created' : 'updated',
            'data' => $response['response'] ?? $response
        ]);
    } else {
        Logger::error('Failed to process room', ['response' => $response], 'unit', $unitId);
        throw new RuntimeException('Failed to process room (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
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
    Logger::error("Unexpected error in room synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'unit', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Room synchronization failed'
    ]);
}

