<?php

/**
 * Расширение Logger для интеграции с ErrorTracker
 * 
 * Добавляет функциональность отслеживания ошибок синхронизации
 * с автоматической дедупликацией и категоризацией
 */

require_once 'Logger.php';
require_once 'ErrorTracker.php';

class EnhancedLogger extends Logger
{
    private static $errorTrackers = [];
    
    /**
     * Логировать ошибку с автоматическим отслеживанием
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     * @param string $entityType Тип сущности
     * @param string $entityId ID сущности
     * @param bool $trackError Отслеживать ли ошибку в базе данных
     */
    public static function log($level, $message, $context = [], $entityType = null, $entityId = null, $trackError = false)
    {
        // Вызываем родительский метод
        parent::log($level, $message, $context, $entityType, $entityId);
        
        // Отслеживаем ошибки в базе данных
        if ($trackError && in_array($level, [self::LEVEL_ERROR, self::LEVEL_WARNING]) && $entityType && $entityId) {
            self::trackErrorInDatabase($level, $message, $context, $entityType, $entityId);
        }
    }
    
    /**
     * Специализированный метод для логирования ошибок синхронизации
     * 
     * @param string $entityType Тип сущности
     * @param string $entityId ID сущности
     * @param string $message Сообщение об ошибке
     * @param array $context Контекст ошибки
     * @param string $level Уровень логирования (по умолчанию ERROR)
     */
    public static function logSyncError($entityType, $entityId, $message, $context = [], $level = self::LEVEL_ERROR)
    {
        // Логируем в файл
        self::log($level, $message, $context, $entityType, $entityId);
        
        // Отслеживаем в базе данных
        self::trackErrorInDatabase($level, $message, $context, $entityType, $entityId);
    }
    
    /**
     * Логировать ошибку контракта
     * 
     * @param string $contractId ID контракта
     * @param string $message Сообщение об ошибке
     * @param array $context Контекст
     */
    public static function logContractError($contractId, $message, $context = [])
    {
        self::logSyncError('contract', $contractId, $message, $context);
    }
    
    /**
     * Логировать ошибку апартамента
     * 
     * @param string $apartmentId ID апартамента
     * @param string $message Сообщение об ошибке
     * @param array $context Контекст
     */
    public static function logApartmentError($apartmentId, $message, $context = [])
    {
        self::logSyncError('apartment', $apartmentId, $message, $context);
    }
    
    /**
     * Логировать ошибку арендатора
     * 
     * @param string $tenantId ID арендатора
     * @param string $message Сообщение об ошибке
     * @param array $context Контекст
     */
    public static function logTenantError($tenantId, $message, $context = [])
    {
        self::logSyncError('tenant', $tenantId, $message, $context);
    }
    
