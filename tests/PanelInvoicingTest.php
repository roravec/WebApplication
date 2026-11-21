<?php
// Panel invoicing: amounts, number formats, migrations, issuing (src/applications/panel).
require_once __DIR__ . '/../src/applications/panel/App.php';

use Panel\Invoicing\Calculator;
use Panel\Invoicing\Companies;
use Panel\Invoicing\Invoices;
use Panel\Invoicing\Numbering;
use Panel\Rights;
use Panel\Schema;
use WebApplication\Entity\Client;

test('invoicing: calculator — non-payer charges no VAT', function () {
    $r = Calculator::compute([
        ['quantity' => 10, 'unit_price' => 21, 'vat_rate' => 23, 'vat_category' => 'S'],
        ['quantity' => 3, 'unit_price' => 0.333, 'vat_rate' => 23, 'vat_category' => 'S'],
    ], false);
    eq('211.00', $r['net']);          // 210.00 + round(0.999) = 1.00
    eq('0.00', $r['vat']);
    eq('211.00', $r['total']);
    eq('O', $r['lines'][0]['vat_category']);
    eq('0.00', $r['lines'][0]['vat_rate']);
});

test('invoicing: calculator — VAT per rate from the sum of lines', function () {
    $r = Calculator::compute([
        ['quantity' => 1, 'unit_price' => 0.05, 'vat_rate' => 23, 'vat_category' => 'S'],
        ['quantity' => 1, 'unit_price' => 0.05, 'vat_rate' => 23, 'vat_category' => 'S'],
        ['quantity' => 2, 'unit_price' => 10, 'vat_rate' => 5, 'vat_category' => 'S'],
        ['quantity' => 1, 'unit_price' => 100, 'vat_rate' => 23, 'vat_category' => 'AE'],
    ], true);
    // 23 %: base 0.10 → VAT 0.02 (per line it would be 0.01 + 0.01 = 0.02 too, but computed from the sum)
    eq('0.10', $r['breakdown']['S|23.00']['net']);
    eq('0.02', $r['breakdown']['S|23.00']['vat']);
    eq('1.00', $r['breakdown']['S|5.00']['vat']);
    eq('0.00', $r['breakdown']['AE|0.00']['vat'], 'reverse charge has no VAT');
    eq('120.10', $r['net']);
    eq('1.02', $r['vat']);
    eq('121.12', $r['total']);
    eq('-0.00' !== Calculator::fmt(-0.001), true);
    eq('0.00', Calculator::fmt(-0.001));
    eq(1234.5, Calculator::parse('1 234,50'));
    eq(null, Calculator::parse('abc'));
});

test('invoicing: number formats and variable symbol', function () {
    eq('FA2026/000198', Numbering::format('FA{RRRR}/{NNNNNN}', 198, '2026-09-30'));
    eq('26-09-0007', Numbering::format('{RR}-{MM}-{NNNN}', 7, '2026-09-30'));
    eq('2026000198', Numbering::variableSymbol('', 'FA2026/000198', 198, '2026-09-30'));
    eq('26000198', Numbering::variableSymbol('{RR}{NNNNNN}', 'FA2026/000198', 198, '2026-09-30'));
    eq(null, Numbering::formatError('FA{RRRR}/{NNNNNN}'));
    ok(Numbering::formatError('FA{RRRR}') !== null, 'counter required');
    ok(Numbering::formatError('FA{NN}{NN}') !== null, 'one counter only');
    ok(Numbering::formatError('FA{XX}{NN}') !== null, 'unknown token');
    ok(Numbering::vsFormatError('{RRRR}{NNNNNNNN}') !== null, 'VS max 10 digits');
    eq(null, Numbering::vsFormatError('{RR}{NNNNNN}'));
});

/** Fresh panel tables (prefix pt_) migrated from src/applications/panel/sql. */
function panelDb(): WebApplication\Database\Database
{
    $db = testDb('pt_');
    foreach ($db->query("SHOW TABLES LIKE 'pt\\_%'") as $row) {
        $t = reset($row);
        if (!in_array($t, ['pt_client', 'pt_token', 'pt_log', 'pt_text'], true)) {
            $db->pdo()->exec("DROP TABLE `$t`");
        }
    }
    return $db;
}

function panelUser($db, string $login, int $rights): Client
{
    $u = new Client($db);
    $u->identifier = $login; $u->secret = 'password-123'; $u->name = ucfirst($login); $u->rights = $rights; $u->status = 1;
    ok($u->create());
    return $u;
}

test('panel: sales & margin report (purchase price at order time, estimate, missing, returns, VAT basis)', function () {
    $db = testDb('pt_');
    $pdo = $db->pdo();
    $t = 'tsr_';
    foreach (['orders', 'order_detail', 'product', 'product_shop', 'product_attribute', 'shop', 'category_lang', 'lang'] as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    $pdo->exec("CREATE TABLE {$t}shop (id_shop INT PRIMARY KEY, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}lang (id_lang INT PRIMARY KEY, iso_code VARCHAR(2))");
    $pdo->exec("CREATE TABLE {$t}category_lang (id_category INT, id_shop INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}product (id_product INT PRIMARY KEY, wholesale_price DECIMAL(20,6), id_category_default INT, is_virtual TINYINT)");
    $pdo->exec("CREATE TABLE {$t}product_shop (id_product INT, id_shop INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}product_attribute (id_product_attribute INT PRIMARY KEY, id_product INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}orders (id_order INT PRIMARY KEY, id_shop INT, reference VARCHAR(9), date_add DATETIME, valid TINYINT,
        conversion_rate DECIMAL(13,6), total_discounts_tax_excl DECIMAL(20,6), total_discounts_tax_incl DECIMAL(20,6),
        total_shipping_tax_excl DECIMAL(20,6), total_shipping_tax_incl DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}order_detail (id_order_detail INT AUTO_INCREMENT PRIMARY KEY, id_order INT, product_id INT, product_attribute_id INT,
        product_name VARCHAR(64), product_reference VARCHAR(32), product_quantity INT, product_quantity_refunded INT, product_quantity_return INT,
        total_price_tax_excl DECIMAL(20,6), total_price_tax_incl DECIMAL(20,6), original_wholesale_price DECIMAL(20,6), purchase_supplier_price DECIMAL(20,6))");
    $pdo->exec("INSERT INTO {$t}shop VALUES (1, 'Howies'), (2, 'SPZ')");
    $pdo->exec("INSERT INTO {$t}lang VALUES (1, 'sk')");
    $pdo->exec("INSERT INTO {$t}category_lang VALUES (10, 1, 1, 'Pásky'), (20, 1, 1, 'ŠPZ')");
    $pdo->exec("INSERT INTO {$t}product VALUES (1, 2.00, 10, 0), (2, 0, 20, 0), (3, 0, 10, 1), (4, 0, 20, 0)");
    $pdo->exec("INSERT INTO {$t}product_shop VALUES (1, 1, 2.00), (2, 2, 6.00), (3, 1, 0), (4, 2, 0)");
    $pdo->exec("INSERT INTO {$t}orders VALUES
        (1, 1, 'AAA', '2026-10-05 10:00:00', 1, 1, 1.00, 1.23, 3.00, 3.69),
        (2, 2, 'BBB', '2026-11-02 10:00:00', 1, 1, 0, 0, 2.00, 2.46),
        (3, 1, 'CCC', '2026-11-03 10:00:00', 0, 1, 0, 0, 0, 0)");    // cancelled → not counted
    $pdo->exec("INSERT INTO {$t}order_detail (id_order, product_id, product_attribute_id, product_name, product_reference, product_quantity,
        product_quantity_refunded, product_quantity_return, total_price_tax_excl, total_price_tax_incl, original_wholesale_price, purchase_supplier_price) VALUES
        (1, 1, 0, 'Páska', 'P1', 4, 1, 0, 20.00, 24.60, 1.50, 0),     -- 3 effective, cost at order time 1.50
        (1, 3, 0, 'Poplatok za dobierku', '', 1, 0, 0, 1.00, 1.00, 0, 0),   -- virtual: no purchase price needed
        (2, 2, 0, 'ŠPZ Texas', 'T1', 1, 0, 0, 10.00, 12.30, 0, 0),      -- estimate: current shop purchase price 6.00
        (2, 4, 0, 'ŠPZ Ohio', 'O1', 1, 0, 0, 8.00, 9.84, 0, 0),         -- missing
        (3, 1, 0, 'Páska', 'P1', 9, 0, 0, 45.00, 55.35, 1.50, 0)");
    $r = new Panel\Reports\SalesReport($db, $t, 'sk');
    $netAll = function () { return true; };
    $d = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'month'], $netAll);
    $s = $d['summary'];
    eq(2, $s['orders'], 'cancelled order not counted');
    eq(3 + 1 + 1 + 1, $s['qty'], 'returned piece subtracted');
    eq('34.00', number_format($s['revenue'], 2, '.', ''), '15 + 1 + 10 + 8');        // 20 × 3/4 = 15
    eq('4.50 6.00', number_format(3 * 1.50, 2, '.', '') . ' ' . number_format(6.0, 2, '.', ''));
    eq('10.50', number_format($s['cogs'], 2, '.', ''), '3 × 1.50 + 6.00 (estimate) + 0');
    eq('22.50', number_format($s['margin'], 2, '.', ''), '34 − 1 discount − 10.50');
    eq(1, $s['missing']);
    eq(1, $s['estimated']);
    eq('5.00', number_format($s['shipping'], 2, '.', ''));
    eq(['2026-10', '2026-11'], array_column($d['rows'], 'label'));
    eq(['ŠPZ Ohio'], array_column($d['missing'], 'name'));
    // non-payer: gross amounts
    $gross = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'shop'], function () { return false; });
    eq('41.59', number_format($gross['summary']['revenue'], 2, '.', ''), '24.60 × 3/4 + 1 + 12.30 + 9.84');
    eq(['SPZ', 'Howies'], array_column($gross['rows'], 'label'), 'by revenue: SPZ 22.14 > Howies 19.45');
    // only one shop, by category
    $cat = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'shops' => [2], 'group' => 'category'], $netAll);
    eq(['ŠPZ'], array_column($cat['rows'], 'label'));
    eq(1, $cat['summary']['orders']);
    // non-payer counting purchase prices with VAT (company setting): cost × 1.23
    $vat = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'month'], function () { return ['net' => true, 'cost_factor' => 1.23]; });
    eq('12.92', number_format($vat['summary']['cogs'], 2, '.', ''), '10.50 × 1.23');

    // purchase price list (module B): missing + sold first, estimate counts as a price, virtual left out
    foreach (['product_lang', 'product_attribute_combination', 'attribute_lang'] as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    $pdo->exec("CREATE TABLE {$t}product_lang (id_product INT, id_shop INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}product_attribute_combination (id_attribute INT, id_product_attribute INT)");
    $pdo->exec("CREATE TABLE {$t}attribute_lang (id_attribute INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("INSERT INTO {$t}product_lang VALUES (1, 1, 1, 'Páska'), (2, 2, 1, 'ŠPZ Texas'), (3, 1, 1, 'Poplatok'), (4, 2, 1, 'ŠPZ Ohio'), (5, 2, 1, 'ŠPZ Iowa')");
    $pdo->exec("INSERT INTO {$t}product VALUES (5, 0, 20, 0)");
    $pdo->exec("INSERT INTO {$t}product_shop VALUES (5, 2, 0)");
    $pdo->exec("ALTER TABLE {$t}product ADD reference VARCHAR(32) NOT NULL DEFAULT ''");
    $pdo->exec("ALTER TABLE {$t}product_attribute ADD reference VARCHAR(32) NOT NULL DEFAULT ''");
    $missingSold = $r->purchasePriceList([1, 2], 'missing_sold');
    eq(['ŠPZ Ohio'], array_column($missingSold, 'name'), 'only sold without any price (Texas has a shop price, Iowa never sold)');
    eq(['ŠPZ Ohio', 'ŠPZ Iowa'], array_column($r->purchasePriceList([1, 2], 'missing'), 'name'), 'sold first');
    eq(4, count($r->purchasePriceList([1, 2], 'all')), 'virtual fee product left out');
    eq('6.00', number_format($r->purchasePriceList([2], 'all', 'texas')[0]['price'], 2, '.', ''));
    eq([2], $r->productShops(4));
    foreach (['product_lang', 'product_attribute_combination', 'attribute_lang'] as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    foreach (['orders', 'order_detail', 'product', 'product_shop', 'product_attribute', 'shop', 'category_lang', 'lang'] as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
});

test('panel: migrations on an EMPTY database (fresh hosting) create the framework tables too', function () {
    $db = testDb('pt_');
    foreach ($db->query("SHOW TABLES LIKE 'pt\\_%'") as $row) {
        $db->pdo()->exec('DROP TABLE `' . reset($row) . '`');
    }
    ok(in_array('000_framework_tables.sql', Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql'), true));
    foreach (['client', 'token', 'log', 'text', 'invoice', 'accounting_package'] as $t) {
        eq(1, count($db->query("SHOW TABLES LIKE 'pt\\_$t'")), "pt_$t exists");
    }
    eq(32, strlen(Panel\Controller\SetupController::key('secret')));
    // employees log in with their e-mail (lower-case)
    eq('jan.novak@erma.sk', Panel\Controller\EmployeesController::normalizeEmail('  Jan.Novak@ERMA.sk '));
    eq(null, Panel\Controller\EmployeesController::emailError('jan.novak@erma.sk'));
    ok(Panel\Controller\EmployeesController::emailError('admin') !== null, 'plain name is not a login');
    ok(Panel\Controller\EmployeesController::emailError('jan@') !== null);
    ok(Panel\Controller\SetupController::key('a') !== Panel\Controller\SetupController::key('b'));
});

test('panel: migrations run once and convert old rights levels (no access lost or gained by the roles migration)', function () {
    $db = panelDb();
    $viewer = panelUser($db, 'viewer', 10);
    $invoicer = panelUser($db, 'invoicer', 50);
    $admin = panelUser($db, 'boss', 100);
    $dir = __DIR__ . '/../src/applications/panel/sql';
    ok(count(Schema::migrate($db, $dir)) >= 1, 'applied');
    eq([], Schema::migrate($db, $dir), 'second run does nothing');
    $roles = new Panel\Core\Roles($db);
    $pv = $roles->person($viewer->getId(), 10);
    eq([Rights::INVOICES_VIEW, Rights::ORDERS_VIEW], (function ($p) { sort($p); return $p; })($pv['allow']), 'old permissions = allowed exceptions');
    eq([[], [], false, true], [$pv['roles'], $pv['deny'], $pv['all_companies'], $pv['all_shops']], 'no role, own companies, all e-shops (as before)');
    ok(in_array(Rights::INVOICES_EDIT, $roles->person($invoicer->getId(), 10)['allow'], true));
    eq(10, (int)$db->fetchValue('SELECT rights FROM pt_client WHERE id = ?', [$invoicer->getId()]));
    eq(100, (int)$db->fetchValue('SELECT rights FROM pt_client WHERE id = ?', [$admin->getId()]));
    $r = new Panel\Core\Access($db, $viewer->getId(), 10);
    ok($r->can(Rights::ORDERS_VIEW) && !$r->can(Rights::INVOICES_EDIT) && !$r->can(Rights::ADMIN_ONLY) && !$r->can(Rights::SETTINGS_EMPLOYEES));
    ok($r->canShop(0, 1) && $r->canShop(5, 9) && $r->shopIdsFor(0) === null, 'all e-shops');
    $boss = new Panel\Core\Access($db, $admin->getId(), 100);
    ok($boss->can(Rights::ADMIN_ONLY) && $boss->can(Rights::SETTINGS_EMPLOYEES) && $boss->can('future.module') && $boss->allCompanies() && $boss->allShops());
    eq('Správca', $boss->roleLabel());
    eq(4, count($roles->all()), 'offered roles');
    eq(0, (int)$db->fetchValue('SELECT COUNT(*) FROM pt_client_role'), 'nobody got a role by the migration');
    ok(!(new Panel\Core\Access($db, 0, 100))->can(Rights::ORDERS_VIEW), 'nobody logged in = nothing');
});

test('permissions: every module registers its own, every key used in the code is registered, labels and groups', function () {
    $all = Panel\Core\Permissions::all();
    $consts = (new ReflectionClass(Rights::class))->getConstants();
    unset($consts['EMPLOYEE'], $consts['ADMIN'], $consts['ADMIN_ONLY']);
    eq([], array_values(array_diff($consts, array_keys($all))), 'every Rights:: key is registered by a module');
    eq([], array_values(array_diff(array_keys($all), $consts)), 'every registered key has a Rights:: constant');
    foreach ($all as $k => $p) {
        ok($p['label'] !== '' && $p['group'] !== '', "label + group of $k");
    }
    // no route of any module is "Správca only" any more — each has a permission a role can give
    $src = '';
    foreach (glob(__DIR__ . '/../src/applications/panel/src/{Modules,Controller}/*.php', GLOB_BRACE) as $f) {
        $src .= file_get_contents($f);
    }
    eq(0, substr_count($src, 'ADMIN_ONLY'), 'no admin-only guard left');
    preg_match_all("/['\"]([a-z]+\.[a-z_]+)['\"]/", $src, $m);
    $jobs = Panel\Core\Scheduler::jobs();
    foreach (array_unique($m[1]) as $k) {
        if (!isset($jobs[$k]) && preg_match('/^(orders|invoices|expenses|stock|reports|companies|settings|api)\.[a-z_]+$/', $k)) {
            ok(isset($all[$k]), "permission $k used in the code is registered");
        }
    }
    throws(function () {
        $m = new class implements Panel\Module {
            public function routes(WebApplication\Http\Router $r, callable $g, Panel\PanelContext $c): void {}
            public function nav(): array { return []; }
            public function settingsTabs(): array { return []; }
            public function permissions(): array { return [['key' => 'orders.view', 'label' => 'x', 'group' => 'x']]; }
            public function recordTypes(): array { return []; }
        };
        Panel\Core\Permissions::fromModules([new Panel\Modules\OrdersModule(), $m]);
    }, LogicException::class);
    eq(['orders.view', 'invoices.edit'], Panel\Core\Permissions::clean(['invoices.edit', 'nonsense', 'orders.view', 'orders.view']), 'register order, unknown dropped');
});

test('permissions: roles + exceptions + companies + e-shops; nobody gives more than he has; changes described', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $roles = new Panel\Core\Roles($db);
    $ana = panelUser($db, 'ana', 10);
    $boss = panelUser($db, 'boss2', 100);
    $exp = $roles->save(null, 'Expedícia test', 'balí', [Rights::ORDERS_VIEW, Rights::ORDERS_EDIT, 'nonsense']);
    eq([Rights::ORDERS_VIEW, Rights::ORDERS_EDIT], $roles->find($exp)['permissions'], 'unknown permission not stored');
    eq('Rola s týmto názvom už existuje.', $roles->nameError('Expedícia test', null));
    eq(null, $roles->nameError('Expedícia test', $exp), 'own name is fine');
    $a = ['admin' => false, 'roles' => [$exp], 'allow' => [Rights::INVOICES_VIEW], 'deny' => [Rights::ORDERS_EDIT],
          'all_companies' => false, 'companies' => [3, 4], 'all_shops' => false, 'shops' => ['0:1', '2:5']];
    $roles->savePerson($ana->getId(), $a);
    $stored = $roles->person($ana->getId(), 10);
    sort($stored['companies']);
    sort($stored['shops']);
    eq($a, $stored, 'stored as given');
    $acc = new Panel\Core\Access($db, $ana->getId(), 10);
    ok($acc->can(Rights::ORDERS_VIEW) && $acc->can(Rights::INVOICES_VIEW), 'from the role + allowed exception');
    ok(!$acc->can(Rights::ORDERS_EDIT), 'denied although the role gives it');
    ok(!$acc->can(Rights::INVOICES_EDIT) && !$acc->can(Rights::SETTINGS_EMPLOYEES));
    eq([3, 4], (function ($c) { sort($c); return $c; })($acc->companyIds()));
    ok($acc->canCompany(3) && !$acc->canCompany(5) && !$acc->allCompanies());
    ok($acc->canShop(0, 1) && $acc->canShop(2, 5) && !$acc->canShop(0, 2) && !$acc->canShop(2, 1));
    eq([[1], [5], []], [$acc->shopIdsFor(0), $acc->shopIdsFor(2), $acc->shopIdsFor(7)]);
    eq('Expedícia test', $acc->roleLabel());

    // the role changes → applies at once to its holders
    $roles->save($exp, 'Expedícia test', 'balí', [Rights::ORDERS_VIEW, Rights::STOCK_VIEW]);
    ok((new Panel\Core\Access($db, $ana->getId(), 10))->can(Rights::STOCK_VIEW));
    eq([$ana->getId()], $roles->holders($exp));

    // beyond: the editor (ana) has less than the boss and can't give what she lacks
    $anaNow = $roles->person($ana->getId(), 10);
    $bossA = $roles->person($boss->getId(), 100);
    eq(['správca'], $roles->beyond($anaNow, $bossA, [3, 4, 5]));
    eq([], $roles->beyond($bossA, $anaNow, [3, 4, 5]), 'Správca may everything');
    eq([], $roles->beyond($anaNow, $anaNow, [3, 4, 5]));
    $more = array_merge($anaNow, ['allow' => [Rights::INVOICES_VIEW, Rights::INVOICES_EDIT], 'companies' => [3, 4, 5], 'shops' => ['0:1', '2:5', '0:2']]);
    eq(['Faktúry — vystavovanie (koncepty, vystavenie, dobropisy, úhrady, odberatelia, e-maily)', 'firma #5', 'e-shop 0:2'],
        $roles->beyond($anaNow, $more, [3, 4, 5]));
    eq([], $roles->beyond($anaNow, array_merge($anaNow, ['companies' => [3, 4, 99]]), [3, 4, 5]), 'a deleted company does not count');
    eq(['všetky firmy', 'všetky e-shopy'], $roles->beyond($anaNow, array_merge($anaNow, ['all_companies' => true, 'all_shops' => true]), [3, 4]));
    $less = array_merge($anaNow, ['roles' => [], 'deny' => [], 'allow' => [Rights::ORDERS_VIEW]]);
    eq([], $roles->beyond($anaNow, $less, [3, 4]), 'giving less is fine');

    // activity log text
    $d = $roles->describeChange($anaNow, array_merge($anaNow, ['roles' => [], 'allow' => [], 'all_shops' => true, 'companies' => [3]]),
        [3 => 'ERMA', 4 => 'Iná'], ['0:1' => 'Howies']);
    ok(strpos($d, 'roly: −Expedícia test') !== false && strpos($d, 'navyše: −Faktúry — prezeranie') !== false
        && strpos($d, 'e-shopy: všetky') !== false && strpos($d, 'firmy: −Iná') !== false, $d);
    eq('', $roles->describeChange($anaNow, $anaNow, [], []), 'nothing changed');

    // delete a role: taken away from its holders
    $roles->delete($exp);
    eq([], $roles->person($ana->getId(), 10)['roles']);
    ok(!(new Panel\Core\Access($db, $ana->getId(), 10))->can(Rights::STOCK_VIEW));
    $roles->grantCompany($ana->getId(), 9);
    ok(in_array(9, $roles->person($ana->getId(), 10)['companies'], true));
});

test('permissions: e-shop scope of the order sources (SQL and Webservice) — lists, search, detail, stats', function () {
    // Webservice source: shops 1 Howies (orders 10, 12), 2 SPZ (order 11)
    $src = new Panel\Shop\ShopOrdersApi(fakeOrderShop(), 3, 'Vzdialený e-shop');
    $src->restrictShops([2]);
    eq([['id_shop' => 2, 'name' => 'SPZ']], $src->shops());
    eq([11], array_column($src->search([])['rows'], 'id_order'));
    eq(0, $src->search(['shop' => 1])['total'], 'a foreign shop in the filter gives nothing');
    eq(null, $src->find(10), 'order of a foreign shop = not found');
    eq(['SPZ'], array_column($src->stats()['per_shop'], 'name'));
    $src->restrictShops([1]);
    eq(10, $src->find(10)['id_order'], 'own shop: detail as usual (the fake shop has the full detail of order 10)');
    $src->restrictShops([]);
    eq([[], 0, null], [$src->shops(), $src->search([])['total'], $src->find(10)], 'no shop = nothing');
    $src->restrictShops(null);
    eq(3, $src->search([])['total'], 'null = all');

    // SQL source (shop tables in the panel database)
    $db = testDb('pt_');
    $pdo = $db->pdo();
    $t = 'tsc_';
    foreach (['orders', 'shop', 'customer', 'address', 'order_state', 'order_state_lang', 'currency', 'lang', 'carrier', 'order_carrier'] as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    $pdo->exec("CREATE TABLE {$t}orders (id_order INT PRIMARY KEY, id_shop INT, id_customer INT, id_address_invoice INT, id_carrier INT, current_state INT,
        id_currency INT, reference VARCHAR(9), date_add DATETIME, total_paid_tax_incl DECIMAL(20,6), payment VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}shop (id_shop INT PRIMARY KEY, name VARCHAR(64), deleted TINYINT)");
    $pdo->exec("CREATE TABLE {$t}customer (id_customer INT PRIMARY KEY, firstname VARCHAR(32), lastname VARCHAR(32), email VARCHAR(128), company VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}address (id_address INT PRIMARY KEY, company VARCHAR(64), firstname VARCHAR(32), lastname VARCHAR(32))");
    $pdo->exec("CREATE TABLE {$t}order_state (id_order_state INT PRIMARY KEY, color VARCHAR(32), paid TINYINT)");
    $pdo->exec("CREATE TABLE {$t}order_state_lang (id_order_state INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}currency (id_currency INT PRIMARY KEY, iso_code VARCHAR(3))");
    $pdo->exec("CREATE TABLE {$t}lang (id_lang INT PRIMARY KEY, iso_code VARCHAR(2))");
    $pdo->exec("CREATE TABLE {$t}carrier (id_carrier INT PRIMARY KEY, name VARCHAR(64), url VARCHAR(255))");
    $pdo->exec("CREATE TABLE {$t}order_carrier (id_order_carrier INT PRIMARY KEY, id_order INT, tracking_number VARCHAR(64))");
    $pdo->exec("INSERT INTO {$t}shop VALUES (1, 'Howies', 0), (2, 'SPZ', 0)");
    $pdo->exec("INSERT INTO {$t}lang VALUES (1, 'sk')");
    $now = date('Y-m-d H:i:s');
    $pdo->exec("INSERT INTO {$t}orders VALUES (1, 1, 0, 0, 0, 0, 0, 'AAA', '$now', 10, 'x'), (2, 2, 0, 0, 0, 0, 0, 'BBB', '$now', 20, 'x'),
        (3, 2, 0, 0, 0, 0, 0, 'CCC', '$now', 30, 'x')");
    $sql = new Panel\Shop\ShopOrders($db, $t);
    eq(3, $sql->search([])['total']);
    $sql->restrictShops([2]);
    eq([[2, 'SPZ']], array_map(function ($r) { return [(int)$r['id_shop'], $r['name']]; }, $sql->shops()));
    eq([3, 2], array_map('intval', array_column($sql->search([])['rows'], 'id_order')));
    eq(0, $sql->search(['shop' => 1])['total']);
    eq(null, $sql->find(1), 'order of a foreign shop = not found');
    $st = $sql->stats();
    eq([2, 50.0, ['SPZ']], [$st['month'], $st['month_total'], array_column($st['per_shop'], 'name')]);
    $sql->restrictShops([]);
    eq([0, []], [$sql->search([])['total'], $sql->shops()]);
});

test('panel: issue invoices — numbers in sequence, issued is immutable, credit note', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => '', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveBanks($cid, [['id' => 0, 'account_name' => 'ERMA', 'iban' => 'SK1783300000002501809819', 'bic' => '', 'is_default' => true, 'active' => true]]);
    // VAT payer only from 2027 → an invoice delivered in 2026 has no VAT
    $companies->saveVatPeriods($cid, [['valid_from' => '2027-01-01', 'vat_payer' => 1, 'icdph' => 'SK2121232916']]);
    $sid = $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '',
        'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'credit_note', 'name' => 'DB', 'format' => 'DB{RRRR}/{NNNN}', 'vs_format' => '',
        'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    Numbering::setNext($db, $companies->oneSeries($sid), 2026, 198);

    $inv = new Invoices($db);
    $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => '2026-09-30', 'delivery_date' => '2026-09-30',
        'due_date' => '2026-10-14', 'buyer_type' => 'b2b', 'buyer_name' => 'ZM Technik, spol. s r. o.', 'buyer_street' => 'Partizánska 251/74',
        'buyer_city' => 'Lehota pod Vtáčnikom', 'buyer_zip' => '972 42', 'buyer_ico' => '36841889']);
    $lines = [['name' => 'Oraverabox PCB výroba PCB', 'quantity' => 10, 'unit' => 'ks', 'unit_price' => 21, 'vat_rate' => 23, 'vat_category' => 'S'],
              ['name' => 'Uzatvárateľná krabička', 'quantity' => 10, 'unit' => 'ks', 'unit_price' => 15, 'vat_rate' => 23, 'vat_category' => 'S']];
    $id1 = $inv->saveDraft(null, $h, $lines, $user);
    $id2 = $inv->saveDraft(null, $h, $lines, $user);
    eq('draft', $inv->find($id1)['status']);
    eq(null, $inv->find($id1)['number']);

    eq('FA2026/000198', $inv->issue($id1, $user)['number']);
    $num2 = $inv->issue($id2, $user);
    eq('FA2026/000199', $num2['number']);
    eq('2026000199', $num2['vs']);
    $i1 = $inv->find($id1);
    eq('issued', $i1['status']);
    eq('360.00', $i1['total']);
    eq('0.00', $i1['total_vat'], 'not a VAT payer in 2026');
    eq('ERMA Group s.r.o.', $i1['supplier']['name']);
    eq('SK1783300000002501809819', $i1['iban']);

    throws(function () use ($inv, $id1, $h, $lines, $user) { $inv->saveDraft($id1, $h, $lines, $user); }, RuntimeException::class);
    throws(function () use ($inv, $id1, $user) { $inv->deleteDraft($id1, $user); }, RuntimeException::class);
    throws(function () use ($inv, $id1, $user) { $inv->issue($id1, $user); }, RuntimeException::class);

    // a VAT payer from 2027: VAT is charged by the delivery date
    $id3 = $inv->saveDraft(null, array_merge($h, ['issue_date' => '2027-01-05', 'delivery_date' => '2027-01-05', 'due_date' => '2027-01-19']), $lines, $user);
    $n3 = $inv->issue($id3, $user);
    eq('FA2027/000001', $n3['number'], 'yearly reset');
    $i3 = $inv->find($id3);
    eq('360.00', $i3['total_net']);
    eq('82.80', $i3['total_vat']);
    eq('SK2121232916', $i3['supplier']['icdph']);

    // credit note: draft with negative lines; can't be issued without a reason
    $cn = $inv->createCreditNote($id1, $user);
    $c = $inv->find($cn);
    eq('credit_note', $c['doc_type']);
    eq('-360.00', $c['total']);
    throws(function () use ($inv, $cn, $user) { $inv->issue($cn, $user); }, RuntimeException::class);
    $c['correction_reason'] = 'Vrátený tovar';
    $c['issue_date'] = $c['due_date'] = '2026-11-19';   // independent of the test machine's clock
    $inv->saveDraft($cn, $c, $c['lines'], $user);
    eq('DB2026/0001', $inv->issue($cn, $user)['number']);

    // draft can be deleted
    $id4 = $inv->saveDraft(null, $h, $lines, $user);
    $inv->deleteDraft($id4, $user);
    eq(null, $inv->find($id4));
    ok(count(Panel\Audit::forEntity($db, 'invoice', $id1)) >= 3, 'audit: create, issue, credit note');
});

test('panel: an invoice issued by mistake goes back to a draft (number released, conditions)', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => '', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveBanks($cid, [['id' => 0, 'account_name' => 'ERMA', 'iban' => 'SK1783300000002501809819', 'bic' => '', 'is_default' => true, 'active' => true]]);
    $sid = $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '',
        'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $series = $companies->oneSeries($sid);
    $inv = new Invoices($db);
    $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => '2026-11-21', 'delivery_date' => '2026-11-21',
        'due_date' => '2026-11-21', 'payment_method' => 'card', 'buyer_type' => 'b2c', 'buyer_name' => 'Ján Novák', 'buyer_street' => 'Hlavná 1',
        'buyer_city' => 'Pravotice', 'buyer_zip' => '956 35']);
    $lines = [['name' => 'Puk', 'quantity' => 2, 'unit' => 'ks', 'unit_price' => 5, 'vat_rate' => 23, 'vat_category' => 'S']];

    // the owner's case: series not set yet → FA2026/000001 (a number the shop already used), paid by card, PDF archived
    $id1 = $inv->saveDraft(null, $h, $lines, $user);
    eq(['Doklad nie je vystavený.'], $inv->unissueErrors($inv->find($id1)));
    eq('FA2026/000001', $inv->issue($id1, $user)['number']);
    eq(1, count($inv->payments($id1)), 'automatic card payment');
    $db->execute('INSERT INTO ' . $db->table('invoice_pdf') . ' (invoice_id, pdf, sha256, size, created_at) VALUES (?, ?, ?, ?, ?)',
        [$id1, '%PDF', str_repeat('a', 64), 4, '2026-11-21 10:00:00']);
    eq([], $inv->unissueErrors($inv->find($id1)));
    throws(function () use ($inv, $id1, $user) { $inv->unissue($id1, '  ', $user); }, InvalidArgumentException::class);
    eq('FA2026/000001', $inv->unissue($id1, 'skúšobná faktúra', $user));
    $d = $inv->find($id1);
    eq(['draft', null, '', null, null, null], [$d['status'], $d['number'], $d['variable_symbol'], $d['supplier'], $d['issued_at'], $d['legal']]);
    eq(1, count($d['lines']), 'lines stay');
    eq([], $inv->payments($id1), 'automatic payment removed');
    eq(null, $db->fetchValue('SELECT sha256 FROM ' . $db->table('invoice_pdf') . ' WHERE invoice_id = ?', [$id1]), 'archived PDF removed');
    eq(1, Numbering::peek($db, $series, '2026-11-21'), 'number 1 released');
    $log = array_values(array_filter(Panel\Audit::forEntity($db, 'invoice', $id1), function ($a) { return $a['action'] === 'unissue'; }));
    contains('FA2026/000001 — skúšobná faktúra (zmazané PDF, SHA-256 ' . str_repeat('a', 64) . '); číselný rad späť na 1', $log[0]['details']);
    throws(function () use ($inv, $id1, $user) { $inv->unissue($id1, 'znova', $user); }, RuntimeException::class);

    // continue the shop's numbers, issue again
    Numbering::setNext($db, $series, 2026, 216);
    eq('FA2026/000216', $inv->issue($id1, $user)['number']);
    $id2 = $inv->saveDraft(null, array_merge($h, ['payment_method' => 'transfer']), $lines, $user);
    eq('FA2026/000217', $inv->issue($id2, $user)['number']);
    contains('FA2026/000217', implode(' ', $inv->unissueErrors($inv->find($id1))), 'not the last one in the series');
    $inv->logEmail($id2, ['jan@example.sk'], 'Faktúra', 'failed', 'SMTP', $user);
    eq([], $inv->unissueErrors($inv->find($id2)), 'a failed e-mail did not deliver it');
    $pid = $inv->addPayment($id2, '2026-11-22', 10, 'transfer', 'výpis', $user);
    contains('úhradu', implode(' ', $inv->unissueErrors($inv->find($id2))));
    $inv->deletePayment($id2, $pid, $user);
    $inv->logEmail($id2, ['jan@example.sk'], 'Faktúra', 'sent', '', $user);
    contains('e-mailom', implode(' ', $inv->unissueErrors($inv->find($id2))));
    throws(function () use ($inv, $id2, $user) { $inv->unissue($id2, 'omyl', $user); }, RuntimeException::class);
    eq('FA2026/000217', $inv->find($id2)['number'], 'still issued');

    // counter already moved by the owner → it is not moved back
    $id3 = $inv->saveDraft(null, array_merge($h, ['payment_method' => 'transfer']), $lines, $user);
    eq('FA2026/000218', $inv->issue($id3, $user)['number']);
    Numbering::setNext($db, $series, 2026, 300);
    $inv->unissue($id3, 'zlé číslo', $user);
    eq(300, Numbering::peek($db, $series, '2026-11-21'));
});

