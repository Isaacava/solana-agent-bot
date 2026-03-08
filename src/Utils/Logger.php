<?php
namespace SolanaAgent\Utils;

use SolanaAgent\Storage\Database;

class Logger
{
    private static bool $dbReady = false;

    public static function info(string $msg, array $ctx = []): void  { self::write('info',  $msg, $ctx); }
    public static function warn(string $msg, array $ctx = []): void  { self::write('warn',  $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::write('error', $msg, $ctx); }
    public static function debug(string $msg, array $ctx = []): void { self::write('debug', $msg, $ctx); }

    private static function write(string $level, string $msg, array $ctx): void
    {
        $logDir = defined('LOG_PATH') ? LOG_PATH : sys_get_temp_dir();
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $msg,
            empty($ctx) ? '' : json_encode($ctx)
        );

        // File log
        @file_put_contents($logDir . '/bot-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

        // DB log (best effort)
        try {
            Database::getInstance()->query(
                'INSERT INTO bot_logs (level, message, context) VALUES (?,?,?)',
                [$level, $msg, empty($ctx) ? null : json_encode($ctx)]
            );
        } catch (\Throwable $ignored) {
            // silently ignore if DB not ready
        }
    }
}
