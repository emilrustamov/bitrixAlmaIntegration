<?php
require_once('Config.php');
Config::load();
define('ALMA_API_KEY', Config::get('ALMA_API_KEY'));
define('ALMA_API_URL', Config::get('ALMA_API_URL'));

class ActiveUnitsProcessor {
    private $apiKey;
    private $apiUrl;
    private $results = [];
    private $unitsWithoutAdditionalId = [];
    
    public function __construct() {
        $this->apiKey = ALMA_API_KEY;
        $this->apiUrl = rtrim(ALMA_API_URL, '/');
    }
    
    private function makeGetRequest($externalId) {
        $url = 'https://colife.argo.properties:1337/external_api/realty/units/external_id/' . $externalId;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'GET'
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("CURL error: " . $error);
        }
        
        curl_close($curl);
        return ['code' => $httpCode, 'response' => $response];
    }
    
    private function makeUpdateRequest($unitId) {
        $url = $this->apiUrl . '/realty/units/' . $unitId . '/';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['is_used_additional_external_id' => true], JSON_UNESCAPED_UNICODE)
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("CURL error: " . $error);
        }
        
        curl_close($curl);
        return ['code' => $httpCode, 'response' => $response];
    }
    
    public function processUnits($externalIds) {
        $total = count($externalIds);
        $success = 0;
        $errors = 0;
        $updated = 0;
        
        echo "Starting processing of {$total} active units...\n";
        echo "API URL: " . $this->apiUrl . "\n";
        echo "API Key: " . substr($this->apiKey, 0, 8) . "...\n";
        echo str_repeat("=", 70) . "\n";
        
        foreach ($externalIds as $index => $externalId) {
            $externalId = trim($externalId);
            if (empty($externalId)) {
                continue;
            }
            
            $progress = ($index + 1) . "/" . $total;
            echo "Processing {$progress}: External ID {$externalId}... ";
            
            try {
                // Получаем информацию о юните
                $result = $this->makeGetRequest($externalId);
                
                if ($result['code'] === 200) {
                    $data = json_decode($result['response'], true);
                    if ($data && isset($data['id'])) {
                        $unitId = $data['id'];
                        $additionalExternalId = isset($data['additional_external_id']) ? $data['additional_external_id'] : '';
                        $isUsed = isset($data['is_used_additional_external_id']) ? $data['is_used_additional_external_id'] : false;
                        $name = isset($data['name']) ? $data['name'] : 'Unknown';
                        $project = isset($data['project']['name']) ? $data['project']['name'] : 'Unknown';
                        
                        // Проверяем есть ли additional_external_id
                        if (empty($additionalExternalId)) {
                            $this->unitsWithoutAdditionalId[] = [
                                'external_id' => $externalId,
                                'alma_id' => $unitId,
                                'name' => $name,
                                'project' => $project
                            ];
                        }
                        
                        // Если is_used_additional_external_id = false, обновляем
                        if (!$isUsed) {
                            echo "Updating... ";
                            $updateResult = $this->makeUpdateRequest($unitId);
                            
                            if ($updateResult['code'] === 200) {
                                $updatedData = json_decode($updateResult['response'], true);
                                if ($updatedData && isset($updatedData['is_used_additional_external_id'])) {
                                    $newIsUsed = $updatedData['is_used_additional_external_id'] ? 'true' : 'false';
                                    echo "✓ Updated to {$newIsUsed}\n";
                                    $updated++;
                                } else {
                                    echo "✗ Invalid update response\n";
                                    $errors++;
                                }
                            } else {
                                echo "✗ Update failed ({$updateResult['code']})\n";
                                $errors++;
                            }
                        } else {
                            echo "✓ Already true\n";
                        }
                        
                        $this->results[] = [
                            'external_id' => $externalId,
                            'alma_id' => $unitId,
                            'additional_external_id' => $additionalExternalId,
                            'is_used_additional_external_id' => $isUsed ? 'true' : 'false',
                            'name' => $name,
                            'project' => $project,
                            'has_additional_id' => !empty($additionalExternalId)
                        ];
                        
                        $success++;
                    } else {
                        echo "✗ Invalid response format\n";
                        $errors++;
                    }
                } elseif ($result['code'] === 404) {
                    echo "✗ Not found (404)\n";
                    $errors++;
                } else {
                    echo "✗ Error {$result['code']}\n";
                    $errors++;
                }
            } catch (Exception $e) {
                echo "✗ Exception: " . $e->getMessage() . "\n";
                $errors++;
            }
            
            usleep(100000); // 0.1 секунды пауза
        }
        
        echo str_repeat("=", 70) . "\n";
        echo "RESULTS:\n";
        echo "Successfully processed: {$success}\n";
        echo "Updated to true: {$updated}\n";
        echo "Errors: {$errors}\n";
        echo "Total: {$total}\n";
        echo "Units without additional_external_id: " . count($this->unitsWithoutAdditionalId) . "\n";
        
        return $this->results;
    }
    
    public function saveResultsToFile($filename = 'active_units_results.txt') {
        if (empty($this->results)) {
            echo "No results to save.\n";
            return;
        }
        
        $content = "External ID\tAlma ID\tAdditional External ID\tis_used\tName\tProject\tHas Additional ID\n";
        $content .= str_repeat("-", 100) . "\n";
        
        foreach ($this->results as $result) {
            $hasAdditional = $result['has_additional_id'] ? 'YES' : 'NO';
            $content .= "{$result['external_id']}\t{$result['alma_id']}\t{$result['additional_external_id']}\t{$result['is_used_additional_external_id']}\t{$result['name']}\t{$result['project']}\t{$hasAdditional}\n";
        }
        
        $filepath = __DIR__ . '/' . $filename;
        file_put_contents($filepath, $content);
        echo "\nResults saved to: {$filepath}\n";
        echo "Total records: " . count($this->results) . "\n";
    }
    
    public function saveUnitsWithoutAdditionalId($filename = 'units_without_additional_id.txt') {
        if (empty($this->unitsWithoutAdditionalId)) {
            echo "\nAll units have additional_external_id!\n";
            return;
        }
        
        $content = "Units WITHOUT additional_external_id:\n";
        $content .= "Total: " . count($this->unitsWithoutAdditionalId) . "\n";
        $content .= str_repeat("=", 80) . "\n";
        
        foreach ($this->unitsWithoutAdditionalId as $unit) {
            $content .= "External ID: {$unit['external_id']} | Alma ID: {$unit['alma_id']} | Name: {$unit['name']} | Project: {$unit['project']}\n";
        }
        
        $filepath = __DIR__ . '/' . $filename;
        file_put_contents($filepath, $content);
        echo "\nUnits without additional_external_id saved to: {$filepath}\n";
        echo "Total units without additional_external_id: " . count($this->unitsWithoutAdditionalId) . "\n";
    }
    
    public function saveResultsToJson($filename = 'active_units_results.json') {
        if (empty($this->results)) {
            echo "No results to save.\n";
            return;
        }
        
        $filepath = __DIR__ . '/' . $filename;
        file_put_contents($filepath, json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "JSON results saved to: {$filepath}\n";
    }
}

try {
    $unitsFile = __DIR__ . '/bitrixapparts.txt';
    if (!file_exists($unitsFile)) {
        throw new Exception("File not found: {$unitsFile}");
    }
    
    $externalIds = file($unitsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($externalIds)) {
        throw new Exception("No external IDs found in file");
    }
    
    echo "Loaded " . count($externalIds) . " external IDs from file\n\n";
    
    $processor = new ActiveUnitsProcessor();
    $results = $processor->processUnits($externalIds);
    $processor->saveResultsToFile();
    $processor->saveUnitsWithoutAdditionalId();
    $processor->saveResultsToJson();
    
    echo "\nDone!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
