<?php

class Config
{
    private static $config = [];
    private static $loaded = false;

    /**
     * @param string|null $envFile
     * @return void
     */
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

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config[$key] ?? $default;
    }
}
