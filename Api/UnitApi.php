<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/BaseApi.php';

Config::load();

class UnitApi {
    private $apiKey;
    private $apiUrl;

    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
    }
    
    public function getRentalObject($bitrixId) {
        $url = rtrim($this->apiUrl, '/') . '/realty/rooms/external_id/' . rawurlencode((string)$bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }
    
    public function createRoom($data) {
        $endpoint = 'rooms/';
        $response = $this->call('POST', $endpoint, $data, true);
        $response['method'] = 'POST';
        return $response;
    }
    
    public function createOrUpdateRoom($data, $bitrixId) {
        $rentalObject = $this->getRentalObject($bitrixId);
        
        if (($rentalObject['code'] ?? 0) === 200 && !empty($rentalObject['response']['id'])) {
            $targetId = $rentalObject['response']['id'];
            $endpoint = 'rooms/' . $targetId . '/';
            
            $patchData = $data;
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
        $url = rtrim($this->apiUrl, '/') . '/realty/' . ltrim($endpoint, '/');
        
        if (!$isPost && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        return BaseApi::sendRequest($this->apiKey, $method, $url, $isPost ? $data : null, false);
    }
}

