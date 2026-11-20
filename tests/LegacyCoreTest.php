<?php
// Apps of the OLDEST core (StadiumControl: SetInitVariables + GetHTML) run through WebApplication\Legacy\OldCoreApplication.
use WebApplication\Http\Request;
use WebApplication\Legacy\OldCoreApplication;

OldCoreApplication::loadOldCore();   // global User / ICRUD of the old core

function oldCoreDb()
{
    $db = testDb('t_');
    $db->pdo()->exec('DROP TABLE IF EXISTS t_users');
    $db->pdo()->exec('CREATE TABLE t_users (id INT AUTO_INCREMENT PRIMARY KEY, login VARCHAR(256) NOT NULL, password VARCHAR(256) NOT NULL,
        name VARCHAR(256) NOT NULL, rights INT NOT NULL DEFAULT 0)');
    $db->execute('INSERT INTO t_text (appid, language, identifier, text) VALUES (?, ?, ?, ?)', ['oldcore', 'en', 'TITLE', 'HBK']);
    return $db;
}

function oldCoreRequest($db, string $method, string $path, array $post = [], array $cookies = []): string
{
    $_POST = $post;
    $req = new Request($method, 'oldcore.test', $path, [], $post, [], [], '', $cookies);
    $app = testApp($req, $db, [
        'jwtSecret' => 'test-secret-long-enough-0123456789',
        'routes' => ['oldcore.test' => 'oldcore'],
        'apps' => ['oldcore' => ['folder' => 'oldcore', 'file' => 'App.php', 'class' => 'OldCoreFixtureApp', 'dbPrefix' => 't_', 'auth' => 'none']],
    ]);
    $res = $app->handle();
    eq(200, $res->status(), 'status for ' . $path);
    return $res->body();
}

test('old core: page names exactly as the old DetectPage/GetContentFilename, texts by appid, assets path, prefix', function () {
    $db = oldCoreDb();
    $_SESSION = [];
    eq('oldcore||HBK|/applications/oldcore/assets|t_|-', oldCoreRequest($db, 'GET', '/'), 'root = empty page name (as before)');
    eq('oldcore|5|HBK|/applications/oldcore/assets|t_|-', oldCoreRequest($db, 'GET', '/tim/5/'));
    eq('oldcore|overview|HBK|/applications/oldcore/assets|t_|-', oldCoreRequest($db, 'GET', '/images/logo.png'), 'a file name → default page');
});

test('old core: users of <prefix>users — login, wrong password, session, deleted user logged out', function () {
    $db = oldCoreDb();
    $_SESSION = [];
    $u = new User(new WebApplication\Database\LegacyDatabase($db), 't_');
    $u->login = 'Peter@Example.sk';
    $u->password = 'tajne-heslo';
    $u->name = "Peter O'Neil";
    $u->rights = 10;
    $u->Create();
    ok($u->id > 0, 'insert id');
    eq('oldcore|overview|HBK|/applications/oldcore/assets|t_|-',
        oldCoreRequest($db, 'POST', '/x.php', ['action' => 'login', 'email' => 'peter@example.sk', 'password' => 'zle']), 'wrong password');
    eq("oldcore|overview|HBK|/applications/oldcore/assets|t_|Peter O'Neil/10",
        oldCoreRequest($db, 'POST', '/x.php', ['action' => 'login', 'email' => 'peter@example.sk', 'password' => 'tajne-heslo']));
    eq("oldcore|team|HBK|/applications/oldcore/assets|t_|Peter O'Neil/10", oldCoreRequest($db, 'GET', '/team'), 'session keeps the login');
    $db->execute('UPDATE t_users SET rights = 3');
    eq("oldcore|team|HBK|/applications/oldcore/assets|t_|Peter O'Neil/3", oldCoreRequest($db, 'GET', '/team'), 'rights re-read every request');
    $db->execute('DELETE FROM t_users');
    eq('oldcore|team|HBK|/applications/oldcore/assets|t_|-', oldCoreRequest($db, 'GET', '/team'), 'deleted user is logged out');
});

