<?php
set_time_limit(300);
ini_set('max_execution_time', 300);

require_once __DIR__ . '/Core/Bitrix24Rest.php';
require_once __DIR__ . '/Core/Logger.php';
require_once __DIR__ . '/Core/Config/Config.php';
require_once __DIR__ . '/Core/Config/ProjectMapping.php';

Config::load();

$projectName = $_GET['project'] ?? $argv[1] ?? 'Dubai';
$days = isset($_GET['days']) ? (int)$_GET['days'] : (isset($argv[2]) ? (int)$argv[2] : 7);
$step = isset($_GET['step']) ? (int)$_GET['step'] : (isset($argv[3]) ? (int)$argv[3] : 1);

Logger::setProject($projectName);

$projectConfig = ProjectMapping::getProjectConfig($projectName);
$fieldMapping = ProjectMapping::getFieldMapping($projectName);
$webhookUrl = $projectConfig['webhook_url'];
$baseUrl = rtrim(Config::get('APP_BASE_URL'), '/') . '/';

$bitrix = new Bitrix24Rest($webhookUrl);

$dateFrom = date('Y-m-d', strtotime("-$days days"));
$dateTo = date('Y-m-d');

echo "=== Batch Contract Sync ===\n";
echo "Project: $projectName\n";
echo "Date range: $dateFrom to $dateTo\n";
echo "Step: $step\n\n";

$contractEntityTypeId = ProjectMapping::getContractEntityTypeId($projectName);
$isDealBased = ProjectMapping::isDealBasedContract($projectName);

$contractIds = [];
$start = 0;
$limit = 50;
$hasMore = true;

echo "Fetching contracts from Bitrix24...\n";

try {
    while ($hasMore) {
        if ($isDealBased) {
            $params = [
                'start' => $start,
                'limit' => $limit,
                'filter' => [
                    '>=DATE_CREATE' => $dateFrom . ' 00:00:00',
                    '<=DATE_CREATE' => $dateTo . ' 23:59:59'
                ],
                'select' => ['ID']
            ];
            
            $response = $bitrix->call('crm.deal.list', $params);
        } else {
            $params = [
                'entityTypeId' => $contractEntityTypeId,
                'start' => $start,
                'limit' => $limit,
                'filter' => [
                    '>=createdTime' => $dateFrom . 'T00:00:00',
                    '<=createdTime' => $dateTo . 'T23:59:59'
                ],
                'select' => ['id']
            ];
            
            $response = $bitrix->call('crm.item.list', $params);
        }
        
        if (!isset($response['result'])) {
            echo "Error: No result in response\n";
            break;
        }
        
        if ($isDealBased) {
            $items = $response['result'] ?? [];
            $itemsCount = count($items);
            
            foreach ($items as $item) {
                if (isset($item['ID'])) {
                    $contractIds[] = $item['ID'];
                }
            }
            
            $hasMore = ($itemsCount == $limit);
        } else {
            $items = $response['result']['items'] ?? [];
            $itemsCount = count($items);
            
            foreach ($items as $item) {
                if (isset($item['id'])) {
                    $contractIds[] = $item['id'];
                }
            }
            
            $hasNext = $response['result']['hasNext'] ?? false;
            $hasMore = $hasNext || ($itemsCount == $limit);
        }
        
        $start += $limit;
        echo "Found " . count($contractIds) . " contracts so far... (page items: $itemsCount)\n";
        
        if ($itemsCount < $limit) {
            $hasMore = false;
        }
    }
} catch (Exception $e) {
    echo "Error fetching contracts: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nTotal contracts found: " . count($contractIds) . "\n\n";

if (empty($contractIds)) {
    echo "No contracts to sync.\n";
    exit(0);
}

if ($step > 1) {
    $filteredIds = [];
    for ($i = 0; $i < count($contractIds); $i += $step) {
        $filteredIds[] = $contractIds[$i];
    }
    $contractIds = $filteredIds;
    echo "After step filter ($step): " . count($contractIds) . " contracts\n\n";
}

echo "Starting sync...\n";
$successCount = 0;
$errorCount = 0;
$errorIds = [];
$skippedIds = [];

foreach ($contractIds as $index => $contractId) {
    $contractNum = $index + 1;
    $total = count($contractIds);
    
    echo "[$contractNum/$total] Syncing contract ID: $contractId ... ";
    
    $url = $baseUrl . 'Api/TenantContractApi.php?id=' . urlencode($contractId) . '&project=' . urlencode($projectName);
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'ignore_errors' => true
        ]
    ]);
    
    $result = @file_get_contents($url, false, $context);
    
    if ($result === false) {
        echo "ERROR\n";
        $errorCount++;
        $errorIds[] = $contractId;
    } else {
        $response = json_decode($result, true);
        if (isset($response['success']) && $response['success']) {
            echo "OK\n";
            $successCount++;
        } else {
            $errorMessage = $response['message'] ?? 'Unknown error';
            $errorStr = json_encode($response);
            
            $isArchived = (
                strpos($errorStr, 'forbidden to edit the archive usage') !== false ||
                strpos($errorStr, 'Contract is already archived') !== false ||
                strpos($errorMessage, 'forbidden to edit the archive usage') !== false ||
                strpos($errorMessage, 'Contract is already archived') !== false
            );
            
            $is404 = (
                strpos($errorStr, '404') !== false ||
                strpos($errorMessage, '404') !== false ||
                strpos($errorMessage, 'No TenantContract matches') !== false
            );
            
            if ($isArchived || $is404) {
                echo "SKIPPED (archived/deleted)\n";
                $skippedIds[] = $contractId;
            } else {
                echo "FAILED: $errorMessage\n";
                $errorCount++;
                $errorIds[] = $contractId;
            }
        }
    }
    
    if ($contractNum < $total) {
        usleep(100000);
    }
}

echo "\n=== Summary ===\n";
echo "Total: " . count($contractIds) . "\n";
echo "Success: $successCount\n";
echo "Errors (excluding archived/deleted): " . count($errorIds) . "\n";
echo "Skipped (archived/deleted): " . count($skippedIds) . "\n";

if (!empty($errorIds)) {
    echo "\n=== Error Contract IDs (excluding archived/deleted) ===\n";
    echo implode(', ', $errorIds) . "\n";
    echo "\nTotal error IDs: " . count($errorIds) . "\n";
}