test('panel: number allocation needs a transaction and refuses an existing number', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $series = ['id' => 1, 'format' => 'X{NNN}', 'vs_format' => '', 'reset_yearly' => 0];
    throws(function () use ($db, $series) { Numbering::allocate($db, $series, 1, '2026-01-01'); }, LogicException::class);
    $db->execute("INSERT INTO pt_invoice (company_id, status, number, issue_date, delivery_date, due_date, created_at, updated_at)
                  VALUES (1, 'issued', 'X001', '2026-01-01', '2026-01-01', '2026-01-01', NOW(), NOW())");
    throws(function () use ($db, $series) {
        $db->transaction(function ($db) use ($series) { Numbering::allocate($db, $series, 1, '2026-01-01'); });
    }, RuntimeException::class);
    eq(1, Numbering::peek($db, $series, '2026-01-01'), 'rolled back: counter unchanged');
});

test('panel: customers are separated by company', function () {
    $db = panelDb();
    panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $c = new Panel\Invoicing\Customers($db);
    $base = ['type' => 'b2b', 'street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '', 'icdph' => '',
             'email' => '', 'phone' => '', 'due_days' => '', 'note' => ''];
    $a = $c->save(null, 1, $base + ['name' => 'Odberateľ firmy A']);
    $c->save(null, 2, $base + ['name' => 'Odberateľ firmy B']);
    eq(['Odberateľ firmy A'], array_column($c->search('Odberateľ', [1])['rows'], 'name'));
    eq(2, $c->search('', [1, 2])['total']);
    eq(0, $c->search('', [])['total'], 'no companies = nothing');
    $c->save($a, 2, $base + ['name' => 'Premenovaný']);
    eq(1, (int)$c->find($a)['company_id'], 'a customer never moves to another company');
});

/** Issued document as Invoices::find() returns it (for the export tests, no database). */
function omegaDoc(array $over, array $lines, bool $payer): array
{
    $calc = Calculator::compute($lines, $payer);
    return array_merge([
        'id' => 1, 'status' => 'issued', 'doc_type' => 'invoice', 'number' => 'FA2026/000198', 'variable_symbol' => '2026000198',
        'issue_date' => '2026-09-30', 'delivery_date' => '2026-09-30', 'due_date' => '2026-10-14', 'currency' => 'EUR',
        'payment_method' => 'transfer', 'iban' => 'SK1783300000002501809819', 'bic' => 'FIOZSKBAXXX', 'vat_payer' => $payer ? 1 : 0,
        'supplier' => ['country' => 'SK', 'icdph' => $payer ? 'SK2121232916' : ''], 'buyer_type' => 'b2b',
        'buyer_name' => 'ZM Technik, spol. s r. o.', 'buyer_street' => 'Partizánska 251/74', 'buyer_city' => 'Lehota pod Vtáčnikom',
        'buyer_zip' => '972 42', 'buyer_country' => 'SK', 'buyer_ico' => '36841889', 'buyer_dic' => '2022464169',
        'buyer_icdph' => 'SK2022464169', 'buyer_email' => '', 'order_reference' => '', 'issued_by_name' => 'Rastislav Oravec',
        'note' => '', 'correction_reason' => '', 'original_invoice_id' => null,
        'lines' => $calc['lines'], 'breakdown' => $calc['breakdown'], 'total' => $calc['total'],
        'total_net' => $calc['net'], 'total_vat' => $calc['vat'], 'bank_account_name' => 'ERMA',
        'buyer_phone' => '',
    ], $over);
}

test('omega export: TAB + CRLF, Windows-1250, R00 T04 / T01, R01 columns, R02 items', function () {
    $doc = omegaDoc([], [
        ['name' => 'Oraverabox PCB výroba PCB', 'quantity' => 10, 'unit' => 'ks', 'unit_price' => 21, 'vat_rate' => 0, 'vat_category' => 'S'],
        ['name' => 'Uzatvárateľná krabička', 'quantity' => 10, 'unit' => 'ks', 'unit_price' => 15, 'vat_rate' => 0, 'vat_category' => 'S'],
    ], false);
    $x = new Panel\Invoicing\OmegaExport(['decimal' => ',']);
    $raw = $x->build([$doc]);
    eq([], $x->errors());
    ok(strpos($raw, "\r\n") !== false && substr_count($raw, "\r\n") === substr_count($raw, "\n"), 'CRLF only');
    $txt = iconv('Windows-1250', 'UTF-8', $raw);
    contains('Uzatvárateľná', $txt);
    ok(strpos($raw, "\xC3\xA1") === false && strpos($raw, "\xE1") !== false, 'á is one Windows-1250 byte, not UTF-8');
    $rows = array_map(function ($l) { return explode("\t", $l); }, explode("\r\n", rtrim($txt, "\r\n")));
    eq(['R00', 'T04'], $rows[0]);
    eq('R01', $rows[1][0]);
    eq('36841889', $rows[1][2]);
    eq('T', $rows[1][12], 'partner is a VAT payer');
    eq(['R00', 'T01'], $rows[2]);
    $h = $rows[3];
    eq('R01', $h[0]);
    eq('FA2026/000198', $h[1]);                // col 2 number
    eq('30.09.2026', $h[4]);                   // col 5 issue date
    eq('14.10.2026', $h[5]);                   // col 6 due date
    eq('360,00', $h[10], 'non-payer → base "neobsahuje" (col 11), decimal comma');
    eq('0', $h[17]);                           // col 18 type 0 = invoice
    eq('OF', $h[18]);                          // col 19 evidence
    eq('360,00', $h[42]);                      // col 43 total
    eq('SK', $h[47]);                          // col 48 VAT country code
    eq('2022464169', $h[48]);                  // col 49 IČ DPH without prefix
    eq('2026000198', $h[70]);                  // col 71 VS
    eq(['R02', 'Oraverabox PCB výroba PCB', '10', 'ks', '21', 'X', '', '', '', 'V'], $rows[4]);
});

test('omega export: VAT payer rates, credit note with positive amounts, three rates are refused', function () {
    $lines = [['name' => 'A', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 100, 'vat_rate' => 23, 'vat_category' => 'S'],
              ['name' => 'B', 'quantity' => 2, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 5, 'vat_category' => 'S']];
    $x = new Panel\Invoicing\OmegaExport([]);
    $rows = array_map(function ($l) { return explode("\t", $l); }, explode("\r\n", rtrim(iconv('Windows-1250', 'UTF-8', $x->build([omegaDoc([], $lines, true)])), "\r\n")));
    $h = $rows[3];
    eq(['20.00', '100.00', '0.00', '0.00'], array_slice($h, 7, 4), 'bases N, V, 0, X');
    eq(['5', '23'], array_slice($h, 11, 2), 'lower / higher rate');
    eq(['1.00', '23.00'], array_slice($h, 13, 2), 'VAT lower / higher');
    eq('V', $rows[4][5]);
    eq('N', $rows[5][5]);

    $neg = array_map(function ($l) { $l['quantity'] = -$l['quantity']; return $l; }, $lines);
    $cn = omegaDoc(['doc_type' => 'credit_note', 'number' => 'DB2026/0001', 'original_invoice_id' => 7, 'correction_reason' => 'Vrátený tovar'], $neg, true);
    $rows = array_map(function ($l) { return explode("\t", $l); }, explode("\r\n", rtrim(iconv('Windows-1250', 'UTF-8', $x->build([$cn], [7 => 'FA2026/000198'])), "\r\n")));
    $h = $rows[3];
    eq('4', $h[17], 'type 4 = credit note');
    eq('OD', $h[18]);
    eq('FA2026/000198', $h[32], 'original invoice in "dod. list"');
    eq('144.00', $h[42], 'positive total');
    eq('1', $rows[4][2], 'positive quantity');

    $three = array_merge($lines, [['name' => 'C', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 19, 'vat_category' => 'S']]);
    $x->build([omegaDoc([], $three, true)]);
    eq(1, count($x->errors()));
    contains('dve', $x->errors()[0]);
});

test('legislation: VAT rates and texts by date, frozen on the issued invoice', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $legal = new Panel\Invoicing\Legislation($db);
    eq(20.0, $legal->standardRate('SK', '2024-12-31'));
    eq(23.0, $legal->standardRate('SK', '2025-01-01'));
    eq(['23.00', '19.00', '5.00'], array_column($legal->ratesOn('SK', '2026-11-19'), 'rate'));
    ok($legal->isValidRate('SK', '2024-06-01', 10) && !$legal->isValidRate('SK', '2025-06-01', 10), '10 % only until 2024');
    eq('Nie sme platiteľmi DPH.', $legal->textsOn('2026-01-01')['non_vat_payer']);
    eq('Ceny sú uvedené v EUR.', Panel\Invoicing\Legislation::fill($legal->textsOn('2026-01-01')['prices_currency'], ['currency' => 'EUR']));

    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'Platiteľ s.r.o.', 'street' => 'A 1', 'city' => 'B', 'zip' => '1', 'country' => 'SK',
        'ico' => '12345678', 'dic' => '', 'registration' => 'OR X', 'email' => '', 'phone' => '', 'web' => '', 'slogan' => '',
        'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveVatPeriods($cid, [['valid_from' => '2020-01-01', 'vat_payer' => 1, 'icdph' => 'SK2020202020']]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'F{NNNN}', 'vs_format' => '',
        'reset_yearly' => 0, 'is_default' => 1, 'active' => 1]);
    $inv = new Invoices($db);
    $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => '2026-03-01', 'delivery_date' => '2026-03-01',
        'due_date' => '2026-03-15', 'buyer_name' => 'Odberateľ', 'buyer_street' => 'Ulica 1', 'buyer_city' => 'Mesto',
        'payment_method' => 'cash']);
    // 20 % was valid only until 2024 → can't be issued in 2026
    $old = $inv->saveDraft(null, $h, [['name' => 'X', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 20, 'vat_category' => 'S']], $user);
    contains('neplatí k dátumu dodania', implode(' ', $inv->issueErrors($inv->find($old))));
    // reverse charge needs the buyer's IČ DPH
    $ae = $inv->saveDraft(null, $h, [['name' => 'X', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 0, 'vat_category' => 'AE']], $user);
    contains('IČ DPH', implode(' ', $inv->issueErrors($inv->find($ae))));

    $ok = $inv->saveDraft(null, $h, [['name' => 'X', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 10, 'vat_rate' => 23, 'vat_category' => 'S']], $user);
    eq([], $inv->issueErrors($inv->find($ok)));
    $inv->issue($ok, $user);
    // the law changes after issuing: new text from 2026-06-01 — the issued invoice keeps the old one
    $texts = $legal->allTexts();
    $texts[] = ['code' => 'paid', 'text' => 'NOVÝ TEXT', 'valid_from' => '2026-06-01', 'note' => ''];
    $legal->saveTexts($texts);
    $issued = $inv->find($ok);
    eq('Uhradené — neuhrádzajte.', $issued['legal']['paid']);
    eq(Panel\Invoicing\Templates::CURRENT, $issued['template']);
    eq('NOVÝ TEXT', $legal->textsOn('2026-06-01')['paid']);
    eq('invoices/templates/' . Panel\Invoicing\Templates::CURRENT, Panel\Invoicing\Templates::viewFor(['template' => '../../etc']));
});

test('PAY by square: spec 1.2.0 string (verified by decoding with liblzma), required beneficiary, QR SVG', function () {
    $p = ['amount' => 800, 'currency' => 'EUR', 'due_date' => '2026-10-14', 'vs' => '2026000198',
        'note' => 'ERMA Group s.r.o.: faktúra FA2026/000198', 'iban' => 'SK17 8330 0000 0025 0180 9819', 'bic' => 'FIOZSKBAXXX',
        'beneficiary_name' => 'ERMA Group s.r.o.', 'beneficiary_address1' => 'Pravotice 178', 'beneficiary_address2' => '956 35 Pravotice'];
    // decoded on 2026-11-19 with Python lzma (FORMAT_RAW, LZMA1 lc=3 lp=0 pb=2): header 0000, CRC OK, 19 fields
    eq('000B0000FQHK3GDG9307VPJH1O6IP5OKRU37NSR53DP32ESVS1LQ6CDVCB703GHVID9EG4SMCU1G6II8CFDO9A2L56FICKA3MSBK6SL49DEB3I0K6H790M52RFSSV8VQ0DHHH961S0GESRQMIE8LF3RNGG3HI3KV28S0A71OT1MSEBB4CMMSS44PO0PKH4R5JRHPF2NFNQVD1I7VHQRAQNC2N9UKPMQ5HU0G5DQRS19P9O7P0K3O85TELGJUG9RGQMPN7HIG',
        Panel\Invoicing\PayBySquare::encode($p));
    eq(1, preg_match('/^[0-9A-V]+$/', Panel\Invoicing\PayBySquare::encode($p)));
    throws(function () use ($p) { Panel\Invoicing\PayBySquare::encode(['beneficiary_name' => ''] + $p); }, InvalidArgumentException::class);
    eq('', Panel\Invoicing\PayBySquare::base32hex(''));
    eq('04', Panel\Invoicing\PayBySquare::base32hex("\x01"));   // 00000001 + 2 padding bits → 00000 00100
    if (class_exists(BaconQrCode\Encoder\Encoder::class)) {
        $svg = Panel\Invoicing\QrSvg::svg(Panel\Invoicing\PayBySquare::encode($p));
        contains('<svg', $svg);
        contains('h1v1h-1z', $svg);
    }
});

test('PDF: dompdf renders an A4 document with the Poppins font and Slovak characters', function () {
    if (!Panel\Invoicing\Pdf::available()) {
        skip('dompdf not installed (composer install)');
    }
    $font = Panel\Invoicing\Pdf::fontDir() . '/Poppins-Regular.ttf';
    $html = '<html><head><meta charset="utf-8"><style>@font-face { font-family: "Poppins"; src: url("' . $font . '"); }
        body { font-family: "Poppins"; }</style></head><body><p>Faktúra — Šťastný Žltý Kôň, ľščťžýáíé 800,00 €</p></body></html>';
    $pdf = Panel\Invoicing\Pdf::fromHtml($html);
    eq('%PDF-', substr($pdf, 0, 5));
    ok(strpos($pdf, 'Poppins') !== false, 'font embedded');
    ok(strlen($pdf) > 5000);
});

test('payments: state paid / partial / unpaid / overdue, card paid at issue, list filter', function () {
    eq('overdue', Invoices::paymentState(['total' => '100.00', 'due_date' => '2026-01-10'], 0, '2026-02-01'));
    eq('unpaid', Invoices::paymentState(['total' => '100.00', 'due_date' => '2026-03-10'], 0, '2026-02-01'));
    eq('partial', Invoices::paymentState(['total' => '100.00', 'due_date' => '2026-01-10'], 40, '2026-02-01'));
    eq('paid', Invoices::paymentState(['total' => '100.00', 'due_date' => '2026-01-10'], 100, '2026-02-01'));
    eq('paid', Invoices::paymentState(['total' => '-50.00', 'due_date' => '2026-01-10'], -50, '2026-02-01'), 'credit note refunded');
    eq('overdue', Invoices::paymentState(['total' => '-50.00', 'due_date' => '2026-01-10'], 0, '2026-02-01'));

    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'Firma', 'street' => 'A 1', 'city' => 'B', 'zip' => '1', 'country' => 'SK', 'ico' => '', 'dic' => '',
        'registration' => '', 'email' => '', 'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '',
        'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'P{NNNN}', 'vs_format' => '',
        'reset_yearly' => 0, 'is_default' => 1, 'active' => 1]);
    $inv = new Invoices($db);
    $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => '2026-01-01', 'delivery_date' => '2026-01-01',
        'due_date' => '2026-01-15', 'buyer_name' => 'X', 'buyer_street' => 'Y 1', 'buyer_city' => 'Z', 'payment_method' => 'card']);
    $line = [['name' => 'A', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 100, 'vat_rate' => 0, 'vat_category' => 'S']];
    $card = $inv->saveDraft(null, $h, $line, $user);
    $inv->issue($card, $user);
    eq(100.0, $inv->paidSum($card), 'card = paid at issue');
    $h['payment_method'] = 'cash';
    $h['bank_account_id'] = null;
    $open = $inv->saveDraft(null, array_merge($h, ['payment_method' => 'cod']), $line, $user);
    $inv->issue($open, $user);
    eq(0.0, $inv->paidSum($open), 'cash on delivery is not paid yet');
    $pid = $inv->addPayment($open, '2026-02-01', 30, 'cod', 'čiastka', $user);
    eq('partial', Invoices::paymentState($inv->find($open), $inv->paidSum($open)));
    eq([(string)$open], array_map('strval', array_column($inv->search(['payment' => 'overdue'], [$cid])['rows'], 'id')), 'overdue filter');
    eq([(string)$card], array_map('strval', array_column($inv->search(['payment' => 'paid'], [$cid])['rows'], 'id')), 'paid filter');
    ok($inv->deletePayment($open, $pid, $user));
    eq(0.0, $inv->paidSum($open));
    eq(['a@b.sk', 'c@d.sk'], Panel\Mailer::parseList(' a@b.sk, c@d.sk;a@b.sk '));
    eq(null, Panel\Mailer::parseList('a@b.sk, nie-je-adresa'));
    eq([], Panel\Mailer::parseList(''));
    eq('{document} {number} — {company}', $companies->mailSettings($cid)['subject'], 'mail defaults');
});

test('bank statement CAMT.053: entries, symbols, booked only, batch split, matching by VS', function () {
    $st = Panel\Invoicing\BankStatement::parseCamt053(file_get_contents(__DIR__ . '/fixtures/camt053-sample.xml'));
    eq('SK1783300000002501809819', $st['iban']);
    eq(5, count($st['entries']), 'pending entry skipped, batch split into 2');
    $e = $st['entries'][0];
    eq(['TX-1001', '2026-11-18', 975.6, '2027000001', 'ZM Technik, spol. s r. o.'], [$e['ref'], $e['date'], $e['amount'], $e['vs'], $e['payer']]);
    eq('SK3111000000002612345678', $e['payer_iban']);
    eq('0000123456', $st['entries'][1]['vs'], 'VS from the message');
    eq(-2.5, $st['entries'][2]['amount'], 'debit is negative');
    eq(['TX-1005-0', 30.0, '111'], [$st['entries'][3]['ref'], $st['entries'][3]['amount'], $st['entries'][3]['vs']]);
    eq(['TX-1005-1', 20.0, '222'], [$st['entries'][4]['ref'], $st['entries'][4]['amount'], $st['entries'][4]['vs']]);
    throws(function () { Panel\Invoicing\BankStatement::parseCamt053('<x/>'); }, InvalidArgumentException::class);
    eq(['vs' => '2026000198', 'ss' => '', 'ks' => '0308'], Panel\Invoicing\BankStatement::symbols('/VS2026000198/SS/KS0308'));
    eq(['vs' => '1234567890', 'ss' => '55', 'ks' => '0308'], Panel\Invoicing\BankStatement::symbols('VS:1234567890SS:55KS:0308'), 'Fio');
    eq(['vs' => '1234567890', 'ss' => '1234567890', 'ks' => '1234'], Panel\Invoicing\BankStatement::symbols('/VS/1234567890/SS/1234567890/KS/1234'));
    eq('', Panel\Invoicing\BankStatement::symbols('ZVS123 KVS9')['vs'] === '123' ? 'x' : '', 'VS inside a word is not a VS');

    $open = [['id' => 7, 'variable_symbol' => '123456', 'total' => '100.00', 'paid' => '0'],
             ['id' => 8, 'variable_symbol' => '2027000001', 'total' => '975.60', 'paid' => '0'],
             ['id' => 9, 'variable_symbol' => '2027000001', 'total' => '50.00', 'paid' => '0']];
    eq(8, Invoices::matchTransaction($st['entries'][0], $open)['id'], 'same VS: the one with the matching amount');
    eq(7, Invoices::matchTransaction($st['entries'][1], $open)['id'], 'leading zeros ignored');
    eq(null, Invoices::matchTransaction($st['entries'][3], $open));
});

