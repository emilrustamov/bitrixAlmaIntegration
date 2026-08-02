<?php

set_time_limit(60);
ini_set('max_execution_time', 60);

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';
require_once __DIR__ . '/BaseApi.php';

Config::load();

class LandlordContractApi {
    private $apiKey;
    private $apiUrl;
    private $projectName;
    private $actionLogger;
    private $clientApi;

    public function __construct($apiKey, $apiUrl, $projectName) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->projectName = $projectName;
        $this->actionLogger = new LandlordContractActionLogger();
        $this->clientApi = new ClientApi($apiKey, $apiUrl);
    }

    public function syncContract(array $bitrixData) {
        $apartmentIdField = ProjectMapping::getLandlordContractField('apartment_id', $this->projectName);
        $apartmentBitrixId = $apartmentIdField ? ($bitrixData[$apartmentIdField] ?? '') : '';
        $landlordId = $this->ensureLandlordExists($bitrixData['landlord_data']);
        $apartmentId = BaseApi::getUnitIdByAptExternalId($this->apiKey, $this->apiUrl, $apartmentBitrixId);
        
        $contractData = $this->buildContractData($bitrixData, $landlordId, $apartmentId);
        
        $existingContract = $this->getContractByExternalId($contractData['external_id']);
        
        if ($existingContract && !($existingContract['unit_usage']['is_archived'] ?? false)) {
            return $this->updateContract($existingContract['id'], $contractData);
        } else {
            return $this->createContract($contractData);
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/owner_contracts/';
        $response = $this->request('POST', $url, $contractData);
        
        $this->actionLogger->logContractCreation(
            $response['id'],
            $contractData['name'] ?? '',
            $contractData['client_id'] ?? '',
            $contractData['unit_id'] ?? '',
            [
                'external_id' => $contractData['external_id'] ?? '',
                'start_date' => $contractData['start_date'] ?? '',
                'end_date' => $contractData['end_date'] ?? '',
                'work_model' => $contractData['work_model'] ?? ''
            ]
        );
        
        return ['code' => 201, 'response' => $response, 'method' => 'POST'];
    }

    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContractByExternalId($contractData['external_id']);
        $patchData = $contractData;
        unset($patchData['client_id']);
        
        $url = $this->apiUrl . 'realty/contracts/owner_contracts/' . $contractId . '/';
        $response = $this->request('PATCH', $url, $patchData);
        
        $this->actionLogger->logUpdate(
            $contractId,
            $contractData['name'] ?? '',
            $oldContractData,
            $patchData
        );

        return ['code' => 200, 'response' => $response, 'method' => 'PATCH'];
    }

    public function getContractByExternalId($externalId) {
        try {
            $url = $this->apiUrl . 'realty/contracts/owner_contracts/external_id/' . $externalId . '/';
            return $this->request('GET', $url);
        } catch (Exception $e) {
            return null;
        }
    }


    public function ensureLandlordExists(array $landlordData) {
        $this->clientApi->ensureClientExists($landlordData, false);
        return $this->clientApi->updateClient($landlordData, false);
    }

    private function buildContractData(array $bitrixData, $landlordId, $apartmentId) {
        $startDateField = ProjectMapping::getLandlordContractField('start_date', $this->projectName);
        $endDateField = ProjectMapping::getLandlordContractField('end_date', $this->projectName);
        $workModelField = ProjectMapping::getLandlordContractField('work_model', $this->projectName);
        $contractScanField = ProjectMapping::getLandlordContractField('contract_scan', $this->projectName);
        $titleDeedField = ProjectMapping::getLandlordContractField('title_deed_scan', $this->projectName);
        $pmlScanField = ProjectMapping::getLandlordContractField('pml_scan', $this->projectName);
        $dtcmScanField = ProjectMapping::getLandlordContractField('dtcm_scan', $this->projectName);
        $pmlStartField = ProjectMapping::getLandlordContractField('pml_start_date', $this->projectName);
        $pmlEndField = ProjectMapping::getLandlordContractField('pml_end_date', $this->projectName);

        $startRaw = $startDateField ? ($bitrixData[$startDateField] ?? '') : '';
        $endRaw = $endDateField ? ($bitrixData[$endDateField] ?? '') : '';
        if (empty($startRaw) || empty($endRaw)) {
            throw new InvalidArgumentException('Landlord contract start_date and end_date are required');
        }
        $startDate = BaseApi::formatDateForAlma($startRaw);
        $endDate = BaseApi::formatDateForAlma($endRaw);

        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $apartmentId,
            'client_id' => $landlordId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'work_model' => $this->mapWork($workModelField ? ($bitrixData[$workModelField] ?? '') : ''),
        ];

        $scanFields = [
            $contractScanField => 'contract_with_client_scan',
            $titleDeedField => 'property_title_deed_scan',
            $pmlScanField => 'property_management_letter_scan',
            $dtcmScanField => 'dtcm_permit_scan',
        ];
        foreach ($scanFields as $field => $almaKey) {
            if (!$field) {
                continue;
            }
            $url = is_array($bitrixData[$field] ?? null) ? ($bitrixData[$field]['urlMachine'] ?? null) : ($bitrixData[$field] ?? null);
            $scanId = BaseApi::uploadImage($this->apiKey, $this->apiUrl, $url, 'Landlord contract document');
            if ($scanId) {
                $contractData[$almaKey] = $scanId;
            }
        }
        if ($pmlStartField && !empty($bitrixData[$pmlStartField])) {
            $contractData['pml_start_date'] = BaseApi::formatDateForAlma($bitrixData[$pmlStartField]);
        }
        if ($pmlEndField && !empty($bitrixData[$pmlEndField])) {
            $contractData['pml_end_date'] = BaseApi::formatDateForAlma($bitrixData[$pmlEndField]);
        }

        return $contractData;
    }

    private function mapWork($bitrixType) {
        $mappedType = ProjectMapping::mapWorkModel($bitrixType, $this->projectName);
        if ($mappedType === null) {
            throw new InvalidArgumentException("Unknown work model type: $bitrixType");
        }
        return $mappedType;
    }

    private function request($method, $url, $data = null) {
        return BaseApi::sendRequest($this->apiKey, $method, $url, $data, true);
    }
    
}

