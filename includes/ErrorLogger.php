<?php
// includes/ErrorLogger.php — centralized error logging with file rotation

class ErrorLogger {
    private const MAX_BYTES = 5242880; // 5 MB
    private const MAX_ROTATED = 5;

    private static bool $registered = false;

    public static function register(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $enabled = defined('LOG_ERRORS') ? LOG_ERRORS : (getenv('APP_ENV') === 'production');
        if (!$enabled) {
            return;
        }

        if (defined('APP_ENV') && APP_ENV === 'production') {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        }

        ini_set('log_errors', '1');

        $logFile = self::logFilePath();
        if ($logFile !== null) {
            ini_set('error_log', $logFile);
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function logFilePath(): ?string {
        $dir = defined('LOG_PATH') && LOG_PATH !== ''
            ? rtrim(LOG_PATH, '/\\')
            : (defined('ROOT_PATH') ? ROOT_PATH . '/logs' : dirname(__DIR__) . '/logs');

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return null;
            }
        }

        $file = $dir . '/app.log';
        self::rotateIfNeeded($file);
        return $file;
    }

    public static function write(string $level, string $message, array $context = []): void {
        $file = self::logFilePath();
        if ($file === null) {
            error_log("[{$level}] {$message}");
            return;
        }

        $line = sprintf(
            "[%s] %s %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        if (in_array($level, ['fatal', 'exception'], true)) {
            self::writeLastError($level, $message, $context);
        }
    }

    public static function writeLastError(string $level, string $message, array $context = []): void {
        $dir = defined('LOG_PATH') && LOG_PATH !== ''
            ? rtrim(LOG_PATH, '/\\')
            : (defined('ROOT_PATH') ? ROOT_PATH . '/logs' : dirname(__DIR__) . '/logs');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $payload = [
            'at' => date('c'),
            'level' => $level,
            'message' => $message,
            'file' => $context['file'] ?? null,
            'line' => $context['line'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ];
        @file_put_contents($dir . '/last_error.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public static function readLastError(): ?array {
        $dir = defined('LOG_PATH') && LOG_PATH !== ''
            ? rtrim(LOG_PATH, '/\\')
            : (defined('ROOT_PATH') ? ROOT_PATH . '/logs' : dirname(__DIR__) . '/logs');
        $path = $dir . '/last_error.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        self::write('error', "{$message} in {$file}:{$line}", ['severity' => $severity]);
        return true;
    }

    public static function handleException(Throwable $e): void {
        self::write('exception', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            http_response_code(500);
            $detail = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8"><title>ข้อผิดพลาด</title></head><body style="font-family:sans-serif;text-align:center;padding:48px;">';
            echo '<h1>เกิดข้อผิดพลาด</h1><p>กรุณาลองใหม่อีกครั้งในภายหลัง</p>';
            echo '<pre style="text-align:left;max-width:900px;margin:24px auto;padding:16px;background:#111;color:#f88;white-space:pre-wrap;font:13px/1.4 Consolas,monospace;">'
                . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</pre>';
            echo '</body></html>';
        }
        exit(1);
    }

    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatal, true)) {
            return;
        }
        self::write('fatal', $error['message'], [
            'file' => $error['file'],
            'line' => $error['line'],
            'type' => $error['type'],
        ]);

        // Avoid silent white screen of death — surface the fatal when possible.
        if (php_sapi_name() === 'cli') {
            return;
        }
        if (!empty($GLOBALS['flixo_wsod_shown'])) {
            return;
        }
        $GLOBALS['flixo_wsod_shown'] = true;
        $msg = $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
        if (!headers_sent()) {
            http_response_code(500);
        }
        // Surface the fatal so we can diagnose production WSOD (remove detail after fix).
        echo '<div style="font-family:sans-serif;text-align:center;padding:48px;"><h1>เกิดข้อผิดพลาด</h1><p>กรุณาลองใหม่อีกครั้งในภายหลัง</p>';
        echo '<pre style="text-align:left;max-width:900px;margin:24px auto;padding:16px;background:#111;color:#f88;white-space:pre-wrap;font:13px/1.4 Consolas,monospace;">'
            . htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre></div>';
    }

    private static function rotateIfNeeded(string $file): void {
        if (!is_file($file) || filesize($file) < self::MAX_BYTES) {
            return;
        }

        $oldest = $file . '.' . self::MAX_ROTATED;
        if (is_file($oldest)) {
            @unlink($oldest);
        }
        for ($i = self::MAX_ROTATED - 1; $i >= 1; $i--) {
            $src = $file . '.' . $i;
            $dst = $file . '.' . ($i + 1);
            if (is_file($src)) {
                @rename($src, $dst);
            }
        }
        @rename($file, $file . '.1');
    }
}
