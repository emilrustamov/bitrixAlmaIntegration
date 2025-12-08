<?php

class BaseApi
{
    /**
     * Базовый HTTP-клиент для запросов в Alma.
     *
     * @param string      $apiKey       Api-Key для Alma
     * @param string      $method       HTTP-метод: GET|POST|PATCH
     * @param string      $url          Полный URL
     * @param array|null  $data         Тело запроса (для POST/PATCH)
     * @param bool        $throwOnError Если true — выбрасывает RuntimeException при HTTP >= 400,
     *                                  иначе возвращает ['code' => int, 'response' => mixed]
     *
     * @return mixed      Декодированный ответ (если $throwOnError = true)
     *                    или ['code' => int, 'response' => mixed] (если false)
     *
     * @throws RuntimeException
     */
    public static function sendRequest(string $apiKey, string $method, string $url, ?array $data = null, bool $throwOnError = true)
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

        $decoded = null;
        if ($response !== false && $response !== null && $response !== '') {
            $decoded = json_decode($response, true);
        }

        if ($throwOnError) {
            if ($httpCode >= 400) {
                $errorMessage = "API request failed ($httpCode)";
                if ($decoded !== null && json_last_error() === JSON_ERROR_NONE && isset($decoded['detail'])) {
                    $errorMessage .= ': ' . $decoded['detail'];
                } elseif ($response) {
                    $errorMessage .= ': ' . substr($response, 0, 200);
                }
                throw new RuntimeException($errorMessage, $httpCode);
            }

            if ($decoded === null && $response !== '' && json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
            }

            return $decoded;
        }

        return [
            'code'     => $httpCode,
            'response' => $decoded,
        ];
    }
}


