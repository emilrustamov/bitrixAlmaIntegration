<?php

require_once __DIR__ . '/../Core/Database/DatabaseConfig.php';
require_once __DIR__ . '/../Core/Config/Config.php';

Config::load();

class CreateBitrixStatsTableMigration {
    private $pdo;
    
    public function __construct() {
        $this->pdo = new PDO(
            'mysql:host=' . Config::get('DB_HOST') . ';dbname=' . Config::get('DB_NAME') . ';charset=' . Config::get('DB_CHARSET'),
            Config::get('DB_USER'),
            Config::get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function up() {
        echo "Creating bitrix_stats table...\n";
        
        $sql = "
            CREATE TABLE IF NOT EXISTS bitrix_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                apartments INT NOT NULL DEFAULT 0,
                rooms INT NOT NULL DEFAULT 0,
                total INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->pdo->exec($sql);
        echo "✅ bitrix_stats table created successfully\n";
    }
    
    public function down() {
        echo "Dropping bitrix_stats table...\n";
        $this->pdo->exec("DROP TABLE IF EXISTS bitrix_stats");
        echo "✅ bitrix_stats table dropped successfully\n";
    }
}

// Выполняем миграцию если скрипт запущен напрямую
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $migration = new CreateBitrixStatsTableMigration();
    $migration->up();
}
