<?php
/**
 * Alma Tenant Contract Synchronization API
 * 
 * Улучшения для предотвращения ошибок маппинга:
 * - Добавлена валидация найденных объектов недвижимости
 * - Проверка соответствия external_id
 * - Детальное логирование для отладки
 * - Предупреждения о потенциальных несоответствиях
 * 
 * @version 2.0
 * @author Updated with validation improvements
 */

set_time_limit(60);
ini_set('max_execution_time', 60);

require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');

// Загружаем конфигурацию
Config::load();

define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));
define('PROJECT_ID', (int)Config::get('PROJECT_ID'));
define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));
class AlmaTenantContractApi {
    private $apiKey;
    private $apiUrl;
    private $actionLogger;
    private $bitrixData;
    private $unitDetailsCache = [];

    public function __construct($apiKey = ALMA_API_KEY, $apiUrl = ALMA_API_URL) {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
        $this->actionLogger = new ContractActionLogger();
    }

    public function syncContract(array $bitrixData) {
        $this->validateBitrixData($bitrixData);
        $this->bitrixData = $bitrixData; // Сохраняем данные для использования в других методах

        try {
            Logger::info("Starting contract sync for external_id: " . ($bitrixData['id'] ?? 'unknown'), [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            $clientId = $this->ensureClientExists($bitrixData['client_data']);
            Logger::debug("Client ID: $clientId", [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            $unitId = $this->getRentalObjectId($bitrixData['unit_external_id']);
            Logger::debug("Unit ID: $unitId", [], 'contract', $bitrixData['id'] ?? 'unknown');
            
            // Дополнительная валидация найденного объекта
            $this->validateRentalObject($unitId, $bitrixData);
            
            $contractData = $this->prepareContractData($bitrixData, $clientId, $unitId);
            $externalId = $contractData['external_id'];
            Logger::debug("Prepared contract data with external_id: $externalId", [], 'contract', $externalId);
            
            $existingContract = $this->getContractByExternalId($externalId);

            if ($existingContract) {
                Logger::info("Found existing contract by external_id, updating...", [], 'contract', $externalId);
                return $this->updateContract($existingContract['id'], $contractData);
            } else {
                Logger::info("No existing contract found by external_id, creating new contract...", [], 'contract', $externalId);
                try {
                    return $this->createContract($contractData);
                } catch (Exception $createError) {
                    Logger::error("Error creating contract: " . $createError->getMessage(), [], 'contract', $externalId);
                    

                    if (strpos($createError->getMessage(), 'intersections in the use of the unit') !== false || 
                        strpos($createError->getMessage(), 'active usage record already exists') !== false) {
                        
                        Logger::warning("Unit usage conflict detected, trying to find existing contract...", [], 'contract', $externalId);
                        

                        if (preg_match('/usage id:(\d+)/', $createError->getMessage(), $matches)) {
                            $existingUsageId = $matches[1];
                            Logger::debug("Found existing usage ID: $existingUsageId", [], 'contract', $externalId);
                            
                            $existingContract = $this->findContractByUsageId($existingUsageId);
                            if ($existingContract) {
                                Logger::info("Found existing contract by usage ID, updating...", [], 'contract', $externalId);
                                return $this->updateContract($existingContract['id'], $contractData);
                            }
                        }
                        

                        $foundContract = $this->findActiveContractByClientAndUnit($clientId, $unitId);
                        if ($foundContract) {
                            Logger::info("Found existing contract by client and unit, updating...", [], 'contract', $externalId);
                            return $this->updateContract($foundContract['id'], $contractData);
                        }
                    }
                    
                    throw $createError;
                }
            }
        } catch (Exception $e) {
            Logger::error("Contract synchronization failed: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw new Exception("Contract synchronization failed: " . $e->getMessage());
        }
    }

    public function createContract(array $contractData) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
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
                'price' => $contractData['price'] ?? ''
            ]
        );
        
        return $response;
    }

    public function updateContract($contractId, array $contractData) {
        $oldContractData = $this->getContract($contractId);
        
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        $response = $this->sendRequest('PATCH', $url, $contractData);
        
        $this->actionLogger->logUpdate(
            $contractId,
            $contractData['name'] ?? '',
            $oldContractData,
            $contractData
        );
        
        return $response;
    }



    public function getContract($contractId) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/' . $contractId . '/';
        return $this->sendRequest('GET', $url);
    }

    public function getContractByExternalId($externalId) {
        $url = $this->apiUrl . 'realty/contracts/tenant_contracts/external_id/' . $externalId . '/';

        try {
            $result = $this->sendRequest('GET', $url);
            Logger::debug("Successfully found contract by external_id: $externalId");
            return $result;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                Logger::debug("Contract not found by external_id: $externalId (404)");
                return null;
            }
            Logger::error("Error getting contract by external_id: $externalId - " . $e->getMessage());
            throw $e;
        }
    }

