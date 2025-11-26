<?php
/**
 * Синхронизация данных из Bitrix24
 * Запускается по cron каждый час
 */

require_once(__DIR__ . '/../Config.php');
require_once(__DIR__ . '/../Logger.php');
require_once(__DIR__ . '/AlmaObjectsManager.php');
require_once(__DIR__ . '/BitrixApiClient.php');

Config::load();

echo "🔄 Начинаем синхронизацию данных Bitrix24...\n";
Logger::info("Starting Bitrix data sync");

try {
    $manager = new AlmaObjectsManager();
    $bitrixClient = new BitrixApiClient();
    
    // Получаем все объекты из базы данных
    $pdo = new PDO(
        "mysql:host=" . Config::get('DB_HOST') . ";dbname=" . Config::get('DB_NAME') . ";charset=" . Config::get('DB_CHARSET'),
        Config::get('DB_USER'),
        Config::get('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $stmt = $pdo->query("
        SELECT alma_id, external_id, is_room, name 
        FROM alma_objects 
        WHERE external_id IS NOT NULL AND external_id != ''
        ORDER BY alma_id
    ");
    
    $objects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalObjects = count($objects);
    $processed = 0;
    $updated = 0;
    $errors = 0;
    
    echo "📊 Найдено объектов для синхронизации: $totalObjects\n";
    
    foreach ($objects as $object) {
        $processed++;
        $external_id = $object['external_id'];
        $is_room = (bool)$object['is_room'];
        $object_type = $is_room ? 'room' : 'unit';
        
        echo "🔄 [$processed/$totalObjects] Обрабатываем объект {$object['alma_id']} (external_id: $external_id, type: $object_type)\n";
        
        try {
            // Проверяем, есть ли свежие данные в кэше (меньше часа)
            $cachedData = $manager->getBitrixData($external_id, $object_type);
            
            if ($cachedData && strtotime($cachedData['last_updated']) > (time() - 3600)) {
                echo "   ✓ Данные свежие, пропускаем\n";
                continue;
            }
            
            // Получаем данные из Bitrix API
            $bitrixInfo = $bitrixClient->getSimpleObjectInfo($external_id, $is_room);
            
            if ($bitrixInfo) {
                // Подготавливаем данные для сохранения
                $dataToSave = [
                    'bitrix_type' => $bitrixInfo['bitrix_type'],
                    'apartment' => $bitrixInfo['apartment'] ?? null,
                    'unit' => $bitrixInfo['unit'] ?? null,
                    'stage' => $bitrixInfo['stage'] ?? null,
                    'status' => 'found'
                ];
                
                // Определяем тип аренды
                if (isset($bitrixInfo['apartment'])) {
                    $rentTypeField = $bitrixInfo['apartment']['ufCrm6_1736951470242'] ?? null;
                    $dataToSave['rent_type'] = ($rentTypeField == 4600) ? 'rooms' : 'unit';
                }
                
                // Сохраняем в кэш
                $manager->saveBitrixData($external_id, $object_type, $dataToSave);
                
                echo "   ✅ Данные обновлены\n";
                $updated++;
                
            } else {
                // Сохраняем информацию о том, что объект не найден
                $manager->saveBitrixData($external_id, $object_type, [
                    'status' => 'not_found'
                ]);
                
                echo "   ⚠️ Объект не найден в Bitrix\n";
                $updated++;
            }
            
            // Небольшая пауза между запросами
            usleep(100000); // 0.1 секунды
            
        } catch (Exception $e) {
            Logger::error("Error syncing Bitrix data for object {$object['alma_id']}: " . $e->getMessage());
            
            // Сохраняем информацию об ошибке
            $manager->saveBitrixData($external_id, $object_type, [
                'status' => 'error',
                'error' => $e->getMessage()
            ]);
            
            echo "   ❌ Ошибка: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n🎉 Синхронизация завершена!\n";
    echo "📊 Статистика:\n";
    echo "   - Обработано: $processed\n";
    echo "   - Обновлено: $updated\n";
    echo "   - Ошибок: $errors\n";
    
    Logger::info("Bitrix sync completed. Processed: $processed, Updated: $updated, Errors: $errors");
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    Logger::error("Critical error in Bitrix sync: " . $e->getMessage());
    exit(1);
}