function syncLandlordContract($id, $projectName) {
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $entityTypeId = ProjectMapping::getLandlordContractEntityTypeId($projectName);
    if (!$entityTypeId) {
        throw new InvalidArgumentException("Landlord contracts not supported for project: $projectName");
    }
    $bitrix = new Bitrix24Rest($webhookUrl);
    $almaApi = new LandlordContractApi(
        Config::get('ALMA_API_KEY'),
        Config::get('ALMA_API_URL'),
        $projectName
    );
    $contractResponse = $bitrix->call('crm.item.get', [
        'entityTypeId' => $entityTypeId,
        'id' => $id,
    ]);
    $contractData = $contractResponse['result']['item'] ?? null;
    if (!$contractData) {
        throw new RuntimeException('Failed to get landlord contract from Bitrix24');
    }
    $contactIdField = ProjectMapping::getLandlordContractField('contact_id', $projectName);
    $contactId = $contractData[$contactIdField] ?? $contractData['contactId'] ?? null;
    if (!$contactId) {
        throw new InvalidArgumentException('Contact ID not found in landlord contract');
    }
    $contactResponse = $bitrix->call('crm.contact.get', ['id' => $contactId]);
    $contact = $contactResponse['result'] ?? null;
    if (!$contact) {
        throw new RuntimeException('Failed to get contact from Bitrix24');
    }
    $emailField = ProjectMapping::getLandlordField('email', $projectName);
    $landlordData = [
        'id' => $contact['ID'],
        'first_name' => $contact['NAME'],
        'last_name' => $contact['LAST_NAME'],
        'email' => $contact[$emailField] ?? (is_array($contact['EMAIL'] ?? null) ? ($contact['EMAIL'][0]['VALUE'] ?? '') : ''),
        'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
    ];

    if ($projectName === 'Dubai') {
        $passport = ProjectMapping::getBitrixItemValue(
            $contractData,
            ProjectMapping::getLandlordField('passport_number', $projectName)
        );
        if ($passport !== null && trim((string) $passport) !== '') {
            $landlordData['passport'] = trim((string) $passport);
        }
        $scanId = BaseApi::uploadImage(
            Config::get('ALMA_API_KEY'),
            Config::get('ALMA_API_URL'),
            ProjectMapping::getBitrixItemValue($contractData, ProjectMapping::getLandlordField('id_scan', $projectName)),
            'ID scan'
        );
        if ($scanId) {
            $landlordData['id_scan'] = $scanId;
        }
    }

    $syncData = array_merge($contractData, [
        'landlord_data' => $landlordData,
    ]);
    $result = $almaApi->syncContract($syncData);
    Logger::info('Landlord contract sync succeeded', [
        'contract_id' => $id,
        'alma_id' => $result['response']['id'] ?? null,
        'method' => $result['method'] ?? 'unknown'
    ], 'landlord_contract', $id);
    return [
        'code' => $result['code'],
        'response' => $result['response'] ?? $result,
        'method' => $result['method'] ?? 'unknown'
    ];
}

if (isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        BaseApi::sendSuccessResponse(syncLandlordContract($_GET['id'], $projectName));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: Landlord contract synchronization failed', 'landlord_contract', $_GET['id'] ?? null);
    }
    exit;
}

