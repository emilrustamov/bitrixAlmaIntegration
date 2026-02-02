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
        $realtyApiUrl = rtrim(Config::get('ALMA_REALTY_API_URL'), '/');
        $url = $realtyApiUrl . '/rooms/external_id/' . rawurlencode($bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    /**
     * Создать комнату (только POST, без проверки существования).
     * Используется в syncRoomsForApartment для создания новых комнат.
     */
    public function createRoom($data) {
        $endpoint = 'rooms/';
        $response = $this->call('POST', $endpoint, $data, true);
        $response['method'] = 'POST';
        return $response;
    }

    /**
     * Создать или обновить комнату (POST если нет, PATCH если есть).
     * Используется в RoomApi.php для обновления существующих комнат.
     */
    public function createOrUpdateRoom($data, $bitrixId) {
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200 && is_array($rentalObject['response']) && !empty($rentalObject['response']['id'])) {
            $targetId = $rentalObject['response']['id'];
            $endpoint = 'rooms/' . $targetId . '/';
            
            $patchData = $data;
            // parent_unit нужен при PATCH, если передан как объект — приводим к id
            if (isset($patchData['parent_unit']) && is_array($patchData['parent_unit'])) {
                $patchData['parent_unit'] = $patchData['parent_unit']['id'] ?? null;
            }
            unset($patchData['external_id']);
            unset($patchData['property_type']);
            
            if (empty($patchData['name'])) {
                $patchData['name'] = $data['name'] ?? ('Room ' . $bitrixId);
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

// NOTE:
// Ранее ниже был блок с автозапуском синхронизации комнаты по $_GET['id'].
// Он выполнялся при include 'UnitApi.php' (например, из AppartApi.php)
// и писал в лог/выводил JSON про комнаты.
// Сейчас UnitApi используется только как библиотека,
// а отдельный эндпоинт для комнат — Api/RoomApi.php.