test('old core: remember-me token — signed, per app, expires; old-core tokens are not accepted', function () {
    $secret = 'test-secret-long-enough-0123456789';
    $t = OldCoreApplication::rememberToken(7, 'oldcore', $secret, time() + 60);
    eq(7, OldCoreApplication::rememberedId($t, 'oldcore', $secret));
    eq(0, OldCoreApplication::rememberedId($t, 'bnhl', $secret), 'other app');
    eq(0, OldCoreApplication::rememberedId($t, 'oldcore', $secret . 'x'), 'other key');
    eq(0, OldCoreApplication::rememberedId(OldCoreApplication::rememberToken(7, 'oldcore', $secret, time() - 1), 'oldcore', $secret), 'expired');
    list($p, $s) = explode('.', $t);
    eq(0, OldCoreApplication::rememberedId(rtrim(strtr(base64_encode('{"u":1,"a":"oldcore","exp":9999999999}'), '+/', '-_'), '=') . '.' . $s, 'oldcore', $secret), 'tampered');
    eq(0, OldCoreApplication::rememberedId('eyJ0eXAiOiJKV1QifQ.eyJ1c2VySWQiOjF9.abc', 'oldcore', $secret), 'token of the old core (3 parts)');
    eq(0, OldCoreApplication::rememberedId($t, 'oldcore', 'short'), 'no usable secret');

    // a request with only the cookie (new browser session) is logged in
    $db = oldCoreDb();
    $_SESSION = [];
    $db->execute('INSERT INTO t_users (login, password, name, rights) VALUES (?, ?, ?, ?)', ['a', password_hash('b', PASSWORD_DEFAULT), 'Anna', 5]);
    $id = (int)$db->fetchValue('SELECT id FROM t_users');
    eq('oldcore|team|HBK|/applications/oldcore/assets|t_|Anna/5',
        oldCoreRequest($db, 'GET', '/team', [], ['auth_token' => OldCoreApplication::rememberToken($id, 'oldcore', $secret, time() + 60)]));
});

test('old core: User (ported) — escaping, case-insensitive login, password change in Update, ReadAll', function () {
    $db = oldCoreDb();
    $legacy = new WebApplication\Database\LegacyDatabase($db);
    $u = new User($legacy, 't_');
    $u->login = 'jan"; DROP TABLE t_users; --';
    $u->password = 'heslo-1';
    $u->name = 'Ján "Kapitán" O\'Brien';
    $u->rights = 255;
    $u->Create();
    eq(1, (int)$db->fetchValue('SELECT COUNT(*) FROM t_users'), 'table still there, one user');
    eq('Ján "Kapitán" O\'Brien', $db->fetchValue('SELECT name FROM t_users'));
    $l = new User($legacy, 't_');
    $l->Login('JAN"; DROP TABLE T_USERS; --', 'heslo-1');
    ok($l->GetLoginVerified(), 'case-insensitive login');
    // edit: new password + name (the old Update had no comma before rights and kept the old hash)
    $e = new User($legacy, 't_');
    $e->id = $u->id;
    $e->Read();
    $e->name = 'Ján';
    $e->password = 'heslo-2';
    $e->Update();
    $chk = new User($legacy, 't_');
    $chk->Login($u->login, 'heslo-1');
    ok(!$chk->GetLoginVerified(), 'old password no longer works');
    $chk = new User($legacy, 't_');
    $chk->Login($u->login, 'heslo-2');
    ok($chk->GetLoginVerified(), 'new password works');
    eq('Ján', $chk->GetName());
    // edit without a password keeps it
    $e2 = new User($legacy, 't_');
    $e2->id = $u->id;
    $e2->Read();
    $e2->rights = 10;
    $e2->Update();
    $chk = new User($legacy, 't_');
    $chk->Login($u->login, 'heslo-2');
    ok($chk->GetLoginVerified(), 'password kept');
    eq('10', (string)$chk->GetRightsLevel());
    eq(1, count(User::ReadAll($legacy, 't_')));
});