    public function findContractByUsageId($usageId) {
        try {

            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
            $contracts = $this->sendRequest('GET', $url);
            $contractsToCheck = array_slice($contracts, -100);
            
            Logger::debug("Searching for contract with usage_id: $usageId in " . count($contractsToCheck) . " contracts");
            

            foreach ($contractsToCheck as $contract) {
                try {
                    $contractDetails = $this->getContract($contract['id']);
                    
                    if (isset($contractDetails['unit_usage']) && 
                        $contractDetails['unit_usage']['usage_id'] == $usageId) {
                        
                        Logger::debug("Found contract with usage_id $usageId: " . $contract['id']);
                        return $contractDetails;
                    }
                } catch (Exception $e) {
                    Logger::warning("Error getting contract details for ID {$contract['id']}: " . $e->getMessage());
                    continue;
                }
            }
            
            Logger::debug("No contract found with usage_id: $usageId");
            return null;
        } catch (Exception $e) {
            Logger::error("Error getting contracts list: " . $e->getMessage());
            return null;
        }
    }

    public function findActiveContractByClientAndUnit($clientId, $unitId) {
        try {

            $url = $this->apiUrl . 'realty/contracts/tenant_contracts/';
            $contracts = $this->sendRequest('GET', $url);
            $contractsToCheck = array_slice($contracts, -50);
            
            Logger::debug("Checking " . count($contractsToCheck) . " contracts out of " . count($contracts) . " total");
            

            foreach ($contractsToCheck as $contract) {
                try {
                    $contractDetails = $this->getContract($contract['id']);
                    
                    if (isset($contractDetails['unit_usage']) && 
                        $contractDetails['unit_usage']['client_id'] == $clientId && 
                        $contractDetails['unit_usage']['unit_id'] == $unitId &&
                        !$contractDetails['unit_usage']['is_archived']) {
                        
                        Logger::debug("Found active contract: " . $contract['id']);
                        return $contractDetails;
                    }
                } catch (Exception $e) {

                    Logger::warning("Error getting contract details for ID {$contract['id']}: " . $e->getMessage());
                    continue;
                }
            }
            
            Logger::debug("No active contract found for client $clientId and unit $unitId");
            return null;
        } catch (Exception $e) {
            Logger::error("Error getting contracts list: " . $e->getMessage());
            return null;
        }
    }

    public function getRentalObjectId($externalId) {
        // Согласно документации (строки 510-518), rental_object API возвращает наиболее подходящую запись по внешнему id
        // Это основной способ определения внутреннего id апартамента или юнита
        $url = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
        $response = $this->sendRequest('GET', $url);

        if (!isset($response['id'])) {
            Logger::warning("Rental object not found via rental_object API", ['external_id' => $externalId]);
            throw new Exception("Rental object not found for external_id: $externalId");
        }

        $unitId = $response['id'];
        
        Logger::info("Found rental object via rental_object API", [
            'unit_id' => $unitId, 
            'external_id' => $externalId,
            'additional_external_id' => $response['additional_external_id'] ?? '',
            'is_used_additional_external_id' => $response['is_used_additional_external_id'] ?? false,
            'parent_unit' => $response['parent_unit'] ?? null
        ]);
        
        // Получаем детали юнита для валидации
        $unitDetails = $this->getUnitDetails($unitId);
        $unitName = $unitDetails['name'] ?? '';
        $expectedUnitName = $this->extractUnitNameFromContract($this->bitrixData['title'] ?? '');
        
        // Дополнительная проверка: сравниваем external_id или additional_external_id
        $currentExternalId = $unitDetails['external_id'] ?? '';
        $currentAdditionalExternalId = $unitDetails['additional_external_id'] ?? '';
        $isUsedAdditionalExternalId = $unitDetails['is_used_additional_external_id'] ?? false;
        
        // Определяем, какой ID используется как основной
        // Если is_used_additional_external_id = true, но additional_external_id пустой, используем external_id
        $actualUsedId = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? $currentAdditionalExternalId : $currentExternalId;
        
        if ($actualUsedId !== $externalId) {
            // АВТОМАТИЧЕСКАЯ ДИАГНОСТИКА И ИСПРАВЛЕНИЕ EXTERNAL_ID МАППИНГОВ
            $this->validateAndFixExternalIdMapping($unitDetails, $externalId, $unitId, $this->bitrixData['id'] ?? 'unknown');
            
            $usedField = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? 'additional_external_id' : 'external_id';
            Logger::error("CRITICAL: Unit $unitId has $usedField '$actualUsedId' but expected '$externalId'. This indicates data inconsistency in Alma.", [], 'contract', $this->bitrixData['id'] ?? 'unknown');
            throw new Exception("CRITICAL MISMATCH: Unit $unitId has $usedField '$actualUsedId' but expected '$externalId'. This indicates data inconsistency in Alma.");
        }
        
        $usedField = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? 'additional_external_id' : 'external_id';
        Logger::info("Unit ID validation passed: using $usedField '$actualUsedId'", [], 'contract', $this->bitrixData['id'] ?? 'unknown');
        
        // Если названия не совпадают, пытаемся найти правильный юнит
        if (!empty($expectedUnitName) && !$this->isUnitNameMatching($unitName, $expectedUnitName)) {
            Logger::warning("Unit name mismatch detected. Expected: '$expectedUnitName', Found: '$unitName'. Attempting to find correct unit...");
            
            $correctedId = $this->findAndFixIncorrectExternalId($externalId);
            if ($correctedId) {
                Logger::info("Successfully corrected external_id from '$externalId' to '$correctedId'");
                return $correctedId;
            }
        }

        return $unitId;
    }

