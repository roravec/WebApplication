<?php
namespace Panel\Core;

use Panel\Rights;
use WebApplication\Database\Database;

/**
 * What one person may do (docs/panel-architektura.md chapter 5) — the ONE check used by routes, screens, lists,
 * exports and later the API: can(permission), companies (canCompany / companyIds / allCompanies) and e-shops
 * (canShop / shopIdsFor / allShops). Správca (client.rights >= 100) may everything everywhere. What is not allowed
 * explicitly is forbidden; nobody logged in = nothing.
 * Layer 3 (sharing of single records: private / project / company / selected people) comes with the records that need
 * it (tasks, documents — phase 2 and 4), through the record references of the core.
 */
class Access
{
    /** @var Database */ private $db;
    /** @var int */ private $clientId;
    /** @var int */ private $rights;
    /** @var array|null */ private $person = null;
    /** @var string[]|null */ private $permissions = null;
    /** @var int[]|null */ private $companies = null;

    public function __construct(Database $db, int $clientId, int $rights)
    {
        $this->db = $db;
        $this->clientId = $clientId;
        $this->rights = $rights;
    }

    public function isAdmin(): bool
    {
        return $this->clientId > 0 && $this->rights >= Rights::ADMIN;
    }

    /** Stored access (roles, exceptions, scope) — Roles::person(). */
    public function person(): array
    {
        if ($this->person === null) {
            $this->person = $this->clientId > 0 ? (new Roles($this->db))->person($this->clientId, $this->rights)
                : ['admin' => false, 'roles' => [], 'allow' => [], 'deny' => [], 'all_companies' => false, 'companies' => [], 'all_shops' => false, 'shops' => []];
        }
        return $this->person;
    }

    /** @param string $permission registered key (Rights::…) or Rights::ADMIN_ONLY */
    public function can(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        return $permission !== Rights::ADMIN_ONLY && in_array($permission, $this->permissions(), true);
    }

    /** Effective permissions (Správca: all registered). @return string[] */
    public function permissions(): array
    {
        if ($this->permissions === null) {
            $this->permissions = $this->clientId > 0 ? (new Roles($this->db))->effective($this->person()) : [];
        }
        return $this->permissions;
    }

    // ------------------------------------------------------------------ companies

    /** All companies, also the ones created later (and data without a company, e.g. an e-shop not assigned to any). */
    public function allCompanies(): bool
    {
        return $this->isAdmin() || $this->person()['all_companies'];
    }

    /** @return int[] */
    public function companyIds(): array
    {
        if ($this->companies === null) {
            $this->companies = $this->allCompanies()
                ? array_map('intval', array_column($this->db->query('SELECT id FROM ' . $this->db->table('company')), 'id'))
                : $this->person()['companies'];
        }
        return $this->companies;
    }

    public function canCompany(int $companyId): bool
    {
        return in_array($companyId, $this->companyIds(), true);
    }

    // ------------------------------------------------------------------ e-shops

    public function allShops(): bool
    {
        return $this->isAdmin() || $this->person()['all_shops'];
    }

    /** @param int $source order source: 0 = e-shops in this database, N = shop connection N */
    public function canShop(int $source, int $shopId): bool
    {
        return $this->allShops() || in_array($source . ':' . $shopId, $this->person()['shops'], true);
    }

    /** Allowed shop ids of one order source; null = all. @return int[]|null */
    public function shopIdsFor(int $source): ?array
    {
        if ($this->allShops()) {
            return null;
        }
        $out = [];
        foreach ($this->person()['shops'] as $key) {
            list($s, $id) = array_map('intval', explode(':', $key));
            if ($s === $source) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** "Správca" or the names of the roles (layout, list of employees). */
    public function roleLabel(): string
    {
        if ($this->isAdmin()) {
            return 'Správca';
        }
        $names = (new Roles($this->db))->roleNamesByClient()[$this->clientId] ?? [];
        return $names ? implode(', ', $names) : 'Zamestnanec';
    }
}
