<?php
// Configuration of the dev container (WA_CONFIG_FILE points here). Not for production.
return [
    'debug' => true,
    'database' => ['host' => 'db', 'name' => 'webapp', 'user' => 'webapp', 'password' => 'webapp'],
    'jwtSecret' => 'dev-only-secret-not-for-production',
    'routes' => [
        '*' => 'demo',
    ],
    'devPathSelect' => true,   // http://<host>:8090/<app>/...
    'apps' => [
        'demo' => ['folder' => 'demo', 'file' => 'App.php', 'class' => 'DemoApp', 'dbPrefix' => 'demo_', 'auth' => 'user'],
    ],
];
