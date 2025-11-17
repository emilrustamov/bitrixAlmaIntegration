<?php

class Bitrix24Rest
{
    private $webhookUrl;

    public function __construct($webhookUrl)
    {
        if (empty($webhookUrl)) {
            throw new InvalidArgumentException("Webhook URL cannot be empty");
        }
        
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid webhook URL format: " . substr($webhookUrl, 0, 50));
        }
        
        $this->webhookUrl = rtrim($webhookUrl, '/') . '/';
    }

    public function call($method, $params = [])
    {
        $url = $this->webhookUrl . $method;
        $query = http_build_query($params);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $query,
            CURLOPT_TIMEOUT => 30,           // Общий таймаут 30 секунд
            CURLOPT_CONNECTTIMEOUT => 10,    // Таймаут подключения 10 секунд
            CURLOPT_FOLLOWLOCATION => true,  // Следовать редиректам
            CURLOPT_MAXREDIRS => 3,          // Максимум 3 редиректа
        ]);

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($result === false) {
            throw new RuntimeException("cURL error: " . $error);
        }

        if ($httpCode >= 400) {
            $errorMessage = "Bitrix24 API error (HTTP $httpCode)";
            
            if ($httpCode === 401) {
                $decodedError = json_decode($result, true);
                if (isset($decodedError['error']) && $decodedError['error'] === 'INVALID_CREDENTIALS') {
                    $errorMessage = "Bitrix24 webhook authentication failed (HTTP 401): Invalid or expired webhook URL. Please check your webhook configuration in config.env file. Webhook URL: " . substr($this->webhookUrl, 0, 50) . "...";
                } else {
                    $errorMessage .= ": " . substr($result, 0, 200);
                }
            } else {
                $errorMessage .= ": " . substr($result, 0, 200);
            }
            
            throw new RuntimeException($errorMessage);
        }

        $decoded = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response from Bitrix24: " . substr($result, 0, 200));
        }

        return $decoded;
    }
}