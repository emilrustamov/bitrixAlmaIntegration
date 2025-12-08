<?php

require_once(__DIR__ . '/../Config.php');
require_once(__DIR__ . '/../Logger.php');

Config::load();

class CreateBitrixDataTableMigration {
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
        echo "📋 Creating bitrix_data table...\n";
        
        $sql = "
            CREATE TABLE IF NOT EXISTS bitrix_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                external_id VARCHAR(50) NOT NULL,
                object_type ENUM('unit', 'room') NOT NULL,
                bitrix_type ENUM('unit', 'room') NOT NULL,
                bitrix_apartment_id INT NULL,
                bitrix_apartment_name VARCHAR(255) NULL,
                bitrix_unit_id INT NULL,
                bitrix_unit_name VARCHAR(255) NULL,
                bitrix_rent_type ENUM('rooms', 'unit') NULL,
                bitrix_status ENUM('found', 'not_found', 'error') NOT NULL DEFAULT 'not_found',
                bitrix_error TEXT NULL,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                UNIQUE KEY unique_external_object (external_id, object_type),
                INDEX idx_external_id (external_id),
                INDEX idx_object_type (object_type),
                INDEX idx_bitrix_status (bitrix_status),
                INDEX idx_last_updated (last_updated)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $this->pdo->exec($sql);
        echo "✅ bitrix_data table created successfully\n";
    }
    
    public function down() {
        echo "📋 Dropping bitrix_data table...\n";
        $this->pdo->exec("DROP TABLE IF EXISTS bitrix_data");
        echo "✅ bitrix_data table dropped successfully\n";
    }
}
