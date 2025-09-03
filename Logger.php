<?php

class Logger
{
    private static $logFile = __DIR__ . '/alma_integration.log';
    private static $instance = null;
    
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    
    private function __construct() {}
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function log($level, $message, $context = [], $entityType = null, $entityId = null)
    {
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
        $context = [
            'method' => $method,
            'url' => $url,
            'http_code' => $httpCode
        ];
        
        if ($data !== null) {
            $context['request_data'] = $data;
        }
        
        if ($response !== null) {
            $context['response'] = $response;
        }
        
        self::info("API Request: $method $url", $context);
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
        
        self::info("Entity $action: $entityName", $context, $entityType, $entityId);
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
