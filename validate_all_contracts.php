<?php
/**
 * Скрипт для получения всех контрактов из Bitrix24 и их проверки через валидатор
 */

require_once('Bitrix24Rest.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

// Получаем проект из параметра или определяем автоматически
$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);

define('WEBHOOK_URL', $projectConfig['webhook_url']);

class ContractValidator {
    private $bitrix;
    private $validatorUrl;

    public function __construct() {
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->validatorUrl = 'https://alma.colifeb24apps.ru/validate_sync.php';
    }

    /**
     * Получить все контракты из Bitrix24
     */
    public function getAllContracts($limit = null) {
        echo "🔍 Получаем список контрактов из Bitrix24...\n";
        
        $contracts = [];
        $start = 0;
        $batchSize = 50; // Bitrix24 рекомендует не более 50 элементов за раз
        
        while (true) {
            $response = $this->bitrix->call('crm.item.list', [
                'entityTypeId' => 183, // Контракты
                'select' => ['id', 'title', 'ufCrm20_1693919019'], // Только нужные поля
                'start' => $start,
                'order' => ['id' => 'DESC'] // От новых к старым
            ]);

            if (!isset($response['result']['items']) || empty($response['result']['items'])) {
                break;
            }

            foreach ($response['result']['items'] as $contract) {
                $contracts[] = [
                    'id' => $contract['id'],
                    'title' => $contract['title'],
                    'unit_external_id' => $contract['ufCrm20_1693919019'] ?? ''
                ];
                
                // Ограничиваем количество, если указан лимит
                if ($limit && count($contracts) >= $limit) {
                    break 2;
                }
            }

            $start += $batchSize;
            
            // Если получили меньше элементов, чем размер батча, значит это последняя страница
            if (count($response['result']['items']) < $batchSize) {
                break;
            }
            
            // Пауза между запросами
            usleep(100000); // 0.1 секунды
        }

        echo "📊 Найдено " . count($contracts) . " контрактов в Bitrix24\n\n";
        return $contracts;
    }

    /**
     * Проверить контракт через валидатор
     */
    public function validateContract($contractId) {
        $url = $this->validatorUrl . "?id=" . urlencode($contractId);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP $httpCode: $response"
            ];
        }

        // Извлекаем JSON из ответа (убираем echo сообщения)
        $jsonStart = strpos($response, '{');
        if ($jsonStart === false) {
            return [
                'success' => false,
                'error' => "No JSON found in response: " . substr($response, 0, 200)
            ];
        }
        
        $jsonResponse = substr($response, $jsonStart);
        $decoded = json_decode($jsonResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => "JSON decode error: " . json_last_error_msg() . " Response: " . substr($jsonResponse, 0, 200)
            ];
        }

        return $decoded;
    }

    /**
     * Проверить все контракты
     */
    public function validateAllContracts($limit = null) {
        echo "🚀 Начинаем проверку всех контрактов...\n\n";

        $contracts = $this->getAllContracts($limit);
        
        if (empty($contracts)) {
            echo "❌ Контракты не найдены\n";
            return;
        }

        $stats = [
            'total' => count($contracts),
            'success' => 0,
            'errors' => 0,
            'title_mismatch' => 0,
            'external_id_mismatch' => 0,
            'additional_external_id_mismatch' => 0,
            'is_used_additional_external_id_incorrect' => 0,
            'not_found' => 0,
            'bitrix_errors' => 0
        ];

        $errors = [];
        $warnings = [];

        foreach ($contracts as $contract) {
            $contractId = $contract['id'];
            echo "🔍 Проверяем контракт $contractId: {$contract['title']}\n";

            $result = $this->validateContract($contractId);
            
            if (!$result['success']) {
                $stats['errors']++;
                $stats['not_found']++;
                $errors[] = [
                    'contract_id' => $contractId,
                    'title' => $contract['title'],
                    'error' => $result['error']
                ];
                echo "  ❌ {$result['error']}\n";
            } else {
                $stats['success']++;
                $validation = $result['validation'];
                
                $issues = [];
                if (!$validation['title_match']) {
                    $issues[] = "названия не совпадают";
                    $stats['title_mismatch']++;
                }
                if (!$validation['external_id_match']) {
                    $issues[] = "external_id не совпадает";
                    $stats['external_id_mismatch']++;
                }
                if (!$validation['additional_external_id_match']) {
                    $issues[] = "additional_external_id не совпадает";
                    $stats['additional_external_id_mismatch']++;
                }
                if (!$validation['is_used_additional_external_id_correct']) {
                    $issues[] = "is_used_additional_external_id неправильно";
                    $stats['is_used_additional_external_id_incorrect']++;
                }

                if (empty($issues)) {
                    echo "  ✅ OK\n";
                } else {
                    echo "  ⚠️  " . implode(', ', $issues) . "\n";
                    $warnings[] = [
                        'contract_id' => $contractId,
                        'title' => $contract['title'],
                        'issues' => $issues,
                        'bitrix' => $result['bitrix'],
                        'alma' => $result['alma']
                    ];
                }
            }

            // Пауза между запросами
            usleep(100000); // 0.1 секунды
        }

        // Выводим статистику
        echo "\n📈 Итоговая статистика:\n";
        echo "   Всего контрактов: {$stats['total']}\n";
        echo "   Успешно проверено: {$stats['success']}\n";
        echo "   Ошибок: {$stats['errors']}\n";
        echo "   Названия не совпадают: {$stats['title_mismatch']}\n";
        echo "   External ID не совпадает: {$stats['external_id_mismatch']}\n";
        echo "   Additional External ID не совпадает: {$stats['additional_external_id_mismatch']}\n";
        echo "   is_used_additional_external_id неправильно: {$stats['is_used_additional_external_id_incorrect']}\n";

        // Выводим ошибки
        if (!empty($errors)) {
            echo "\n❌ Ошибки (первые 10):\n";
            foreach (array_slice($errors, 0, 10) as $error) {
                echo "   - Контракт {$error['contract_id']}: {$error['title']} - {$error['error']}\n";
            }
            if (count($errors) > 10) {
                echo "   ... и еще " . (count($errors) - 10) . " ошибок\n";
            }
        }

        // Выводим предупреждения
        if (!empty($warnings)) {
            echo "\n⚠️  Предупреждения (первые 10):\n";
            foreach (array_slice($warnings, 0, 10) as $warning) {
                echo "   - Контракт {$warning['contract_id']}: {$warning['title']}\n";
                echo "     Проблемы: " . implode(', ', $warning['issues']) . "\n";
                if (isset($warning['bitrix']['rent_type'])) {
                    echo "     Bitrix rent_type: {$warning['bitrix']['rent_type']} ({$warning['bitrix']['rent_type_value']})\n";
                }
                echo "     Alma is_used_additional_external_id: " . ($warning['alma']['is_used_additional_external_id'] ? 'true' : 'false') . "\n";
            }
            if (count($warnings) > 10) {
                echo "   ... и еще " . (count($warnings) - 10) . " предупреждений\n";
            }
        }

        return [
            'stats' => $stats,
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}

// Запускаем проверку
$validator = new ContractValidator();
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;

if ($limit) {
    echo "🔢 Ограничение: проверяем первые $limit контрактов\n\n";
}

$result = $validator->validateAllContracts($limit);
?>
