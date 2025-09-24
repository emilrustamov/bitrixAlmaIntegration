<?php

class Logger
{
    private static $logFile = __DIR__ . '/alma_integration.log';
    private static $instance = null;
    private static $minLevel = self::LEVEL_WARNING; // Изменено с INFO на WARNING
    
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    
    const MAX_RESPONSE_LENGTH = 50;
    
    // Добавляем счетчики для предотвращения спама
    private static $logCounters = [];
    private static $lastLogTimes = [];
    private static $suppressDuplicates = true;
    
    private static $levelPriority = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3
    ];
    
    private function __construct() {}
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function setMinLevel($level)
    {
        if (isset(self::$levelPriority[$level])) {
            self::$minLevel = $level;
        }
    }
    
    public static function getMinLevel()
    {
        return self::$minLevel;
    }
    
    public static function setSuppressDuplicates($suppress)
    {
        self::$suppressDuplicates = $suppress;
    }
    
    public static function getSuppressDuplicates()
    {
        return self::$suppressDuplicates;
    }
    
    private static function extractKeyFields($response)
    {
        if (empty($response)) {
            return $response;
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return strlen($response) > 50 ? substr($response, 0, 50) . '...' : $response;
        }
        $keyFields = [];
        if (isset($decoded['id'])) $keyFields['id'] = $decoded['id'];
        if (isset($decoded['external_id'])) $keyFields['external_id'] = $decoded['external_id'];
        if (isset($decoded['name'])) $keyFields['name'] = $decoded['name'];
        if (isset($decoded['detail'])) $keyFields['error'] = $decoded['detail'];
        if (isset($decoded['message'])) $keyFields['message'] = $decoded['message'];
        if (isset($decoded['non_field_errors'])) $keyFields['errors'] = $decoded['non_field_errors'];
        foreach (['email', 'last_name', 'first_name', 'phone'] as $field) {
            if (isset($decoded[$field]) && is_array($decoded[$field])) {
                $keyFields[$field . '_error'] = $decoded[$field][0];
            }
        }
        
        return json_encode($keyFields, JSON_UNESCAPED_UNICODE);
    }
    
    private static function shouldLog($level)
    {
        return self::$levelPriority[$level] >= self::$levelPriority[self::$minLevel];
    }
    
    private static function shouldSuppressDuplicate($message, $entityType, $entityId)
    {
        if (!self::$suppressDuplicates) {
            return false;
        }
        
        $key = md5($message . $entityType . $entityId);
        $now = time();
        
        // Если это тот же лог в течение последних 60 секунд - подавляем
        if (isset(self::$lastLogTimes[$key]) && ($now - self::$lastLogTimes[$key]) < 60) {
            self::$logCounters[$key] = (self::$logCounters[$key] ?? 0) + 1;
            return true;
        }
        
        // Если было много повторений - логируем сводку
        if (isset(self::$logCounters[$key]) && self::$logCounters[$key] > 5) {
            self::log(self::LEVEL_INFO, "Suppressed " . self::$logCounters[$key] . " duplicate messages: " . substr($message, 0, 100), [], $entityType, $entityId);
            self::$logCounters[$key] = 0;
        }
        
        self::$lastLogTimes[$key] = $now;
        self::$logCounters[$key] = 0;
        return false;
    }
    
    public static function log($level, $message, $context = [], $entityType = null, $entityId = null)
    {
        if (!self::shouldLog($level)) {
            return;
        }
        
        // Проверяем дубликаты для INFO и WARNING уровней
        if (in_array($level, [self::LEVEL_INFO, self::LEVEL_WARNING]) && self::shouldSuppressDuplicate($message, $entityType, $entityId)) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
        
        if ($entityType) {
            $logEntry['entity_type'] = $entityType;
        }
        
        if ($entityId) {
            $logEntry['entity_id'] = $entityId;
        }
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        if ($level === self::LEVEL_ERROR) {
            echo htmlspecialchars("[$timestamp] [$level] $message") . "<br>\n";
            flush();
        }
    }
    
    public static function debug($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_DEBUG, $message, $context, $entityType, $entityId);
    }
    
    public static function info($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_INFO, $message, $context, $entityType, $entityId);
    }
    
    public static function warning($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_WARNING, $message, $context, $entityType, $entityId);
    }
    
    public static function error($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_ERROR, $message, $context, $entityType, $entityId);
    }
    
    public static function logApiRequest($method, $url, $data = null, $response = null, $httpCode = null)
    {
        if ($httpCode >= 400) {
            $shortResponse = self::extractKeyFields($response);
            self::error("API Error: $method " . basename($url) . " ($httpCode)", ['error' => $shortResponse]);
        }
    }
    
    public static function logEntityAction($action, $entityType, $entityId, $entityName, $details = [], $changes = null)
    {
        $context = [
            'action' => $action,
            'entity_name' => $entityName,
            'details' => $details
        ];
        
        if ($changes) {
            $context['changes'] = $changes;
        }
        
        // Логируем только важные действия
        if (in_array($action, ['CREATED', 'UPDATED', 'ERROR'])) {
            self::info("Entity $action: $entityName", $context, $entityType, $entityId);
        }
    }
    
    // Новый метод для логирования только критических событий
    public static function logCritical($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_ERROR, $message, $context, $entityType, $entityId);
    }
    
    // Метод для логирования только при ошибках
    public static function logOnError($message, $context = [], $entityType = null, $entityId = null)
    {
        // Логируем только если есть ошибки в контексте
        if (isset($context['error']) || isset($context['exception']) || isset($context['http_code']) && $context['http_code'] >= 400) {
            self::log(self::LEVEL_ERROR, $message, $context, $entityType, $entityId);
        }
    }
    
    public static function getLogs($startDate = null, $endDate = null, $level = null, $entityType = null)
    {
        if (!file_exists(self::$logFile)) {
            return [];
        }
        
        $logs = [];
        $lines = file(self::$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $logEntry = json_decode($line, true);
            if (!$logEntry) continue;
            
            $logDate = date('Y-m-d', strtotime($logEntry['timestamp']));
            
            if ($startDate && $logDate < $startDate) continue;
            if ($endDate && $logDate > $endDate) continue;
            if ($level && $logEntry['level'] !== $level) continue;
            if ($entityType && ($logEntry['entity_type'] ?? '') !== $entityType) continue;
            
            $logs[] = $logEntry;
        }
        
        return $logs;
    }
    
    public static function getStats($startDate = null, $endDate = null)
    {
        $logs = self::getLogs($startDate, $endDate);
        
        $stats = [
            'total' => count($logs),
            'debug' => 0,
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'by_entity' => []
        ];
        
        foreach ($logs as $log) {
            $level = $log['level'] ?? 'unknown';
            if (isset($stats[$level])) {
                $stats[$level]++;
            }
            
            $entityType = $log['entity_type'] ?? 'general';
            if (!isset($stats['by_entity'][$entityType])) {
                $stats['by_entity'][$entityType] = 0;
            }
            $stats['by_entity'][$entityType]++;
        }
        
        return $stats;
    }
    
    // Метод для ротации логов
    public static function rotateLogs($maxSizeMB = 10)
    {
        if (!file_exists(self::$logFile)) {
            return;
        }
        
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;
        $currentSize = filesize(self::$logFile);
        
        if ($currentSize > $maxSizeBytes) {
            $backupFile = self::$logFile . '.' . date('Y-m-d-H-i-s');
            rename(self::$logFile, $backupFile);
            
            // Создаем новый пустой лог файл
            file_put_contents(self::$logFile, '');
            
            // Сжимаем старый файл
            if (function_exists('gzopen')) {
                $gzFile = $backupFile . '.gz';
                $fp_in = fopen($backupFile, 'rb');
                $fp_out = gzopen($gzFile, 'wb9');
                
                if ($fp_in && $fp_out) {
                    while (!feof($fp_in)) {
                        gzwrite($fp_out, fread($fp_in, 1024 * 512));
                    }
                    fclose($fp_in);
                    gzclose($fp_out);
                    unlink($backupFile); // Удаляем несжатый файл
                }
            }
            
            self::info("Log file rotated", ['old_size' => $currentSize, 'backup_file' => basename($backupFile)]);
        }
    }
    
    // Метод для очистки старых логов
    public static function cleanOldLogs($daysToKeep = 7)
    {
        $logDir = dirname(self::$logFile);
        $pattern = $logDir . '/alma_integration.log.*';
        $files = glob($pattern);
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                self::info("Deleted old log file", ['file' => basename($file)]);
            }
        }
    }
}

