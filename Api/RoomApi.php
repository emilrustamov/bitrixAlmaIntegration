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
Logger::setProject($projectName);

define('WEBHOOK_URL',     $projectConfig['webhook_url']);
define('ALMA_API_KEY',    Config::get('ALMA_API_KEY'));
define('ALMA_API_URL',    Config::get('ALMA_API_URL'));
define('PROJECT_ID',      $projectConfig['id']);

/**
 * Alma API client for room synchronization.
 */
class RoomApi {
    private $apiKey;
    private $apiUrl;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
    }

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
     * Create or update Alma room using Bitrix payload.
     */
    public function createOrUpdateRoom($data, $bitrixId) {
        $unitApi = new UnitApi($this->apiKey, $this->apiUrl);
        return $unitApi->createOrUpdateRoom($data, $bitrixId);
    }
}

try {
    $roomId = $_GET['id'] ?? null;
    if (!$roomId) {
        throw new InvalidArgumentException('Room ID is required');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma   = new RoomApi(ALMA_API_KEY, ALMA_API_URL);

    $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
    $apartmentField = ProjectMapping::getUnitApartmentField($projectName);
    
    $bitrixRoom = $bitrix->call('crm.item.get', [
        'entityTypeId' => $unitEntityTypeId,
        'id' => $roomId
    ]);

    if (!isset($bitrixRoom['result']['item'])) {
        throw new RuntimeException('Failed to get room data from Bitrix24');
    }

    $roomData = $bitrixRoom['result']['item'];

    // Получаем ID апартамента из комнаты
    $apartmentId = null;
    if (isset($roomData[$apartmentField])) {
        $apartmentFieldValue = $roomData[$apartmentField];
        $apartmentId = is_array($apartmentFieldValue) ? ($apartmentFieldValue[0] ?? null) : $apartmentFieldValue;
    }

    if (!$apartmentId) {
        throw new InvalidArgumentException('Apartment ID not found in room');
    }

    $unitApi = new UnitApi(ALMA_API_KEY, ALMA_API_URL);
    $existingRoom = $unitApi->getRentalObject($roomId);
    $roomExists = ($existingRoom['code'] === 200 && isset($existingRoom['response']['id']));
    
    if ($roomExists) {
        $parentUnit = $existingRoom['response']['parent_unit'] ?? null;
        if (is_array($parentUnit) && isset($parentUnit['id'])) {
            $parentUnitId = $parentUnit['id'];
        } elseif (is_numeric($parentUnit)) {
            $parentUnitId = $parentUnit;
        }
    }
    
    if (!$roomExists) {
        $apartmentUnit = $alma->getUnitByExternalId($apartmentId);
        if ($apartmentUnit['code'] !== 200 || !isset($apartmentUnit['response']['id'])) {
            throw new RuntimeException("Apartment $apartmentId not found in Alma. Please sync apartment first.");
        }
        $parentUnitId = $apartmentUnit['response']['id'];
    }

    $externalId = $roomData['id'] ?? $roomId;
    $name = $roomData['title'] ?? ('Room ' . $externalId);
    if (empty($name)) {
        $name = 'Room ' . $externalId;
    }

    $unitInternalAreaField = ProjectMapping::getUnitField('internal_area', $projectName);
    $unitGoalRentCostField = ProjectMapping::getUnitField('goal_rent_cost', $projectName);
    $unitGoalRentCostAltField = ProjectMapping::getUnitField('goal_rent_cost_alt', $projectName);
    $unitNumberField = ProjectMapping::getUnitField('number', $projectName);

    $internalArea = $unitInternalAreaField ? (float)($roomData[$unitInternalAreaField] ?? 0) : 0;
    
    if ($unitGoalRentCostField) {
        $goalRentCost = (float)($roomData[$unitGoalRentCostField] ?? 0);
        if ($unitGoalRentCostAltField && $goalRentCost == 0) {
            $goalRentCost = (float)($roomData[$unitGoalRentCostAltField] ?? 0);
        }
    } else {
        $goalRentCost = 0;
    }

    $number = '0';
    if ($unitNumberField && isset($roomData[$unitNumberField])) {
        $numberValue = $roomData[$unitNumberField];
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

    $data = [
        'external_id' => (string)$externalId,
        'name' => $name,
        'header' => $roomData['title'] ?? $name,
        'goal_rent_cost' => $goalRentCost,
        'internal_area' => $internalArea,
        'photos' => [],
        'property_type' => 'room',
        'number' => $number,
    ];
    
    if (isset($parentUnitId)) {
        $data['parent_unit'] = $parentUnitId;
    }

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
        Logger::error('Failed to process room', ['response' => $response], 'room', $roomId);
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
    ], 'room', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Room synchronization failed'
    ]);
}

