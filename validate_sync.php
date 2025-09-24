<?php
/**
 * Валидатор синхронизации контрактов между Bitrix24 и Alma
 * 
 * Использование:
 * 1. Через веб-сервер:
 *    https://alma.colifeb24apps.ru/validate_sync.php?id=1234
 *    https://alma.colifeb24apps.ru/validate_sync.php?start=1000&end=1100
 * 
 * 2. Через curl:
 *    curl "https://alma.colifeb24apps.ru/validate_sync.php?id=1234"
 * 
 * 3. Для отладки Bitrix24 данных:
 *    curl "https://alma.colifeb24apps.ru/validate_sync.php?debug=1234"
 */

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');
require_once('ProjectMapping.php');

// Получаем проект из параметра или определяем автоматически
$projectName = $_GET['project'] ?? 'Dubai';

// Получаем маппинг полей для проекта
$fieldMapping = ProjectMapping::getFieldMapping($projectName);

Config::load();
$projectConfig = ProjectMapping::getProjectConfig($projectName);

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', $projectConfig['id']);
define('WEBHOOK_URL', $projectConfig['webhook_url']);

class SyncValidator {
    private $bitrix;
    private $almaApiKey;
    private $almaApiUrl;
    private $fieldMapping;

    public function __construct() {
        global $fieldMapping;
        $this->bitrix = new Bitrix24Rest(WEBHOOK_URL);
        $this->almaApiKey = ALMA_API_KEY;
        $this->almaApiUrl = ALMA_API_URL;
        $this->fieldMapping = $fieldMapping;
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
            
            // Отладочная информация
            if (isset($_GET['debug']) && $_GET['debug'] == $unitId) {
                echo "DEBUG: Unit fields for ID $unitId:\n";
                echo json_encode($unit, JSON_PRETTY_PRINT) . "\n";
            }
            
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
            
            // Получаем rent_type из связанного апартамента
            $rentType = null;
            $rentTypeValue = null;
            $isUsedAdditionalExternalId = null;
            
            if ($apartmentId && $apartmentId != $unit['id']) {
                // Получаем данные апартамента для определения rent_type
                $apartmentResponse = $this->bitrix->call('crm.item.get', [
                    'entityTypeId' => 144, // Апартаменты
                    'id' => $apartmentId,
                ]);
                
                if (isset($apartmentResponse['result']['item'])) {
                    $apartment = $apartmentResponse['result']['item'];
                    $rentTypeField = $this->fieldMapping['fields']['rent_type'];
                    $rentTypeValue = $apartment[$rentTypeField] ?? '4598'; // По умолчанию unit
                    $rentTypeMapping = $this->fieldMapping['rent_type_mapping'];
                    $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';
                    $isUsedAdditionalExternalId = ($rentType === 'rooms'); // rooms = шеринговый
                }
            }
            
            return [
                'type' => 'unit',
                'id' => $unit['id'],
                'title' => $unit['title'],
                'external_id' => $apartmentId, // ID апартамента, к которому привязан юнит
                'additional_external_id' => $unit['id'], // ID самого юнита
                'stage_for_alma' => null, // У юнитов нет поля Stage for alma
                'rent_type' => $rentType,
                'rent_type_value' => $rentTypeValue,
                'is_used_additional_external_id' => $isUsedAdditionalExternalId
            ];
        }

        // Если не найден как юнит, пробуем как апартамент
        $response = $this->bitrix->call('crm.item.get', [
            'entityTypeId' => 144, // Апартаменты
            'id' => $unitId,
        ]);

