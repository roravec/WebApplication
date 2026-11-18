<?php
/** Dev only: create framework tables for every configured app and a user admin/admin. */
require '/var/www/html/bootstrap.php';
$config = WebApplication\Config::load();
$c = $config->get('database');
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = preg_replace('/^--.*$/m', '', file_get_contents('/sql/schema.sql'));
foreach ($config->get('apps') as $name => $app) {
    foreach (array_filter(array_map('trim', explode(';', str_replace('{prefix}', $app['dbPrefix'], $schema)))) as $stmt) {
        $pdo->exec($stmt);
    }
    $db = WebApplication\Database\Database::fromPdo($pdo, $app['dbPrefix']);
    if (WebApplication\Entity\Client::readByIdentifier($db, 'admin') === null) {
        $u = new WebApplication\Entity\Client($db);
        $u->identifier = 'admin'; $u->secret = 'admin'; $u->name = 'Admin'; $u->rights = 100; $u->status = 1;
        $u->create();
    }
    echo "$name: tables {$app['dbPrefix']}* ready, user admin/admin\n";
}
