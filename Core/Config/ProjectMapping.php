<?php

class ProjectMapping {

    public static function getFieldMapping($projectName = 'Dubai') {
        $mappings = [
            'Dubai' => [
                'apartment_entity_type_ids' => [144],
                'unit_entity_type_id' => 167,
                'contract_entity_type_id' => 183,
                'landlord_contract_entity_type_id' => 148,
                'landlord_contract_fields' => [
                    'start_date' => 'ufCrm10_1693823247516',
                    'end_date' => 'ufCrm10_1693823282826',
                    'work_model' => 'ufCrm10_1708955821',
                    'apartment_id' => 'ufCrm10_1693823301',
                    'contact_id' => 'contactId',
                    'contract_scan' => 'ufCrm10_1709042143',
                    'title_deed_scan' => 'ufCrm10_1694000636731',
                    'pml_scan' => 'ufCrm10_1694000391518',
                    'dtcm_scan' => 'ufCrm10_1694000558852',
                    'pml_start_date' => 'ufCrm10_1708956056',
                    'pml_end_date' => 'ufCrm10_1708955996',
                ],
                'contract_fields' => [
                    'unit_id' => 'ufCrm20_1693919019',
                    'start_date' => 'ufCrm20ContractStartDate',
                    'end_date' => 'ufCrm20ContractEndDate',
                    'contract_type' => 'ufCrm20_1693561495',
                    'history' => 'ufCrm20ContractHistory',
                    'contract_scan' => 'ufCrm20Contract',
                    'paying_party' => 'ufCrm20PayerNameOrCompanyName',
                ],
                'tenant_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_20_1696523391',
                    'id_scan' => 'UF_CRM_20_1696615939',
                    'nationality' => 'UF_CRM_1755104713',
                ],
                'landlord_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_10_1740556751168',
                    'id_scan' => 'UF_CRM_10_1741788524',
                    'nationality' => 'UF_CRM_1755104713',
                ],
                'nationality_mapping' => [
                    '8690' => 2,
                    '8692' => 70,
                    '8730' => 7,
                    '8702' => 63,
                    '8704' => 25,
                    '8706' => 14,
                    '8708' => 21,
                    '8712' => 69,
                    '8714' => 58,
                    '8716' => 77,
                    '8718' => 61,
                    '8722' => 1,
                    '8726' => 62,
                    '8734' => 57,
                    '8736' => 9,
                    '8738' => 11,
                    '8740' => 64,
                    '8744' => 15,
                    '8746' => 4,
                    '8748' => 3,
                    '8750' => 22,
                    '8752' => 23,
                    '8754' => 24,
                    '8756' => 26,
                    '8762' => 32,
                    '8764' => 34,
                    '8768' => 25,
                    '8776' => 40,
                    '8778' => 76,
                    '8782' => 41,
                    '8784' => 43,
                    '8786' => 48,
                    '8788' => 12,
                    '8792' => 49,
                    '8798' => 68,
                    '8804' => 66,
                    '9002' => 46,
                    '9090' => 20,
                    '9092' => 6,
                    '9094' => 8,
                    '9096' => 13,
                    '9098' => 17,
                    '9100' => 18,
                    '9102' => 31,
                    '9108' => 38,
                    '9118' => 42,
                    '9120' => 45,
                    '9122' => 50,
                    '9124' => 51,
                    '9130' => 56,
                    '9194' => 37,
                ],
                'fields' => [
                    'building_name' => 'ufCrm6_1682232363193',
                    'apartment_number' => 'ufCrm6_1682232396330', 
                    'internal_area' => 'ufCrm6_1682232424142',
                    'goal_rent_cost' => 'ufCrm6_1682232447205',
                    'number_of_baths' => 'ufCrm6_1682232465964',
                    'property_type' => 'ufCrm6_1682232863625',
                    'floor' => 'ufCrm6_1682232312628',
                    'address' => 'ufCrm6_1718821717',
                    'subway_station' => 'ufCrm6_1682233481671',
                    'internet_login' => 'ufCrm6_1682235809295',
                    'internet_password' => 'ufCrm6_1686728251990',
                    'parking_number' => 'ufCrm6_1683299159437',
                    'parking_type' => 'ufCrm6_1741230023480',
                    'electronic_lock_password' => 'ufCrm6_1715777670',
                    'keybox_code' => 'ufCrm6_1720794204',
                    'is_swimming_pool' => 'ufCrm6_1697622591377',
                    'additional_external_id' => 'ufCrm6_1684425402',
                    'stage' => 'ufCrm6_1742486676',
                    'rent_type' => 'ufCrm6_1736951470242',
                    'date_of_refused' => 'ufCrm6_1742377926',
                ],
                'unit_fields' => [
                    'internal_area' => 'ufCrm8_1686662606534',
                    'goal_rent_cost' => 'opportunity',
                    'number' => 'ufCrm8_1698838056004',
                ],
                'blocking_list_iblock_id' => 300,
                'termination_list_event_type' => 'Расторжение',
                'list_inactive_status_ids' => ['2504'],
                'blocking_list_reason_alma_client_ids' => [
                    'Landlor lives in (проживает собственник)' => 15106,
                    'Repair (ремонт)' => 15107,
                    "Landlord's friends live in (проживают друзья собственника)" => 15108,
                    'Another reason (другое)' => 15109,
                    'Invest' => 15110,
                    'Tenants of the landlord' => 15111,
                ],
                'termination_alma_client_id' => 15135,
                'unit_number_mapping' => [
                    '1294' => '1',
                    '1296' => '2',
                    '1298' => '3',
                    '1300' => '4',
                    '1302' => '5',
                    '5860' => '6',
                    '5862' => '7',
                    '5864' => '8',
                    '5866' => '9',
                    '5868' => '10',
                    '5902' => 'SP1-1',
                    '5904' => 'SP1-2',
                    '5906' => 'SP2-1',
                    '5908' => 'SP2-2',
                    '5910' => 'SP3-1',
                    '5912' => 'SP3-2',
                    '5914' => 'SP4-1',
                    '5916' => 'SP4-2',
                    '5918' => 'SP5-1',
                    '5920' => 'SP5-2',
                    '5922' => 'SP6-1',
                    '5924' => 'SP6-2',
                    '5926' => 'SP7-1',
                    '5928' => 'SP7-2',
                    '5930' => 'SP8-1',
                    '5932' => 'SP8-2',
                    '5934' => 'SP9-1',
                    '5936' => 'SP9-2',
                    '5938' => 'SP10-1',
                    '5940' => 'SP10-2',
                ],
                'property_type_mapping' => [
                    '52' => 'studio',
                    '54' => 'apartment',
                    '56' => 'apartment',
                    '58' => 'apartment',
                    '60' => 'apartment',
                    '62' => 'apartment',
                    '64' => 'apartment',
                    '10662' => 'apartment',
                    '10664' => 'apartment',
                    '10666' => 'apartment',
                    '10668' => 'apartment',
                    '10670' => 'apartment',
                    '10672' => 'apartment'
                ],
                'bedrooms_mapping' => [
                    '52' => 1,
                    '54' => 1,
                    '56' => 2,
                    '58' => 3,
                    '60' => 4,
                    '62' => 5,
                    '64' => 6,
                    '10662' => 1,
                    '10664' => 1,
                    '10666' => 2,
                    '10668' => 2,
                    '10670' => 3,
                    '10672' => 3
                ],
                'rent_type_mapping' => [
                    '4600' => 'rooms',
                    '4598' => 'unit'
                ],
                'parking_payment_type_mapping' => [
                    '5944' => 'access_card',
                    '5946' => 'parking_card',
                    '5948' => 'parking_sticker',
                    '5950' => 'on_the_barrier',
                ],
                'default_rent_type_value' => '4598',
                'contract_type_mapping' => [
                    '882' => 'Airbnb',
                    '8672' => 'Ejari',
                    '884' => 'Short term from 1 to 3 months',
                    '886' => 'Long-term 3+ months',
                    '1304' => 'Booking',
                    '1306' => 'Short contract up to 1 month',
                    '6578' => 'Less than a month',
                    '0000' => 'Blocking',
                    '0002' => 'Termination',
                ],
                'no_invitation_contract_type_ids' => ['882', '8672'],
                'work_model_mapping' => [
                    '1696' => 'Rent to rent',
                    '1698' => 'TM 10%',
                    '1700' => 'TM 15%',
                    '1702' => 'TM 17%',
                    '1704' => 'TM 20%',
                    '1706' => 'Long term',
                    '1730' => 'TM 18%'
                ],
                'metro_mapping' => [
                    66 => 'Centrepoint',
                    68 => 'Emirates',
                    70 => 'Airport Terminal 3',
                    72 => 'Airport Terminal 1',
                    74 => 'Al Garhoud',
                    76 => 'Deira City Centre',
                    78 => 'Al Rigga',
                    80 => 'Union',
                    82 => 'Bur Juman',
                    84 => 'ADCB',
                    86 => 'Max',
                    88 => 'World Trade Centre',
                    90 => 'Emirates Towers',
                    92 => 'Financial Centre',
                    94 => 'Burj Khalifa / Dubai Mall',
                    96 => 'Business Bay',
                    98 => 'Noor Bank',
                    100 => 'Dubai Expo 2020',
                    102 => 'Mall of the Emirates',
                    104 => 'Sharaf DG',
                    106 => 'Dubai Internet City',
                    108 => 'Nakheel',
                    110 => 'Sobha Realty',
                    112 => 'DMCC',
                    114 => 'Nakheel Harbor and Tower',
                    116 => 'Ibn Battuta',
                    118 => 'Energy',
                    120 => 'Danube',
                    122 => 'Life Pharmacy',
                    124 => 'Etisalat by e&',
                    126 => 'Al Qusais',
                    128 => 'Dubai Airport Free Zone',
                    130 => 'Al Nahda',
                    132 => 'Stadium',
                    134 => 'Al Quiadah',
                    136 => 'Abu Hail',
                    138 => 'Abu Baker Al Siddique',
                    140 => 'Salah Al Din',
                    142 => 'Baniyas Square',
                    144 => 'Gold Souq',
                    146 => 'Al Ras',
                    148 => 'Al Ghubaiba',
                    152 => 'Oud Metha',
                    154 => 'Dubai Healthcare City',
                    156 => 'Al Jadaf',
                    158 => 'Creek',
                    182 => 'Sobha realty',
                    184 => 'Al Furjan',
                    10676 => 'Jumeirah Golf Estates Metro Station',
                    10678 => 'The Gardens',
                    10680 => 'Discovery Gardens',
                    10682 => 'Al Fardan Exchange',
                    10684 => 'Equity',
                    10686 => 'Onpassive',
                    10688 => 'National Paints',
                    10690 => 'Insurance market',
                    2762 => null
                ],
                'tenant_contract_archive_stage_ids' => [
                    'DT183_28:FAIL',
                    'DT183_28:SUCCESS',
                ],
                'archive_stage_ids' => [
                    'DT144_10:FAIL',
                    'DT144_10:UC_XH6370',
                    'Ex-Apartments',
                    'Ex-Apartment',
                    'Ex-apartment',
                    'Termination process',
                ],
                'allowed_stage_ids' => ['DT144_10:PREPARATION', 'DT144_10:CLIENT', '5980', '5974', 'Available', 'Rented']
            ],
            'HongKong' => [
                'apartment_entity_type_ids' => [1054],
                'unit_entity_type_id' => 1058,
                'landlord_contract_entity_type_id' => null,
                'landlord_contract_fields' => [],
                'contract_entity_type_id' => 1072,
                'contract_fields' => [
                    'unit_id' => 'ufCrm19Units',
                    'start_date' => 'ufCrm19CheckinDate',
                    'end_date' => 'ufCrm19CheckoutDate',
                    'contract_type' => 'ufCrm19ContractType',
                    'history' => 'ufCrm19ContractHistory',
                    'contract_scan' => 'ufCrm19Contract',
                ],
                'tenant_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_20_1696523391',
                    'id_scan' => 'UF_CRM_20_1696615939',
                ],
                'landlord_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_10_1740556751168',
                    'id_scan' => 'UF_CRM_10_1741788524',
                ],
                'fields' => [
                    'building_name' => 'ufCrm11Building',
                    'apartment_number' => 'ufCrm11ApartmentNumber',
                    'internal_area' => 'ufCrm11ApartmentAreaSqmtrs',
                    'goal_rent_cost' => 'opportunity',
                    'number_of_baths' => 'ufCrm11Bathrooms',
                    'property_type' => 'ufCrm11Type',
                    'floor' => 'ufCrm11Floor',
                    'address' => 'ufCrm11Address',
                    'internet_login' => 'ufCrm11WifiName',
                    'internet_password' => 'ufCrm11WifiPassword',
                    'parking_number' => 'ufCrm11ParkingNumber',
                    'electronic_lock_password' => 'ufCrm11PassFromElectricalLock',
                    'keybox_code' => 'ufCrm11Keys',
                    'is_swimming_pool' => 'ufCrm11PoolInBuilding',
                    'additional_external_id' => 'ufCrm11Units',
                    'stage' => 'ufCrm11StageForAlma',
                    'rent_type' => 'ufCrm11RentType',
                    'subway_station' => null,
                ],
                'unit_fields' => [
                    'internal_area' => 'ufCrm13TotalAreaOfTheRoom',
                    'goal_rent_cost' => 'opportunity',
                    'goal_rent_cost_alt' => 'ufCrm13RentAmount',
                    'number' => 'UF_CRM_13_NUMBER_OF_UNIT',
                ],
                'unit_number_mapping' => [
                    '725' => '1',
                    '727' => '2',
                    '729' => '3',
                    '731' => '4',
                    '733' => '5',
                    '735' => '6',
                    '737' => '7',
                    '739' => '8',
                    '741' => '9',
                    '743' => '10',
                    '745' => 'SP1-1',
                    '747' => 'SP1-2',
                    '749' => 'SP2-1',
                    '751' => 'SP2-2',
                    '753' => 'SP3-1',
                    '755' => 'SP3-2',
                    '757' => 'SP4-1',
                    '759' => 'SP4-2',
                    '761' => 'SP5-1',
                    '763' => 'SP5-2',
                    '765' => 'SP6-1',
                    '767' => 'SP6-2',
                    '769' => 'SP7-1',
                    '771' => 'SP7-2',
                    '773' => 'SP8-1',
                    '775' => 'SP8-2',
                    '777' => 'SP9-1',
                    '779' => 'SP9-2',
                    '781' => 'SP10-1',
                    '783' => 'SP10-2',
                ],
                'property_type_mapping' => [
                    '809' => 'apartment',
                    '813' => 'apartment',
                ],
                'bedrooms_mapping' => [
                    '803' => 1,
                    '805' => 1,
                    '807' => 2,
                    '809' => 3,
                    '811' => 4,
                    '813' => 5,
                    '815' => 6,
                ],
                'rent_type_mapping' => [
                    '1069' => 'unit',
                    '1071' => 'rooms'
                ],
                'default_rent_type_value' => '1069',
                'contract_type_mapping' => [
                    '983' => 'Short contract up to 1 month',
                    '985' => 'Airbnb',
                    '987' => 'Booking',
                    '989' => 'Less than a month',
                    '991' => 'Short term from 1 to 3 months',
                    '993' => 'Long-term 3+ months',
                    '1277' => 'Less than 1 month contract',
                    '1279' => '1+ months',
                ],
                'no_invitation_contract_type_ids' => ['985'],
                'work_model_mapping' => [],
                'metro_mapping' => [],
                'tenant_contract_archive_stage_ids' => [
                    'DT1072_27:FAIL',
                    'DT1072_27:SUCCESS',
                ],
                'archive_stage_ids' => ['1025', '1027', '1029'],
                'allowed_stage_ids' => ['1021', '1023']
            ]
        ];
        
        return $mappings[$projectName] ?? $mappings['Dubai'];
    }
    
    public static function getProjectConfig($projectName = 'Dubai') {
        $configs = [
            'Dubai' => [
                'id' => (int)Config::get('PROJECT_DUBAI_ID'),
                'webhook_url' => Config::get('PROJECT_DUBAI_WEBHOOK_URL'),
                'name' => Config::get('PROJECT_DUBAI_NAME')
            ],
            'HongKong' => [
                'id' => (int)Config::get('PROJECT_HONGKONG_ID'), 
                'webhook_url' => Config::get('PROJECT_HONGKONG_WEBHOOK_URL'),
                'name' => Config::get('PROJECT_HONGKONG_NAME')
            ]
        ];
        
        return $configs[$projectName] ?? $configs['Dubai'];
    }
    
    public static function mapContractType($bitrixType, $projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['contract_type_mapping'][(string)$bitrixType] ?? null;
    }

    /**
     * @param mixed $bitrixValue
     * @param string $projectName
     * @return int|null
     */
    public static function mapNationality($bitrixValue, $projectName = 'Dubai')
    {
        if ($bitrixValue === null || $bitrixValue === '') {
            return null;
        }
        $mapping = self::getFieldMapping($projectName);
        $countryId = $mapping['nationality_mapping'][(string) $bitrixValue] ?? null;
        return $countryId !== null ? (int) $countryId : null;
    }
    
    public static function mapWorkModel($bitrixType, $projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['work_model_mapping'][(string)$bitrixType] ?? null;
    }
    
    public static function getUnitEntityTypeId($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['unit_entity_type_id'] ?? 167;
    }
    
    public static function getContractEntityTypeId($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['contract_entity_type_id'] ?? 183;
    }

    /**
     * @param array $aptItem
     * @param string $projectName
     * @return string
     */
    public static function resolveRentType(array $aptItem, $projectName = 'Dubai') {
        $rentTypeField = self::getApartmentRentTypeField($projectName);
        if ($rentTypeField === null) {
            return 'unit';
        }
        $mapping = self::getFieldMapping($projectName);
        $rentTypeMapping = $mapping['rent_type_mapping'] ?? [];
        $defaultRentTypeValue = self::getDefaultRentTypeValue($projectName);
        $rentTypeValue = $aptItem[$rentTypeField] ?? $defaultRentTypeValue;
        if (is_array($rentTypeValue)) {
            $rentTypeValue = $rentTypeValue[0] ?? $defaultRentTypeValue;
        }
        return $rentTypeMapping[(string) $rentTypeValue] ?? 'unit';
    }

    public static function getTenantContractArchiveStageIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['tenant_contract_archive_stage_ids'] ?? [];
        return is_array($ids) ? array_map('strval', $ids) : [];
    }
    
    public static function getContractField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['contract_fields'][$fieldName] ?? null;
    }
    
    public static function getLandlordField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['landlord_fields'][$fieldName] ?? null;
    }

    public static function getTenantField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['tenant_fields'][$fieldName] ?? null;
    }

    public static function getBitrixItemValue(array $item, ?string $fieldCode)
    {
        if ($fieldCode === null || $fieldCode === '') {
            return null;
        }
        if (array_key_exists($fieldCode, $item)) {
            return $item[$fieldCode];
        }
        $camelCase = self::bitrixFieldToCamelCase($fieldCode);
        if ($camelCase !== $fieldCode && array_key_exists($camelCase, $item)) {
            return $item[$camelCase];
        }
        return null;
    }

    private static function bitrixFieldToCamelCase(string $fieldCode): string
    {
        if (preg_match('/^UF_CRM_(\d+)_(.+)$/', $fieldCode, $matches)) {
            return 'ufCrm' . $matches[1] . '_' . $matches[2];
        }
        if (preg_match('/^UF_CRM_(\d+)$/', $fieldCode, $matches)) {
            return 'ufCrm' . $matches[1];
        }
        return $fieldCode;
    }
    
    public static function getUnitApartmentField($projectName = 'Dubai') {
        if ($projectName === 'HongKong') {
            return 'ufCrm13Apartments';
        }
        return 'ufCrm8_1684429208';
    }
    
    public static function getApartmentRentTypeField($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['fields']['rent_type'] ?? null;
    }
    
    public static function getUnitField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['unit_fields'][$fieldName] ?? null;
    }
    
    public static function getUnitNumberMapping($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['unit_number_mapping'] ?? [];
    }

    public static function getLandlordContractEntityTypeId($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['landlord_contract_entity_type_id'] ?? 148;
    }

    public static function getLandlordContractField($fieldName, $projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['landlord_contract_fields'][$fieldName] ?? null;
    }

    public static function getApartmentField($fieldName, $projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['fields'][$fieldName] ?? null;
    }

    public static function getApartmentEntityTypeIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['apartment_entity_type_ids'] ?? [];
        return is_array($ids) ? $ids : [];
    }

    public static function getArchiveStageIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['archive_stage_ids'] ?? [];
        return array_map('strval', $ids);
    }

    public static function getAllowedStageIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['allowed_stage_ids'] ?? [];
        return array_map('strval', $ids);
    }

    public static function getNoInvitationContractTypeIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['no_invitation_contract_type_ids'] ?? [];
        return array_map('strval', $ids);
    }

    public static function getDefaultRentTypeValue($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return (string) ($mapping['default_rent_type_value'] ?? '4598');
    }

    public static function getAirbnbContractTypeId($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $map = $mapping['contract_type_mapping'] ?? [];
        foreach ($map as $id => $name) {
            if ($name === 'Airbnb') {
                return (string) $id;
            }
        }
        return null;
    }

    public static function getTerminationContractTypeId($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $map = $mapping['contract_type_mapping'] ?? [];
        foreach ($map as $id => $name) {
            if ($name === 'Termination') {
                return (string) $id;
            }
        }
        return null;
    }

    public static function getTerminationAlmaClientId($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $id = $mapping['termination_alma_client_id'] ?? 0;
        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }

    public static function getTerminationListEventType($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['termination_list_event_type'] ?? 'Расторжение';
    }

    /**
     * @param string $projectName
     * @return string[]
     */
    public static function getListInactiveStatusIds($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        $ids = $mapping['list_inactive_status_ids'] ?? [];
        return array_map('strval', $ids);
    }
}


