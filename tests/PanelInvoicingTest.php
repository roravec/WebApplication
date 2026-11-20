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

test('panel: migrations run once and convert old rights levels', function () {
    $db = panelDb();
    $viewer = panelUser($db, 'viewer', 10);
    $invoicer = panelUser($db, 'invoicer', 50);
    $admin = panelUser($db, 'boss', 100);
    $dir = __DIR__ . '/../src/applications/panel/sql';
    ok(count(Schema::migrate($db, $dir)) >= 1, 'applied');
    eq([], Schema::migrate($db, $dir), 'second run does nothing');
    eq([Rights::INVOICES_VIEW, Rights::ORDERS_VIEW], (function ($p) { sort($p); return $p; })(Rights::permissionsOf($db, $viewer->getId())));
    ok(in_array(Rights::INVOICES_EDIT, Rights::permissionsOf($db, $invoicer->getId()), true));
    eq(10, (int)$db->fetchValue('SELECT rights FROM pt_client WHERE id = ?', [$invoicer->getId()]));
    eq(100, (int)$db->fetchValue('SELECT rights FROM pt_client WHERE id = ?', [$admin->getId()]));
    $r = new Rights($db, $viewer->getId(), 10);
    ok($r->can(Rights::ORDERS_VIEW) && !$r->can(Rights::INVOICES_EDIT) && !$r->can(Rights::ADMIN_ONLY));
    ok((new Rights($db, $admin->getId(), 100))->can(Rights::ADMIN_ONLY));
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
