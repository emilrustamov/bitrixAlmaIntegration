<?php

require_once __DIR__ . '/../Core/Config/ProjectMapping.php';
require_once __DIR__ . '/../Core/Bitrix24Rest.php';
require_once __DIR__ . '/ListSyncHelpers.php';

const LIST_PROP_ENTITY_ID = 'PROPERTY_1626';
const LIST_PROP_STATUS = 'PROPERTY_1628';
const LIST_PROP_TYPE = 'PROPERTY_1624';
const LIST_PROP_START = 'PROPERTY_1630';
const LIST_PROP_END = 'PROPERTY_1632';
const LIST_PROP_REASON = 'PROPERTY_1636';
const LIST_PROP_ACTIVE_STATUS = 'PROPERTY_1800';

/**
 * @param array $element
 * @param string $projectName
 * @return bool
 */
function isListElementInactive(array $element, $projectName = 'Dubai')
{
    $value = firstListPropValue($element, LIST_PROP_ACTIVE_STATUS);
    $inactiveIds = ProjectMapping::getListInactiveStatusIds($projectName);
    return $value !== null && in_array((string) $value, $inactiveIds, true);
}

/**
 * @param array|null $contract
 * @return bool
 */
function isAlmaContractArchived($contract)
{
    if (!is_array($contract)) {
        return false;
    }
    $status = (string) ($contract['unit_usage']['status'] ?? '');
    return $status === 'archived' || $status === 'canceled';
}

/**
 * @param mixed $value
 * @return string|null
 */
function normalizeListDate($value)
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    $trimmed = trim((string) $value);
    $dt = date_create_from_format('d.m.Y', $trimmed)
        ?: date_create_from_format('Y-m-d', $trimmed)
        ?: date_create($trimmed);
    return $dt ? $dt->format('Y-m-d') : null;
}

/**
 * @param string $projectName
 * @param string|int $listElementId
 * @return array
 */
function fetchListElement($projectName, $listElementId)
{
    $mapping = ProjectMapping::getFieldMapping($projectName);
    $listIblockId = isset($mapping['blocking_list_iblock_id']) ? (int) $mapping['blocking_list_iblock_id'] : 0;
    if ($listIblockId <= 0) {
        throw new RuntimeException('List not configured');
    }
    $projectConfig = ProjectMapping::getProjectConfig($projectName);
    $bitrix = new Bitrix24Rest($projectConfig['webhook_url']);
    $elRes = $bitrix->call('lists.element.get', [
        'IBLOCK_TYPE_ID' => 'lists',
        'IBLOCK_ID' => $listIblockId,
        'ELEMENT_ID' => $listElementId,
    ]);
    $raw = $elRes['result'] ?? [];
    $element = isset($raw['ID']) ? $raw : (is_array($raw) && isset($raw[0]) ? $raw[0] : null);
    if (empty($element)) {
        throw new RuntimeException('List element not found');
    }
    return $element;
}

/**
 * @param array $unitData
 * @param string $projectName
 * @param string|int|null $parentUnitId
 * @return array
 */
function mapBitrixRoomToAlmaPayload(array $unitData, $projectName, $parentUnitId = null)
{
    $unitExternalId = $unitData['id'] ?? null;
    $name = $unitData['title'] ?? ('Room ' . $unitExternalId);

    $unitInternalAreaField = ProjectMapping::getUnitField('internal_area', $projectName);
    $unitGoalRentCostField = ProjectMapping::getUnitField('goal_rent_cost', $projectName);
    $unitGoalRentCostAltField = ProjectMapping::getUnitField('goal_rent_cost_alt', $projectName);
    $unitNumberField = ProjectMapping::getUnitField('number', $projectName);

    $internalArea = $unitInternalAreaField ? (float) ($unitData[$unitInternalAreaField] ?? 0) : 0;

    if ($unitGoalRentCostField) {
        $goalRentCost = (float) ($unitData[$unitGoalRentCostField] ?? 0);
        if ($unitGoalRentCostAltField && $goalRentCost == 0) {
            $goalRentCost = (float) ($unitData[$unitGoalRentCostAltField] ?? 0);
        }
    } else {
        $goalRentCost = 0;
    }

    $number = '0';
    if ($unitNumberField && isset($unitData[$unitNumberField])) {
        $numberValue = $unitData[$unitNumberField];
        if (is_array($numberValue)) {
            $numberValue = $numberValue[0] ?? null;
        }
        if ($numberValue !== null) {
            $numberMapping = ProjectMapping::getUnitNumberMapping($projectName);
            $number = $numberMapping[(string) $numberValue] ?? (string) $numberValue;
        }
    }
    if ($number === '') {
        $number = '0';
    }

    $payload = [
        'external_id' => (string) $unitExternalId,
        'name' => $name,
        'header' => $unitData['title'] ?? $name,
        'goal_rent_cost' => $goalRentCost,
        'internal_area' => $internalArea,
        'photos' => [],
        'property_type' => 'room',
        'number' => $number,
    ];

    if ($parentUnitId !== null) {
        $payload['parent_unit'] = $parentUnitId;
    }

    return $payload;
}

