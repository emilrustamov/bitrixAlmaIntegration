<?php

require_once __DIR__ . '/UnitApi.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $projectName = $_GET['project'] ?? 'Dubai';
    try {
        BaseApi::sendSuccessResponse(syncUnitById($_GET['id'], $projectName));
    } catch (Throwable $e) {
        BaseApi::sendErrorResponse($e, 'Unexpected error: Room synchronization failed', 'room', $_GET['id'] ?? null);
    }
    exit;
}
