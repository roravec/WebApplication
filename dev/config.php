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
    ],
    'apps' => [
        'erma' => ['folder' => 'erma', 'file' => 'App.php', 'class' => 'ErmaApp', 'dbPrefix' => 'ermaweb_', 'auth' => 'none',
                   'panelUrl' => '/panel'],
        'panel' => ['folder' => 'panel', 'file' => 'App.php', 'class' => 'PanelApp', 'dbPrefix' => 'panel_', 'auth' => 'user',
                    'shop' => ['prefix' => 'ps_', 'lang' => 'sk']],   // PrestaShop tables, imported by dev/import-shop.sh
        'demo' => ['folder' => 'demo', 'file' => 'App.php', 'class' => 'DemoApp', 'dbPrefix' => 'demo_', 'auth' => 'user'],
    ],
];
