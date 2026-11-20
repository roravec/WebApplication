<?php
/**
 * Framework bootstrap: class autoloading + the global IWebApp interface.
 * Included by index.php (web) and tests/run.php (CLI). Compatible with PHP 7.3 – 8.x.
 */

define('WA_ROOT', __DIR__);                       // deployable root (= web root on the hosting)
define('WA_FRAMEWORK', __DIR__ . '/framework');   // framework classes, namespace WebApplication\
define('WA_APPLICATIONS', __DIR__ . '/applications');
define('WA_CONFIG', __DIR__ . '/config');
define('WA_VAR', __DIR__ . '/var');               // logs, cache (not web-accessible)

spl_autoload_register(function ($class) {
    $prefix = 'WebApplication\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = WA_FRAMEWORK . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Composer libraries (composer.json → src/vendor, e.g. dompdf for invoice PDFs), when installed.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Apps implement the global IWebApp interface (kept global for backward compatibility).
require_once WA_FRAMEWORK . '/IWebApp.php';
