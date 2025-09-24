# Troubleshooting Guide для Alma-Bitrix24 Integration

## 🚨 Частые проблемы и их решения

### 1. Ошибка "It is not possible to rent a unit divided into rooms"

**Причина:** Система пытается создать контракт на юнит, который разделен на комнаты в Alma.

**Диагностика:**
```bash
# Проверить контракт в Bitrix24
curl -X POST "https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.item.get" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "entityTypeId=183&id=CONTRACT_ID"

# Найти поле ufCrm20_1693919019 - это ID апартамента в Bitrix24
# Проверить, какой объект в Alma привязан к этому external_id
curl -X GET "https://colife.argo.properties:1337/external_api/realty/rental_object/EXTERNAL_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

**Решение:**
1. Найти правильный объект в Alma (не разделенный на комнаты)
2. Освободить external_id от неправильного объекта
3. Привязать external_id к правильному объекту

```bash
# Освободить external_id от неправильного объекта
curl -X PATCH "https://colife.argo.properties:1337/external_api/realty/units/WRONG_UNIT_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" \
     -H "Content-Type: application/json" \
     -d '{"external_id": "OLD_ID_temp"}'

# Привязать external_id к правильному объекту
curl -X PATCH "https://colife.argo.properties:1337/external_api/realty/units/CORRECT_UNIT_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" \
     -H "Content-Type: application/json" \
     -d '{"external_id": "EXTERNAL_ID"}'
```

### 2. Ошибка "Cannot create contract on archived unit"

**Причина:** Объект заархивирован в Alma.

**Решение:** Разархивировать объект в веб-интерфейсе Alma или через API:
```bash
curl -X PATCH "https://colife.argo.properties:1337/external_api/realty/units/UNIT_ID/archive/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" \
     -H "Content-Type: application/json" \
     -d '{"is_archived": false}'
```

### 3. Ошибка "It is forbidden to edit the archive usage"

**Причина:** Система пытается обновить контракт, который имеет архивный usage в Alma.

**Диагностика:**
- Контракт существует в Alma, но его `unit_usage.is_archived = true`
- Система пытается обновить архивный контракт вместо создания нового

**Решение:** 
1. Проверить статус существующего контракта
2. Если usage заархивирован - создать новый контракт вместо обновления
3. Возможно потребуется ручное вмешательство в Alma для разархивирования

```bash
# Проверить существующий контракт
curl -X GET "https://colife.argo.properties:1337/external_api/realty/contracts/tenant_contracts/external_id/EXTERNAL_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

### 4. Ошибка "This field may not be null" (last_name)

**Причина:** У клиента в контракте отсутствует обязательное поле фамилии (last_name).

**Диагностика:**
- Проверить данные контакта в Bitrix24
- Убедиться, что поле LAST_NAME заполнено

**Решение:**
1. Заполнить поле LAST_NAME в контакте в Bitrix24
2. Или добавить проверку в код для обработки пустых полей

```bash
# Проверить данные контакта
curl -X POST "https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.contact.get" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "id=CONTACT_ID"
```

### 5. Ошибка "There are intersections in the use of the unit"

**Причина:** Система пытается создать контракт на объект, который уже занят в указанные даты другим контрактом.

**Диагностика:**
- Проверить статус объекта в Alma (должен быть "available", а не "rented")
- Проверить существующие контракты на этот объект
- Убедиться, что даты нового контракта не пересекаются с существующими

**Решение:**
1. Проверить статус объекта в Alma
2. Если объект "rented" - завершить существующий контракт
3. Изменить даты контракта в Bitrix24
4. Или использовать другой объект

```bash
# Проверить статус объекта
curl -X GET "https://colife.argo.properties:1337/external_api/realty/units/UNIT_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"

# Проверить все контракты на объект
curl -X GET "https://colife.argo.properties:1337/external_api/realty/contracts/tenant_contracts/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" | grep "UNIT_ID"

# Проверить конкретный usage по ID (если указан в ошибке)
curl -X GET "https://colife.argo.properties:1337/external_api/realty/unit_usages/USAGE_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

**Пример:** Контракт 6086 пытался создать контракт на объект с external_id "1770" (ID 15409), но этот объект уже имеет статус "rented" и занят другим контрактом.

### 6. External ID маппинг проблемы

**Важно понимать:**
- В Bitrix24 поле `ufCrm20_1693919019` содержит **внутренний ID апартамента из Bitrix24**
- Этот ID используется как `external_id` в Alma
- Система ищет объект в Alma через `/external_api/realty/rental_object/EXTERNAL_ID/`

**Процесс поиска объекта:**
1. Контракт → `ufCrm20_1693919019` (ID апартамента в Bitrix24)
2. Система ищет в Alma объект с `external_id = ID из Bitrix24`
3. Если найден - использует его для создания контракта

**Частая ошибка:** External_id привязан к неправильному объекту в Alma.

## 🔧 Полезные команды для диагностики

### Проверить контракт в Bitrix24:
```bash
curl -X POST "https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.item.get" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "entityTypeId=183&id=CONTRACT_ID" | jq '.result.item.ufCrm20_1693919019'
```

### Проверить объект в Alma по external_id:
```bash
curl -X GET "https://colife.argo.properties:1337/external_api/realty/rental_object/EXTERNAL_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

