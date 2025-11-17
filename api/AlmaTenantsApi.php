<?php

require_once(__DIR__ . '/../Logger.php');
require_once(__DIR__ . '/../Config.php');

class AlmaTenantsApi
{
    private $apiKey;
    private $apiUrl;
    private $debug;
    private $actionLogger;

    public function __construct($apiKey, $apiUrl, $debug = false)
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

    public function updateTenant($tenantId, array $tenantData)
    {
        $oldTenantData = $this->getTenant($tenantId);
        $dataForUpdate = $tenantData;
        unset($dataForUpdate['external_id']);
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
        }
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