    private function validateRentalObject($unitId, array $bitrixData) {
        try {
            // Получаем полную информацию об объекте
            $unitUrl = $this->apiUrl . 'realty/units/' . $unitId . '/';
            $unitDetails = $this->sendRequest('GET', $unitUrl);
            
            $unitName = $unitDetails['name'] ?? 'Unknown';
            $buildingName = isset($unitDetails['building']['name']) ? $unitDetails['building']['name'] : 'Unknown';
            $externalId = $bitrixData['unit_external_id'] ?? 'unknown';
            $contractId = $bitrixData['id'] ?? 'unknown';
            
            Logger::info("Validating rental object: ID=$unitId, Name=$unitName, Building=$buildingName", [], 'contract', $contractId);
            
            // Проверяем, что объект не заблокирован
            if (isset($unitDetails['status']) && $unitDetails['status'] === 'blocked') {
                throw new Exception("Cannot create contract on blocked unit: $unitName");
            }
            
            // Проверяем, что объект не заархивирован
            if (isset($unitDetails['is_archived']) && $unitDetails['is_archived']) {
                throw new Exception("Cannot create contract on archived unit: $unitName");
            }
            
            // Проверяем соответствие external_id или additional_external_id - это критично!
            $currentExternalId = $unitDetails['external_id'] ?? '';
            $currentAdditionalExternalId = $unitDetails['additional_external_id'] ?? '';
            $isUsedAdditionalExternalId = $unitDetails['is_used_additional_external_id'] ?? false;
            
            // Определяем, какой ID используется как основной
            // Если is_used_additional_external_id = true, но additional_external_id пустой, используем external_id
            $actualUsedId = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? $currentAdditionalExternalId : $currentExternalId;
            
            if ($actualUsedId !== $externalId) {
                // АВТОМАТИЧЕСКАЯ ДИАГНОСТИКА И ИСПРАВЛЕНИЕ EXTERNAL_ID МАППИНГОВ
                $this->validateAndFixExternalIdMapping($unitDetails, $externalId, $unitId, $contractId);
                
                $usedField = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? 'additional_external_id' : 'external_id';
                throw new Exception("CRITICAL MISMATCH: Unit $unitId has $usedField '$actualUsedId' but expected '$externalId'. This indicates data inconsistency in Alma. Unit: $unitName, Building: $buildingName");
            }
            
            // Дополнительная проверка: сравниваем название объекта с ожидаемым из контракта
            $contractTitle = $bitrixData['title'] ?? '';
            $expectedUnitName = $this->extractUnitNameFromContract($contractTitle);
            
            if (!empty($expectedUnitName) && !$this->isUnitNameMatching($unitName, $expectedUnitName)) {
                // ВАЖНО: Если external_id совпадает, но названия разные, это может быть нормально
                // если найден апартамент вместо юнита (Ap. vs Un.)
                Logger::warning("Unit name mismatch detected. Expected: '$expectedUnitName', Found: '$unitName'. This might be apartment-unit relationship.", [], 'contract', $contractId);
                
                // Проверяем, является ли это случаем апартамент-юнит
                if (!$this->isApartmentUnitRelationship($unitName, $expectedUnitName)) {
                    throw new Exception("CRITICAL MISMATCH: Unit name mismatch. Expected '$expectedUnitName' but found '$unitName'. Unit ID: $unitId, External ID: $externalId. This indicates wrong unit mapping in Alma.");
                } else {
                    Logger::info("✓ Apartment-Unit relationship confirmed. Proceeding with contract creation.", [], 'contract', $contractId);
                }
            }
            
            Logger::debug("Rental object validation passed: $unitName in $buildingName", [], 'contract', $contractId);
            
        } catch (Exception $e) {
            Logger::error("Error validating rental object: " . $e->getMessage(), [], 'contract', $bitrixData['id'] ?? 'unknown');
            throw $e;
        }
    }

    /**
     * Проверяет наличие дублирующих объектов с одинаковым external_id
     */
    private function checkForDuplicateExternalIds($externalId) {
        try {
            // Попробуем найти все объекты с таким external_id
            $searchUrl = $this->apiUrl . 'realty/units/?search=' . urlencode($externalId);
            $searchResults = $this->sendRequest('GET', $searchUrl);
            
            if (is_array($searchResults) && count($searchResults) > 1) {
                Logger::warning("Found multiple objects with similar external_id: $externalId", [], 'contract', $externalId);
                foreach ($searchResults as $obj) {
                    if (isset($obj['external_id']) && $obj['external_id'] === $externalId) {
                        Logger::warning("Duplicate external_id found: Unit {$obj['id']} - {$obj['name']}", [], 'contract', $externalId);
                    }
                }
            }
        } catch (Exception $e) {
            // Игнорируем ошибки поиска, так как это не критично
            Logger::debug("Search for duplicates failed: " . $e->getMessage(), [], 'contract', $externalId);
        }
    }
    
    /**
     * Извлекает название объекта из названия контракта
     * Например: "Contract_Стас_Еговцев_Un. 668.1 / CLAREN TOWER 2, apt. 1902_18.09.2025"
     * Вернет: "Un. 668.1 / CLAREN TOWER 2, apt. 1902"
     */
    private function extractUnitNameFromContract($contractTitle) {
        // Ищем паттерн "Un. X.X / BUILDING, apt. XXXX" или "Ap. XXX. BUILDING, apt. XXXX"
        if (preg_match('/(Un\.|Ap\.)\s*[\d\.]+\s*\/\s*[^,]+,\s*apt\.\s*[\d]+/', $contractTitle, $matches)) {
            return trim($matches[0]);
        }
        return '';
    }
    
