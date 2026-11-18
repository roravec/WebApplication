<?php
/**
 * Copy to config.php and fill in. config.php is git-ignored and must never be committed.
 * (Servers with the old ApplicationConfig.php / DatabaseConfig.php / JwtSecret.php still work.)
 */
return [
    // Show error details in the browser. NEVER true on production — errors always go to var/log/.
    'debug' => false,

    'timezone' => 'Europe/Bratislava',

    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
    ],

    // Long random string, e.g. output of: php -r "echo bin2hex(random_bytes(32));"
    'jwtSecret' => '',

    // Which app answers which domain (and optionally path). Longest match wins, '*' = default.
    'routes' => [
        'erma.sk' => 'erma',
        'www.erma.sk' => 'erma',
        // 'erma.sk/panel'     => 'panel',
        // 'hbkpravotice.sk'   => 'hbkpravotice',
        // 'www.hbkpravotice.sk' => 'hbkpravotice',
        // 'bnhl.sk'           => 'bnhl',
        // 'www.bnhl.sk'       => 'bnhl',
    ],

    // Development only: http://localhost:8080/<app>/... selects the app by the first path segment.
    'devPathSelect' => false,

    // Write API logins into <prefix>log (no tokens/secrets are stored).
    'logApiAuth' => false,

    'apps' => [
        'erma' => [
            'folder' => 'erma',          // applications/<folder>/
            'file' => 'WebApp.php',      // file with the app class
            'class' => 'WebApp',         // implements IWebApp or WebApplication\Http\RequestHandler
            'dbPrefix' => 'ermaweb_',    // table prefix in the shared database
            'auth' => 'none',            // none | user (browser login) | api (device/API tokens)
        ],
    ],
];
