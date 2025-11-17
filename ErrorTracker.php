<?php

/**
 * Универсальная система отслеживания ошибок синхронизации
 * 
 * Особенности:
 * - Полиморфная архитектура для разных типов сущностей
 * - Дедупликация ошибок (одна ошибка = одна запись)
 * - Автоматическое обновление счетчиков повторений
 * - Гибкая система категоризации ошибок
 * - Возможность расширения для новых типов сущностей
 */

require_once 'DatabaseConfig.php';

class ErrorTracker
{
    private $pdo;
    private static $instance = null;
    
    // Константы для типов сущностей
    const ENTITY_CONTRACT = 'contract';
    const ENTITY_APARTMENT = 'apartment';
    const ENTITY_TENANT = 'tenant';
    const ENTITY_LANDLORD = 'landlord';
    const ENTITY_LANDLORD_CONTRACT = 'landlord_contract';
    const ENTITY_WEBHOOK = 'webhook';
    
    // Константы для категорий ошибок
    const CATEGORY_API_ERROR = 'api_error';
    const CATEGORY_VALIDATION_ERROR = 'validation_error';
    const CATEGORY_DATA_ERROR = 'data_error';
    const CATEGORY_SYSTEM_ERROR = 'system_error';
    const CATEGORY_PERMISSION_ERROR = 'permission_error';
    
    // Константы для статусов ошибок
    const STATUS_ACTIVE = 'active';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_IGNORED = 'ignored';
    
