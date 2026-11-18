<?php
use WebApplication\Application;
use WebApplication\Config;
use WebApplication\Database\LegacyDatabase;
use WebApplication\Entity\Client;
use WebApplication\Entity\LogEntry;
use WebApplication\Entity\Token;
use WebApplication\Http\Request;
use WebApplication\Logger;

function makeClient($db, string $identifier, string $password, int $status = 1): Client
{
    $c = new Client($db);
    $c->identifier = $identifier;
    $c->secret = $password;
    $c->name = ucfirst($identifier);
    $c->rights = 5;
    $c->status = $status;
    ok($c->create(), 'client created');
    return $c;
}

function testApp(Request $request, $db, array $cfg = []): Application
{
    $config = new Config(array_replace_recursive([
        'jwtSecret' => 'test-secret',
        'applicationsDir' => __DIR__ . '/fixtures/applications',
        'routes' => ['legacy.test' => 'legacy', 'demo.test' => 'demo'],
        'apps' => [
            'legacy' => ['folder' => 'legacy', 'dbPrefix' => 't_'],
            'demo' => ['folder' => '../../../src/applications/demo', 'file' => 'App.php', 'class' => 'DemoApp', 'dbPrefix' => 't_', 'auth' => 'user'],
        ],
    ], $cfg));
    $app = new Application($config, $request, new Logger(sys_get_temp_dir() . '/wa_test_log'));
    $app->setDatabase($db);
    return $app;
}

// ---------------------------------------------------------------- entities
test('client: create, login, wrong password, case-insensitive identifier', function () {
    $db = testDb();
    $c = makeClient($db, 'anna', 'pa$$word');
    ok($c->getId() > 0);
    $hash = $db->fetchValue('SELECT secret_hash FROM ' . $db->table('client') . ' WHERE id = ?', [$c->getId()]);
    ok(password_verify('pa$$word', $hash), 'stored as hash');
    ok(Client::readByIdentifierAndSecret($db, 'ANNA', 'pa$$word') !== null);
    eq(null, Client::readByIdentifierAndSecret($db, 'anna', 'wrong'));
    $x = new Client($db);
    ok($x->login('anna', 'pa$$word'));
    ok($x->isActive());
    eq(5, $x->getRights());
});

test('client: update keeps password unless a new one is set; readAll with params', function () {
    $db = testDb();
    $c = makeClient($db, 'bob', 'one');
    makeClient($db, 'cyril', 'x', 0);
    $c->name = 'Bob B';
    ok($c->update());
    ok(Client::readByIdentifierAndSecret($db, 'bob', 'one') !== null, 'password unchanged');
    $c->secret = 'two';
    ok($c->update());
    ok(Client::readByIdentifierAndSecret($db, 'bob', 'two') !== null, 'password changed');
    eq(1, count(Client::readAll($db, 'WHERE status = ?', [1])));
    eq('Bob B', Client::readById($db, $c->getId())->getName());
});

test('token: refresh token valid, revoke, purge', function () {
    $db = testDb();
    $c = makeClient($db, 'dev1', 's');
    $value = $c->generateRefreshToken();
    $t = Token::validateRefreshToken($db, $value);
    ok($t !== null);
    eq($c->getId(), $t->userid);
    ok(Token::revokeByValue($db, $value, $c->getId()));
    eq(null, Token::validateRefreshToken($db, $value));
    eq(1, Token::purge($db));
});

test('log entry: create and read back', function () {
    $db = testDb();
    $l = new LogEntry($db);
    $l->action = 'test';
    $l->message = 'hello';
    ok($l->create());
    $all = LogEntry::readAll($db);
    eq('test', $all[0]->action);
});

// ---------------------------------------------------------------- legacy database API
test('legacy db: Query/fetch_assoc/num_rows, EscapeString, strings like mysqli, insert id, errors', function () {
    $db = testDb();
    $legacy = new LegacyDatabase($db);
    ok($legacy->Query("INSERT INTO t_client (identifier, secret_hash, name) VALUES ('o', 'x', '" . $legacy->EscapeString("Mr O'Brien") . "')") === true);
    ok($legacy->GetLastInsertID() > 0);
    $res = $legacy->Query("SELECT id, name, rights FROM t_client");
    eq(1, $legacy->GetNumRows($res));
    $row = $res->fetch_assoc();
    eq("Mr O'Brien", $row['name']);
    eq('string', gettype($row['id']), 'values are strings like mysqli');
    eq(null, $res->fetch_assoc());
    eq(false, $legacy->Query('SELECT nonsense FROM nowhere'));
    ok($legacy->error !== '');
    // modern queries still return native types afterwards
    eq('integer', gettype($db->fetchValue('SELECT id FROM t_client LIMIT 1')));
});

