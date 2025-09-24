<?php

require_once(__DIR__ . '/BaseApiClient.php');

class Bitrix24ApiClient extends BaseApiClient
{
    private $webhookUrl;

    public function __construct($webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function call($method, $params = [])
    {
        $url = $this->webhookUrl . $method;
        $query = http_build_query($params);
        
        if (!empty($query)) {
            $url .= '?' . $query;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        Logger::logApiRequest('GET', $url, $params, $response, $httpCode);

        return json_decode($response, true);
    }

    public function getApartment($entityTypeId, $id)
    {
        return $this->call('crm.item.get', [
            'entityTypeId' => $entityTypeId,
            'id' => $id
        ]);
    }

    public function getContact($id)
    {
        return $this->call('crm.contact.get', ['id' => $id]);
    }

    public function getContract($entityTypeId, $id)
    {
        return $this->call('crm.item.get', [
            'entityTypeId' => $entityTypeId,
            'id' => $id
        ]);
    }
}

