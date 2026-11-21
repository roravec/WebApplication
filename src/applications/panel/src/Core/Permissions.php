<?php
namespace Panel\Core;

/**
 * Register of all permissions — every module registers its own (Module::permissions(), docs/panel-architektura.md
 * chapter 5): key "module.action", label shown on the employee and role forms, group = heading on those forms,
 * sensitive = money / settings / irreversible things (marked on the forms, given only on purpose).
 */
class Permissions
{
    /** @var array|null key => ['key', 'label', 'group', 'sensitive'] */
    private static $all = null;

    /** @return array key => ['key', 'label', 'group', 'sensitive'] in the order of the modules */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = self::fromModules(\PanelApp::modules());
        }
        return self::$all;
    }

    /** @param \Panel\Module[] $modules */
    public static function fromModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            foreach ($m->permissions() as $p) {
                if (!preg_match('/^[a-z]+(\.[a-z_]+)+$/', $p['key']) || strlen($p['key']) > 40) {
                    throw new \LogicException('Neplatný kľúč oprávnenia: ' . $p['key']);
                }
                if (isset($out[$p['key']])) {
                    throw new \LogicException('Oprávnenie ' . $p['key'] . ' registrujú dva moduly.');
                }
                $out[$p['key']] = ['key' => $p['key'], 'label' => (string)$p['label'], 'group' => (string)($p['group'] ?? ''),
                    'sensitive' => !empty($p['sensitive'])];
            }
        }
        return $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /** @return array group => [key => definition] */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::all() as $k => $p) {
            $out[$p['group']][$k] = $p;
        }
        return $out;
    }

    /** Only registered keys, unique, in the register's order. @return string[] */
    public static function clean(array $keys): array
    {
        $keys = array_flip(array_map('strval', $keys));
        return array_values(array_filter(array_keys(self::all()), function ($k) use ($keys) { return isset($keys[$k]); }));
    }
}
