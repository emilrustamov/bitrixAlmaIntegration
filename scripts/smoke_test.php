<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function assertTrue(bool $cond, string $name): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $name\n";
        $passed++;
        return;
    }
    echo "FAIL  $name\n";
    $failed++;
}

require_once $root . '/Core/Config/Config.php';
require_once $root . '/Core/Config/ProjectMapping.php';
require_once $root . '/Core/Logger.php';
require_once $root . '/Api/BaseApi.php';

Config::load();

ob_start();
BaseApi::sendSuccessResponse([
    'code' => 200,
    'response' => ['id' => 11513, 'external_id' => '10918'],
    'method' => 'PATCH',
]);
$out = ob_get_clean();

ob_start();
BaseApi::sendErrorResponse(new InvalidArgumentException('bad id'), 'x', 'contract', '1');
$errOut = ob_get_clean();

ob_start();
Logger::setProject('general');
Logger::error('smoke logger check', ['test' => true], 'smoke', '0');
$logEcho = ob_get_clean();

echo "=== unit: ProjectMapping ===\n";
$dubaiApt = ProjectMapping::getApartmentEntityTypeIds('Dubai');
assertTrue($dubaiApt === [144], 'Dubai apartment entity types');
$hkApt = ProjectMapping::getApartmentEntityTypeIds('HongKong');
assertTrue($hkApt === [1054], 'HongKong apartment entity types');
$archive = ProjectMapping::getTenantContractArchiveStageIds('Dubai');
assertTrue(in_array('DT183_28:FAIL', $archive, true), 'Dubai archive stages include FAIL');
assertTrue(in_array('DT183_28:SUCCESS', $archive, true), 'Dubai archive stages include SUCCESS');
$hkArchive = ProjectMapping::getTenantContractArchiveStageIds('HongKong');
assertTrue(in_array('DT1072_27:FAIL', $hkArchive, true), 'HongKong archive stages include FAIL');
assertTrue(in_array('DT1072_27:SUCCESS', $hkArchive, true), 'HongKong archive stages include SUCCESS');
assertTrue(ProjectMapping::getContractField('apartment_id', 'Dubai') === null, 'no dead contract apartment_id field');
assertTrue(ProjectMapping::getContractEntityTypeId('Dubai') === 183, 'Dubai contract entity type');
assertTrue(ProjectMapping::getUnitEntityTypeId('Dubai') === 167, 'Dubai unit entity type');

echo "=== unit: BaseApi response shape ===\n";
$decoded = json_decode($out, true);
assertTrue(is_array($decoded), 'success body is JSON object');
assertTrue(($decoded['id'] ?? null) === 11513, 'success unwraps id');
assertTrue(!array_key_exists('success', $decoded), 'no success envelope');
assertTrue(!array_key_exists('method', $decoded), 'no method envelope');
assertTrue(!array_key_exists('code', $decoded), 'no code envelope');

$err = json_decode($errOut, true);
assertTrue(isset($err['message']), 'error has message');
assertTrue(!array_key_exists('success', $err), 'error has no success flag');
assertTrue(!array_key_exists('method', $err), 'error has no method');

echo "=== unit: Logger no HTML echo ===\n";
assertTrue($logEcho === '', 'Logger::error does not echo HTML');

echo "=== live: API smoke ===\n";
$base = getenv('ALMA_SMOKE_BASE') ?: 'https://alma.colifeb24apps.com';
$contractId = getenv('ALMA_SMOKE_CONTRACT_ID') ?: '10918';

function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

[$code, $body, $cerr] = httpGet($base . '/Api/TenantContractApi.php?id=' . rawurlencode($contractId) . '&project=Dubai');
assertTrue($cerr === '', 'tenant contract curl ok');
assertTrue($code === 200, 'tenant contract HTTP 200');
$json = json_decode($body, true);
assertTrue(is_array($json), 'tenant contract JSON');
assertTrue(($json['external_id'] ?? null) === $contractId || ($json['external_id'] ?? null) === (int) $contractId || (string) ($json['external_id'] ?? '') === $contractId, 'tenant contract external_id');
assertTrue(!array_key_exists('success', $json), 'live response without success envelope');
assertTrue(isset($json['id']), 'live response has alma id');

[$code2, $body2] = httpGet($base . '/Api/TenantContractApi.php?id=0&project=Dubai');
$errJson = json_decode($body2, true);
assertTrue($code2 >= 400, 'invalid id returns 4xx/5xx');
assertTrue(is_array($errJson) && isset($errJson['message']), 'invalid id returns message');

[$code3, $body3] = httpGet($base . '/Api/AppartApi.php?id=0&project=Dubai');
$errApt = json_decode($body3, true);
assertTrue($code3 >= 400, 'invalid apartment returns 4xx/5xx');
assertTrue(is_array($errApt) && isset($errApt['message']), 'invalid apartment returns message');

echo "\nResult: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