abstract class ActionLogger
{
    protected $entityType;

    public function __construct($entityType)
    {
        $this->entityType = $entityType;
    }

    protected function logAction($action, $entityId, $entityName, $details = [], $changes = null, $error = null)
    {
        $context = [
            'action' => $action,
            'entity_name' => $entityName,
            'details' => $details
        ];

        if ($changes) {
            $context['changes'] = $changes;
        }

        if ($error) {
            $context['error'] = $error;
            Logger::error("Entity action failed: $action", $context, $this->entityType, $entityId);
        } else {
            Logger::logEntityAction($action, $this->entityType, $entityId, $entityName, $details, $changes);
        }
    }

    public function logError($entityId, $entityName, $error, $context = [])
    {
        $this->logAction('ERROR', $entityId, $entityName, $context, null, $error);
    }
}

class TenantActionLogger extends ActionLogger
{
    public function __construct()
    {
        parent::__construct('tenant');
    }

    public function logTenantCreation($tenantId, $firstName, $lastName, $email, $phone, $details = [])
    {
        $entityName = trim($firstName . ' ' . $lastName);
        if (empty($entityName)) {
            $entityName = 'Tenant ' . $tenantId;
        }

        $this->logAction('CREATED', $tenantId, $entityName, array_merge([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone
        ], $details));
    }

