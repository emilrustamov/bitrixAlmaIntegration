<?php

require_once(__DIR__ . '/../Config.php');
require_once(__DIR__ . '/../Logger.php');

Config::load();

class SimplifiedAlmaTablesMigration {
    private $pdo;
    
    public function __construct() {
        $this->pdo = new PDO(
            "mysql:host=" . Config::get('DB_HOST') . ";dbname=" . Config::get('DB_NAME') . ";charset=" . Config::get('DB_CHARSET'),
            Config::get('DB_USER'),
            Config::get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function up() {
        echo "🚀 Running migration: Simplified Alma Tables\n";
        
        // Таблица для объектов (базовая информация)
        $sqlObjects = "
            CREATE TABLE IF NOT EXISTS alma_objects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alma_id INT NOT NULL UNIQUE,
                external_id VARCHAR(255),
                name VARCHAR(255),
                header VARCHAR(255),
                object_type ENUM('apartment', 'room') NOT NULL,
                is_room BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_alma_id (alma_id),
                INDEX idx_external_id (external_id),
                INDEX idx_type (object_type),
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        // Таблица для детальной информации объектов (только нужные поля)
        $sqlDetails = "
            CREATE TABLE IF NOT EXISTS alma_object_details (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alma_id INT NOT NULL UNIQUE,
                is_archived BOOLEAN DEFAULT FALSE,
                status VARCHAR(50),
                project_name VARCHAR(255),
                building_name VARCHAR(255),
                parent_unit_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_alma_id (alma_id),
                INDEX idx_project (project_name),
                INDEX idx_status (status),
                INDEX idx_archived (is_archived),
                FOREIGN KEY (alma_id) REFERENCES alma_objects(alma_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        // Таблица для отслеживания миграций (если не существует)
        $sqlMigrations = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        try {
            echo "📋 Creating alma_objects table...\n";
            $this->pdo->exec($sqlObjects);
            
            echo "📋 Creating alma_object_details table...\n";
            $this->pdo->exec($sqlDetails);
            
            echo "📋 Ensuring migrations table exists...\n";
            $this->pdo->exec($sqlMigrations);
            
            // Записываем выполнение миграции
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO migrations (migration) VALUES (?)");
            $stmt->execute(['002_simplified_alma_tables']);
            
            echo "✅ Migration completed successfully!\n";
            Logger::info("Migration 002_simplified_alma_tables executed successfully");
            
        } catch (PDOException $e) {
            echo "❌ Migration failed: " . $e->getMessage() . "\n";
            Logger::error("Migration 002_simplified_alma_tables failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function down() {
        echo "🔄 Rolling back migration: Simplified Alma Tables\n";
        
        $tables = [
            'alma_object_details',
            'alma_objects'
        ];
        
        try {
            foreach ($tables as $table) {
                echo "🗑️ Dropping table: $table\n";
                $this->pdo->exec("DROP TABLE IF EXISTS $table");
            }
            
            // Удаляем запись о миграции
            $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?");
            $stmt->execute(['002_simplified_alma_tables']);
            
            echo "✅ Rollback completed successfully!\n";
            Logger::info("Migration 002_simplified_alma_tables rolled back successfully");
            
        } catch (PDOException $e) {
            echo "❌ Rollback failed: " . $e->getMessage() . "\n";
            Logger::error("Migration 002_simplified_alma_tables rollback failed: " . $e->getMessage());
            throw $e;
        }
    }
}

// Запуск миграции если файл вызван напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $migration = new SimplifiedAlmaTablesMigration();
        
        $action = $argv[1] ?? 'up';
        
        if ($action === 'up') {
            $migration->up();
        } elseif ($action === 'down') {
            $migration->down();
        } else {
            echo "Usage: php " . basename(__FILE__) . " [up|down]\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