test('database: transaction rolls back on exception', function () {
    $db = testDb();
    throws(function () use ($db) {
        $db->transaction(function ($db) {
            $db->execute('INSERT INTO ' . $db->table('log') . ' (timestamp, action) VALUES (NOW(), ?)', ['x']);
            throw new RuntimeException('fail');
        });
    }, RuntimeException::class);
    eq(0, (int)$db->fetchValue('SELECT COUNT(*) FROM ' . $db->table('log')));
    throws(function () use ($db) { $db->table('x; DROP'); }, InvalidArgumentException::class);
});

// ---------------------------------------------------------------- whole request through Application
test('app: legacy IWebApp — texts cached, echo kept, name injected', function () {
    $db = testDb();
    $db->execute('INSERT INTO t_text (appid, language, identifier, text) VALUES (?, ?, ?, ?)', ['legacy', 'sk', 'TITLE', 'Ahoj']);
    $app = testApp(new Request('GET', 'legacy.test', '/'), $db);
    $res = $app->handle();
    eq(200, $res->status());
    eq('echoed+Ahoj|legacy', $res->body());
    eq('UNKNOWN', $app->getTextFromDatabase('sk', 'UNKNOWN'), 'missing text falls back to identifier');
});

test('app: legacy status codes (http_response_code and response())', function () {
    $db = testDb();
    $res = testApp(new Request('DELETE', 'legacy.test', '/'), $db)->handle();
    eq(405, $res->status());
    eq('no DELETE', $res->body());
    $res = testApp(new Request('POST', 'legacy.test', '/'), $db)->handle();
    eq('posted', $res->body());
});

test('app: legacy app reads data through the compatibility layer', function () {
    $db = testDb();
    makeClient($db, 'x', 'y');
    $db->execute('UPDATE t_client SET name = ?', ["Mr O'Brien"]);
    $res = testApp(new Request('GET', 'legacy.test', '/items'), $db)->handle();
    eq("string:Mr O'Brien|1", $res->body());
});

test('app: unknown host gives 404 page', function () {
    $res = testApp(new Request('GET', 'nothing.test', '/'), testDb())->handle();
    eq(404, $res->status());
    contains('Page not found', $res->body());
});

test('app: demo (RequestHandler) — escaping, JSON, login with CSRF, logout', function () {
    $db = testDb();
    makeClient($db, 'eva', 'secret1');
    $_SESSION = [];

    $res = testApp(new Request('GET', 'demo.test', '/hello/<b>x&"'), $db)->handle();
    contains('Hello, &lt;b&gt;x&amp;&quot;!', $res->body());

    $res = testApp(new Request('GET', 'demo.test', '/api/status'), $db)->handle();
    eq(false, json_decode($res->body(), true)['loggedIn']);

    // without CSRF token → rejected
    $res = testApp(new Request('POST', 'demo.test', '/login', [], ['login' => 'eva', 'password' => 'secret1']), $db)->handle();
    eq(400, $res->status());

    $token = WebApplication\Csrf::token();
    $res = testApp(new Request('POST', 'demo.test', '/login', [], ['login' => 'eva', 'password' => 'bad', '_csrf' => $token]), $db)->handle();
    eq(401, $res->status());
    eq($token, $_SESSION['_csrf'] ?? null, 'failed login keeps the CSRF token');

    $res = testApp(new Request('POST', 'demo.test', '/login', [], ['login' => 'eva', 'password' => 'secret1', '_csrf' => $token]), $db)->handle();
    eq(302, $res->status());
    eq('/', $res->header('Location'));

    $res = testApp(new Request('GET', 'demo.test', '/'), $db)->handle();
    contains('Logged in', $res->body(), 'flash message');
    contains('Eva', $res->body(), 'user name shown');

    // deactivated user is logged out on the next request
    $db->execute('UPDATE t_client SET status = 0');
    $res = testApp(new Request('GET', 'demo.test', '/api/status'), $db)->handle();
    eq(false, json_decode($res->body(), true)['loggedIn']);
    $_SESSION = [];
});

test('app: user session of one app is not valid in another app', function () {
    $db = testDb();
    $c = makeClient($db, 'fero', 'pw');
    $_SESSION = ['wa_user' => ['id' => $c->getId(), 'app' => 'otherapp']];
    $res = testApp(new Request('GET', 'demo.test', '/api/status'), $db)->handle();
    eq(false, json_decode($res->body(), true)['loggedIn']);
    $_SESSION = [];
});