/**
 * @param string $projectName
 * @param string $externalId
 * @param string $title
 * @param string|int $unitId
 * @param string|int $clientId
 * @param string $startDate
 * @param string $endDate
 * @param string $contractTypeId
 * @return array
 */
function buildSyntheticContractPayload(
    $projectName,
    $externalId,
    $title,
    $unitId,
    $clientId,
    $startDate,
    $endDate,
    $contractTypeId
) {
    $startDateField = ProjectMapping::getContractField('start_date', $projectName);
    $endDateField = ProjectMapping::getContractField('end_date', $projectName);
    $contractTypeField = ProjectMapping::getContractField('contract_type', $projectName);

    return [
        'id' => $externalId,
        'title' => $title,
        'unit_id' => $unitId,
        'client_id' => $clientId,
        'opportunity' => 0,
        $startDateField => $startDate,
        $endDateField => $endDate,
        $contractTypeField => $contractTypeId,
    ];
}

/**
 * @param array $contact
 * @param string|null $emailField
 * @param string $projectName
 * @return array
 */
function mapBitrixContactToClientData(array $contact, $emailField = null, $projectName = 'Dubai')
{
    $email = '';
    if ($emailField && isset($contact[$emailField])) {
        $email = is_array($contact[$emailField])
            ? ($contact[$emailField][0]['VALUE'] ?? $contact[$emailField][0] ?? '')
            : (string) $contact[$emailField];
    } elseif (is_array($contact['EMAIL'] ?? null)) {
        $email = $contact['EMAIL'][0]['VALUE'] ?? '';
    }

    $phone = '';
    if (is_array($contact['PHONE'] ?? null)) {
        $phone = $contact['PHONE'][0]['VALUE'] ?? '';
    }

    $clientData = [
        'id' => $contact['ID'],
        'first_name' => $contact['NAME'] ?? '',
        'last_name' => $contact['LAST_NAME'] ?? '',
        'email' => $email,
        'phone' => $phone,
    ];

    $nationalityField = ProjectMapping::getTenantField('nationality', $projectName)
        ?: ProjectMapping::getLandlordField('nationality', $projectName);
    if ($nationalityField) {
        $bitrixValue = ProjectMapping::getBitrixItemValue($contact, $nationalityField);
        if (is_array($bitrixValue)) {
            $bitrixValue = $bitrixValue[0] ?? null;
        }
        $countryId = ProjectMapping::mapNationality($bitrixValue, $projectName);
        if ($countryId !== null) {
            $clientData['country'] = $countryId;
        }
    }

    return $clientData;
}

/**
 * @param string $rawMessage
 * @param int|string|null $almaUnitId
 * @return string
 */
function formatAlmaSyncErrorForBitrixComment($rawMessage, $almaUnitId = null): string
{
    $detail = trim((string) $rawMessage);
    $detail = preg_replace('/^API request failed \(\d+\):\s*/', '', $detail) ?? $detail;
    $detail = preg_replace('/^Bitrix24 API error \(HTTP \d+\):\s*/', '', $detail) ?? $detail;
    $detail = preg_replace('/^API error:\s*/', '', $detail) ?? $detail;
    $detail = preg_replace('/^Validation error:\s*/', '', $detail) ?? $detail;

    $decoded = json_decode($detail, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded) && isset($decoded['error_description']) && (string) $decoded['error_description'] !== '') {
            $detail = (string) $decoded['error_description'];
        } else {
            $detail = flattenAlmaErrorPayload($decoded);
        }
    }

    $detail = trim((string) $detail);
    if ($detail === '') {
        $detail = trim((string) $rawMessage);
    }

    if (stripos($detail, 'intersection') !== false) {
        $comment = "[color=#ff0000]Alma sync error:[/color]\n[b]Пересечение дат[/b]";
        if ($almaUnitId !== null && $almaUnitId !== '') {
            $base = rtrim((string) Config::get('ALMA_WEB_URL', 'https://colife.alma-app.com'), '/');
            $url = $base . '/units/' . rawurlencode((string) $almaUnitId);
            $comment .= "\n[url=" . $url . "]Открыть юнит в Alma[/url]";
        }
        return $comment;
    }

    $detail = clarifyAlmaSyncErrorDetail($detail);

    return "[color=#ff0000]Alma sync error:[/color]\n[b]" . $detail . '[/b]';
}

