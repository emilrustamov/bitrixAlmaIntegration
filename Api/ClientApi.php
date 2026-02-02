<?php
require_once __DIR__ . '/BaseApi.php';

class ClientApi {
    private $apiKey;
    private $apiUrl;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
    }

    public function ensureClientExists(array $clientData) {
        if (empty($clientData['id'])) {
            throw new InvalidArgumentException('Client external id is required');
        }

        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = BaseApi::sendRequest($this->apiKey, 'GET', $url);
            return $client['id'];
        } catch (Exception $e) {
            $url = $this->apiUrl . 'users/clients/';

            $payload = [
                'external_id' => $clientData['id'],
                'first_name'  => $clientData['first_name'] ?? '',
                'last_name'   => $clientData['last_name'] ?? '',
                'phone'       => $clientData['phone'] ?? '',
                'country'     => $clientData['country'] ?? 4,
                'birthday'    => '1999-06-03T00:00:00Z',
            ];

            if (!empty($clientData['email'])) {
                $payload['email'] = $clientData['email'];
            }

            $newClient = BaseApi::sendRequest($this->apiKey, 'POST', $url, $payload);
            return $newClient['id'];
        }
    }

    public function updateClient(array $clientData) {
        if (empty($clientData['id'])) {
            throw new InvalidArgumentException('Client external id is required');
        }

        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $existingClient = BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
            
            if ($existingClient['code'] !== 200 || !isset($existingClient['response']['id'])) {
                return $this->ensureClientExists($clientData);
            }

            $almaClientId = $existingClient['response']['id'];
            $updateUrl = $this->apiUrl . 'realty/clients/external_id/' . $clientData['id'] . '/';

            $payload = [];
            
            if (isset($clientData['first_name'])) {
                $payload['first_name'] = $clientData['first_name'];
            }
            if (isset($clientData['last_name'])) {
                $payload['last_name'] = $clientData['last_name'];
            }
            if (isset($clientData['phone'])) {
                $payload['phone'] = $clientData['phone'];
            }
            if (isset($clientData['email'])) {
                $payload['email'] = $clientData['email'];
            }

            if (!empty($payload)) {
                BaseApi::sendRequest($this->apiKey, 'PATCH', $updateUrl, $payload);
            }

            return $almaClientId;
        } catch (Exception $e) {
            return $this->ensureClientExists($clientData);
        }
    }
}