    /**
     * Проверяет, является ли это случаем апартамент-юнит отношения
     * Когда найден апартамент (Ap.) вместо ожидаемого юнита (Un.)
     */
    private function isApartmentUnitRelationship($actualName, $expectedName) {
        Logger::debug("Checking apartment-unit relationship: Actual='$actualName' vs Expected='$expectedName'");
        
        // Извлекаем ключевые части
        preg_match('/(\w+)\.?\s*(\d+\.?\d*)\s*[.\/]\s*([^,]+),\s*apt\.\s*(\d+)/', $actualName, $actualParts);
        preg_match('/(\w+)\.?\s*(\d+\.?\d*)\s*[.\/]\s*([^,]+),\s*apt\.\s*(\d+)/', $expectedName, $expectedParts);
        
        if (count($actualParts) !== 5 || count($expectedParts) !== 5) {
            Logger::debug("Could not extract parts for apartment-unit relationship check");
            return false;
        }
        
        $actualType = $actualParts[1]; // Ap или Un
        $expectedType = $expectedParts[1]; // Ap или Un
        
        // Проверяем, что это апартамент вместо юнита
        if ($actualType !== 'Ap' || $expectedType !== 'Un') {
            Logger::debug("Not apartment-unit relationship: Actual=$actualType, Expected=$expectedType");
            return false;
        }
        
        // Проверяем совпадение номера (без дробной части для апартамента)
        $actualNumberInt = (int)$actualParts[2];
        $expectedNumberInt = (int)$expectedParts[2];
        
        // Нормализуем названия зданий для сравнения
        $actualBuildingNorm = strtolower(trim($actualParts[3]));
        $expectedBuildingNorm = strtolower(trim($expectedParts[3]));
        
        // Проверяем совпадение здания и квартиры
        $buildingMatch = $actualBuildingNorm === $expectedBuildingNorm;
        $apartmentMatch = $actualParts[4] === $expectedParts[4];
        $numberMatch = $actualNumberInt === $expectedNumberInt;
        
        $isRelationship = $apartmentMatch && $buildingMatch && $numberMatch;
        
        if ($isRelationship) {
            Logger::debug("✓ Apartment-Unit relationship confirmed: Ap.$actualNumberInt matches Un.$expectedNumberInt, Building match=$buildingMatch, Apartment match=$apartmentMatch");
        } else {
            Logger::debug("Not apartment-unit relationship: Number match=$numberMatch, Building match=$buildingMatch, Apartment match=$apartmentMatch");
        }
        
        return $isRelationship;
    }

    /**
     * Проверяет, соответствует ли название объекта ожидаемому
     * Сравнивает основные части (номер, здание, квартиру), игнорируя форматирование
     */
    private function isUnitNameMatching($actualName, $expectedName) {
        Logger::debug("Comparing unit names: Actual='$actualName' vs Expected='$expectedName'");
        
        // Проверяем, что строки не пустые
        if (empty($actualName) || empty($expectedName)) {
            Logger::debug("One or both unit names are empty");
            return false;
        }
        
        // Извлекаем ключевые части - поддерживаем оба формата: "Ap. 668. BUILDING" и "Un. 668.1 / BUILDING"
        preg_match('/(\w+)\.?\s*(\d+\.?\d*)\s*[.\/]\s*([^,]+),\s*apt\.\s*(\d+)/', $actualName, $actualParts);
        preg_match('/(\w+)\.?\s*(\d+\.?\d*)\s*[.\/]\s*([^,]+),\s*apt\.\s*(\d+)/', $expectedName, $expectedParts);
        
        Logger::debug("Extracted parts - Actual: " . json_encode($actualParts) . " Expected: " . json_encode($expectedParts));
        
        if (count($actualParts) !== 5 || count($expectedParts) !== 5) {
            Logger::debug("Failed to extract parts from unit names");
            return false;
        }
        
        // Сравниваем здание и квартиру (игнорируем тип Un/Ap и различия в номерах)
        // Номера могут отличаться (668.1 vs 668), поэтому сравниваем только здание и квартиру
        $actualBuilding = strtolower(trim($actualParts[3]));
        $expectedBuilding = strtolower(trim($expectedParts[3]));
        $buildingMatch = $actualBuilding === $expectedBuilding;
        $apartmentMatch = $actualParts[4] === $expectedParts[4];
        
        Logger::debug("Building match: $buildingMatch, Apartment match: $apartmentMatch");
        
        return $apartmentMatch && $buildingMatch;
    }


