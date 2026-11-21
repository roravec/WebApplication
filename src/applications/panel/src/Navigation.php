<?php
namespace Panel;

/**
 * The left menu and the tabs above pages, built from the registered modules and filtered by the person's permissions
 * (docs/panel-architektura.md, chapter 3): daily work in groups, everything that is set up once in "Nastavenia".
 * Sections (PanelContext::page): "orders" = menu item orders; "invoices:payments" = item invoices, its tab payments;
 * "settings:companies" = Nastavenia, tab Firmy.
 */
class Navigation
{
    /** group => heading (order = order in the menu; '' = no heading) */
    const GROUPS = ['' => '', 'sales' => 'Predaj', 'finance' => 'Financie', 'stock' => 'Sklad', 'work' => 'Práca', 'settings' => ''];

    /** @var Module[] */ private $modules = [];

    public function add(Module $module): void
    {
        $this->modules[] = $module;
    }

    /** @return Module[] */
    public function modules(): array
    {
        return $this->modules;
    }

    /** '' = any logged-in employee; array = any of them. */
    public static function allowed(PanelContext $ctx, $permission): bool
    {
        if (is_array($permission)) {
            foreach ($permission as $p) {
                if ($ctx->can($p)) {
                    return true;
                }
            }
            return false;
        }
        return $permission === '' || $ctx->can($permission);
    }

    /** All menu items (unfiltered) incl. "Nastavenia", key => item. */
    private function items(): array
    {
        $items = [];
        foreach ($this->modules as $m) {
            foreach ($m->nav() as $i) {
                $items[$i['key']] = $i + ['group' => '', 'permission' => '', 'order' => 50, 'tabs' => []];
            }
        }
        $items['settings'] = ['key' => 'settings', 'group' => 'settings', 'path' => '/settings', 'label' => 'Nastavenia',
            'permission' => '', 'order' => 0, 'tabs' => $this->settingsTabsAll()];
        return $items;
    }

    private function settingsTabsAll(): array
    {
        $tabs = [];
        foreach ($this->modules as $m) {
            foreach ($m->settingsTabs() as $t) {
                $tabs[] = $t + ['permission' => '', 'order' => 50];
            }
        }
        usort($tabs, function ($a, $b) { return $a['order'] <=> $b['order']; });
        return $tabs;
    }

    /** Tabs of Nastavenia this person may open. */
    public function settingsTabs(PanelContext $ctx): array
    {
        return array_values(array_filter($this->settingsTabsAll(), function ($t) use ($ctx) { return self::allowed($ctx, $t['permission']); }));
    }

    /**
     * The menu for this person: list of ['label' => group heading or '', 'items' => list of ['key', 'path', 'label']].
     * Nastavenia only when at least one of its tabs is allowed (it opens the first one).
     */
    public function menu(PanelContext $ctx): array
    {
        $groups = array_fill_keys(array_keys(self::GROUPS), []);
        foreach ($this->items() as $key => $i) {
            if ($key === 'settings') {
                $tabs = $this->settingsTabs($ctx);
                if (!$tabs) {
                    continue;
                }
                $i['path'] = $tabs[0]['path'];
            } elseif (!self::allowed($ctx, $i['permission'])) {
                continue;
            }
            $groups[$i['group']][] = $i;
        }
        $out = [];
        foreach ($groups as $g => $items) {
            if (!$items) {
                continue;
            }
            usort($items, function ($a, $b) { return $a['order'] <=> $b['order']; });
            $label = self::GROUPS[$g];
            // a group with one item of the same name (Sklad → Sklad) needs no heading
            if (count($items) === 1 && $items[0]['label'] === $label) {
                $label = '';
            }
            $out[] = ['label' => $label, 'items' => array_map(function ($i) {
                return ['key' => $i['key'], 'path' => $i['path'], 'label' => $i['label']];
            }, $items)];
        }
        return $out;
    }

    /** Menu key of a section ("invoices:payments" → "invoices"). */
    public static function menuKey(string $section): string
    {
        return (string)strtok($section, ':');
    }

    /** Tabs above a page of this section (allowed ones, with 'active'); [] = none. */
    public function tabs(string $section, PanelContext $ctx): array
    {
        if (strpos($section, ':') === false) {
            return [];
        }
        list($key, $tab) = explode(':', $section, 2);
        $item = $this->items()[$key] ?? null;
        if ($item === null) {
            return [];
        }
        $out = [];
        foreach ($item['tabs'] as $t) {
            if (self::allowed($ctx, $t['permission'] ?? '')) {
                $out[] = ['key' => $t['key'], 'path' => $t['path'], 'label' => $t['label'], 'active' => $t['key'] === $tab];
            }
        }
        return count($out) > 1 || $key === 'settings' ? $out : [];
    }
}
