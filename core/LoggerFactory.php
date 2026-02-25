<?php
/**
 * Logger Factory
 * 
 * Creates and configures Monolog logger instances
 */

declare(strict_types=1);

namespace PsyTest\Core;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

class LoggerFactory
{
    private static array $loggers = [];
    
    /**
     * Get a logger instance
     * 
     * @param string $channel Logger channel name
     * @param string|null $logPath Path to logs directory
     * @param string $level Minimum log level
     * @return Logger Configured logger
     */
    public static function getLogger(
        string $channel = 'app',
        ?string $logPath = null,
        string $level = 'info'
    ): Logger {
        if (isset(self::$loggers[$channel])) {
            return self::$loggers[$channel];
        }
        
        if ($logPath === null) {
            $configLoader = require __DIR__ . '/../config.php';
            $logPath = $configLoader->logPath();
        }
        
        // Create logs directory if it doesn't exist
        if (!is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
        
        $logger = new Logger($channel);
        
        // Rotating file handler (keeps last 7 days of logs)
        $fileHandler = new RotatingFileHandler(
            $logPath . '/' . $channel . '.log',
            7,
            self::parseLevel($level)
        );
        
        // Format: [datetime] channel.LEVEL: message [context]
        $fileHandler->setFormatter(new \Monolog\Formatter\LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,
            true
        ));
        
        $logger->pushHandler($fileHandler);
        
        // Add console handler in debug mode
        $configLoader = require __DIR__ . '/../config.php';
        if ($configLoader->isDebug()) {
            $consoleHandler = new StreamHandler('php://stdout', self::parseLevel($level));
            $consoleHandler->setFormatter(new \Monolog\Formatter\LineFormatter(
                "%datetime% [%level_name%] %message%\n",
                'Y-m-d H:i:s',
                true,
                true
            ));
            $logger->pushHandler($consoleHandler);
        }
        
        self::$loggers[$channel] = $logger;
        
        return $logger;
    }
    
    /**
     * Parse log level string to Monolog Level enum
     */
    private static function parseLevel(string $level): Level
    {
        return match (strtolower($level)) {
            'debug' => Level::Debug,
            'info' => Level::Info,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            'alert' => Level::Alert,
            'emergency' => Level::Emergency,
            default => Level::Info,
        };
    }
}
