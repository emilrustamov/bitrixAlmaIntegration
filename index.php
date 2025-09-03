<?php
require_once('Bitrix24Rest.php');
require_once('Logger.php');
require_once('Config.php');

// Загружаем конфигурацию
Config::load();

define('WEBHOOK_URL', Config::get('WEBHOOK_URL'));

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