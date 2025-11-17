<?php
declare(strict_types=1);

$apiUrl = 'https://colife.argo.properties:1337/external_api/realty/units/';
$apiKey = '3ae0539d134e9b7320e6d3ff28a11bde';

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Api-Key: ' . $apiKey,
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno) {
    fwrite(STDERR, "curl_error=$errno $error\n");
    exit(1);
}
if ($httpCode !== 200) {
    fwrite(STDERR, "http_status=$httpCode\n");
    fwrite(STDERR, substr((string)$response, 0, 500) . "\n");
    exit(1);
}

$data = json_decode((string)$response, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "json_parse_error=1\n");
    exit(1);
}

$items = [];
if (is_array($data)) {
    $isList = array_is_list($data) && (empty($data) || (isset($data[0]) && is_array($data[0])));
    if ($isList) {
        $items = $data;
    } else {
        foreach (["data", "results", "items", "rows"] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $items = $data[$k];
                break;
            }
        }
        if (empty($items) && isset($data['data']) && is_array($data['data']) && isset($data['data']['items']) && is_array($data['data']['items'])) {
            $items = $data['data']['items'];
        }
    }
}

// Exclude items with 'test' or 'double' in the name (case-insensitive)
$items = array_values(array_filter($items, function ($it) {
    $name = isset($it['name']) ? (string)$it['name'] : '';
    return stripos($name, 'test') === false && stripos($name, 'double') === false;
}));

$withExternalId = 0;
foreach ($items as $it) {
    $ext = isset($it['external_id']) ? (string)$it['external_id'] : '';
    if (trim($ext) !== '') {
        $withExternalId++;
    }
}

echo "total_items=" . count($items) . PHP_EOL;
echo "with_external_id=" . $withExternalId . PHP_EOL;
