<?php

require_once('Bitrix24Rest.php');
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

class AlmaRoomsApi {
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

            if ($response['code'] === 200) {
                Logger::info("Room updated in Alma", [
                    'bitrix_id' => $bitrixId,
                    'alma_id' => $targetId
                ], 'unit', $bitrixId);
            }
            return $response;
        }

        $endpoint = 'rooms/';
        $response = $this->call('POST', $endpoint, $data, true);
        $response['method'] = 'POST';

        if (isset($response['response']['id'])) {
            Logger::info("Room created in Alma", [
                'bitrix_id' => $bitrixId,
                'alma_id' => $response['response']['id']
            ], 'unit', $bitrixId);
        }

        return $response;
    }

    public function call($method, $endpoint, $data = [], $isPost = true) {
        $realtyApiUrl = rtrim(Config::get('ALMA_REALTY_API_URL'), '/');
        $url = $realtyApiUrl . '/' . ltrim($endpoint, '/');
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
}

try {
    $unitId = $_GET['id'] ?? null;
    if (!$unitId) { 
        throw new Exception('Unit ID is required'); 
    }

    if ($projectName !== 'HongKong') {
        throw new Exception('This script is only for HongKong project');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $alma   = new AlmaRoomsApi(ALMA_API_KEY, ALMA_API_URL);

    $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
    
    $bitrixUnit = $bitrix->call('crm.item.get', [
        'entityTypeId' => $unitEntityTypeId,
        'id' => $unitId
    ]);

    if (!isset($bitrixUnit['result']['item'])) {
        throw new Exception('Failed to get unit data from Bitrix24');
    }

    $unitData = $bitrixUnit['result']['item'];

    // Получаем ID апартамента из юнита
    $apartmentField = ProjectMapping::getUnitApartmentField($projectName);
    $apartmentId = null;
    if (isset($unitData[$apartmentField])) {
        $apartmentFieldValue = $unitData[$apartmentField];
        $apartmentId = is_array($apartmentFieldValue) ? ($apartmentFieldValue[0] ?? null) : $apartmentFieldValue;
    }

    if (!$apartmentId) {
        throw new Exception('Apartment ID not found in unit');
    }

    // Получаем апартамент из Alma для parent_unit
    $apartmentRentalObject = $alma->getRentalObject($apartmentId);
    if ($apartmentRentalObject['code'] !== 200 || !isset($apartmentRentalObject['response']['id'])) {
        throw new Exception("Apartment $apartmentId not found in Alma. Please sync apartment first.");
    }
    
    $parentUnitId = $apartmentRentalObject['response']['id'];

    $externalId = $unitData['id'] ?? $unitId;
    $name = $unitData['title'] ?? ('Room ' . $externalId);
    if ($name === '') { $name = 'Room ' . $externalId; }

    // Поля для HongKong юнитов
    $internalArea = (float)($unitData['ufCrm13TotalAreaOfTheRoom'] ?? 0);
    $goalRentCost = (float)($unitData['opportunity'] ?? ($unitData['ufCrm13RentAmount'] ?? 0));
    
    // property_type для rooms - всегда "room" для HongKong
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

    Logger::info("Sending room data to Alma", [
        'unit_id' => $unitId,
        'external_id' => $externalId,
        'parent_unit' => $parentUnitId,
        'data' => $data
    ], 'unit', $unitId);

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
        throw new Exception('Failed to process room (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
    }

} catch (Exception $e) {
    Logger::error('Exception occurred: ' . $e->getMessage(), [
        'exception' => $e->getMessage(),
        'trace'     => $e->getTraceAsString()
    ], 'unit', $_GET['id'] ?? 'unknown');

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