    /**
     * Находит и исправляет неправильный external_id в Alma
     * Ищет объект с правильным названием и меняет его external_id
     */
    private function findAndFixIncorrectExternalId($expectedExternalId) {
        try {
            Logger::info("Attempting to find and fix incorrect external_id: $expectedExternalId");
            
            // Получаем все юниты из Alma
            $unitsUrl = $this->apiUrl . 'realty/units/';
            $unitsResponse = $this->sendRequest('GET', $unitsUrl);
            
            if (!isset($unitsResponse['results'])) {
                Logger::warning("No units found in Alma response");
                return null;
            }
            
            $units = $unitsResponse['results'];
            Logger::info("Found " . count($units) . " units in Alma");
            
            // Ищем объект с правильным названием
            foreach ($units as $unit) {
                $unitName = $unit['name'] ?? '';
                $unitId = $unit['id'] ?? null;
                $currentExternalId = $unit['external_id'] ?? '';
                
                // Проверяем, подходит ли этот объект по названию
                if ($this->isUnitNameMatching($unitName, $this->extractUnitNameFromContract($this->bitrixData['title'] ?? ''))) {
                    Logger::info("Found matching unit: ID=$unitId, Name='$unitName', Current external_id='$currentExternalId'");
                    
                    // Если у объекта уже правильный external_id, возвращаем его
                    if ($currentExternalId === $expectedExternalId) {
                        Logger::info("Unit already has correct external_id");
                        return $unitId;
                    }
                    
                    // Пытаемся изменить external_id
                    if ($this->updateUnitExternalId($unitId, $expectedExternalId)) {
                        Logger::info("Successfully updated external_id for unit $unitId to '$expectedExternalId'");
                        return $unitId;
                    }
                }
            }
            
            Logger::warning("No matching unit found for external_id: $expectedExternalId");
            return null;
            
        } catch (Exception $e) {
            Logger::error("Error in findAndFixIncorrectExternalId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Обновляет external_id для юнита в Alma
     */
    private function updateUnitExternalId($unitId, $newExternalId) {
        try {
            $url = $this->apiUrl . 'realty/units/' . $unitId . '/';
            
            // Сначала освобождаем старый external_id, если он занят
            $this->freeExternalId($newExternalId);
            
            $data = [
                'external_id' => $newExternalId
            ];
            
            $response = $this->sendRequest('PATCH', $url, $data);
            
            if (isset($response['id'])) {
                Logger::info("Successfully updated external_id for unit $unitId to '$newExternalId'");
                return true;
            } else {
                Logger::error("Failed to update external_id for unit $unitId: " . json_encode($response));
                return false;
            }
            
        } catch (Exception $e) {
            Logger::error("Error updating external_id for unit $unitId: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получает детали юнита по ID
     */
    private function getUnitDetails($unitId) {
        $url = $this->apiUrl . 'realty/units/' . $unitId . '/';
        return $this->sendRequest('GET', $url);
    }

    /**
     * Освобождает external_id, если он занят другим объектом
     */
    private function freeExternalId($externalId) {
        try {
            // Ищем объект с этим external_id
            $searchUrl = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
            $existingObject = $this->sendRequest('GET', $searchUrl);
            
            if (isset($existingObject['id'])) {
                $objectId = $existingObject['id'];
                $tempExternalId = $externalId . '_temp_' . time();
                
                Logger::info("Freeing external_id '$externalId' from object $objectId, setting to '$tempExternalId'");
                
                // Временно меняем external_id на уникальный
                $updateUrl = $this->apiUrl . 'realty/units/' . $objectId . '/';
                $data = ['external_id' => $tempExternalId];
                
                $this->sendRequest('PATCH', $updateUrl, $data);
                Logger::info("Successfully freed external_id '$externalId'");
            }
            
        } catch (Exception $e) {
            // Если объект не найден, это нормально - external_id уже свободен
            if (strpos($e->getMessage(), '404') !== false) {
                Logger::info("External_id '$externalId' is already free");
            } else {
                Logger::warning("Error freeing external_id '$externalId': " . $e->getMessage());
            }
        }
    }

    public function ensureClientExists(array $clientData) {
        try {
            $url = $this->apiUrl . 'users/clients/external_id/' . $clientData['id'] . '/';
            $client = $this->sendRequest('GET', $url);
            return $client['id'];
        } catch (Exception $e) {
            try {
                $url = $this->apiUrl . 'users/clients/';
                $birthday = !empty($clientData['birthday']) ? $this->formatBirthday($clientData['birthday']) : null;
                $newClient = $this->sendRequest('POST', $url, [
                    'external_id' => $clientData['id'],
                    'first_name' => $clientData['first_name'],
                    'last_name' => $clientData['last_name'],
                    'email' => $clientData['email'],
                    'phone' => $clientData['phone'],
                    'country' => 4,
                    'birthday' => $birthday
                ]);
                return $newClient['id'];
            } catch (Exception $createException) {
                if (strpos($createException->getMessage(), 'email') !== false && 
                    strpos($createException->getMessage(), 'already exists') !== false) {
                    
                    try {
                        $allClientsUrl = $this->apiUrl . 'users/clients/';
                        $allClients = $this->sendRequest('GET', $allClientsUrl);
                        
                        foreach ($allClients as $client) {
                            if ($client['email'] === $clientData['email'] || 
                                $client['external_id'] === $clientData['id']) {
                                return $client['id'];
                            }
                        }
                    } catch (Exception $searchException) {
                    }
                }
                
                throw $createException;
            }
        }
    }
    private function formatDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Invalid date format: пустая строка");
        }
        $date = DateTime::createFromFormat('Y-m-d', $dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        $date = DateTime::createFromFormat(DateTime::ATOM, $dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr, $matches)) {
            return $matches[0] . 'T00:00:00Z';
        }
        throw new InvalidArgumentException("Invalid date format: $dateStr");
    }

    private function formatBirthday($dateStr) {
        if (empty($dateStr)) {
            return null;
        }
        
        // Попробуем различные форматы дат
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s+00:00'];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateStr);
            if ($date) {
                return $date->format('Y-m-d\T00:00:00\Z');
            }
        }
        
        // Если ничего не подошло, попробуем стандартный парсер
        $date = new DateTime($dateStr);
        if ($date) {
            return $date->format('Y-m-d\T00:00:00\Z');
        }
        
        throw new InvalidArgumentException("Invalid birthday format: $dateStr");
    }

    private function prepareContractData(array $bitrixData, $clientId, $unitId) {
        $contractData = [
            'external_id' => $bitrixData['id'],
            'unit_id' => $unitId,
            'client_id' => $clientId,
            'name' => $bitrixData['title'],
            'start_date' => $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']),
            'end_date' => $this->formatDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']),
            'price' => number_format($bitrixData['OPPORTUNITY_WITH_CURRENCY'], 2, '.', ''),
            'type_contract' => $this->mapContractType($bitrixData['UF_CRM_20_1693561495'] ?? ''),
        ];

        if (!empty($bitrixData['UF_CRM_20_CONTRACT_HISTORY'])) {
            $contractData['history'] = $bitrixData['UF_CRM_20_CONTRACT_HISTORY'];
        }

        $contractScan = $this->uploadFile($bitrixData['ufCrm20Contract']['urlMachine'] ?? null);
        if ($contractScan) {
            $contractData['contract_scan'] = $contractScan;
        }

        return $contractData;
    }

    private function validateBitrixData(array $bitrixData) {
        $requiredFields = [
            'id', 'title', 'unit_external_id', 'client_data',
            'UF_CRM_20_CONTRACT_START_DATE', 'UF_CRM_20_CONTRACT_END_DATE',
            'OPPORTUNITY_WITH_CURRENCY'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($bitrixData[$field])) {
                throw new InvalidArgumentException("Missing required field: $field");
            }

            if (empty($bitrixData[$field]) && $bitrixData[$field] !== '0') {
                throw new InvalidArgumentException("Empty value for required field: $field");
            }
        }

        $this->validateDate($bitrixData['UF_CRM_20_CONTRACT_START_DATE']);
        $this->validateDate($bitrixData['UF_CRM_20_CONTRACT_END_DATE']);
    }

    private function validateDate($dateStr) {
        if (empty($dateStr)) {
            throw new InvalidArgumentException("Invalid date format: пустая строка");
        }
        if (DateTime::createFromFormat('Y-m-d', $dateStr)) {
            return;
        }
        if (DateTime::createFromFormat(DateTime::ATOM, $dateStr)) {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
            return;
        }
        throw new InvalidArgumentException("Invalid date format: $dateStr. Expected Y-m-d or ISO8601");
    }

    private function mapContractType($bitrixType) {
        $mapping = [
            '882' => 'Airbnb',
            '884' => 'Short term from 1 to 3 months',
            '886' => 'Long-term 3+ months',
            '1304' => 'Booking',
            '1306' => 'Short contract up to 1 month'
        ];

        $bitrixTypeStr = (string)$bitrixType;

        if (!isset($mapping[$bitrixTypeStr])) {
            throw new InvalidArgumentException("Unknown contract type: $bitrixType");
        }

        return $mapping[$bitrixTypeStr];
    }

    private function uploadFile($fileUrl) {
        if (empty($fileUrl)) {
            return null;
        }

        $uploadUrl = $this->apiUrl . 'external-image/';
        $fileData = [
            'url' => $fileUrl,
            'description' => 'Contract document'
        ];

        try {
            $response = $this->sendRequest('POST', $uploadUrl, $fileData);
            return $response['id'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function sendRequest($method, $url, $data = null) {
        $headers = [
            'Api-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        }

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);


        Logger::logApiRequest($method, $url, $data, $response, $httpCode);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL error: $error");
        }

        curl_close($ch);

        if ($httpCode >= 400) {
            $errorMessage = "API request failed ($httpCode)";
            if ($response) {
                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['detail'])) {
                    $errorMessage .= ": " . $decoded['detail'];
                } else {
                    $errorMessage .= ": " . substr($response, 0, 200);
                }
            }
            throw new RuntimeException($errorMessage, $httpCode);
        }

        $decodedResponse = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
        }

        return $decodedResponse;
    }
    
    public function getActionLogger()
    {
        return $this->actionLogger;
    }

    /**
     * АВТОМАТИЧЕСКАЯ ПРОВЕРКА И ИСПРАВЛЕНИЕ EXTERNAL_ID МАППИНГОВ
     * Ищет правильный юнит по названию и исправляет external_id маппинги
     */
    private function validateAndFixExternalIdMapping($unitDetails, $expectedExternalId, $currentUnitId, $contractId) {
        $currentName = $unitDetails['name'] ?? '';
        $currentExternalId = $unitDetails['external_id'] ?? '';
        $currentAdditionalExternalId = $unitDetails['additional_external_id'] ?? '';
        $isUsedAdditionalExternalId = $unitDetails['is_used_additional_external_id'] ?? false;
        
        // Определяем, какой ID используется как основной
        $actualUsedId = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? $currentAdditionalExternalId : $currentExternalId;
        
        // Если external_id не совпадает, ищем правильный юнит по названию
        if ($actualUsedId !== $expectedExternalId) {
            Logger::warning("External ID mismatch detected. Expected: $expectedExternalId, Found: $actualUsedId. Searching for correct unit...", [], 'contract', $contractId);
            Logger::info("Current unit details: " . json_encode($unitDetails), [], 'contract', $contractId);
            
            // Ищем юнит с правильным external_id
            $correctUnit = $this->findUnitByExternalId($expectedExternalId);
            Logger::info("Search result for external_id $expectedExternalId: " . json_encode($correctUnit), [], 'contract', $contractId);
            
            if ($correctUnit) {
                $correctUnitId = $correctUnit['id'] ?? 'unknown';
                $correctUnitName = $correctUnit['name'] ?? 'unknown';
                $correctUnitExternalId = $correctUnit['external_id'] ?? 'unknown';
                
                Logger::info("Found correct unit: ID=$correctUnitId, Name=$correctUnitName, External ID=$correctUnitExternalId", [], 'contract', $contractId);
                
                // Проверяем, что названия совпадают
                if ($this->isUnitNameMatching($correctUnitName, $currentName)) {
                    Logger::info("Unit names match! This confirms the external_id mapping issue.", [], 'contract', $contractId);
                    
                    // АВТОМАТИЧЕСКИ ИСПРАВЛЯЕМ external_id маппинг
                    $fixSuccess = $this->suggestExternalIdFix($currentUnitId, $correctUnitId, $expectedExternalId, $contractId);
                    
                    if ($fixSuccess) {
                        Logger::info("External ID mapping fixed automatically. Refreshing unit data...", [], 'contract', $contractId);
                        
                        // Обновляем данные юнита после исправления
                        $unitUrl = $this->apiUrl . 'realty/units/' . $currentUnitId . '/';
                        $updatedUnitDetails = $this->sendRequest('GET', $unitUrl);
                        
                        // Обновляем кэш данных юнита
                        $this->unitDetailsCache[$currentUnitId] = $updatedUnitDetails;
                        
                        Logger::info("Unit data refreshed. Re-validating with updated data...", [], 'contract', $contractId);
                        
                        // Перезапускаем валидацию с обновленными данными
                        $this->validateRentalObjectWithUpdatedData($updatedUnitDetails, $expectedExternalId, $currentUnitId, $contractId);
                        return; // Выходим из функции, чтобы продолжить создание контракта
                    } else {
                        Logger::error("Automatic fix failed. Manual intervention required.", [], 'contract', $contractId);
                    }
                } else {
                    Logger::warning("Unit names don't match. Expected: $currentName, Found: $correctUnitName", [], 'contract', $contractId);
                }
            } else {
                Logger::error("No unit found with external_id: $expectedExternalId", [], 'contract', $contractId);
            }
        }
    }
    
    /**
     * Ищет юнит по external_id
     */
    private function findUnitByExternalId($externalId) {
        try {
            // Сначала пробуем через rental_object API
            $rentalObjectUrl = $this->apiUrl . 'realty/rental_object/' . $externalId . '/';
            $response = $this->sendRequest('GET', $rentalObjectUrl);
            
            if (isset($response['id'])) {
                // Получаем полную информацию о юните
                $unitId = $response['id'];
                $unitUrl = $this->apiUrl . 'realty/units/' . $unitId . '/';
                $unitDetails = $this->sendRequest('GET', $unitUrl);
                
                if (isset($unitDetails['id'])) {
                    return $unitDetails;
                }
            }
        } catch (Exception $e) {
            Logger::debug("Rental object API failed for external_id $externalId: " . $e->getMessage());
        }
        
        // Если не нашли, пробуем поиск по units
        try {
            $unitsUrl = $this->apiUrl . 'realty/units/?external_id=' . $externalId;
            $response = $this->sendRequest('GET', $unitsUrl);
            
            if (isset($response['results']) && count($response['results']) > 0) {
                return $response['results'][0];
            }
        } catch (Exception $e) {
            Logger::debug("Units search failed for external_id $externalId: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Повторная валидация с обновленными данными юнита
     */
    private function validateRentalObjectWithUpdatedData($unitDetails, $expectedExternalId, $unitId, $contractId) {
        $currentExternalId = $unitDetails['external_id'] ?? '';
        $currentAdditionalExternalId = $unitDetails['additional_external_id'] ?? '';
        $isUsedAdditionalExternalId = $unitDetails['is_used_additional_external_id'] ?? false;
        
        // Определяем, какой ID используется как основной
        $actualUsedId = ($isUsedAdditionalExternalId && !empty($currentAdditionalExternalId)) ? $currentAdditionalExternalId : $currentExternalId;
        
        Logger::info("Re-validation: Expected: $expectedExternalId, Found: $actualUsedId (external_id: $currentExternalId, additional_external_id: $currentAdditionalExternalId, is_used_additional: $isUsedAdditionalExternalId)", [], 'contract', $contractId);
        
        if ($actualUsedId !== $expectedExternalId) {
            Logger::error("Re-validation failed: External ID still doesn't match after fix", [], 'contract', $contractId);
            throw new Exception("CRITICAL MISMATCH: Unit $unitId still has incorrect external_id after automatic fix. Expected: $expectedExternalId, Found: $actualUsedId");
        }
        
        Logger::info("✓ Re-validation passed: External ID matches after fix", [], 'contract', $contractId);
    }

    /**
     * АВТОМАТИЧЕСКИ ИСПРАВЛЯЕТ external_id маппинги
     */
    private function suggestExternalIdFix($wrongUnitId, $correctUnitId, $externalId, $contractId) {
        Logger::info("=== AUTOMATIC EXTERNAL_ID MAPPING FIX ===", [], 'contract', $contractId);
        
        try {
            // Step 1: Исправляем external_id и additional_external_id на неправильном юните
            Logger::info("Step 1: Fixing external_id and additional_external_id on wrong unit $wrongUnitId", [], 'contract', $contractId);
            $wrongUnitUrl = $this->apiUrl . 'realty/units/' . $wrongUnitId . '/';
            $this->sendRequest('PATCH', $wrongUnitUrl, [
                'external_id' => $externalId . '_old',
                'additional_external_id' => $externalId . '_old',
                'is_used_additional_external_id' => false
            ]);
            Logger::info("✓ Successfully fixed external_id and additional_external_id on unit $wrongUnitId", [], 'contract', $contractId);
            
            // Step 2: Устанавливаем правильный external_id на правильном юните
            Logger::info("Step 2: Setting external_id $externalId on correct unit $correctUnitId", [], 'contract', $contractId);
            $correctUnitUrl = $this->apiUrl . 'realty/units/' . $correctUnitId . '/';
            $this->sendRequest('PATCH', $correctUnitUrl, [
                'external_id' => $externalId,
                'is_used_additional_external_id' => false
            ]);
            Logger::info("✓ Successfully set external_id on unit $correctUnitId", [], 'contract', $contractId);
            
            Logger::info("=== EXTERNAL_ID MAPPING FIXED SUCCESSFULLY ===", [], 'contract', $contractId);
            return true;
            
        } catch (Exception $e) {
            Logger::error("Failed to automatically fix external_id mapping: " . $e->getMessage(), [], 'contract', $contractId);
            Logger::info("=== MANUAL FIX REQUIRED ===", [], 'contract', $contractId);
            Logger::info("Step 1: PATCH https://colife.argo.properties:1337/external_api/realty/units/$wrongUnitId/", [], 'contract', $contractId);
            Logger::info("Body: {\"external_id\": \"{$externalId}_old\"}", [], 'contract', $contractId);
            Logger::info("Step 2: PATCH https://colife.argo.properties:1337/external_api/realty/units/$correctUnitId/", [], 'contract', $contractId);
            Logger::info("Body: {\"external_id\": \"$externalId\"}", [], 'contract', $contractId);
            Logger::info("=== END MANUAL FIX INSTRUCTIONS ===", [], 'contract', $contractId);
            return false;
        }
    }
}



try {
    $almaApi = new AlmaTenantContractApi();

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);
    $bitrixTenats = $bitrix->call('crm.item.get', [
        'entityTypeId' => 183,
        'id' => $_GET['id'],
    ]);

    if (!isset($bitrixTenats['result']['item'])) {
        throw new Exception("Contract not found in Bitrix24 with ID: " . $_GET['id']);
    }
    
    $bitrixData = $bitrixTenats['result']['item'];

    $contactResponse = $bitrix->call('crm.contact.get', [
        'id' => $bitrixData['contactId']
    ]);
    
    if (!isset($contactResponse['result'])) {
        throw new Exception("Contact not found in Bitrix24 with ID: " . $bitrixData['contactId']);
    }
    
    $contactData = $contactResponse['result'];

    $bitrixContractData = [
        'id' => $bitrixData['id'],
        'title' => $bitrixData['title'],
        'unit_external_id' => $bitrixData['ufCrm20_1693919019'] ?? '',
        'client_data' => [
            'id' => $contactData['ID'],
            'first_name' => $contactData['NAME'],
            'last_name' => $contactData['LAST_NAME'],
            'email' => $contactData['EMAIL'][0]['VALUE'] ?? '',
            'phone' => $contactData['PHONE'][0]['VALUE'] ?? '',
            'birthday' => $contactData['BIRTHDATE'] ?? '',
        ],
        'UF_CRM_20_CONTRACT_START_DATE' => $bitrixData['ufCrm20ContractStartDate'] ?? '',
        'UF_CRM_20_CONTRACT_END_DATE' => $bitrixData['ufCrm20ContractEndDate'] ?? '',
        'OPPORTUNITY_WITH_CURRENCY' => $bitrixData['opportunity'] ?? 0,
        'UF_CRM_20_CONTRACT_HISTORY' => $bitrixData['ufCrm20ContractHistory'] ?? '',
        'UF_CRM_20_1693561495' => $bitrixData['ufCrm20_1693561495'] ?? '',
        'ufCrm20Contract' => $bitrixData['ufCrm20Contract'] ?? null,
    ];

    $result = $almaApi->syncContract($bitrixContractData);

    if (isset($result['id'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Contract successfully synchronized',
            'alma_id' => $result['id'],
            'data' => $result
        ]);
    } else {
        throw new Exception('Contract synchronization failed');
    }

} catch (InvalidArgumentException $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'Contract Validation Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Data validation error: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'API Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'API error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $almaApi->getActionLogger()->logError(
        $_GET['id'] ?? 'unknown',
        'Unexpected Error',
        $e->getMessage(),
        ['contract_data' => $bitrixContractData ?? []]
    );

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unexpected error: ' . $e->getMessage()
    ]);
}