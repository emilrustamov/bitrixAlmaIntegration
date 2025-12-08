#!/bin/bash

# Скрипт для настройки cron job для синхронизации Alma объектов
# Запускается каждый час

SCRIPT_DIR="/var/www/alma/objects_app"
PHP_PATH="/usr/bin/php"
LOG_FILE="/var/www/alma/logs/objects_sync.log"

# Создаем директорию для логов если не существует
mkdir -p /var/www/alma/logs

# Добавляем cron job (каждый час в 5 минут)
CRON_JOB="5 * * * * $PHP_PATH $SCRIPT_DIR/sync_objects_full.php >> $LOG_FILE 2>&1"

# Проверяем, существует ли уже такой cron job
if ! crontab -l 2>/dev/null | grep -q "sync_objects_full.php"; then
    # Добавляем новый cron job
    (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
    echo "✅ Cron job добавлен: синхронизация объектов Alma каждый час в 5 минут"
    echo "📋 Команда: $CRON_JOB"
else
    echo "ℹ️  Cron job уже существует"
fi

# Показываем текущие cron jobs
echo ""
echo "📋 Текущие cron jobs:"
crontab -l

echo ""
echo "📝 Логи будут записываться в: $LOG_FILE"
echo "🔍 Для просмотра логов используйте: tail -f $LOG_FILE"
