<?php

class Logger
{
    private static $logBaseDir = __DIR__ . '/../logs';
    private static $currentProject = 'general';
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    
    public static function setLogBaseDir($path)
    {
        if (!empty($path)) {
            self::$logBaseDir = rtrim($path, DIRECTORY_SEPARATOR);
        }
    }
    
    public static function setProject($projectName)
    {
        self::$currentProject = self::sanitizeProjectName($projectName);
    }
    
    public static function log($level, $message, $context = [], $entityType = null, $entityId = null)
    {
        $timestamp = gmdate('Y-m-d H:i:s');
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
        $logFile = self::resolveLogFilePath();
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        if ($level === self::LEVEL_ERROR || $level === self::LEVEL_WARNING) {
            $errorLogFile = self::resolveErrorLogFilePath();
            file_put_contents($errorLogFile, $logLine, FILE_APPEND | LOCK_EX);
        }
        if ($level === self::LEVEL_ERROR) {
            echo htmlspecialchars("[$timestamp] [$level] $message") . "<br>\n";
            flush();
        }
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
            self::error("API Error: $method " . basename($url) . " ($httpCode)", ['response' => $response, 'http_code' => $httpCode]);
        }
    }
    
    public static function logEntityAction($action, $entityType, $entityId, $entityName, $details = [], $changes = null)
    {
        $context = array_merge(['action' => $action, 'entity_name' => $entityName, 'details' => $details], $changes ? ['changes' => $changes] : []);
        self::info("Entity $action: $entityName", $context, $entityType, $entityId);
    }
    
    private static function resolveLogFilePath()
    {
        $project = self::$currentProject ?: 'general';
        $dir = self::$logBaseDir . DIRECTORY_SEPARATOR . $project . DIRECTORY_SEPARATOR . gmdate('Y-m-d');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . gmdate('H') . '.log';
    }

    private static function resolveErrorLogFilePath()
    {
        $project = self::$currentProject ?: 'general';
        $dir = self::$logBaseDir . DIRECTORY_SEPARATOR . $project . DIRECTORY_SEPARATOR . gmdate('Y-m-d');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . gmdate('H') . '_error.log';
    }
    
    private static function sanitizeProjectName($projectName)
    {
        $normalized = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$projectName);
        return $normalized !== '' ? strtolower($normalized) : 'general';
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
        if ($error) {
            Logger::error("Entity action failed: $action", array_merge(['action' => $action, 'entity_name' => $entityName, 'details' => $details, 'error' => $error], $changes ? ['changes' => $changes] : []), $this->entityType, $entityId);
        } else {
            Logger::logEntityAction($action, $this->entityType, $entityId, $entityName, $details, $changes);
        }
    }

    protected function logUpdateInternal($entityId, $entityName, $oldData, $newData, array $fieldsToCompare)
    {
        $changes = [];
        foreach ($fieldsToCompare as $field) {
            $oldValue = $oldData[$field] ?? '';
            $newValue = $newData[$field] ?? '';
            if ($oldValue !== $newValue) {
                $changes[$field] = ['old_value' => $oldValue, 'new_value' => $newValue];
            }
        }
        $this->logAction('UPDATED', $entityId, $entityName, [], $changes);
    }
}

class ContractActionLogger extends ActionLogger
{
    private static $updateFields = ['name', 'start_date', 'end_date', 'price', 'type_contract'];

    public function __construct()
    {
        parent::__construct('contract');
    }

    public function logContractCreation($contractId, $contractName, $clientId, $unitId, $details = [])
    {
        $this->logAction('CREATED', $contractId, $contractName, array_merge(['client_id' => $clientId, 'unit_id' => $unitId], $details));
    }

    public function logUpdate($contractId, $contractName, $oldData, $newData)
    {
        $this->logUpdateInternal($contractId, $contractName, $oldData, $newData, self::$updateFields);
    }
}

class ApartmentActionLogger extends ActionLogger
{
    private static $updateFields = ['name', 'floor', 'area', 'rooms', 'status'];

    public function __construct()
    {
        parent::__construct('apartment');
    }

    public function logApartmentCreation($apartmentId, $apartmentName, $buildingId, $details = [])
    {
        $this->logAction('CREATED', $apartmentId, $apartmentName, array_merge(['building_id' => $buildingId], $details));
    }

    public function logUpdate($apartmentId, $apartmentName, $oldData, $newData)
    {
        $this->logUpdateInternal($apartmentId, $apartmentName, $oldData, $newData, self::$updateFields);
    }
}

class LandlordContractActionLogger extends ActionLogger
{
    private static $updateFields = ['name', 'start_date', 'end_date', 'work_model', 'unit_id', 'client_id'];

    public function __construct()
    {
        parent::__construct('landlord_contract');
    }

    public function logContractCreation($contractId, $contractName, $landlordId, $apartmentId, $details = [])
    {
        $this->logAction('CREATED', $contractId, $contractName, array_merge(['landlord_id' => $landlordId, 'apartment_id' => $apartmentId], $details));
    }

    public function logUpdate($contractId, $contractName, $oldData, $newData)
    {
        $this->logUpdateInternal($contractId, $contractName, $oldData, $newData, self::$updateFields);
    }
}


