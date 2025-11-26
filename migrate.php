<?php
/**
 * Alma Migration Manager
 * Управление миграциями базы данных
 */

require_once('Config.php');
require_once('Logger.php');

Config::load();

class MigrationManager {
    private $pdo;
    private $migrationsDir;
    
    public function __construct() {
        $this->migrationsDir = __DIR__ . '/migrations';
        $this->pdo = new PDO(
            "mysql:host=" . Config::get('DB_HOST') . ";dbname=" . Config::get('DB_NAME') . ";charset=" . Config::get('DB_CHARSET'),
            Config::get('DB_USER'),
            Config::get('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $this->ensureMigrationsTable();
    }
    
    private function ensureMigrationsTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $this->pdo->exec($sql);
    }
    
    public function getAvailableMigrations() {
        $migrations = [];
        $files = glob($this->migrationsDir . '/*.php');
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $migrations[] = $filename;
        }
        
        sort($migrations);
        return $migrations;
    }
    
    public function getExecutedMigrations() {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY migration");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getPendingMigrations() {
        $available = $this->getAvailableMigrations();
        $executed = $this->getExecutedMigrations();
        
        return array_diff($available, $executed);
    }
    
    public function runMigration($migrationName) {
        $migrationFile = $this->migrationsDir . '/' . $migrationName . '.php';
        
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationName");
        }
        
        echo "🚀 Running migration: $migrationName\n";
        
        require_once $migrationFile;
        
        // Предполагаем, что класс миграции называется по шаблону
        $className = $this->getMigrationClassName($migrationName);
        
        if (!class_exists($className)) {
            throw new Exception("Migration class not found: $className");
        }
        
        $migration = new $className();
        $migration->up();
        
        echo "✅ Migration $migrationName completed\n";
    }
    
    public function rollbackMigration($migrationName) {
        $migrationFile = $this->migrationsDir . '/' . $migrationName . '.php';
        
        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: $migrationName");
        }
        
        echo "🔄 Rolling back migration: $migrationName\n";
        
        require_once $migrationFile;
        
        $className = $this->getMigrationClassName($migrationName);
        
        if (!class_exists($className)) {
            throw new Exception("Migration class not found: $className");
        }
        
        $migration = new $className();
        $migration->down();
        
        echo "✅ Migration $migrationName rolled back\n";
    }
    
    public function runAllPending() {
        $pending = $this->getPendingMigrations();
        
        if (empty($pending)) {
            echo "✅ No pending migrations\n";
            return;
        }
        
        echo "📋 Found " . count($pending) . " pending migrations\n";
        
        foreach ($pending as $migration) {
            $this->runMigration($migration);
        }
        
        echo "🎉 All migrations completed!\n";
    }
    
    public function status() {
        $available = $this->getAvailableMigrations();
        $executed = $this->getExecutedMigrations();
        $pending = $this->getPendingMigrations();
        
        echo "📊 Migration Status:\n";
        echo "Available: " . count($available) . "\n";
        echo "Executed: " . count($executed) . "\n";
        echo "Pending: " . count($pending) . "\n\n";
        
        if (!empty($pending)) {
            echo "🔄 Pending migrations:\n";
            foreach ($pending as $migration) {
                echo "  - $migration\n";
            }
        }
        
        if (!empty($executed)) {
            echo "\n✅ Executed migrations:\n";
            foreach ($executed as $migration) {
                echo "  - $migration\n";
            }
        }
    }
    
    private function getMigrationClassName($migrationName) {
        // Преобразуем имя файла в имя класса
        // 001_create_alma_tables -> CreateAlmaTablesMigration
        $parts = explode('_', $migrationName);
        array_shift($parts); // Убираем номер
        
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        $className .= 'Migration';
        
        return $className;
    }
}

// CLI интерфейс
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $manager = new MigrationManager();
        $command = $argv[1] ?? 'status';
        
        switch ($command) {
            case 'status':
                $manager->status();
                break;
                
            case 'migrate':
                $manager->runAllPending();
                break;
                
            case 'rollback':
                $migration = $argv[2] ?? null;
                if (!$migration) {
                    echo "Usage: php migrate.php rollback <migration_name>\n";
                    exit(1);
                }
                $manager->rollbackMigration($migration);
                break;
                
            case 'run':
                $migration = $argv[2] ?? null;
                if (!$migration) {
                    echo "Usage: php migrate.php run <migration_name>\n";
                    exit(1);
                }
                $manager->runMigration($migration);
                break;
                
            default:
                echo "Alma Migration Manager\n\n";
                echo "Usage:\n";
                echo "  php migrate.php status              - Show migration status\n";
                echo "  php migrate.php migrate             - Run all pending migrations\n";
                echo "  php migrate.php run <migration>     - Run specific migration\n";
                echo "  php migrate.php rollback <migration> - Rollback specific migration\n";
                break;
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