        if (isset($response['result']['item'])) {
            $unit = $response['result']['item'];
            
            // Определяем тип аренды из поля "Rent (by rooms / unit)"
            $rentTypeField = $this->fieldMapping['fields']['rent_type'];
            $rentTypeValue = $unit[$rentTypeField] ?? '4598'; // По умолчанию unit
            $rentTypeMapping = $this->fieldMapping['rent_type_mapping'];
            $rentType = $rentTypeMapping[$rentTypeValue] ?? 'unit';

            $isUsedAdditionalExternalId = ($rentType === 'rooms'); // rooms = шеринговый
            
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
                'stage_for_alma' => $unit['ufCrm6_1742486676'] ?? null, // Поле "Stage for alma"
                'rent_type' => $rentType,
                'rent_type_value' => $rentTypeValue,
                'is_used_additional_external_id' => $isUsedAdditionalExternalId
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
            if ($httpCode === 404) {
                // Объект не найден - возможно архивирован
                echo "⚠️  Объект с external_id '$externalId' не найден (возможно архивирован)\n";
            }
            error_log("Rental object API error: HTTP $httpCode, Response: $response");
            return null;
        }

        $rentalObjectData = json_decode($response, true);
        
        if (!isset($rentalObjectData['id'])) {
            error_log("Rental object not found for external_id: $externalId, Response: " . json_encode($rentalObjectData));
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
        echo "🔍 Проверяем контракт $contractId...\n";

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
        
        // 6. Проверяем правильность is_used_additional_external_id для апартаментов
        $isUsedAdditionalExternalIdCorrect = true;
        $isUsedAdditionalExternalIdExpected = null;
        $isUsedAdditionalExternalIdActual = null;
        
        if ($bitrixUnit['type'] === 'apartment' && isset($bitrixUnit['is_used_additional_external_id'])) {
            $isUsedAdditionalExternalIdExpected = $bitrixUnit['is_used_additional_external_id'];
            $isUsedAdditionalExternalIdActual = $almaObject['is_used_additional_external_id'] ?? null;
            $isUsedAdditionalExternalIdCorrect = ($isUsedAdditionalExternalIdExpected === $isUsedAdditionalExternalIdActual);
        }

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
                'stage_for_alma' => $bitrixUnit['stage_for_alma'],
                'rent_type' => $bitrixUnit['rent_type'] ?? null,
                'rent_type_value' => $bitrixUnit['rent_type_value'] ?? null,
                'is_used_additional_external_id' => $isUsedAdditionalExternalIdExpected
            ],
            'alma' => [
                'object_id' => $almaObject['id'],
                'object_name' => $almaTitle,
                'external_id' => $almaObject['external_id'],
                'additional_external_id' => $almaObject['additional_external_id'],
                'is_used_additional_external_id' => $isUsedAdditionalExternalIdActual
            ],
            'validation' => [
                'title_match' => $isTitleMatch,
                'external_id_match' => $bitrixUnit['external_id'] == $almaObject['external_id'],
                'additional_external_id_match' => $bitrixUnit['additional_external_id'] == $almaObject['additional_external_id'],
                'is_used_additional_external_id_correct' => $isUsedAdditionalExternalIdCorrect,
                'is_used_additional_external_id_expected' => $isUsedAdditionalExternalIdExpected,
                'is_used_additional_external_id_actual' => $isUsedAdditionalExternalIdActual
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

    /**
     * Найти объект в Alma по additional_external_id
     */
    public function findAlmaObjectByAdditionalId($additionalExternalId) {
        $url = rtrim($this->almaApiUrl, '/') . "/realty/rental_object/" . urlencode($additionalExternalId) . "/";
        
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

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['id'])) {
                return $this->getAlmaObject($data['external_id']);
            }
        }
        
        return null;
    }

    /**
     * Обновить объект в Alma через PATCH
     */
    public function updateAlmaObject($almaId, $updateData) {
        $url = rtrim($this->almaApiUrl, '/') . "/realty/units/" . $almaId . "/";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode($updateData),
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    /**
     * Найти объект в Alma по external_id
     */
    public function findAlmaObjectByExternalId($externalId) {
        $url = rtrim($this->almaApiUrl, '/') . "/realty/rental_object/" . urlencode($externalId) . "/";
        
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

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['id'])) {
                return $this->getAlmaObject($data['external_id']);
            }
        }
        
        return null;
    }

    /**
     * Найти объект в Alma по additional_external_id через поиск
     */
    public function findAlmaObjectByAdditionalIdSearch($additionalExternalId) {
        // Используем API поиска для поиска по additional_external_id
        $url = rtrim($this->almaApiUrl, '/') . "/realty/units/?additional_external_id=" . urlencode($additionalExternalId);
        
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

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['results']) && !empty($data['results'])) {
                return $data['results'][0];
            }
        }
        
        return null;
    }

    /**
     * Освободить конфликтующие ID в Alma
     */
    public function freeConflictingIds($externalId, $additionalExternalId, $targetAlmaId) {
        echo "🔍 Ищем конфликтующие объекты...\n";
        
        $conflicts = [];
        
        // Ищем объект с external_id
        if ($externalId) {
            $conflictObject = $this->findAlmaObjectByExternalId($externalId);
            if ($conflictObject && $conflictObject['id'] != $targetAlmaId) {
                $conflicts[] = [
                    'type' => 'external_id',
                    'object' => $conflictObject,
                    'conflicting_value' => $externalId
                ];
            }
        }
        
        // Ищем объект с additional_external_id
        if ($additionalExternalId) {
            $conflictObject = $this->findAlmaObjectByAdditionalIdSearch($additionalExternalId);
            if ($conflictObject && $conflictObject['id'] != $targetAlmaId) {
                $conflicts[] = [
                    'type' => 'additional_external_id',
                    'object' => $conflictObject,
                    'conflicting_value' => $additionalExternalId
                ];
            }
        }
        
        // Также ищем объекты, которые могут иметь эти ID в других полях
        // Это нужно для случаев, когда additional_external_id используется как external_id в другом объекте
        if ($additionalExternalId) {
            $conflictObject = $this->findAlmaObjectByExternalId($additionalExternalId);
            if ($conflictObject && $conflictObject['id'] != $targetAlmaId) {
                $conflicts[] = [
                    'type' => 'external_id',
                    'object' => $conflictObject,
                    'conflicting_value' => $additionalExternalId
                ];
            }
        }
        
        if (empty($conflicts)) {
            echo "✅ Конфликтов не найдено\n";
            return true;
        }
        
        echo "⚠️  Найдено " . count($conflicts) . " конфликтующих объектов:\n";
        
        foreach ($conflicts as $conflict) {
            $obj = $conflict['object'];
            echo "  - Объект {$obj['id']}: {$obj['name']} (статус: " . ($obj['stage'] ?? 'unknown') . ")\n";
            
            // Проверяем, не является ли это Ex-объектом
            $stage = $obj['stage'] ?? '';
            if (strpos($stage, 'Ex-') === 0) {
                echo "    ⚠️  Пропускаем Ex-объект\n";
                continue;
            }
            
            // Освобождаем конфликтующий ID, добавляя суффикс _old
            $updateData = [];
            if ($conflict['type'] === 'external_id') {
                $updateData['external_id'] = $conflict['conflicting_value'] . '_old';
                echo "    🔄 Освобождаем external_id = {$conflict['conflicting_value']} (переименовываем в: {$conflict['conflicting_value']}_old)\n";
            } else {
                $updateData['additional_external_id'] = $conflict['conflicting_value'] . '_old';
                echo "    🔄 Освобождаем additional_external_id = {$conflict['conflicting_value']} (переименовываем в: {$conflict['conflicting_value']}_old)\n";
            }
            
            $result = $this->updateAlmaObject($obj['id'], $updateData);
            if ($result['success']) {
                echo "    ✅ ID успешно освобожден\n";
            } else {
                echo "    ❌ Ошибка освобождения ID: " . $result['response'] . "\n";
                return false;
            }
        }
        
        return true;
    }

    /**
     * Исправить маппинг для контракта
     */
    public function fixContractMapping($contractId) {
        echo "=== Исправление маппинга для контракта $contractId ===\n";

        // 1. Получаем данные из Bitrix24
        $bitrixContract = $this->getBitrixContract($contractId);
        if (!$bitrixContract) {
            echo "❌ Контракт не найден в Bitrix24\n";
            return false;
        }

        $unitExternalId = $bitrixContract['unit_external_id'];
        if (empty($unitExternalId)) {
            echo "❌ Не указан unit_external_id в контракте\n";
            return false;
        }

        $bitrixUnit = $this->getBitrixUnit($unitExternalId);
        if (!$bitrixUnit) {
            echo "❌ Объект (апартамент/юнит) не найден в Bitrix24 по ID: $unitExternalId\n";
            return false;
        }

        echo "📋 Данные из Bitrix24:\n";
        echo "  - Тип: " . $bitrixUnit['type'] . "\n";
        echo "  - Название: " . $bitrixUnit['title'] . "\n";
        echo "  - External ID: " . $bitrixUnit['external_id'] . "\n";
        echo "  - Additional External ID: " . $bitrixUnit['additional_external_id'] . "\n";
        echo "  - Stage for Alma: " . ($bitrixUnit['stage_for_alma'] ?? 'не указан') . "\n";

        // Проверяем статус "Stage for alma" для апартаментов
        if ($bitrixUnit['type'] === 'apartment' && $bitrixUnit['stage_for_alma']) {
            if ($bitrixUnit['stage_for_alma'] === 'Ex-Apartments') {
                echo "⚠️  Апартамент имеет статус 'Ex-Apartments' - пропускаем исправление\n";
                return true;
            }
        }

        // 2. Находим объект в Alma по additional_external_id (если есть)
        $almaObject = null;
        if ($bitrixUnit['additional_external_id']) {
            $almaObject = $this->findAlmaObjectByAdditionalId($bitrixUnit['additional_external_id']);
        }

        if (!$almaObject) {
            echo "❌ Объект не найден в Alma (additional_external_id: " . $bitrixUnit['additional_external_id'] . ")\n";
            return false;
        }

        echo "📋 Данные из Alma:\n";
        echo "  - ID: " . $almaObject['id'] . "\n";
        echo "  - Название: " . $almaObject['name'] . "\n";
        echo "  - External ID: " . $almaObject['external_id'] . "\n";
        echo "  - Additional External ID: " . $almaObject['additional_external_id'] . "\n";
        echo "  - Статус: " . ($almaObject['stage'] ?? 'unknown') . "\n";

        // 3. Проверяем, нужно ли исключить объект
        $stage = $almaObject['stage'] ?? '';
        if (strpos($stage, 'Ex-') === 0) {
            echo "⚠️  Объект имеет статус '$stage' - пропускаем (Ex-Apartments/Ex-units не трогаем)\n";
            return true;
        }

        // 4. Проверяем, нужно ли обновлять
        $needsUpdate = false;
        $updateData = [];

        if ($almaObject['external_id'] !== $bitrixUnit['external_id']) {
            echo "🔄 External ID не совпадает: Alma='{$almaObject['external_id']}', Bitrix='{$bitrixUnit['external_id']}'\n";
            $updateData['external_id'] = $bitrixUnit['external_id'];
            $needsUpdate = true;
        }

        if ($almaObject['additional_external_id'] !== $bitrixUnit['additional_external_id']) {
            echo "🔄 Additional External ID не совпадает: Alma='{$almaObject['additional_external_id']}', Bitrix='{$bitrixUnit['additional_external_id']}'\n";
            $updateData['additional_external_id'] = $bitrixUnit['additional_external_id'];
            $needsUpdate = true;
        }

        if (!$needsUpdate) {
            echo "✅ Маппинг уже корректен\n";
            return true;
        }

        // 5. Сначала освобождаем конфликтующие ID, добавляя _old к текущим значениям
        echo "🔄 Освобождаем конфликтующие ID...\n";
        $tempUpdateData = [];
        
        if ($almaObject['external_id'] && $almaObject['external_id'] != $bitrixUnit['external_id']) {
            $tempUpdateData['external_id'] = $almaObject['external_id'] . '_old';
            echo "  - Переименовываем external_id: {$almaObject['external_id']} -> {$almaObject['external_id']}_old\n";
        }
        
        if ($almaObject['additional_external_id'] && $almaObject['additional_external_id'] != $bitrixUnit['additional_external_id']) {
            $tempUpdateData['additional_external_id'] = $almaObject['additional_external_id'] . '_old';
            echo "  - Переименовываем additional_external_id: {$almaObject['additional_external_id']} -> {$almaObject['additional_external_id']}_old\n";
        }
        
        if (!empty($tempUpdateData)) {
            $tempResult = $this->updateAlmaObject($almaObject['id'], $tempUpdateData);
            if (!$tempResult['success']) {
                echo "❌ Ошибка освобождения ID: " . $tempResult['response'] . "\n";
                return false;
            }
            echo "✅ Конфликтующие ID освобождены\n";
        }

        // 6. Теперь обновляем объект с правильными значениями
        echo "🔄 Обновляем объект с правильными значениями...\n";
        $result = $this->updateAlmaObject($almaObject['id'], $updateData);

        if ($result['success']) {
            echo "✅ Объект успешно обновлен в Alma\n";
            return true;
        } else {
            echo "❌ Ошибка обновления объекта в Alma: HTTP " . $result['http_code'] . "\n";
            echo "Ответ: " . $result['response'] . "\n";
            return false;
        }
    }
}

