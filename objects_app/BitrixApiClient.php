<?php

class BitrixApiClient {
    private $baseUrl;
    
    public function __construct() {
        require_once(__DIR__ . '/../Core/Logger.php');
        require_once(__DIR__ . '/../Core/Config/Config.php');
        
        Config::load();
        
        // Используем webhook URL из конфига
        $this->baseUrl = Config::get('PROJECT_DUBAI_WEBHOOK_URL');
    }
    
    /**
     * Выполнить запрос к Bitrix24 API
     */
    private function makeRequest($endpoint, $params = []) {
        $url = $this->baseUrl . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            Logger::error("Bitrix API cURL Error: " . $error);
            return null;
        }
        
        if ($httpCode !== 200) {
            Logger::error("Bitrix API HTTP Error: " . $httpCode . " Response: " . $response);
            return null;
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("Bitrix API JSON Error: " . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
    
    /**
     * Получить контракт по ID
     */
    public function getContract($contractId) {
        return $this->makeRequest('crm.item.get', [
            'entityTypeId' => 183,
            'id' => $contractId
        ]);
    }
    
    /**
     * Получить юнит по ID
     */
    public function getUnit($unitId) {
        return $this->makeRequest('crm.item.get', [
            'entityTypeId' => 167,
            'id' => $unitId
        ]);
    }
    
    /**
     * Получить апартамент по ID
     */
    public function getApartment($apartmentId) {
        return $this->makeRequest('crm.item.get', [
            'entityTypeId' => 144,
            'id' => $apartmentId
        ]);
    }
    
    /**
     * Получить список контрактов с пагинацией
     */
    public function getContracts($start = 0, $limit = 50) {
        return $this->makeRequest('crm.item.list', [
            'entityTypeId' => 183,
            'start' => $start,
            'limit' => $limit
        ]);
    }
    
    /**
     * Получить список юнитов с пагинацией
     */
    public function getUnits($start = 0, $limit = 50) {
        return $this->makeRequest('crm.item.list', [
            'entityTypeId' => 167,
            'start' => $start,
            'limit' => $limit
        ]);
    }
    
    /**
     * Получить список апартаментов с пагинацией
     */
    public function getApartments($start = 0, $limit = 50) {
        return $this->makeRequest('crm.item.list', [
            'entityTypeId' => 144,
            'start' => $start,
            'limit' => $limit
        ]);
    }
    
    /**
     * Получить контракты для конкретного юнита
     */
    public function getContractsByUnit($unitId) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . 'crm.item.list');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'entityTypeId' => 183,
            'filter' => [
                'ufCrm20_1693919019' => $unitId
            ]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            Logger::error("Bitrix API Error getting contracts for unit $unitId: " . ($error ?: "HTTP $httpCode"));
            return null;
        }
        
        $data = json_decode($response, true);
        return $data;
    }
    
    /**
     * Получить простую информацию об объекте без контрактов
     */
    public function getSimpleObjectInfo($external_id, $is_room) {
        try {
            if ($is_room) {
                // Для комнат: external_id = ID юнита в Bitrix
                $unitResponse = $this->getUnit($external_id);
                
                if ($unitResponse && isset($unitResponse['result']['item'])) {
                    $unit = $unitResponse['result']['item'];
                    $apartmentId = $unit['ufCrm8_1684429208'] ?? null;
                    
                    $apartment = null;
                    if ($apartmentId) {
                        $apartmentResponse = $this->getApartment($apartmentId);
                        if ($apartmentResponse && isset($apartmentResponse['result']['item'])) {
                            $apartment = $apartmentResponse['result']['item'];
                        }
                    }
                    
                    return [
                        'unit' => $unit,
                        'apartment' => $apartment,
                        'bitrix_type' => 'room',
                        'stage' => $unit['ufCrm6_1742486676'] ?? null
                    ];
                }
            } else {
                // Для юнитов: external_id = ID апартамента в Bitrix
                $apartmentResponse = $this->getApartment($external_id);
                
                if ($apartmentResponse && isset($apartmentResponse['result']['item'])) {
                    $apartment = $apartmentResponse['result']['item'];
                    
                    return [
                        'unit' => null,
                        'apartment' => $apartment,
                        'bitrix_type' => 'unit',
                        'stage' => $apartment['ufCrm6_1742486676'] ?? null
                    ];
                }
            }
            
        } catch (Exception $e) {
            Logger::error("Error getting simple object info for external_id $external_id: " . $e->getMessage());
        }
        
        return null;
    }
    
    
    /**
     * Получить статистику по типам объектов из Bitrix с пагинацией
     */
    public function getBitrixStats() {
        $stats = [
            'apartments' => 0,
            'rooms' => 0,
            'total' => 0,
            'processedRecords' => 0
        ];
        
        $start = 0;
        $limit = 50; // Bitrix API limit
        $hasMore = true;
        
        Logger::info("Starting Bitrix stats collection with categoryId: 10");
        
        try {
            while ($hasMore) {
                $params = [
                    'entityTypeId' => 144, // Apartments
                    'filter' => [
                        'categoryId' => 10, // Категория "Apartments"
                    ],
                    'select' => [
                        'id', 
                        'title', 
                        'ufCrm6_1684425402', // Units* (Привязка к элементам CRM)
                        'ufCrm6_1736951470242' // Rent (by rooms / unit)
                    ],
                    'start' => $start
                ];
                
                $response = $this->makeRequest('crm.item.list', $params);
                
                if (!isset($response['result']) || !isset($response['result']['items'])) {
                    Logger::error("Bitrix API error or no items in response", ['response' => $response]);
                    break;
                }
                
                $items = $response['result']['items'];
                $hasMore = $response['result']['hasNext'] ?? false;
                
                foreach ($items as $apartment) {
                    $unitsArray = $apartment['ufCrm6_1684425402'] ?? []; // Units* (массив ID юнитов)
                    $rentType = $apartment['ufCrm6_1736951470242'] ?? null; // Rent (by rooms / unit)
                    
                    // Ensure unitsArray is an array
                    if (!is_array($unitsArray)) {
                        $unitsArray = [$unitsArray];
                    }
                    
                    $unitsCount = count($unitsArray);
                    
                    // Logic based on Rent (by rooms / unit) field
                    if ($rentType == 4598) { // Value for "Unit"
                        $stats['apartments']++;
                    } elseif ($rentType == 4600) { // Value for "Rooms"
                        // Count rooms based on the number of elements in Units*
                        $stats['rooms'] += $unitsCount;
                    }
                    
                    $stats['processedRecords']++;
                }
                
                $start += $limit;
                usleep(100000); // 100ms pause
            }
            
            $stats['total'] = $stats['apartments'] + $stats['rooms'];
            
            Logger::info("Bitrix stats final", [
                'apartments' => $stats['apartments'],
                'rooms' => $stats['rooms'], 
                'total' => $stats['total'],
                'processedRecords' => $stats['processedRecords']
            ]);
            
            return ['success' => true, 'data' => $stats];
            
        } catch (Exception $e) {
            Logger::error("Failed to get Bitrix stats: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
}
