<?php
/**
 * Проверка и исправление is_used_additional_external_id для всех объектов
 * на основе rent_type из Bitrix24
 */

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

// Получаем проект из параметра или определяем автоматически
$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping = ProjectMapping::getFieldMapping($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);

class RentTypeChecker {
    private $bitrix;
    private $almaApiKey;
    private $almaApiUrl;
    private $fieldMapping;
    private $fixedCount = 0;
    private $errorCount = 0;
    private $checkedCount = 0;

    public function __construct() {
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->almaApiKey = ALMA_API_KEY;
        $this->almaApiUrl = ALMA_API_URL;
        $this->fieldMapping = ProjectMapping::getFieldMapping('Dubai');
    }

    /**
     * Получить rent_type из Bitrix24 для апартамента
     */
    private function getBitrixRentType($apartmentId) {
        try {
            $response = $this->bitrix->call('crm.item.get', [
                'entityTypeId' => $this->fieldMapping['entity_type_id'], // 144 для апартаментов
                'id' => $apartmentId,
            ]);

            if (!isset($response['result']['item'])) {
                return null;
            }

            $apartment = $response['result']['item'];
            $rentTypeField = $this->fieldMapping['fields']['rent_type'];
            $rentTypeValue = $apartment[$rentTypeField] ?? '4598'; // По умолчанию unit
            $rentTypeMapping = $this->fieldMapping['rent_type_mapping'];
            $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
            
            return [
                'rent_type' => $rentType,
                'rent_type_value' => $rentTypeValue,
                'title' => $apartment['title'] ?? 'Unknown'
            ];
        } catch (Exception $e) {
            echo "❌ Ошибка получения данных апартамента $apartmentId: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Получить все объекты из Alma
     */
    private function getAllAlmaObjects() {
        $url = rtrim($this->almaApiUrl, '/') . "/realty/units/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "❌ Ошибка получения объектов из Alma: HTTP $httpCode\n";
            return [];
        }

        $data = json_decode($response, true);
        return $data ?? [];
    }

    /**
     * Обновить is_used_additional_external_id в Alma
     */
    private function updateAlmaObject($almaId, $isUsedAdditionalExternalId) {
        $url = rtrim($this->almaApiUrl, '/') . "/realty/units/" . $almaId . "/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['is_used_additional_external_id' => $isUsedAdditionalExternalId]),
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    /**
     * Проверить и исправить is_used_additional_external_id для объекта
     */
    public function checkAndFixObject($almaObject) {
        $almaId = $almaObject['id'];
        $externalId = $almaObject['external_id'];
        $currentIsUsed = $almaObject['is_used_additional_external_id'] ?? false;
        $name = $almaObject['name'] ?? 'Unknown';

        $this->checkedCount++;

        // Пропускаем объекты без external_id
        if (empty($externalId)) {
            echo "⏭️  Пропускаем объект $almaId: нет external_id\n";
            return;
        }

        // Получаем rent_type из Bitrix24
        $bitrixData = $this->getBitrixRentType($externalId);
        if (!$bitrixData) {
            echo "❌ Не удалось получить данные из Bitrix24 для external_id: $externalId\n";
            $this->errorCount++;
            return;
        }

        // Определяем правильное значение is_used_additional_external_id
        $correctIsUsed = ($bitrixData['rent_type'] === 'rooms'); // rooms = шеринговый
        
        echo "🔍 Объект $almaId ($name):\n";
        echo "   External ID: $externalId\n";
        echo "   Bitrix rent_type: {$bitrixData['rent_type']} ({$bitrixData['rent_type_value']})\n";
        echo "   Текущее is_used: " . ($currentIsUsed ? 'true' : 'false') . "\n";
        echo "   Правильное is_used: " . ($correctIsUsed ? 'true' : 'false') . "\n";

        if ($currentIsUsed === $correctIsUsed) {
            echo "   ✅ Уже правильно\n";
        } else {
            echo "   🔄 Исправляем...\n";
            
            $result = $this->updateAlmaObject($almaId, $correctIsUsed);
            if ($result['success']) {
                echo "   ✅ Исправлено\n";
                $this->fixedCount++;
            } else {
                echo "   ❌ Ошибка исправления: HTTP {$result['http_code']} - {$result['response']}\n";
                $this->errorCount++;
            }
        }
        echo "\n";
    }

    /**
     * Проверить все объекты
     */
    public function checkAllObjects($limit = null) {
        echo "🚀 Начинаем проверку is_used_additional_external_id для всех объектов...\n\n";

        $almaObjects = $this->getAllAlmaObjects();
        if (empty($almaObjects)) {
            echo "❌ Не удалось получить объекты из Alma\n";
            return;
        }

        if ($limit) {
            $almaObjects = array_slice($almaObjects, 0, $limit);
            echo "📊 Проверяем первые $limit объектов из " . count($this->getAllAlmaObjects()) . " в Alma\n\n";
        } else {
            echo "📊 Найдено " . count($almaObjects) . " объектов в Alma\n\n";
        }

        foreach ($almaObjects as $object) {
            $this->checkAndFixObject($object);
            
            // Пауза между запросами
            usleep(100000); // 0.1 секунды
        }

        echo "📈 Статистика:\n";
        echo "   Проверено: $this->checkedCount\n";
        echo "   Исправлено: $this->fixedCount\n";
        echo "   Ошибок: $this->errorCount\n";
    }

    /**
     * Проверить конкретный объект
     */
    public function checkSpecificObject($almaId) {
        echo "🔍 Проверяем объект $almaId...\n\n";

        $url = rtrim($this->almaApiUrl, '/') . "/realty/units/" . $almaId . "/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "❌ Объект $almaId не найден в Alma\n";
            return;
        }

        $object = json_decode($response, true);
        $this->checkAndFixObject($object);
    }
}

// Обработка запросов
if (isset($_GET['id'])) {
    $checker = new RentTypeChecker();
    $checker->checkSpecificObject($_GET['id']);
} elseif (isset($_GET['all'])) {
    $checker = new RentTypeChecker();
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
    $checker->checkAllObjects($limit);
} else {
    echo "Использование:\n";
    echo "?id=1234 - проверить конкретный объект\n";
    echo "?all=1 - проверить все объекты\n";
    echo "?all=1&limit=50 - проверить первые 50 объектов\n";
}
?>
