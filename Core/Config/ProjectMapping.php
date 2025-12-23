<?php

class ProjectMapping {
    
    public static function getFieldMapping($projectName = 'Dubai') {
        $mappings = [
            'Dubai' => [
                'entity_type_id' => 144,
                'unit_entity_type_id' => 167,
                'contract_entity_type_id' => 183,
                'contract_fields' => [
                    'unit_id' => 'ufCrm20_1693919019',
                    'apartment_id' => null,
                    'start_date' => 'ufCrm20ContractStartDate',
                    'end_date' => 'ufCrm20ContractEndDate',
                    'contract_type' => 'ufCrm20_1693561495',
                    'history' => 'ufCrm20ContractHistory',
                    'contract_scan' => 'ufCrm20Contract',
                ],
                'tenant_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_20_1696523391',
                    'passport_scan' => 'UF_CRM_20_1696615939',
                ],
                'landlord_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_10_1740556751168',
                    'passport_scan' => 'UF_CRM_10_1694000435068',
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
                    'electronic_lock_password' => 'ufCrm6_1715777670',
                    'keybox_code' => 'ufCrm6_1720794204',
                    'is_swimming_pool' => 'ufCrm6_1697622591377',
                    'additional_external_id' => 'ufCrm6_1684425402',
                    'stage' => 'ufCrm6_1742486676',
                    'rent_type' => 'ufCrm6_1736951470242',
                    'email' => 'UF_CRM_1727788747'
                ],
                'unit_fields' => [
                    'internal_area' => 'ufCrm8_1686662606534',
                    'goal_rent_cost' => 'opportunity',
                    'number' => 'ufCrm8_1698838056004',
                ],
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
                    '64' => 'apartment'
                ],
                'bedrooms_mapping' => [
                    '52' => 1,
                    '54' => 1,
                    '56' => 2,
                    '58' => 3,
                    '60' => 4,
                    '62' => 5,
                    '64' => 6
                ],
                'rent_type_mapping' => [
                    '4600' => 'rooms',
                    '4598' => 'unit'
                ],
                'contract_type_mapping' => [
                    '882' => 'Airbnb',
                    '884' => 'Short term from 1 to 3 months',
                    '886' => 'Long-term 3+ months', 
                    '1304' => 'Booking',
                    '1306' => 'Short contract up to 1 month',
                    '6578' => 'Less than a month'
                ],
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
                    66 => 'Rashidiya',
                    68 => 'Emirates',
                    70 => 'Airport Terminal 3',
                    72 => 'Airport Terminal 1',
                    74 => 'GGICO',
                    76 => 'Deira City Centre',
                    78 => 'Al Rigga',
                    80 => 'Union',
                    82 => 'Bur Juman',
                    84 => 'ADCB',
                    86 => 'Al Jaffiliya',
                    88 => 'World Trade Centre',
                    90 => 'Emirates Towers',
                    92 => 'Financial Centre',
                    94 => 'Burj Khalifa / Dubai Mall',
                    96 => 'Business Bay',
                    98 => 'Noor Bank',
                    100 => 'First Gulf Bank (FGB)',
                    102 => 'Mall of the Emirates',
                    104 => 'Sharaf DG',
                    106 => 'Dubai Internet City',
                    108 => 'Nakheel',
                    110 => 'Damac Properties',
                    112 => 'DMCC',
                    114 => 'Nakheel Harbor and Tower',
                    116 => 'Ibn Battuta',
                    118 => 'Energy',
                    120 => 'Danube',
                    122 => 'UAE Exchange',
                    124 => 'Etisalat',
                    126 => 'Al Qusais',
                    128 => 'Dubai Airport Free Zone',
                    130 => 'Al Nahda',
                    132 => 'Stadium',
                    134 => 'Al Quiadah',
                    136 => 'Abu Hail',
                    138 => 'Abu Baker Al Siddique',
                    140 => 'Salah Al Din',
                    142 => 'Baniyas Square',
                    144 => 'Palm Deira',
                    146 => 'Al Ras',
                    148 => 'Al Ghubaiba',
                    150 => 'Al Fahidi',
                    152 => 'Oud Metha',
                    154 => 'Dubai Healthcare City',
                    156 => 'Al Jadaf',
                    158 => 'Creek',
                    182 => 'Sobha realty',
                    184 => 'Al Furjan',
                    254 => 'Centrepoint',
                    2762 => null
                ],
                'failure_stage_id' => 'DT183_28:FAIL'
            ],
            'HongKong' => [
                'entity_type_id' => 1054,
                'unit_entity_type_id' => 1058,
                'contract_entity_type_id' => 2,
                'contract_stage_id' => 'UC_KSMIAX',
                'contract_fields' => [
                    'unit_id' => 'UF_CRM_1764013723',
                    'apartment_id' => 'UF_CRM_1764013747',
                    'start_date' => 'UF_CRM_6745817E27230',
                    'end_date' => 'UF_CRM_1733107462743',
                    'contract_type' => 'UF_CRM_1758865148194',
                    'history' => null,
                    'contract_scan' => null,
                ],
                'tenant_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_20_1696523391',
                    'passport_scan' => 'UF_CRM_20_1696615939',
                ],
                'landlord_fields' => [
                    'email' => 'UF_CRM_1727788747',
                    'passport_number' => 'UF_CRM_10_1740556751168',
                    'passport_scan' => 'UF_CRM_10_1694000435068',
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
                    'email' => 'UF_CRM_1727788747'
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
                    '809' => 2,
                    '813' => 2,
                ],
                'rent_type_mapping' => [
                    '1069' => 'unit',
                    '1071' => 'rooms'
                ],
                'contract_type_mapping' => [
                    '983' => 'Short contract up to 1 month',
                    '985' => 'Airbnb',
                    '987' => 'Booking',
                    '989' => 'Less than a month',
                    '991' => 'Short term from 1 to 3 months',
                    '993' => 'Long-term 3+ months',
                    '995' => 'Ejari'
                ],
                'work_model_mapping' => [],
                'metro_mapping' => [],
                'stage_mapping' => [
                    '1019' => 'Blocked',
                    '1021' => 'Available',
                    '1023' => 'Rented',
                    '1025' => 'Ex'
                ]
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
    
    public static function getContractStageId($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['contract_stage_id'] ?? null;
    }

    public static function getFailureStageId($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['failure_stage_id'] ?? null;
    }
    
    public static function isDealBasedContract($projectName = 'Dubai') {
        return $projectName === 'HongKong';
    }
    
    public static function getContractField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['contract_fields'][$fieldName] ?? null;
    }
    
    public static function getTenantField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['tenant_fields'][$fieldName] ?? null;
    }
    
    public static function getLandlordField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['landlord_fields'][$fieldName] ?? null;
    }
    
    public static function getUnitApartmentField($projectName = 'Dubai') {
        if ($projectName === 'HongKong') {
            return 'ufCrm13Apartments';
        }
        return 'ufCrm8_1684429208';
    }
    
    public static function getApartmentRentTypeField($projectName = 'Dubai') {
        if ($projectName === 'HongKong') {
            return null;
        }
        return 'ufCrm6_1736951470242';
    }
    
    public static function getUnitField($fieldName, $projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['unit_fields'][$fieldName] ?? null;
    }
    
    public static function getUnitNumberMapping($projectName = 'Dubai') {
        return self::getFieldMapping($projectName)['unit_number_mapping'] ?? [];
    }
    
}


