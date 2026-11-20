<?php
// Configuration of the dev container (WA_CONFIG_FILE points here). Not for production.
return [
    'debug' => true,
    'database' => ['host' => 'db', 'name' => 'webapp', 'user' => 'webapp', 'password' => 'webapp'],
    'jwtSecret' => 'dev-only-secret-not-for-production',
    'routes' => [
        '*' => 'erma',            // http://<host>:8090/        → erma.sk placeholder
        '*/panel' => 'panel',     // http://<host>:8090/panel   → employee section
        '*/demo' => 'demo',       // http://<host>:8090/demo    → framework demo app
        // ported club / league webs (no hosts-file edits: sslip.io resolves the name to the server's IP)
        'hbkpravotice.sk.192-168-0-11.sslip.io' => 'hbkpravotice',
        'bnhl.sk.192-168-0-11.sslip.io' => 'bnhl',
    ],
    'apps' => [
        'erma' => ['folder' => 'erma', 'file' => 'App.php', 'class' => 'ErmaApp', 'dbPrefix' => 'ermaweb_', 'auth' => 'none',
                   'panelUrl' => '/panel'],
        'panel' => ['folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
                    'shop' => ['prefix' => 'ps_', 'lang' => 'sk'],   // PrestaShop tables, imported by dev/import-shop.sh
                    'mail' => ['transport' => 'smtp', 'host' => 'mail', 'port' => 1025, 'secure' => '', 'user' => '', 'password' => '']],
        'demo' => ['folder' => 'demo', 'file' => 'App.php', 'class' => 'DemoApp', 'dbPrefix' => 'demo_', 'auth' => 'user'],
        'hbkpravotice' => ['folder' => 'hbkpravotice', 'file' => 'App.php', 'class' => 'HbkpravoticeApp', 'dbPrefix' => 'hbkpravotice_', 'auth' => 'none'],
        // bnhl reads games / players / teams from hbkpravotice's StadiumControl API — on dev from the dev copy, not production
        'bnhl' => ['folder' => 'bnhl', 'file' => 'App.php', 'class' => 'BnhlApp', 'dbPrefix' => 'hbkpravotice_', 'auth' => 'none',
                   'stadiumControlApi' => 'http://hbkpravotice.sk.192-168-0-11.sslip.io:8090/StadiumControl'],
    ],
];