    private function __construct()
    {
        $this->initializeDatabase();
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initializeDatabase()
    {
        try {
            $this->pdo = new PDO(
                DatabaseConfig::getDSN(),
                DatabaseConfig::getUser(),
                DatabaseConfig::getPass(),
                DatabaseConfig::getOptions()
            );
            
            $this->createTables();
        } catch (PDOException $e) {
            Logger::error("Failed to initialize ErrorTracker database: " . $e->getMessage());
            throw new RuntimeException("Database initialization failed");
        }
    }
    
    private function createTables()
    {
        // Основная таблица ошибок
        $sql = "
            CREATE TABLE IF NOT EXISTS sync_errors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(50) NOT NULL,
                entity_id VARCHAR(100) NOT NULL,
                error_hash VARCHAR(64) NOT NULL UNIQUE,
                error_message TEXT NOT NULL,
                error_category VARCHAR(50) NOT NULL,
                error_details TEXT,
                first_occurrence DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_occurrence DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                occurrence_count INT NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                resolution_notes TEXT,
                resolved_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->pdo->exec($sql);
        
        // Индексы для быстрого поиска
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_entity ON sync_errors(entity_type, entity_id)",
            "CREATE INDEX IF NOT EXISTS idx_error_hash ON sync_errors(error_hash)",
            "CREATE INDEX IF NOT EXISTS idx_status ON sync_errors(status)",
            "CREATE INDEX IF NOT EXISTS idx_category ON sync_errors(error_category)",
            "CREATE INDEX IF NOT EXISTS idx_last_occurrence ON sync_errors(last_occurrence)",
            "CREATE INDEX IF NOT EXISTS idx_first_occurrence ON sync_errors(first_occurrence)"
        ];
        
        foreach ($indexes as $indexSql) {
            $this->pdo->exec($indexSql);
        }
        
        // Таблица для расширения - дополнительные поля для разных типов сущностей
        $sql = "
            CREATE TABLE IF NOT EXISTS sync_error_extensions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                error_id INT NOT NULL,
                extension_key VARCHAR(100) NOT NULL,
                extension_value TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (error_id) REFERENCES sync_errors(id) ON DELETE CASCADE,
                UNIQUE KEY unique_error_extension (error_id, extension_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->pdo->exec($sql);
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_extension_key ON sync_error_extensions(extension_key)");
    }
    
    /**
     * Записать или обновить ошибку
     * 
     * @param string $entityType Тип сущности (contract, apartment, etc.)
     * @param string $entityId ID сущности
     * @param string $errorMessage Сообщение об ошибке
     * @param string $category Категория ошибки
     * @param array $details Дополнительные детали
     * @param array $extensions Расширенные поля для конкретного типа сущности
     * @return array Информация о записи ошибки
     */
    public function trackError(
        $entityType,
        $entityId,
        $errorMessage,
        $category = self::CATEGORY_SYSTEM_ERROR,
        $details = [],
        $extensions = []
    ) {
        $errorHash = $this->generateErrorHash($entityType, $entityId, $errorMessage, $category);
        
        try {
            $this->pdo->beginTransaction();
            
            // Проверяем, существует ли уже такая ошибка
            $stmt = $this->pdo->prepare("
                SELECT id, occurrence_count, last_occurrence 
                FROM sync_errors 
                WHERE error_hash = ?
            ");
            $stmt->execute([$errorHash]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Обновляем существующую ошибку
                $newCount = $existing['occurrence_count'] + 1;
                $stmt = $this->pdo->prepare("
                    UPDATE sync_errors 
                    SET occurrence_count = ?, 
                        last_occurrence = CURRENT_TIMESTAMP,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$newCount, $existing['id']]);
                
                $errorId = $existing['id'];
                $isNew = false;
            } else {
                // Создаем новую ошибку
                $stmt = $this->pdo->prepare("
                    INSERT INTO sync_errors 
                    (entity_type, entity_id, error_hash, error_message, error_category, error_details)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $entityType,
                    $entityId,
                    $errorHash,
                    $errorMessage,
                    $category,
                    json_encode($details, JSON_UNESCAPED_UNICODE)
                ]);
                
                $errorId = $this->pdo->lastInsertId();
                $isNew = true;
            }
            
            // Сохраняем расширенные поля
            if (!empty($extensions)) {
                $this->saveExtensions($errorId, $extensions);
            }
            
            $this->pdo->commit();
            
            return [
                'error_id' => $errorId,
                'is_new' => $isNew,
                'hash' => $errorHash,
                'occurrence_count' => $isNew ? 1 : $newCount
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            Logger::error("Failed to track error: " . $e->getMessage(), [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error_message' => $errorMessage
            ]);
            throw new RuntimeException("Error tracking failed");
        }
    }
    
    /**
     * Получить ошибки по критериям
     * 
     * @param array $filters Фильтры поиска
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array
     */
    public function getErrors($filters = [], $limit = 100, $offset = 0)
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['entity_type'])) {
            $where[] = "entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['entity_id'])) {
            $where[] = "entity_id = ?";
            $params[] = $filters['entity_id'];
        }
        
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['category'])) {
            $where[] = "error_category = ?";
            $params[] = $filters['category'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = "first_occurrence >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "first_occurrence <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['min_occurrences'])) {
            $where[] = "occurrence_count >= ?";
            $params[] = $filters['min_occurrences'];
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        $sql = "
            SELECT se.*, 
                   GROUP_CONCAT(CONCAT(see.extension_key, ':', see.extension_value) SEPARATOR '|') as extensions
            FROM sync_errors se
            LEFT JOIN sync_error_extensions see ON se.id = see.error_id
            $whereClause
            GROUP BY se.id
            ORDER BY se.last_occurrence DESC, se.occurrence_count DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $errors = $stmt->fetchAll();
        
        // Парсим расширения
        foreach ($errors as &$error) {
            $error['extensions'] = $this->parseExtensions($error['extensions']);
            $error['error_details'] = json_decode($error['error_details'], true) ?: [];
        }
        
        return $errors;
    }
    
    /**
     * Получить статистику ошибок
     * 
     * @param array $filters Фильтры
     * @return array
     */
    public function getErrorStats($filters = [])
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['date_from'])) {
            $where[] = "first_occurrence >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = "first_occurrence <= ?";
            $params[] = $filters['date_to'];
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        // Общая статистика
        $sql = "
            SELECT 
                COUNT(*) as total_errors,
                COUNT(DISTINCT entity_type) as entity_types_count,
                COUNT(DISTINCT entity_id) as unique_entities,
                SUM(occurrence_count) as total_occurrences,
                AVG(occurrence_count) as avg_occurrences_per_error
            FROM sync_errors
            $whereClause
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $general = $stmt->fetch();
        
        // Статистика по типам сущностей
        $sql = "
            SELECT 
                entity_type,
                COUNT(*) as error_count,
                SUM(occurrence_count) as total_occurrences,
                AVG(occurrence_count) as avg_occurrences
            FROM sync_errors
            $whereClause
            GROUP BY entity_type
            ORDER BY error_count DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $byEntity = $stmt->fetchAll();
        
        // Статистика по категориям
        $sql = "
            SELECT 
                error_category,
                COUNT(*) as error_count,
                SUM(occurrence_count) as total_occurrences
            FROM sync_errors
            $whereClause
            GROUP BY error_category
            ORDER BY error_count DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $byCategory = $stmt->fetchAll();
        
        return [
            'general' => $general,
            'by_entity_type' => $byEntity,
            'by_category' => $byCategory
        ];
    }
    
    /**
     * Отметить ошибку как решенную
     * 
     * @param int $errorId ID ошибки
     * @param string $resolutionNotes Примечания к решению
     * @return bool
     */
    public function resolveError($errorId, $resolutionNotes = '')
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_errors 
            SET status = ?, resolution_notes = ?, resolved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        return $stmt->execute([self::STATUS_RESOLVED, $resolutionNotes, $errorId]);
    }
    
    /**
     * Игнорировать ошибку
     * 
     * @param int $errorId ID ошибки
     * @param string $notes Примечания
     * @return bool
     */
    public function ignoreError($errorId, $notes = '')
    {
        $stmt = $this->pdo->prepare("
            UPDATE sync_errors 
            SET status = ?, resolution_notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        return $stmt->execute([self::STATUS_IGNORED, $notes, $errorId]);
    }
    
    /**
     * Очистить старые решенные ошибки
     * 
     * @param int $daysToKeep Количество дней для хранения
     * @return int Количество удаленных записей
     */
    public function cleanOldResolvedErrors($daysToKeep = 30)
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM sync_errors 
            WHERE status IN (?, ?) 
            AND resolved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([self::STATUS_RESOLVED, self::STATUS_IGNORED, $daysToKeep]);
        return $stmt->rowCount();
    }
    
    private function generateErrorHash($entityType, $entityId, $errorMessage, $category)
    {
        // Создаем хеш на основе ключевых параметров ошибки
        $keyData = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'error_message' => $this->normalizeErrorMessage($errorMessage),
            'category' => $category
        ];
        
        return hash('sha256', json_encode($keyData, JSON_UNESCAPED_UNICODE));
    }
    
    private function normalizeErrorMessage($message)
    {
        // Нормализуем сообщение об ошибке для лучшей группировки
        // Убираем динамические части (ID, даты, etc.)
        $normalized = $message;
        
        // Заменяем числа на плейсхолдеры
        $normalized = preg_replace('/\b\d+\b/', '{ID}', $normalized);
        
        // Заменяем даты на плейсхолдеры
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}/', '{DATE}', $normalized);
        
        // Заменяем временные метки
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '{TIMESTAMP}', $normalized);
        
        return $normalized;
    }
    
    private function saveExtensions($errorId, $extensions)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_error_extensions (error_id, extension_key, extension_value)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE extension_value = VALUES(extension_value)
        ");
        
        foreach ($extensions as $key => $value) {
            $stmt->execute([$errorId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
        }
    }
    
    private function parseExtensions($extensionsString)
    {
        if (empty($extensionsString)) {
            return [];
        }
        
        $extensions = [];
        $pairs = explode('|', $extensionsString);
        
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($key, $value) = explode(':', $pair, 2);
                $extensions[$key] = json_decode($value, true) ?: $value;
            }
        }
        
        return $extensions;
    }
}

