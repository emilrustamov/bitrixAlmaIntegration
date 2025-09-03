<?php

class ReAlma
{
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $debug;

    public function __construct(string $apiKey, string $baseUrl, int $timeout = 30, bool $debug = false)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->debug = $debug;
    }

    public function call(string $method, string $endpoint, array $data = [], array $params = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method);

        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
        ];

        if ($method === 'GET' && !empty($params)) {
            $options[CURLOPT_URL] .= '?' . http_build_query($params);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($this->debug) {
            $this->logRequest($method, $url, $data, $headers, $response, $info, $error);
        }

        if ($error) {
            throw new AlmaApiException('cURL Error: ' . $error, $info['http_code']);
        }

        $headerSize = $info['header_size'];
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        $result = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AlmaApiException(
                'Failed to decode API response: ' . json_last_error_msg(),
                $info['http_code'],
                $responseBody
            );
        }

        if ($info['http_code'] >= 400) {
            $errorMessage = $result['message'] ?? $result['error'] ?? 'Unknown API error';
            throw new AlmaApiException($errorMessage, $info['http_code'], $result);
        }

        return $result;
    }

    private function logRequest(
        string $method,
        string $url,
        array $data,
        array $headers,
               $response,
        array $info,
        string $error
    ): void {
        $log = [
            'date' => date('Y-m-d H:i:s'),
            'method' => $method,
            'url' => $url,
            'request_data' => $data,
            'request_headers' => $headers,
            'response_info' => $info,
            'response' => $response,
            'error' => $error,
        ];

        file_put_contents(
            __DIR__ . '/alma_api_debug.log',
            json_encode($log, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
    }

    public function getApartment($id, $byExternalId = false): array
    {
        $endpoint = $byExternalId
            ? "external_api/realty/units/external_id/{$id}/"
            : "external_api/realty/units/{$id}/";
        return $this->call('GET', $endpoint);
    }

    public function createApartment(array $data): array
    {
        return $this->call('POST', 'external_api/realty/units/', $data);
    }

    public function updateApartment($id, array $data, $byExternalId = false): array
    {
        $endpoint = $byExternalId
            ? "external_api/realty/units/external_id/{$id}/"
            : "external_api/realty/units/{$id}/";
        return $this->call('PATCH', $endpoint, $data);
    }

    public function archiveApartment($id, $isArchived, $byExternalId = false): array
    {
        $endpoint = $byExternalId
            ? "external_api/realty/units/external_id/{$id}/archive/"
            : "external_api/realty/units/{$id}/archive/";
        return $this->call('PATCH', $endpoint, ['is_archived' => $isArchived]);
    }
}

class AlmaApiException extends Exception
{
    private $responseData;

    public function __construct(string $message, int $code = 0, $responseData = null, Throwable $previous = null)
    {
        $this->responseData = $responseData;
        parent::__construct($message, $code, $previous);
    }

    public function getResponseData()
    {
        return $this->responseData;
    }
}