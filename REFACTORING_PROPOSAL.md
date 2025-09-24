# Предложение по рефакторингу структуры проекта

## Текущие проблемы:
1. **Дублирование кода** - `tenant.php` и `api/AlmaTenantsApi.php` содержат одинаковую логику
2. **Неиспользуемые методы** - `getAllTenants()`, `validateBirthdate()`
3. **Смешанная ответственность** - в одном файле API клиенты, валидация, логика
4. **Отсутствие разделения** - нет четкого разделения на слои

## Предлагаемая структура:

```
/var/www/alma/
├── config/
│   ├── Config.php
│   ├── ProjectMapping.php
│   └── config.env
├── controllers/
│   ├── ApartmentController.php
│   ├── TenantController.php
│   └── ContractController.php
├── services/
│   ├── ApartmentService.php
│   ├── TenantService.php
│   ├── ContractService.php
│   └── BuildingService.php
├── models/
│   ├── Apartment.php
│   ├── Tenant.php
│   ├── Contract.php
│   └── Building.php
├── api/
│   ├── AlmaApiClient.php
│   ├── Bitrix24ApiClient.php
│   └── BaseApiClient.php
├── utils/
│   ├── Logger.php
│   ├── Validator.php
│   └── FileUploader.php
├── handlers/
│   ├── ApartmentHandler.php
│   ├── TenantHandler.php
│   └── ContractHandler.php
├── index.php (main router)
├── appart.php (legacy)
├── tenant.php (legacy)
└── tenatContract.php (legacy)
```

## Преимущества:
1. **Разделение ответственности** - каждый класс имеет одну задачу
2. **Переиспользование кода** - общие методы в базовых классах
3. **Легкость тестирования** - можно тестировать каждый слой отдельно
4. **Масштабируемость** - легко добавлять новые проекты/функции
5. **Читаемость** - понятная структура проекта

## План миграции:
1. Создать базовые классы (`BaseApiClient`, `BaseService`)
2. Выделить общую логику в сервисы
3. Создать модели для данных
4. Обновить контроллеры
5. Постепенно мигрировать существующие файлы