/**
 * Специализированные трекеры для разных типов сущностей
 */

abstract class BaseEntityErrorTracker
{
    protected $errorTracker;
    protected $entityType;
    
    public function __construct()
    {
        $this->errorTracker = ErrorTracker::getInstance();
    }
    
    abstract protected function getEntityType();
    
    abstract protected function categorizeError($errorMessage, $context = []);
    
    abstract protected function getExtensions($entityId, $context = []);
    
    public function track($entityId, $errorMessage, $context = [])
    {
        $category = $this->categorizeError($errorMessage, $context);
        $extensions = $this->getExtensions($entityId, $context);
        
        return $this->errorTracker->trackError(
            $this->getEntityType(),
            $entityId,
            $errorMessage,
            $category,
            $context,
            $extensions
        );
    }
}

class ContractErrorTracker extends BaseEntityErrorTracker
{
    protected function getEntityType()
    {
        return ErrorTracker::ENTITY_CONTRACT;
    }
    
    protected function categorizeError($errorMessage, $context = [])
    {
        if (strpos($errorMessage, 'forbidden to edit the archive usage') !== false) {
            return ErrorTracker::CATEGORY_PERMISSION_ERROR;
        }
        
        if (strpos($errorMessage, 'API request failed') !== false) {
            return ErrorTracker::CATEGORY_API_ERROR;
        }
        
        if (strpos($errorMessage, 'Missing required field') !== false) {
            return ErrorTracker::CATEGORY_VALIDATION_ERROR;
        }
        
        if (strpos($errorMessage, 'Invalid date format') !== false) {
            return ErrorTracker::CATEGORY_DATA_ERROR;
        }
        
        return ErrorTracker::CATEGORY_SYSTEM_ERROR;
    }
    
