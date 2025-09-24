# API Field Mapping Documentation

## Bitrix24 API

### Правильный способ запроса к Bitrix24

**❌ Неправильно (прямой curl):**
```bash
curl -s "https://colifeae.bitrix24.eu/rest/35578/API_KEY/crm.item.get?entityTypeId=144&id=14"
```

**✅ Правильно (через webhook):**
```bash
curl -X POST "https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.item.get" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "entityTypeId=144&id=14"
```

### Поля в Bitrix24

**Поле "Rent (by rooms / unit)":**
- **Название в API**: `ufCrm6_1736951470242` (с маленькой буквы!)
- **НЕ**: `UF_CRM_6_1736951470242` (с большой буквы)

**Значения поля:**
- `4600` = "rooms" (шеринговый апартамент)
- `4598` = "unit" (обычный апартамент)

### Проверка апартамента в Bitrix24

```bash
curl -X POST "https://colifeae.bitrix24.eu/rest/86428/veqe4foxak36hydi/crm.item.get" \
     -H "Content-Type: application/x-www-form-urlencoded" \
     -d "entityTypeId=144&id=APARTMENT_ID" | \
jq '.result.item | {id, title, ufCrm6_1736951470242}'
```

## Alma API

### Получение списка объектов

**Список объектов возвращает только базовые поля:**
```bash
curl -s -H "Api-Key: API_KEY" "https://colife.argo.properties:1337/external_api/realty/units/" | \
jq '.[] | {id, external_id, is_used_additional_external_id, name}'
# is_used_additional_external_id будет null!
```

**Для получения полной информации нужен отдельный запрос:**
```bash
curl -s -H "Api-Key: API_KEY" "https://colife.argo.properties:1337/external_api/realty/units/OBJECT_ID/" | \
jq '{id, external_id, is_used_additional_external_id, name}'
```

### Поле is_used_additional_external_id

**Логика:**
- `true` = шеринговый апартамент (rooms)
- `false` = обычный апартамент (unit)

**Определение из Bitrix24:**
```php
$correctIsUsed = ($bitrixData['rent_type'] === 'rooms'); // rooms = шеринговый
```

## Известные шеринговые апартаменты

Проверенные апартаменты с `rent_type = 4600` (rooms):

| ID  | Название                          | Rent Type |
|-----|-----------------------------------|-----------|
| 4   | Ap. 02. Marina Wharf 2, apt. 1701 | 4600      |
| 8   | Ap. 04. Marina Terrace, apt. 127  | 4600      |
| 14  | Ap. 08. Sadaf 6, apt. 212         | 4600      |
| 16  | Ap. 09. Up Tower, apt. 301        | 4600      |
| 48  | Ap. 25. Princess, apt. 1203       | 4600      |
| 58  | Ap. 30. Rimal 6, apt. 201         | 4600      |
| 116 | Ap. 59. Up Tower, apt. 2206       | 4600      |
| 118 | Ap. 60. Up Tower, apt. 2306       | 4600      |

## Скрипты для проверки

### Массовая проверка и исправление
```bash
# Проверить первые 50 объектов
curl -s "https://alma.colifeb24apps.ru/mass_fix_rent_types.php?limit=50"

# Проверить все объекты
curl -s "https://alma.colifeb24apps.ru/mass_fix_rent_types.php?limit=0"
```

### Проверка конкретного объекта
```bash
curl -s "https://alma.colifeb24apps.ru/check_rent_types.php?id=OBJECT_ID"
```

### Валидация контракта
```bash
curl -s "https://alma.colifeb24apps.ru/validate_sync.php?id=CONTRACT_ID"
```

## Важные моменты

1. **Всегда используйте webhook URL** для запросов к Bitrix24
2. **Поле rent_type в Bitrix24** называется `ufCrm6_1736951470242` (с маленькой буквы)
3. **Список объектов в Alma** не возвращает `is_used_additional_external_id` - нужен отдельный запрос
4. **Шеринговые апартаменты** имеют `rent_type = 4600` в Bitrix24 и `is_used_additional_external_id = true` в Alma
5. **Массовое исправление** уже выполнено для 376 объектов

## Результаты массового исправления

- **Проверено**: 647 объектов с external_id
- **Исправлено**: 376 объектов ✅
- **Пропущено**: 58 объектов (тестовые или отсутствующие в Bitrix24)
- **Ошибок**: 68 объектов (заархивированные - это нормально)

Все основные проблемы с `is_used_additional_external_id` решены!
