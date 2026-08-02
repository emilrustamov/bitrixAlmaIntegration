<?php

require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Config/Config.php';
require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/BaseApi.php';
require_once __DIR__ . '/SyncHelpers.php';

Config::load();

class UnitApi {
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
     * @param string|int $bitrixId
     * @return array
     */
    public function getRoomByExternalId($bitrixId) {
        $url = rtrim($this->apiUrl, '/') . '/realty/rooms/external_id/' . rawurlencode((string)$bitrixId) . '/';
        return BaseApi::sendRequest($this->apiKey, 'GET', $url, null, false);
    }

    /**
     * @param array $data
     * @param string|int $bitrixId
     * @return array
     */
    public function upsertRoom($data, $bitrixId) {
        $rentalObject = $this->getRoomByExternalId($bitrixId);

        if (($rentalObject['code'] ?? 0) === 200 && !empty($rentalObject['response']['id'])) {
            $patchData = $data;
            if (isset($patchData['parent_unit']) && is_array($patchData['parent_unit'])) {
                $patchData['parent_unit'] = $patchData['parent_unit']['id'] ?? null;
            }
            unset($patchData['external_id']);
            unset($patchData['property_type']);

            if (empty($patchData['name'])) {
                $patchData['name'] = $data['name'] ?? ('Room ' . $bitrixId);
            }

            $response = BaseApi::callRealty($this->apiKey, $this->apiUrl, 'PATCH', 'rooms/' . $rentalObject['response']['id'] . '/', $patchData, true);
            $response['method'] = 'PATCH';
            return $response;
        }

        $response = BaseApi::callRealty($this->apiKey, $this->apiUrl, 'POST', 'rooms/', $data, true);
        $response['method'] = 'POST';

        return $response;
    }
}

/**
 * @param string|int $roomId
 * @param string $projectName
 * @return array
 */
function syncUnitById($roomId, $projectName)
{
    Config::load();
    Logger::setProject($projectName);
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $webhookUrl = $projectConfig['webhook_url'];
    $apiKey = Config::get('ALMA_API_KEY');
    $apiUrl = Config::get('ALMA_API_URL');

    $bitrix = new Bitrix24Rest($webhookUrl);
    $unitApi = new UnitApi($apiKey, $apiUrl);

    $unitEntityTypeId = ProjectMapping::getUnitEntityTypeId($projectName);
    $apartmentField = ProjectMapping::getUnitApartmentField($projectName);

    $bitrixRoom = $bitrix->call('crm.item.get', [
        'entityTypeId' => $unitEntityTypeId,
        'id' => $roomId,
    ]);

    if (!isset($bitrixRoom['result']['item'])) {
        throw new RuntimeException('Failed to get room data from Bitrix24');
    }

    $roomData = $bitrixRoom['result']['item'];
    $apartmentId = $roomData[$apartmentField] ?? null;
    if (is_array($apartmentId)) {
        $apartmentId = $apartmentId[0] ?? null;
    }
    if (empty($apartmentId)) {
        throw new InvalidArgumentException('Apartment ID not found in room');
    }

    $apartmentEntityTypeIds = ProjectMapping::getApartmentEntityTypeIds($projectName);
    $apartmentEntityTypeId = $apartmentEntityTypeIds[0] ?? null;
    if ($apartmentEntityTypeId === null) {
        throw new RuntimeException('Apartment entity type id is not configured for project ' . $projectName);
    }
    $apartmentRes = $bitrix->call('crm.item.get', [
        'entityTypeId' => $apartmentEntityTypeId,
        'id' => $apartmentId,
    ]);
    if (!isset($apartmentRes['result']['item'])) {
        throw new RuntimeException('Failed to get apartment data from Bitrix24 for room sync');
    }
    $apartmentData = $apartmentRes['result']['item'];
    $rentType = ProjectMapping::resolveRentType($apartmentData, $projectName);
    if ($rentType !== 'rooms') {
        throw new InvalidArgumentException(
            'Room synchronization is allowed only for apartments with rent type "rooms". ' .
            'Current rent type: ' . $rentType
        );
    }

    $existingRoom = $unitApi->getRoomByExternalId($roomId);
    $parentUnitId = null;
    if (($existingRoom['code'] ?? 0) === 200 && !empty($existingRoom['response']['id'])) {
        $p = $existingRoom['response']['parent_unit'] ?? null;
        $parentUnitId = (is_array($p) && isset($p['id'])) ? $p['id'] : (is_numeric($p) ? $p : null);
    } else {
        $apartmentUnit = BaseApi::fetchUnitByExternalId($apiKey, $apiUrl, (string) $apartmentId);
        if (($apartmentUnit['code'] ?? 0) !== 200 || empty($apartmentUnit['response']['id'])) {
            throw new RuntimeException("Apartment $apartmentId not found in Alma. Please sync apartment first.");
        }
        $parentUnitId = $apartmentUnit['response']['id'];
    }

    $data = mapBitrixRoomToAlmaPayload($roomData, $projectName, $parentUnitId);
    $externalId = $data['external_id'];
    $response = $unitApi->upsertRoom($data, $externalId);

    if (isset($response['response']['id']) || (isset($response['code']) && $response['code'] >= 200 && $response['code'] < 300)) {
        return [
            'code' => $response['code'] ?? 200,
            'response' => $response['response'] ?? $response,
            'method' => $response['method'] ?? 'unknown',
        ];
    }

    Logger::error('Failed to process room', ['response' => $response], 'room', $roomId);
    throw new RuntimeException('Failed to process room (Method: ' . ($response['method'] ?? 'unknown') . '): ' . json_encode($response));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        BaseApi::sendSuccessResponse(syncUnitById($_GET['id'], $projectName));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: Room synchronization failed', 'room', $_GET['id'] ?? null);
    }
    exit;
}
