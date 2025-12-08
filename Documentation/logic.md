# Справочник по синхронизации контрактов

## 📋 Содержание
1. [Структура данных](#структура-данных)
2. [Логика шеринга апартаментов](#логика-шеринга-апартаментов)
3. [Как работает поиск объектов](#как-работает-поиск-объектов)
4. [Команды для работы](#команды-для-работы)
5. [API методы для проверки](#api-методы-для-проверки)

---

## 🏗️ Структура данных

### В Alma:
```
Проекты → Здания → Апартаменты → Комнаты
```

### В Bitrix24:

**Апартаменты (entityTypeId: 144):**
- [Список апартаментов](https://colifeae.bitrix24.eu/page/apartments/apartments/type/144/)
- Содержат данные апартамента (название, адрес, тип и т.д.)
- Поле `ufCrm6_1736951470242` определяет тип аренды (4600 = 'rooms', 4598 = 'unit')

**Юниты (entityTypeId: 167):**
- [Список юнитов](https://colifeae.bitrix24.eu/page/units/ddlqfr/type/167/)
- Принадлежат апартаменту через поле `ufCrm8_1684429208`
- Содержат конкретные данные комнаты/юнита

**Контракты (entityTypeId: 183):**
- [Список контрактов](https://colifeae.bitrix24.eu/page/register_of_documents/register_of_documents/type/183/)
- Ссылаются на юнит через поле `ufCrm20_1693919019`

### Связи между объектами:

```
Контракт (183) 
    ↓ ufCrm20_1693919019
Юнит (167)
    ↓ ufCrm8_1684429208  
Апартамент (144)
    ↓ ufCrm6_1736951470242
Тип аренды (rooms/unit)
```

---

## 🔄 Логика шеринга апартаментов

### Основные принципы

### Шеринг (rent_type = 'rooms'):
- **Апартамент в Alma:**
  - `external_id` = ID апартамента из Bitrix24
  - `additional_external_id` = "" (пустой)
  - `is_used_additional_external_id` = false

- **Комнаты в Alma:**
  - `external_id` = ID юнита из Bitrix24
  - `parent_unit` = ID апартамента в Alma (не из Bitrix24!)

### Не-шеринг (rent_type = 'unit'):
- **Апартамент в Alma:**
  - `external_id` = ID апартамента из Bitrix24
  - `additional_external_id` = ID юнита из Bitrix24
  - `is_used_additional_external_id` = true

### Важные моменты

1. **Комнаты НЕ должны занимать external_id апартаментов!**
2. **Комнаты должны иметь parent_unit = ID апартамента в Alma**
3. **additional_external_id используется только для поиска, не для комнат**

### Пример конфликта

❌ **Неправильно:**
- Комната: `external_id: "62"` (занимает ID апартамента)
- Апартамент: не может найтись по `external_id: "62"`

✅ **Правильно:**
- Комната: `external_id: "134"` (ID юнита из Bitrix24)
- Апартамент: `external_id: "62"` (ID апартамента из Bitrix24)

### Исправление конфликтов

1. Переименовать комнату: `external_id: "old_id" → "unit_id_from_bitrix"`
2. Проверить, что апартамент может найтись по своему ID
3. Убедиться, что `parent_unit` у комнаты указывает на правильный апартамент в Alma

### ⚠️ Важные ограничения

**Контракты НЕ синхронизируются для следующих типов:**
- **Airbnb** (тип: 882) - исключены из синхронизации
- **Ejari** (тип: 8672) - исключены из синхронизации

**Синхронизируются только контракты типов:**
- **884** - Short term from 1 to 3 months
- **886** - Long-term 3+ months
- **1304** - Booking
- **1306** - Short contract up to 1 month
- **6578** - Less than a month

---

## 🔍 Как работает поиск объектов

### Логика поиска rental_object в Alma

#### Алгоритм работы API `rental_object`:

**1. Поиск объектов:**
```php
GET: external_api/realty/rental_object/{{bitrix_id}}/
```

**Сначала выбираются все комнаты и юниты:**
- **Комнаты** - у которых `external_id` совпадает с вашим `external_id`
- **Полные юниты** - у которых `additional_external_id` совпадает с вашим `external_id` и `is_used_additional_external_id` включён

**2. Определение приоритета:**

| Приоритет | Условие | Описание |
|-----------|---------|----------|
| **3 (высший)** | `additional_external_id` совпадает, `is_used_additional_external_id = true` | Полный юнит найден по дополнительному идентификатору |
| **2 (средний)** | Дочерний объект (есть `parent_unit`) | Комната, часть юнита |
| **1 (низкий)** | Все остальные объекты | Обычные юниты и апартаменты |
| **0 (специальный)** | `additional_external_id = ""`, `is_used_additional_external_id = true`, `external_id` совпадает | Полный юнит по основному ID |

**3. Итоговый результат:**
- Записи сортируются по возрастанию приоритета и убыванию ID
- Возвращается первая запись из отсортированного списка

### Логика поиска в TenantContractApi.php:

1. **Проверка типа контракта:**
   - Контракт содержит поле `ufCrm20_1693561495` = тип контракта
   - **Airbnb (882) и Ejari (8672) исключаются** - возвращается ошибка
   - Остальные типы проходят валидацию

2. **Получение данных из Bitrix24:**
   - Контракт содержит поле `ufCrm20_1693919019` = ID юнита из Bitrix24
   - Юнит содержит поле `ufCrm8_1684429208` = ID апартамента из Bitrix24
   - Апартамент содержит поле `ufCrm6_1736951470242` = тип аренды (4600 = 'rooms', 4598 = 'unit')

3. **Определение типа объекта:**
   - Если `rent_type = 'rooms'` → это шеринговый апартамент
   - Если `rent_type = 'unit'` → это обычный апартамент

4. **Поиск в Alma:**
   - **Для шеринговых:** ищем комнату по `external_id = unit_id` из Bitrix24
   - **Для обычных:** ищем апартамент по `external_id = apartment_id` из Bitrix24

### Пример цепочки поиска:

#### Шеринговый апартамент (rooms):
```
Контракт 6222 → unit_id: 62 → apartment_id: 14 → rent_type: 4600 (rooms)
↓
Шеринговый апартамент → ищем комнату с external_id: "62"
↓
Находим комнату 2376 с external_id: "62" и parent_unit: 15477
```

#### Не-шеринговый апартамент (unit):
```
Контракт 8331 → unit_id: 88 → apartment_id: 15 → rent_type: 4598 (unit)
↓
Обычный апартамент → ищем апартамент с external_id: "15" 
↓
Находим апартамент 5421 с external_id: "15" (приоритет зависит от additional_external_id)
```

### Пример работы rental_object API:

```php
GET /realty/rental_object/62/ 
```

**Результат поиска объекта ID "62":**

1. **Ищем комнаты:** external_id = "62" → Find: Room ID 2376 (приоритет 2)
2. **Ищем полные юниты:** additional_external_id = "62" + is_used = true → Find: Unit ID 2992 (приоритет 3)  
3. **Сортируется по приоритету:** Unit(3) → Room(2)
4. **Возвращается:** Unit ID 2992 (высший приоритет)
```

### Ключевые поля для поиска:

- **ufCrm20_1693561495** (в контракте) = тип контракта (882/8672 исключены)
- **ufCrm20_1693919019** (в контракте) = unit_id из Bitrix24
- **ufCrm8_1684429208** (в юните) = apartment_id из Bitrix24  
- **ufCrm6_1736951470242** (в апартаменте) = rent_type (4600/4598)

---

## ⚡ Команды для работы

### Синхронизация контракта:
```bash
cd /var/www/alma && php -r '$_GET["id"] = "КОНТРАКТ_ID"; include "TenantContractApi.php";'
```

### Примеры:
```bash
# Любой контракт
cd /var/www/alma && php -r '$_GET["id"] = "НОМЕР_КОНТРАКТА"; include "TenantContractApi.php";'
```

---

## 🔧 API методы для проверки

### Проверка в Alma:

#### Проверка контракта в Alma:
```bash
curl -s "https://colife.argo.properties:1337/external_api/realty/contracts/tenant_contracts/external_id/КОНТРАКТ_ID/" -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

#### Проверка комнаты в Alma:
```bash
curl -s "https://colife.argo.properties:1337/external_api/realty/rooms/external_id/UNIT_ID/" -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

#### Проверка апартамента в Alma:
```bash
curl -s "https://colife.argo.properties:1337/external_api/realty/rental_object/APARTMENT_ID/" -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"
```

### Проверка в Bitrix24:

#### Получить один элемент (get):
```bash
# Контракт
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=183&id=КОНТРАКТ_ID"

# Юнит
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=167&id=UNIT_ID"

# Апартамент
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=144&id=APARTMENT_ID"

# Клиент
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.contact.get?id=CLIENT_ID"
```

#### Получить список элементов (list):
```bash
# Все контракты с лимитом
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=183&start=0&limit=50"

# Все юниты с лимитом
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=167&start=0&limit=50"

# Все апартаменты с лимитом
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=144&start=0&limit=50"

# Контракты с фильтром по типу (POST запрос)
curl -s -X POST "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list" \
  -d "entityTypeId=183&filter[ufCrm20_1693561495]=884"

# Контракты для определенного клиента
curl -s -X POST "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list" \
  -d "entityTypeId=183&filter[ufCrm20_1693919019]=UNIT_ID"

# С пагинацией (start=0, limit=50 для первой страницы)
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=183&start=0&limit=50"

# Вторая страница
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=183&start=50&limit=50"
```

### Примеры проверки:

**Контракт 6222:**
```bash
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=183&id=6222"
```

**Юнит 62:**
```bash
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=167&id=62"
```

**Апартамент 14:**
```bash
curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=144&id=14"
```

---

## 📝 Быстрый справочник

| Что нужно | Команда |
|-----------|---------|
| Синхронизировать контракт | `cd /var/www/alma && php -r '$_GET["id"] = "ID"; include "TenantContractApi.php";'` |
| Проверить контракт в Alma | `curl -s "https://colife.argo.properties:1337/external_api/realty/contracts/tenant_contracts/external_id/ID/" -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"` |
| Проверить контракт в Bitrix24 | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=183&id=ID"` |
| **Получить список контрактов в Bitrix24** | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=183&start=0&limit=50"` |
| Проверить комнату в Alma | `curl -s "https://colife.argo.properties:1337/external_api/realty/rooms/external_id/ID/" -H "Api-Key: 3ae0539d134e9b7320e6d3ff28a11bde"` |
| Проверить юнит в Bitrix24 | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=167&id=ID"` |
| **Получить список юнитов в Bitrix24** | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=167&start=0&limit=50"` |
| Проверить апартамент в Bitrix24 | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.get?entityTypeId=144&id=ID"` |
| **Получить список апартаментов в Bitrix24** | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.item.list?entityTypeId=144&start=0&limit=50"` |
| Проверить клиента в Bitrix24 | `curl -s "https://colifeae.bitrix24.eu/rest/4/iy4qd3t503yjho46//crm.contact.get?id=ID"` |