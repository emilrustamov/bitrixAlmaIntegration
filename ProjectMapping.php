<?php

class ProjectMapping {
    
    public static function getFieldMapping($projectName = 'Dubai') {
        $mappings = [
            'Dubai' => [
                'entity_type_id' => 144,
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
                    '52' => 1,  // studio
                    '54' => 1,
                    '56' => 2,
                    '58' => 3,
                    '60' => 4,
                    '62' => 5,
                    '64' => 6
                ],
                'rent_type_mapping' => [
                    '4600' => 'rooms',  // шеринг
                    '4598' => 'unit'    // обычный апартамент
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
                ]
            ],
            'HongKong' => [
                'entity_type_id' => 1054,
                'fields' => [
                    'building_name' => 'ufCrm11Building',
                    'apartment_number' => 'ufCrm11ApartmentNumber',
                    'internal_area' => 'ufCrm11ApartmentAreaSqmtrs',
                    'goal_rent_cost' => 'opportunity', // Поле opportunity есть в обеих системах
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
                    'email' => 'UF_CRM_1727788747'
                ],
                'property_type_mapping' => [
                    '809' => 'apartment',
                    '813' => 'apartment', 
                    // TODO: Добавить другие типы недвижимости для Гонконга
                ],
                'bedrooms_mapping' => [
                    '809' => 2,
                    '813' => 2, 
                    // TODO: Добавить маппинг количества спален для Гонконга
                ],
                'metro_mapping' => [] // В Гонконге нет метро Dubai
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
    
    public static function getContractTypeMapping($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['contract_type_mapping'] ?? [];
    }
    
    public static function mapContractType($bitrixType, $projectName = 'Dubai') {
        $mapping = self::getContractTypeMapping($projectName);
        $bitrixTypeStr = (string)$bitrixType;
        
        return $mapping[$bitrixTypeStr] ?? null;
    }
    
    public static function getWorkModelMapping($projectName = 'Dubai') {
        $mapping = self::getFieldMapping($projectName);
        return $mapping['work_model_mapping'] ?? [];
    }
    
    public static function mapWorkModel($bitrixType, $projectName = 'Dubai') {
        $mapping = self::getWorkModelMapping($projectName);
        $bitrixTypeStr = (string)$bitrixType;
        
        return $mapping[$bitrixTypeStr] ?? null;
    }
    
}