// Проверяем один контракт
if (isset($_GET['id'])) {
    $validator = new SyncValidator();
    $result = $validator->validateContract($_GET['id']);
    
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверяем диапазон контрактов
if (isset($_GET['start']) && isset($_GET['end'])) {
    $startId = (int)$_GET['start'];
    $endId = (int)$_GET['end'];
    
    $validator = new SyncValidator();
    $results = [];
    
    for ($contractId = $startId; $contractId <= $endId; $contractId++) {
        $result = $validator->validateContract($contractId);
        $results[$contractId] = $result;
        
        if ($result['success']) {
            $validation = $result['validation'];
            $bitrix = $result['bitrix'];
            
            $issues = [];
            if (!$validation['title_match']) $issues[] = "названия не совпадают";
            if (!$validation['external_id_match']) $issues[] = "external_id не совпадает";
            if (!$validation['additional_external_id_match']) $issues[] = "additional_external_id не совпадает";
            if (!$validation['is_used_additional_external_id_correct']) $issues[] = "is_used_additional_external_id неправильно";
            
            if (empty($issues)) {
                echo "✅ Контракт $contractId: OK\n";
            } else {
                echo "⚠️  Контракт $contractId: " . implode(', ', $issues) . "\n";
                echo "   Bitrix: {$bitrix['unit_title']} (тип: {$bitrix['rent_type']}, is_used: " . ($bitrix['is_used_additional_external_id'] ? 'true' : 'false') . ")\n";
                echo "   Alma: {$result['alma']['object_name']} (is_used: " . ($result['alma']['is_used_additional_external_id'] ? 'true' : 'false') . ")\n";
            }
        } else {
            echo "❌ Контракт $contractId: {$result['error']}\n";
        }
        
        // Пауза между запросами
        usleep(100000); // 0.1 секунды
    }
    
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Исправляем маппинг для одного контракта
if (isset($_GET['fix'])) {
    $validator = new SyncValidator();
    $result = $validator->fixContractMapping($_GET['fix']);
    exit;
}

// Отладка юнита
if (isset($_GET['debug'])) {
    $validator = new SyncValidator();
    $unitData = $validator->getBitrixUnit($_GET['debug']);
    echo "Unit data for ID " . $_GET['debug'] . ":\n";
    echo json_encode($unitData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo "Использование:\n";
echo "?id=1234 - проверить один контракт\n";
echo "?start=1000&end=1100 - проверить диапазон контрактов\n";
echo "?fix=1234 - исправить маппинг для контракта\n";
