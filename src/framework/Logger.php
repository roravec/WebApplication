<?php
namespace WebApplication;

/** Writes one line per message into var/log/app-YYYY-MM-DD.log.php (falls back to PHP's error_log). */
class Logger
{
    /** @var string */
    private $dir;
    /** @var string */
    private $channel;

    public function __construct(string $dir = WA_VAR . '/log', string $channel = 'app')
    {
        $this->dir = $dir;
        $this->channel = $channel;
    }

    public function withChannel(string $channel): self
    {
        return new self($this->dir, $channel);
    }

    public function error(string $message, array $context = []): void { $this->log('ERROR', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log('WARNING', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('INFO', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf("[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            $this->channel,
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        // .php + exit guard: even if the web server ignores .htaccess, the log can't be downloaded
        $file = $this->dir . '/app-' . date('Y-m-d') . '.log.php';
        if (!is_file($file)) {
            $line = "<?php http_response_code(404); exit; ?>\n" . $line;
        }
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