test('RPO register: record mapping (current name, address, commercial register entry)', function () {
    // shape of https://api.statistics.sk/rpo/v1/search?identifier=53036875 (2026-11-19)
    $r = ['fullNames' => [['value' => 'ERMA Group s. r. o.', 'validFrom' => '2020-04-17']],
        'addresses' => [
            ['validFrom' => '2022-07-05', 'regNumber' => 0, 'buildingNumber' => '178', 'postalCodes' => ['95635'],
             'municipality' => ['value' => 'Pravotice'], 'country' => ['value' => 'Slovenská republika', 'code' => '703']],
            ['validFrom' => '2020-04-17', 'validTo' => '2022-07-04', 'street' => 'Karpatské námestie', 'regNumber' => 0,
             'buildingNumber' => '10A', 'postalCodes' => ['83106'], 'municipality' => ['value' => 'Bratislava'], 'country' => ['code' => '703']]],
        'sourceRegister' => ['value' => ['value' => 'Obchodný register', 'code' => '1'],
            'registrationOffices' => [['value' => 'Okresný súd Trenčín', 'validFrom' => '2020-04-17']],
            'registrationNumbers' => [['value' => 'Sro/43969/R', 'validFrom' => '2020-04-17']]]];
    eq(['name' => 'ERMA Group s. r. o.', 'street' => 'Pravotice 178', 'zip' => '956 35', 'city' => 'Pravotice', 'country' => 'SK',
        'registration' => 'Obchodný register Okresného súdu Trenčín, oddiel Sro, vložka č. 43969/R'], Panel\Invoicing\Rpo::map($r));
    $r['addresses'][0] = ['street' => 'Hlavná', 'regNumber' => 1234, 'buildingNumber' => '5', 'postalCodes' => ['81101'],
        'municipality' => ['value' => 'Bratislava'], 'country' => ['code' => '703']];
    eq('Hlavná 1234/5', Panel\Invoicing\Rpo::map($r)['street']);
    throws(function () { Panel\Invoicing\Rpo::lookup('12'); }, InvalidArgumentException::class);
});

test('secrets: AES-256-GCM round trip, wrong key / tampering → null; Fio input checks', function () {
    $s = new Panel\Secret('test-app-secret-1234567890');
    $enc = $s->encrypt('FioToken123abcXYZ');
    ok(strpos($enc, 'FioToken') === false && strncmp($enc, 'v1:', 3) === 0, 'not readable');
    ok($enc !== $s->encrypt('FioToken123abcXYZ'), 'random IV');
    eq('FioToken123abcXYZ', $s->decrypt($enc));
    eq(null, (new Panel\Secret('another-secret-0987654321'))->decrypt($enc), 'other key');
    $raw = base64_decode(substr($enc, 3));
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    eq(null, $s->decrypt('v1:' . base64_encode($raw)), 'tampered');
    throws(function () { new Panel\Secret(''); }, RuntimeException::class);
    throws(function () { Panel\Invoicing\Fio::movements('bad token!', '2026-01-01', '2026-01-31'); }, InvalidArgumentException::class);
    throws(function () { Panel\Invoicing\Fio::movements(str_repeat('a', 40), '1.1.2026', '2026-01-31'); }, InvalidArgumentException::class);
});

test('Fio JSON (periods) → statement entries: symbols, IBAN, card payment, VS from the message', function () {
    // shape of https://fioapi.fio.cz/v1/rest/periods/{token}/…/transactions.json (real answer 2026-11-21, values made up)
    $c = function ($id, $v) { return ['value' => $v, 'name' => 'x', 'id' => $id]; };
    $json = json_encode(['accountStatement' => [
        'info' => ['accountId' => '2501809819', 'bankId' => '8330', 'currency' => 'EUR', 'iban' => 'SK1783300000002501809819'],
        'transactionList' => ['transaction' => [
            ['column22' => $c(22, 27935030001), 'column0' => $c(0, '2026-11-18+0100'), 'column1' => $c(1, 975.6),
             'column14' => $c(14, 'EUR'), 'column2' => $c(2, 'SK3111000000002612345678'), 'column3' => null,
             'column10' => $c(10, 'ZM Technik, spol. s r. o.'), 'column4' => $c(4, '0308'), 'column5' => $c(5, '2027000001'),
             'column6' => $c(6, '55'), 'column16' => $c(16, 'faktura 2027000001'), 'column8' => $c(8, 'Bezhotovostní příjem')],
            ['column22' => $c(22, 27935030002), 'column0' => $c(0, '2026-11-19+0100'), 'column1' => $c(1, -51.83),
             'column14' => $c(14, 'EUR'), 'column2' => null, 'column10' => $c(10, 'Obchod'), 'column5' => $c(5, '1234'),
             'column16' => $c(16, 'Nákup: Obchod, Trenčín'), 'column8' => $c(8, 'Platba kartou')],
            ['column22' => $c(22, 27935030003), 'column0' => $c(0, '2026-11-20+0100'), 'column1' => $c(1, 20),
             'column14' => $c(14, 'EUR'), 'column2' => $c(2, '2900123456'), 'column3' => $c(3, '2010'),
             'column10' => $c(10, 'Ján Novák'), 'column5' => null, 'column16' => $c(16, '/VS0000123456/SS/KS0308')],
        ]]]]);
    $st = Panel\Invoicing\Fio::parseJson($json);
    eq(['SK1783300000002501809819', 'EUR', 3], [$st['iban'], $st['currency'], count($st['entries'])]);
    eq(['ref' => '27935030001', 'date' => '2026-11-18', 'amount' => 975.6, 'currency' => 'EUR', 'vs' => '2027000001', 'ss' => '55',
        'ks' => '0308', 'payer' => 'ZM Technik, spol. s r. o.', 'payer_iban' => 'SK3111000000002612345678', 'message' => 'faktura 2027000001'],
        $st['entries'][0]);
    eq([-51.83, '1234', ''], [$st['entries'][1]['amount'], $st['entries'][1]['vs'], $st['entries'][1]['payer_iban']], 'card payment');
    eq(['0000123456', '0308', ''], [$st['entries'][2]['vs'], $st['entries'][2]['ks'], $st['entries'][2]['payer_iban']], 'VS from the message, local account no IBAN');
    $open = [['id' => 8, 'variable_symbol' => '2027000001', 'total' => '975.60', 'paid' => '0']];
    eq(8, Invoices::matchTransaction($st['entries'][0], $open)['id']);
    throws(function () { Panel\Invoicing\Fio::parseJson('<response/>'); }, RuntimeException::class);
});

test('e-invoice UBL 2.1 / Peppol BIS 3.0: structure, totals, discount line, credit note, non-payer rules', function () {
    $sup = ['name' => 'Testovacia Firma s.r.o.', 'street' => 'Hlavná 1', 'city' => 'Trenčín', 'zip' => '911 01', 'country' => 'SK',
        'ico' => '12345678', 'dic' => '2023456789', 'icdph' => 'SK2023456789', 'vat_payer' => true, 'registration' => 'OR', 'email' => '', 'phone' => ''];
    $lines = [['name' => 'A', 'sku' => '', 'quantity' => 10, 'unit' => 'ks', 'unit_price' => 21, 'vat_rate' => 23, 'vat_category' => 'S'],
              ['name' => 'Zľava', 'sku' => '', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => -10, 'vat_rate' => 23, 'vat_category' => 'S']];
    $inv = omegaDoc(['buyer_dic' => '2029876543', 'order_reference' => 'OBJ-1'], $lines, true);
    eq([], Panel\Invoicing\Ubl::check($inv, $sup));
    $x = simplexml_load_string(Panel\Invoicing\Ubl::build($inv, $sup, []));
    $x->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
    $x->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
    $v = function ($p) use ($x) { $r = $x->xpath($p); return $r ? (string)$r[0] : null; };
    eq(Panel\Invoicing\Ubl::CUSTOMIZATION, $v('//cbc:CustomizationID'));
    eq('380', $v('//cbc:InvoiceTypeCode'));
    eq('2023456789', $v('//cac:AccountingSupplierParty//cbc:EndpointID'));
    eq('0245', (string)$x->xpath('//cac:AccountingSupplierParty//cbc:EndpointID/@schemeID')[0]);
    eq('200.00', $v('//cac:LegalMonetaryTotal/cbc:LineExtensionAmount'));
    eq('246.00', $v('//cac:LegalMonetaryTotal/cbc:PayableAmount'));
    eq('-1', $v('//cac:InvoiceLine[2]/cbc:InvoicedQuantity'), 'BR-27: discount = negative quantity');
    eq('10', $v('//cac:InvoiceLine[2]/cac:Price/cbc:PriceAmount'), 'price never negative');
    eq('H87', (string)$x->xpath('//cac:InvoiceLine[1]/cbc:InvoicedQuantity/@unitCode')[0]);

    $o = omegaDoc(['buyer_dic' => '2029876543'], [$lines[0]], false);
    $xo = Panel\Invoicing\Ubl::build($o, ['vat_payer' => false, 'icdph' => ''] + $sup, ['non_vat_payer' => 'Nie sme platiteľmi DPH.']);
    ok(strpos($xo, 'SK2029876543') === false, 'BR-O-02: no buyer VAT id with category O');
    contains('<cbc:TaxExemptionReason>Nie sme platiteľmi DPH.</cbc:TaxExemptionReason>', $xo);
    ok(strpos($xo, '<cbc:Percent>') === false, 'category O has no percent');

    $cn = omegaDoc(['doc_type' => 'credit_note', 'number' => 'DB1', 'buyer_dic' => '2029876543'],
        [['quantity' => -2] + $lines[0]], true);
    $xc = Panel\Invoicing\Ubl::build($cn, $sup, [], ['number' => 'FA1', 'issue_date' => '2026-01-01']);
    contains('<CreditNote ', $xc);
    contains('<cbc:CreditedQuantity unitCode="H87">2</cbc:CreditedQuantity>', $xc);
    contains('<cbc:PayableAmount currencyID="EUR">51.66</cbc:PayableAmount>', $xc);
    contains('<cbc:ID>FA1</cbc:ID>', $xc);
    ok(Panel\Invoicing\Ubl::check(omegaDoc(['buyer_dic' => ''], $lines, true), $sup) !== [], 'B2C without DIČ → no e-invoice');
});

test('stock: receipt lots, FIFO issue with purchase prices, transfer keeps price, no negative stock, inventory', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $cid = (new Companies($db))->save(null, ['name' => 'Firma', 'street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '',
        'dic' => '', 'registration' => '', 'email' => '', 'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '',
        'accent_color' => '#f74040', 'active' => 1]);
    $st = new Panel\Stock\Stock($db);
    $a = $st->saveWarehouse(null, ['company_id' => $cid, 'code' => 'HOW', 'name' => 'Howies', 'location' => 'Pravotice', 'kind' => 'shop', 'active' => 1]);
    $b = $st->saveWarehouse(null, ['company_id' => $cid, 'code' => 'DIL', 'name' => 'Dielňa', 'location' => '', 'kind' => 'other', 'active' => 1]);
    $tape = $st->saveItem(null, $cid, ['sku' => 'TAPE', 'name' => 'Páska', 'unit' => 'ks', 'ean' => '', 'min_qty' => '', 'note' => '', 'active' => 1]);
    $h = ['company_id' => $cid, 'warehouse_id' => $a, 'partner' => 'Dodávateľ', 'reference' => 'FV-1', 'note' => ''];

    $r1 = $st->post($h + ['doc_type' => 'receipt', 'doc_date' => '2026-01-10'], [['item_id' => $tape, 'qty' => 10, 'unit_cost' => 2]], $user);
    $st->post($h + ['doc_type' => 'receipt', 'doc_date' => '2026-02-10'], [['item_id' => $tape, 'qty' => 5, 'unit_cost' => 3, 'lot_code' => 'B2']], $user);
    eq('PR2026/0001', $st->doc($r1)['number'], 'default series created');
    eq(15.0, $st->qty($tape, $a));

    $i1 = $st->post($h + ['doc_type' => 'issue', 'doc_date' => '2026-03-01'], [['item_id' => $tape, 'qty' => 12]], $user);
    eq('26.00', $st->doc($i1)['total_cost'], 'FIFO: 10 × 2 + 2 × 3');
    eq('VY2026/0001', $st->doc($i1)['number']);
    eq(3.0, $st->qty($tape, $a));
    eq(9.0, $st->levels($tape)[$a]['value']);

    $st->post($h + ['doc_type' => 'transfer', 'doc_date' => '2026-03-02', 'target_warehouse_id' => $b], [['item_id' => $tape, 'qty' => 2]], $user);
    $lb = $st->lots($tape, $b);
    eq([1, '2.000', '3.0000', '2026-02-10', 'B2'], [count($lb), $lb[0]['qty_left'], $lb[0]['unit_cost'], $lb[0]['received_on'], $lb[0]['lot_code']],
        'transfer keeps price, receipt date and lot code');
    eq(1.0, $st->qty($tape, $a));

    throws(function () use ($st, $h, $tape, $user) {
        $st->post($h + ['doc_type' => 'issue', 'doc_date' => '2026-03-03'], [['item_id' => $tape, 'qty' => 5]], $user);
    }, RuntimeException::class);
    eq(1.0, $st->qty($tape, $a), 'failed issue changed nothing');
    eq(1, (int)$db->fetchValue("SELECT COUNT(*) FROM pt_stock_doc WHERE doc_type = 'issue'"), 'no document, number not used');

    $st->post($h + ['doc_type' => 'adjust', 'doc_date' => '2026-03-31'], [['item_id' => $tape, 'counted' => 4]], $user);
    eq(4.0, $st->qty($tape, $a), 'surplus +3 at the last price');
    eq(12.0, $st->levels($tape)[$a]['value']);
    $adj = $st->post($h + ['doc_type' => 'adjust', 'doc_date' => '2026-04-01'], [['item_id' => $tape, 'counted' => 0]], $user);
    eq(0.0, $st->qty($tape, $a));
    eq('-12.00', $st->doc($adj)['total_cost'], 'shortage value');
    throws(function () use ($st, $h, $tape, $b, $user) {
        $st->post($h + ['doc_type' => 'transfer', 'doc_date' => '2026-04-02', 'target_warehouse_id' => $h['warehouse_id']], [['item_id' => $tape, 'qty' => 1]], $user);
    }, RuntimeException::class);
    eq(1, count($st->items([$cid])), 'items list works');
});

/** PrestaShop Webservice simulated with canned XML (tests run without a shop). */
class FakePrestaShopApi extends Panel\Shop\PrestaShopApi
{
    public $puts = [];
    public $putXml = [];
    public $posts = [];
    /** @var array resource => XML string, or Closure(params) returning it (state that changes after a write) */
    private $xml;
    public function __construct(array $xml) { parent::__construct('http://shop.test/', str_repeat('K', 32)); $this->xml = $xml; }
    public $gets = [];
    public function get(string $resource, array $params = []): SimpleXMLElement
    {
        $this->gets[] = [$resource, $params];
        if (!isset($this->xml[$resource])) {
            throw new RuntimeException('no ' . $resource, 404);   // like a missing object / a resource the key may not read
        }
        $x = $this->xml[$resource];
        return simplexml_load_string('<prestashop>' . ($x instanceof Closure ? $x($params) : $x) . '</prestashop>', 'SimpleXMLElement', LIBXML_NOCDATA);
    }
    public function put(string $resource, int $id, SimpleXMLElement $xml, array $params = []): SimpleXMLElement
    {
        $this->puts[] = [$resource, $id, (int)$xml->stock_available->quantity];
        $this->putXml[] = [$resource, $id, $xml, $params];
        return $xml;
    }
    public function post(string $resource, string $xml, array $params = []): SimpleXMLElement
    {
        $this->posts[] = [$resource, $xml, $params];
        if (isset($this->xml['POST ' . $resource])) {   // the shop's answer (e.g. the new object with its id)
            return simplexml_load_string('<prestashop>' . ($this->xml['POST ' . $resource])($xml) . '</prestashop>');
        }
        return simplexml_load_string($xml);
    }
    public $deletes = [];
    public function delete(string $resource, int $id, array $params = []): void
    {
        $this->deletes[] = [$resource, $id, $params];
    }
    public function getMany(array $requests, array $optional = []): array
    {
        $out = [];
        foreach (array_filter($requests) as $k => $r) {
            try {
                $out[$k] = $this->get($r[0], $r[1] ?? []);
            } catch (RuntimeException $e) {
                if (!in_array($k, $optional, true)) {
                    throw $e;
                }
                $out[$k] = null;
            }
        }
        return $out;
    }
}

test('e-shop stock (PrestaShop Webservice): products + combinations as in the shop, shared group stock, update', function () {
    throws(function () { new Panel\Shop\PrestaShopApi('ftp://x', str_repeat('K', 32)); }, InvalidArgumentException::class);
    throws(function () { new Panel\Shop\PrestaShopApi('https://x.sk/', 'short'); }, InvalidArgumentException::class);
    $sa = function ($id, $p, $a, $q, $group) {
        return "<stock_available><id>$id</id><id_product>$p</id_product><id_product_attribute>$a</id_product_attribute><id_shop>0</id_shop>"
            . "<id_shop_group>$group</id_shop_group><quantity>$q</quantity><depends_on_stock>0</depends_on_stock><out_of_stock>2</out_of_stock><location></location></stock_available>";
    };
    $api = new FakePrestaShopApi([
        'shop_groups' => '<shop_groups><shop_group><id>2</id><name>Howies</name><share_stock>1</share_stock></shop_group></shop_groups>',
        'shops' => '<shops><shop><id>1</id><name>Howies Hockey</name><id_shop_group>2</id_shop_group></shop></shops>',
        'languages' => '<languages><language><id>1</id><iso_code>en</iso_code></language><language><id>2</id><iso_code>sk</iso_code></language></languages>',
        'products' => '<products>'
            . '<product><id>10</id><reference>LACE</reference><ean13></ean13><active>1</active><name><language id="1">Laces</language><language id="2">Šnúrky</language></name></product>'
            . '<product><id>11</id><reference>TAPE</reference><ean13>859</ean13><active>1</active><name><language id="1">Tape</language><language id="2"></language></name></product>'
            . '</products>',
        'product_option_values' => '<product_option_values><product_option_value><id>5</id><name><language id="2">182 cm</language></name></product_option_value>'
            . '<product_option_value><id>6</id><name><language id="2">213 cm</language></name></product_option_value></product_option_values>',
        'combinations' => '<combinations>'
            . '<combination><id>1</id><id_product>10</id_product><reference>LACE-182</reference><ean13></ean13><associations><product_option_values><product_option_value><id>5</id></product_option_value></product_option_values></associations></combination>'
            . '<combination><id>2</id><id_product>10</id_product><reference></reference><ean13></ean13><associations><product_option_values><product_option_value><id>6</id></product_option_value></product_option_values></associations></combination>'
            . '</combinations>',
        'stock_availables' => '<stock_availables>' . $sa(100, 10, 0, 9, 2) . $sa(101, 10, 1, 4, 2) . $sa(102, 10, 2, 5, 2) . $sa(103, 11, 0, 30, 2) . '</stock_availables>',
        'stock_availables/103' => $sa(103, 11, 0, 30, 2),
        'stock_availables/999' => $sa(999, 11, 0, 30, 7),
    ]);
    $ss = new Panel\Shop\ShopStock($api);
    $shop = $ss->shop(1);
    eq(['id' => 1, 'name' => 'Howies Hockey', 'group_id' => 2, 'group_name' => 'Howies', 'share_stock' => true], $shop);
    $rows = $ss->rows($shop);
    eq(['Šnúrky — 182 cm', 'Šnúrky — 213 cm', 'Tape'], array_column($rows, 'name'), 'combinations as rows, product total skipped, name fallback');
    eq(['LACE-182', 'LACE', 'TAPE'], array_column($rows, 'reference'), 'combination reference, else product reference');
    eq([4, 5, 30], array_column($rows, 'quantity'));
    eq(1, count($ss->rows($shop, 'tape')), 'search');
    eq(['quantity' => 35, 'out_of_stock' => 2, 'location' => ''], $ss->update($shop, 103, ['quantity' => 35]));
    eq([['stock_availables', 103, 35]], $api->puts);
    throws(function () use ($ss, $shop) { $ss->update($shop, 999, ['quantity' => 1]); }, RuntimeException::class);
});

test('e-shop product settings: safe PUT (no associations / read-only / empty nullable fields), combinations', function () {
    $product = '<product><id>10</id><manufacturer_name>X</manufacturer_name><quantity>9</quantity><reference>LACE</reference><ean13></ean13>'
        . '<active>1</active><minimal_quantity>1</minimal_quantity><low_stock_threshold></low_stock_threshold><low_stock_alert>0</low_stock_alert>'
        . '<wholesale_price>1.500000</wholesale_price><position_in_category>3</position_in_category>'
        . '<name><language id="1">Laces</language><language id="2">Šnúrky</language></name>'
        . '<available_now><language id="1">In stock</language><language id="2">Skladom</language></available_now>'
        . '<available_later><language id="1"></language><language id="2"></language></available_later>'
        . '<associations><categories><category><id>2</id></category></categories></associations></product>';
    $combo = function ($id, $pid, $low) {
        return "<combination><id>$id</id><id_product>$pid</id_product><quantity>4</quantity><reference>LACE-182</reference><minimal_quantity>1</minimal_quantity>"
            . "<low_stock_threshold>$low</low_stock_threshold><wholesale_price>1.200000</wholesale_price>"
            . '<associations><product_option_values><product_option_value><id>5</id></product_option_value></product_option_values></associations></combination>';
    };
    $api = new FakePrestaShopApi([
        'products/10' => $product,
        'product_option_values' => '<product_option_values><product_option_value><id>5</id><name><language id="2">182 cm</language></name></product_option_value></product_option_values>',
        'combinations' => '<combinations>' . $combo(1, 10, '') . '</combinations>',
        'combinations/1' => $combo(1, 10, ''),
        'combinations/7' => $combo(7, 99, '3'),
    ]);
    $sp = new Panel\Shop\ShopProducts($api);
    $shop = ['id' => 1, 'name' => 'Howies Hockey'];
    $p = $sp->product($shop, 10, 2);
    eq(['Šnúrky', 'LACE', '1', '', '0', 'Skladom', ''], [$p['name'], $p['reference'], $p['minimal_quantity'], $p['low_stock_threshold'],
        $p['low_stock_alert'], $p['available_now'], $p['available_later']]);
    eq([['id' => 1, 'label' => '182 cm', 'reference' => 'LACE-182', 'minimal_quantity' => '1', 'low_stock_threshold' => '', 'wholesale_price' => '1.200000', 'price' => '']],
        $p['combinations']);

    $sp->updateProduct($shop, 10, ['minimal_quantity' => '2', 'available_later' => 'Do 5 dní', 'bogus' => 'x'], 2);
    list($res, $id, $x, $params) = $api->putXml[0];
    eq(['products', 10, ['id_shop' => 1]], [$res, $id, $params]);
    $n = $x->product;
    eq('2', (string)$n->minimal_quantity);
    eq('Do 5 dní', (string)$n->available_later->language[1], 'language text set for the panel language');
    eq('', (string)$n->available_later->language[0], 'other languages untouched');
    foreach (['associations', 'manufacturer_name', 'quantity', 'position_in_category', 'low_stock_threshold', 'bogus'] as $gone) {
        eq(false, isset($n->$gone), $gone . ' not sent');
    }
    eq('1.500000', (string)$n->wholesale_price, 'other fields sent back unchanged');

    $sp->updateCombination($shop, 1, 10, ['low_stock_threshold' => '3', 'minimal_quantity' => '5']);
    $c = $api->putXml[1][2]->combination;
    eq(['3', '5', false, false], [(string)$c->low_stock_threshold, (string)$c->minimal_quantity, isset($c->associations), isset($c->quantity)]);
    throws(function () use ($sp, $shop) { $sp->updateCombination($shop, 7, 10, ['minimal_quantity' => '2']); }, RuntimeException::class);
    eq(2, count($api->putXml), 'combination of another product is not written');
});

test('order state change in the shop: reference check, order_histories POST, same state skipped', function () {
    $api = null;
    $api = new FakePrestaShopApi([
        'orders/5' => function ($params) use (&$api) {
            $s = $api->posts ? 4 : 3;   // the shop reports the new state after the POST
            return "<order><id>5</id><reference>ABCDEFGHI</reference><current_state>$s</current_state></order>";
        },
    ]);
    $os = new Panel\Shop\ShopOrderState($api);
    throws(function () use ($os) { $os->change(5, 1, 'OTHERREF', 4, false); }, RuntimeException::class);
    eq(3, $os->change(5, 1, 'ABCDEFGHI', 3, true), 'same state');
    eq([], $api->posts, 'nothing posted for a reference mismatch or the same state');
    eq(4, $os->change(5, 1, 'ABCDEFGHI', 4, true));
    eq(1, count($api->posts));
    list($res, $xml, $params) = $api->posts[0];
    eq(['order_histories', ['id_shop' => 1, 'sendemail' => 1]], [$res, $params]);
    $h = simplexml_load_string($xml)->order_history;
    eq([5, 4], [(int)$h->id_order, (int)$h->id_order_state]);
});

/**
 * A tiny PrestaShop Webservice list resource: applies filter[...] ([a|b], %[text]%, [from,to]), sort=[id_DESC], limit and
 * display=[fields] to $rows the way PrestaShop does, and returns <$list><$item>…</$item></$list>.
 */
