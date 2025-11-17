<?php

/**
 * Конфигурация базы данных для ErrorTracker
 */

require_once 'Config.php';

class DatabaseConfig
{
    private static $config = null;
    
    private static function loadConfig()
    {
        if (self::$config === null) {
            Config::load();
            self::$config = [
                'host' => Config::get('DB_HOST', 'localhost'),
                'name' => Config::get('DB_NAME', 'alma'),
                'user' => Config::get('DB_USER', 'alma_user'),
                'pass' => Config::get('DB_PASS', 'alma_password123'),
                'charset' => Config::get('DB_CHARSET', 'utf8mb4')
            ];
        }
        return self::$config;
    }
    
    public static function getDSN()
    {
        $config = self::loadConfig();
        return "mysql:host=" . $config['host'] . ";dbname=" . $config['name'] . ";charset=" . $config['charset'];
    }
    
    public static function getOptions()
    {
        $config = self::loadConfig();
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $config['charset']
        ];
    }
    
    public static function getHost()
    {
        return self::loadConfig()['host'];
    }
    
    public static function getName()
    {
        return self::loadConfig()['name'];
    }
    
    public static function getUser()
    {
        return self::loadConfig()['user'];
    }
    
    public static function getPass()
    {
        return self::loadConfig()['pass'];
    }
    
    public static function getCharset()
    {
        return self::loadConfig()['charset'];
    }
}
