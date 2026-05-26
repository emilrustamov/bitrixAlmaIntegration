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


class TenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $projectName;
    private $actionLogger;
    private $bitrix;
    private $clientApi;

    public function __construct($apiKey, $apiUrl, $projectName, $webhookUrl) {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/') . '/';
        $this->projectName = $projectName;
        $this->actionLogger = new ContractActionLogger();
        $this->bitrix = new Bitrix24Rest($webhookUrl);
        $this->clientApi = new ClientApi($apiKey, $apiUrl);
    }

    public function syncContract(array $bitrixData) {
        if ($this->shouldArchive($bitrixData, $this->projectName)) {
            return $this->archiveContractByExternalId(
                $bitrixData['id'] ?? $bitrixData['external_id'] ?? $bitrixData['ID'] ?? null
            );
        }
        
        $contractType = $this->requireContractType($bitrixData, $this->projectName);
        $clientId = isset($bitrixData['client_id'])
            ? (string) $bitrixData['client_id']
            : $this->ensureClientExists($bitrixData['client_data'], $contractType);
        $unitId = $this->findAlmaObjectById($bitrixData['unit_external_id']);
        
        $contractData = $this->buildContractData($bitrixData, $clientId, $unitId);
        
        $unitBitrixId = $bitrixData['unit_external_id'] ?? null;
        if ($unitBitrixId !== null && $unitBitrixId !== '') {
            $terminationDate = $this->getTerminationDate((string) $unitBitrixId);
            if ($terminationDate !== null) {
                $contractEnd = substr($contractData['end_date'], 0, 10);
                if ($contractEnd > $terminationDate) {
                    throw new InvalidArgumentException(
                        'Contract end date must not be after apartment termination date (' . $terminationDate . ')'
                    );
                }
            }
        }
        
        $existing = $this->getContractByExternalId($contractData['external_id']);
        
        if ($existing && !($existing['unit_usage']['is_archived'] ?? false)) {
            return $this->updateContract($existing['id'], $contractData);
        }
        return $this->createContract($contractData);
    }

    public function createContractOnly(array $bitrixData) {
        $contractType = $this->requireContractType($bitrixData, $this->projectName);
        $clientId = isset($bitrixData['client_id'])
            ? (string) $bitrixData['client_id']
            : $this->ensureClientExists($bitrixData['client_data'], $contractType);
        $unitId = $this->resolveUnitId($bitrixData);
        $contractData = $this->buildContractData($bitrixData, $clientId, $unitId);
        return $this->createContract($contractData);
    }

    public function upsertBlockingContract(array $syncData) {
        $externalId = $syncData['id'] ?? null;
        if ($externalId === null || $externalId === '') {
            return $this->createContractOnly($syncData);
        }
        $existing = $this->getContractByExternalId($externalId);
        if ($existing && !($existing['unit_usage']['is_archived'] ?? false)) {
            $unitId = $this->resolveUnitId($syncData);
            $contractData = $this->buildContractData($syncData, (string) $syncData['client_id'], $unitId);
            return $this->updateContract($existing['id'], $contractData);
        }
        return $this->createContractOnly($syncData);
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
        
        try {
            $response = $this->request('POST', $url, $contractData);
            $response['paying_party'] = $contractData['paying_party'] ?? '';
            $this->actionLogger->logContractCreation(
                $response['id'],
                $contractData['name'] ?? '',
                $contractData['client_id'] ?? '',
                $contractData['unit_id'] ?? '',
                [
                    'external_id' => $contractData['external_id'] ?? '',
                    'start_date' => $contractData['start_date'] ?? '',
                    'end_date' => $contractData['end_date'] ?? '',
                    'price' => $contractData['price'] ?? ''
                ]
            );
            return ['code' => 201, 'response' => $response, 'method' => 'POST'];
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'intersection')) {
                $existing = $this->getContractByExternalId($contractData['external_id']);
                if ($existing) {
                    Logger::info('Contract already exists, updating instead of creating', [
                        'external_id' => $contractData['external_id'],
                        'alma_id' => $existing['id'] ?? null
                    ], 'contract', $contractData['external_id']);
                    return $this->updateContract($existing['id'], $contractData);
                }
            }
            throw $e;
        }
    }


    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContractByExternalId($contractData['external_id']);
        $patchData = $contractData;
        unset($patchData['client_id']);
        
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        $response = $this->request('PATCH', $url, $patchData);
        $response['paying_party'] = $contractData['paying_party'] ?? '';
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
            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/';
            return $this->request('GET', $url);
        } catch (Exception $e) {
            return null;
        }
    }

    public function ensureClientExists(array $clientData, $contractType = '') {
        $isAirbnb = ProjectMapping::getAirbnbContractTypeId($this->projectName) === (string) $contractType;
        
        if (empty($clientData['email']) && $isAirbnb) {
            $clientData['email'] = $this->buildAirbnbEmail($clientData['first_name'] ?? '', $clientData['last_name'] ?? '');
        }
        
        if ($isAirbnb && !empty($clientData['last_name'])) {
            $lastName = trim($clientData['last_name']);
            $suffix = ' airbnb';
            if (!str_ends_with($lastName, $suffix)) {
                $clientData['last_name'] = $lastName . $suffix;
            }
        }

        $noInvitationIds = ProjectMapping::getNoInvitationContractTypeIds($this->projectName);
        $sendInvitation = !in_array((string) $contractType, $noInvitationIds, true);
        $this->clientApi->ensureClientExists($clientData, $sendInvitation);
        return $this->clientApi->updateClient($clientData);
    }

    private function buildContractData(array $bitrixData, $clientId, $unitId) {
        $startDateField = ProjectMapping::getContractField('start_date', $this->projectName);
        $endDateField = ProjectMapping::getContractField('end_date', $this->projectName);
        $contractTypeField = ProjectMapping::getContractField('contract_type', $this->projectName);
        $historyField = ProjectMapping::getContractField('history', $this->projectName);
        $contractScanField = ProjectMapping::getContractField('contract_scan', $this->projectName);
        $payingPartyField = ProjectMapping::getContractField('paying_party', $this->projectName);

        $startDate = BaseApi::formatDateForAlma($bitrixData[$startDateField] ?? '');
        $endDate = BaseApi::formatDateForAlma($bitrixData[$endDateField] ?? '');
        
        $contractType = $bitrixData[$contractTypeField] ?? '';
        $typeContract = ProjectMapping::mapContractType($contractType, $this->projectName);
        $isAirbnb = ProjectMapping::getAirbnbContractTypeId($this->projectName) === (string)$contractType;
        $rawPrice = $bitrixData['opportunity'] ?? 0;
        $numericPrice = is_numeric($rawPrice) ? (float)$rawPrice : 0.0;
        $price = $isAirbnb ? '0.00' : number_format($numericPrice, 2, '.', '');
        
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'price' => $price,
            'type_contract' => $typeContract,
            'rent_type' => $typeContract === 'Ejari' ? 'long_term' : 'short_term',
        ];

        if ($historyField && !empty($bitrixData[$historyField])) {
            $history = $bitrixData[$historyField];
            $val = is_array($history) ? implode('; ', array_filter(array_map('trim', $history))) : trim($history);
            if ($val !== '') {
                $contractData['history'] = $val;
            }
        }

        if ($contractScanField && !empty($bitrixData[$contractScanField])) {
            $scanData = $bitrixData[$contractScanField];
            $scanUrl = is_array($scanData) ? ($scanData['urlMachine'] ?? null) : $scanData;
            $contractScan = BaseApi::uploadImage($this->apiKey, $this->apiUrl, $scanUrl, 'Contract document');
            if ($contractScan) {
                $contractData['contract_scan'] = $contractScan;
            }
        }

        if ($payingPartyField) {
            $contractData['paying_party'] = trim((string)($bitrixData[$payingPartyField] ?? ''));
        }

        return $contractData;
    }


    private function buildAirbnbEmail($firstName, $lastName) {
        $first = mb_strtolower(trim($firstName));
        $last = mb_strtolower(trim($lastName));
        
        $firstClean = preg_replace('/[^a-z0-9]/', '', $first);
        $lastClean = preg_replace('/[^a-z0-9]/', '', $last);
        
        if (empty($firstClean) && empty($lastClean)) {
            $username = 'airbnb' . bin2hex(random_bytes(2));
        } elseif (empty($firstClean)) {
            $username = $lastClean;
        } elseif (empty($lastClean)) {
            $username = $firstClean;
        } else {
            $username = $firstClean . '.' . $lastClean;
        }
        
        return "{$username}@airbnb.colife";
    }

    private function request($method, $url, $data = null) {
        return BaseApi::sendRequest($this->apiKey, $method, $url, $data, true);
    }

    private function requireContractType(array $bitrixData, $projectName) {
        $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);
        $contractType = $bitrixData[$contractTypeField] ?? '';
        if (ProjectMapping::mapContractType($contractType, $projectName) === null) {
            throw new InvalidArgumentException("Unknown contract type: $contractType");
        }
        return $contractType;
    }

    private function resolveUnitId(array $syncData) {
        $unitId = $syncData['unit_id'] ?? null;
        if ($unitId !== null && $unitId !== '') {
            return (string) $unitId;
        }
        return $this->findAlmaObjectById($syncData['unit_external_id'] ?? '');
    }

    public function findAlmaObjectById($bitrixId) {
        $url = $this->apiUrl . 'realty/rental_object/' . $bitrixId . '/';
        $result = BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
        $code = $result['code'] ?? 0;
        if ($code === 404) {
            throw new InvalidArgumentException("Unit or rental object (Bitrix ID: $bitrixId) not found in Alma. Sync the apartment or unit first.");
        }
        if ($code >= 400 || empty($result['response']['id'])) {
            throw new RuntimeException($result['response']['detail'] ?? 'Failed to get rental object from Alma', $code ?: 500);
        }
        return $result['response']['id'];
    }

    public function findUnitIdByAptExternalId($apartmentExternalId) {
        return BaseApi::getUnitIdByAptExternalId($this->apiKey, $this->apiUrl, $apartmentExternalId);
    }

    public function getTerminationDate($unitOrApartmentBitrixId) {
        $projectName = $this->projectName;
        $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
        $apartmentTypeIds = ProjectMapping::getApartmentEntityTypeIds($projectName);
        $apartmentEntityTypeId = $apartmentTypeIds[0];
        $apartmentLinkField = ProjectMapping::getUnitApartmentField($projectName);
        $dateField = ProjectMapping::getApartmentField('date_of_refused', $projectName);
        if ($dateField === null) {
            return null;
        }
        $apartmentId = null;
        try {
            $unitItem = $this->bitrix->call('crm.item.get', [
                'entityTypeId' => (int) $unitEntityTypeId,
                'id' => $unitOrApartmentBitrixId,
            ]);
            $item = $unitItem['result']['item'] ?? null;
            if ($item !== null && !empty($item[$apartmentLinkField])) {
                $link = $item[$apartmentLinkField];
                $apartmentId = is_array($link) ? ($link['id'] ?? reset($link)) : $link;
            }
        } catch (Throwable $e) {
        }
        if ($apartmentId === null) {
            try {
                $aptItem = $this->bitrix->call('crm.item.get', [
                    'entityTypeId' => (int) $apartmentEntityTypeId,
                    'id' => $unitOrApartmentBitrixId,
                ]);
                if (!empty($aptItem['result']['item'])) {
                    $apartmentId = $unitOrApartmentBitrixId;
                }
            } catch (Throwable $e) {
                return null;
            }
        }
        if ($apartmentId === null) {
            return null;
        }
        try {
            $apartment = $this->bitrix->call('crm.item.get', [
                'entityTypeId' => (int) $apartmentEntityTypeId,
                'id' => $apartmentId,
            ]);
        } catch (Throwable $e) {
            return null;
        }
        $apt = $apartment['result']['item'] ?? null;
        if ($apt === null) {
            return null;
        }
        $raw = $apt[$dateField] ?? null;
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $dt = date_create_from_format('Y-m-d', trim($raw)) ?: date_create(trim($raw));
        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function shouldArchive(array $bitrixData, $projectName) {
        if ($projectName !== 'Dubai') {
            return false;
        }
        $stageIds = ProjectMapping::getTenantContractArchiveStageIds($projectName);
        if ($stageIds === []) {
            return false;
        }
        $current = $bitrixData['stageId'] ?? null;
        return $current !== null && in_array((string) $current, $stageIds, true);
    }
    
    public function archiveContractByExternalId($externalId) {
        if (!$externalId) {
            throw new InvalidArgumentException('Contract external id is required for archiving');
        }
        
        $response = $this->request(
            'PATCH',
            $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/archive/',
            ['is_archived' => true]
        );
        
        Logger::info('Contract archived', ['external_id' => $externalId], 'contract', $externalId);
        
        return ['code' => 200, 'response' => $response, 'method' => 'ARCHIVE'];
    }

}


