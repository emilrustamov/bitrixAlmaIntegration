<?php
/**
 * Массовое исправление is_used_additional_external_id для всех объектов
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

class MassRentTypeFixer {
    private $bitrix;
    private $almaApiKey;
    private $almaApiUrl;
    private $fieldMapping;
    private $fixedCount = 0;
    private $errorCount = 0;
    private $checkedCount = 0;
    private $skippedCount = 0;
    private $errors = [];

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
            return null;
        }
    }

    /**
     * Получить все объекты с external_id из Alma
     */
    private function getAllAlmaObjectsWithExternalId() {
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
        if (!$data) {
            return [];
        }

        // Фильтруем только объекты с external_id
        return array_filter($data, function($object) {
            return !empty($object['external_id']);
        });
    }

    /**
     * Получить полную информацию об объекте из Alma
     */
    private function getAlmaObjectDetails($almaId) {
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
            return null;
        }

        return json_decode($response, true);
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
        $name = $almaObject['name'] ?? 'Unknown';

        $this->checkedCount++;

        // Получаем полную информацию об объекте
        $almaObjectFull = $this->getAlmaObjectDetails($almaId);
        if (!$almaObjectFull) {
            $this->errors[] = "Объект $almaId не найден в Alma";
            $this->errorCount++;
            return;
        }

        $currentIsUsed = $almaObjectFull['is_used_additional_external_id'] ?? false;

        // Получаем rent_type из Bitrix24
        $bitrixData = $this->getBitrixRentType($externalId);
        if (!$bitrixData) {
            // Пропускаем тестовые объекты и объекты, которых нет в Bitrix24
            $this->skippedCount++;
            return;
        }

        // Определяем правильное значение is_used_additional_external_id
        $correctIsUsed = ($bitrixData['rent_type'] === 'rooms'); // rooms = шеринговый
        
        if ($currentIsUsed === $correctIsUsed) {
            // Уже правильно
            return;
        }

        echo "🔧 Исправляем объект $almaId ($name):\n";
        echo "   External ID: $externalId\n";
        echo "   Bitrix rent_type: {$bitrixData['rent_type']} ({$bitrixData['rent_type_value']})\n";
        echo "   Было: " . ($currentIsUsed ? 'true' : 'false') . " → Стало: " . ($correctIsUsed ? 'true' : 'false') . "\n";
        
        $result = $this->updateAlmaObject($almaId, $correctIsUsed);
        if ($result['success']) {
            echo "   ✅ Исправлено\n";
            $this->fixedCount++;
        } else {
            $errorMsg = "HTTP {$result['http_code']} - {$result['response']}";
            echo "   ❌ Ошибка: $errorMsg\n";
            $this->errors[] = "Объект $almaId: $errorMsg";
            $this->errorCount++;
        }
        echo "\n";
    }

    /**
     * Массовая проверка и исправление
     */
    public function massFix($limit = null) {
        echo "🚀 Начинаем массовое исправление is_used_additional_external_id...\n\n";

        $almaObjects = $this->getAllAlmaObjectsWithExternalId();
        if (empty($almaObjects)) {
            echo "❌ Не удалось получить объекты из Alma\n";
            return;
        }

        if ($limit) {
            $almaObjects = array_slice($almaObjects, 0, $limit);
            echo "📊 Проверяем первые $limit объектов из " . count($this->getAllAlmaObjectsWithExternalId()) . " с external_id\n\n";
        } else {
            echo "📊 Найдено " . count($almaObjects) . " объектов с external_id в Alma\n\n";
        }

        foreach ($almaObjects as $object) {
            $this->checkAndFixObject($object);
            
            // Пауза между запросами
            usleep(100000); // 0.1 секунды
        }

        echo "📈 Итоговая статистика:\n";
        echo "   Проверено: $this->checkedCount\n";
        echo "   Исправлено: $this->fixedCount\n";
        echo "   Пропущено: $this->skippedCount\n";
        echo "   Ошибок: $this->errorCount\n";

        if (!empty($this->errors)) {
            echo "\n❌ Ошибки:\n";
            foreach ($this->errors as $error) {
                echo "   - $error\n";
            }
        }
    }
}

// Обработка запросов
if (isset($_GET['limit'])) {
    $fixer = new MassRentTypeFixer();
    $limit = (int)$_GET['limit'];
    $fixer->massFix($limit);
} else {
    echo "Использование:\n";
    echo "?limit=50 - исправить первые 50 объектов\n";
    echo "?limit=100 - исправить первые 100 объектов\n";
    echo "?limit=0 - исправить все объекты (осторожно!)\n";
}
?>
