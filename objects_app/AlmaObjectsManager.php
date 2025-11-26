<?php
/**
 * Alma Objects Manager
 * Управление объектами недвижимости
 */

class AlmaObjectsManager {
    private $apiKey;
    private $apiUrl;
    private $pdo;
    private $bitrixClient;
    
    public function __construct() {
        $this->apiKey = Config::get('ALMA_API_KEY');
        $this->apiUrl = rtrim(Config::get('ALMA_API_URL'), '/');
        $this->initDatabase();
        
        // Инициализация Bitrix API клиента
        require_once(__DIR__ . '/BitrixApiClient.php');
        $this->bitrixClient = new BitrixApiClient();
    }
    
    private function initDatabase() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . Config::get('DB_HOST') . ";dbname=" . Config::get('DB_NAME') . ";charset=" . Config::get('DB_CHARSET'),
                Config::get('DB_USER'),
                Config::get('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            Logger::error("Database connection failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function makeApiCall($endpoint) {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => 'GET'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            $error = curl_error($ch);
            Logger::error("CURL Error for $endpoint: " . $error);
            curl_close($ch);
            return ['error' => $error];
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            Logger::error("API Error: HTTP $httpCode for $endpoint");
            return ['error' => "HTTP $httpCode"];
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("JSON decode error for $endpoint: " . json_last_error_msg());
            return ['error' => 'Invalid JSON'];
        }
        
