<?php

require_once __DIR__ . '/../Core/Logger.php';

class BaseApi {
    public static function sendRequest($apiKey, $method, $url, $data = null, $throwOnError = true)
    {
        $headers = [
            'Api-Key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $upperMethod = strtoupper($method);
        if ($upperMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($upperMethod !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $upperMethod);
        }

        if ($data !== null && $upperMethod !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL error: $error");
        }

        curl_close($ch);

        $decoded = (is_string($response) && $response !== '') ? json_decode($response, true) : null;

        if ($throwOnError) {
            if ($httpCode >= 400) {
                $msg = "API request failed ($httpCode)";
                if (is_array($decoded) && isset($decoded['detail'])) {
                    $msg .= ': ' . (is_string($decoded['detail']) ? $decoded['detail'] : json_encode($decoded['detail']));
                } elseif ($httpCode === 404) {
                    $msg .= ': resource not found';
                } elseif (is_string($response) && $response !== '' && !str_starts_with(trim($response), '<')) {
                    $msg .= ': ' . substr($response, 0, 200);
                }
                throw new RuntimeException($msg, $httpCode);
            }
            if ($decoded === null && is_string($response) && $response !== '') {
                throw new RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
            }
            return $decoded;
        }

        return [
            'code'     => $httpCode,
            'response' => $decoded,
        ];
    }

    public static function sendErrorResponse($e, $msg, $entityType = 'api', $entityId = null, $extra = [])
    {
        if ($e instanceof InvalidArgumentException) {
            $code = 400;
            $message = 'Validation error: ' . $e->getMessage();
        } elseif ($e instanceof RuntimeException) {
            $code = 500;
            $message = 'API error: ' . $e->getMessage();
        } else {
            Logger::error($msg . ': ' . $e->getMessage(), [
                'exception_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ], $entityType, $entityId ?? 'unknown');
            $code = 500;
            $message = $msg;
        }
        http_response_code($code);
        header('Content-Type: application/json');
        $response = array_merge(['message' => $message], $extra);
        echo json_encode(['success' => false, 'code' => $code, 'response' => $response, 'method' => 'ERROR']);
    }

    public static function sendSuccessResponse($payload)
    {
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true], $payload));
    }

    public static function formatDateForAlma($dateStr)
    {
        $trimmed = trim($dateStr);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Date is required');
        }
        $date = DateTime::createFromFormat('Y-m-d', $trimmed) ?: date_create($trimmed);
        if (!$date) {
            throw new InvalidArgumentException("Invalid date: $dateStr");
        }
        return $date->format('Y-m-d\T00:00:00\Z');
    }

    public static function fetchUnitByExternalId($apiKey, $apiUrl, $externalId)
    {
        $url = rtrim($apiUrl, '/') . '/realty/units/external_id/' . rawurlencode($externalId) . '/';
        return self::sendRequest($apiKey, 'GET', $url, null, false);
    }

    public static function getUnitIdByApartmentExternalId($apiKey, $apiUrl, $apartmentExternalId)
    {
        $result = self::fetchUnitByExternalId($apiKey, $apiUrl, $apartmentExternalId);
        $code = $result['code'] ?? 0;
        $response = $result['response'] ?? [];
        if ($code === 404) {
            throw new InvalidArgumentException("Apartment (Bitrix ID: $apartmentExternalId) not found in Alma. Sync the apartment or unit first.");
        }
        if ($code >= 400 || empty($response['id'])) {
            $detail = is_array($response) && isset($response['detail']) ? (is_string($response['detail']) ? $response['detail'] : json_encode($response['detail'])) : 'Failed to get unit from Alma';
            throw new RuntimeException($detail, $code ?: 500);
        }
        return (string) $response['id'];
    }

    public static function uploadImage($apiKey, $apiUrl, $fileUrl, $description = 'Document')
    {
        if (empty($fileUrl)) {
            return null;
        }
        $uploadUrl = rtrim($apiUrl, '/') . '/external-image/';
        $fileData = ['url' => $fileUrl, 'description' => $description];
        try {
            $response = self::sendRequest($apiKey, 'POST', $uploadUrl, $fileData, true);
            return $response['id'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }
}