/**
 * @param string $detail
 * @return string
 */
function clarifyAlmaSyncErrorDetail($detail): string
{
    if (stripos($detail, 'Client with received email already exists') !== false) {
        return 'Дубль клиента: email уже существует в Alma';
    }
    return $detail;
}

/**
 * @param int|string $almaClientId
 * @return string
 */
function formatAlmaContractCreatedForBitrixComment($almaClientId): string
{
    $base = rtrim((string) Config::get('ALMA_WEB_URL', 'https://colife.alma-app.com'), '/');
    $url = $base . '/clients/' . rawurlencode((string) $almaClientId);
    return "[color=#008000]Alma sync:[/color]\n[b]Контракт успешно создан[/b]\n[url=" . $url . "]Открыть клиента в Alma[/url]";
}

/**
 * @param mixed $payload
 * @param string|null $fieldName
 * @return string
 */
function flattenAlmaErrorPayload($payload, $fieldName = null): string
{
    if (is_string($payload)) {
        return $fieldName !== null && $fieldName !== ''
            ? $fieldName . ': ' . $payload
            : $payload;
    }
    if (!is_array($payload)) {
        $text = trim((string) $payload);
        if ($text === '') {
            return '';
        }
        return $fieldName !== null && $fieldName !== ''
            ? $fieldName . ': ' . $text
            : $text;
    }
    if ($payload === []) {
        return '';
    }
    if (array_is_list($payload)) {
        $parts = [];
        foreach ($payload as $item) {
            $flat = flattenAlmaErrorPayload($item, $fieldName);
            if ($flat !== '') {
                $parts[] = $flat;
            }
        }
        return implode('; ', $parts);
    }
    $parts = [];
    foreach ($payload as $key => $value) {
        $flat = flattenAlmaErrorPayload($value, (string) $key);
        if ($flat !== '') {
            $parts[] = $flat;
        }
    }
    return implode('; ', $parts);
}

/**
 * @param Bitrix24Rest $bitrix
 * @param int|string $entityTypeId
 * @param int|string $entityId
 * @param string $comment
 * @return void
 */
function addCrmTimelineComment(Bitrix24Rest $bitrix, $entityTypeId, $entityId, $comment): void
{
    $bitrix->call('crm.timeline.comment.add', [
        'fields' => [
            'ENTITY_ID' => (int) $entityId,
            'ENTITY_TYPE' => 'dynamic_' . (int) $entityTypeId,
            'COMMENT' => $comment,
        ],
    ]);
}

/**
 * @param string $webhookUrl
 * @return int|null
 */
function getBitrixRestUserIdFromWebhookUrl($webhookUrl): ?int
{
    if (preg_match('#/rest/(\d+)/#', $webhookUrl, $matches)) {
        return (int) $matches[1];
    }
    return null;
}

/**
 * Webhook after our error comment: card fields were not changed (updatedTime <= comment time).
 *
 * @param Bitrix24Rest $bitrix
 * @param int|string $entityTypeId
 * @param int|string $entityId
 * @param array $contractData
 * @param int|null $integrationUserId
 * @return bool
 */
function isTenantContractWebhookEchoFromErrorComment(
    Bitrix24Rest $bitrix,
    $entityTypeId,
    $entityId,
    array $contractData,
    $integrationUserId
): bool {
    if ($integrationUserId === null) {
        return false;
    }

    $list = $bitrix->call('crm.timeline.comment.list', [
        'filter' => [
            'ENTITY_ID' => (int) $entityId,
            'ENTITY_TYPE' => 'dynamic_' . (int) $entityTypeId,
        ],
        'order' => ['ID' => 'DESC'],
    ]);
    $items = $list['result'] ?? [];
    if (!is_array($items) || $items === []) {
        return false;
    }

    $latest = $items[0];
    $text = (string) ($latest['COMMENT'] ?? '');
    $authorId = (int) ($latest['AUTHOR_ID'] ?? 0);
    if ($authorId !== (int) $integrationUserId || !str_contains($text, 'Alma sync')) {
        return false;
    }

    $commentTs = strtotime((string) ($latest['CREATED'] ?? ''));
    $updatedTs = strtotime((string) ($contractData['updatedTime'] ?? $contractData['UPDATED_TIME'] ?? ''));
    if ($commentTs === false) {
        return false;
    }
    if ($updatedTs === false) {
        return true;
    }

    return $updatedTs <= $commentTs;
}