function syncTenantContract($id, $projectName) {
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $almaApi = new TenantContractApi(
        Config::get('ALMA_API_KEY'),
        Config::get('ALMA_API_URL'),
        $projectName,
        $webhookUrl
    );
    $bitrix = new Bitrix24Rest($webhookUrl);
    $contractTypeId = ProjectMapping::getContractEntityTypeId($projectName);

    $contractRes = $bitrix->call('crm.item.get', [
        'entityTypeId' => $contractTypeId,
        'id' => $id,
    ]);
    $contractData = $contractRes['result']['item'];

    $contactId = $contractData['contactId'] ?? $contractData['CONTACT_ID'] ?? null;
    $contactRes = $bitrix->call('crm.contact.get', ['id' => $contactId]);
    $contact = $contactRes['result'];

    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $historyField = ProjectMapping::getContractField('history', $projectName);
    $typeField = ProjectMapping::getContractField('contract_type', $projectName);
    $scanField = ProjectMapping::getContractField('contract_scan', $projectName);
    $payerField = ProjectMapping::getContractField('paying_party', $projectName);

    $emailRaw = $contact['EMAIL'] ?? null;
    $email = is_array($emailRaw) ? ($emailRaw[0]['VALUE'] ?? '') : '';

    $syncData = [
        'id' => $contractData['id'] ?? $id,
        'title' => $contractData['title'],
        'unit_external_id' => $contractData[ProjectMapping::getContractField('unit_id', $projectName)] ?? '',
        'stageId' => $contractData['stageId'] ?? $contractData['stage_id'] ?? $contractData['STAGE_ID'] ?? null,
        'client_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $email,
            'phone' => (is_array($contact['PHONE'] ?? null) ? ($contact['PHONE'][0]['VALUE'] ?? '') : ''),
        ],
        'opportunity' => $contractData['opportunity'] ?? 0,
    ];
    
    if ($startDateField) $syncData[$startDateField] = $contractData[$startDateField] ?? '';
    if ($endDateField) $syncData[$endDateField] = $contractData[$endDateField] ?? '';
    if ($historyField) $syncData[$historyField] = $contractData[$historyField] ?? '';
    if ($typeField) $syncData[$typeField] = $contractData[$typeField] ?? '';
    if ($scanField) $syncData[$scanField] = $contractData[$scanField] ?? null;
    if ($payerField) $syncData[$payerField] = $contractData[$payerField] ?? '';

    $result = $almaApi->syncContract($syncData);
    Logger::info('Contract sync succeeded', [
        'contract_id' => $id,
        'alma_id' => $result['response']['id'] ?? null,
        'method' => $result['method'] ?? 'unknown'
    ], 'contract', $id);
    return [
        'code' => $result['code'],
        'response' => $result['response'] ?? $result,
        'method' => $result['method'] ?? 'unknown'
    ];
}

if (!defined('SKIP_TENANT_CONTRACT_ENTRY') && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        BaseApi::sendSuccessResponse(syncTenantContract($_GET['id'], $projectName));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: Contract synchronization failed', 'contract', $_GET['id'] ?? null);
    }
    exit;
}
