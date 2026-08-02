<?php

class Logger
{
    private static $logBaseDir = __DIR__ . '/../logs';
    private static $currentProject = 'general';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';

    /**
     * @param string $projectName
     * @return void
     */
    public static function setProject($projectName)
    {
        self::$currentProject = self::sanitizeProjectName($projectName);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @param string|null $entityType
     * @param string|null $entityId
     * @return void
     */
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

    /**
     * @param string $message
     * @param array $context
     * @param string|null $entityType
     * @param string|null $entityId
     * @return void
     */
    public static function info($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_INFO, $message, $context, $entityType, $entityId);
    }

    /**
     * @param string $message
     * @param array $context
     * @param string|null $entityType
     * @param string|null $entityId
     * @return void
     */
    public static function warning($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_WARNING, $message, $context, $entityType, $entityId);
    }

    /**
     * @param string $message
     * @param array $context
     * @param string|null $entityType
     * @param string|null $entityId
     * @return void
     */
    public static function error($message, $context = [], $entityType = null, $entityId = null)
    {
        self::log(self::LEVEL_ERROR, $message, $context, $entityType, $entityId);
    }

    /**
     * @param string $action
     * @param string $entityType
     * @param string|int $entityId
     * @param string $entityName
     * @param array $details
     * @param array|null $changes
     * @return void
     */
    public static function logEntityAction($action, $entityType, $entityId, $entityName, $details = [], $changes = null)
    {
        $context = array_merge(['action' => $action, 'entity_name' => $entityName, 'details' => $details], $changes ? ['changes' => $changes] : []);
        self::info("Entity $action: $entityName", $context, $entityType, $entityId);
    }

    /**
     * @return string
     */
    private static function resolveLogFilePath()
    {
        $project = self::$currentProject ?: 'general';
        $dir = self::$logBaseDir . DIRECTORY_SEPARATOR . $project . DIRECTORY_SEPARATOR . gmdate('Y-m-d');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . gmdate('H') . '.log';
    }

    /**
     * @return string
     */
    private static function resolveErrorLogFilePath()
    {
        $project = self::$currentProject ?: 'general';
        $dir = self::$logBaseDir . DIRECTORY_SEPARATOR . $project . DIRECTORY_SEPARATOR . gmdate('Y-m-d');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . gmdate('H') . '_error.log';
    }

    /**
     * @param string $projectName
     * @return string
     */
    private static function sanitizeProjectName($projectName)
    {
        $normalized = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$projectName);
        return $normalized !== '' ? strtolower($normalized) : 'general';
    }
}

class ActionLogger
{
    protected $entityType;
    protected $updateFields;

    /**
     * @param string $entityType
     * @param array $updateFields
     */
    public function __construct($entityType, array $updateFields = [])
    {
        $this->entityType = $entityType;
        $this->updateFields = $updateFields;
    }

    /**
     * @param string $action
     * @param string|int $entityId
     * @param string $entityName
     * @param array $details
     * @param array|null $changes
     * @return void
     */
    protected function logAction($action, $entityId, $entityName, $details = [], $changes = null)
    {
        Logger::logEntityAction($action, $this->entityType, $entityId, $entityName, $details, $changes);
    }

    /**
     * @param string|int $entityId
     * @param string $entityName
     * @param array|null $oldData
     * @param array $newData
     * @return void
     */
    public function logUpdate($entityId, $entityName, $oldData, $newData)
    {
        $changes = [];
        $oldData = is_array($oldData) ? $oldData : [];
        foreach ($this->updateFields as $field) {
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
    public function __construct()
    {
        parent::__construct('contract', ['name', 'start_date', 'end_date', 'price', 'type_contract']);
    }

    /**
     * @param string|int $contractId
     * @param string $contractName
     * @param string|int $clientId
     * @param string|int $unitId
     * @param array $details
     * @return void
     */
    public function logContractCreation($contractId, $contractName, $clientId, $unitId, $details = [])
    {
        $this->logAction('CREATED', $contractId, $contractName, array_merge(['client_id' => $clientId, 'unit_id' => $unitId], $details));
    }
}

class ApartmentActionLogger extends ActionLogger
{
    public function __construct()
    {
        parent::__construct('apartment', ['name', 'floor', 'internal_area', 'number_of_bedrooms', 'property_type', 'goal_rent_cost']);
    }

    /**
     * @param string|int $apartmentId
     * @param string $apartmentName
     * @param string|int $buildingId
     * @param array $details
     * @return void
     */
    public function logApartmentCreation($apartmentId, $apartmentName, $buildingId, $details = [])
    {
        $this->logAction('CREATED', $apartmentId, $apartmentName, array_merge(['building_id' => $buildingId], $details));
    }
}

class LandlordContractActionLogger extends ActionLogger
{
    public function __construct()
    {
        parent::__construct('landlord_contract', ['name', 'start_date', 'end_date', 'work_model', 'unit_id', 'client_id']);
    }

    /**
     * @param string|int $contractId
     * @param string $contractName
     * @param string|int $landlordId
     * @param string|int $apartmentId
     * @param array $details
     * @return void
     */
    public function logContractCreation($contractId, $contractName, $landlordId, $apartmentId, $details = [])
    {
        $this->logAction('CREATED', $contractId, $contractName, array_merge(['landlord_id' => $landlordId, 'apartment_id' => $apartmentId], $details));
    }
}