        return $decoded;
    }
    
    public function getCategorizedObjects() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    o.*,
                    d.project_name,
                    d.building_name,
                    d.status,
                    d.is_archived,
                    d.parent_unit_id
                FROM alma_objects o
                LEFT JOIN alma_object_details d ON o.alma_id = d.alma_id
                ORDER BY o.name
            ");
            
            $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $categorized = [
                'Hong Kong' => [],
                'Dubai' => [],
                'Unknown' => []
            ];
            
            foreach ($objects as $object) {
                $projectName = 'Unknown';
                
                if (!empty($object['project_name'])) {
                    $name = strtolower($object['project_name']);
                    if (strpos($name, 'hong') !== false || strpos($name, 'kong') !== false) {
                        $projectName = 'Hong Kong';
                    } elseif (strpos($name, 'dubai') !== false || strpos($name, 'uae') !== false) {
                        $projectName = 'Dubai';
                    } elseif (strpos($name, 'west') !== false && strpos($name, 'tower') !== false) {
                        // West Tower относим к Dubai
                        $projectName = 'Dubai';
                    } elseif (strpos($name, 'downtown') !== false && strpos($name, 'towers') !== false) {
                        // Downtown Towers относим к Dubai
                        $projectName = 'Dubai';
                    }
                }
                
                $object['is_room'] = (bool)$object['is_room'];
                $object['is_archived'] = (bool)$object['is_archived'];
                
                // Убеждаемся что статус передается как строка
                if (isset($object['status']) && $object['status'] !== null) {
                    $object['status'] = (string)$object['status'];
                }
                
                $categorized[$projectName][] = $object;
            }
            
            return $categorized;
            
        } catch (PDOException $e) {
            Logger::error("Failed to get categorized objects: " . $e->getMessage());
            return [
                'Hong Kong' => [],
                'Dubai' => [],
                'West Tower' => [],
                'Unknown' => []
            ];
        }
    }
    
    public function syncFromApi() {
        try {
            Logger::info("Starting manual sync from API");
            
            $stats = [
                'total_processed' => 0,
                'new_objects' => 0,
                'updated_objects' => 0,
                'unchanged_objects' => 0,
                'errors' => 0
            ];
            
            // Синхронизируем юниты
            $units = $this->makeApiCall('realty/units/');
            if (isset($units['error'])) {
                throw new Exception("Failed to get units: " . $units['error']);
            }
            
            foreach ($units as $unit) {
                $result = $this->processObject($unit, 'apartment', false);
                $stats[$result]++;
                $stats['total_processed']++;
            }
            
            // Синхронизируем комнаты
            $rooms = $this->makeApiCall('realty/rooms/');
            if (isset($rooms['error'])) {
                throw new Exception("Failed to get rooms: " . $rooms['error']);
            }
            
            foreach ($rooms as $room) {
                $result = $this->processObject($room, 'room', true);
                $stats[$result]++;
                $stats['total_processed']++;
            }
            
            Logger::info("Manual sync completed", $stats);
            
            return [
                'success' => true,
                'message' => "Синхронизация завершена. Обработано: {$stats['total_processed']}, новых: {$stats['new_objects']}, обновлено: {$stats['updated_objects']}",
                'stats' => $stats
            ];
            
        } catch (Exception $e) {
            Logger::error("Manual sync failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить статистику из Bitrix по типам объектов
     */
    public function getBitrixStats($forceRefresh = false) {
        if (!$forceRefresh) {
            // Попробуем получить из кэша
            $cached = $this->getCachedBitrixStats();
            if ($cached) {
                return $cached;
            }
        }
        
        // Получаем статистику из Bitrix API
        $result = $this->bitrixClient->getBitrixStats();
        
        if ($result['success']) {
            $stats = $result['data'];
            // Сохраняем в кэш
            $this->saveBitrixStats($stats);
            return $stats;
        } else {
            return [
                'apartments' => 0,
                'rooms' => 0,
                'total' => 0,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }
    }
    
    /**
     * Обновить статистику Bitrix и сохранить в базу
     */
    public function updateBitrixStats() {
        try {
            Logger::info("Starting Bitrix stats update");
            
            // Получаем свежую статистику из Bitrix
            $result = $this->bitrixClient->getBitrixStats();
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to get Bitrix stats'
                ];
            }
            
            $stats = $result['data'];
            
            if (isset($stats['error'])) {
                return [
                    'success' => false,
                    'error' => $stats['error']
                ];
            }
            
            // Сохраняем в базу
            $stmt = $this->pdo->prepare("
                INSERT INTO bitrix_stats (apartments, rooms, total, created_at, updated_at)
                VALUES (:apartments, :rooms, :total, NOW(), NOW())
            ");
            
            $stmt->execute([
                'apartments' => $stats['apartments'],
                'rooms' => $stats['rooms'],
                'total' => $stats['total']
            ]);
            
            Logger::info("Bitrix stats saved to database", $stats);
            
            return [
                'success' => true,
                'data' => $stats,
                'message' => "Статистика обновлена и сохранена в базу",
                'debug' => $stats['debug'] ?? []
            ];
            
        } catch (Exception $e) {
            Logger::error("Error updating Bitrix stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить последнюю статистику Bitrix из базы
     */
    public function getCachedBitrixStats() {
        try {
            $stmt = $this->pdo->query("
                SELECT apartments, rooms, total, updated_at
                FROM bitrix_stats
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return [
                    'success' => true,
                    'data' => $result,
                    'cached' => true
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Нет сохраненной статистики. Обновите данные из Bitrix24.'
                ];
            }
            
        } catch (Exception $e) {
            Logger::error("Error getting cached Bitrix stats: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function processObject($apiObject, $objectType, $isRoom) {
        try {
            $existingObject = $this->getExistingObject($apiObject['id']);
            
            $newData = [
                'alma_id' => $apiObject['id'],
                'external_id' => $apiObject['external_id'] ?? '',
                'name' => $apiObject['name'] ?? '',
                'header' => $apiObject['header'] ?? '',
                'object_type' => $objectType,
                'is_room' => $isRoom ? 1 : 0
            ];
            
            if (!$existingObject) {
                $this->createObject($newData);
                // Для новых объектов всегда получаем детали
                $this->updateObjectDetails($apiObject['id'], $isRoom);
                return 'new_objects';
            } else {
                $hasBasicChanges = $this->hasChanges($existingObject, $newData);
                
                if ($hasBasicChanges) {
                    $this->updateObject($newData);
                }
                
                // ВСЕГДА обновляем детали для всех объектов
                $this->updateObjectDetails($apiObject['id'], $isRoom);
                
                return $hasBasicChanges ? 'updated_objects' : 'unchanged_objects';
            }
            
        } catch (Exception $e) {
            Logger::error("Error processing object {$apiObject['id']}: " . $e->getMessage());
            return 'errors';
        }
    }
    
    public function getExistingObject($almaId) {
        $stmt = $this->pdo->prepare("SELECT * FROM alma_objects WHERE alma_id = ?");
        $stmt->execute([$almaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function getExistingObjectWithDetails($almaId) {
        $stmt = $this->pdo->prepare("
            SELECT o.*, d.status, d.is_archived, d.project_name, d.building_name, d.parent_unit_id
            FROM alma_objects o
            LEFT JOIN alma_object_details d ON o.alma_id = d.alma_id
            WHERE o.alma_id = ?
        ");
        $stmt->execute([$almaId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Структурируем данные для сравнения
            $details = [
                'status' => $result['status'],
                'is_archived' => $result['is_archived'],
                'project_name' => $result['project_name'],
                'building_name' => $result['building_name'],
                'parent_unit_id' => $result['parent_unit_id']
            ];
            $result['details'] = $details;
        }
        
        return $result;
    }
    
    private function fetchObjectDetails($almaId, $isRoom) {
        try {
            $endpoint = $isRoom ? 'realty/rooms/' . $almaId . '/' : 'realty/units/' . $almaId . '/';
            $response = $this->makeApiCall($endpoint);
            
            if ($response) {
                return [
                    'status' => $response['status'] ?? 'unknown',
                    'is_archived' => $response['is_archived'] ?? false,
                    'project_name' => $response['project']['name'] ?? null,
                    'building_name' => $response['building']['name'] ?? null,
                    'parent_unit_id' => $response['parent_unit']['id'] ?? null
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            Logger::error("Error fetching details for object $almaId: " . $e->getMessage());
            return null;
        }
    }
    
    private function hasChanges($existing, $new) {
        $fieldsToCompare = ['external_id', 'name', 'header', 'object_type', 'is_room'];
        
        foreach ($fieldsToCompare as $field) {
            $existingValue = $existing[$field] ?? '';
            $newValue = $new[$field] ?? '';
            
            if ((string)$existingValue !== (string)$newValue) {
                return true;
            }
        }
        
        return false;
    }
    
    private function updateObjectDetails($almaId, $isRoom) {
        try {
            $objectDetails = $this->fetchObjectDetails($almaId, $isRoom);
            if ($objectDetails) {
                $this->saveObjectDetailsFromArray($almaId, $objectDetails);
            }
        } catch (Exception $e) {
            Logger::error("Error updating details for object $almaId: " . $e->getMessage());
        }
    }
    
    private function createObject($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO alma_objects (
                alma_id, external_id, name, header, object_type, is_room, 
                created_at, updated_at
            ) VALUES (
                :alma_id, :external_id, :name, :header, :object_type, :is_room,
                NOW(), NOW()
            )
        ");
        
        // Убираем поле details из данных для SQL
        $sqlData = [
            'alma_id' => $data['alma_id'],
            'external_id' => $data['external_id'],
            'name' => $data['name'],
            'header' => $data['header'],
            'object_type' => $data['object_type'],
            'is_room' => $data['is_room']
        ];
        
        $stmt->execute($sqlData);
    }
    
    private function updateObject($data) {
        $stmt = $this->pdo->prepare("
            UPDATE alma_objects SET
                external_id = :external_id,
                name = :name,
                header = :header,
                object_type = :object_type,
                is_room = :is_room,
                updated_at = NOW()
            WHERE alma_id = :alma_id
        ");
        
        // Убираем поле details из данных для SQL
        $sqlData = [
            'alma_id' => $data['alma_id'],
            'external_id' => $data['external_id'],
            'name' => $data['name'],
            'header' => $data['header'],
            'object_type' => $data['object_type'],
            'is_room' => $data['is_room']
        ];
        
        $stmt->execute($sqlData);
    }
    
    public function getObjectDetails($objectId, $isRoom) {
        try {
            $endpoint = $isRoom ? "realty/rooms/{$objectId}/" : "realty/units/{$objectId}/";
            $details = $this->makeApiCall($endpoint);
            
            if (isset($details['error'])) {
                return ['success' => false, 'error' => $details['error']];
            }
            
            // Сохраняем детали в базу (только нужные поля)
            $this->saveObjectDetails($details);
            
            return ['success' => true, 'data' => $details];
            
        } catch (Exception $e) {
            Logger::error("Failed to get object details: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function saveObjectDetails($details) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO alma_object_details (
                    alma_id, is_archived, status, project_name, building_name, parent_unit_id,
                    created_at, updated_at
                ) VALUES (
                    :alma_id, :is_archived, :status, :project_name, :building_name, :parent_unit_id,
                    NOW(), NOW()
                ) ON DUPLICATE KEY UPDATE
                    is_archived = VALUES(is_archived),
                    status = VALUES(status),
                    project_name = VALUES(project_name),
                    building_name = VALUES(building_name),
                    parent_unit_id = VALUES(parent_unit_id),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                'alma_id' => $details['id'],
                'is_archived' => $details['is_archived'] ? 1 : 0,
                'status' => $details['status'] ?? '',
                'project_name' => $details['project']['name'] ?? '',
                'building_name' => $details['building']['name'] ?? '',
                'parent_unit_id' => $details['parent_unit']['id'] ?? null
            ]);
            
            Logger::info("Saved details for object: " . $details['id']);
            
        } catch (PDOException $e) {
            Logger::error("Failed to save object details: " . $e->getMessage());
        }
    }
    
    private function saveObjectDetailsFromArray($almaId, $details) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO alma_object_details (
                    alma_id, is_archived, status, project_name, building_name, parent_unit_id,
                    created_at, updated_at
                ) VALUES (
                    :alma_id, :is_archived, :status, :project_name, :building_name, :parent_unit_id,
                    NOW(), NOW()
                ) ON DUPLICATE KEY UPDATE
                    is_archived = VALUES(is_archived),
                    status = VALUES(status),
                    project_name = VALUES(project_name),
                    building_name = VALUES(building_name),
                    parent_unit_id = VALUES(parent_unit_id),
                    updated_at = NOW()
            ");
            
            $stmt->execute([
                'alma_id' => $almaId,
                'is_archived' => $details['is_archived'] ? 1 : 0,
                'status' => $details['status'] ?? 'unknown',
                'project_name' => $details['project_name'] ?? null,
                'building_name' => $details['building_name'] ?? null,
                'parent_unit_id' => $details['parent_unit_id'] ?? null
            ]);
            
            Logger::info("Saved details for object: " . $almaId);
            
        } catch (PDOException $e) {
            Logger::error("Failed to save object details: " . $e->getMessage());
        }
    }
    
    /**
     * Обогатить данные объекта информацией из Bitrix24
     */
    public function enrichObjectWithBitrixData($object) {
        $external_id = $object['external_id'] ?? null;
        $is_room = $object['is_room'] ?? false;
        
        if (!$external_id) {
            return $object;
        }
        
        try {
            $bitrixInfo = $this->bitrixClient->getSimpleObjectInfo($external_id, $is_room);
            
            if ($bitrixInfo) {
                // Добавляем информацию об апартаменте из Bitrix
                if (isset($bitrixInfo['apartment'])) {
                    $apartment = $bitrixInfo['apartment'];
                    $object['bitrix_apartment_name'] = $apartment['title'] ?? null;
                    $object['bitrix_apartment_id'] = $apartment['id'] ?? null;
                    
                    // Определяем тип аренды из апартамента
                    $rentTypeField = $apartment['ufCrm6_1736951470242'] ?? null;
                    $object['bitrix_rent_type'] = ($rentTypeField == 4600) ? 'rooms' : 'unit';
                }
                
                // Добавляем информацию о юните из Bitrix (для комнат)
                if (isset($bitrixInfo['unit'])) {
                    $unit = $bitrixInfo['unit'];
                    $object['bitrix_unit_name'] = $unit['title'] ?? null;
                    $object['bitrix_unit_id'] = $unit['id'] ?? null;
                }
                
                $object['bitrix_type'] = $bitrixInfo['bitrix_type'];
                $object['bitrix_status'] = 'found';
                
            } else {
                $object['bitrix_status'] = 'not_found';
            }
            
        } catch (Exception $e) {
            error_log("Error enriching object {$object['alma_id']} with Bitrix data: " . $e->getMessage());
            
            // Добавляем информацию об ошибке
            $object['bitrix_error'] = $e->getMessage();
            $object['bitrix_status'] = 'error';
        }
        
        return $object;
    }
    
    /**
     * Получить объекты с обогащенными данными из Bitrix
     */
    public function getCategorizedObjectsWithBitrix() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    o.*,
                    d.project_name,
                    d.building_name,
                    d.status,
                    d.is_archived,
                    d.parent_unit_id
                FROM alma_objects o
                LEFT JOIN alma_object_details d ON o.alma_id = d.alma_id
                ORDER BY o.name
            ");
            
            $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $categorized = [
                'Hong Kong' => [],
                'Dubai' => [],
                'Unknown' => []
            ];
            
            foreach ($objects as $object) {
                // Обогащаем данными из Bitrix
                $enrichedObject = $this->enrichObjectWithBitrixData($object);
                
                $projectName = 'Unknown';
                
                if (!empty($enrichedObject['project_name'])) {
                    $name = strtolower($enrichedObject['project_name']);
                    if (strpos($name, 'hong') !== false || strpos($name, 'kong') !== false) {
                        $projectName = 'Hong Kong';
                    } elseif (strpos($name, 'dubai') !== false || strpos($name, 'uae') !== false ||
                              (strpos($name, 'west') !== false && strpos($name, 'tower') !== false) ||
                              (strpos($name, 'downtown') !== false && strpos($name, 'towers') !== false)) {
                        $projectName = 'Dubai';
                    }
                }
                
                // Убеждаемся, что поля имеют правильные типы
                $enrichedObject['is_archived'] = (bool)($enrichedObject['is_archived'] ?? false);
                $enrichedObject['status'] = (string)($enrichedObject['status'] ?? 'unknown');
                
                $categorized[$projectName][] = $enrichedObject;
            }
            
            return $categorized;
            
        } catch (PDOException $e) {
            error_log("Database error in getCategorizedObjectsWithBitrix: " . $e->getMessage());
            return [
                'Hong Kong' => [],
                'Dubai' => [],
                'Unknown' => []
            ];
        }
    }
    
    /**
     * Сохранить данные Bitrix в базу данных
     */
    public function saveBitrixData($external_id, $object_type, $bitrixInfo) {
        try {
            $sql = "
                INSERT INTO bitrix_data (
                    external_id, object_type, bitrix_type, 
                    bitrix_apartment_id, bitrix_apartment_name,
                    bitrix_unit_id, bitrix_unit_name,
                    bitrix_rent_type, bitrix_stage, bitrix_status, bitrix_error
                ) VALUES (
                    :external_id, :object_type, :bitrix_type,
                    :bitrix_apartment_id, :bitrix_apartment_name,
                    :bitrix_unit_id, :bitrix_unit_name,
                    :bitrix_rent_type, :bitrix_stage, :bitrix_status, :bitrix_error
                ) ON DUPLICATE KEY UPDATE
                    bitrix_type = VALUES(bitrix_type),
                    bitrix_apartment_id = VALUES(bitrix_apartment_id),
                    bitrix_apartment_name = VALUES(bitrix_apartment_name),
                    bitrix_unit_id = VALUES(bitrix_unit_id),
                    bitrix_unit_name = VALUES(bitrix_unit_name),
                    bitrix_rent_type = VALUES(bitrix_rent_type),
                    bitrix_stage = VALUES(bitrix_stage),
                    bitrix_status = VALUES(bitrix_status),
                    bitrix_error = VALUES(bitrix_error),
                    last_updated = CURRENT_TIMESTAMP
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'external_id' => $external_id,
                'object_type' => $object_type,
                'bitrix_type' => $bitrixInfo['bitrix_type'] ?? 'unit',
                'bitrix_apartment_id' => $bitrixInfo['apartment']['id'] ?? null,
                'bitrix_apartment_name' => $bitrixInfo['apartment']['title'] ?? null,
                'bitrix_unit_id' => $bitrixInfo['unit']['id'] ?? null,
                'bitrix_unit_name' => $bitrixInfo['unit']['title'] ?? null,
                'bitrix_rent_type' => $bitrixInfo['rent_type'] ?? null,
                'bitrix_stage' => $bitrixInfo['stage'] ?? null,
                'bitrix_status' => $bitrixInfo['status'] ?? 'not_found',
                'bitrix_error' => $bitrixInfo['error'] ?? null
            ]);
            
        } catch (PDOException $e) {
            Logger::error("Failed to save Bitrix data: " . $e->getMessage());
        }
    }
    
    /**
     * Получить данные Bitrix из базы данных
     */
    public function getBitrixData($external_id, $object_type) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM bitrix_data 
                WHERE external_id = :external_id AND object_type = :object_type
            ");
            $stmt->execute([
                'external_id' => $external_id,
                'object_type' => $object_type
            ]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            Logger::error("Failed to get Bitrix data: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Получить объекты с кэшированными данными из Bitrix
     */
    public function getCategorizedObjectsWithBitrixCached() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    o.*,
                    d.project_name,
                    d.building_name,
                    d.status,
                    d.is_archived,
                    d.parent_unit_id,
                    b.bitrix_status,
                    b.bitrix_apartment_name,
                    b.bitrix_apartment_id,
                    b.bitrix_unit_name,
                    b.bitrix_unit_id,
                    b.bitrix_rent_type,
                    b.bitrix_stage,
                    b.bitrix_type,
                    b.bitrix_error,
                    b.last_updated as bitrix_last_updated
                FROM alma_objects o
                LEFT JOIN alma_object_details d ON o.alma_id = d.alma_id
                LEFT JOIN bitrix_data b ON o.external_id = b.external_id 
                    AND b.object_type = CASE WHEN o.is_room = 1 THEN 'room' ELSE 'unit' END
                ORDER BY o.name
            ");
            
            $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $categorized = [
                'Hong Kong' => [],
                'Dubai' => [],
                'Unknown' => []
            ];
            
            foreach ($objects as $object) {
                $projectName = 'Unknown';
                
                if (!empty($object['project_name'])) {
                    $name = strtolower($object['project_name']);
                    if (strpos($name, 'hong') !== false || strpos($name, 'kong') !== false) {
                        $projectName = 'Hong Kong';
                    } elseif (strpos($name, 'dubai') !== false || strpos($name, 'uae') !== false ||
                              (strpos($name, 'west') !== false && strpos($name, 'tower') !== false) ||
                              (strpos($name, 'downtown') !== false && strpos($name, 'towers') !== false)) {
                        $projectName = 'Dubai';
                    }
                }
                
                // Убеждаемся, что поля имеют правильные типы
                $object['is_archived'] = (bool)($object['is_archived'] ?? false);
                $object['status'] = (string)($object['status'] ?? 'unknown');
                
                // Добавляем информацию о том, что данные из кэша
                $object['bitrix_cached'] = !empty($object['bitrix_status']);
                
                // Проверяем правильность external_id (должен содержать только цифры)
                $object['invalid_external_id'] = false;
                if (!empty($object['external_id'])) {
                    // Проверяем, содержит ли external_id что-то кроме цифр
                    if (!preg_match('/^[0-9]+$/', $object['external_id'])) {
                        $object['invalid_external_id'] = true;
                    }
                }
                
                // Проверяем соответствие статусов для объектов Дубая
                $object['status_mismatch'] = false;
                if ($projectName === 'Dubai' && !empty($object['bitrix_stage'])) {
                    $almaStatus = strtolower($object['status']);
                    $bitrixStage = strtolower($object['bitrix_stage']);
                    
                    // Маппинг статусов Bitrix на статусы Alma
                    $statusMapping = [
                        'available' => 'available',
                        'rented' => 'rented', 
                        'blocked' => 'blocked',
                        'ex-apartments' => 'archived'
                    ];
                    
                    $expectedAlmaStatus = $statusMapping[$bitrixStage] ?? null;
                    
                    // Проверяем архивный статус отдельно
                    if ($bitrixStage === 'ex-apartments') {
                        $object['status_mismatch'] = !$object['is_archived'];
                    } else if ($expectedAlmaStatus) {
                        $object['status_mismatch'] = ($almaStatus !== $expectedAlmaStatus);
                    }
                }
                
                $categorized[$projectName][] = $object;
            }
            
            return $categorized;
            
        } catch (PDOException $e) {
            Logger::error("Database error in getCategorizedObjectsWithBitrixCached: " . $e->getMessage());
            return [
                'Hong Kong' => [],
                'Dubai' => [],
                'Unknown' => []
            ];
        }
    }
}
?>
