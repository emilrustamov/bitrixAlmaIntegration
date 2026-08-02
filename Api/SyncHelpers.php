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
 * @return array
 */
function mapBitrixContactToClientData(array $contact, $emailField = null)
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

    return [
        'id' => $contact['ID'],
        'first_name' => $contact['NAME'] ?? '',
        'last_name' => $contact['LAST_NAME'] ?? '',
        'email' => $email,
        'phone' => $phone,
    ];
}
