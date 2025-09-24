<?php
require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

Config::load();

// Получаем проект из параметра или определяем автоматически
$projectName = $_GET['project'] ?? 'Dubai';
$projectConfig = ProjectMapping::getProjectConfig($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);

class ContractChecker {
    private $bitrix;
    private $almaApiKey;
    private $almaApiUrl;

    public function __construct() {
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->almaApiKey = ALMA_API_KEY;
        $this->almaApiUrl = ALMA_API_URL;
    }

    /**
     * Получить данные контракта из Bitrix24
     */
    public function getBitrixContract($contractId) {
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 183,
            'id' => $contractId,
        ]);

        if (!isset($response['result']['item'])) {
            return null;
        }

        $contract = $response['result']['item'];
        
        // Получаем данные контакта
        $contactResponse = $this->bitrix->call('crm.contact.get', [
            'id' => $contract['contactId']
        ]);
        
        if (!isset($contactResponse['result'])) {
            return null;
        }
        
        $contact = $contactResponse['result'];

        return [
            'id' => $contract['id'],
            'title' => $contract['title'],
            'unit_external_id' => $contract['ufCrm20_1693919019'] ?? '',
            'client_data' => [
                'name' => $contact['name'] ?? '',
                'last_name' => $contact['lastName'] ?? '',
                'email' => $contact['email'][0]['VALUE'] ?? '',
                'phone' => $contact['phone'][0]['VALUE'] ?? '',
            ]
        ];
    }

    /**
     * Получить данные апартамента/юнита из Bitrix24
     */
    public function getBitrixUnit($unitId) {
        // Сначала пробуем как юнит
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 167, // Юниты
            'id' => $unitId,
        ]);

        if (isset($response['result']['item'])) {
            $unit = $response['result']['item'];
            
            // Используем parentId144 для получения ID апартамента
            $apartmentId = $unit['parentId144'] ?? null;
            
            // Если parentId144 не найден, проверяем поле ufCrm8_1684429208
            if (!$apartmentId && isset($unit['ufCrm8_1684429208']) && is_array($unit['ufCrm8_1684429208']) && !empty($unit['ufCrm8_1684429208'])) {
                $apartmentId = $unit['ufCrm8_1684429208'][0];
            }
            
            // Если апартамент все еще не найден, ищем через поиск
            if (!$apartmentId) {
                $apartmentResponse = $this->bitrix->call('crm.item.list', [
                    'entityTypeId' => 144, // Апартаменты
                    'filter' => [
                        'ufCrm144_1693919019' => $unit['id'] // Поле, которое связывает апартамент с юнитом
                    ]
                ]);
                
                if (isset($apartmentResponse['result']['items']) && !empty($apartmentResponse['result']['items'])) {
                    $apartmentId = $apartmentResponse['result']['items'][0]['id'];
                }
            }
            
            // Если апартамент не найден, используем ID юнита как fallback
            if (!$apartmentId) {
                $apartmentId = $unit['id'];
            }
            
            return [
                'type' => 'unit',
                'id' => $unit['id'],
                'title' => $unit['title'],
                'external_id' => $apartmentId, // ID апартамента, к которому привязан юнит
                'additional_external_id' => $unit['id'], // ID самого юнита
                'stage_for_alma' => null // У юнитов нет поля Stage for alma
            ];
        }

        // Если не найден как юнит, пробуем как апартамент
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 144, // Апартаменты
            'id' => $unitId,
        ]);

        if (isset($response['result']['item'])) {
            $unit = $response['result']['item'];
            
            // Для апартамента нужно найти связанный юнит
            // Ищем юнит с parentId144 равным ID апартамента
            $unitResponse = $this->bitrix->call('crm.item.list', [
                'entityTypeId' => 167, // Юниты
                'filter' => [
                    'parentId144' => $unit['id'] // Поле, которое связывает юнит с апартаментом
                ]
            ]);
            
            $additionalExternalId = null;
            if (isset($unitResponse['result']['items']) && !empty($unitResponse['result']['items'])) {
                $additionalExternalId = $unitResponse['result']['items'][0]['id'];
            }
            
            return [
                'type' => 'apartment',
                'id' => $unit['id'],
                'title' => $unit['title'],
                'external_id' => $unit['id'], // ID апартамента
                'additional_external_id' => $additionalExternalId,
                'stage_for_alma' => $unit['ufCrm6_1742486676'] ?? null // Поле "Stage for alma"
            ];
        }

        return null;
    }

    /**
     * Получить данные объекта из Alma по external_id
     */
    public function getAlmaObject($externalId) {
        // Сначала получаем ID объекта через rental_object API
        $rentalObjectUrl = rtrim($this->almaApiUrl, '/') . "/realty/rental_object/" . urlencode($externalId) . "/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $rentalObjectUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $rentalObjectData = json_decode($response, true);
        
        if (!isset($rentalObjectData['id'])) {
            return null;
        }

        // Для external_id ищем апартамент (нет parent_unit), но не блокируем поиск
        // Проверка parent_unit убрана, так как она мешает поиску правильных объектов

        // Теперь получаем полную информацию через units API
        $unitId = $rentalObjectData['id'];
        $unitUrl = rtrim($this->almaApiUrl, '/') . "/realty/units/" . $unitId . "/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $unitUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        
        if (isset($data['id'])) {
            return $data;
        }
        
        return null;
    }

    /**
     * Получить данные объекта из Alma по additional_external_id
     */
    public function getAlmaObjectByAdditionalId($additionalExternalId) {
        $url = $this->almaApiUrl . "/realty/units/?additional_external_id=" . urlencode($additionalExternalId);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        
        // rental_object API возвращает один объект напрямую
        if (isset($data['id'])) {
            return $data;
        }
        
        return null;
    }

    /**
     * Проверить правильность синхронизации контракта
     */
    public function validateContract($contractId) {
        // 1. Получаем данные из Bitrix24
        $bitrixContract = $this->getBitrixContract($contractId);
        if (!$bitrixContract) {
            return [
                'success' => false,
                'error' => 'Контракт не найден в Bitrix24'
            ];
        }

        $unitExternalId = $bitrixContract['unit_external_id'];
        if (empty($unitExternalId)) {
            return [
                'success' => false,
                'error' => 'Не указан unit_external_id в контракте'
            ];
        }

        // 2. Получаем данные юнита/апартамента из Bitrix24
        $bitrixUnit = $this->getBitrixUnit($unitExternalId);
        if (!$bitrixUnit) {
            return [
                'success' => false,
                'error' => "Юнит/апартамент $unitExternalId не найден в Bitrix24"
            ];
        }

        // 3. Проверяем статус "Stage for alma" для апартаментов
        if ($bitrixUnit['type'] === 'apartment' && $bitrixUnit['stage_for_alma']) {
            if ($bitrixUnit['stage_for_alma'] === 'Ex-Apartments') {
                return [
                    'success' => false,
                    'error' => "Апартамент имеет статус 'Ex-Apartments' - пропускаем проверку",
                    'stage_for_alma' => $bitrixUnit['stage_for_alma']
                ];
            }
        }

        // 4. Получаем данные объекта из Alma
        $almaObject = $this->getAlmaObject($bitrixUnit['external_id']);
        
        // Если не найден по external_id, пробуем по additional_external_id
        if (!$almaObject && $bitrixUnit['additional_external_id']) {
            $almaObject = $this->getAlmaObjectByAdditionalId($bitrixUnit['additional_external_id']);
        }

        if (!$almaObject) {
            return [
                'success' => false,
                'error' => "Объект не найден в Alma (external_id: {$bitrixUnit['external_id']}, additional: {$bitrixUnit['additional_external_id']})"
            ];
        }

        // 5. Сравниваем названия
        $bitrixTitle = $bitrixUnit['title'];
        $almaTitle = $almaObject['name'];

        $isTitleMatch = $this->compareTitles($bitrixTitle, $almaTitle);

        return [
            'success' => true,
            'bitrix' => [
                'contract_id' => $bitrixContract['id'],
                'contract_title' => $bitrixContract['title'],
                'unit_id' => $bitrixUnit['id'],
                'unit_title' => $bitrixTitle,
                'unit_type' => $bitrixUnit['type'],
                'external_id' => $bitrixUnit['external_id'],
                'additional_external_id' => $bitrixUnit['additional_external_id'],
                'stage_for_alma' => $bitrixUnit['stage_for_alma']
            ],
            'alma' => [
                'object_id' => $almaObject['id'],
                'object_name' => $almaTitle,
                'external_id' => $almaObject['external_id'],
                'additional_external_id' => $almaObject['additional_external_id']
            ],
            'validation' => [
                'title_match' => $isTitleMatch,
                'external_id_match' => $bitrixUnit['external_id'] == $almaObject['external_id'],
                'additional_external_id_match' => $bitrixUnit['additional_external_id'] == $almaObject['additional_external_id']
            ]
        ];
    }

    /**
     * Сравнить названия объектов (упрощенное сравнение)
     */
    private function compareTitles($bitrixTitle, $almaTitle) {
        // Убираем лишние пробелы и приводим к нижнему регистру
        $bitrix = strtolower(trim($bitrixTitle));
        $alma = strtolower(trim($almaTitle));
        
        // Убираем точки и запятые
        $bitrix = str_replace(['.', ','], '', $bitrix);
        $alma = str_replace(['.', ','], '', $alma);
        
        return $bitrix === $alma;
    }
}

