<?php

require_once(__DIR__ . '/../Logger.php');

abstract class BaseApiClient
{
    protected $apiKey;
    protected $apiUrl;
    protected $actionLogger;

    public function __construct($apiKey, $apiUrl)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
    }

    protected function sendRequest($method, $url, $data = null)
    {
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        $curl = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ];

        if ($method === 'POST' || $method === 'PATCH') {
            if ($method === 'PATCH') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            } else {
                $options[CURLOPT_CUSTOMREQUEST] = 'POST';
            }
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = 'GET';
            if (!empty($data)) {
                $url .= '?' . http_build_query($data);
                $options[CURLOPT_URL] = $url;
            }
        }

        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        // Логируем только ошибки API
        if ($httpCode >= 400) {
            Logger::logApiRequest($method, $url, $data, $response, $httpCode);
        }

        return [
            'code' => $httpCode,
            'response' => json_decode($response, true)
        ];
    }

    protected function uploadFile($fileUrl)
    {
        if (empty($fileUrl)) {
            return null;
        }

        try {
            $fileContent = file_get_contents($fileUrl);
            if ($fileContent === false) {
                Logger::warning("Failed to download file: $fileUrl");
                return null;
            }

            $base64Content = base64_encode($fileContent);
            $filename = basename(parse_url($fileUrl, PHP_URL_PATH));
            
            return [
                'name' => $filename,
                'content' => $base64Content
            ];
        } catch (Exception $e) {
            Logger::error("File upload failed: " . $e->getMessage());
            return null;
        }
    }

    public function getActionLogger()
    {
        return $this->actionLogger;
    }
}