function fakeWsList(string $list, string $item, array $rows): Closure
{
    return function (array $params) use ($list, $item, $rows) {
        if (isset($params['id_shop']) && in_array($list, ['orders', 'customers'], true)) {   // multistore: lists of the context shop only
            $rows = array_values(array_filter($rows, function ($r) use ($params) { return (int)$r['id_shop'] === (int)$params['id_shop']; }));
        } elseif (in_array($list, ['orders', 'customers'], true)) {
            $rows = array_values(array_filter($rows, function ($r) { return (int)$r['id_shop'] === 1; }));   // default shop
        }
        foreach ($params['filter'] ?? [] as $field => $cond) {
            $rows = array_values(array_filter($rows, function ($r) use ($field, $cond) {
                $v = (string)($r[$field] ?? '');
                if (preg_match('/^%\[(.*)\]%$/u', $cond, $m)) {
                    return mb_stripos($v, $m[1], 0, 'UTF-8') !== false;
                }
                $inner = substr($cond, 1, -1);
                if (strpos($inner, ',') !== false) {
                    list($a, $b) = explode(',', $inner);
                    return $v >= $a && $v <= $b;
                }
                return in_array($v, explode('|', $inner), true);
            }));
        }
        if (($params['sort'] ?? '') === '[id_DESC]') {
            usort($rows, function ($a, $b) { return $b['id'] - $a['id']; });
        }
        if (isset($params['limit'])) {
            list($off, $n) = explode(',', $params['limit']);
            $rows = array_slice($rows, (int)$off, (int)$n);
        }
        $fields = isset($params['display']) && $params['display'] !== 'full' ? explode(',', trim($params['display'], '[]')) : null;
        $x = "<$list>";
        foreach ($rows as $r) {
            $x .= "<$item>";
            foreach ($fields ?: array_keys($r) as $f) {
                $v = $r[$f] ?? '';
                $x .= is_array($v) ? "<$f>" . implode('', array_map(function ($l, $t) { return '<language id="' . $l . '">' . htmlspecialchars($t) . '</language>'; }, array_keys($v), $v)) . "</$f>"
                    : "<$f>" . htmlspecialchars((string)$v) . "</$f>";
            }
            $x .= "</$item>";
        }
        return $x . "</$list>";
    };
}

/** Simulated PrestaShop with 3 orders in 2 shops (used by the Webservice order tests). */
function fakeOrderShop(): FakePrestaShopApi
{
    $today = date('Y-m-d');
    $orders = [
        ['id' => 10, 'reference' => 'ABCDEF', 'id_shop' => 1, 'date_add' => date('Y-m-d', strtotime('-20 days')) . ' 10:00:00', 'total_paid_tax_incl' => '19.20',
         'payment' => 'Bankový prevod', 'current_state' => 2, 'id_customer' => 5, 'id_address_invoice' => 7, 'id_currency' => 1],
        ['id' => 11, 'reference' => 'XYZXYZ', 'id_shop' => 2, 'date_add' => date('Y-m-d', strtotime('-3 days')) . ' 10:00:00', 'total_paid_tax_incl' => '5.00',
         'payment' => 'Dobierka', 'current_state' => 1, 'id_customer' => 6, 'id_address_invoice' => 8, 'id_currency' => 1],
        ['id' => 12, 'reference' => 'QQQQQQ', 'id_shop' => 1, 'date_add' => $today . ' 08:00:00', 'total_paid_tax_incl' => '1.00',
         'payment' => 'Dobierka', 'current_state' => 1, 'id_customer' => 5, 'id_address_invoice' => 7, 'id_currency' => 1],
    ];
    return new FakePrestaShopApi([
        'languages' => '<languages><language><id>1</id><iso_code>en</iso_code></language><language><id>2</id><iso_code>sk</iso_code></language></languages>',
        'shops' => fakeWsList('shops', 'shop', [['id' => 2, 'name' => 'SPZ'], ['id' => 1, 'name' => 'Howies']]),
        'order_states' => fakeWsList('order_states', 'order_state', [
            ['id' => 1, 'name' => [1 => 'Shipped', 2 => 'Odoslané'], 'color' => '#aaa', 'paid' => 0, 'shipped' => 1, 'delivery' => 0, 'deleted' => 0],
            ['id' => 2, 'name' => [1 => 'Paid', 2 => 'Platba akceptovaná'], 'color' => '#0a0', 'paid' => 1, 'shipped' => 0, 'delivery' => 0, 'deleted' => 0],
            ['id' => 9, 'name' => [1 => 'Old', 2 => 'Starý'], 'color' => '#000', 'paid' => 0, 'shipped' => 0, 'delivery' => 0, 'deleted' => 1]]),
        'currencies' => fakeWsList('currencies', 'currency', [['id' => 1, 'iso_code' => 'EUR']]),
        'countries' => fakeWsList('countries', 'country', [['id' => 37, 'iso_code' => 'SK', 'name' => [1 => 'Slovakia', 2 => 'Slovensko']]]),
        'carriers' => fakeWsList('carriers', 'carrier', [['id' => 219, 'name' => 'Packeta']]),
        'orders' => fakeWsList('orders', 'order', $orders),
        'orders/10' => '<order><id>10</id><id_address_delivery>7</id_address_delivery><id_address_invoice>7</id_address_invoice>'
            . '<id_currency>1</id_currency><id_customer>5</id_customer><id_carrier>219</id_carrier><current_state>2</current_state>'
            . '<secure_key>secret</secure_key><id_shop>1</id_shop><payment>Bankový prevod</payment><date_add>2026-10-01 10:00:00</date_add>'
            . '<total_discounts_tax_incl>0.000000</total_discounts_tax_incl><total_discounts_tax_excl>0.000000</total_discounts_tax_excl>'
            . '<total_paid_tax_incl>19.200000</total_paid_tax_incl><total_products_wt>14.300000</total_products_wt>'
            . '<total_shipping_tax_incl>4.900000</total_shipping_tax_incl><total_shipping_tax_excl>4.900000</total_shipping_tax_excl>'
            . '<carrier_tax_rate>0.000</carrier_tax_rate><total_wrapping_tax_incl>0.000000</total_wrapping_tax_incl>'
            . '<total_wrapping_tax_excl>0.000000</total_wrapping_tax_excl><reference>ABCDEF</reference>'
            . '<associations><order_rows><order_row><id>1</id></order_row></order_rows></associations></order>',
        'order_details' => fakeWsList('order_details', 'order_detail', [
            ['id' => 1, 'id_order' => 10, 'product_name' => 'Brúsny kameň', 'product_reference' => 'K1', 'product_quantity' => 1,
             'unit_price_tax_excl' => '10.000000', 'unit_price_tax_incl' => '12.300000', 'total_price_tax_excl' => '10.000000', 'total_price_tax_incl' => '12.300000'],
            ['id' => 2, 'id_order' => 10, 'product_name' => 'Šnúrky', 'product_reference' => 'S1', 'product_quantity' => 2,
             'unit_price_tax_excl' => '1.000000', 'unit_price_tax_incl' => '1.000000', 'total_price_tax_excl' => '2.000000', 'total_price_tax_incl' => '2.000000'],
            ['id' => 3, 'id_order' => 11, 'product_name' => 'Iná', 'product_reference' => '', 'product_quantity' => 1,
             'unit_price_tax_excl' => '5', 'unit_price_tax_incl' => '5', 'total_price_tax_excl' => '5', 'total_price_tax_incl' => '5']]),
        'order_histories' => fakeWsList('order_histories', 'order_history', [
            ['id' => 1, 'id_order' => 10, 'id_order_state' => 9, 'date_add' => '2026-10-01 10:00:00'],
            ['id' => 2, 'id_order' => 10, 'id_order_state' => 2, 'date_add' => '2026-10-02 10:00:00']]),
        'customers' => fakeWsList('customers', 'customer', [
            ['id' => 5, 'id_shop' => 1, 'firstname' => 'Ján', 'lastname' => 'Novák', 'email' => 'jan@example.sk', 'company' => '', 'passwd' => 'hash'],
            ['id' => 6, 'id_shop' => 2, 'firstname' => 'Eva', 'lastname' => 'Malá', 'email' => 'eva@example.sk', 'company' => 'Malá s.r.o.', 'passwd' => 'hash']]),
        'addresses' => fakeWsList('addresses', 'address', [
            ['id' => 7, 'id_customer' => 5, 'company' => '', 'firstname' => 'Ján', 'lastname' => 'Novák', 'address1' => 'Hlavná 1', 'address2' => '',
             'postcode' => '956 35', 'city' => 'Pravotice', 'phone' => '0900 000 000', 'phone_mobile' => '', 'vat_number' => '', 'dni' => '', 'id_country' => 37],
            ['id' => 8, 'id_customer' => 6, 'company' => 'Malá s.r.o.', 'firstname' => 'Eva', 'lastname' => 'Malá', 'address1' => 'Dolná 2', 'address2' => '',
             'postcode' => '811 01', 'city' => 'Bratislava', 'phone' => '', 'phone_mobile' => '', 'vat_number' => 'SK2120000000', 'dni' => '50000000', 'id_country' => 37]]),
        // no 'order_payments' → like a key without that permission
    ]);
}

test('orders through the Webservice (no database): list, filters, search, detail, stats', function () {
    $api = fakeOrderShop();
    $src = new Panel\Shop\ShopOrdersApi($api, 3, 'Vzdialený e-shop');
    eq([3, 'Vzdialený e-shop', true], [$src->id(), $src->label(), $src->available()]);
    eq([['id_shop' => 1, 'name' => 'Howies'], ['id_shop' => 2, 'name' => 'SPZ']], $src->shops());
    eq(['Odoslané', 'Platba akceptovaná'], array_column($src->states(), 'name'), 'Slovak names, deleted state left out, sorted');

    $r = $src->search([], 1, 2);
    eq(3, $r['total']);
    eq([12, 11], array_column($r['rows'], 'id_order'), 'newest first, page size');
    $row = $r['rows'][1];
    eq(['src' => 3, 'reference' => 'XYZXYZ', 'shop' => 'SPZ', 'state' => 'Odoslané', 'state_color' => '#aaa', 'firstname' => 'Eva',
        'lastname' => 'Malá', 'email' => 'eva@example.sk', 'company' => 'Malá s.r.o.', 'currency' => 'EUR'],
        array_intersect_key($row, array_flip(['src', 'reference', 'shop', 'state', 'state_color', 'firstname', 'lastname', 'email', 'company', 'currency'])));
    eq([10], array_column($src->search([], 2, 2)['rows'], 'id_order'), 'second page');
    eq([12, 10], array_column($src->search(['shop' => 1])['rows'], 'id_order'));
    eq([12, 11], array_column($src->search(['state' => 1])['rows'], 'id_order'));
    eq([12], array_column($src->search(['from' => date('Y-m-d')])['rows'], 'id_order'), 'date filter');
    eq([11], array_column($src->search(['q' => 'malá'])['rows'], 'id_order'), 'customer surname / company, case-insensitive');
    eq([12, 10], array_column($src->search(['q' => 'Ján Novák'])['rows'], 'id_order'), 'first + last name');
    eq([10], array_column($src->search(['q' => '10'])['rows'], 'id_order'), 'order id');
    eq([10], array_column($src->search(['q' => 'abcd'])['rows'], 'id_order'), 'reference');
    eq([12, 10], array_column($src->search(['q' => 'jan@example'])['rows'], 'id_order'), 'e-mail');
    eq([10], array_column($src->search(['q' => 'jan@example', 'state' => 2])['rows'], 'id_order'), 'search + filter');
    eq(3, $src->search(['q' => '[|]'])['total'], 'filter syntax characters are removed (empty search)');

    $o = $src->find(10);
    eq([10, 3, 'ABCDEF', 'Howies', 'Platba akceptovaná', 'EUR', 'Packeta', 'Ján', 'jan@example.sk'],
        [$o['id_order'], $o['src'], $o['reference'], $o['shop'], $o['state'], $o['currency'], $o['carrier'], $o['customer_firstname'], $o['customer_email']]);
    eq(false, isset($o['secure_key']), 'secure key not taken');
    eq([23.0, 0.0], array_column($o['lines'], 'tax_rate'), 'VAT rate from the line totals');
    eq(['Brúsny kameň', 'Šnúrky'], array_column($o['lines'], 'product_name'));
    eq(['Pravotice', 'Slovensko', 'SK'], [$o['address_invoice']['city'], $o['address_invoice']['country'], $o['address_invoice']['country_iso']]);
    eq($o['address_invoice'], $o['address_delivery']);
    eq(['Starý', 'Platba akceptovaná'], array_column($o['history'], 'state'), 'history also with a deleted state');
    eq([[], true], [$o['payments'], $o['payments_unavailable']], 'key without order_payments → unknown, not "no payment"');
    eq(null, $src->find(999));
    foreach ($api->gets as $g) {
        if ($g[0] === 'customers') {
            ok(($g[1]['display'] ?? '') !== 'full' && strpos($g[1]['display'], 'passwd') === false, 'customers never read in full');
        }
    }

    $st = $src->stats();
    eq([1, 2, 3, 25.2], [$st['today'], $st['week'], $st['month'], round($st['month_total'], 2)]);
    eq([['name' => 'Howies', 'orders' => 2, 'total' => 20.2], ['name' => 'SPZ', 'orders' => 1, 'total' => 5.0]],
        array_map(function ($s) { $s['total'] = round($s['total'], 2); return $s; }, $st['per_shop']));

    eq([23.0, 23.0, 7.0, 0.0, 0.0, 5.0], [Panel\Shop\ShopOrdersApi::rate(12.30, 10.00), Panel\Shop\ShopOrdersApi::rate(10.99, 8.93),
        Panel\Shop\ShopOrdersApi::rate(10.70, 10.00), Panel\Shop\ShopOrdersApi::rate(5, 5), Panel\Shop\ShopOrdersApi::rate(0, 0),
        Panel\Shop\ShopOrdersApi::rate(10.50, 10.00)]);
});

test('orders of several PrestaShops: invoice / customer / company link carry the source', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $company = ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35', 'country' => 'SK',
        'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => '', 'phone' => '', 'web' => '', 'slogan' => '',
        'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
    $cid = $companies->save(null, $company);
    $cid2 = $companies->save(null, ['name' => 'Iná firma s.r.o.'] + $company);
    $companies->saveBanks($cid, [['id' => 0, 'account_name' => 'ERMA', 'iban' => 'SK1783300000002501809819', 'bic' => '', 'is_default' => true, 'active' => true]]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '',
        'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);

    // e-shop "1" exists in this database (source 0) AND in connection 3 — two different shops
    $companies->saveShops($cid, ['0:1', '3:1', '9:2', 'bad'], [0, 3]);
    eq(['0:1' => $cid, '3:1' => $cid], $companies->shopMap(), 'only offered sources are saved');
    $companies->saveShops($cid2, ['3:1'], [3]);
    eq([$cid, $cid2], [$companies->companyForShop(0, 1), $companies->companyForShop(3, 1)], 'moved to the other company');
    $companies->saveShops($cid, [], [3]);
    eq($cid, $companies->companyForShop(0, 1), 'a source not offered in the form (unreachable shop) keeps its assignment');
    eq(0, $companies->companyForShop(5, 1));

    $o = (new Panel\Shop\ShopOrdersApi(fakeOrderShop(), 3, 'Vzdialený'))->find(10);
    $inv = new Invoices($db);
    $id = $inv->createFromOrder($o, $cid, $user);
    $doc = $inv->find($id);
    eq([3, 10, 'ABCDEF', 1], [(int)$doc['shop_connection_id'], (int)$doc['shop_order_id'], $doc['order_reference'], (int)$doc['shop_id']]);
    eq(['Brúsny kameň', 'Šnúrky', 'Doprava: Packeta'], array_column($doc['lines'], 'name'));
    eq('Ján Novák', $doc['buyer_name']);
    eq($id, (int)$inv->findByOrder(3, 10)['id']);
    eq(null, $inv->findByOrder(0, 10), 'order 10 of this database is another order');
    throws(function () use ($inv, $o, $cid, $user) { $inv->createFromOrder($o, $cid, $user); }, RuntimeException::class);
    $local = $o;
    $local['src'] = 0;
    $id2 = $inv->createFromOrder($local, $cid, $user);
    ok($id2 !== $id, 'same order number from another source = its own invoice');
    $cust = array_map(function ($r) { return array_map('intval', $r); }, $db->query('SELECT shop_connection_id, shop_customer_id FROM pt_customer ORDER BY id'));
    eq([['shop_connection_id' => 3, 'shop_customer_id' => 5], ['shop_connection_id' => 0, 'shop_customer_id' => 5]], $cust,
        'customer 5 of each shop is a different customer');
    eq(3, (int)(new Panel\Invoicing\Customers($db))->findByShopCustomer($cid, 3, 5)['shop_connection_id']);
});

/** Reads a ZIP made by Panel\Zip (local headers only): name => content. */
function unzipTest(string $zip): array
{
    $out = [];
    $pos = 0;
    while (substr($zip, $pos, 4) === "PK\x03\x04") {
        $h = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen', substr($zip, $pos, 30));
        $name = substr($zip, $pos + 30, $h['nlen']);
        $data = substr($zip, $pos + 30 + $h['nlen'] + $h['elen'], $h['csize']);
        $raw = $h['method'] === 8 ? gzinflate($data) : $data;
        ok(crc32($raw) === $h['crc'], "crc of $name");
        ok(($h['flags'] & 0x0800) !== 0, 'UTF-8 name flag');
        $out[$name] = $raw;
        $pos += 30 + $h['nlen'] + $h['elen'] + $h['csize'];
    }
    ok(substr($zip, $pos, 4) === "PK\x01\x02", 'central directory follows');
    ok(strpos($zip, "PK\x05\x06") !== false, 'end record');
    return $out;
}

test('zip: pure-PHP writer — deflate / store, CRC, UTF-8 names, central directory', function () {
    $z = new Panel\Zip();
    $z->add('a/prehľad.txt', str_repeat('účtovníctvo ', 200));
    $z->add('b/x.bin', "\x00\x01");
    $files = unzipTest($z->bytes());
    eq(['a/prehľad.txt', 'b/x.bin'], array_keys($files));
    eq(str_repeat('účtovníctvo ', 200), $files['a/prehľad.txt']);
    eq("\x00\x01", $files['b/x.bin']);
    eq(2, $z->count());
});

/** Mailer double: remembers messages, can fail. */
class TestMailer extends Panel\Mailer
{
    public $sent = [];
    public $fail = false;
    public function __construct() { parent::__construct([]); }
    public function configured(): bool { return true; }
    public function send(array $m): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP nedostupný');
        }
        $this->sent[] = $m;
    }
}

test('accounting package: content, send once, again, late documents, failure, automatic sending', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => 'info@erma.sk', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveBanks($cid, [['id' => 0, 'account_name' => 'ERMA', 'iban' => 'SK1783300000002501809819', 'bic' => '', 'is_default' => true, 'active' => true]]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'credit_note', 'name' => 'DB', 'format' => 'DB{RRRR}/{NNNN}', 'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $companies->saveExportSettings($cid, array_merge($companies->exportSettings($cid), ['accountant' => ['email' => 'uctovnik@example.sk', 'auto' => 0, 'day' => 5]]));

    $inv = new Invoices($db);
    $issue = function (string $date, float $price) use ($inv, $cid, $user) {
        $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => $date, 'delivery_date' => $date,
            'due_date' => date('Y-m-d', strtotime("$date +14 days")), 'buyer_type' => 'b2b', 'buyer_name' => 'Odberateľ, s. r. o.', 'buyer_ico' => '36841889',
            'buyer_street' => 'Hlavná 1', 'buyer_city' => 'Prievidza', 'buyer_zip' => '971 01']);
        $id = $inv->saveDraft(null, $h, [['name' => 'Tovar', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => $price, 'vat_rate' => 23, 'vat_category' => 'S']], $user);
        $inv->issue($id, $user);
        return $id;
    };
    $a = $issue('2026-10-02', 100);
    $b = $issue('2026-10-20', 50);
    $late = $issue('2026-09-28', 10);          // issued with an older date, never sent before → goes into the October package
    $next = $issue('2026-11-03', 70);          // November → not in October
    $inv->addPayment($a, '2026-10-10', 100, 'transfer', '', $user);
    $db->execute('UPDATE pt_invoice SET omega_exported_at = ? WHERE id = ?', ['2026-10-25 10:00:00', $b]);   // exported by hand before

    $acc = new Panel\Invoicing\AccountingPackage($db, $inv, $companies, function (array $d) { return '%PDF-' . $d['number']; });
    $p = $acc->build($cid, '2026-10');
    $files = unzipTest($p['zip']);
    $base = 'erma-group-s-r-o-2026-10/';
    ok(isset($files[$base . 'omega-2026-10.txt']), 'Omega file');
    ok(isset($files[$base . 'faktury/FA2026-000001.pdf']) && isset($files[$base . 'faktury/FA2026-000003.pdf']), 'PDFs incl. the late one');
    ok(!isset($files[$base . 'faktury/FA2026-000004.pdf']), 'November invoice not in October');
    eq([$a, $b, $late], array_map('intval', array_values(array_intersect([$a, $b, $late, $next], $p['doc_ids']))));
    eq(3, count($p['doc_ids']));
    sort($p['omega_ids']);
    $exp = [$a, $late];
    sort($exp);
    eq($exp, $p['omega_ids'], 'already exported document is not in the Omega file again');
    $csv = $files[$base . 'prehlad-dokladov-2026-10.csv'];
    ok(strpos($csv, "\xEF\xBB\xBF") === 0, 'CSV with BOM for Excel');
    eq(4, substr_count($csv, "\r\n"), 'header + 3 documents');
    contains('nie — exportovaný už 25.10.2026', $csv);
    contains('FA2026/000001;Faktúra', $csv);
    contains('100,00', $files[$base . 'uhrady-2026-10.csv']);
    contains('Import z textového súboru', $files[$base . 'NAVOD.txt']);
    eq(0, (int)$db->fetchValue('SELECT COUNT(*) FROM pt_invoice WHERE accounting_package_id IS NOT NULL'), 'build/download marks nothing');

    // failure: nothing assigned, can be sent later
    $mail = new TestMailer();
    $mail->fail = true;
    $r = $acc->send($cid, '2026-10', $mail, $user);
    ok(!$r['ok']);
    contains('SMTP nedostupný', $r['message']);
    eq('failed', $acc->find($cid, '2026-10')['status']);
    eq(0, (int)$db->fetchValue('SELECT COUNT(*) FROM pt_invoice WHERE accounting_package_id IS NOT NULL'));

    $mail->fail = false;
    $r = $acc->send($cid, '2026-10', $mail, $user);
    ok($r['ok'], $r['message']);
    eq(1, count($mail->sent));
    eq(['uctovnik@example.sk'], $mail->sent[0]['to']);
    eq('Doklady za október 2026 — ERMA Group s.r.o.', $mail->sent[0]['subject']);
    contains('- faktúry: 3', $mail->sent[0]['text']);
    eq('erma-group-s-r-o-2026-10.zip', $mail->sent[0]['attachments'][0][0]);
    $pkg = $acc->find($cid, '2026-10');
    eq('sent', $pkg['status']);
    eq(3, (int)$db->fetchValue('SELECT COUNT(*) FROM pt_invoice WHERE accounting_package_id = ?', [(int)$pkg['id']]));
    ok($inv->find($late)['omega_exported_at'] !== null, 'marked as exported to Omega');

    // the same month again: refused without "again"; with "again" exactly the same content
    $r = $acc->send($cid, '2026-10', $mail, $user);
    ok(!$r['ok']);
    contains('už bol odoslaný', $r['message']);
    $r = $acc->send($cid, '2026-10', $mail, $user, 'manual', true);
    ok($r['ok'], $r['message']);
    eq($mail->sent[0]['attachments'][0][1], $mail->sent[1]['attachments'][0][1], 'resent package identical');

    // automatic: off → nothing; on, before the day → nothing; from the day → November package (October already sent)
    eq([], $acc->sendDue($mail, '2026-12-07'));
    $companies->saveExportSettings($cid, array_merge($companies->exportSettings($cid), ['accountant' => ['email' => 'uctovnik@example.sk', 'auto' => 1, 'day' => 5]]));
    eq([], $acc->sendDue($mail, '2026-12-04'));
    $r = $acc->sendDue($mail, '2026-12-05');
    eq(1, count($r));
    ok($r[0]['ok'], $r[0]['message']);
    $nov = json_decode($acc->find($cid, '2026-11')['doc_ids'], true);
    eq([$next], array_map('intval', $nov), 'only the November invoice');
    eq('auto', $acc->find($cid, '2026-11')['trigger']);
    eq([], $acc->sendDue($mail, '2026-12-06'), 'sent once');
    // an empty month: e-mail without attachment
    $companies->saveExportSettings($cid, array_merge($companies->exportSettings($cid), ['accountant' => ['email' => 'uctovnik@example.sk', 'auto' => 1, 'day' => 1]]));
    $r = $acc->sendDue($mail, '2027-01-02');
    ok($r[0]['ok']);
    $last = end($mail->sent);
    eq([], $last['attachments']);
    contains('nie sú žiadne vystavené ani prijaté doklady', $last['text']);
    ok(count(Panel\Audit::forEntity($db, 'company', $cid)) >= 4, 'audit of the packages');

    // settings validation
    eq('Na automatické posielanie zadajte e-mail účtovníka.', Panel\Invoicing\AccountingPackage::readSettings(['acc_auto' => 1])[1]['accountant']);
    eq(28, Panel\Invoicing\AccountingPackage::readSettings(['acc_day' => 31])[0]['day']);
    eq('október 2026', Panel\Invoicing\AccountingPackage::label('2026-10'));
    eq(['1 doklad', '3 doklady', '5 dokladov', '0 dokladov'], array_map(['Panel\Invoicing\AccountingPackage', 'docs'], [1, 3, 5, 0]));
    eq('2026-12', Panel\Invoicing\AccountingPackage::previousPeriod('2027-01-15'));
});

