<?php
/**
 * Автоматический фикс контрактов начиная с определенного ID
 * 
 * Использование:
 * php auto_fix_contracts.php [start_id] [limit]
 * 
 * Примеры:
 * php auto_fix_contracts.php 5966 50    # Начать с 5966, исправить 50 контрактов
 * php auto_fix_contracts.php 5900       # Начать с 5900, исправить до конца
 */

require_once('validate_sync.php');

class AutoFixContracts {
    private $validator;
    private $fixed = 0;
    private $skipped = 0;
    private $errors = 0;
    
    public function __construct() {
        $this->validator = new SyncValidator();
    }
    
    /**
     * Автоматически исправить контракты
     */
    public function autoFix($startId, $limit = null) {
        echo "=== АВТОМАТИЧЕСКИЙ ФИКС КОНТРАКТОВ ===\n";
        echo "Начинаем с ID: $startId\n";
        echo "Шаг: 2 (проверяем каждый второй контракт)\n";
        echo "Лимит: " . ($limit ?: 'без ограничений') . "\n\n";
        
        $currentId = $startId;
        $processed = 0;
        
        while (true) {
            if ($limit && $processed >= $limit) {
                echo "\n🏁 Достигнут лимит обработки ($limit контрактов)\n";
                break;
            }
            
            echo "--- Контракт $currentId ---\n";
            
            // 1. Сначала проверяем контракт
            $validation = $this->validator->validateContract($currentId);
            
            if (!$validation['success']) {
                $error = $validation['error'];
                
                // Пропускаем определенные типы ошибок
                if (strpos($error, 'не найден в Bitrix24') !== false ||
                    strpos($error, 'Не указан unit_external_id') !== false) {
                    echo "⏭️  Пропускаем: $error\n";
                    $this->skipped++;
                    $currentId--;
                    $processed++;
                    continue;
                }
                
                // Для ошибок "Объект не найден в Alma" пытаемся исправить
                if (strpos($error, 'Объект не найден в Alma') !== false) {
                    echo "🔍 Пытаемся исправить: $error\n";
                    
                    // Проверяем, есть ли сообщение об архивированном объекте
                    if (strpos($error, 'возможно архивирован') !== false) {
                        echo "📦 ОБЪЕКТ В АРХИВЕ - требуется ручное открытие!\n";
                        echo "   Контракт: $currentId\n";
                        echo "   Ошибка: $error\n";
                        echo "   Действие: Откройте объект вручную в Alma, затем повторите фикс\n\n";
                        $this->skipped++;
                    } else {
                        $fixResult = $this->validator->fixContractMapping($currentId);
                        
                        if ($fixResult) {
                            echo "✅ Автофикс успешен!\n";
                            $this->fixed++;
                        } else {
                            echo "❌ Автофикс не удался\n";
                            $this->errors++;
                        }
                    }
                } else {
                    echo "⚠️  Неизвестная ошибка: $error\n";
                    $this->errors++;
                }
            } else {
                // Контракт найден, проверяем проблемы
                $issues = [];
                $validation_data = $validation['validation'];
                
                if (!$validation_data['external_id_match']) {
                    $issues[] = "external_id не совпадает";
                }
                if (!$validation_data['additional_external_id_match']) {
                    $issues[] = "additional_external_id не совпадает";
                }
                if (!$validation_data['is_used_additional_external_id_correct']) {
                    $issues[] = "is_used_additional_external_id неправильно";
                }
                
                if (empty($issues)) {
                    echo "✅ Контракт уже корректен\n";
                    $this->skipped++;
                } else {
                    echo "🔧 Проблемы: " . implode(', ', $issues) . "\n";
                    echo "🔄 Пытаемся исправить...\n";
                    
                    $fixResult = $this->validator->fixContractMapping($currentId);
                    
                    if ($fixResult) {
                        echo "✅ Автофикс успешен!\n";
                        $this->fixed++;
                    } else {
                        echo "❌ Автофикс не удался\n";
                        $this->errors++;
                    }
                }
            }
            
            $currentId -= 2; // Шаг в 2
            $processed++;
            
            // Пауза между запросами
            sleep(1);
            
            echo "\n";
        }
        
        $this->printSummary();
    }
    
    /**
     * Вывести итоговую статистику
     */
    private function printSummary() {
        echo "=== ИТОГИ ===\n";
        echo "✅ Исправлено: {$this->fixed}\n";
        echo "⏭️  Пропущено: {$this->skipped}\n";
        echo "❌ Ошибок: {$this->errors}\n";
        echo "📊 Всего обработано: " . ($this->fixed + $this->skipped + $this->errors) . "\n";
    }
}

// Получаем параметры из командной строки
$startId = isset($argv[1]) ? (int)$argv[1] : 5966;
$limit = isset($argv[2]) ? (int)$argv[2] : null;

// Запускаем автофикс
$autoFix = new AutoFixContracts();
$autoFix->autoFix($startId, $limit);