### Найти объекты с похожим названием:
```bash
curl -X GET "https://colife.argo.properties:1337/external_api/realty/units/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" | \
     jq '.[] | select(.name | contains("SEARCH_TERM")) | {id, name, external_id}'
```

### Проверить детали объекта:
```bash
curl -X GET "https://colife.argo.properties:1337/external_api/realty/units/UNIT_ID/" \
     -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde" | \
     jq '{id, name, external_id, is_archived, status}'
```

### Тестировать интеграцию:
```bash
curl -X GET "https://alma.colifeb24apps.ru/tenatContract.php?id=CONTRACT_ID"
```

## 📋 Чек-лист для решения проблем с контрактами

1. ✅ Получить данные контракта из Bitrix24
2. ✅ Найти поле `ufCrm20_1693919019` (ID апартамента)
3. ✅ Проверить, какой объект в Alma привязан к этому external_id
4. ✅ Убедиться, что объект не заархивирован
5. ✅ Убедиться, что объект не разделен на комнаты
6. ✅ Если объект неправильный - найти правильный и переназначить external_id
7. ✅ Протестировать интеграцию

## 🎯 Ключевые поля в документации

- `UF_CRM_20_1693919019` - **Apartments** (ID апартамента в контрактах)
- `UF_CRM_20_CONTRACT_START_DATE` - дата начала аренды
- `UF_CRM_20_CONTRACT_END_DATE` - дата окончания аренды
- `OPPORTUNITY_WITH_CURRENCY` - сумма контракта

## ⚠️ НЕ ДЕЛАТЬ

- НЕ выдумывать новые поля (например, `parentId167`)
- НЕ пытаться обновлять поля через API, если они не обновляются
- НЕ создавать контракты на заархивированные или разделенные на комнаты объекты

## 🔍 ОТКРЫТЫЕ ВОПРОСЫ И ПРОБЛЕМЫ

### ❓ Проблема с датами контрактов
**Найдена проблема:** Система читает неправильные поля дат в контрактах.

**Контракт 6026 (пример):**
- Используется: `ufCrm20ContractStartDate: "2025-03-08"` (неправильно)
- Должно быть: `ufCrm_20_CONTRACT_START_DATE_2: "2025-09-08"` (правильно)

**Возможное решение:** Изменить код чтобы читать из полей `_2`:
```php
'UF_CRM_20_CONTRACT_START_DATE' => $bitrixData['ufCrm_20_CONTRACT_START_DATE_2'] ?? $bitrixData['ufCrm20ContractStartDate'] ?? '',
'UF_CRM_20_CONTRACT_END_DATE' => $bitrixData['ufCrm_20_CONTRACT_END_DATE_2'] ?? $bitrixData['ufCrm20ContractEndDate'] ?? '',
```

**Статус:** Требует подтверждения от пользователя


## 🔄 Структура данных

**Bitrix24 → Alma маппинг:**
- Внутренний ID апартамента в Bitrix24 → external_id в Alma
- Система использует rental_object API для поиска правильного объекта
- Контракты создаются через tenant_contracts API

**Иерархия в Alma:**
- Проекты → Здания → Апартаменты → Комнаты
- Контракты создаются на апартаменты или комнаты, но НЕ на разделенные апартаменты

### Структура успешного ответа от API

При успешной синхронизации контракта API возвращает следующую структуру:

```json
{
  "success": true,
  "message": "Contract successfully synchronized",
  "alma_id": 5861,
  "data": {
    "id": 5861,
    "name": "Contract_Aleksandr_Danilov_Un. 259.1 / Park Ridge Tower C, apt. 221_24.08.2025",
    "number": "",
    "start_date": "2025-08-24T00:00:00",
    "end_date": "2025-09-26T00:00:00",
    "external_id": "5644",
    "created_at": "2025-09-19T08:58:32.835589Z",
    "updated_at": "2025-09-22T06:39:49.093601Z",
    "unit_usage": {
      "usage_id": 16968,
      "client_id": 12637,
      "unit_id": 1751,
      "client_type": "tenant",
      "is_archived": false
    },
    "price": "8899.00",
    "contract_scan": "/private-media/colife/contracts_files/tenant_contracts/contract_5861/contract_scan/259%20signed%20(1).pdf",
    "co_tenant": "",
    "co_tenant_id_scan": null,
    "history": "",
    "type_contract": "Short term from 1 to 3 months",
    "co_tenant_id": ""
  }
}
```

**Ключевые поля для логики проверки смены клиента:**
- `unit_usage.client_id` - ID текущего клиента в контракте
- `unit_usage.is_archived` - статус архивации usage (если true - нельзя редактировать)
