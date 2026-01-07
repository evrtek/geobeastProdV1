<?php
/**
 * Logger Utility
 * Handles application logging with different severity levels
 */

require_once __DIR__ . '/../config/config.php';

class Logger {
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';

    private static $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
        'CRITICAL' => 4
    ];

    private static $logFile = null;
    private static $minLevel = null;

    /**
     * Initialize logger
     */
    private static function init() {
        if (self::$logFile === null) {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/app_' . date('Y-m-d') . '.log';
        }

        if (self::$minLevel === null) {
            $configLevel = strtoupper(Config::get('LOG_LEVEL', 'DEBUG'));
            self::$minLevel = self::$levels[$configLevel] ?? 0;
        }
    }

    /**
     * Log a message
     */
    public static function log($level, $message, $context = []) {
        self::init();

        $levelValue = self::$levels[$level] ?? 0;
        if ($levelValue < self::$minLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextString = !empty($context) ? ' ' . json_encode($context) : '';
        $logLine = "[$timestamp] [$level] $message$contextString" . PHP_EOL;

        // Write to file
        file_put_contents(self::$logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Also write to PHP error log for critical errors
        if ($levelValue >= self::$levels['ERROR']) {
            error_log("[$level] $message$contextString");
        }
    }

    /**
     * Log debug message
     */
    public static function debug($message, $context = []) {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log info message
     */
    public static function info($message, $context = []) {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message
     */
    public static function warning($message, $context = []) {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message
     */
    public static function error($message, $context = []) {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log critical message
     */
    public static function critical($message, $context = []) {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Log API request
     */
    public static function logRequest($endpoint, $method, $userId = null) {
        self::info("API Request: $method $endpoint", [
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }

    /**
     * Log exception
     */
    public static function logException(Exception $e, $context = []) {
        self::error($e->getMessage(), array_merge($context, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]));
    }

    /**
     * Log security event
     */
    public static function logSecurity($event, $details = []) {
        self::warning("SECURITY: $event", array_merge($details, [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => time()
        ]));
    }

    /**
     * Log database query (for debugging)
     */
    public static function logQuery($query, $params = [], $executionTime = null) {
        if (Config::isDebug()) {
            self::debug("SQL Query: $query", [
                'params' => $params,
                'execution_time_ms' => $executionTime
            ]);
        }
    }

    /**
     * Get recent log entries
     */
    public static function getRecentLogs($lines = 100) {
        self::init();

        if (!file_exists(self::$logFile)) {
            return [];
        }

        $file = file(self::$logFile);
        return array_slice($file, -$lines);
    }

    /**
     * Clean old log files (older than 30 days)
     */
    public static function cleanOldLogs($daysToKeep = 30) {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            return;
        }

        $files = glob($logDir . '/app_*.log');
        $cutoff = time() - ($daysToKeep * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }
}
