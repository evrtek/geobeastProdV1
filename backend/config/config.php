<?php
/**
 * GeoBeasts Configuration Loader
 * Loads environment variables and provides configuration access
 */

class Config {
    private static $config = [];
    private static $loaded = false;

    /**
     * Load configuration from .env file
     */
    public static function load($envPath = null) {
        if (self::$loaded) {
            return;
        }

        if ($envPath === null) {
            $envPath = __DIR__ . '/.env';
        }

        if (!file_exists($envPath)) {
            throw new Exception('.env file not found. Please copy .env.example to .env and configure.');
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Remove quotes if present
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }

                self::$config[$key] = $value;

                // Also set as environment variable
                if (!getenv($key)) {
                    putenv("$key=$value");
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Get configuration value
     */
    public static function get($key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        // Try config array first, then environment variable, then default
        if (isset(self::$config[$key])) {
            return self::$config[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        return $default;
    }

    /**
     * Get database configuration
     */
    public static function getDb() {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'port' => self::get('DB_PORT', '3306'),
            'name' => self::get('DB_NAME', 'geobeasts'),
            'user' => self::get('DB_USER', 'geobeasts_user'),
            'password' => self::get('DB_PASSWORD', 'DEADp00l07??'),
            'charset' => 'utf8mb4'
        ];
    }

    /**
     * Check if in production environment
     */
    public static function isProduction() {
        return self::get('APP_ENV', 'development') === 'production';
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isDebug() {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    /**
     * Get Revolut payment configuration
     */
    public static function getRevolut() {
        return [
            'api_key' => self::get('REVOLUT_API_KEY', ''),
            'api_url' => self::get('REVOLUT_API_URL', 'https://merchant.revolut.com/api/1.0'),
            'webhook_secret' => self::get('REVOLUT_WEBHOOK_SECRET', ''),
            'mode' => self::get('REVOLUT_MODE', 'sandbox') // 'sandbox' or 'production'
        ];
    }
}