    /**
     * Получить статистику ошибок за период
     * 
     * @param string $startDate Дата начала (Y-m-d)
     * @param string $endDate Дата окончания (Y-m-d)
     * @param string $entityType Тип сущности (опционально)
     * @return array
     */
    public static function getErrorStats($startDate = null, $endDate = null, $entityType = null)
    {
        try {
            $errorTracker = ErrorTracker::getInstance();
            
            $filters = [];
            if ($startDate) {
                $filters['date_from'] = $startDate . ' 00:00:00';
            }
            if ($endDate) {
                $filters['date_to'] = $endDate . ' 23:59:59';
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            
            return $errorTracker->getErrorStats($filters);
        } catch (Exception $e) {
            // Fallback к файловому логу
            return self::getFileBasedStats($startDate, $endDate, $entityType);
        }
    }
    
    /**
     * Получить ошибки за период
     * 
     * @param string $startDate Дата начала (Y-m-d)
     * @param string $endDate Дата окончания (Y-m-d)
     * @param string $entityType Тип сущности (опционально)
     * @param int $limit Лимит записей
     * @return array
     */
    public static function getErrors($startDate = null, $endDate = null, $entityType = null, $limit = 100)
    {
        try {
            $errorTracker = ErrorTracker::getInstance();
            
            $filters = [];
            if ($startDate) {
                $filters['date_from'] = $startDate . ' 00:00:00';
            }
            if ($endDate) {
                $filters['date_to'] = $endDate . ' 23:59:59';
            }
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            
            return $errorTracker->getErrors($filters, $limit);
        } catch (Exception $e) {
            // Fallback к файловому логу
            return self::getFileBasedErrors($startDate, $endDate, $entityType, $limit);
        }
    }
    
    /**
     * Отметить ошибку как решенную
     * 
     * @param int $errorId ID ошибки
     * @param string $resolutionNotes Примечания к решению
     * @return bool
     */
    public static function resolveError($errorId, $resolutionNotes = '')
    {
        try {
            $errorTracker = ErrorTracker::getInstance();
            return $errorTracker->resolveError($errorId, $resolutionNotes);
        } catch (Exception $e) {
            self::error("Failed to resolve error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получить топ ошибок по количеству повторений
     * 
     * @param int $limit Количество записей
     * @param string $entityType Тип сущности (опционально)
     * @return array
     */
    public static function getTopErrors($limit = 10, $entityType = null)
    {
        try {
            $errorTracker = ErrorTracker::getInstance();
            
            $filters = ['min_occurrences' => 2]; // Только повторяющиеся ошибки
            if ($entityType) {
                $filters['entity_type'] = $entityType;
            }
            
            return $errorTracker->getErrors($filters, $limit);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Очистить старые решенные ошибки
     * 
     * @param int $daysToKeep Количество дней для хранения
     * @return int Количество удаленных записей
     */
    public static function cleanOldErrors($daysToKeep = 30)
    {
        try {
            $errorTracker = ErrorTracker::getInstance();
            return $errorTracker->cleanOldResolvedErrors($daysToKeep);
        } catch (Exception $e) {
            self::error("Failed to clean old errors: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Отслеживать ошибку в базе данных
     */
    private static function trackErrorInDatabase($level, $message, $context, $entityType, $entityId)
    {
        try {
            $tracker = ErrorTrackerFactory::create($entityType);
            $result = $tracker->track($entityId, $message, $context);
            
            // Логируем информацию о трекинге только для новых ошибок
            if ($result['is_new']) {
                self::info("New error tracked in database", [
                    'error_id' => $result['error_id'],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'hash' => $result['hash']
                ], $entityType, $entityId);
            }
            
        } catch (Exception $e) {
            // Не логируем ошибки трекинга, чтобы избежать рекурсии
            error_log("ErrorTracker failed: " . $e->getMessage());
        }
    }
    
    /**
     * Fallback методы для работы с файловыми логами
     */
    private static function getFileBasedStats($startDate, $endDate, $entityType)
    {
        $logs = self::getLogs($startDate, $endDate, self::LEVEL_ERROR, $entityType);
        
        $stats = [
            'total_errors' => count($logs),
            'entity_types_count' => count(array_unique(array_column($logs, 'entity_type'))),
            'unique_entities' => count(array_unique(array_column($logs, 'entity_id'))),
            'total_occurrences' => count($logs),
            'avg_occurrences_per_error' => 1
        ];
        
        return ['general' => $stats, 'by_entity_type' => [], 'by_category' => []];
    }
    
    private static function getFileBasedErrors($startDate, $endDate, $entityType, $limit)
    {
        $logs = self::getLogs($startDate, $endDate, self::LEVEL_ERROR, $entityType);
        
        // Ограничиваем количество записей
        $logs = array_slice($logs, 0, $limit);
        
        // Преобразуем в формат ErrorTracker
        $errors = [];
        foreach ($logs as $log) {
            $errors[] = [
                'id' => null,
                'entity_type' => $log['entity_type'] ?? 'unknown',
                'entity_id' => $log['entity_id'] ?? 'unknown',
                'error_message' => $log['message'] ?? '',
                'error_category' => 'system_error',
                'error_details' => $log['context'] ?? [],
                'first_occurrence' => $log['timestamp'] ?? '',
                'last_occurrence' => $log['timestamp'] ?? '',
                'occurrence_count' => 1,
                'status' => 'active',
                'extensions' => []
            ];
        }
        
        return $errors;
    }
}

/**
 * Утилиты для работы с ошибками синхронизации
 */
class SyncErrorUtils
{
    /**
     * Анализировать ошибки за период и выдать рекомендации
     * 
     * @param string $startDate Дата начала
     * @param string $endDate Дата окончания
     * @return array
     */
    public static function analyzeErrors($startDate, $endDate)
    {
        $stats = EnhancedLogger::getErrorStats($startDate, $endDate);
        $topErrors = EnhancedLogger::getTopErrors(20);
        
        $analysis = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'summary' => $stats['general'],
            'recommendations' => [],
            'critical_issues' => [],
            'top_errors' => $topErrors
        ];
        
        // Анализируем статистику и выдаем рекомендации
        if ($stats['general']['total_errors'] > 100) {
            $analysis['recommendations'][] = [
                'type' => 'high_error_rate',
                'message' => 'Высокий уровень ошибок. Рекомендуется проверить стабильность интеграции.',
                'priority' => 'high'
            ];
        }
        
        // Анализируем топ ошибки
        foreach ($topErrors as $error) {
            if ($error['occurrence_count'] > 10) {
                $analysis['critical_issues'][] = [
                    'entity_type' => $error['entity_type'],
                    'entity_id' => $error['entity_id'],
                    'error_message' => $error['error_message'],
                    'occurrence_count' => $error['occurrence_count'],
                    'recommendation' => self::getRecommendationForError($error)
                ];
            }
        }
        
        return $analysis;
    }
    
    /**
     * Получить рекомендацию для конкретной ошибки
     */
    private static function getRecommendationForError($error)
    {
        $message = $error['error_message'];
        
        if (strpos($message, 'forbidden to edit the archive usage') !== false) {
            return 'Контракт имеет архивное использование. Создайте новый контракт вместо обновления существующего.';
        }
        
        if (strpos($message, 'Failed to get apartment data from Bitrix24') !== false) {
            return 'Проблема с получением данных апартамента из Bitrix24. Проверьте права доступа и структуру данных.';
        }
        
        if (strpos($message, 'email already exists') !== false) {
            return 'Дублирование email. Используйте существующего клиента вместо создания нового.';
        }
        
        return 'Требуется ручной анализ ошибки.';
    }
    
    /**
     * Создать отчет по ошибкам
     * 
     * @param string $startDate Дата начала
     * @param string $endDate Дата окончания
     * @return string HTML отчет
     */
    public static function generateErrorReport($startDate, $endDate)
    {
        $analysis = self::analyzeErrors($startDate, $endDate);
        
        $html = "<!DOCTYPE html>
        <html>
        <head>
            <title>Отчет по ошибкам синхронизации</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .summary { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .recommendation { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 10px 0; }
                .critical { background: #f8d7da; padding: 10px; border-left: 4px solid #dc3545; margin: 10px 0; }
                .error-item { background: #e9ecef; padding: 10px; margin: 5px 0; border-radius: 3px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Отчет по ошибкам синхронизации</h1>
            <p><strong>Период:</strong> {$startDate} - {$endDate}</p>
            
            <div class='summary'>
                <h2>Общая статистика</h2>
                <p><strong>Всего ошибок:</strong> {$analysis['summary']['total_errors']}</p>
                <p><strong>Типов сущностей:</strong> {$analysis['summary']['entity_types_count']}</p>
                <p><strong>Уникальных сущностей:</strong> {$analysis['summary']['unique_entities']}</p>
                <p><strong>Общее количество повторений:</strong> {$analysis['summary']['total_occurrences']}</p>
            </div>";
        
        if (!empty($analysis['recommendations'])) {
            $html .= "<h2>Рекомендации</h2>";
            foreach ($analysis['recommendations'] as $rec) {
                $html .= "<div class='recommendation'><strong>{$rec['type']}:</strong> {$rec['message']}</div>";
            }
        }
        
        if (!empty($analysis['critical_issues'])) {
            $html .= "<h2>Критические проблемы</h2>";
            foreach ($analysis['critical_issues'] as $issue) {
                $html .= "<div class='critical'>
                    <strong>{$issue['entity_type']} #{$issue['entity_id']}</strong><br>
                    <strong>Ошибка:</strong> {$issue['error_message']}<br>
                    <strong>Повторений:</strong> {$issue['occurrence_count']}<br>
                    <strong>Рекомендация:</strong> {$issue['recommendation']}
                </div>";
            }
        }
        
        $html .= "<h2>Топ ошибок</h2>
            <table>
                <tr>
                    <th>Тип сущности</th>
                    <th>ID сущности</th>
                    <th>Сообщение об ошибке</th>
                    <th>Количество повторений</th>
                    <th>Первое появление</th>
                    <th>Последнее появление</th>
                </tr>";
        
        foreach ($analysis['top_errors'] as $error) {
            $html .= "<tr>
                <td>{$error['entity_type']}</td>
                <td>{$error['entity_id']}</td>
                <td>" . htmlspecialchars($error['error_message']) . "</td>
                <td>{$error['occurrence_count']}</td>
                <td>{$error['first_occurrence']}</td>
                <td>{$error['last_occurrence']}</td>
            </tr>";
        }
        
        $html .= "</table></body></html>";
        
        return $html;
    }
}
