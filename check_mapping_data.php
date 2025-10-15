<?php
require_once('Config.php');
Config::load();

class MappingDataChecker {
    private $bitrixApiUrl = 'https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.item.get';
    private $almaApiUrl = 'https://colife.argo.properties:1337/external_api/realty/units/external_id/';
    private $almaApiKey;
    private $results = [];
    private $mismatched = [];
    
    public function __construct() {
        $this->almaApiKey = Config::get('ALMA_API_KEY');
    }
    
    private function getBitrixData($apartmentId) {
        $url = $this->bitrixApiUrl . '?entityTypeId=144&id=' . $apartmentId;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("Bitrix CURL error: " . $error);
        }
        
        curl_close($curl);
        
        if ($httpCode !== 200) {
            throw new Exception("Bitrix API error: HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['result']['item'])) {
            throw new Exception("Invalid Bitrix response format");
        }
        
        return $data['result']['item'];
    }
    
    private function getAlmaData($apartmentId) {
        $url = $this->almaApiUrl . $apartmentId;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->almaApiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Exception("Alma CURL error: " . $error);
        }
        
        curl_close($curl);
        
        if ($httpCode !== 200) {
            throw new Exception("Alma API error: HTTP {$httpCode}");
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid Alma response format");
        }
        
        return $data;
    }
    
    public function checkMapping($apartmentIds) {
        $total = count($apartmentIds);
        $success = 0;
        $errors = 0;
        
        echo "Starting mapping data check for {$total} apartments...\n";
        echo str_repeat("=", 80) . "\n";
        
        foreach ($apartmentIds as $index => $apartmentId) {
            $apartmentId = trim($apartmentId);
            if (empty($apartmentId)) {
                continue;
            }
            
            $progress = ($index + 1) . "/" . $total;
            echo "Processing {$progress}: Apartment ID {$apartmentId}... ";
            
            try {
                // Получаем данные из Bitrix24
                $bitrixData = $this->getBitrixData($apartmentId);
                $bitrixAdditionalId = isset($bitrixData['ufCrm6_1684425402']) && is_array($bitrixData['ufCrm6_1684425402']) 
                    ? (count($bitrixData['ufCrm6_1684425402']) > 0 ? $bitrixData['ufCrm6_1684425402'][0] : '') 
                    : '';
                
                // Получаем данные из Alma
                $almaData = $this->getAlmaData($apartmentId);
                $almaExternalId = isset($almaData['external_id']) ? $almaData['external_id'] : '';
                $almaAdditionalId = isset($almaData['additional_external_id']) ? $almaData['additional_external_id'] : '';
                
                // Сравниваем данные
                $externalIdMatch = ($apartmentId == $almaExternalId);
                $additionalIdMatch = ($bitrixAdditionalId == $almaAdditionalId);
                $hasAdditionalId = !empty($bitrixAdditionalId) && !empty($almaAdditionalId);
                
                $result = [
                    'apartment_id' => $apartmentId,
                    'bitrix_additional_id' => $bitrixAdditionalId,
                    'alma_external_id' => $almaExternalId,
                    'alma_additional_id' => $almaAdditionalId,
                    'external_id_match' => $externalIdMatch,
                    'additional_id_match' => $additionalIdMatch,
                    'has_additional_id' => $hasAdditionalId,
                    'alma_name' => isset($almaData['name']) ? $almaData['name'] : 'Unknown'
                ];
                
                $this->results[] = $result;
                
                if (!$externalIdMatch || !$additionalIdMatch) {
                    $this->mismatched[] = $result;
                    echo "❌ MISMATCH\n";
                } else {
                    echo "✅ OK\n";
                }
                
                $success++;
                
            } catch (Exception $e) {
                echo "❌ ERROR: " . $e->getMessage() . "\n";
                $errors++;
                
                $this->results[] = [
                    'apartment_id' => $apartmentId,
                    'error' => $e->getMessage(),
                    'external_id_match' => false,
                    'additional_id_match' => false
                ];
            }
            
            usleep(200000); // 0.2 секунды пауза
        }
        
        echo str_repeat("=", 80) . "\n";
        echo "RESULTS:\n";
        echo "Successfully processed: {$success}\n";
        echo "Errors: {$errors}\n";
        echo "Total: {$total}\n";
        echo "Mismatched records: " . count($this->mismatched) . "\n";
        
        return $this->results;
    }
    
    public function saveResults($filename = 'mapping_check_results.txt') {
        if (empty($this->results)) {
            echo "No results to save.\n";
            return;
        }
        
        $content = "Apartment ID\tBitrix Additional ID\tAlma External ID\tAlma Additional ID\tExt ID Match\tAdd ID Match\tHas Add ID\tAlma Name\tError\n";
        $content .= str_repeat("-", 120) . "\n";
        
        foreach ($this->results as $result) {
            $error = isset($result['error']) ? $result['error'] : '';
            $extMatch = isset($result['external_id_match']) ? ($result['external_id_match'] ? 'YES' : 'NO') : 'N/A';
            $addMatch = isset($result['additional_id_match']) ? ($result['additional_id_match'] ? 'YES' : 'NO') : 'N/A';
            $hasAdd = isset($result['has_additional_id']) ? ($result['has_additional_id'] ? 'YES' : 'NO') : 'N/A';
            
            $content .= "{$result['apartment_id']}\t";
            $content .= (isset($result['bitrix_additional_id']) ? $result['bitrix_additional_id'] : 'N/A') . "\t";
            $content .= (isset($result['alma_external_id']) ? $result['alma_external_id'] : 'N/A') . "\t";
            $content .= (isset($result['alma_additional_id']) ? $result['alma_additional_id'] : 'N/A') . "\t";
            $content .= "{$extMatch}\t";
            $content .= "{$addMatch}\t";
            $content .= "{$hasAdd}\t";
            $content .= (isset($result['alma_name']) ? $result['alma_name'] : 'N/A') . "\t";
            $content .= "{$error}\n";
        }
        
        $filepath = __DIR__ . '/' . $filename;
        file_put_contents($filepath, $content);
        echo "\nResults saved to: {$filepath}\n";
        echo "Total records: " . count($this->results) . "\n";
    }
    
    public function saveMismatched($filename = 'mismatched_mapping.txt') {
        if (empty($this->mismatched)) {
            echo "\nNo mismatched records found!\n";
            return;
        }
        
        $content = "MISMATCHED RECORDS:\n";
        $content .= "Total: " . count($this->mismatched) . "\n";
        $content .= str_repeat("=", 80) . "\n";
        
        foreach ($this->mismatched as $result) {
            $content .= "Apartment ID: {$result['apartment_id']}\n";
            $content .= "  Bitrix Additional ID: {$result['bitrix_additional_id']}\n";
            $content .= "  Alma External ID: {$result['alma_external_id']}\n";
            $content .= "  Alma Additional ID: {$result['alma_additional_id']}\n";
            $content .= "  External ID Match: " . ($result['external_id_match'] ? 'YES' : 'NO') . "\n";
            $content .= "  Additional ID Match: " . ($result['additional_id_match'] ? 'YES' : 'NO') . "\n";
            $content .= "  Alma Name: {$result['alma_name']}\n";
            $content .= str_repeat("-", 40) . "\n";
        }
        
        $filepath = __DIR__ . '/' . $filename;
        file_put_contents($filepath, $content);
        echo "\nMismatched records saved to: {$filepath}\n";
        echo "Total mismatched: " . count($this->mismatched) . "\n";
    }
    
    public function saveJson($filename = 'mapping_check_results.json') {
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
    $apartmentsFile = __DIR__ . '/bitrixapparts.txt';
    if (!file_exists($apartmentsFile)) {
        throw new Exception("File not found: {$apartmentsFile}");
    }
    
    $apartmentIds = file($apartmentsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($apartmentIds)) {
        throw new Exception("No apartment IDs found in file");
    }
    
    echo "Loaded " . count($apartmentIds) . " apartment IDs from file\n\n";
    
    $checker = new MappingDataChecker();
    $results = $checker->checkMapping($apartmentIds);
    $checker->saveResults();
    $checker->saveMismatched();
    $checker->saveJson();
    
    echo "\nDone!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}