test('received documents (Náklady): card = paid, duplicates, sums, overdue, cancel, files, categories, accountant package', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => 'info@erma.sk', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveExportSettings($cid, array_merge($companies->exportSettings($cid), ['accountant' => ['email' => 'uctovnik@example.sk', 'auto' => 0, 'day' => 5]]));
    $x = new Panel\Expenses\Expenses($db);
    eq(12, count($x->categories()), 'seeded categories');
    $doc = function (array $v) use ($cid) {
        return array_merge(array_fill_keys(Panel\Expenses\Expenses::FIELDS, ''), ['company_id' => $cid, 'shop_id' => null, 'category_id' => 2,
            'doc_type' => 'invoice', 'supplier_name' => 'Packeta Slovakia s.r.o.', 'supplier_ico' => '48136999', 'supplier_country' => 'SK',
            'document_number' => '2026001', 'issue_date' => '2026-10-05', 'delivery_date' => '2026-10-05', 'due_date' => '2026-10-19',
            'currency' => 'EUR', 'total_net' => 120, 'total_vat' => 0, 'total' => 120, 'payment_method' => 'transfer', 'note' => ''], $v);
    };
    $a = $x->save(null, $doc([]), $user);
    $b = $x->save(null, $doc(['supplier_name' => 'Tesco', 'supplier_ico' => '', 'document_number' => 'BL-1', 'category_id' => 12,
        'payment_method' => 'card', 'issue_date' => '2026-10-12', 'delivery_date' => '2026-10-12', 'due_date' => null, 'total' => 15.5, 'total_net' => 15.5]), $user);
    $nov = $x->save(null, $doc(['document_number' => '2026002', 'issue_date' => '2026-11-04', 'delivery_date' => '2026-11-04', 'due_date' => '2026-11-18']), $user);
    eq('open', $x->find($a)['status']);
    eq('paid', $x->find($b)['status'], 'card = paid');
    eq('2026-10-12', $x->find($b)['paid_on']);

    // duplicate: same supplier (by IČO) + number; another number or itself is not a duplicate
    eq($a, (int)$x->duplicate($cid, '48136999', 'x', '2026001', null)['id']);
    eq(null, $x->duplicate($cid, '48136999', 'x', '2026001', $a));
    eq(null, $x->duplicate($cid, '48136999', 'x', '2026999', null));
    eq($b, (int)$x->duplicate($cid, '', 'Tesco', 'BL-1', null)['id'], 'without IČO by name');

    // list: sums, overdue, search
    $all = $x->search(['companies' => [$cid]]);
    eq(3, $all['total']);
    eq(255.5, (float)$all['sums']['total']);
    eq(240.0, (float)$all['sums']['open']);
    $db->execute('UPDATE pt_expense SET due_date = ? WHERE id = ?', [date('Y-m-d', strtotime('+10 days')), $nov]);
    eq(1, $x->search(['companies' => [$cid], 'status' => 'overdue'])['total'], 'open and past due');
    eq(1, $x->search(['companies' => [$cid], 'q' => 'tesco'])['total']);
    eq(2, $x->search(['companies' => [$cid], 'from' => '2026-10-01', 'to' => '2026-10-31'])['total']);
    eq(0, $x->search(['companies' => [999]])['total'], 'other company');

    // paid / reopened / cancelled (cancelled not in the default list, not in the package)
    $x->markPaid($a, '2026-10-18', 'výpis 10/2026', $user);
    eq('paid', $x->find($a)['status']);
    $x->markOpen($a, $user);
    eq(['open', null], [$x->find($a)['status'], $x->find($a)['paid_on']]);
    $c = $x->save(null, $doc(['document_number' => 'DUP']), $user);
    $x->cancel($c, 'zadaný dvakrát', $user);
    eq('cancelled', $x->find($c)['status']);
    eq(3, $x->search(['companies' => [$cid]])['total']);
    eq(1, $x->search(['companies' => [$cid], 'status' => 'cancelled'])['total']);
    $actions = array_column($x->history($a), 'action');
    ok(in_array('paid', $actions, true) && in_array('unpaid', $actions, true) && in_array('create', $actions, true), 'history');

    // files
    $fid = $x->addFile($a, 'faktura packeta.pdf', 'application/pdf', '%PDF-1.4 packeta', $user);
    eq('%PDF-1.4 packeta', $x->file($fid)['data']);
    eq(hash('sha256', '%PDF-1.4 packeta'), $x->find($a)['files'][0]['sha256']);

    // categories: rename, deactivate, add; nothing deleted
    $rows = array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'active' => 1]; }, $x->categories());
    $rows[0]['name'] = 'Tovar na predaj';
    $rows[11]['active'] = 0;
    $rows[] = ['id' => '', 'name' => 'Provízie marketplace', 'kind' => 'order'];
    $rows[] = ['id' => '', 'name' => '', 'kind' => 'order'];
    $x->saveCategories($rows, $user);
    eq(13, count($x->categories()));
    eq(12, count($x->categories(true)));
    eq('Tovar na predaj', $x->categories()[0]['name']);

    // accountant's package: October received documents with their files + list; problem for the one without a file
    $acc = (new Panel\Invoicing\AccountingPackage($db, new Invoices($db), $companies, function (array $d) { return '%PDF'; }))->withExpenses($x);
    $p = $acc->build($cid, '2026-10');
    $files = unzipTest($p['zip']);
    $base = 'erma-group-s-r-o-2026-10/';
    eq('%PDF-1.4 packeta', $files[$base . 'prijate/2026-10-05_Packeta-Slovakia-s-r-o-2026001.pdf'] ?? null, 'file of the received document');
    $csv = $files[$base . 'prijate-doklady-2026-10.csv'];
    eq(3, substr_count($csv, "\r\n"), 'header + 2 documents (cancelled and November left out)');
    contains('Packeta Slovakia s.r.o.;48136999', $csv);
    contains('Tesco', $csv);
    eq(1, count($p['problems']));
    contains('Prijatý doklad bez súboru: Tesco BL-1', $p['problems'][0]);
    $ids = $p['expense_ids'];
    sort($ids);
    eq([$a, $b], $ids);

    $mail = new TestMailer();
    $r = $acc->send($cid, '2026-10', $mail, $user);
    ok($r['ok'], $r['message']);
    contains('prijaté: 2 doklady', $r['message']);
    contains('- prijaté doklady (náklady): 2 (spolu 135,50 EUR)', $mail->sent[0]['text']);
    $pkg = $acc->find($cid, '2026-10');
    eq(2, count(json_decode($pkg['expense_ids'], true)));
    eq((int)$pkg['id'], (int)$x->find($a)['accounting_package_id']);
    eq(null, $x->find($nov)['accounting_package_id']);
    // a document entered later for October goes into the next package, the sent one stays the same
    $lateExp = $x->save(null, $doc(['document_number' => 'LATE', 'delivery_date' => '2026-10-30']), $user);
    $r = $acc->send($cid, '2026-10', $mail, $user, 'manual', true);
    eq($mail->sent[0]['attachments'][0][1], $mail->sent[1]['attachments'][0][1], 'resent package identical');
    $novIds = $acc->build($cid, '2026-11')['expense_ids'];
    sort($novIds);
    eq([$nov, $lateExp], $novIds, 'late October document in the November package');

    // form helpers
    $C = 'Panel\Controller\ExpensesController';
    eq([1234.5, 12.5, 12.5, 1234.56, null, null], [$C::amount('1 234,50'), $C::amount('12,5'), $C::amount('12.50 €'), $C::amount('1.234,56'), $C::amount('abc'), $C::amount('1,234')]);
    eq(['2026-11-20', '2026-02-03', null, null], [$C::date('20.11.2026'), $C::date('2026-02-03'), $C::date('31.2.2026'), $C::date('x')]);
    eq(['application/pdf', 'image/jpeg', 'image/png', 'application/xml', 'application/octet-stream'],
        [$C::mime('%PDF-1.7'), $C::mime("\xFF\xD8\xFF\xE0"), $C::mime("\x89PNG\r\n"), $C::mime("\xEF\xBB\xBF<?xml version=\"1.0\"?><Invoice/>"), $C::mime('MZ...')]);
});

test('received documents C2: payment from the bank statement (VS / IBAN), new cost from a movement, recurring costs', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => 'info@erma.sk', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $x = new Panel\Expenses\Expenses($db);
    $doc = function (array $v) use ($cid) {
        return array_merge(array_fill_keys(Panel\Expenses\Expenses::FIELDS, ''), ['company_id' => $cid, 'shop_id' => null, 'category_id' => 6,
            'doc_type' => 'invoice', 'supplier_name' => 'Prenajímateľ s.r.o.', 'supplier_country' => 'SK', 'document_number' => 'F1',
            'issue_date' => '2026-11-01', 'delivery_date' => '2026-11-01', 'due_date' => '2026-11-15', 'currency' => 'EUR',
            'total_net' => 300, 'total_vat' => 0, 'total' => 300, 'payment_method' => 'transfer', 'note' => ''], $v);
    };
    $rent = $x->save(null, $doc(['variable_symbol' => '2026011', 'supplier_iban' => 'SK3112000000198742637541']), $user);
    $phone = $x->save(null, $doc(['supplier_name' => 'Telekom', 'document_number' => 'T1', 'total' => 25.9, 'total_net' => 25.9,
        'supplier_iban' => 'SK0809000000000123123123', 'category_id' => 7]), $user);
    $open = $x->openDocuments($cid);
    eq(2, count($open));
    $M = 'Panel\Expenses\Expenses';
    $mv = function (float $amount, string $vs = '', string $iban = '', string $ref = 'R1') {
        return ['ref' => $ref, 'date' => '2026-11-14', 'amount' => $amount, 'currency' => 'EUR', 'vs' => $vs, 'ss' => '', 'ks' => '',
                'payer' => 'Prenajímateľ s.r.o.', 'payer_iban' => $iban, 'message' => 'nájom 11/2026'];
    };
    eq([$rent, 'vs'], (function ($m) { return [(int)$m['id'], $m['by']]; })($M::matchPayment($mv(-300, '0002026011'), $open)), 'by VS (leading zeros)');
    eq($rent, (int)$M::matchPayment($mv(-280, '2026011'), $open)['id'], 'VS with another amount still proposed');
    eq([$phone, 'iban'], (function ($m) { return [(int)$m['id'], $m['by']]; })($M::matchPayment($mv(-25.9, '', 'SK08 0900 0000 0001 2312 3123'), $open)), 'by IBAN + amount');
    eq(null, $M::matchPayment($mv(-25, '', 'SK0809000000000123123123'), $open), 'IBAN with another amount: no proposal');
    eq(null, $M::matchPayment($mv(300, '2026011'), $open), 'incoming payment is not a cost');

    ok($x->payFromBank($rent, '2026-11-14', 'R1', 'výpis', $user));
    eq(['paid', '2026-11-14', 'R1'], [$x->find($rent)['status'], $x->find($rent)['paid_on'], $x->find($rent)['bank_ref']]);
    ok(!$x->payFromBank($rent, '2026-11-14', 'R9', '', $user), 'not open any more');
    throws(function () use ($x, $phone, $user) { $x->payFromBank($phone, '2026-11-14', 'R1', '', $user); }, PDOException::class);
    eq(['R1'], $x->importedRefs(['R1', 'R2', '']));
    $x->markOpen($rent, $user);
    eq([], $x->importedRefs(['R1']), 'reopened document frees the movement');

    $fee = $x->createFromBank($cid, ['ref' => 'FEE-1', 'date' => '2026-11-30', 'amount' => -2.5, 'currency' => 'EUR', 'vs' => '', 'payer' => '',
        'payer_iban' => '', 'message' => 'Poplatok za vedenie účtu'], 3, $user);
    $f = $x->find($fee);
    eq(['paid', 'FEE-1', 2.5, 'Poplatok za vedenie účtu', 'other'], [$f['status'], $f['bank_ref'], (float)$f['total'], $f['supplier_name'], $f['doc_type']]);

    // recurring: last day of the month stays the last day, catch-up of missed months, end date, parallel safety
    eq(['2026-02-28', '2026-03-31', '2027-02-15', '2027-01-10', '2028-02-29'], [$M::addMonths('2026-01-31', 1, 31), $M::addMonths('2026-02-28', 1, 31),
        $M::addMonths('2026-11-15', 3, 15), $M::addMonths('2026-12-10', 1, 10), $M::addMonths('2027-02-28', 12, 31)]);
    $rec = function (array $v) use ($cid) {
        return array_merge(array_fill_keys(Panel\Expenses\Expenses::RECURRING_FIELDS, ''), ['company_id' => $cid, 'shop_id' => null,
            'category_id' => 6, 'name' => 'Nájom skladu', 'supplier_name' => 'Prenajímateľ s.r.o.', 'supplier_country' => 'SK', 'currency' => 'EUR',
            'total' => 300, 'total_vat' => 0, 'payment_method' => 'transfer', 'interval_months' => 1, 'due_days' => 10, 'day' => 31,
            'next_date' => '2026-09-30', 'end_date' => '2026-11-30', 'active' => 1, 'note' => ''], $v);
    };
    $r1 = $x->saveRecurring(null, $rec([]), $user);
    $r2 = $x->saveRecurring(null, $rec(['name' => 'Hosting', 'payment_method' => 'card', 'total' => 12, 'interval_months' => 12, 'day' => 5,
        'next_date' => '2026-12-05', 'end_date' => null]), $user);
    $made = $x->generateRecurring('2026-11-20');
    eq(2, count($made), 'September and October rent (November not yet)');
    $docs = array_map([$x, 'find'], $made);
    eq(['2026-09-30', '2026-10-31'], array_column($docs, 'delivery_date'));
    eq('2026-10-10', $docs[0]['due_date']);
    eq([$r1, $r1], array_map('intval', array_column($docs, 'recurring_id')));
    eq(['open', 'open'], array_column($docs, 'status'));
    contains('Opakovaný náklad „Nájom skladu“', $docs[0]['note']);
    eq('2026-11-30', $x->recurring($r1)['next_date']);
    eq([], $x->generateRecurring('2026-11-20'), 'nothing twice');
    $made = $x->generateRecurring('2026-12-05');
    eq(2, count($made), 'November rent + hosting');
    eq(0, (int)$x->recurring($r1)['active'], 'ended after the end date');
    eq('2027-12-05', $x->recurring($r2)['next_date']);
    $hosting = $x->find($made[1]);
    eq(['paid', '2026-12-05'], [$hosting['status'], $hosting['paid_on']], 'card = paid');
    eq([], $x->generateRecurring('2027-06-01'));
    // the accountant's automatic package creates the due recurring documents first
    $x->saveRecurring($r1, array_merge($x->recurring($r1), ['active' => 1, 'next_date' => '2026-12-31', 'end_date' => null]), $user);
    $acc = (new Panel\Invoicing\AccountingPackage($db, new Invoices($db), $companies, function () { return '%PDF'; }))->withExpenses($x);
    $acc->sendDue(new TestMailer(), '2027-01-02');
    eq('2027-01-31', $x->recurring($r1)['next_date'], 'sendDue generated December');
});

test('order costs (module D): most specific valid rule, % + fixed, contribution margin in the sales report, rule editing', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $pdo = $db->pdo();
    $t = 'tsc_';
    $tables = ['orders', 'order_detail', 'product', 'product_shop', 'product_attribute', 'shop', 'category_lang', 'lang', 'carrier'];
    foreach ($tables as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    $pdo->exec("CREATE TABLE {$t}shop (id_shop INT PRIMARY KEY, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}lang (id_lang INT PRIMARY KEY, iso_code VARCHAR(2))");
    $pdo->exec("CREATE TABLE {$t}category_lang (id_category INT, id_shop INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}product (id_product INT PRIMARY KEY, wholesale_price DECIMAL(20,6), id_category_default INT, is_virtual TINYINT)");
    $pdo->exec("CREATE TABLE {$t}product_shop (id_product INT, id_shop INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}product_attribute (id_product_attribute INT PRIMARY KEY, id_product INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}carrier (id_carrier INT PRIMARY KEY, id_reference INT, name VARCHAR(64), deleted TINYINT)");
    $pdo->exec("CREATE TABLE {$t}orders (id_order INT PRIMARY KEY, id_shop INT, id_carrier INT, module VARCHAR(64), payment VARCHAR(64), reference VARCHAR(9),
        date_add DATETIME, valid TINYINT, conversion_rate DECIMAL(13,6), total_paid_tax_incl DECIMAL(20,6),
        total_discounts_tax_excl DECIMAL(20,6), total_discounts_tax_incl DECIMAL(20,6), total_shipping_tax_excl DECIMAL(20,6), total_shipping_tax_incl DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}order_detail (id_order_detail INT AUTO_INCREMENT PRIMARY KEY, id_order INT, product_id INT, product_attribute_id INT,
        product_name VARCHAR(64), product_reference VARCHAR(32), product_quantity INT, product_quantity_refunded INT, product_quantity_return INT,
        total_price_tax_excl DECIMAL(20,6), total_price_tax_incl DECIMAL(20,6), original_wholesale_price DECIMAL(20,6), purchase_supplier_price DECIMAL(20,6))");
    $pdo->exec("INSERT INTO {$t}shop VALUES (1, 'Howies'), (2, 'SPZ')");
    $pdo->exec("INSERT INTO {$t}lang VALUES (1, 'sk')");
    $pdo->exec("INSERT INTO {$t}product VALUES (1, 0, 10, 0)");
    // Packeta edited in PrestaShop → new id_carrier, same id_reference
    $pdo->exec("INSERT INTO {$t}carrier VALUES (5, 5, 'Packeta (old)', 1), (9, 5, 'Packeta', 0), (7, 7, 'Slovenská pošta', 0)");
    $pdo->exec("INSERT INTO {$t}orders VALUES
        (1, 1, 5, 'prestashopgopay', 'GoPay', 'A', '2026-10-05 10:00:00', 1, 1, 50.00, 0, 0, 3.00, 3.00),
        (2, 1, 9, 'erma_cod', 'Dobierka', 'B', '2026-11-05 10:00:00', 1, 1, 100.00, 0, 0, 4.00, 4.00),
        (3, 2, 7, 'ps_wirepayment', 'Prevod', 'C', '2026-11-06 10:00:00', 1, 1, 20.00, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO {$t}order_detail (id_order, product_id, product_attribute_id, product_name, product_reference, product_quantity,
        product_quantity_refunded, product_quantity_return, total_price_tax_excl, total_price_tax_incl, original_wholesale_price, purchase_supplier_price) VALUES
        (1, 1, 0, 'Páska', 'P1', 1, 0, 0, 47.00, 47.00, 20.00, 0), (2, 1, 0, 'Páska', 'P1', 2, 0, 0, 96.00, 96.00, 20.00, 0),
        (3, 1, 0, 'Páska', 'P1', 1, 0, 0, 20.00, 20.00, 10.00, 0)");

    $oc = new Panel\Reports\OrderCosts($db, $t);
    eq(['5' => 'Packeta', '7' => 'Slovenská pošta'], array_map('strval', $oc->carriers()), 'by id_reference, current name');
    $mods = array_keys($oc->paymentModules());
    sort($mods);
    eq(['erma_cod', 'prestashopgopay', 'ps_wirepayment'], $mods);
    $row = function (array $v) { return array_merge(['id' => '', 'shop_id' => '', 'type' => 'shipping', 'match_key' => '', 'fixed' => '', 'percent' => '',
        'valid_from' => '', 'valid_to' => '', 'note' => ''], $v); };
    $err = $oc->save([$row(['fixed' => '3,10', 'type' => 'x']), $row(['fixed' => 'abc']), $row(['fixed' => '1', 'shop_id' => '3'])], [1, 2], true, $user);
    eq(3, count($err), 'type, number, shop access');
    eq(['Riadok 1: zvoľte druh nákladu.'], array_slice($err, 0, 1));
    eq(['Riadok 1: k tomuto e-shopu nemáte prístup.'], $oc->save([$row(['fixed' => '1'])], [1], false, $user), 'global rule needs admin');
    eq([], $oc->save([
        $row(['fixed' => '2,90', 'match_key' => '5', 'label' => 'Packeta', 'valid_to' => '2026-10-31']),
        $row(['fixed' => '3,20', 'match_key' => '5', 'label' => 'Packeta', 'valid_from' => '2026-11-01']),     // price change from November
        $row(['fixed' => '4,50']),                                                                                // any carrier
        $row(['type' => 'payment', 'match_key' => 'prestashopgopay', 'percent' => '2,2', 'fixed' => '0,10']),
        $row(['type' => 'payment', 'match_key' => 'erma_cod', 'fixed' => '1']),
        $row(['type' => 'packaging', 'fixed' => '0,50']),
        $row(['type' => 'packaging', 'fixed' => '0,30', 'shop_id' => '2']),                                       // SPZ: envelope only
        $row(['fixed' => '', 'percent' => '']),                                                                    // empty new row: ignored
    ], [1, 2], true, $user));
    eq(7, count($oc->rules()));
    $o1 = $oc->forOrder(['id_shop' => 1, 'date' => '2026-10-05', 'carrier' => '5', 'module' => 'prestashopgopay', 'total' => 50]);
    eq(['2.90', '1.20', '0.50'], [number_format($o1['shipping'], 2), number_format($o1['payment'], 2), number_format($o1['packaging'], 2)], 'October price, 2.2 % of 50 + 0.10');
    $o2 = $oc->forOrder(['id_shop' => 1, 'date' => '2026-11-05', 'carrier' => '5', 'module' => 'erma_cod', 'total' => 100]);
    eq(['3.20', '1.00', '0.50'], [number_format($o2['shipping'], 2), number_format($o2['payment'], 2), number_format($o2['packaging'], 2)], 'November price');
    $o3 = $oc->forOrder(['id_shop' => 2, 'date' => '2026-11-06', 'carrier' => '7', 'module' => 'ps_wirepayment', 'total' => 20]);
    eq(['4.50', '0.00', '0.30'], [number_format($o3['shipping'], 2), number_format($o3['payment'], 2), number_format($o3['packaging'], 2)], 'generic carrier, e-shop packaging');
    ok(!$o3['no_shipping']);

    // sales report: contribution = margin + shipping − order costs (month / shop rows and the total)
    $r = new Panel\Reports\SalesReport($db, $t, 'sk');
    $d = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'shop'], function () { return false; }, $oc->loader());
    $s = $d['summary'];
    ok($d['costs']);
    eq('93.00', number_format($s['margin'], 2, '.', ''), '(47 − 20) + (96 − 40) + (20 − 10)');
    eq('10.60', number_format($s['ship_cost'], 2, '.', ''), '2.90 + 3.20 + 4.50');
    eq('2.20', number_format($s['pay_cost'], 2, '.', ''));
    eq('1.30', number_format($s['pack_cost'], 2, '.', ''));
    eq('85.90', number_format($s['contribution'], 2, '.', ''), '93 + 7 shipping paid − 14.10');
    $byShop = array_column($d['rows'], null, 'label');
    eq('80.70', number_format($byShop['Howies']['contribution'], 2, '.', ''), '27 + 56 + 7 − (2.90 + 1.20 + 0.50) − (3.20 + 1 + 0.50)');
    eq('5.20', number_format($byShop['SPZ']['contribution'], 2, '.', ''), '10 − 4.50 − 0.30');
    $p = $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'product'], function () { return false; }, $oc->loader());
    eq(0.0, $p['rows'][0]['order_costs'], 'per order: not in product rows');
    eq('85.90', number_format($p['summary']['contribution'], 2, '.', ''), 'but in the total');
    ok(!$r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'month'], function () { return false; })['costs'], 'without rules');
    // delete a rule → order without a shipping rule is counted
    $generic = array_values(array_filter($oc->rules(), function ($x) { return $x['type'] === 'shipping' && $x['match_key'] === ''; }))[0];
    eq([], $oc->save([['id' => $generic['id'], 'delete' => 1]], [1, 2], true, $user));
    eq(1, $r->compute(['from' => '2026-10-01', 'to' => '2026-11-30', 'group' => 'month'], function () { return false; }, $oc->loader())['summary']['no_ship_rule']);
    foreach ($tables as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
});

