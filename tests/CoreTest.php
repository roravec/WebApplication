<?php
use WebApplication\AppResolver;
use WebApplication\Auth\Jwt;
use WebApplication\Config;
use WebApplication\Http\Request;
use WebApplication\Http\Response;
use WebApplication\Http\Router;
use WebApplication\View;

function req(string $host, string $path = '/', string $method = 'GET', array $headers = [], array $post = [], string $body = ''): Request
{
    return new Request($method, $host, $path, [], $post, $headers, [], $body);
}

function resolverConfig(array $extra = []): Config
{
    return new Config(array_replace([
        'routes' => [
            'erma.sk' => 'erma',
            'www.erma.sk' => 'erma',
            'erma.sk/panel' => 'panel',
            'localhost' => 'erma',
        ],
        'apps' => [
            'erma' => ['dbPrefix' => 'ermaweb_'],
            'panel' => ['dbPrefix' => 'panel_', 'auth' => 'user'],
            'bnhl' => ['dbPrefix' => 'hbkpravotice_'],
        ],
    ], $extra));
}

// ---------------------------------------------------------------- app selection
test('request: several uploaded files (name[] multiple), one file, empty slots', function () {
    $multi = ['name' => ['a.txt', '', 'b.pdf'], 'type' => ['text/plain', '', 'application/pdf'], 'tmp_name' => ['/tmp/a', '', '/tmp/b'],
        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE], 'size' => [3, 0, 0]];
    $r = new Request('POST', 'x', '/', [], [], [], [], '', [], ['files' => $multi, 'one' => ['name' => 'c', 'type' => '', 'tmp_name' => '/tmp/c',
        'error' => UPLOAD_ERR_OK, 'size' => 1]]);
    eq(['a.txt', 'b.pdf'], array_column($r->files('files'), 'name'));
    eq(UPLOAD_ERR_INI_SIZE, $r->files('files')[1]['error']);
    eq(['c'], array_column($r->files('one'), 'name'));
    eq([], $r->files('none'));
    eq(null, $r->file('files'), 'file() is for a single input');
});

test('request: path from the request URI (also "type:id" segments that parse_url() rejects), query and fragment cut', function () {
    eq('/panel/r/invoice:7', Request::pathFromUri('/panel/r/invoice:7'));
    eq('/panel/r/invoice:7/qr', Request::pathFromUri('/panel/r/invoice:7/qr?x=1#a'));
    eq('/panel/r/order:0:2481', Request::pathFromUri('/panel/r/order:0:2481'));
    eq('/', Request::pathFromUri(''));
    eq('/', Request::pathFromUri('?a=1'));
    eq('/a/b', Request::pathFromUri('https://erma.sk/a/b?c=d'));
    eq('/', Request::pathFromUri('https://erma.sk'));
    // the router matches such a segment
    $r = new Router();
    $r->get('/r/{ref:[a-z][a-z_]+(?::\d+)+}', function ($req, $p) { return Response::text($p['ref']); });
    eq('invoice:7', $r->dispatch(req('x', '/r/invoice:7'))->body());
});

test('resolver: domain and www. domain map to the same app', function () {
    $r = new AppResolver(resolverConfig());
    eq('erma', $r->resolve(req('erma.sk'))->name);
    eq('erma', $r->resolve(req('WWW.Erma.sk:443', '/about'))->name);
});

test('resolver: path-mounted app wins (longest match) and gets basePath', function () {
    $r = new AppResolver(resolverConfig());
    $d = $r->resolve(req('erma.sk', '/panel/invoices/5'));
    eq('panel', $d->name);
    eq('/panel', $d->basePath);
    eq('user', $d->auth);
    eq('erma', $r->resolve(req('erma.sk', '/panelx'))->name, 'prefix must end at a segment');
});

test('resolver: legacy fallback uses first host label without www.', function () {
    $r = new AppResolver(resolverConfig());
    eq('bnhl', $r->resolve(req('www.bnhl.sk'))->name);
    eq(null, $r->resolve(req('unknown.example')));
});

test('resolver: devPathSelect picks app from first segment', function () {
    $r = new AppResolver(resolverConfig(['routes' => [], 'devPathSelect' => true]));
    $d = $r->resolve(req('localhost', '/bnhl/matches'));
    eq('bnhl', $d->name);
    eq('/bnhl', $d->basePath);
});

test('resolver: * is the default app', function () {
    $r = new AppResolver(resolverConfig(['routes' => ['*' => 'bnhl', 'erma.sk' => 'erma']]));
    eq('bnhl', $r->resolve(req('anything.sk'))->name);
    eq('erma', $r->resolve(req('erma.sk'))->name);
});

test('resolver: */path matches any host; more specific path wins; exact host beats *', function () {
    $r = new AppResolver(resolverConfig(['routes' => ['erma.sk' => 'erma', '*/panel' => 'panel', '*' => 'bnhl', 'other.sk/panel' => 'erma']]));
    eq('panel', $r->resolve(req('erma.sk', '/panel/orders'))->name);
    eq('/panel', $r->resolve(req('anything.test', '/panel'))->basePath);
    eq('erma', $r->resolve(req('erma.sk', '/about'))->name);
    eq('erma', $r->resolve(req('other.sk', '/panel'))->name, 'exact host with same path beats */panel');
    eq('bnhl', $r->resolve(req('x.sk', '/'))->name);
});

