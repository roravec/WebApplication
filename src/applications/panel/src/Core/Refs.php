<?php
namespace Panel\Core;

use Panel\PanelContext;

/**
 * Record references (docs/panel-architektura.md chapter 4): one identifier "type:id" for any record of any module —
 * invoice:15, order:0:2481 (order source + id_order), expense:3 … Every module registers its types
 * (Module::recordTypes()): label, number of id parts, resolve (title + URL, null = not found OR not allowed — the access
 * check of the person is inside), optional lookup (find records by a number / name for linking).
 * Everything built on references (short links /r/<ref> + QR, links, comments, activity) shows only what resolve() allows.
 */
class Refs
{
    /** @var array|null type => definition */
    private static $types = null;

    /** @var PanelContext */
    private $ctx;
    /** @var array ref => resolved|null (per request) */
    private $memo = [];

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /** @return array type => ['type', 'label', 'parts', 'resolve', 'lookup'] */
    public static function types(): array
    {
        if (self::$types === null) {
            self::$types = self::fromModules(\PanelApp::modules());
        }
        return self::$types;
    }

    /** @param \Panel\Module[] $modules */
    public static function fromModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            foreach ($m->recordTypes() as $t) {
                if (!preg_match('/^[a-z][a-z_]{1,19}$/', $t['type']) || isset($out[$t['type']])) {
                    throw new \LogicException('Neplatný alebo dvakrát registrovaný typ záznamu: ' . $t['type']);
                }
                $out[$t['type']] = ['type' => $t['type'], 'label' => (string)$t['label'], 'parts' => (int)($t['parts'] ?? 1),
                    'resolve' => $t['resolve'], 'lookup' => $t['lookup'] ?? null];
            }
        }
        return $out;
    }

    /** "invoice:15" → ['invoice', [15]]; null when the text is not a reference of a registered type. */
    public static function parse(string $ref): ?array
    {
        if (strlen($ref) > 80 || !preg_match('/^([a-z][a-z_]{1,19})((?::\d{1,10})+)$/', $ref, $m)) {
            return null;
        }
        $type = self::types()[$m[1]] ?? null;
        $ids = array_map('intval', explode(':', substr($m[2], 1)));
        return $type && count($ids) === $type['parts'] ? [$m[1], $ids] : null;
    }

    public static function make(string $type, int ...$ids): string
    {
        return $type . ':' . implode(':', $ids);
    }

    /** Audit entity → reference for the simple types (entity name = type, one id); null for others. */
    public static function fromEntity(string $entity, int $id): ?string
    {
        $t = self::types()[$entity] ?? null;
        return $t && $t['parts'] === 1 && $id > 0 ? $entity . ':' . $id : null;
    }

    /**
     * The record for the logged-in person: ['ref', 'type', 'label' (type name), 'title', 'url' (panel path), 'company_id' (0 = none)];
     * null = does not exist, or the person may not see it (never tell which).
     */
    public function resolve(string $ref): ?array
    {
        if (!array_key_exists($ref, $this->memo)) {
            $this->memo[$ref] = null;
            $p = self::parse($ref);
            if ($p !== null && $this->ctx->isLoggedIn()) {
                $t = self::types()[$p[0]];
                try {
                    $r = ($t['resolve'])($this->ctx, $p[1]);
                } catch (\Throwable $e) {
                    $r = null;   // e.g. a connected e-shop is unreachable — treated as not visible
                }
                if (is_array($r)) {
                    $this->memo[$ref] = ['ref' => $ref, 'type' => $p[0], 'label' => $t['label'], 'title' => (string)$r['title'], 'url' => (string)$r['url'],
                        'company_id' => (int)($r['company_id'] ?? 0)];
                }
            }
        }
        return $this->memo[$ref];
    }

    public function canSee(string $ref): bool
    {
        return $this->resolve($ref) !== null;
    }

    /**
     * What the person typed to link a record → references he may see: a reference ("invoice:15"), a panel address
     * (https://…/panel/r/invoice:15 or a page URL is not parsed — use the short link), or a number / name found by the
     * modules' lookups (invoice number, order reference …). @return array list of resolved records (max $limit)
     */
    public function find(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        if (preg_match('#/r/([a-z_]+(?::\d+)+)#', $q, $m)) {
            $q = $m[1];
        }
        if (self::parse($q) !== null) {
            $r = $this->resolve($q);
            return $r ? [$r] : [];
        }
        $out = [];
        foreach (self::types() as $t) {
            if ($t['lookup'] === null || mb_strlen($q, 'UTF-8') < 2) {
                continue;
            }
            try {
                $refs = ($t['lookup'])($this->ctx, $q);
            } catch (\Throwable $e) {
                $refs = [];
            }
            foreach ($refs as $ref) {
                $r = $this->resolve($ref);
                if ($r !== null) {
                    $out[$ref] = $r;
                }
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }
        return array_values($out);
    }

    /** Absolute short link of the record (for QR codes on labels). */
    public function shortUrl(string $ref): string
    {
        $req = $this->ctx->request;
        return ($req->isHttps() ? 'https' : 'http') . '://' . $req->host() . $this->ctx->url('/r/' . $ref);
    }
}
