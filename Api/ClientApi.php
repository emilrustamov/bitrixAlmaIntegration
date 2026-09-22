<?php

require_once __DIR__ . '/BaseApi.php';

class ClientApi {
    private $apiKey;
    private $apiUrl;

    /**
     * @param string $apiKey
     * @param string $apiUrl
     */
    public function __construct($apiKey, $apiUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
    }

    /**
     * @param array $clientData
     * @param bool $sendInvitation
     * @return string
     */
    public function updateClient(array $clientData, $sendInvitation = true) {
        $id = $this->ensureClientExists($clientData, $sendInvitation);
        $this->patchClient($id, $clientData);
        return $id;
    }

    /**
     * @param array $clientData
     * @return string|null Alma client id, or null if client does not exist
     */
    public function updateExistingClient(array $clientData): ?string
    {
        if (empty($clientData['id'])) {
            throw new InvalidArgumentException('Client external id is required');
        }
        $existing = $this->getClientByExternalId($clientData['id']);
        if ($existing === null || empty($existing['id'])) {
            return null;
        }
        $almaId = (string) $existing['id'];
        $this->patchClient($almaId, $clientData);
        return $almaId;
    }

    /**
     * @param string $almaId
     * @param array $clientData
     * @return void
     */
    private function patchClient($almaId, array $clientData): void
    {
        $payload = array_intersect_key($clientData, array_flip([
            'first_name', 'last_name', 'phone', 'email', 'passport', 'passport_scan', 'id_scan', 'country',
        ]));
        if ($payload === []) {
            return;
        }
        BaseApi::sendRequest(
            $this->apiKey,
            'PATCH',
            $this->apiUrl . 'users/clients/' . $almaId . '/',
            $payload
        );
    }

    /**
     * @param string|int $externalId
     * @return array|null
     */
    public function getClientByExternalId($externalId): ?array
    {
        $externalId = (string) $externalId;
        $realtyUrl = $this->apiUrl . 'realty/clients/external_id/' . rawurlencode($externalId) . '/';
        $realtyRes = BaseApi::sendRequest($this->apiKey, 'GET', $realtyUrl, null, false);
        if (($realtyRes['code'] ?? 0) === 200 && is_array($realtyRes['response'] ?? null)) {
            return $realtyRes['response'];
        }
        $usersUrl = $this->apiUrl . 'users/clients/external_id/' . rawurlencode($externalId) . '/';
        $usersRes = BaseApi::sendRequest($this->apiKey, 'GET', $usersUrl, null, false);
        if (($usersRes['code'] ?? 0) === 200 && is_array($usersRes['response'] ?? null)) {
            return $usersRes['response'];
        }
        return null;
    }

    /**
     * @param array $clientData
     * @param bool $sendInvitation
     * @return string
     */
    public function ensureClientExists(array $clientData, $sendInvitation = true) {
        if (empty($clientData['id'])) {
            throw new InvalidArgumentException('Client external id is required');
        }
        $getUrl = $this->apiUrl . 'users/clients/external_id/' . (string) $clientData['id'] . '/';
        $res = BaseApi::sendRequest($this->apiKey, 'GET', $getUrl, null, false);
        if (($res['code'] ?? 0) === 200 && isset($res['response']['id'])) {
            return (string) $res['response']['id'];
        }
        $payload = $this->buildCreateClientPayload($clientData, $sendInvitation);
        $newClient = BaseApi::sendRequest($this->apiKey, 'POST', $this->apiUrl . 'users/clients/', $payload);
        return (string) $newClient['id'];
    }

    /**
     * @param array $clientData
     * @param bool $sendInvitation
     * @return array
     */
    private function buildCreateClientPayload(array $clientData, $sendInvitation) {
        $payload = [
            'external_id' => $clientData['id'],
            'first_name'  => $clientData['first_name'] ?? '',
            'last_name'   => $clientData['last_name'] ?? '',
            'phone'       => $clientData['phone'] ?? '',
            'country'     => $clientData['country'] ?? 4,
            'birthday'    => '1999-06-03T00:00:00Z',
            'is_send_password_when_creating' => $sendInvitation,
        ];
        if (!empty($clientData['email'])) {
            $payload['email'] = $clientData['email'];
        }
        if (!empty($clientData['passport'])) {
            $payload['passport'] = $clientData['passport'];
        }
        return $payload;
    }
}


