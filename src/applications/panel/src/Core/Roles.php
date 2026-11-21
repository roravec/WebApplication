<?php
namespace Panel\Core;

use WebApplication\Database\Database;

/**
 * Roles (named, editable sets of permissions) and the access of one person (docs/panel-architektura.md chapter 5):
 * Správca (client.rights >= 100) = everything; otherwise permissions = permissions of the person's roles + allowed
 * exceptions − denied exceptions; scope = all companies or the listed ones, all e-shops or the listed ones.
 *
 * A person's access as an array (person(), savePerson(), effective()):
 *   admin bool, roles int[], allow string[], deny string[], all_companies bool, companies int[], all_shops bool,
 *   shops string[] ("source:id_shop", source 0 = this database, else the connection id — like company_shop)
 */
class Roles
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function t(string $name): string
    {
        return $this->db->table($name);
    }

    // ------------------------------------------------------------------ roles

    /** @return array list of roles: id, name, description, permissions string[], holders int (people with the role) */
    public function all(): array
    {
        $perms = [];
        foreach ($this->db->query("SELECT role_id, permission FROM {$this->t('role_permission')}") as $r) {
            $perms[(int)$r['role_id']][] = $r['permission'];
        }
        $out = [];
        foreach ($this->db->query("SELECT r.*, (SELECT COUNT(*) FROM {$this->t('client_role')} cr WHERE cr.role_id = r.id) AS holders
                FROM {$this->t('role')} r ORDER BY r.name") as $r) {
            $r['id'] = (int)$r['id'];
            $r['holders'] = (int)$r['holders'];
            $r['permissions'] = Permissions::clean($perms[$r['id']] ?? []);
            $out[] = $r;
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /** @return string|null error */
    public function nameError(string $name, ?int $id): ?string
    {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
            return 'Zadajte názov roly (najviac 100 znakov).';
        }
        $other = $this->db->fetchValue("SELECT id FROM {$this->t('role')} WHERE name = ?", [$name]);
        return $other !== false && $other !== null && (int)$other !== (int)$id ? 'Rola s týmto názvom už existuje.' : null;
    }

    /** @return int the role id */
    public function save(?int $id, string $name, string $description, array $permissions): int
    {
        return $this->db->transaction(function (Database $db) use ($id, $name, $description, $permissions) {
            if ($id) {
                $db->execute("UPDATE {$this->t('role')} SET name = ?, description = ? WHERE id = ?", [$name, $description, $id]);
            } else {
                $db->execute("INSERT INTO {$this->t('role')} (name, description) VALUES (?, ?)", [$name, $description]);
                $id = $db->lastInsertId();
            }
            $db->execute("DELETE FROM {$this->t('role_permission')} WHERE role_id = ?", [$id]);
            foreach (Permissions::clean($permissions) as $p) {
                $db->execute("INSERT INTO {$this->t('role_permission')} (role_id, permission) VALUES (?, ?)", [$id, $p]);
            }
            return (int)$id;
        });
    }

    /** Deletes the role and takes it away from its holders. */
    public function delete(int $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            $db->execute("DELETE FROM {$this->t('client_role')} WHERE role_id = ?", [$id]);
            $db->execute("DELETE FROM {$this->t('role_permission')} WHERE role_id = ?", [$id]);
            $db->execute("DELETE FROM {$this->t('role')} WHERE id = ?", [$id]);
        });
    }

    /** @return int[] ids of the people who have the role */
    public function holders(int $roleId): array
    {
        return array_map('intval', array_column($this->db->query("SELECT client_id FROM {$this->t('client_role')} WHERE role_id = ?", [$roleId]), 'client_id'));
    }

    /** @return array client id => role names (sorted) */
    public function roleNamesByClient(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT cr.client_id, r.name FROM {$this->t('client_role')} cr JOIN {$this->t('role')} r ON r.id = cr.role_id
                ORDER BY r.name") as $r) {
            $out[(int)$r['client_id']][] = $r['name'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ one person

    /** Access of a person as stored (see the class comment). */
    public function person(int $clientId, int $rights): array
    {
        $exc = $this->db->query("SELECT permission, allow FROM {$this->t('permission')} WHERE client_id = ?", [$clientId]);
        $scope = $this->db->fetchOne("SELECT all_companies, all_shops FROM {$this->t('client_scope')} WHERE client_id = ?", [$clientId]);
        $shops = [];
        foreach ($this->db->query("SELECT connection_id, shop_id FROM {$this->t('shop_access')} WHERE client_id = ?", [$clientId]) as $s) {
            $shops[] = (int)$s['connection_id'] . ':' . (int)$s['shop_id'];
        }
        return [
            'admin' => $rights >= \Panel\Rights::ADMIN,
            'roles' => array_map('intval', array_column($this->db->query("SELECT role_id FROM {$this->t('client_role')} WHERE client_id = ?", [$clientId]), 'role_id')),
            'allow' => array_values(array_column(array_filter($exc, function ($e) { return (int)$e['allow'] === 1; }), 'permission')),
            'deny' => array_values(array_column(array_filter($exc, function ($e) { return (int)$e['allow'] === 0; }), 'permission')),
            'all_companies' => $scope !== false && (bool)$scope['all_companies'],
            'companies' => array_map('intval', array_column($this->db->query("SELECT company_id FROM {$this->t('company_access')} WHERE client_id = ?", [$clientId]), 'company_id')),
            'all_shops' => $scope === false || (bool)$scope['all_shops'],
            'shops' => $shops,
        ];
    }

    /** Stores roles, exceptions and scope (client.rights — Správca — is stored by the caller with the account). */
    public function savePerson(int $clientId, array $a): void
    {
        $this->db->transaction(function (Database $db) use ($clientId, $a) {
            foreach (['client_role', 'permission', 'company_access', 'shop_access', 'client_scope'] as $t) {
                $db->execute("DELETE FROM {$this->t($t)} WHERE client_id = ?", [$clientId]);
            }
            $valid = array_column($this->all(), 'id');
            foreach (array_unique(array_map('intval', $a['roles'])) as $r) {
                if (in_array($r, $valid, true)) {
                    $db->execute("INSERT INTO {$this->t('client_role')} (client_id, role_id) VALUES (?, ?)", [$clientId, $r]);
                }
            }
            $deny = Permissions::clean($a['deny']);
            foreach (Permissions::clean($a['allow']) as $p) {
                if (!in_array($p, $deny, true)) {
                    $db->execute("INSERT INTO {$this->t('permission')} (client_id, permission, allow) VALUES (?, ?, 1)", [$clientId, $p]);
                }
            }
            foreach ($deny as $p) {
                $db->execute("INSERT INTO {$this->t('permission')} (client_id, permission, allow) VALUES (?, ?, 0)", [$clientId, $p]);
            }
            foreach (array_unique(array_map('intval', $a['companies'])) as $id) {
                if ($id > 0) {
                    $db->execute("INSERT INTO {$this->t('company_access')} (client_id, company_id) VALUES (?, ?)", [$clientId, $id]);
                }
            }
            foreach (array_unique($a['shops']) as $key) {
                if (preg_match('/^(\d+):(\d+)$/', (string)$key, $m) && (int)$m[2] > 0) {
                    $db->execute("INSERT INTO {$this->t('shop_access')} (client_id, connection_id, shop_id) VALUES (?, ?, ?)", [$clientId, (int)$m[1], (int)$m[2]]);
                }
            }
            $db->execute("INSERT INTO {$this->t('client_scope')} (client_id, all_companies, all_shops) VALUES (?, ?, ?)",
                [$clientId, $a['all_companies'] ? 1 : 0, $a['all_shops'] ? 1 : 0]);
        });
    }

    /** Gives one person access to one company (e.g. the creator of a new company). */
    public function grantCompany(int $clientId, int $companyId): void
    {
        $this->db->execute("INSERT IGNORE INTO {$this->t('company_access')} (client_id, company_id) VALUES (?, ?)", [$clientId, $companyId]);
    }

    /** Permissions given by the roles. @param int[] $roleIds @return string[] */
    public function rolePermissions(array $roleIds): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            if (in_array($r['id'], $roleIds, true)) {
                $out = array_merge($out, $r['permissions']);
            }
        }
        return Permissions::clean($out);
    }

    /** Effective permissions of an access array: Správca = all; else roles + allowed − denied. @return string[] */
    public function effective(array $a): array
    {
        if ($a['admin']) {
            return array_keys(Permissions::all());
        }
        return array_values(array_diff(Permissions::clean(array_merge($this->rolePermissions($a['roles']), $a['allow'])), $a['deny']));
    }

    /**
     * Is access $b within access $a? (an editor may change only people with no more rights than he has, and give only
     * what he has — the same rule as for API keys: never more than the author). @return string[] what $b has beyond $a
     */
    public function beyond(array $a, array $b, array $allCompanyIds): array
    {
        if ($a['admin']) {
            return [];
        }
        $out = [];
        if ($b['admin']) {
            return ['správca'];
        }
        foreach (array_diff($this->effective($b), $this->effective($a)) as $p) {
            $out[] = Permissions::label($p);
        }
        if (!$a['all_companies']) {
            if ($b['all_companies']) {
                $out[] = 'všetky firmy';
            } else {
                foreach (array_diff($b['companies'], $a['companies']) as $c) {
                    if (in_array($c, $allCompanyIds, true)) {
                        $out[] = 'firma #' . $c;
                    }
                }
            }
        }
        if (!$a['all_shops']) {
            if ($b['all_shops']) {
                $out[] = 'všetky e-shopy';
            } else {
                foreach (array_diff($b['shops'], $a['shops']) as $s) {
                    $out[] = 'e-shop ' . $s;
                }
            }
        }
        return $out;
    }

    /** What changed, for the activity log ("Zmeny práv sa zapisujú"). */
    public function describeChange(array $old, array $new, array $companyNames, array $shopNames): string
    {
        $roleNames = array_column($this->all(), 'name', 'id');
        $parts = [];
        if ($old['admin'] !== $new['admin']) {
            $parts[] = $new['admin'] ? 'správca: áno' : 'správca: nie';
        }
        $diff = function (string $label, array $o, array $n, callable $name) use (&$parts) {
            $add = array_diff($n, $o);
            $del = array_diff($o, $n);
            if ($add || $del) {
                $parts[] = $label . ': ' . implode(', ', array_merge(array_map(function ($x) use ($name) { return '+' . $name($x); }, $add),
                    array_map(function ($x) use ($name) { return '−' . $name($x); }, $del)));
            }
        };
        $diff('roly', $old['roles'], $new['roles'], function ($id) use ($roleNames) { return $roleNames[$id] ?? '#' . $id; });
        $perm = function ($k) { return Permissions::label($k); };
        $diff('navyše', $old['allow'], $new['allow'], $perm);
        $diff('zakázané', $old['deny'], $new['deny'], $perm);
        if ($old['all_companies'] !== $new['all_companies']) {
            $parts[] = $new['all_companies'] ? 'firmy: všetky' : 'firmy: len vybrané';
        }
        $diff('firmy', $old['companies'], $new['companies'], function ($id) use ($companyNames) { return $companyNames[$id] ?? '#' . $id; });
        if ($old['all_shops'] !== $new['all_shops']) {
            $parts[] = $new['all_shops'] ? 'e-shopy: všetky' : 'e-shopy: len vybrané';
        }
        $diff('e-shopy', $old['shops'], $new['shops'], function ($k) use ($shopNames) { return $shopNames[$k] ?? $k; });
        return implode('; ', $parts);
    }
}
