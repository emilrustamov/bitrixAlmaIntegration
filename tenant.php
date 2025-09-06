<?php

require_once('Bitrix24Rest.php');
require_once('ReAlma.php');
require_once('Dumper.php');
require_once('Logger.php');
require_once('Config.php');

// Загружаем конфигурацию
Config::load();

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));
define('PROJECT_ID', (int)Config::get('PROJECT_ID'));

class AlmaTenantsApi
{
    private $apiKey;
    private $apiUrl;
    private $debug;
    private $actionLogger;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL, $debug = false)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->debug = $debug;
        $this->actionLogger = new TenantActionLogger();
    }

    public function syncTenant(array $bitrixData)
    {
        $this->validateBitrixData($bitrixData);

        try {
            $tenantData = $this->prepareTenantData($bitrixData);
            $externalId = $tenantData['external_id'];
            $existingTenant = $this->getTenantByExternalId($externalId);

            if ($existingTenant) {
                return $this->updateTenant($existingTenant['id'], $tenantData);
            } else {
                return $this->createTenant($tenantData);
            }
        } catch (Exception $e) {
            throw new Exception("Tenant synchronization failed: " . $e->getMessage());
        }
    }

    public function createTenant(array $tenantData)
    {
        $url = $this->apiUrl . 'users/clients/';
        $response = $this->sendRequest('POST', $url, $tenantData);

        $this->actionLogger->logTenantCreation(
            $response['id'],
            $tenantData['first_name'] ?? '',
            $tenantData['last_name'] ?? '',
            $tenantData['email'] ?? '',
            $tenantData['phone'] ?? '',
            [
                'external_id' => $tenantData['external_id'] ?? '',
                'birthday' => $tenantData['birthday'] ?? null,
                'country' => $tenantData['country'] ?? 4
            ]
        );

        return $response;
    }

    /**
     * Обновляет существующего клиента
     */
    public function updateTenant($tenantId, array $tenantData)
    {
        // Получаем текущие данные клиента для сравнения
        $oldTenantData = $this->getTenant($tenantId);

        // Создаем копию данных для отправки (убираем поля которые не должны передаваться)
        $dataForUpdate = $tenantData;
        unset($dataForUpdate['external_id']);
        
        // Убираем поля которые не должны передаваться при обновлении
        $fieldsToRemove = ['external_id', 'id', 'status'];
        foreach ($fieldsToRemove as $field) {
            if (isset($dataForUpdate[$field])) {
                unset($dataForUpdate[$field]);
            }
        }

        $url = $this->apiUrl . 'users/clients/' . $tenantId . '/';
        $response = $this->sendRequest('PATCH', $url, $dataForUpdate);

        $entityName = trim(($tenantData['first_name'] ?? '') . ' ' . ($tenantData['last_name'] ?? ''));
        if (empty($entityName)) {
            $entityName = 'Tenant ' . $tenantId;
        }

        $this->actionLogger->logUpdate(
            $tenantId,
            $entityName,
            $oldTenantData,
            $dataForUpdate
        );

        return $response;
    }

    public function getTenant($tenantId)
    {
        $url = $this->apiUrl . 'users/clients/' . $tenantId . '/';
        return $this->sendRequest('GET', $url);
    }

    public function getTenantByExternalId($externalId)
    {
        $url = $this->apiUrl . 'users/clients/external_id/' . $externalId . '/';

        try {
            $result = $this->sendRequest('GET', $url);
            return $result;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function getAllTenants()
    {
        $url = $this->apiUrl . 'users/clients/';
        return $this->sendRequest('GET', $url);
    }

    public function getActionLogger()
    {
        return $this->actionLogger;
    }

    private function prepareTenantData(array $bitrixData)
    {
        $data = [
            'external_id' => (string)$bitrixData['id'],
            'first_name' => $bitrixData['name'] ?? '',
            'last_name' => $bitrixData['last_name'] ?? '',
            'phone' => $bitrixData['phone_work'] ?? '',
            'country' => 4,
            'birthday' => $this->formatBirthday($bitrixData['birthdate'] ?? null)
        ];

        if (!empty($bitrixData['UF_CRM_1727788747'])) {
            $data['email'] = $bitrixData['UF_CRM_1727788747'];
        } else {
            $data['email'] = 'no-email-' . $bitrixData['id'] . '@colife.local';
        }
        
        // Passport/ID fields
        if (!empty($bitrixData['UF_CRM_20_1696523391'])) {
            $data['passport'] = $bitrixData['UF_CRM_20_1696523391'];
        }

        $passportScan = $this->uploadFile($bitrixData['UF_CRM_20_1696615939']['urlMachine'] ?? null);
        if ($passportScan) {
            $data['passport_scan'] = $passportScan;
        }

        $idScan = $this->uploadFile($bitrixData['id_scan_url'] ?? null);
        if ($idScan) {
            $data['id_scan'] = $idScan;
        }

        return $data;
    }

    private function validateBitrixData(array $bitrixData)
    {
        $requiredFields = ['id', 'name'];

        foreach ($requiredFields as $field) {
            if (!isset($bitrixData[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }

            if (empty($bitrixData[$field]) && $bitrixData[$field] !== '0') {
                throw new InvalidArgumentException("Empty value for required field: $field");
            }
        }

        if (!empty($bitrixData['UF_CRM_1727788747']) && !filter_var($bitrixData['UF_CRM_1727788747'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format: " . $bitrixData['UF_CRM_1727788747']);
        }

        if (!empty($bitrixData['birthdate'])) {
            $this->validateBirthdate($bitrixData['birthdate']);
        }
    }

    private function validateBirthdate($dateStr)
    {
        if (empty($dateStr)) {
            return;
        }

        $formats = ['Y-m-d', DateTime::ATOM, 'Y-m-d\TH:i:s'];

        foreach ($formats as $format) {
            if (DateTime::createFromFormat($format, $dateStr)) {
                return;
            }
        }

        throw new InvalidArgumentException("Invalid birthdate format: $dateStr. Expected Y-m-d or ISO8601");
    }

    private function formatBirthday($dateStr)
    {
        if (empty($dateStr)) {
            return null;
        }

        $formats = ['Y-m-d', DateTime::ATOM, 'Y-m-d\TH:i:s'];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateStr);
            if ($date) {
                return $date->format('Y-m-d\T00:00:00');
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr, $matches)) {
            return $matches[0] . 'T00:00:00';
        }

        throw new InvalidArgumentException("Invalid birthday format: $dateStr");
    }

    private function uploadFile($fileUrl)
    {
        if (empty($fileUrl)) {
            return null;
        }

        $uploadUrl = $this->apiUrl . 'external-image/';
        $fileData = [
            'url' => $fileUrl,
            'description' => 'Client document'
        ];

        try {
            $response = $this->sendRequest('POST', $uploadUrl, $fileData);
            return $response['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function sendRequest($method, $url, $data = null)
    {
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($this->debug) {
            Logger::logApiRequest($method, $url, $data, $response, $httpCode);
        }

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL error: $error");
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            $errorMessage = "API request failed ($httpCode)";
            if ($response) {
                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['detail'])) {
                    $errorMessage .= ": " . $decoded['detail'];
                } else {
                    $errorMessage .= ": " . substr($response, 0, 200);
                }
            }
            throw new RuntimeException($errorMessage, $httpCode);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
        }

        return $decodedResponse;
    }
}



try {
    $contactId = $_GET['id'] ?? null;

    if (!$contactId) {
        throw new Exception('Contact ID is required');
    }

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $almaApi = new AlmaTenantsApi(ALMA_API_KEY, ALMA_API_URL, true);

    $bitrixContact = $bitrix->call('crm.contact.get', [
        'id' => $contactId
    ]);

    if (!isset($bitrixContact['result'])) {
        throw new Exception('Failed to get contact data from Bitrix24');
    }

    $contactData = $bitrixContact['result'];

    $phone = '';
    if (!empty($contactData['PHONE']) && is_array($contactData['PHONE'])) {
        foreach ($contactData['PHONE'] as $phoneData) {
            if (!empty($phoneData['VALUE'])) {
                $phone = $phoneData['VALUE'];
                break;
            }
        }
    }

    $bitrixTenantData = [
        'id' => $contactData['ID'],
        'name' => $contactData['NAME'] ?? '',
        'last_name' => $contactData['LAST_NAME'] ?? '',
        'UF_CRM_1727788747' => $contactData['UF_CRM_1727788747'] ?? '',
        'phone_work' => $contactData['PHONE_WORK_0'] ?? $phone,
        'birthdate' => $contactData['BIRTHDATE'] ?? null,
        'ufCrm10_1694000435068' => $contactData['UF_CRM_1694000435068'] ?? []
    ];

    $result = $almaApi->syncTenant($bitrixTenantData);

    if (isset($result['id'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Tenant successfully synchronized',
            'alma_id' => $result['id'],
            'data' => $result
        ]);
    } else {
        throw new Exception('Tenant synchronization failed');
    }
} catch (InvalidArgumentException $e) {
    $almaApi->getActionLogger()->logError(
        $contactId ?? 'unknown',
        'Contact Validation Error',
        $e->getMessage(),
        ['contact_data' => $bitrixTenantData ?? []]
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Data validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    $almaApi->getActionLogger()->logError(
        $contactId ?? 'unknown',
        'API Error',
        $e->getMessage(),
        ['contact_data' => $bitrixTenantData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $almaApi->getActionLogger()->logError(
        $contactId ?? 'unknown',
        'Unexpected Error',
        $e->getMessage(),
        ['contact_data' => $bitrixTenantData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}
