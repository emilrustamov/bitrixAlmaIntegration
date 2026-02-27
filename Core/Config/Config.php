<?php

class Config
{
    private static $config = [];
    private static $loaded = false;

    public static function load($envFile = null)
    {
        if (self::$loaded) {
            return;
        }

        $envFile = $envFile ?: __DIR__ . '/../../config.env';

        if (!file_exists($envFile)) {
            throw new Exception("Environment file not found: $envFile");
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                self::$config[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config[$key] ?? $default;
    }

    public static function getAll()
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config;
    }

    public static function has($key)
    {
        if (!self::$loaded) {
            self::load();
        }
        return isset(self::$config[$key]);
    }
}


