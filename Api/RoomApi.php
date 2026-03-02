<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/UnitApi.php';

Config::load();

$projectName   = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping  = ProjectMapping::getFieldMapping($projectName);
Logger::setProject($projectName);

define('WEBHOOK_URL',     $projectConfig['webhook_url']);
define('ALMA_API_KEY',    Config::get('ALMA_API_KEY'));
define('ALMA_API_URL',    Config::get('ALMA_API_URL'));
define('PROJECT_ID',      $projectConfig['id']);

class RoomApi {
    private $apiKey;
    private $apiUrl;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
    }

    public function getUnitByExternalId($bitrixId) {
        return BaseApi::fetchUnitByExternalId($this->apiKey, $this->apiUrl, (string) $bitrixId);
    }

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
    $apartmentId = $roomData[$apartmentField] ?? null;
    if (is_array($apartmentId)) {
        $apartmentId = $apartmentId[0] ?? null;
    }
    if (empty($apartmentId)) {
        throw new InvalidArgumentException('Apartment ID not found in room');
    }

    $apartmentEntityTypeIds = ProjectMapping::getApartmentEntityTypeIds($projectName);
    $apartmentEntityTypeId = $apartmentEntityTypeIds[0] ?? null;
    if ($apartmentEntityTypeId === null) {
        throw new RuntimeException('Apartment entity type id is not configured for project ' . $projectName);
    }
    $apartmentRes = $bitrix->call('crm.item.get', [
        'entityTypeId' => $apartmentEntityTypeId,
        'id' => $apartmentId
    ]);
    if (!isset($apartmentRes['result']['item'])) {
        throw new RuntimeException('Failed to get apartment data from Bitrix24 for room sync');
    }
    $apartmentData = $apartmentRes['result']['item'];
    $rentTypeField = ProjectMapping::getApartmentRentTypeField($projectName);
    $rentType = 'unit';
    if ($rentTypeField !== null) {
        $rentTypeMapping = $fieldMapping['rent_type_mapping'] ?? [];
        $defaultRentTypeValue = ProjectMapping::getDefaultRentTypeValue($projectName);
        $rentTypeValue = $apartmentData[$rentTypeField] ?? $defaultRentTypeValue;
        if (is_array($rentTypeValue)) {
            $rentTypeValue = $rentTypeValue[0] ?? $defaultRentTypeValue;
        }
        $rentType = $rentTypeMapping[(string)$rentTypeValue] ?? 'unit';
    }
    if ($rentType !== 'rooms') {
        throw new InvalidArgumentException(
            'Room synchronization is allowed only for apartments with rent type "rooms". ' .
            'Current rent type: ' . $rentType
        );
    }

    $unitApi = new UnitApi(ALMA_API_KEY, ALMA_API_URL);
    $existingRoom = $unitApi->getRentalObject($roomId);
    $roomExists = ($existingRoom['code'] ?? 0) === 200 && !empty($existingRoom['response']['id']);
    $parentUnitId = null;
    if ($roomExists) {
        $p = $existingRoom['response']['parent_unit'] ?? null;
        $parentUnitId = (is_array($p) && isset($p['id'])) ? $p['id'] : (is_numeric($p) ? $p : null);
    } else {
        $apartmentUnit = $alma->getUnitByExternalId($apartmentId);
        if (($apartmentUnit['code'] ?? 0) !== 200 || empty($apartmentUnit['response']['id'])) {
            throw new RuntimeException("Apartment $apartmentId not found in Alma. Please sync apartment first.");
        }
        $parentUnitId = $apartmentUnit['response']['id'];
    }

    $externalId = $roomData['id'] ?? $roomId;
    $name = $roomData['title'] ?? ('Room ' . $externalId);

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
    
    if ($parentUnitId !== null) {
        $data['parent_unit'] = $parentUnitId;
    }

    $response = $alma->createOrUpdateRoom($data, $externalId);

    if (isset($response['response']['id']) || (isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
        BaseApi::sendSuccessResponse([
            'code' => $response['code'] ?? 200,
            'response' => $response['response'] ?? $response,
            'method' => $response['method'] ?? 'unknown',
        ]);
    } else {
        Logger::error('Failed to process room', ['response' => $response], 'room', $roomId);
        throw new RuntimeException('Failed to process room (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
    }

} catch (Throwable $e) {
    BaseApi::sendErrorResponse($e, 'Unexpected error: Room synchronization failed', 'room', $_GET['id'] ?? null);
}

