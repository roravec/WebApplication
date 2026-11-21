<?php
namespace Panel\Tasks;

use Panel\PanelContext;
use Panel\Rights;
use WebApplication\Database\Database;

/**
 * Projects (docs/panel-architektura.md 6.1): belong to a company, have members with a role in the project and the
 * kanban columns. Who sees a project (permission tasks.view +): its members — also external people without access to
 * the company — and people with projects.all in the project's company. Who changes tasks: tasks.edit and not a
 * reader. Who manages the project (members, columns, archive): projects.manage + sees it, or its owner.
 */
class Projects
{
    const ROLES = ['owner' => 'vlastník', 'member' => 'člen', 'reader' => 'len čítanie', 'external' => 'externý'];
    const DEFAULT_COLUMNS = [['Na rade', 0], ['Rozpracované', 0], ['Na kontrolu', 0], ['Hotovo', 1]];

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

    public function find(int $id): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('project')} WHERE id = ?", [$id]);
        return $r === false ? null : $r;
    }

    public function findByKey(string $key): ?array
    {
        $r = $this->db->fetchOne("SELECT * FROM {$this->t('project')} WHERE `key` = ?", [strtoupper($key)]);
        return $r === false ? null : $r;
    }

    /** Role of the person in the project, '' = not a member. */
    public function role(int $projectId, int $clientId): string
    {
        return (string)$this->db->fetchValue("SELECT role FROM {$this->t('project_member')} WHERE project_id = ? AND client_id = ?", [$projectId, $clientId]);
    }

    public function canSee(PanelContext $ctx, array $p): bool
    {
        $u = $ctx->user();
        if ($u === null || !$ctx->can(Rights::TASKS_VIEW)) {
            return false;
        }
        return $this->role((int)$p['id'], $u->getId()) !== ''
            || ($ctx->can(Rights::PROJECTS_ALL) && $ctx->canCompany((int)$p['company_id']));
    }

    public function canEdit(PanelContext $ctx, array $p): bool
    {
        return $this->canSee($ctx, $p) && $ctx->can(Rights::TASKS_EDIT) && $this->role((int)$p['id'], $ctx->user()->getId()) !== 'reader'
            && $p['status'] === 'active';
    }

    public function canManage(PanelContext $ctx, array $p): bool
    {
        return $this->canSee($ctx, $p) && ($ctx->can(Rights::PROJECTS_MANAGE) || $this->role((int)$p['id'], $ctx->user()->getId()) === 'owner');
    }

    /** SQL condition (alias p) + params: the projects the person sees — filtered in the database. */
    public function visibleSql(PanelContext $ctx, string $alias = 'p'): array
    {
        $u = $ctx->user();
        if ($u === null || !$ctx->can(Rights::TASKS_VIEW)) {
            return ['0 = 1', []];
        }
        $member = "$alias.id IN (SELECT project_id FROM {$this->t('project_member')} WHERE client_id = ?)";
        $ids = $ctx->can(Rights::PROJECTS_ALL) ? $ctx->companyIds() : [];
        return [$ids ? "($member OR $alias.company_id IN (" . implode(',', array_map('intval', $ids)) . '))' : $member, [$u->getId()]];
    }

    /** @return array visible projects with open / all task counts */
    public function visible(PanelContext $ctx, bool $archived = false): array
    {
        list($w, $p) = $this->visibleSql($ctx);
        return $this->db->query("SELECT p.*, c.name AS company_name,
                (SELECT COUNT(*) FROM {$this->t('task')} t WHERE t.project_id = p.id AND t.closed_at IS NULL) AS open_tasks,
                (SELECT COUNT(*) FROM {$this->t('task')} t WHERE t.project_id = p.id) AS all_tasks
            FROM {$this->t('project')} p LEFT JOIN {$this->t('company')} c ON c.id = p.company_id
            WHERE $w AND p.status = ? ORDER BY p.name", array_merge($p, [$archived ? 'archived' : 'active']));
    }

    /** @return string|null error */
    public function keyError(string $key, ?int $id): ?string
    {
        if (!preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $key)) {
            return 'Kľúč projektu: 2 až 10 veľkých písmen alebo číslic, na začiatku písmeno (napr. LED). Bude v číslach úloh: LED-12.';
        }
        if ($key === 'U') {
            return 'Kľúč U je vyhradený pre osobné úlohy.';
        }
        $other = $this->db->fetchValue("SELECT id FROM {$this->t('project')} WHERE `key` = ?", [$key]);
        return $other && (int)$other !== (int)$id ? 'Projekt s týmto kľúčom už existuje.' : null;
    }

    /** New project with the default columns; the author becomes its owner. @return int id */
    public function create(int $companyId, string $key, string $name, string $description, int $authorId): int
    {
        return $this->db->transaction(function (Database $db) use ($companyId, $key, $name, $description, $authorId) {
            $db->execute("INSERT INTO {$this->t('project')} (company_id, `key`, name, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$companyId, $key, $name, $description, $authorId, date('Y-m-d H:i:s')]);
            $id = (int)$db->lastInsertId();
            foreach (self::DEFAULT_COLUMNS as $i => list($col, $done)) {
                $db->execute("INSERT INTO {$this->t('project_column')} (project_id, name, position, done) VALUES (?, ?, ?, ?)", [$id, $col, $i, $done]);
            }
            $db->execute("INSERT INTO {$this->t('project_member')} (project_id, client_id, role) VALUES (?, ?, 'owner')", [$id, $authorId]);
            return $id;
        });
    }

    public function update(int $id, string $name, string $description, string $status): void
    {
        $this->db->execute("UPDATE {$this->t('project')} SET name = ?, description = ?, status = ? WHERE id = ?",
            [$name, $description, $status === 'archived' ? 'archived' : 'active', $id]);
    }

    /** @return array client id => ['client_id', 'name', 'role', 'active'] */
    public function members(int $projectId): array
    {
        $out = [];
        foreach ($this->db->query("SELECT m.client_id, m.role, c.name, c.status FROM {$this->t('project_member')} m
                JOIN {$this->t('client')} c ON c.id = m.client_id WHERE m.project_id = ? ORDER BY c.name", [$projectId]) as $r) {
            $out[(int)$r['client_id']] = ['client_id' => (int)$r['client_id'], 'name' => $r['name'], 'role' => $r['role'], 'active' => (bool)$r['status']];
        }
        return $out;
    }

    /** @param array $members client id => role */
    public function saveMembers(int $projectId, array $members): void
    {
        $this->db->transaction(function (Database $db) use ($projectId, $members) {
            $db->execute("DELETE FROM {$this->t('project_member')} WHERE project_id = ?", [$projectId]);
            foreach ($members as $cid => $role) {
                if (isset(self::ROLES[$role]) && (int)$cid > 0) {
                    $db->execute("INSERT INTO {$this->t('project_member')} (project_id, client_id, role) VALUES (?, ?, ?)", [$projectId, (int)$cid, $role]);
                }
            }
        });
    }

    public function columns(int $projectId): array
    {
        return $this->db->query("SELECT * FROM {$this->t('project_column')} WHERE project_id = ? ORDER BY position, id", [$projectId]);
    }

    /**
     * @param array $columns list of [id (0 = new), name, done]; missing ones are removed (their tasks move to the first)
     */
    public function saveColumns(int $projectId, array $columns): void
    {
        $this->db->transaction(function (Database $db) use ($projectId, $columns) {
            $keep = [];
            foreach (array_values($columns) as $i => $c) {
                if ((int)$c['id'] > 0) {
                    $db->execute("UPDATE {$this->t('project_column')} SET name = ?, done = ?, position = ? WHERE id = ? AND project_id = ?",
                        [$c['name'], $c['done'] ? 1 : 0, $i, (int)$c['id'], $projectId]);
                    $keep[] = (int)$c['id'];
                } else {
                    $db->execute("INSERT INTO {$this->t('project_column')} (project_id, name, position, done) VALUES (?, ?, ?, ?)",
                        [$projectId, $c['name'], $i, $c['done'] ? 1 : 0]);
                    $keep[] = (int)$db->lastInsertId();
                }
            }
            $gone = array_column($db->query("SELECT id FROM {$this->t('project_column')} WHERE project_id = ? AND id NOT IN (" . implode(',', $keep) . ')',
                [$projectId]), 'id');
            if ($gone) {
                $db->execute("UPDATE {$this->t('task')} SET column_id = ? WHERE column_id IN (" . implode(',', array_map('intval', $gone)) . ')', [$keep[0]]);
                $db->execute("DELETE FROM {$this->t('project_column')} WHERE id IN (" . implode(',', array_map('intval', $gone)) . ')');
            }
            // done flags of the tasks follow their column
            $db->execute("UPDATE {$this->t('task')} t JOIN {$this->t('project_column')} c ON c.id = t.column_id
                SET t.closed_at = IF(c.done = 1, COALESCE(t.closed_at, ?), NULL) WHERE t.project_id = ?", [date('Y-m-d H:i:s'), $projectId]);
        });
    }

    /** Hours per person in the project (time sheets). */
    public function hours(int $projectId): array
    {
        return $this->db->query("SELECT c.name, SUM(e.minutes) AS minutes, SUM(IF(e.billable = 1, e.minutes, 0)) AS billable
            FROM {$this->t('time_entry')} e JOIN {$this->t('client')} c ON c.id = e.client_id WHERE e.project_id = ?
            GROUP BY e.client_id, c.name ORDER BY minutes DESC", [$projectId]);
    }
}
