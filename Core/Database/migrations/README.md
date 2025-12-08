# 🗄️ Alma Database Migrations

Система миграций для управления структурой базы данных Alma.

## 📋 Доступные команды

### Просмотр статуса миграций
```bash
php migrate.php status
```

### Запуск всех ожидающих миграций
```bash
php migrate.php migrate
```

### Запуск конкретной миграции
```bash
php migrate.php run 001_create_alma_tables
```

### Откат миграции
```bash
php migrate.php rollback 001_create_alma_tables
```

## 📊 Структура таблиц

### `alma_objects`
Базовая информация об объектах недвижимости:
- `alma_id` - ID объекта в Alma
- `external_id` - ID объекта в Bitrix24
- `name` - Название объекта
- `header` - Заголовок объекта
- `object_type` - Тип объекта (apartment/room)
- `is_room` - Является ли комнатой

### `alma_object_details`
Детальная информация об объектах:
- Статус архивации
- Информация о проекте и здании
- Площадь, количество комнат
- Цена аренды
- Адрес и контактная информация
- Технические детали (WiFi, замки и т.д.)

### `alma_projects`
Информация о проектах:
- `alma_id` - ID проекта в Alma
- `name` - Название проекта

### `alma_buildings`
Информация о зданиях:
- `alma_id` - ID здания в Alma
- `name` - Название здания
- `project_id` - Связь с проектом
- `number_of_units` - Количество юнитов

### `migrations`
Отслеживание выполненных миграций:
- `migration` - Название миграции
- `executed_at` - Время выполнения

## 🚀 Первый запуск

1. Убедитесь что настроен `config.env` с параметрами БД
2. Запустите миграции:
   ```bash
   php migrate.php migrate
   ```
3. Проверьте статус:
   ```bash
   php migrate.php status
   ```

## 📝 Создание новых миграций

Создайте файл в формате: `002_название_миграции.php`

Структура миграции:
```php
<?php
class НазваниеМиграцииMigration {
    private $pdo;
    
    public function __construct() {
        // Инициализация PDO
    }
    
    public function up() {
        // Применение изменений
    }
    
    public function down() {
        // Откат изменений
    }
}
?>
```
