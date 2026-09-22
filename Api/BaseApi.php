<?php

require_once __DIR__ . '/../Core/Logger.php';

class BaseApi {
    /**
     * @param string $apiKey
     * @param string $apiUrl
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param bool $isPost
     * @return array
     */
    public static function callRealty($apiKey, $apiUrl, $method, $endpoint, $data = [], $isPost = true)
    {
        $url = rtrim($apiUrl, '/') . '/realty/' . ltrim($endpoint, '/');
        if (!$isPost && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        return self::sendRequest($apiKey, $method, $url, $isPost ? $data : null, false);
    }

    /**
     * @param string $apiKey
     * @param string $method
     * @param string $url
     * @param mixed $data
     * @param bool $throwOnError
     * @return mixed
     */
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
            $jsonFlags = JSON_UNESCAPED_UNICODE;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }
            $payload = json_encode($data, $jsonFlags);
            if ($payload === false) {
                throw new RuntimeException('Failed to encode request JSON: ' . json_last_error_msg());
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
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

        if (!$throwOnError) {
            return [
                'code'     => $httpCode,
                'response' => $decoded,
            ];
        }

        if ($httpCode >= 400) {
            $msg = "API request failed ($httpCode)";
            if (is_array($decoded) && isset($decoded['detail'])) {
                $detail = $decoded['detail'];
                $msg .= ': ' . (is_string($detail) ? $detail : json_encode($detail));
            } elseif ($httpCode === 404) {
                $msg .= ': resource not found';
            } elseif (is_string($response) && $response !== '' && !str_starts_with(trim($response), '<')) {
                $msg .= ': ' . substr($response, 0, 200);
            }
            throw new RuntimeException($msg, $httpCode);
        }

        if ($decoded === null && is_string($response) && $response !== '') {
            throw new RuntimeException('Invalid JSON response: ' . substr($response, 0, 200));
        }

        return $decoded;
    }

    public static function sendErrorResponse($e, $msg, $entityType = 'api', $entityId = null, $extra = [])
    {
        if ($e instanceof InvalidArgumentException) {
            $code = 400;
            $message = 'Validation error: ' . $e->getMessage();
        } elseif ($e instanceof RuntimeException) {
            $exceptionCode = (int) $e->getCode();
            $code = ($exceptionCode >= 400 && $exceptionCode < 600) ? $exceptionCode : 500;
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
        echo json_encode(array_merge(['message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    }

    public static function sendSuccessResponse($payload)
    {
        header('Content-Type: application/json');
        $status = 200;
        $body = $payload;
        if (
            is_array($payload)
            && array_key_exists('code', $payload)
            && array_key_exists('response', $payload)
            && array_diff_key($payload, array_flip(['code', 'response', 'method'])) === []
        ) {
            $status = (int) $payload['code'];
            if ($status < 100 || $status > 599) {
                $status = 200;
            }
            $body = $payload['response'];
        }
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE);
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

    public static function getUnitIdByAptExternalId($apiKey, $apiUrl, $apartmentExternalId)
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

    public static function uploadImage($apiKey, $apiUrl, $fileData, $description = 'Document')
    {
        $fileUrl = self::extractBitrixFileUrl($fileData);
        if (empty($fileUrl)) {
            return null;
        }

        $downloaded = self::downloadRemoteFile($fileUrl);
        if ($downloaded === null) {
            Logger::warning('File download failed', ['url' => $fileUrl, 'description' => $description]);
            return null;
        }

        $uploadUrl = rtrim($apiUrl, '/') . '/external-image/';
        try {
            return self::uploadMultipartImage(
                $apiKey,
                $uploadUrl,
                $downloaded['path'],
                $downloaded['mime'],
                $downloaded['filename'],
                $description
            );
        } finally {
            @unlink($downloaded['path']);
        }
    }

    private static function downloadRemoteFile(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
        if (curl_errno($ch)) {
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        if ($httpCode >= 400 || !is_string($body) || $body === '') {
            return null;
        }

        $mime = trim(explode(';', $contentType)[0]);
        $extension = self::mimeToExtension($mime);
        $path = tempnam(sys_get_temp_dir(), 'alma_upload_') . '.' . $extension;
        if (file_put_contents($path, $body) === false) {
            return null;
        }

        return [
            'path' => $path,
            'mime' => $mime,
            'filename' => 'document.' . $extension,
        ];
    }

    private static function uploadMultipartImage(
        string $apiKey,
        string $uploadUrl,
        string $filePath,
        string $mime,
        string $filename,
        string $description
    ): ?int {
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => [
                'image' => new CURLFile($filePath, $mime, $filename),
                'description' => $description,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            Logger::warning('File upload failed', [
                'description' => $description,
                'error' => $error,
            ]);
            return null;
        }
        curl_close($ch);

        $decoded = is_string($response) && $response !== '' ? json_decode($response, true) : null;
        if ($httpCode >= 400) {
            Logger::warning('File upload failed', [
                'description' => $description,
                'http_code' => $httpCode,
                'response' => $decoded ?? $response,
            ]);
            return null;
        }

        return isset($decoded['id']) ? (int) $decoded['id'] : null;
    }

    private static function mimeToExtension(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            default => 'bin',
        };
    }

    public static function extractBitrixFileUrl($fileData): ?string
    {
        $urls = self::extractBitrixFileUrls($fileData);
        return $urls[0] ?? null;
    }

    public static function extractBitrixFileUrls($fileData): array
    {
        if ($fileData === null || $fileData === '' || $fileData === []) {
            return [];
        }
        if (is_string($fileData)) {
            $trimmed = trim($fileData);
            return $trimmed !== '' ? [$trimmed] : [];
        }
        if (!is_array($fileData)) {
            return [];
        }
        if (!empty($fileData['urlMachine']) && is_string($fileData['urlMachine'])) {
            return [$fileData['urlMachine']];
        }
        $urls = [];
        foreach ($fileData as $item) {
            if (is_string($item) && trim($item) !== '') {
                $urls[] = $item;
                continue;
            }
            if (is_array($item) && !empty($item['urlMachine']) && is_string($item['urlMachine'])) {
                $urls[] = $item['urlMachine'];
            }
        }
        return $urls;
    }
}