// ---------------------------------------------------------------- request
test('request: path normalisation, segments and app-relative path', function () {
    $r = req('erma.sk', '//panel//invoices/5/')->withBasePath('/panel');
    eq('/panel/invoices/5', $r->path());
    eq(['panel', 'invoices', '5'], $r->segments());
    eq('/invoices/5', $r->appPath());
    eq(['invoices', '5'], $r->appSegments());
    eq('/', req('erma.sk', '/panel')->withBasePath('/panel')->appPath());
});

test('request: headers are case-insensitive, bearer token, https behind proxy', function () {
    $r = new Request('GET', 'x', '/', [], [], ['Authorization' => 'Bearer abc.def', 'X-Forwarded-Proto' => 'https'], []);
    eq('Bearer abc.def', $r->header('AUTHORIZATION'));
    eq('abc.def', $r->bearerToken());
    ok($r->isHttps());
    ok(!req('x')->isHttps());
});

test('request: JSON body and input()', function () {
    $r = req('x', '/', 'POST', ['Content-Type' => 'application/json'], ['a' => 'form'], '{"a":"json","n":5}');
    eq('json', $r->input('a'));
    eq(5, $r->input('n'));
    eq('d', $r->input('missing', 'd'));
});

// ---------------------------------------------------------------- router
test('router: parameters, regex constraint, 404 and 405', function () {
    $router = (new Router())
        ->get('/', function () { return 'home'; })
        ->get('/invoices/{id:\d+}', function ($req, $p) { return 'inv ' . $p['id']; })
        ->post('/invoices', function () { return Response::json(['created' => true], 201); });
    eq('home', $router->dispatch(req('x', '/'))->body());
    eq('inv 42', $router->dispatch(req('x', '/invoices/42'))->body());
    eq(404, $router->dispatch(req('x', '/invoices/abc'))->status());
    $r = $router->dispatch(req('x', '/invoices', 'POST'));
    eq(201, $r->status());
    eq('application/json; charset=utf-8', $r->header('content-type'));
    $r = $router->dispatch(req('x', '/invoices', 'DELETE'));
    eq(405, $r->status());
    eq('POST', $r->header('Allow'));
});

test('router: works on app-relative paths', function () {
    $router = (new Router())->get('/invoices', function () { return 'list'; });
    eq('list', $router->dispatch(req('erma.sk', '/panel/invoices')->withBasePath('/panel'))->body());
});

// ---------------------------------------------------------------- JWT
test('jwt: round trip, tampering, expiry, wrong secret, alg none', function () {
    $t = Jwt::encode(['sub' => 7, 'exp' => time() + 60], 'secret');
    eq(7, Jwt::decode($t, 'secret')['sub']);
    eq(null, Jwt::decode($t, 'other'));
    $parts = explode('.', $t);
    $parts[1] = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'exp' => time() + 60])), '+/', '-_'), '=');
    eq(null, Jwt::decode(implode('.', $parts), 'secret'), 'tampered payload');
    eq(null, Jwt::decode(Jwt::encode(['sub' => 7, 'exp' => time() - 1], 'secret'), 'secret'), 'expired');
    $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $parts[1] . '.';
    eq(null, Jwt::decode($none, 'secret'), 'alg none');
    throws(function () { Jwt::encode(['a' => 1], ''); }, RuntimeException::class);
});

// ---------------------------------------------------------------- view
test('view: escaping, layout, invalid template names', function () {
    $dir = sys_get_temp_dir() . '/wa_view_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/page.php", '<p><?= $e($name) ?></p>');
    file_put_contents("$dir/layout.php", '<main><?= $content ?></main>');
    $v = new View($dir);
    eq('<main><p>&lt;script&gt;x&lt;/script&gt; &amp; &quot;</p></main>', $v->render('page', ['name' => '<script>x</script> & "'], 'layout'));
    throws(function () use ($v) { $v->render('../etc/passwd'); }, InvalidArgumentException::class);
});

// ---------------------------------------------------------------- config
test('config: legacy split files are converted', function () {
    $dir = sys_get_temp_dir() . '/wa_cfg_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/ApplicationConfig.php", '<?php return ["bnhl" => ["folder" => "bnhl", "file" => "WebApp.php", "class" => "App", "dbPrefix" => "hbkpravotice_", "userEnable" => true], "debugEnabled" => true];');
    file_put_contents("$dir/DatabaseConfig.php", '<?php return ["dbHost" => "h", "dbName" => "n", "dbUser" => "u", "dbPass" => "p", "dbPort" => 3307];');
    file_put_contents("$dir/JwtSecret.php", '<?php return ["jwt_secret" => "s"];');
    $c = Config::load($dir);
    eq(true, $c->get('debug'));
    eq('h', $c->get('database.host'));
    eq(3307, $c->get('database.port'));
    eq('s', $c->get('jwtSecret'));
    eq('user', $c->get('apps.bnhl.auth'));
    eq('App', $c->get('apps.bnhl.class'));
});

test('config: defaults for apps', function () {
    $c = new Config(['apps' => ['x' => []]]);
    eq('x', $c->get('apps.x.folder'));
    eq('x_', $c->get('apps.x.dbPrefix'));
    eq('none', $c->get('apps.x.auth'));
    eq(false, $c->get('debug'));
});