    protected function getExtensions($entityId, $context = [])
    {
        $extensions = [];
        
        if (isset($context['contract_id'])) {
            $extensions['contract_id'] = $context['contract_id'];
        }
        
        if (isset($context['unit_external_id'])) {
            $extensions['unit_external_id'] = $context['unit_external_id'];
        }
        
        if (isset($context['client_id'])) {
            $extensions['client_id'] = $context['client_id'];
        }
        
        if (isset($context['alma_contract_id'])) {
            $extensions['alma_contract_id'] = $context['alma_contract_id'];
        }
        
        return $extensions;
    }
}

class ApartmentErrorTracker extends BaseEntityErrorTracker
{
    protected function getEntityType()
    {
        return ErrorTracker::ENTITY_APARTMENT;
    }
    
    protected function categorizeError($errorMessage, $context = [])
    {
        if (strpos($errorMessage, 'Failed to get apartment data from Bitrix24') !== false) {
            return ErrorTracker::CATEGORY_API_ERROR;
        }
        
        if (strpos($errorMessage, 'Apartment not found') !== false) {
            return ErrorTracker::CATEGORY_DATA_ERROR;
        }
        
        return ErrorTracker::CATEGORY_SYSTEM_ERROR;
    }
    
    protected function getExtensions($entityId, $context = [])
    {
        $extensions = [];
        
        if (isset($context['building_id'])) {
            $extensions['building_id'] = $context['building_id'];
        }
        
        if (isset($context['project_id'])) {
            $extensions['project_id'] = $context['project_id'];
        }
        
        return $extensions;
    }
}

class TenantErrorTracker extends BaseEntityErrorTracker
{
    protected function getEntityType()
    {
        return ErrorTracker::ENTITY_TENANT;
    }
    
    protected function categorizeError($errorMessage, $context = [])
    {
        if (strpos($errorMessage, 'email already exists') !== false) {
            return ErrorTracker::CATEGORY_VALIDATION_ERROR;
        }
        
        if (strpos($errorMessage, 'API request failed') !== false) {
            return ErrorTracker::CATEGORY_API_ERROR;
        }
        
        return ErrorTracker::CATEGORY_SYSTEM_ERROR;
    }
    
    protected function getExtensions($entityId, $context = [])
    {
        $extensions = [];
        
        if (isset($context['email'])) {
            $extensions['email'] = $context['email'];
        }
        
        if (isset($context['phone'])) {
            $extensions['phone'] = $context['phone'];
        }
        
        return $extensions;
    }
}

/**
 * Фабрика для создания трекеров ошибок
 */
class ErrorTrackerFactory
{
    public static function create($entityType)
    {
        switch ($entityType) {
            case ErrorTracker::ENTITY_CONTRACT:
                return new ContractErrorTracker();
            case ErrorTracker::ENTITY_APARTMENT:
                return new ApartmentErrorTracker();
            case ErrorTracker::ENTITY_TENANT:
                return new TenantErrorTracker();
            case ErrorTracker::ENTITY_WEBHOOK:
                return new WebhookErrorTracker();
            default:
                throw new InvalidArgumentException("Unsupported entity type: $entityType");
        }
    }
}

class WebhookErrorTracker extends BaseEntityErrorTracker
{
    protected function getEntityType()
    {
        return ErrorTracker::ENTITY_WEBHOOK;
    }
    
    protected function categorizeError($errorMessage, $context = [])
    {
        if (strpos($errorMessage, 'Failed to call') !== false) {
            return ErrorTracker::CATEGORY_API_ERROR;
        }
        
        if (strpos($errorMessage, 'Failed to get') !== false) {
            return ErrorTracker::CATEGORY_DATA_ERROR;
        }
        
        return ErrorTracker::CATEGORY_SYSTEM_ERROR;
    }
    
    protected function getExtensions($entityId, $context = [])
    {
        $extensions = [];
        
        if (isset($context['url'])) {
            $extensions['url'] = $context['url'];
        }
        
        if (isset($context['project_id'])) {
            $extensions['project_id'] = $context['project_id'];
        }
        
        return $extensions;
    }
}
