<?php
namespace Panel;

use WebApplication\Database\Database;

/**
 * Employee permissions.
 * - Administrator = panel_client.rights >= ADMIN: every permission, every company, manages employees.
 * - Other employees (rights = EMPLOYEE): the permissions ticked for them (table <prefix>permission) and only the
 *   companies they were given access to (table <prefix>company_access). New modules (e.g. tasks) add permissions here.
 */
class Rights
{
    const EMPLOYEE = 10;
    const ADMIN = 100;

    const ORDERS_VIEW = 'orders.view';
    const ORDERS_EDIT = 'orders.edit';
    const INVOICES_VIEW = 'invoices.view';
    const INVOICES_EDIT = 'invoices.edit';
    const COMPANIES_MANAGE = 'companies.manage';
    const STOCK_VIEW = 'stock.view';
    const STOCK_EDIT = 'stock.edit';

    /** Pseudo-permission used by route guards: administrators only. */
    const ADMIN_ONLY = 'admin';

    /** @var Database */ private $db;
    /** @var int */ private $clientId;
    /** @var bool */ private $admin;
    /** @var string[]|null */ private $permissions = null;
    /** @var int[]|null */ private $companies = null;

    public function __construct(Database $db, int $clientId, int $rights)
    {
        $this->db = $db;
        $this->clientId = $clientId;
        $this->admin = $rights >= self::ADMIN;
    }

    /** permission => label, in the order shown on the employee form. */
    public static function permissionLabels(): array
    {
        return [
            self::ORDERS_VIEW => 'Objednávky z e-shopov — prezeranie',
            self::ORDERS_EDIT => 'Objednávky — zmena stavu v e-shope',
            self::INVOICES_VIEW => 'Faktúry — prezeranie',
            self::INVOICES_EDIT => 'Faktúry — vystavovanie (koncepty, vystavenie, dobropisy, odberatelia)',
            self::COMPANIES_MANAGE => 'Firmy — nastavenia, bankové účty, číselné rady',
            self::STOCK_VIEW => 'Sklad — prezeranie stavu a pohybov',
            self::STOCK_EDIT => 'Sklad — príjemky, výdajky, prevodky, inventúra, karty a sklady',
        ];
    }

    public static function roleLabel(int $rights): string
    {
        return $rights >= self::ADMIN ? 'Správca' : 'Zamestnanec';
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function can(string $permission): bool
    {
        if ($this->admin) {
            return true;
        }
        if ($permission === self::ADMIN_ONLY) {
            return false;
        }
        return in_array($permission, $this->permissions(), true);
    }

    /** @return string[] */
    public function permissions(): array
    {
        if ($this->permissions === null) {
            $this->permissions = self::permissionsOf($this->db, $this->clientId);
        }
        return $this->permissions;
    }

    /** Ids of companies this employee may work with (administrator: all). @return int[] */
    public function companyIds(): array
    {
        if ($this->companies === null) {
            $this->companies = $this->admin
                ? array_map('intval', array_column($this->db->query('SELECT id FROM ' . $this->db->table('company')), 'id'))
                : self::companiesOf($this->db, $this->clientId);
        }
        return $this->companies;
    }

    public function canCompany(int $companyId): bool
    {
        return in_array($companyId, $this->companyIds(), true);
    }

    /** @return string[] */
    public static function permissionsOf(Database $db, int $clientId): array
    {
        return array_column($db->query('SELECT permission FROM ' . $db->table('permission') . ' WHERE client_id = ?', [$clientId]), 'permission');
    }

    /** @return int[] */
    public static function companiesOf(Database $db, int $clientId): array
    {
        return array_map('intval', array_column(
            $db->query('SELECT company_id FROM ' . $db->table('company_access') . ' WHERE client_id = ?', [$clientId]), 'company_id'));
    }

    /** Replaces the permissions and company access of an employee. */
    public static function save(Database $db, int $clientId, array $permissions, array $companyIds): void
    {
        $db->transaction(function (Database $db) use ($clientId, $permissions, $companyIds) {
            $db->execute('DELETE FROM ' . $db->table('permission') . ' WHERE client_id = ?', [$clientId]);
            foreach (array_unique($permissions) as $p) {
                if (array_key_exists($p, self::permissionLabels())) {
                    $db->execute('INSERT INTO ' . $db->table('permission') . ' (client_id, permission) VALUES (?, ?)', [$clientId, $p]);
                }
            }
            $db->execute('DELETE FROM ' . $db->table('company_access') . ' WHERE client_id = ?', [$clientId]);
            foreach (array_unique(array_map('intval', $companyIds)) as $id) {
                if ($id > 0) {
                    $db->execute('INSERT INTO ' . $db->table('company_access') . ' (client_id, company_id) VALUES (?, ?)', [$clientId, $id]);
                }
            }
        });
    }

    /** Gives one employee access to one company (e.g. the creator of a new company). */
    public static function grantCompany(Database $db, int $clientId, int $companyId): void
    {
        $db->execute('INSERT IGNORE INTO ' . $db->table('company_access') . ' (client_id, company_id) VALUES (?, ?)', [$clientId, $companyId]);
    }
}
