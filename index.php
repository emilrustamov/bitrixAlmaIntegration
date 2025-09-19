<?php
require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');

Config::load();

define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));

$isWebhookRequest = isset($_REQUEST['data']['FIELDS']['ID']) && 
                   isset($_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID']) ||
                   isset($_REQUEST['event']);

if ($isWebhookRequest) {
    $idEl = $_REQUEST['data']['FIELDS']['ID'];
    $idItem = $_REQUEST['data']['FIELDS']['ENTITY_TYPE_ID'];

    $bitrix = new Bitrix24Rest(WEBHOOK_URL);

    if ($idItem == 144) {
        $bitrixApartment = $bitrix->call('crm.item.get', [
            'entityTypeId' => 144,
            'id' => $idEl
        ]);

        if($bitrixApartment['result']['item']['ufCrm6_1753278068179'] != '8638') {
               file_get_contents(Config::get('APP_BASE_URL') . 'appart.php?id=' . $idEl);
        }

    } elseif ($idItem == 3 || $_REQUEST['event'] == 'ONCRMCONTACTUPDATE') {
        $bitrixContact = $bitrix->call('crm.contact.get', [
            'id' => $idEl
        ]);

        if (isset($bitrixContact['result'])) {
            $contactData = $bitrixContact['result'];
            $contactType = $contactData['TYPE_ID'] ?? '';
            $isTenant = ($contactType === 'TENANT' || $contactType === 'CLIENT');
            
            if ($isTenant) {
                file_get_contents(Config::get('APP_BASE_URL') . 'tenant.php?id=' . $idEl);
            } 
        }
    } elseif ($idItem == 183) {
        $bitrixTenantContract = $bitrix->call('crm.item.get', [
            'entityTypeId' => 183,
            'id' => $idEl
        ]);

        if (isset($bitrixTenantContract['result'])) {
            file_get_contents(Config::get('APP_BASE_URL') . 'tenatContract.php?id=' . $idEl);
        }
    }
    } else {
        $level = $_GET['level'] ?? '';
        $entityType = $_GET['entity_type'] ?? '';
        $limit = (int)($_GET['limit'] ?? 50);
        
        $logs = Logger::getLogs(null, null, $level, $entityType);
        $logs = array_reverse($logs);
        $logs = array_slice($logs, 0, $limit);
        
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Alma Integration Logs</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .filters { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .filters form { display: flex; gap: 15px; align-items: end; flex-wrap: wrap; }
                .filter-group { display: flex; flex-direction: column; }
                .filter-group label { margin-bottom: 5px; font-weight: bold; }
                .filter-group select, .filter-group input { padding: 5px; }
                .btn { padding: 8px 15px; background: #007cba; color: white; border: none; border-radius: 3px; cursor: pointer; }
                .log-entry { border: 1px solid #ddd; margin: 10px 0; padding: 15px; border-radius: 5px; }
                .log-entry.error { border-left: 4px solid #e74c3c; background: #fdf2f2; }
                .log-entry.warning { border-left: 4px solid #f39c12; background: #fef9e7; }
                .log-entry.info { border-left: 4px solid #3498db; background: #f0f8ff; }
                .log-header { display: flex; justify-content: space-between; margin-bottom: 10px; }
                .log-level { padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
                .log-level.error { background: #e74c3c; color: white; }
                .log-level.warning { background: #f39c12; color: white; }
                .log-level.info { background: #3498db; color: white; }
                .log-context { background: #f8f9fa; padding: 10px; margin-top: 10px; border-radius: 3px; font-family: monospace; font-size: 12px; }
            </style>
        </head>
        <body>
            <h1>Alma Integration Logs</h1>
            
            <div class="filters">
                <form method="GET">
                    <div class="filter-group">
                        <label>Уровень:</label>
                        <select name="level">
                            <option value="">Все</option>
                            <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                            <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                            <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>INFO</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Тип:</label>
                        <select name="entity_type">
                            <option value="">Все</option>
                            <option value="apartment" <?= $entityType === 'apartment' ? 'selected' : '' ?>>Апартаменты</option>
                            <option value="tenant" <?= $entityType === 'tenant' ? 'selected' : '' ?>>Клиенты</option>
                            <option value="contract" <?= $entityType === 'contract' ? 'selected' : '' ?>>Контракты</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Количество:</label>
                        <input type="number" name="limit" value="<?= $limit ?>" min="10" max="200">
                    </div>
                    <button type="submit" class="btn">Применить</button>
                </form>
            </div>
            
            <?php if (empty($logs)): ?>
                <p>Нет записей</p>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-entry <?= strtolower($log['level']) ?>">
                        <div class="log-header">
                            <span><?= $log['timestamp'] ?></span>
                            <span class="log-level <?= strtolower($log['level']) ?>"><?= $log['level'] ?></span>
                        </div>
                        <div><strong><?= htmlspecialchars($log['message']) ?></strong></div>
                        <?php if (isset($log['entity_type']) && isset($log['entity_id'])): ?>
                            <div style="color: #666; font-size: 14px;"><?= $log['entity_type'] ?> #<?= $log['entity_id'] ?></div>
                        <?php endif; ?>
                        <?php if (!empty($log['context'])): ?>
                            <div class="log-context"><?= htmlspecialchars(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </body>
        </html>
        <?php
    }