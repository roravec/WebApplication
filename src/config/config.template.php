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
        // 'panel' => [
        //     'folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
        //     'shop' => ['prefix' => 'ps_', 'lang' => 'sk'],
        //     // Sending invoices by e-mail. Credentials stay here (not in the database).
        //     // transport: smtp (recommended, SMTP of the hosting / mailbox) or mail (PHP mail()).
        //     'mail' => ['transport' => 'smtp', 'host' => 'smtp.example.sk', 'port' => 465, 'secure' => 'ssl',
        //                'user' => 'faktury@erma.sk', 'password' => ''],
        //     // optional: URL /panel/cron/accounting?token=<cron_token> sends the monthly package for the accountant
        //     // without anybody opening the panel (hosting web cron / external cron service); min. 20 random chars
        //     'cron_token' => '',
        // ],
        // Club / league webs ported from StadiumControl (oldest core, run by WebApplication\Legacy\OldCoreApplication).
        // Both use the hbkpravotice_ tables; logins are in hbkpravotice_users ("remember me" is signed with jwtSecret).
        // 'hbkpravotice' => ['folder' => 'hbkpravotice', 'file' => 'App.php', 'class' => 'HbkpravoticeApp',
        //                    'dbPrefix' => 'hbkpravotice_', 'auth' => 'none'],
        // 'bnhl' => ['folder' => 'bnhl', 'file' => 'App.php', 'class' => 'BnhlApp', 'dbPrefix' => 'hbkpravotice_', 'auth' => 'none',
        //            'stadiumControlApi' => 'https://hbkpravotice.sk/StadiumControl'],   // optional, this is the default
    ],
];
