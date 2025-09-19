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

### 3. External ID маппинг проблемы

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
