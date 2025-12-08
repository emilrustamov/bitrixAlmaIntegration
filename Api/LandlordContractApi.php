<?php
set_time_limit(60);
ini_set('max_execution_time', 60);

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/ClientApi.php';
require_once(__DIR__ . '/BaseApi.php');

Config::load();

$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);
Logger::setProject($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);

class LandlordContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $bitrix;
    private $clientApi;

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new LandlordContractActionLogger();
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->clientApi = new ClientApi($apiKey, $apiUrl);
    }

    public function syncContract(array $bitrixData) {
        $landlordId = $this->ensureLandlordExists($bitrixData['landlord_data']);
        $apartmentId = $this->findApartmentInAlma($bitrixData['apartment_id']);
        
        $contractData = $this->prepareContractData($bitrixData, $landlordId, $apartmentId);
        
        $existingContract = $this->getContractByExternalId($contractData['external_id']);
        
        if ($existingContract && !($existingContract['unit_usage']['is_archived'] ?? false)) {
            return $this->updateContract($existingContract['id'], $contractData);
        } else {
            return $this->createContract($contractData);
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/owner_contracts/';
        $response = $this->sendRequest('POST', $url, $contractData);
        
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
        $response = $this->sendRequest('PATCH', $url, $patchData);
        
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
            return $this->sendRequest('GET', $url);
        } catch (Exception $e) {
            return null;
        }
    }


    public function ensureLandlordExists(array $landlordData) {
        return $this->clientApi->ensureClientExists($landlordData);
    }

    private function formatDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Date is required");
        }
        
        $date = DateTime::createFromFormat('Y-m-d', $dateStr) ?: new DateTime($dateStr);
        return $date->format('Y-m-d\T00:00:00\Z');
    }

    private function prepareContractData(array $bitrixData, $landlordId, $apartmentId) {
        $startDate = $this->formatDate($bitrixData['ufCrm10_1693823247516']);
        $endDate = $this->formatDate($bitrixData['ufCrm10_1693823282826']);
        
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $apartmentId,
            'client_id' => $landlordId,
            'name' => $bitrixData['title'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'work_model' => $this->mapWorkModel($bitrixData['ufCrm10_1708955821'] ?? ''),
        ];

        $contractScan = $this->uploadFile($bitrixData['ufCrm10_1709042143']['urlMachine'] ?? null);
        if ($contractScan) {
            $contractData['contract_with_client_scan'] = $contractScan;
        }

        $titleDeedScan = $this->uploadFile($bitrixData['ufCrm10_1694000636731']['urlMachine'] ?? null);
        if ($titleDeedScan) {
            $contractData['property_title_deed_scan'] = $titleDeedScan;
        }

        $pmlScan = $this->uploadFile($bitrixData['ufCrm10_1694000391518']['urlMachine'] ?? null);
        if ($pmlScan) {
            $contractData['property_management_letter_scan'] = $pmlScan;
        }

        $dtcmScan = $this->uploadFile($bitrixData['ufCrm10_1694000558852']['urlMachine'] ?? null);
        if ($dtcmScan) {
            $contractData['dtcm_permit_scan'] = $dtcmScan;
        }

        if (!empty($bitrixData['ufCrm10_1708956056'])) {
            $contractData['pml_start_date'] = $this->formatDate($bitrixData['ufCrm10_1708956056']);
        }
        if (!empty($bitrixData['ufCrm10_1708955996'])) {
            $contractData['pml_end_date'] = $this->formatDate($bitrixData['ufCrm10_1708955996']);
        }

        return $contractData;
    }

    private function mapWorkModel($bitrixType) {
        $mappedType = ProjectMapping::mapWorkModel($bitrixType, $GLOBALS['projectName']);
        if ($mappedType === null) {
            throw new InvalidArgumentException("Unknown work model type: $bitrixType");
        }
        return $mappedType;
    }

    private function findApartmentInAlma($apartmentBitrixId) {
        $url = $this->apiUrl . 'realty/units/external_id/' . $apartmentBitrixId . '/';
        $response = $this->sendRequest('GET', $url);
        return $response['id'];
    }

    private function uploadFile($fileUrl) {
        if (empty($fileUrl)) {
            return null;
        }

        $uploadUrl = $this->apiUrl . 'external-image/';
        $fileData = [
            'url' => $fileUrl,
            'description' => 'Landlord contract document'
        ];

        try {
            $response = $this->sendRequest('POST', $uploadUrl, $fileData);
            return $response['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function sendRequest($method, $url, $data = null) {
        return BaseApi::sendRequest($this->apiKey, $method, $url, $data, true);
    }
    
}

try {
    $almaApi = new LandlordContractApi();
    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $GLOBALS['projectName'] = $projectName;

    $contractResponse = $bitrix->call('crm.item.get', [
        'entityTypeId' => 148,
        'id' => $_GET['id'],
    ]);
    $contractData = $contractResponse['result']['item'];

    $contactResponse = $bitrix->call('crm.contact.get', ['id' => $contractData['contactId']]);
    $contact = $contactResponse['result'];

    $syncData = [
        'id' => $contractData['id'],
        'title' => $contractData['title'],
        'apartment_id' => $contractData['ufCrm10_1693823301'] ?? '',
        'landlord_data' => [
            'id' => $contact['ID'],
            'first_name' => $contact['NAME'],
            'last_name' => $contact['LAST_NAME'],
            'email' => $contact['UF_CRM_1727788747'] ?? '',
            'phone' => $contact['PHONE'][0]['VALUE'] ?? '',
        ],
        'ufCrm10_1693823247516' => $contractData['ufCrm10_1693823247516'] ?? '',
        'ufCrm10_1693823282826' => $contractData['ufCrm10_1693823282826'] ?? '',
        'ufCrm10_1694000435068' => $contractData['ufCrm10_1694000435068'] ?? null,
        'ufCrm10_1694000558852' => $contractData['ufCrm10_1694000558852'] ?? null,
        'ufCrm10_1694000391518' => $contractData['ufCrm10_1694000391518'] ?? null,
        'ufCrm10_1694000636731' => $contractData['ufCrm10_1694000636731'] ?? null,
        'ufCrm10_1709042143' => $contractData['ufCrm10_1709042143'] ?? null,
        'ufCrm10_1708955821' => $contractData['ufCrm10_1708955821'] ?? '',
        'ufCrm10_1708956056' => $contractData['ufCrm10_1708956056'] ?? '',
        'ufCrm10_1708955996' => $contractData['ufCrm10_1708955996'] ?? '',
    ];

    $result = $almaApi->syncContract($syncData);
    $almaId = $result['response']['id'] ?? null;
    $method = $result['method'] ?? 'unknown';
    
    Logger::info('Landlord contract sync succeeded', [
        'contract_id' => $_GET['id'],
        'alma_id' => $almaId,
        'method' => $method
    ], 'landlord_contract', $_GET['id']);
    
    echo json_encode([
        'success' => true,
        'alma_id' => $almaId,
        'method' => $method,
        'operation' => $method === 'POST' ? 'created' : 'updated',
        'data' => $result['response'] ?? $result
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    Logger::error("Unexpected error in landlord contract synchronization: " . $e->getMessage(), [
        'exception_type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'landlord_contract', $_GET['id'] ?? 'unknown');
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: Landlord contract synchronization failed'
    ]);
}