// Читаем список контрактов
$contracts = file('contracts.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
array_shift($contracts); // Убираем заголовок "ID"

$checker = new ContractChecker();
$results = [];
$detailed_errors = [];
$stats = [
    'total' => 0,
    'success' => 0,
    'not_found_bitrix' => 0,
    'not_found_alma' => 0,
    'ex_apartments' => 0,
    'mapping_issues' => 0,
    'no_unit_id' => 0
];

echo "Проверяем " . count($contracts) . " контрактов...\n\n";

foreach ($contracts as $contractId) {
    $contractId = trim($contractId);
    if (empty($contractId)) continue;
    
    $stats['total']++;
    echo "Проверяем контракт $contractId... ";
    
    $result = $checker->validateContract($contractId);
    $results[$contractId] = $result;
    
    if (!$result['success']) {
        $error_type = '';
        $error_details = $result['error'];
        
        if (strpos($result['error'], 'не найден в Bitrix24') !== false) {
            $stats['not_found_bitrix']++;
            $error_type = 'NOT_FOUND_BITRIX';
            echo "❌ Не найден в Bitrix24\n";
        } elseif (strpos($result['error'], 'не найден в Alma') !== false) {
            $stats['not_found_alma']++;
            $error_type = 'NOT_FOUND_ALMA';
            echo "❌ Не найден в Alma\n";
        } elseif (strpos($result['error'], 'Ex-Apartments') !== false) {
            $stats['ex_apartments']++;
            $error_type = 'EX_APARTMENTS';
            echo "⚠️  Ex-Apartments\n";
        } elseif (strpos($result['error'], 'Не указан unit_external_id') !== false) {
            $stats['no_unit_id']++;
            $error_type = 'NO_UNIT_ID';
            echo "❌ Нет unit_external_id\n";
        } else {
            $error_type = 'OTHER';
            echo "❌ " . $result['error'] . "\n";
        }
        
        // Записываем детальную ошибку
        $detailed_errors[] = [
            'contract_id' => $contractId,
            'error_type' => $error_type,
            'error_message' => $error_details,
            'bitrix_data' => isset($result['bitrix']) ? $result['bitrix'] : null,
            'alma_data' => isset($result['alma']) ? $result['alma'] : null
        ];
    } else {
        $validation = $result['validation'];
        if ($validation['external_id_match'] && $validation['additional_external_id_match']) {
            $stats['success']++;
            echo "✅ OK\n";
        } else {
            $stats['mapping_issues']++;
            $error_type = 'MAPPING_ISSUE';
            echo "⚠️  Проблемы с маппингом\n";
            
            // Записываем проблемы с маппингом
            $mapping_issues = [];
            if (!$validation['external_id_match']) {
                $mapping_issues[] = "external_id не совпадает: Bitrix={$result['bitrix']['external_id']}, Alma={$result['alma']['external_id']}";
            }
            if (!$validation['additional_external_id_match']) {
                $mapping_issues[] = "additional_external_id не совпадает: Bitrix={$result['bitrix']['additional_external_id']}, Alma={$result['alma']['additional_external_id']}";
            }
            if (!$validation['title_match']) {
                $mapping_issues[] = "названия не совпадают: Bitrix='{$result['bitrix']['unit_title']}', Alma='{$result['alma']['object_name']}'";
            }
            
            $detailed_errors[] = [
                'contract_id' => $contractId,
                'error_type' => $error_type,
                'error_message' => 'Проблемы с маппингом: ' . implode('; ', $mapping_issues),
                'bitrix_data' => $result['bitrix'],
                'alma_data' => $result['alma'],
                'validation' => $result['validation']
            ];
        }
    }
    
    // Пауза между запросами
    usleep(100000); // 0.1 секунды
}

echo "\n=== СТАТИСТИКА ===\n";
echo "Всего контрактов: " . $stats['total'] . "\n";
echo "✅ Успешно: " . $stats['success'] . "\n";
echo "❌ Не найден в Bitrix24: " . $stats['not_found_bitrix'] . "\n";
echo "❌ Не найден в Alma: " . $stats['not_found_alma'] . "\n";
echo "❌ Нет unit_external_id: " . $stats['no_unit_id'] . "\n";
echo "⚠️  Ex-Apartments: " . $stats['ex_apartments'] . "\n";
echo "⚠️  Проблемы с маппингом: " . $stats['mapping_issues'] . "\n";

// Сохраняем результаты в файлы
file_put_contents('contract_validation_results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents('contract_validation_errors.json', json_encode($detailed_errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Создаем CSV файл с ошибками для удобного просмотра
$csv = "Contract ID,Error Type,Error Message,Bitrix Unit ID,Bitrix External ID,Bitrix Additional ID,Alma Object ID,Alma External ID,Alma Additional ID\n";
foreach ($detailed_errors as $error) {
    $csv .= sprintf("%s,%s,\"%s\",%s,%s,%s,%s,%s,%s\n",
        $error['contract_id'],
        $error['error_type'],
        str_replace('"', '""', $error['error_message']),
        $error['bitrix_data']['unit_id'] ?? '',
        $error['bitrix_data']['external_id'] ?? '',
        $error['bitrix_data']['additional_external_id'] ?? '',
        $error['alma_data']['object_id'] ?? '',
        $error['alma_data']['external_id'] ?? '',
        $error['alma_data']['additional_external_id'] ?? ''
    );
}
file_put_contents('contract_validation_errors.csv', $csv);

echo "\nРезультаты сохранены в:\n";
echo "- contract_validation_results.json (полные результаты)\n";
echo "- contract_validation_errors.json (только ошибки)\n";
echo "- contract_validation_errors.csv (ошибки в CSV формате)\n";
?>