test('company result, money, VAT registration turnover, todo, monthly summary text (modules E + F)', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $pdo = $db->pdo();
    $t = 'tbr_';
    $tables = ['orders', 'order_detail', 'product', 'product_shop', 'product_attribute', 'shop', 'category_lang', 'lang', 'carrier'];
    foreach ($tables as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
    $pdo->exec("CREATE TABLE {$t}shop (id_shop INT PRIMARY KEY, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}lang (id_lang INT PRIMARY KEY, iso_code VARCHAR(2))");
    $pdo->exec("CREATE TABLE {$t}category_lang (id_category INT, id_shop INT, id_lang INT, name VARCHAR(64))");
    $pdo->exec("CREATE TABLE {$t}product (id_product INT PRIMARY KEY, wholesale_price DECIMAL(20,6), id_category_default INT, is_virtual TINYINT)");
    $pdo->exec("CREATE TABLE {$t}product_shop (id_product INT, id_shop INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}product_attribute (id_product_attribute INT PRIMARY KEY, id_product INT, wholesale_price DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}carrier (id_carrier INT PRIMARY KEY, id_reference INT, name VARCHAR(64), deleted TINYINT)");
    $pdo->exec("CREATE TABLE {$t}orders (id_order INT PRIMARY KEY, id_shop INT, id_carrier INT, module VARCHAR(64), payment VARCHAR(64), reference VARCHAR(9),
        date_add DATETIME, valid TINYINT, conversion_rate DECIMAL(13,6), total_paid_tax_incl DECIMAL(20,6),
        total_discounts_tax_excl DECIMAL(20,6), total_discounts_tax_incl DECIMAL(20,6), total_shipping_tax_excl DECIMAL(20,6), total_shipping_tax_incl DECIMAL(20,6))");
    $pdo->exec("CREATE TABLE {$t}order_detail (id_order_detail INT AUTO_INCREMENT PRIMARY KEY, id_order INT, product_id INT, product_attribute_id INT,
        product_name VARCHAR(64), product_reference VARCHAR(32), product_quantity INT, product_quantity_refunded INT, product_quantity_return INT,
        total_price_tax_excl DECIMAL(20,6), total_price_tax_incl DECIMAL(20,6), original_wholesale_price DECIMAL(20,6), purchase_supplier_price DECIMAL(20,6))");
    $pdo->exec("INSERT INTO {$t}shop VALUES (1, 'Howies'), (2, 'Iná firma')");
    $pdo->exec("INSERT INTO {$t}lang VALUES (1, 'sk')");
    $pdo->exec("INSERT INTO {$t}product VALUES (1, 0, 10, 0)");
    $pdo->exec("INSERT INTO {$t}carrier VALUES (1, 1, 'Packeta', 0)");
    $pdo->exec("INSERT INTO {$t}orders VALUES
        (1, 1, 1, 'cod', 'Dobierka', 'A', '2026-01-10 10:00:00', 1, 1, 105, 0, 0, 5, 5),
        (2, 1, 1, 'cod', 'Dobierka', 'B', '2026-02-10 10:00:00', 1, 1, 200, 0, 0, 0, 0),
        (3, 2, 1, 'cod', 'Dobierka', 'C', '2026-01-12 10:00:00', 1, 1, 999, 0, 0, 0, 0)");
    $pdo->exec("INSERT INTO {$t}order_detail (id_order, product_id, product_attribute_id, product_name, product_reference, product_quantity,
        product_quantity_refunded, product_quantity_return, total_price_tax_excl, total_price_tax_incl, original_wholesale_price, purchase_supplier_price) VALUES
        (1, 1, 0, 'Páska', 'P1', 1, 0, 0, 100, 100, 40, 0), (2, 1, 0, 'Páska', 'P1', 2, 0, 0, 200, 200, 40, 0), (3, 1, 0, 'Páska', 'P1', 1, 0, 0, 999, 999, 1, 0)");

    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '53036875',
        'dic' => '', 'registration' => '', 'email' => 'info@erma.sk', 'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '',
        'accent_color' => '#f74040', 'active' => 1]);
    $inv = function (array $v) use ($db, $cid) {
        $v += ['company_id' => $cid, 'doc_type' => 'invoice', 'status' => 'issued', 'currency' => 'EUR', 'total_vat' => 0, 'shop_order_id' => null,
               'shop_connection_id' => 0, 'shop_id' => null, 'buyer_name' => 'Odberateľ', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
        $v['total'] = $v['total_net'];
        $v['issue_date'] = $v['delivery_date'];
        $db->execute('INSERT INTO pt_invoice (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
        return (int)$db->lastInsertId();
    };
    $pay = function (int $id, string $date, float $amount) use ($db) {
        $db->execute('INSERT INTO pt_invoice_payment (invoice_id, paid_on, amount, created_at) VALUES (?, ?, ?, ?)', [$id, $date, $amount, $date . ' 00:00:00']);
    };
    $a = $inv(['number' => 'FA1', 'delivery_date' => '2026-01-10', 'due_date' => '2026-01-24', 'total_net' => 105, 'shop_order_id' => 1, 'shop_id' => 1]);
    $b = $inv(['number' => 'FA2', 'delivery_date' => '2026-01-20', 'due_date' => '2026-02-03', 'total_net' => 300]);
    $inv(['number' => 'DB1', 'doc_type' => 'credit_note', 'delivery_date' => '2026-02-05', 'due_date' => '2026-02-19', 'total_net' => -50]);
    $inv(['number' => 'FA3', 'delivery_date' => '2026-02-07', 'due_date' => '2026-02-21', 'total_net' => 80, 'currency' => 'USD']);
    $inv(['number' => '', 'status' => 'draft', 'delivery_date' => '2026-02-08', 'due_date' => '2026-02-22', 'total_net' => 999]);
    $pay($a, '2026-01-15', 105);
    $pay($b, '2026-02-01', 100);

    $x = new Panel\Expenses\Expenses($db);
    $doc = function (array $v) use ($cid) {
        return array_merge(array_fill_keys(Panel\Expenses\Expenses::FIELDS, ''), ['company_id' => $cid, 'shop_id' => null, 'doc_type' => 'invoice',
            'supplier_name' => 'Dodávateľ', 'supplier_country' => 'SK', 'document_number' => 'X', 'issue_date' => null, 'due_date' => null, 'currency' => 'EUR',
            'total_vat' => 0, 'payment_method' => 'transfer', 'note' => ''], $v, ['total_net' => $v['total']]);
    };
    $rent = $x->save(null, $doc(['category_id' => 6, 'delivery_date' => '2026-01-31', 'total' => 60]), $user);
    $packeta = $x->save(null, $doc(['category_id' => 2, 'delivery_date' => '2026-01-31', 'total' => 7]), $user);
    $x->save(null, $doc(['category_id' => 1, 'delivery_date' => '2026-02-15', 'due_date' => '2026-03-25', 'total' => 500]), $user);
    $x->save(null, $doc(['category_id' => 5, 'shop_id' => 1, 'delivery_date' => '2026-02-20', 'due_date' => '2026-03-01', 'total' => 20, 'document_number' => '']), $user);
    $x->cancel($x->save(null, $doc(['category_id' => 6, 'delivery_date' => '2026-01-15', 'total' => 1000]), $user), 'omyl', $user);
    $x->markPaid($rent, '2026-01-31', '', $user);
    $x->markPaid($packeta, '2026-02-15', '', $user);

    $oc = new Panel\Reports\OrderCosts($db, $t);
    eq([], $oc->save([['id' => '', 'shop_id' => '', 'type' => 'shipping', 'match_key' => '', 'fixed' => '3', 'percent' => '']], [1], true, $user));
    $sales = new Panel\Reports\SalesReport($db, $t, 'sk');
    $res = new Panel\Reports\BusinessResult($db, $sales, $oc->loader());
    $gross = function () { return false; };
    $r = $res->compute($cid, [1], '2026-01-01', '2026-02-28', $gross);
    $jan = $r['months']['2026-01'];
    $feb = $r['months']['2026-02'];
    eq(['105.00', '40.00', '300.00', '7.00', 'actual', '60.00', '298.00'], [number_format($jan['shop_revenue'], 2, '.', ''), number_format($jan['cogs'], 2, '.', ''),
        number_format($jan['other_revenue'], 2, '.', ''), number_format($jan['order_costs'], 2, '.', ''), $jan['order_costs_source'],
        number_format($jan['overhead'], 2, '.', ''), number_format($jan['profit'], 2, '.', '')],
        'January: shop 100 + 5 shipping, invoice of the order left out, real Packeta cost wins over the estimate 3');
    eq(['200.00', '-50.00', '3.00', 'estimate', '20.00', '500.00', '47.00'], [number_format($feb['shop_revenue'], 2, '.', ''), number_format($feb['other_revenue'], 2, '.', ''),
        number_format($feb['order_costs'], 2, '.', ''), $feb['order_costs_source'], number_format($feb['overhead'], 2, '.', ''),
        number_format($feb['goods_purchases'], 2, '.', ''), number_format($feb['profit'], 2, '.', '')],
        'February: credit note, no carrier invoice → estimate, goods purchase is stock (not in profit)');
    eq(['345.00', 'mixed', 1], [number_format($r['total']['profit'], 2, '.', ''), $r['total']['order_costs_source'], $r['foreign']], 'USD invoice counted as foreign, draft ignored');
    eq(1, count($r['shops']), 'the other company\'s e-shop is not in it');
    eq(['305.00', '180.00', '179.00', '20.00', '159.00'], [number_format($r['shops'][0]['revenue'], 2, '.', ''), number_format($r['shops'][0]['margin'], 2, '.', ''),
        number_format($r['shops'][0]['contribution'], 2, '.', ''), number_format($r['shops'][0]['direct'], 2, '.', ''), number_format($r['shops'][0]['result'], 2, '.', '')]);
    $noShops = $res->compute($cid, [], '2026-01-01', '2026-02-28', $gross);
    eq(['355.00', '0.00'], [number_format($noShops['total']['other_revenue'], 2, '.', ''), number_format($noShops['total']['shop_revenue'], 2, '.', '')],
        'a company without e-shops: every invoice is revenue (also those of orders)');

    // money
    $m = new Panel\Reports\Money($db);
    eq(['over0', 'over30', 'over60', 'due7', 'due30', 'later', 'later'], [$m::bucket('2026-03-19', '2026-03-20'), $m::bucket('2026-02-15', '2026-03-20'),
        $m::bucket('2026-01-01', '2026-03-20'), $m::bucket('2026-03-27', '2026-03-20'), $m::bucket('2026-04-10', '2026-03-20'), $m::bucket('2026-05-01', '2026-03-20'), $m::bucket(null, '2026-03-20')]);
    $rec = $m->receivables($cid, '2026-03-20');
    eq(['150.00', '150.00', '200.00', '-50.00'], [number_format($rec['total'], 2, '.', ''), number_format($rec['overdue'], 2, '.', ''),
        number_format($rec['buckets']['over30'], 2, '.', ''), number_format($rec['buckets']['over0'], 2, '.', '')], 'FA2 rest 200, credit note −50, USD left out');
    $pa = $m->payables($cid, '2026-03-20');
    eq(['520.00', '20.00', '500.00'], [number_format($pa['total'], 2, '.', ''), number_format($pa['overdue'], 2, '.', ''), number_format($pa['buckets']['due7'], 2, '.', '')]);
    $fl = $m->flows($cid, '2026-01-01', '2026-03-31');
    eq([[105.0, 60.0], [100.0, 7.0], [0.0, 0.0]], array_map(function ($x) { return [$x['in'], $x['out']]; }, array_values($fl)));

    // VAT registration turnover (seeded SK rules: 2025+ calendar year 50 000 / 62 500)
    $vt = new Panel\Reports\VatTurnover($db);
    eq('year', $vt->ruleOn('SK', '2026-02-28')['period']);
    eq('rolling12', $vt->ruleOn('SK', '2024-06-30')['period']);
    $s = $vt->status($cid, $sales, [1], '2026-02-28');
    eq(['555.00', 'ok', '2026-01-01'], [number_format($s['turnover'], 2, '.', ''), $s['level'], $s['from']], 'shop 305 + invoices 300 − 50 (EUR, not of local orders)');
    ok($s['projection'] > $s['turnover'], 'extrapolated to the year');
    ok(is_string($vt->saveRules([['country' => 'SK', 'period' => 'year', 'threshold' => '500', 'threshold_immediate' => '400', 'valid_from' => '2025-01-01']], $user)), 'immediate < threshold refused');
    ok(is_string($vt->saveRules([], $user)), 'at least one rule');
    $rule = function ($th, $im) { return ['country' => 'SK', 'period' => 'year', 'threshold' => $th, 'threshold_immediate' => $im, 'valid_from' => '2025-01-01', 'valid_to' => '', 'note' => 'test']; };
    eq(null, $vt->saveRules([$rule('650', '')], $user));
    eq('warn', $vt->status($cid, $sales, [1], '2026-02-28')['level'], '85 %');
    eq(null, $vt->saveRules([$rule('500', '600')], $user));
    eq('over', $vt->status($cid, $sales, [1], '2026-02-28')['level']);
    eq(null, $vt->saveRules([$rule('500', '550')], $user));
    $s = $vt->status($cid, $sales, [1], '2026-02-28');
    eq('over_immediate', $s['level']);
    contains('5 pracovných dní', $s['text']);
    eq(null, $vt->status($cid, $sales, [1], '2024-06-30'), 'no rule for 2024 any more');

    // todo
    $todo = new Panel\Reports\Todo($db, $t);
    $e = $todo->expenses([$cid], '2026-03-20');
    eq([4, 1, 1, 20.0], [$e['no_file'], $e['no_number'], $e['overdue'], $e['overdue_sum']], 'cancelled one left out');
    eq(['count' => 1, 'oldest' => '2026-02-10'], $todo->ordersWithoutInvoice([1], '2026-03-20', 90), 'order A has an invoice');
    eq(['count' => 0, 'oldest' => null], $todo->ordersWithoutInvoice([], '2026-03-20'));

    // summary e-mail text
    $txt = Panel\Reports\MonthlySummary::text('ERMA Group s.r.o.', '2026-02', $feb, $jan, $r['total'], $r['total'], $s, $rec, $pa,
        ['orders' => 1] + $e, 'https://erma.sk/panel/reports/result');
    contains('Súhrn firmy ERMA Group s.r.o. za február 2026', $txt);
    contains('ZISK:           47,00 €  (minulý rok 298,00 €, -84 %)', $txt);
    contains('(odhad — chýbajú doklady dopravcov)', $txt);
    contains('- 1 objednávok za 60 dní bez faktúry', $txt);
    contains('Registrácia k DPH: Obrat', $txt);
    foreach ($tables as $x) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}$x`");
    }
});

test('order tools: tracking + address through the Webservice (shop context, reference check, read-only fields), restock, sales', function () {
    $synopsis = function (string $res, array $ro) {
        $f = '';
        foreach ($ro as $r) {
            $f .= "<$r read_only=\"true\"/>";
        }
        return "<$res><id/>$f</$res>";
    };
    $api = new FakePrestaShopApi([
        'orders/7' => '<order><id>7</id><reference>REF7</reference><id_address_delivery>11</id_address_delivery><id_address_invoice>11</id_address_invoice>'
            . '<total_paid>10</total_paid><associations><order_rows><order_row><id>1</id></order_row></order_rows></associations></order>',
        'orders' => function ($p) use ($synopsis) { return $synopsis('order', ['total_paid']); },
        'order_carriers' => function ($p) use ($synopsis) {
            return isset($p['schema']) ? $synopsis('order_carrier', ['date_add']) : '<order_carriers><order_carrier><id>33</id></order_carrier></order_carriers>';
        },
        'order_carriers/33' => '<order_carrier><id>33</id><id_order>7</id_order><tracking_number></tracking_number><date_add>2026-01-01</date_add></order_carrier>',
        'addresses/11' => '<address><id>11</id><id_customer>5</id_customer><alias>Moja</alias><firstname>Ján</firstname><lastname>Novák</lastname>'
            . '<address1>Stará 1</address1><city>Levice</city><postcode>93401</postcode><deleted>0</deleted><date_add>x</date_add></address>',
        'addresses' => function ($p) use ($synopsis) { return $synopsis('address', []); },
        'POST addresses' => function ($xml) { return '<address><id>99</id></address>'; },
    ]);
    $ed = new Panel\Shop\ShopOrderEdit($api);
    throws(function () use ($ed) { $ed->setTracking(7, 1, 'OTHER', 'Z1'); }, RuntimeException::class);
    eq([], $api->putXml, 'reference mismatch: nothing written');
    $api->gets = [];
    $ed->setTracking(7, 2, 'REF7', 'Z123');
    list($res, $id, $xml, $params) = $api->putXml[0];
    eq(['order_carriers', 33, ['id_shop' => 2]], [$res, $id, $params]);
    eq('Z123', (string)$xml->order_carrier->tracking_number);
    ok(!isset($xml->order_carrier->date_add), 'read-only field removed');
    foreach ($api->gets as $g) {
        if ($g[0] === 'orders/7' || $g[0] === 'order_carriers/33') {
            eq(2, $g[1]['id_shop'] ?? null, 'shop context on ' . $g[0]);
        }
    }

    $new = $ed->changeAddress(7, 2, 'REF7', 'delivery', ['address1' => 'Nová 5', 'city' => 'Nitra', 'firstname' => 'Ján', 'lastname' => 'Novák', 'evil' => 'x']);
    eq(99, $new);
    list($res, $xml, $params) = end($api->posts);
    $a = simplexml_load_string($xml)->address;
    eq(['addresses', ['id_shop' => 2]], [$res, $params]);
    eq(['Nová 5', 'Nitra', '5', 'Objednávka REF7'], [(string)$a->address1, (string)$a->city, (string)$a->id_customer, (string)$a->alias]);
    ok(!isset($a->id) && !isset($a->deleted) && !isset($a->evil), 'new address without id / deleted / unknown fields');
    list($res, $id, $xml, $params) = end($api->putXml);
    eq(['orders', 7, '99', '11'], [$res, $id, (string)$xml->order->id_address_delivery, (string)$xml->order->id_address_invoice]);
    ok(!isset($xml->order->associations) && !isset($xml->order->total_paid), 'order PUT without associations and read-only fields');

    // restock: +2 pieces of a combination in a shop that shares stock with its group
    $api = new FakePrestaShopApi([
        'shop_groups' => '<shop_groups><shop_group><id>1</id><name>G</name><share_stock>1</share_stock></shop_group></shop_groups>',
        'shops' => '<shops><shop><id>3</id><name>Howies</name><id_shop_group>1</id_shop_group></shop></shops>',
        'stock_availables' => function ($p) {
            eq(['[1]', '[4]', '[8]'], [$p['filter']['id_shop_group'] ?? '', $p['filter']['id_product'], $p['filter']['id_product_attribute']]);
            return '<stock_availables><stock_available><id>50</id><quantity>5</quantity></stock_available></stock_availables>';
        },
        'stock_availables/50' => '<stock_available><id>50</id><id_shop>0</id_shop><id_shop_group>1</id_shop_group><quantity>5</quantity><out_of_stock>2</out_of_stock><location></location></stock_available>',
    ]);
    eq(7, (new Panel\Shop\ShopStock($api))->addQuantity(3, 4, 8, 2));
    eq([['stock_availables', 50, 7]], $api->puts);

    // sales (specific prices)
    $api = new FakePrestaShopApi([
        'POST specific_prices' => function ($xml) { return '<specific_price><id>12</id></specific_price>'; },
        'specific_prices/12' => '<specific_price><id>12</id><id_product>4</id_product><id_shop>3</id_shop></specific_price>',
        'specific_prices/13' => '<specific_price><id>13</id><id_product>9</id_product><id_shop>3</id_shop></specific_price>',
    ]);
    $sp = new Panel\Shop\ShopProducts($api);
    eq(12, $sp->addSpecificPrice(['id' => 3], 4, 0, 'percentage', 15, true, '2026-11-21', ''));
    $x = simplexml_load_string($api->posts[0][1])->specific_price;
    eq(['0.15', 'percentage', '1', '3', '2026-11-21 00:00:00', '0000-00-00 00:00:00', '-1'],
        [(string)$x->reduction, (string)$x->reduction_type, (string)$x->reduction_tax, (string)$x->id_shop, (string)$x->from, (string)$x->to, (string)$x->price]);
    $sp->addSpecificPrice(['id' => 3], 4, 8, 'amount', 2.5, false, '', '2026-12-31');
    $x = simplexml_load_string($api->posts[1][1])->specific_price;
    eq(['2.5', '0', '8', '2026-12-31 23:59:59'], [(string)$x->reduction, (string)$x->reduction_tax, (string)$x->id_product_attribute, (string)$x->to]);
    throws(function () use ($sp) { $sp->addSpecificPrice(['id' => 3], 4, 0, 'percentage', 100, true, '', ''); }, InvalidArgumentException::class);
    $sp->deleteSpecificPrice(['id' => 3], 12, 4);
    eq([['specific_prices', 12, ['id_shop' => 3]]], $api->deletes);
    throws(function () use ($sp) { $sp->deleteSpecificPrice(['id' => 3], 13, 4); }, RuntimeException::class);
});

test('order lines: plan (quantity, price, remove, add), shipping, totals; apply through the Webservice with checks', function () {
    $L = function ($id, $pid, $qty, $incl, $excl) {
        return ['id' => $id, 'product_id' => $pid, 'attribute_id' => 0, 'name' => 'P' . $pid, 'reference' => 'R' . $pid, 'qty' => $qty, 'reinjected' => 0,
            'unit_incl' => $incl, 'unit_excl' => $excl, 'total_incl' => round($incl * $qty, 2), 'total_excl' => round($excl * $qty, 2), 'weight' => 0.5];
    };
    $lines = [1 => $L(1, 10, 2, 12.0, 10.0), 2 => $L(2, 20, 1, 5.0, 5.0)];
    $ops = Panel\Shop\ShopOrderLines::plan($lines, ['lines' => [1 => ['qty' => 3, 'price' => 12.0], 2 => ['qty' => 0, 'price' => null]]]);
    eq(['update', 'delete'], array_column($ops, 'op'));
    eq([36.0, 30.0, -1], [$ops[0]['line']['total_incl'], $ops[0]['line']['total_excl'], $ops[0]['stock']], 'more pieces: taken from stock');
    eq([-5.0, 1], [$ops[1]['d_incl'], $ops[1]['stock']], 'removed line: back to stock');
    $ops = Panel\Shop\ShopOrderLines::plan($lines, ['lines' => [1 => ['qty' => 2, 'price' => 9.6]]]);
    eq([9.6, 8.0, 19.2, 16.0, 0], [$ops[0]['line']['unit_incl'], $ops[0]['line']['unit_excl'], $ops[0]['line']['total_incl'],
        $ops[0]['line']['total_excl'], $ops[0]['stock']], 'new price keeps the line VAT rate (20 %)');
    eq([], Panel\Shop\ShopOrderLines::plan($lines, ['lines' => [1 => ['qty' => 2, 'price' => 12.0], 2 => ['qty' => 1, 'price' => 5.0]]]), 'nothing changed');
    $prod = ['product_id' => 30, 'attribute_id' => 4, 'name' => 'Nový - Veľkosť : M', 'reference' => 'N30', 'incl' => 24.0, 'excl' => 20.0, 'weight' => 1.0];
    $ops = Panel\Shop\ShopOrderLines::plan($lines, ['add' => [['product' => $prod, 'qty' => 2, 'price' => null]]]);
    eq(['add', 48.0, 40.0, -2], [$ops[0]['op'], $ops[0]['d_incl'], $ops[0]['d_excl'], $ops[0]['stock']], 'new line at the shop price');
    $same = ['product_id' => 10, 'attribute_id' => 0, 'name' => 'x', 'reference' => '', 'incl' => 1.0, 'excl' => 1.0, 'weight' => 0.0];
    throws(function () use ($lines, $same) { Panel\Shop\ShopOrderLines::plan($lines, ['add' => [['product' => $same, 'qty' => 1, 'price' => null]]]); },
        InvalidArgumentException::class);
    eq('add', Panel\Shop\ShopOrderLines::plan($lines, ['lines' => [1 => ['qty' => 0, 'price' => null]],
        'add' => [['product' => $same, 'qty' => 1, 'price' => null]]])[1]['op'], 'a removed product can be added again');

    $o = ['id_carrier' => 5, 'total_shipping_tax_incl' => 3.6, 'total_shipping_tax_excl' => 3.0, 'carrier_tax_rate' => 20];
    eq(null, Panel\Shop\ShopOrderLines::planShipping($o, ['carrier_id' => 5, 'price' => 3.6]));
    $s = Panel\Shop\ShopOrderLines::planShipping($o, ['carrier_id' => 6, 'price' => 4.8]);
    eq([6, 4.8, 4.0], [$s['carrier_id'], $s['incl'], $s['excl']]);
    $t = Panel\Shop\ShopOrderLines::totals(['total_products' => 25, 'total_products_wt' => 29, 'total_shipping' => 3.6, 'total_shipping_tax_incl' => 3.6,
        'total_shipping_tax_excl' => 3.0, 'total_paid' => 32.6, 'total_paid_tax_incl' => 32.6, 'total_paid_tax_excl' => 28.0], 7.0, 5.0, 1.2, 1.0);
    eq([30.0, 36.0, 4.8, 40.8, 40.8, 34.0], [$t['total_products'], $t['total_products_wt'], $t['total_shipping'], $t['total_paid'],
        $t['total_paid_tax_incl'], $t['total_paid_tax_excl']]);

    // apply: Webservice of a shop (multistore id_shop 2), order REF7 with 2 lines
    $detail = function ($id, $pid, $qty, $incl, $excl) {
        return "<order_detail><id>$id</id><id_order>7</id_order><id_shop>2</id_shop><product_id>$pid</product_id><product_attribute_id>0</product_attribute_id>"
            . "<product_name>P$pid</product_name><product_reference>R$pid</product_reference><product_quantity>$qty</product_quantity>"
            . '<product_quantity_reinjected>0</product_quantity_reinjected><product_quantity_refunded>1</product_quantity_refunded><reduction_percent>10</reduction_percent>'
            . "<product_price>$excl</product_price><unit_price_tax_incl>$incl</unit_price_tax_incl><unit_price_tax_excl>$excl</unit_price_tax_excl>"
            . '<total_price_tax_incl>' . ($incl * $qty) . '</total_price_tax_incl><total_price_tax_excl>' . ($excl * $qty) . '</total_price_tax_excl>'
            . '<product_weight>0.5</product_weight><original_wholesale_price>0</original_wholesale_price><associations><taxes><tax><id>1</id></tax></taxes></associations></order_detail>';
    };
    $api = new FakePrestaShopApi([
        'orders/7' => '<order><id>7</id><reference>REF7</reference><id_shop>2</id_shop><id_lang>1</id_lang><id_currency>1</id_currency><id_carrier>5</id_carrier>'
            . '<id_address_delivery>11</id_address_delivery><total_products>25</total_products><total_products_wt>29</total_products_wt>'
            . '<total_shipping>3.6</total_shipping><total_shipping_tax_incl>3.6</total_shipping_tax_incl><total_shipping_tax_excl>3</total_shipping_tax_excl>'
            . '<carrier_tax_rate>20</carrier_tax_rate><total_paid>32.6</total_paid><total_paid_tax_incl>32.6</total_paid_tax_incl>'
            . '<total_paid_tax_excl>28</total_paid_tax_excl><total_paid_real>32.6</total_paid_real><associations><order_rows/></associations></order>',
        'order_details' => function ($p) use ($detail) {
            return isset($p['schema']) ? '<order_detail><id/></order_detail>' : '<order_details>' . $detail(1, 10, 2, 12, 10) . $detail(2, 20, 1, 5, 5) . '</order_details>';
        },
        'order_details/1' => $detail(1, 10, 2, 12, 10),
        'order_carriers' => function ($p) {
            return isset($p['schema']) ? '<order_carrier><id/></order_carrier>' : '<order_carriers><order_carrier><id>33</id></order_carrier></order_carriers>';
        },
        'order_carriers/33' => '<order_carrier><id>33</id><id_order>7</id_order><id_carrier>5</id_carrier><weight>1.5</weight>'
            . '<shipping_cost_tax_incl>3.6</shipping_cost_tax_incl><shipping_cost_tax_excl>3</shipping_cost_tax_excl></order_carrier>',
        'addresses/11' => '<address><id>11</id><id_country>37</id_country></address>',
        'products/30' => '<product><id>30</id><name><language id="1">Nový</language></name><reference>N30</reference><supplier_reference/><ean13/><isbn/><upc/>'
            . '<weight>1</weight><wholesale_price>8</wholesale_price><price>20</price><id_tax_rules_group>1</id_tax_rules_group>'
            . '<panel_incl>24.000000</panel_incl><panel_excl>20.000000</panel_excl></product>',
        'POST order_details' => function ($xml) { return '<order_detail><id>3</id></order_detail>'; },
    ]);
    $ed = new Panel\Shop\ShopOrderLines($api);
    $expect = ['lines' => [1 => ['qty' => 2, 'price' => 12.0], 2 => ['qty' => 1, 'price' => 5.0]], 'total' => 32.6, 'carrier' => 5, 'shipping' => 3.6];
    $req = ['lines' => [1 => ['qty' => 3, 'price' => 12.0], 2 => ['qty' => 0, 'price' => 5.0]],
        'add' => [['product_id' => 30, 'attribute_id' => 0, 'qty' => 1, 'price' => null]], 'shipping' => ['carrier_id' => 6, 'price' => 4.8]];
    throws(function () use ($ed, $req, $expect) { $ed->apply(7, 2, 'REF7', $req, ['total' => 30.0] + $expect); }, RuntimeException::class);
    throws(function () use ($ed, $req, $expect) { $ed->apply(7, 2, 'REF7', $req, ['lines' => [1 => ['qty' => 2, 'price' => 12.0]]] + $expect); }, RuntimeException::class);
    throws(function () use ($ed, $req, $expect) { $ed->apply(7, 2, 'OTHER', $req, $expect); }, RuntimeException::class);
    eq([[], [], []], [$api->putXml, $api->deletes, $api->posts], 'order changed meanwhile / other reference: nothing written');

    $r = $ed->apply(7, 2, 'REF7', $req, $expect, [1 => ['tax_rate' => '20.000', 'tax_name' => 'DPH 20%']]);
    eq([[], 64.8], [$r['problems'], $r['total']]);
    contains('pridané: Nový (1 ks × 24,00 €)', implode('; ', $r['done']));
    eq([['order_details', 2, ['id_shop' => 2]]], $api->deletes);
    $puts = [];
    foreach ($api->putXml as $x) {
        $puts[$x[0]] = $x;
        eq(['id_shop' => 2], $x[3], 'shop context on ' . $x[0]);
    }
    $d = $puts['order_details'][2]->order_detail;
    eq(['3', '36.000000', '30.000000', '20.000', 'DPH 20%'], [(string)$d->product_quantity, (string)$d->total_price_tax_incl,
        (string)$d->total_price_tax_excl, (string)$d->tax_rate, (string)$d->tax_name], 'line PUT keeps the hidden VAT fields');
    ok(!isset($d->associations), 'line PUT without associations');
    list($res, $xml, $params) = $api->posts[0];
    $n = simplexml_load_string($xml)->order_detail;
    eq(['order_details', ['id_shop' => 2]], [$res, $params]);
    eq(['30', '7', 'Nový', '1', '0', '0', '24.000000', '20.000000', '8.000000'], [(string)$n->product_id, (string)$n->id_order, (string)$n->product_name,
        (string)$n->product_quantity, (string)$n->product_quantity_refunded, (string)$n->reduction_percent, (string)$n->unit_price_tax_incl,
        (string)$n->unit_price_tax_excl, (string)$n->original_wholesale_price], 'new line = copy of a line with the product data, refunds / reductions reset');
    ok(!isset($n->id), 'new line without id');
    $oc = $puts['order_carriers'][2]->order_carrier;
    eq(['6', '4.800000', '4.000000', '2.500000'], [(string)$oc->id_carrier, (string)$oc->shipping_cost_tax_incl, (string)$oc->shipping_cost_tax_excl,
        (string)$oc->weight], 'carrier, shipping price, weight of the products');
    $ord = $puts['orders'][2]->order;
    eq(['50.000000', '60.000000', '4.800000', '64.800000', '64.800000', '54.000000', '6', '32.6'], [(string)$ord->total_products,
        (string)$ord->total_products_wt, (string)$ord->total_shipping, (string)$ord->total_paid, (string)$ord->total_paid_tax_incl,
        (string)$ord->total_paid_tax_excl, (string)$ord->id_carrier, (string)$ord->total_paid_real], 'order totals by the difference, paid amount stays');
    ok(!isset($ord->associations), 'order PUT without associations');
    $priceGet = array_values(array_filter($api->gets, function ($g) { return $g[0] === 'products/30'; }))[0][1];
    eq([1, 0, 37, 2], [$priceGet['price[panel_incl][use_tax]'], $priceGet['price[panel_excl][use_tax]'], $priceGet['price[panel_incl][country]'],
        $priceGet['id_shop']], 'shop price with / without VAT for the delivery country');

    // discount of the order: 10 % of the products (29 € with VAT) = 2,90 €; without VAT in the products' ratio (25 / 29)
    $d = Panel\Shop\ShopOrderLines::planDiscount(['total_discounts_tax_incl' => 0, 'total_discounts_tax_excl' => 0], 29.0, 25.0,
        ['mode' => 'percent', 'value' => 10]);
    eq([2.9, 2.5, 2.9], [$d['incl'], $d['excl'], $d['d_incl']]);
    eq(null, Panel\Shop\ShopOrderLines::planDiscount(['total_discounts_tax_incl' => 2.9], 29.0, 25.0, ['mode' => 'amount', 'value' => 2.9]),
        'same discount = no change');
    $t = Panel\Shop\ShopOrderLines::totals(['total_paid_tax_incl' => 32.6, 'total_paid_tax_excl' => 28.0, 'total_discounts_tax_incl' => 1.0],
        0.0, 0.0, 0.0, 0.0, 1.9, 1.5);
    eq([2.9, 30.7, 26.5], [$t['total_discounts_tax_incl'], $t['total_paid_tax_incl'], $t['total_paid_tax_excl']], 'a bigger discount lowers the total');
    $api->putXml = [];
    $r = $ed->apply(7, 2, 'REF7', ['lines' => [1 => ['qty' => 2, 'price' => 12.0], 2 => ['qty' => 1, 'price' => 5.0]],
        'shipping' => ['carrier_id' => 5, 'price' => 3.6], 'discount' => ['mode' => 'percent', 'value' => 10]], $expect + ['discount' => 0.0]);
    eq([[], 29.7], [$r['problems'], $r['total']]);
    contains('zľava 0,00 € → 2,90 € (10 % z tovaru)', implode('; ', $r['done']));
    eq(['orders'], array_column($api->putXml, 0), 'only the discount: only the order is written');
    $ord = $api->putXml[0][2]->order;
    eq(['2.900000', '2.900000', '2.500000', '29.700000', '29.700000', '25.500000', '29.000000'], [(string)$ord->total_discounts,
        (string)$ord->total_discounts_tax_incl, (string)$ord->total_discounts_tax_excl, (string)$ord->total_paid, (string)$ord->total_paid_tax_incl,
        (string)$ord->total_paid_tax_excl, (string)$ord->total_products_wt]);
    throws(function () use ($ed, $expect) { $ed->apply(7, 2, 'REF7', ['discount' => ['mode' => 'amount', 'value' => 1]], $expect + ['discount' => 5.0]); },
        RuntimeException::class);
});

test('order states in the shop: list, turn the e-mail on, new state, form checks', function () {
    $state = function ($id, $name, $tpl, $send, $deleted = 0) {
        return "<order_state><id>$id</id><unremovable>1</unremovable><delivery>0</delivery><hidden>0</hidden><send_email>$send</send_email>"
            . "<module_name></module_name><invoice>1</invoice><color>#108510</color><logable>1</logable><shipped>1</shipped><paid>1</paid>"
            . "<pdf_delivery>0</pdf_delivery><pdf_invoice>0</pdf_invoice><deleted>$deleted</deleted>"
            . "<name><language id=\"1\">EN $name</language><language id=\"2\">$name</language></name>"
            . "<template><language id=\"1\">$tpl</language><language id=\"2\">$tpl</language></template></order_state>";
    };
    $api = new FakePrestaShopApi([
        'order_states' => function ($p) use ($state) {
            if (($p['schema'] ?? '') === 'blank') {
                return '<order_state><id/><unremovable/><delivery/><hidden/><send_email/><module_name/><invoice/><color/><logable/><shipped/><paid/>'
                    . '<pdf_delivery/><pdf_invoice/><deleted/><name><language id="1"/><language id="2"/></name>'
                    . '<template><language id="1"/><language id="2"/></template></order_state>';
            }
            if (isset($p['schema'])) {
                return '<order_state><id/></order_state>';
            }
            return '<order_states>' . $state(4, 'Zaplatená a odoslaná', 'shipped', 0) . $state(2, 'Platba akceptovaná', 'payment', 1)
                . $state(9, 'Zmazaný', '', 0, 1) . '</order_states>';
        },
        'order_states/4' => $state(4, 'Zaplatená a odoslaná', 'shipped', 0),
        'POST order_states' => function ($xml) {
            $s = simplexml_load_string($xml)->order_state;
            return '<order_state><id>40</id><send_email>' . $s->send_email . '</send_email></order_state>';
        },
    ]);
    $st = new Panel\Shop\ShopOrderStates($api);
    $all = $st->all(2);
    eq([2, 4], array_keys($all), 'deleted states left out, sorted by name');
    eq(['Zaplatená a odoslaná', 'shipped', false, true], [$all[4]['name'], $all[4]['template'], $all[4]['send_email'], $all[4]['paid']]);
    eq(['bankwire', 'cashondelivery', 'cheque'], array_slice(Panel\Shop\ShopOrderStates::templates($all), 0, 3));

    $v = ['name' => 'Zaplatená & odoslaná', 'template' => 'shipped', 'color' => '#108510', 'send_email' => true, 'paid' => true,
        'shipped' => true, 'delivery' => false, 'pdf_delivery' => false, 'logable' => true, 'hidden' => false];
    eq([], Panel\Shop\ShopOrderStates::errors($v));
    eq(4, $st->save(4, $v, 2));
    list($res, $id, $xml) = $api->putXml[0];
    $s = $xml->order_state;
    eq(['order_states', 4, '1', 'Zaplatená & odoslaná', 'EN Zaplatená a odoslaná', '1', '1'], [$res, $id, (string)$s->send_email,
        (string)$s->name->language[1], (string)$s->name->language[0], (string)$s->unremovable, (string)$s->invoice],
        'only the Slovak name changes; unremovable / invoice flags stay');
    contains('&amp; odoslan', $xml->asXML(), '& escaped in the XML');

    $new = array_merge($v, ['name' => 'Odoslané Packetou', 'template' => 'in_transit', 'paid' => false]);
    eq(40, $st->save(0, $new, 2));
    $n = simplexml_load_string($api->posts[0][1])->order_state;
    eq(['Odoslané Packetou', 'Odoslané Packetou', 'in_transit', '0', '0', '0'], [(string)$n->name->language[0], (string)$n->name->language[1],
        (string)$n->template->language[1], (string)$n->paid, (string)$n->invoice, (string)$n->unremovable], 'a new state: all languages, invoice flags off');
    ok(!isset($n->id), 'new state without id');

    $bad = Panel\Shop\ShopOrderStates::errors(['name' => '', 'template' => 'zlá šablóna', 'color' => 'red', 'send_email' => true]);
    eq(['name', 'template', 'color'], array_keys($bad));
    eq(['template'], array_keys(Panel\Shop\ShopOrderStates::errors(array_merge($v, ['template' => '']))), 'e-mail needs a template');
});

test('returns: partial credit note (returned goods + shipping), order log, branded mail, invoice template 2026-2', function () {
    $db = panelDb();
    $user = panelUser($db, 'admin', 100);
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $cid = $companies->save(null, ['name' => 'ERMA Group s.r.o.', 'street' => 'Pravotice 178', 'city' => 'Pravotice', 'zip' => '956 35',
        'country' => 'SK', 'ico' => '53036875', 'dic' => '2121232916', 'registration' => '', 'email' => 'info@erma.sk', 'phone' => '', 'web' => '',
        'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1]);
    $companies->saveBanks($cid, [['id' => 0, 'account_name' => 'ERMA', 'iban' => 'SK1783300000002501809819', 'bic' => 'FIOZSKBAXXX', 'bank' => 'Fio banka, a.s.',
        'is_default' => true, 'active' => true]]);
    eq('Fio banka, a.s.', $companies->banks($cid)[0]['bank']);
    $companies->saveSeries(null, $cid, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNNNNN}', 'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $companies->saveSeries(null, $cid, ['doc_type' => 'credit_note', 'name' => 'DB', 'format' => 'DB{RRRR}/{NNNN}', 'vs_format' => '', 'reset_yearly' => 1, 'is_default' => 1, 'active' => 1]);
    $inv = new Invoices($db);
    $h = array_merge($inv->defaults($cid, 'invoice', $user), ['issue_date' => '2026-11-18', 'delivery_date' => '2026-11-18', 'due_date' => '2026-12-02',
        'buyer_type' => 'b2c', 'buyer_name' => 'Klaudia Turanová', 'buyer_street' => 'Štefánika 7', 'buyer_city' => 'Levice', 'buyer_zip' => '93401',
        'buyer_email' => 'k@example.sk', 'buyer_phone' => '0940730049']);
    $id = $inv->saveDraft(null, $h, [
        ['name' => 'Howies Vosk na čepeľ', 'sku' => '330', 'quantity' => 2, 'unit' => 'ks', 'unit_price' => 6.9, 'vat_rate' => 0, 'vat_category' => 'S'],
        ['name' => 'Páska čierna', 'sku' => '63', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 4, 'vat_rate' => 0, 'vat_category' => 'S'],
        ['name' => 'Doprava: Zásielkovňa SK', 'sku' => '', 'quantity' => 1, 'unit' => 'ks', 'unit_price' => 3.59, 'vat_rate' => 0, 'vat_category' => 'S'],
    ], $user, ['shop_id' => 1, 'shop_connection_id' => 0, 'shop_order_id' => 2508, 'order_reference' => '2456']);
    $inv->issue($id, $user);
    $cn = $inv->createCreditNote($id, $user, [['sku' => '330', 'name' => 'Howies Vosk na čepeľ', 'quantity' => 1], ['shipping' => true]], 'Vrátenie tovaru');
    $c = $inv->find($cn);
    eq([['Howies Vosk na čepeľ', '-1.000'], ['Doprava: Zásielkovňa SK', '-1.000']], array_map(function ($l) { return [$l['name'], $l['quantity']]; }, $c['lines']));
    eq(['-10.49', 'Vrátenie tovaru', '2456', null], [$c['total'], $c['correction_reason'], $c['order_reference'], $c['shop_order_id']]);
    eq($id, (int)$inv->findByOrder(0, 2508)['id'], 'the order still points to its invoice, not to the credit note');
    throws(function () use ($inv, $id, $user) { $inv->createCreditNote($id, $user, [['sku' => 'X', 'name' => 'Iný', 'quantity' => 1]], 'x'); }, RuntimeException::class);
    eq(2, count($inv->find($cn)['lines']));

    // order log: notes, returns, settings
    $log = new Panel\Shop\OrderLog($db);
    $log->addNote(0, 2508, 'Zákazník volal', $user);
    eq(['Zákazník volal'], array_column($log->notes(0, 2508), 'text'));
    eq([2508 => 1], $log->noteCounts(0, [2508, 2509]));
    $log->addReturn(0, 2508, [['id_order_detail' => 5, 'product_id' => 1, 'product_attribute_id' => 0, 'name' => 'Vosk', 'reference' => '330', 'quantity' => 1]],
        true, true, $cn, 'Platba bola vrátená', '', [], $user);
    $log->addReturn(0, 2508, [['id_order_detail' => 5, 'product_id' => 1, 'product_attribute_id' => 0, 'name' => 'Vosk', 'reference' => '330', 'quantity' => 1]],
        false, false, null, '', '', ['Naskladnenie: chyba'], $user);
    eq([5 => 2], $log->returned(0, 2508));
    eq(['Naskladnenie: chyba'], $log->returns(0, 2508)[1]['problems']);
    $log->logMessage(0, 2508, ['k@example.sk'], 'Otázka', 'Text', '', $user);
    eq('sent', $log->messages(0, 2508)[0]['status']);
    eq(null, $log->setting('shipping_states.0'));
    $log->setSetting('shipping_states.0', [2, 11]);
    eq([2, 11], $log->setting('shipping_states.0'));

    // e-mail in the shop's look
    $m = Panel\ShopMail::message(['text' => 'Dobrý deň'], ['name' => 'Howies', 'web' => 'https://howieshockey.sk/', 'logo' => 'PNG', 'logo_mime' => 'image/png'], 'pätička');
    contains('cid:shoplogo', $m['html']);
    eq([['shoplogo', 'PNG', 'image/png', 'logo']], $m['embedded']);
    contains("-- \npätička", $m['text']);
    eq(['text' => 'x'], Panel\ShopMail::message(['text' => 'x'], null));

    // the new invoice template: everything the old shop invoices had (domestic account, bank, codes, buyer contact, texts)
    eq('invoice-2026-2', Panel\Invoicing\Templates::CURRENT);
    $i = $inv->find($id);
    $view = new WebApplication\View(__DIR__ . '/../src/applications/panel/views');
    $html = $view->render('invoices/templates/invoice-2026-2', ['inv' => $i, 'supplier' => $i['supplier'], 'original' => null,
        'legal' => (new Panel\Invoicing\Legislation($db))->textsOn('2026-11-18'), 'logo' => '', 'stamp' => '', 'qr' => '', 'qrError' => '',
        'fontBase' => '', 'forPdf' => true, 'title' => 'x', 'bankName' => 'Fio banka, a.s.', 'f' => new Panel\Format(),
        'shop' => ['name' => 'HowiesHockey', 'web' => 'https://www.howieshockey.sk/store/', 'email' => 'info@howieshockey.sk', 'phone' => '+421 949 00 11 02', 'logo' => '']]);
    foreach (['2501809819/8330', 'Fio banka, a.s.', 'Kód: 330', '0940730049', 'k@example.sk', 'Slovensko', 'Táto faktúra slúži zároveň aj ako dodací list.',
              'Ďakujeme Vám za nákup na howieshockey.sk.', 'www.howieshockey.sk', 'info@howieshockey.sk', 'Dátum splatnosti:', '2.12.2026',
              'Celková suma bez DPH:', 'Číslo objednávky:', '2456'] as $needle) {
        contains($needle, $html);
    }
});

test('Pomocník: Markdown is escaped and formatted; every page has a guide; links between guides work', function () {
    $url = function ($p) { return '/panel' . $p; };
    $html = Panel\Markdown::toHtml("## Postup\n\n1. **Uložiť** <script>\n2. pozri [Firmy](/companies) a [návod](help:slovnik)\n\n"
        . "> Pozor: `kód` *dôležité*\n\n| A | B |\n|---|---|\n| 1 | [x](https://example.sk) |", $url);
    contains('<h3 id="postup">Postup</h3>', $html);
    contains('<ol><li><b>Uložiť</b> &lt;script&gt;</li>', $html);
    contains('<a href="/panel/companies">Firmy</a>', $html);
    contains('<a href="/panel/help/slovnik">návod</a>', $html);
    contains('<blockquote>Pozor: <code>kód</code> <i>dôležité</i></blockquote>', $html);
    contains('<th>A</th>', $html);
    contains('target="_blank" rel="noopener"', $html);
    ok(strpos($html, '<script>') === false, 'no raw HTML');
    eq('Nastavenia firmy', Panel\Markdown::title("# Nastavenia firmy\n\ntext"));

    $dir = __DIR__ . '/../src/applications/panel';
    $idx = require $dir . '/help/_index.php';
    $missing = [];
    foreach (glob($dir . '/src/Controller/*.php') as $f) {   // every page rendered by a controller has its guide
        preg_match_all("/page\('([a-z\/-]+)'/", file_get_contents($f), $m);
        foreach ($m[1] as $tpl) {
            if (!in_array($tpl, ['notfound', 'forbidden'], true) && !isset($idx['pages'][$tpl])) {
                $missing[] = $tpl;
            }
        }
    }
    eq([], array_values(array_unique($missing)), 'pages without a guide in help/_index.php');
    $slugs = array_merge(array_merge(...array_values($idx['groups'])), array_values($idx['pages']), array_values($idx['sections']));
    foreach (array_unique($slugs) as $s) {
        ok(is_file("$dir/help/$s.md"), "guide $s.md exists");
    }
    foreach (glob("$dir/help/*.md") as $f) {
        preg_match_all('/\(help:([a-z0-9-]+)\)/', file_get_contents($f), $m);
        foreach ($m[1] as $s) {
            ok(is_file("$dir/help/$s.md"), basename($f) . " links to help:$s");
        }
        ok(in_array(basename($f, '.md'), $slugs, true), basename($f) . ' is listed in help/_index.php');
        ok(Panel\Markdown::title(file_get_contents($f)) !== '', basename($f) . ' has a "# title"');
    }
});

/** PanelContext without the whole application: a database + a logged-in person (core services, record resolvers). */
class TestPanelContext extends Panel\PanelContext
{
    public $testDb;
    public $testUser;

    public function __construct($db, ?Client $user)
    {
        $this->testDb = $db;
        $this->testUser = $user;
        $this->request = new WebApplication\Http\Request('GET', 'panel.test', '/');
    }

    protected function authUser(): ?Client { return $this->testUser; }
    public function db(): WebApplication\Database\Database { return $this->testDb; }
    public function url(string $path = '/'): string { return '/panel' . $path; }
    public function orderSources(): array { return []; }
    public function orderSource(int $id): ?Panel\Shop\OrderSource { return null; }
}

test('records: reference types registered by the modules, parse / make, audit entity → reference', function () {
    $types = Panel\Core\Refs::types();
    foreach (['invoice', 'order', 'expense', 'customer', 'company', 'stock_item', 'stock_doc', 'warehouse', 'employee', 'role'] as $t) {
        ok(isset($types[$t]) && $types[$t]['label'] !== '', "type $t");
    }
    eq(2, $types['order']['parts']);
    eq(['invoice', [15]], Panel\Core\Refs::parse('invoice:15'));
    eq(['order', [0, 2481]], Panel\Core\Refs::parse('order:0:2481'));
    foreach (['invoice', 'invoice:', 'invoice:x', 'order:5', 'invoice:1:2', 'nothing:1', 'Invoice:1', 'invoice:15 '] as $bad) {
        eq(null, Panel\Core\Refs::parse($bad), "not a reference: '$bad'");
    }
    eq('order:3:10', Panel\Core\Refs::make('order', 3, 10));
    eq('invoice:7', Panel\Core\Refs::fromEntity('invoice', 7));
    eq(null, Panel\Core\Refs::fromEntity('shop_order', 7), 'needs the source → passed explicitly');
    eq(null, Panel\Core\Refs::fromEntity('legislation', 1), 'not a record type');
    throws(function () {
        $m = new class implements Panel\Module {
            public function routes(WebApplication\Http\Router $r, callable $g, Panel\PanelContext $c): void {}
            public function nav(): array { return []; }
            public function settingsTabs(): array { return []; }
            public function permissions(): array { return []; }
            public function recordTypes(): array { return [['type' => 'invoice', 'label' => 'x', 'resolve' => function () { return null; }]]; }
        };
        Panel\Core\Refs::fromModules([new Panel\Modules\InvoicingModule(), $m]);
    }, LogicException::class);
});

test('records: resolve only what the person may see (permission + company), lookup, short link, comments, links, activity', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $fields = ['street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '', 'registration' => '', 'email' => '', 'phone' => '',
        'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
    $c1 = $companies->save(null, ['name' => 'Firma A'] + $fields);
    $c2 = $companies->save(null, ['name' => 'Firma B'] + $fields);
    $t = $db->table('invoice');
    $db->execute("INSERT INTO $t (company_id, doc_type, status, number, issue_date, delivery_date, due_date, currency, created_at, updated_at) VALUES (?, 'invoice', 'issued', 'FA2026/000777', '2026-11-01', '2026-11-01', '2026-11-15', 'EUR', NOW(), NOW())", [$c1]);
    $inv1 = $db->lastInsertId();
    $db->execute("INSERT INTO $t (company_id, doc_type, status, number, issue_date, delivery_date, due_date, currency, created_at, updated_at) VALUES (?, 'invoice', 'draft', NULL, '2026-11-01', '2026-11-01', '2026-11-15', 'EUR', NOW(), NOW())", [$c2]);
    $inv2 = $db->lastInsertId();
    $boss = panelUser($db, 'boss3', 100);
    $eva = panelUser($db, 'eva', 10);
    $eva->name = 'Eva Malá'; $eva->update();
    $jan = panelUser($db, 'jan', 10);
    $jan->name = 'Ján Novák'; $jan->update();
    $roles = new Panel\Core\Roles($db);
    $roles->savePerson($eva->getId(), ['admin' => false, 'roles' => [], 'allow' => [Rights::INVOICES_VIEW], 'deny' => [], 'all_companies' => false,
        'companies' => [$c1], 'all_shops' => true, 'shops' => []]);

    $b = new TestPanelContext($db, $boss);
    $e = new TestPanelContext($db, $eva);
    $j = new TestPanelContext($db, $jan);
    eq(['ref' => "invoice:$inv1", 'type' => 'invoice', 'label' => 'Faktúra', 'title' => 'Faktúra FA2026/000777', 'url' => "/invoices/$inv1", 'company_id' => $c1],
        $e->refs()->resolve("invoice:$inv1"));
    eq(null, $e->refs()->resolve("invoice:$inv2"), 'other company = not visible');
    eq(null, $j->refs()->resolve("invoice:$inv1"), 'no permission = not visible');
    eq("Faktúra — koncept #$inv2", $b->refs()->resolve("invoice:$inv2")['title']);
    eq(null, $b->refs()->resolve('invoice:999999'), 'does not exist');
    eq(null, (new TestPanelContext($db, null))->refs()->resolve("invoice:$inv1"), 'nobody logged in');
    eq(null, $e->refs()->resolve('employee:' . $jan->getId()), 'employees only with settings.employees');
    eq('Ján Novák', $b->refs()->resolve('employee:' . $jan->getId())['title']);
    eq(['invoice:' . $inv1], array_column($e->refs()->find('000777'), 'ref'), 'lookup by number');
    eq([], $e->refs()->find('nič-také'));
    eq(["invoice:$inv1"], array_column($e->refs()->find("invoice:$inv1"), 'ref'), 'a reference');
    eq(["invoice:$inv1"], array_column($e->refs()->find("https://erma.sk/panel/r/invoice:$inv1"), 'ref'), 'a short link');
    eq([], $e->refs()->find("invoice:$inv2"), 'a reference of an invisible record');
    eq("http://panel.test/panel/r/invoice:$inv1", $e->refs()->shortUrl("invoice:$inv1"));

    // comments + mentions
    $cm = $b->comments();
    $id = $cm->add("invoice:$inv1", "  @ján novák pozri prosím, aj invoice:$inv2 a @Eva Malá\r\n2. riadok <b>  ", $boss);
    $sorted = function ($x) { sort($x); return $x; };
    eq($sorted([$jan->getId(), $eva->getId()]), $sorted($cm->mentionedIn('@ján novák pozri @Eva Malá')), 'case-insensitive full names');
    eq([], $cm->mentionedIn('ján novák bez zavináča, @Jána'));
    $row = $cm->forRef("invoice:$inv1")[0];
    eq("@ján novák pozri prosím, aj invoice:$inv2 a @Eva Malá\n2. riadok <b>", $row['body'], 'trimmed, CRLF → LF');
    eq(2, count($row['mentions']));
    $html = Panel\Core\Comments::render($row['body'], $row['mentions'], function ($r) use ($e) { return $e->refs()->resolve($r); }, function ($p) { return '/panel' . $p; });
    contains('<b class="mention">@ján novák</b>', $html);
    contains('&lt;b&gt;', $html);
    contains("invoice:$inv2", $html);
    ok(strpos($html, "/panel/invoices/$inv2") === false, 'no link to a record the reader may not see');
    $html = Panel\Core\Comments::render("viď invoice:$inv1", [], function ($r) use ($e) { return $e->refs()->resolve($r); }, function ($p) { return '/panel' . $p; });
    contains('<a href="/panel/invoices/' . $inv1 . '" title="Faktúra">Faktúra FA2026/000777</a>', $html);
    eq(["invoice:$inv1" => 1], $cm->counts(["invoice:$inv1", "invoice:$inv2"]));
    $cm->update($id, 'opravené @Eva Malá');
    eq([$eva->getId()], array_keys($cm->forRef("invoice:$inv1")[0]['mentions']), 'mentions follow the edit');
    ok($cm->forRef("invoice:$inv1")[0]['edited_at'] !== null);
    $cm->delete($id);
    eq([], $cm->counts(["invoice:$inv1"]), 'deleted comments are not counted');
    ok($cm->forRef("invoice:$inv1")[0]['deleted_at'] !== null, 'stays as "zmazaný"');
    eq([], $cm->forRef("invoice:$inv1")[0]['mentions']);

    // links
    $ln = $b->links();
    $lid = $ln->add("invoice:$inv1", "invoice:$inv2", 'derived', $boss);
    ok($lid > 0);
    eq(null, $ln->add("invoice:$inv1", "invoice:$inv2", 'derived', $boss), 'same link twice');
    eq(null, $ln->add("invoice:$inv1", "invoice:$inv1", 'relates', $boss), 'a record with itself');
    eq(null, $ln->add("invoice:$inv1", "invoice:$inv2", 'nonsense', $boss));
    $rel = $ln->add("invoice:$inv2", "invoice:$inv1", 'relates', $boss);
    eq(null, $ln->add("invoice:$inv1", "invoice:$inv2", 'relates', $boss), '"relates" works both ways');
    eq([['relates', "invoice:$inv2", 'súvisí s'], ['derived', "invoice:$inv2", 'vzniklo zo záznamu']],
        array_map(function ($l) { return [$l['kind'], $l['other'], $l['label']]; }, $ln->forRef("invoice:$inv1")));
    eq('z neho vzniklo', $ln->forRef("invoice:$inv2")[1]['label'], 'the other side');
    $panel = $e->recordPanel("invoice:$inv1");
    eq([], $panel['links'], 'links to records Eva may not see are hidden');
    eq(null, $e->recordPanel("invoice:$inv2"));
    eq(2, count($b->recordPanel("invoice:$inv1")['links']));
    $ln->delete($rel);
    eq(1, count($ln->forRef("invoice:$inv1")));

    // activity: Audit derives the reference; the feed shows only visible records
    Panel\Audit::log($db, $boss, 'invoice', $inv1, 'issue', 'A');
    Panel\Audit::log($db, $boss, 'invoice', $inv2, 'update', 'B');
    Panel\Audit::log($db, $boss, 'shop_order', 5, 'state', 'C', 'order:0:5');
    Panel\Audit::log($db, $boss, 'legislation', 1, 'save', 'D');
    eq(['A'], array_column($b->activity()->forRef("invoice:$inv1"), 'details'));
    eq(null, $db->fetchValue("SELECT ref FROM pt_audit WHERE details = 'D'"), 'no record → no reference');
    eq(['A'], array_column($e->activity()->feed($e->refs())['rows'], 'details'), 'Eva: only her company; the order source does not exist here');
    eq(['B', 'A'], array_column($b->activity()->feed($b->refs())['rows'], 'details'));
    $page = $b->activity()->feed($b->refs(), 0, 1);
    eq(['B'], array_column($page['rows'], 'details'));
    eq(['A'], array_column($b->activity()->feed($b->refs(), $page['next'], 1)['rows'], 'details'), 'next page');
});

test('records: migration 020 — old audit rows get references, internal order notes become comments', function () {
    $db = panelDb();
    $dir = __DIR__ . '/../src/applications/panel/sql';
    // migrate up to 019, put old data in, then 020
    $tmp = sys_get_temp_dir() . '/wa_mig_' . getmypid();
    @mkdir($tmp);
    foreach (glob("$tmp/*.sql") as $f) { unlink($f); }
    foreach (glob("$dir/*.sql") as $f) {
        if ((int)basename($f) <= 19) { copy($f, "$tmp/" . basename($f)); }
    }
    Schema::migrate($db, $tmp);
    $now = date('Y-m-d H:i:s');
    $db->execute("INSERT INTO pt_shop_connection (name, url, api_key, active, local_db, created_at) VALUES ('Vzdialený', 'https://x.sk', 'k', 1, 0, NOW())");
    $conn = $db->lastInsertId();
    foreach ([['invoice', 3, 'x'], ['shop_order', 10, 'ABC: stav'], ['shop_order', 11, 'Vzdialený XYZ: stav'], ['legislation', 1, 'y']] as $a) {
        $db->execute("INSERT INTO pt_audit (created_at, client_id, client_name, entity, entity_id, action, details) VALUES (?, 0, '', ?, ?, 'x', ?)", [$now, $a[0], $a[1], $a[2]]);
    }
    $db->execute("INSERT INTO pt_order_note (source, order_id, text, client_id, client_name, created_at) VALUES (0, 10, 'volal', 1, 'Eva', ?), ($conn, 11, 'pošleme', NULL, '', ?)", [$now, $now]);
    copy("$dir/020_records.sql", "$tmp/020_records.sql");
    eq(['020_records.sql'], Schema::migrate($db, $tmp));
    eq(['invoice:3', 'order:0:10', "order:$conn:11", null], array_column($db->query('SELECT ref FROM pt_audit ORDER BY id'), 'ref'));
    eq([['order:0:10', 'volal', 'Eva'], ["order:$conn:11", 'pošleme', '']],
        array_map(function ($r) { return [$r['ref'], $r['body'], $r['client_name']]; }, $db->query('SELECT * FROM pt_comment ORDER BY id')));
    $log = new Panel\Shop\OrderLog($db);
    eq(['volal'], array_column($log->notes(0, 10), 'text'), 'OrderLog reads the comments');
    $log->addNote(0, 10, 'nová', null);
    eq([10 => 2], $log->noteCounts(0, [10, 12]));
    foreach (glob("$tmp/*.sql") as $f) { unlink($f); }
    @rmdir($tmp);
});

class FakeMailer extends Panel\Mailer
{
    public $sent = [];
    public function __construct() { parent::__construct(['from' => 'panel@example.sk']); }
    public function configured(): bool { return true; }
    public function send(array $m): void { $this->sent[] = $m; }
}

/** Fake Nextcloud server: WebDAV folders/files in memory + the share API. */
function fakeNextcloud(array &$calls): callable
{
    $files = [];   // path => bytes ('' for folders, marked in $dirs)
    $dirs = ['/' => true];
    $shares = 0;
    return function (string $m, string $url, array $h, ?string $body, int $t) use (&$calls, &$files, &$dirs, &$shares): array {
        $calls[] = [$m, $url];
        if (strpos($url, '/status.php') !== false) {
            return [200, json_encode(['installed' => true, 'maintenance' => false, 'versionstring' => '35.0.3'])];
        }
        if (strpos($url, '/ocs/v2.php/apps/files_sharing/api/v1/shares') !== false) {
            if ($m === 'POST') {
                parse_str((string)$body, $f);
                if (!isset($dirs[$f['path']])) {
                    return [404, json_encode(['ocs' => ['meta' => ['message' => 'Wrong path, file/folder does not exist']]])];
                }
                $shares++;
                return [200, json_encode(['ocs' => ['data' => ['id' => (string)(100 + $shares), 'url' => 'https://nc.test/s/tok' . $shares,
                    'permissions' => (int)$f['permissions']]]])];
            }
            return [200, '{}'];
        }
        $p = '/' . rawurldecode(preg_replace('#^.*/remote\.php/dav/files/[^/]+/?#', '', $url));
        $p = $p === '/' ? '/' : rtrim($p, '/');
        switch ($m) {
            case 'MKCOL':
                if (isset($dirs[$p])) { return [405, '']; }
                $dirs[$p] = true;
                return [201, ''];
            case 'PUT':
                $files[$p] = (string)$body;
                return [201, ''];
            case 'GET':
                return isset($files[$p]) ? [200, $files[$p]] : [404, ''];
            case 'DELETE':
                unset($files[$p]);
                return [204, ''];
            case 'PROPFIND':
                if (!isset($dirs[$p])) { return [404, '']; }
                $base = '/remote.php/dav/files/panel' . ($p === '/' ? '' : implode('/', array_map('rawurlencode', explode('/', $p))));
                $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>' . $base . '/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
                foreach ($files as $fp => $bytes) {
                    if (dirname($fp) === $p) {
                        $xml .= '<d:response><d:href>' . $base . '/' . rawurlencode(basename($fp)) . '</d:href><d:propstat><d:prop><d:getlastmodified>Sat, 21 Nov 2026 10:00:00 GMT</d:getlastmodified>'
                            . '<d:getcontentlength>' . strlen($bytes) . '</d:getcontentlength><d:resourcetype/></d:prop></d:propstat></d:response>';
                    }
                }
                foreach ($dirs as $dp => $x) {
                    if ($dp !== '/' && $dp !== $p && dirname($dp) === $p) {
                        $xml .= '<d:response><d:href>' . $base . '/' . rawurlencode(basename($dp)) . '/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';
                    }
                }
                return [207, $xml . '</d:multistatus>'];
        }
        return [400, ''];
    };
}

test('scheduler: due rules (minutes, daily from an hour), lock, tick at most once a minute, the registered jobs', function () {
    $jobs = Panel\Core\Scheduler::jobs();
    foreach (['notifications.email', 'notifications.digest', 'accounting.package', 'reports.monthly_summary', 'expenses.recurring'] as $k) {
        ok(isset($jobs[$k]) && $jobs[$k]['label'] !== '', "job $k");
    }
    $now = strtotime('2026-11-21 10:00:00');
    $every5 = ['every' => 5, 'hour' => null];
    ok(Panel\Core\Scheduler::isDue($every5, null, $now), 'never ran');
    ok(!Panel\Core\Scheduler::isDue($every5, ['last_run_at' => '2026-11-21 09:57:00'], $now));
    ok(Panel\Core\Scheduler::isDue($every5, ['last_run_at' => '2026-11-21 09:55:00'], $now));
    $daily7 = ['every' => 1440, 'hour' => 7];
    ok(!Panel\Core\Scheduler::isDue($daily7, null, strtotime('2026-11-21 06:59:00')), 'not before 7:00');
    ok(Panel\Core\Scheduler::isDue($daily7, ['last_run_at' => '2026-11-20 23:00:00'], $now), 'new day');
    ok(!Panel\Core\Scheduler::isDue($daily7, ['last_run_at' => '2026-11-21 07:01:00'], $now), 'once a day');

    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $sys = (new TestPanelContext($db, null))->system();
    $s = new Panel\Core\Scheduler($db);
    $r = $s->run($sys, 'expenses.recurring', true);
    eq([['expenses.recurring', true, 'vytvorených dokladov: 0']], $r);
    eq('vytvorených dokladov: 0', $s->state()['expenses.recurring']['last_message']);
    eq([], $s->run($sys, 'expenses.recurring'), 'not due again the same day');
    $db->execute("UPDATE pt_job_run SET running_until = ? WHERE job = 'expenses.recurring'", [date('Y-m-d H:i:s', time() + 300)]);
    eq([], $s->run($sys, 'expenses.recurring', true), 'locked by another run');
    ok($s->tickDue(1000000), 'first tick');
    ok(!$s->tickDue(1000030), 'second tick within a minute');
    ok($s->tickDue(1000061));
    $ran = array_column($s->run($sys, null, false, strtotime('2026-11-22 08:00:00')), 0);
    ok(in_array('notifications.email', $ran, true) && in_array('notifications.digest', $ran, true), implode(',', $ran));
});

test('notifications: mentions and the discussion, only for people who may see the record, bell, immediate e-mail and daily digest', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $fields = ['street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '', 'registration' => '', 'email' => 'firma@example.sk',
        'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
    $c1 = $companies->save(null, ['name' => 'Firma A'] + $fields);
    $db->execute("INSERT INTO pt_invoice (company_id, doc_type, status, number, issue_date, delivery_date, due_date, currency, created_at, updated_at)
        VALUES (?, 'invoice', 'issued', 'FA1', '2026-11-01', '2026-11-01', '2026-11-15', 'EUR', NOW(), NOW())", [$c1]);
    $inv = $db->lastInsertId();
    $boss = panelUser($db, 'boss@example.sk', 100);
    $boss->name = 'Šéf Hlavný'; $boss->update();
    $eva = panelUser($db, 'eva@example.sk', 10);
    $eva->name = 'Eva Malá'; $eva->update();
    $jan = panelUser($db, 'jan@example.sk', 10);
    $jan->name = 'Ján Novák'; $jan->update();
    (new Panel\Core\Roles($db))->savePerson($eva->getId(), ['admin' => false, 'roles' => [], 'allow' => [Rights::INVOICES_VIEW], 'deny' => [],
        'all_companies' => true, 'companies' => [], 'all_shops' => true, 'shops' => []]);
    $b = new TestPanelContext($db, $boss);
    $ref = "invoice:$inv";
    $record = $b->refs()->resolve($ref);
    $body = '@Eva Malá a @Ján Novák pozrite';
    $id = $b->comments()->add($ref, $body, $boss);
    eq(1, Panel\Core\Events::emit($b, 'comment.added', ['ref' => $ref, 'actor' => $boss, 'record' => $record, 'comment_id' => $id, 'body' => $body,
        'mentions' => $b->comments()->mentionedIn($body), 'thread' => false]), 'the notification listener ran');
    $n = $b->notifications();
    eq(1, $n->unreadCount($eva->getId()), 'Eva may see the invoice');
    eq(0, $n->unreadCount($jan->getId()), 'Ján may not (no permission) — not notified');
    eq(0, $n->unreadCount($boss->getId()), 'not the author');
    $row = $n->forPerson($eva->getId())[0];
    eq(['mention', $ref, 'pending'], [$row['event'], $row['ref'], $row['email_state']]);
    contains('Šéf Hlavný vás spomenul — Faktúra Faktúra FA1', $row['text']);

    // Eva answers → the boss (earlier participant) hears about it
    $e = new TestPanelContext($db, $eva);
    $id2 = $e->comments()->add($ref, 'pozriem', $eva);
    Panel\Core\Events::emit($e, 'comment.added', ['ref' => $ref, 'actor' => $eva, 'record' => $e->refs()->resolve($ref), 'comment_id' => $id2,
        'body' => 'pozriem', 'mentions' => [], 'thread' => true]);
    eq('comment', $n->forPerson($boss->getId())[0]['event']);

    // e-mails
    $mail = new FakeMailer();
    $sys = $b->system();
    eq('e-mailov: 2', $n->sendPending($sys, $mail));
    eq([['boss@example.sk'], ['eva@example.sk']], array_column($mail->sent, 'to'), 'one e-mail per person');
    contains('http://panel.test/panel/r/' . $ref, $mail->sent[1]['text']);
    eq('nič na odoslanie', $n->sendPending($sys, $mail), 'sent once');
    eq('e-maily nie sú nastavené (config.php → apps.panel.mail) — upozornenia sú len v paneli',
        $n->sendPending($sys, new Panel\Mailer([])) === 'nič na odoslanie' ? 'e-maily nie sú nastavené (config.php → apps.panel.mail) — upozornenia sú len v paneli' : 'x');
    $n->setMode($eva->getId(), 'daily');
    eq('daily', $n->mode($eva->getId()));
    throws(function () use ($n, $eva) { $n->setMode($eva->getId(), 'often'); }, InvalidArgumentException::class);
    $n->notify($b, $eva->getId(), $ref, 'mention', 'A', 'x');
    $n->notify($b, $eva->getId(), $ref, 'mention', 'B', 'x');
    $mail->sent = [];
    eq('nič na odoslanie', $n->sendPending($sys, $mail), 'daily people wait for the digest');
    eq('e-mailov: 1', $n->sendDigest($sys, $mail));
    contains('2 nové upozornenia', $mail->sent[0]['text']);
    $n->setMode($eva->getId(), 'off');
    $n->notify($b, $eva->getId(), $ref, 'mention', 'C', 'x');
    eq('none', $n->forPerson($eva->getId())[0]['email_state'], 'off = only the bell');
    $n->markRead($eva->getId());
    eq(0, $n->unreadCount($eva->getId()));
    // read in the panel before the e-mail went out → no e-mail
    $n->setMode($jan->getId(), 'immediate');
    $n->setMode($eva->getId(), 'immediate');
    $n->notify($b, $eva->getId(), $ref, 'mention', 'D', 'x');
    $n->markRead($eva->getId());
    $mail->sent = [];
    $n->sendPending($sys, $mail);
    eq([], $mail->sent, 'already read');
    $eva->status = 0; $eva->update();
    ok(!$n->notify($b, $eva->getId(), $ref, 'mention', 'E'), 'inactive person');
});

test('nextcloud: status + login check, folder listing, folders, upload / download / delete, share links, readable errors', function () {
    $calls = [];
    $nc = new Panel\Core\Nextcloud('https://nc.test/', 'panel', 'app-pass', fakeNextcloud($calls));
    eq('35.0.3', $nc->check());
    eq(null, $nc->listFolder('Panel/X'), 'missing folder');
    $nc->ensureFolder('Panel/Firma A/Faktúra/invoice-1');
    $nc->ensureFolder('Panel/Firma A/Faktúra/invoice-1');   // again: existing folders are fine
    eq([], $nc->listFolder('Panel/Firma A/Faktúra/invoice-1'));
    $nc->upload('Panel/Firma A/Faktúra/invoice-1/zmluva č.1.pdf', 'PDF');
    $list = $nc->listFolder('Panel/Firma A/Faktúra/invoice-1');
    eq([['zmluva č.1.pdf', false, 3, '2026-11-21']], array_map(function ($f) { return [$f['name'], $f['dir'], $f['size'], substr($f['modified'], 0, 10)]; }, $list));
    eq(['Faktúra'], array_column($nc->listFolder('Panel/Firma A'), 'name'), 'sub-folder listed');
    eq('PDF', $nc->download('Panel/Firma A/Faktúra/invoice-1/zmluva č.1.pdf'));
    list($id, $url) = $nc->share('Panel/Firma A/Faktúra/invoice-1', 'upload', 'tajne-heslo', '2026-12-31');
    eq(['101', 'https://nc.test/s/tok1'], [$id, $url]);
    $sent = [];
    $wide = new Panel\Core\Nextcloud('https://nc.test', 'panel', 'x', function ($m, $u, $h, $b) use (&$sent) {
        $sent[] = [$m, (string)$b];
        if ($m === 'POST') { return [200, json_encode(['ocs' => ['data' => ['id' => '7', 'url' => 'https://nc.test/s/w', 'permissions' => 15]]])]; }
        if ($m === 'PUT') { return [200, json_encode(['ocs' => ['data' => ['id' => '7', 'permissions' => 15]]])]; }   // refuses to narrow
        return [200, '{}'];
    });
    throws(function () use ($wide) { $wide->share('Panel/x', 'upload', null, null); }, RuntimeException::class);
    eq(['POST', 'PUT', 'DELETE'], array_column($sent, 0), 'a link with more rights than asked is deleted again');
    ok(strpos($sent[0][1], 'publicUpload') === false && strpos($sent[0][1], 'permissions=4') !== false, 'file drop = create only');
    throws(function () use ($nc) { $nc->share('Panel/nie-je', 'read', null, null); }, RuntimeException::class);
    $nc->delete('Panel/Firma A/Faktúra/invoice-1/zmluva č.1.pdf');
    eq([], $nc->listFolder('Panel/Firma A/Faktúra/invoice-1'));
    ok(in_array(['MKCOL', 'https://nc.test/remote.php/dav/files/panel/Panel/Firma%20A/Fakt%C3%BAra'], $calls, true), 'paths encoded');
    $down = new Panel\Core\Nextcloud('https://nc.test', 'panel', 'x', function () { return [0, '']; });
    try { $down->check(); ok(false); } catch (RuntimeException $e) { contains('dočasne nedostupné', $e->getMessage()); }
    $bad = new Panel\Core\Nextcloud('https://nc.test', 'panel', 'x', function () { return [401, '']; });
    try { $bad->listFolder('a'); ok(false); } catch (RuntimeException $e) { contains('heslo aplikácie', $e->getMessage()); }
    throws(function () { new Panel\Core\Nextcloud('nc.test', 'u', 'p'); }, InvalidArgumentException::class);
});

test('files of records + integrations: folder per record (fixed), names, duplicates, shares; secrets encrypted, kept when empty', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $companies = new Companies($db);
    $fields = ['street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '', 'registration' => '', 'email' => '',
        'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
    $c1 = $companies->save(null, ['name' => 'Firma/A: s.r.o.'] + $fields);
    $boss = panelUser($db, 'boss5', 100);
    $ctx = new TestPanelContext($db, $boss);
    $calls = [];
    $files = new Panel\Core\Files($ctx);
    $files->setClient(new Panel\Core\Nextcloud('https://nc.test', 'panel', 'p', fakeNextcloud($calls)));
    $rec = ['ref' => 'invoice:7', 'label' => 'Faktúra', 'title' => 'FA1', 'url' => '/invoices/7', 'company_id' => $c1];
    eq([], $files->listFiles($rec), 'no folder yet');
    eq(null, $files->folder($rec, false));
    eq('a.pdf', $files->upload($rec, '../a.pdf', 'x'));
    eq('Panel/Firma A s.r.o./Faktúra/invoice-7', $files->folder($rec, false), 'company name made safe, ref in the name');
    eq('a (2).pdf', $files->upload($rec, 'a.pdf', 'y'), 'same name → (2)');
    eq(['a (2).pdf', 'a.pdf'], array_column($files->listFiles($rec), 'name'));
    eq('y', $files->download($rec, 'a (2).pdf'));
    throws(function () use ($files, $rec) { $files->download($rec, '../../etc/passwd'); }, RuntimeException::class);
    $db->execute("UPDATE pt_company SET name = 'Iné meno' WHERE id = ?", [$c1]);
    eq('Panel/Firma A s.r.o./Faktúra/invoice-7', $files->folder($rec, true), 'the folder stays after renaming the company');
    eq('Panel/Spoločné/Rola/role-2', $files->folder(['ref' => 'role:2', 'label' => 'Rola', 'company_id' => 0], true));
    $s = $files->share($rec, 'read', '', 14);
    eq(['read', 'https://nc.test/s/tok1', 0, date('Y-m-d', strtotime('+14 days'))], [$s['kind'], $s['url'], (int)$s['has_password'], $s['expires']]);
    throws(function () use ($files, $rec) { $files->share($rec, 'read', 'kratke', 7); }, InvalidArgumentException::class);
    eq(1, count($files->shares('invoice:7')));
    $files->revoke($s);
    eq([], $files->shares('invoice:7'));
    eq('', Panel\Core\Files::safeName('..'));
    eq('b.txt', Panel\Core\Files::fileName("C:\\x\\a\x01/b.txt"), 'path dropped');
    eq('a b.txt', Panel\Core\Files::safeName("a\x01/b.txt"), 'one segment');
    ok(Panel\Core\Files::uploadLimit() > 0 && Panel\Core\Files::uploadLimit() <= 100 * 1048576);

    // integrations
    $int = new Panel\Core\Integrations($db, new Panel\Secret('test-secret-1234567890'));
    eq(null, $int->config('nextcloud'), 'not set up');
    $int->save('nextcloud', ['url' => 'https://nc.test', 'user' => 'panel', 'password' => 'tajne-heslo', 'root' => 'Panel'], true);
    eq('tajne-heslo', $int->config('nextcloud')['password']);
    ok(strpos((string)$db->fetchValue("SELECT config FROM pt_integration WHERE name = 'nextcloud'"), 'tajne-heslo') === false, 'stored encrypted');
    $fv = $int->formValues('nextcloud');
    eq(['', true, 'panel'], [$fv['password'], $fv['password_stored'], $fv['user']], 'the form never gets the secret back');
    $int->save('nextcloud', ['url' => 'https://nc2.test', 'user' => 'panel', 'password' => '', 'root' => ''], true);
    eq(['https://nc2.test', 'tajne-heslo'], [$int->config('nextcloud')['url'], $int->config('nextcloud')['password']], 'empty secret = unchanged');
    eq(null, (new Panel\Core\Integrations($db, new Panel\Secret('another-secret-0987654321')))->config('nextcloud'), 'other key = unreadable');
    $int->save('nextcloud', ['url' => 'https://nc2.test', 'user' => 'panel', 'password' => '', 'root' => ''], false);
    eq(null, $int->config('nextcloud'), 'disabled');
    $int->setStatus('nextcloud', true, 'funguje');
    eq('ok', $int->row('nextcloud')['status']);
    throws(function () use ($int) { $int->save('dropbox', [], true); }, InvalidArgumentException::class);
});

test('documents + calendar + search: number series of module documents, month grid, due dates, more search results', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    throws(function () {
        $m = new class implements Panel\Module, Panel\Core\ProvidesDocumentTypes {
            public function routes(WebApplication\Http\Router $r, callable $g, Panel\PanelContext $c): void {}
            public function nav(): array { return []; }
            public function settingsTabs(): array { return []; }
            public function permissions(): array { return []; }
            public function recordTypes(): array { return []; }
            public function documentTypes(): array { return ['invoice' => ['label' => 'x', 'table' => 'x', 'format' => 'X{NNNN}']]; }
        };
        Panel\Core\Documents::fromModules([$m]);
    }, LogicException::class);
    $m2 = new class implements Panel\Module, Panel\Core\ProvidesDocumentTypes {
        public function routes(WebApplication\Http\Router $r, callable $g, Panel\PanelContext $c): void {}
        public function nav(): array { return []; }
        public function settingsTabs(): array { return []; }
        public function permissions(): array { return []; }
        public function recordTypes(): array { return []; }
        public function documentTypes(): array { return ['quote' => ['label' => 'Cenová ponuka', 'table' => 'quote', 'format' => 'CP{RRRR}/{NNNN}']]; }
    };
    eq(['quote' => ['label' => 'Cenová ponuka', 'table' => 'quote', 'format' => 'CP{RRRR}/{NNNN}']], Panel\Core\Documents::fromModules([$m2]));
    $companies = new Companies($db);
    $fields = ['street' => '', 'city' => '', 'zip' => '', 'country' => 'SK', 'ico' => '', 'dic' => '', 'registration' => '', 'email' => '',
        'phone' => '', 'web' => '', 'slogan' => '', 'due_days' => 14, 'invoice_note' => '', 'accent_color' => '#f74040', 'active' => 1];
    $c1 = $companies->save(null, ['name' => 'Firma A'] + $fields);
    throws(function () use ($db, $c1) { $db->transaction(function ($db) use ($c1) { Panel\Core\Documents::allocate($db, $c1, 'quote', '2026-11-21'); }); },
        InvalidArgumentException::class);
    // a series of the company for a module type (normally created by allocate from the registered default format)
    $companies->saveSeries(null, $c1, ['doc_type' => 'invoice', 'name' => 'FA', 'format' => 'FA{RRRR}/{NNN}', 'vs_format' => '', 'reset_yearly' => 1,
        'is_default' => 1, 'active' => 1]);
    $n = $db->transaction(function ($db) use ($c1) { return Panel\Core\Documents::allocate($db, $c1, 'invoice', '2026-11-21'); });
    eq('FA2026/001', $n['number']);
    eq('FA2026/002', $db->transaction(function ($db) use ($c1) { return Panel\Core\Documents::allocate($db, $c1, 'invoice', '2026-11-21'); })['number']);

    $weeks = Panel\Core\Calendar::monthGrid('2026-11');
    eq(['2026-10-26', '2026-12-06', 6], [$weeks[0][0], end($weeks)[6], count($weeks)]);
    $boss = panelUser($db, 'boss6', 100);
    $ctx = new TestPanelContext($db, $boss);
    $db->execute("INSERT INTO pt_invoice (company_id, doc_type, status, number, issue_date, delivery_date, due_date, currency, total, buyer_name, created_at, updated_at)
        VALUES (?, 'invoice', 'issued', 'FA9', '2026-11-01', '2026-11-01', '2026-11-10', 'EUR', 50, 'Zákazník', NOW(), NOW())", [$c1]);
    $invId = $db->lastInsertId();
    $ev = Panel\Core\Calendar::events($ctx, '2026-11-01', '2026-11-30');
    eq(['2026-11-10'], array_keys($ev));
    eq(['over', '/invoices/' . $invId], [$ev['2026-11-10'][0]['kind'], $ev['2026-11-10'][0]['url']]);
    contains('Splatnosť FA9 Zákazník (50,00 EUR)', $ev['2026-11-10'][0]['title']);
    eq([], Panel\Core\Calendar::events(new TestPanelContext($db, panelUser($db, 'nobody6', 10)), '2026-11-01', '2026-11-30'), 'no permission → no dates');
    eq(1, count($ctx->refs()->find('FA9', 60)));
});

test('API keys: shown once, only the hash stored, never more than the author, revoked / expired / inactive author, rate limit', function () {
    $db = panelDb();
    Schema::migrate($db, __DIR__ . '/../src/applications/panel/sql');
    $boss = panelUser($db, 'boss7', 100);
    $ana = panelUser($db, 'ana7', 10);
    (new Panel\Core\Roles($db))->savePerson($ana->getId(), ['admin' => false, 'roles' => [], 'allow' => [Rights::ORDERS_VIEW], 'deny' => [],
        'all_companies' => false, 'companies' => [], 'all_shops' => true, 'shops' => []]);
    $keys = new Panel\Core\ApiKeys($db);
    throws(function () use ($keys, $boss) { $keys->create($boss, ' ', [], 30); }, InvalidArgumentException::class);
    list($k, $token) = $keys->create($boss, 'CI', [Rights::ORDERS_VIEW, 'nonsense'], 30);
    ok(preg_match('/^pk_[0-9a-f]{8}_[A-Za-z0-9_-]{40}$/', $token) === 1, $token);
    eq([Rights::ORDERS_VIEW], $k['permissions']);
    ok(strpos((string)$db->fetchValue('SELECT hash FROM pt_api_key WHERE id = ?', [$k['id']]), substr($token, 12)) === false, 'secret not stored');
    list($key, $owner, $err) = $keys->authenticate($token, '1.2.3.4');
    eq([$k['id'], $boss->getId(), ''], [$key['id'], $owner->getId(), $err]);
    eq('1.2.3.4', $keys->find((int)$k['id'])['last_ip']);
    eq('Neplatný API kľúč.', $keys->authenticate(substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A'), '')[2]);
    ok($keys->authenticate('Bearer x', '')[0] === null);

    // the key acts as the boss but only with its permissions
    $ctx = (new TestPanelContext($db, null))->asApiKey($owner, $key['permissions']);
    ok($ctx->can(Rights::ORDERS_VIEW) && !$ctx->can(Rights::INVOICES_VIEW) && !$ctx->isAdmin(), 'limited Správca');
    ok($ctx->access()->allCompanies(), 'scope of the owner stays');
    // Ana's key asking for more than she has gets only what she has
    list($ka, $ta) = $keys->create($ana, 'skript', [Rights::ORDERS_VIEW, Rights::INVOICES_EDIT], 0);
    eq(null, $ka['expires_at'], 'without expiry');
    $actx = (new TestPanelContext($db, null))->asApiKey($ana, $ka['permissions']);
    ok($actx->can(Rights::ORDERS_VIEW) && !$actx->can(Rights::INVOICES_EDIT), 'never more than the author');

    $keys->revoke((int)$k['id'], 'Boss');
    eq('API kľúč bol zrušený.', $keys->authenticate($token, '')[2]);
    $db->execute('UPDATE pt_api_key SET expires_at = ? WHERE id = ?', ['2020-01-01', $ka['id']]);
    contains('Platnosť API kľúča skončila', $keys->authenticate($ta, '')[2]);
    $db->execute('UPDATE pt_api_key SET expires_at = NULL WHERE id = ?', [$ka['id']]);
    $ana->status = 0; $ana->update();
    eq('Autor API kľúča už nemá aktívny účet.', $keys->authenticate($ta, '')[2]);
    ok(!$keys->overLimit((int)$ka['id']));
    for ($i = 0; $i < Panel\Core\ApiKeys::RATE; $i++) {
        $keys->log((int)$ka['id'], 'GET', '/api/v1/me', 200, '');
    }
    ok($keys->overLimit((int)$ka['id']));
    eq(5, count($keys->calls((int)$ka['id'], 5)));
    eq(2, count($keys->all()));
    eq(1, count($keys->forOwner($ana->getId())));
});
