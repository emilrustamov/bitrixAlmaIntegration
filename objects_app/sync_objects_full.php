<?php

require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/DatabaseConfig.php';

Config::load();

class AlmaObjectsSync {
    private $apiKey;
    private $apiUrl;
    private $pdo;
    private $stats = [
        'total_processed' => 0,
        'new_objects' => 0,
        'updated_objects' => 0,
        'unchanged_objects' => 0,
        'errors' => 0
    ];
    
    public function __construct() {
        $this->apiKey = Config::get('ALMA_API_KEY');
        $this->apiUrl = rtrim(Config::get('ALMA_API_URL'), '/');
        $this->initDatabase();
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
    
    private function makeApiCall($endpoint) {
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
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            Logger::error("API Error: HTTP $httpCode for $endpoint");
            return false;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Logger::error("JSON decode error for $endpoint: " . json_last_error_msg());
            return false;
        }
        
        return $decoded;
    }
    
    public function syncAllObjects() {
        Logger::info("Starting Alma objects synchronization");
        echo "🚀 Начинаем синхронизацию объектов Alma...\n";
        
        // Синхронизируем юниты (апартаменты)
        $this->syncUnits();
        
        // Синхронизируем комнаты
        $this->syncRooms();
        
        // Выводим статистику
        $this->printStats();
        
        Logger::info("Alma objects synchronization completed", $this->stats);
        echo "✅ Синхронизация завершена!\n";
    }
    
    private function syncUnits() {
        echo "📋 Синхронизируем апартаменты...\n";
        
        $units = $this->makeApiCall('realty/units/');
        if (!$units) {
            Logger::error("Failed to get units from API");
            return;
        }
        
        foreach ($units as $unit) {
            $this->processObject($unit, 'apartment', false);
        }
        
        echo "✅ Апартаменты обработаны: " . count($units) . "\n";
    }
    
    private function syncRooms() {
        echo "📋 Синхронизируем комнаты...\n";
        
        $rooms = $this->makeApiCall('realty/rooms/');
        if (!$rooms) {
            Logger::error("Failed to get rooms from API");
            return;
        }
        
        foreach ($rooms as $room) {
            $this->processObject($room, 'room', true);
        }
        
        echo "✅ Комнаты обработаны: " . count($rooms) . "\n";
    }
    
    private function processObject($apiObject, $objectType, $isRoom) {
        try {
            $this->stats['total_processed']++;
            
            // Получаем существующий объект из БД
            $existingObject = $this->getExistingObject($apiObject['id']);
            
            // Подготавливаем данные для сравнения
            $newData = [
                'alma_id' => $apiObject['id'],
                'external_id' => $apiObject['external_id'] ?? '',
                'name' => $apiObject['name'] ?? '',
                'header' => $apiObject['header'] ?? '',
                'object_type' => $objectType,
                'is_room' => $isRoom ? 1 : 0
            ];
            
            if (!$existingObject) {
                // Новый объект - создаем
                $this->createObject($newData);
                $this->stats['new_objects']++;
                echo "➕ Создан: {$newData['name']} (ID: {$newData['alma_id']})\n";
            } else {
                // Проверяем изменения
                if ($this->hasChanges($existingObject, $newData)) {
                    $this->updateObject($newData);
                    $this->stats['updated_objects']++;
                    echo "🔄 Обновлен: {$newData['name']} (ID: {$newData['alma_id']})\n";
                } else {
                    $this->stats['unchanged_objects']++;
                    // Не выводим сообщение для неизмененных объектов (слишком много)
                }
            }
            
        } catch (Exception $e) {
            $this->stats['errors']++;
            Logger::error("Error processing object {$apiObject['id']}: " . $e->getMessage());
            echo "❌ Ошибка обработки объекта {$apiObject['id']}: " . $e->getMessage() . "\n";
        }
    }
    
    private function getExistingObject($almaId) {
        $stmt = $this->pdo->prepare("SELECT * FROM alma_objects WHERE alma_id = ?");
        $stmt->execute([$almaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function hasChanges($existing, $new) {
        // Сравниваем ключевые поля
        $fieldsToCompare = ['external_id', 'name', 'header', 'object_type', 'is_room'];
        
        foreach ($fieldsToCompare as $field) {
            $existingValue = $existing[$field] ?? '';
            $newValue = $new[$field] ?? '';
            
            // Приводим к строке для корректного сравнения
            if ((string)$existingValue !== (string)$newValue) {
                return true;
            }
        }
        
        return false;
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
        
        $stmt->execute($data);
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
        
        $stmt->execute($data);
    }
    
    private function printStats() {
        echo "\n📊 Статистика синхронизации:\n";
        echo "   Всего обработано: {$this->stats['total_processed']}\n";
        echo "   Новых объектов: {$this->stats['new_objects']}\n";
        echo "   Обновлено: {$this->stats['updated_objects']}\n";
        echo "   Без изменений: {$this->stats['unchanged_objects']}\n";
        echo "   Ошибок: {$this->stats['errors']}\n";
    }
    
    public function getStats() {
        return $this->stats;
    }
}

// Запуск синхронизации если файл вызван напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $sync = new AlmaObjectsSync();
        $sync->syncAllObjects();
        
        $stats = $sync->getStats();
        if ($stats['errors'] > 0) {
            exit(1); // Код ошибки для cron
        }
        
    } catch (Exception $e) {
        Logger::error("Sync failed: " . $e->getMessage());
        echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
