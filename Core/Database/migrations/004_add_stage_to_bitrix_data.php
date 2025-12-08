<?php

require_once(__DIR__ . '/../Config.php');
require_once(__DIR__ . '/../Logger.php');

Config::load();

class AddStageToBitrixDataMigration {
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
        echo "📋 Adding stage field to bitrix_data table...\n";
        
        $sql = "
            ALTER TABLE bitrix_data 
            ADD COLUMN bitrix_stage VARCHAR(50) NULL AFTER bitrix_rent_type,
            ADD INDEX idx_bitrix_stage (bitrix_stage)
        ";
        
        $this->pdo->exec($sql);
        echo "✅ Stage field added successfully\n";
    }
    
    public function down() {
        echo "📋 Removing stage field from bitrix_data table...\n";
        $this->pdo->exec("ALTER TABLE bitrix_data DROP COLUMN bitrix_stage, DROP INDEX idx_bitrix_stage");
        echo "✅ Stage field removed successfully\n";
    }
}
