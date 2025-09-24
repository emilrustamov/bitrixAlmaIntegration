<?php

require_once(__DIR__ . '/BaseApiClient.php');
require_once(__DIR__ . '/../Config.php');

class AlmaApiClient extends BaseApiClient
{
    private $projectId;

    public function __construct($apiKey, $apiUrl, $projectId = null)
    {
        parent::__construct($apiKey, $apiUrl);
        $this->actionLogger = new ApartmentActionLogger();
        $this->projectId = $projectId ?: Config::get('PROJECT_DUBAI_ID');
    }

    public function getRentalObject($bitrixId)
    {
        $response = $this->sendRequest('GET', $this->apiUrl . 'rental_object/' . $bitrixId . '/');
        
        if ($response['code'] === 200) {
            return $response;
        }
        
        Logger::warning("Rental object not found via rental_object API", ['external_id' => $bitrixId]);
        return $response;
    }

    public function createOrUpdateApartment($data, $bitrixId)
    {
        $rentalObject = $this->getRentalObject($bitrixId);

        if ($rentalObject['code'] === 200) {
            $almaId = $rentalObject['response']['id'];
            $endpoint = 'units/' . $almaId . '/';
            $oldData = $rentalObject['response'];
            
            // Проверяем, не заархивирован ли апартамент
            $unitDetails = $this->sendRequest('GET', $this->apiUrl . $endpoint);
            if ($unitDetails['code'] === 200 && 
                isset($unitDetails['response']['is_archived']) && 
                $unitDetails['response']['is_archived']) {
                
                $unitName = $unitDetails['response']['name'] ?? 'Apartment ' . $bitrixId;
                Logger::warning("Apartment $unitName is archived, unarchiving for update", [], 'apartment', $bitrixId);
                $this->unarchiveApartment($almaId);
            }
            
            $response = $this->sendRequest('PATCH', $this->apiUrl . $endpoint, $data);
            $response['method'] = 'PATCH';
            
            if ($response['code'] === 200) {
                $this->actionLogger->logUpdate(
                    $almaId,
                    $data['name'] ?? 'Apartment ' . $bitrixId,
                    $oldData,
                    $data
                );
            }
        } else {
            $endpoint = 'units/';
            $response = $this->sendRequest('POST', $this->apiUrl . $endpoint, $data);
            $response['method'] = 'POST';
            
            if (isset($response['response']['id'])) {
                $this->actionLogger->logApartmentCreation(
                    $response['response']['id'],
                    $data['name'] ?? 'Apartment ' . $bitrixId,
                    $data['building'] ?? 'Unknown Building',
                    $data['property_type'] ?? 'apartment',
                    [
                        'external_id' => $data['external_id'] ?? $bitrixId,
                        'number' => $data['number'] ?? '',
                        'internal_area' => $data['internal_area'] ?? 0,
                        'goal_rent_cost' => $data['goal_rent_cost'] ?? 0,
                        'address' => $data['address'] ?? '',
                        'number_of_bedrooms' => $data['number_of_bedrooms'] ?? 1,
                        'number_of_baths' => $data['number_of_baths'] ?? 1
                    ]
                );
            }
        }

        return $response;
    }

    private function unarchiveApartment($almaId)
    {
        $url = 'units/' . $almaId . '/archive/';
        $data = ['is_archived' => false];
        return $this->sendRequest('PATCH', $this->apiUrl . $url, $data);
    }

    public function createOrGetBuilding($buildingName)
    {
        $response = $this->sendRequest('GET', $this->apiUrl . 'buildings/');

        if ($response['code'] === 200 && !empty($response['response'])) {
            // Ищем здание по точному названию
            foreach ($response['response'] as $building) {
                if (trim($building['name']) === trim($buildingName)) {
                    $buildingId = $building['id'];
                    return $buildingId;
                }
            }
        }
        
        // Создаем новое здание
        $buildingData = [
            'name' => $buildingName,
            'project' => $this->projectId
        ];
        $response = $this->sendRequest('POST', $this->apiUrl . 'buildings/', $buildingData);
        
        if ($response['code'] === 201 || $response['code'] === 200) {
            $buildingId = $response['response']['id'];
            return $buildingId;
        }
        
        throw new Exception("Failed to create or find building: $buildingName");
    }
}

