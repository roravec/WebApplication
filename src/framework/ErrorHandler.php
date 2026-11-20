<?php
namespace WebApplication;

/**
 * Central error handling: everything goes to the log file; visitors get a plain error page.
 * Details are shown only when 'debug' => true in config (never enable on production).
 */
class ErrorHandler
{
    /** @var Logger */
    private $logger;
    /** @var bool */
    private $debug;
    /** @var bool */
    private static $registered = false;

    public function __construct(Logger $logger, bool $debug)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    public function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        ini_set('display_errors', $this->debug ? '1' : '0');
        ini_set('log_errors', '1');
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /** Warnings/notices are logged, not turned into exceptions (old app code produces many). */
    public function handleError(int $errno, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;   // silenced with @
        }
        // deprecation notices of third-party libraries (src/vendor) are not ours to fix — e.g. libraries that still
        // support PHP 7.3 trigger PHP 8.4 "implicitly nullable" notices; upgrade them when the hosting leaves 7.3
        if (($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) && defined('WA_ROOT')
            && strpos(str_replace('\\', '/', $file), str_replace('\\', '/', WA_ROOT) . '/vendor/') === 0) {
            return true;
        }
        $this->logger->warning($message, ['file' => $file, 'line' => $line, 'type' => $errno]);
        return !$this->debug;   // in debug mode let PHP display it as well
    }

    public function handleException(\Throwable $e): void
    {
        $this->logger->error(get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $this->shortTrace($e),
        ]);
        $this->renderError(500, $e);
    }

    public function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->logger->error('Fatal: ' . $err['message'], ['file' => $err['file'], 'line' => $err['line']]);
            $this->renderError(500, null, $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        }
    }

    public function renderError(int $status, ?\Throwable $e = null, string $detail = ''): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, ($e ? $e->getMessage() . "\n" . $e->getTraceAsString() : $detail) . "\n");
            return;
        }
        $info = '';
        if ($this->debug) {
            $text = $e ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : $detail;
            $info = '<pre style="white-space:pre-wrap;text-align:left">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</pre>';
        }
        echo self::page($status, $status === 404 ? 'Page not found' : 'Something went wrong', $info);
    }

    /** Minimal standalone error page (works without any app). */
    public static function page(int $status, string $title, string $extraHtml = ''): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $status . ' ' . $t . '</title><style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem;color:#222}'
            . 'h1{font-size:1.4rem}</style></head><body><h1>' . $status . ' – ' . $t . '</h1>' . $extraHtml . '</body></html>';
    }

    private function shortTrace(\Throwable $e): array
    {
        $out = [];
        foreach (array_slice($e->getTrace(), 0, 8) as $f) {
            $out[] = ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
        }
        return $out;
    }
}