    public function logUpdate($tenantId, $entityName, $oldData, $newData)
    {
        $changes = [];
        
        $fieldsToCompare = ['first_name', 'last_name', 'email', 'phone', 'birthday'];
        foreach ($fieldsToCompare as $field) {
            $oldValue = $oldData[$field] ?? '';
            $newValue = $newData[$field] ?? '';
            
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ];
            }
        }

        $this->logAction('UPDATED', $tenantId, $entityName, [], $changes);
    }
}

class ContractActionLogger extends ActionLogger
{
    public function __construct()
    {
        parent::__construct('contract');
    }

    public function logContractCreation($contractId, $contractName, $clientId, $unitId, $details = [])
    {
        $this->logAction('CREATED', $contractId, $contractName, array_merge([
            'client_id' => $clientId,
            'unit_id' => $unitId
        ], $details));
    }

    public function logUpdate($contractId, $contractName, $oldData, $newData)
    {
        $changes = [];
        
        $fieldsToCompare = ['name', 'start_date', 'end_date', 'price', 'type_contract'];
        foreach ($fieldsToCompare as $field) {
            $oldValue = $oldData[$field] ?? '';
            $newValue = $newData[$field] ?? '';
            
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ];
            }
        }

        $this->logAction('UPDATED', $contractId, $contractName, [], $changes);
    }
}

class ApartmentActionLogger extends ActionLogger
{
    public function __construct()
    {
        parent::__construct('apartment');
    }

    public function logApartmentCreation($apartmentId, $apartmentName, $buildingId, $details = [])
    {
        $this->logAction('CREATED', $apartmentId, $apartmentName, array_merge([
            'building_id' => $buildingId
        ], $details));
    }

    public function logUpdate($apartmentId, $apartmentName, $oldData, $newData)
    {
        $changes = [];
        
        $fieldsToCompare = ['name', 'floor', 'area', 'rooms', 'status'];
        foreach ($fieldsToCompare as $field) {
            $oldValue = $oldData[$field] ?? '';
            $newValue = $newData[$field] ?? '';
            
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'old_value' => $oldValue,
                    'new_value' => $newValue
                ];
            }
        }

        $this->logAction('UPDATED', $apartmentId, $apartmentName, [], $changes);
    }
}
