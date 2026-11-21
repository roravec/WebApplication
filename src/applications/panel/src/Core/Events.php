<?php
namespace Panel\Core;

use Panel\PanelContext;

/**
 * Events (docs/panel-architektura.md chapter 4): a module announces "what happened" — comment.added, link.added,
 * invoice.issued, order.state_changed … — and whoever is interested reacts (Core\ProvidesListeners): notifications
 * today, automations and other modules later. No direct calls between modules. A failing listener never breaks the
 * action that emitted the event (it is logged).
 *
 * Event data always has: ref (the record), actor (Client|null), plus event-specific keys (documented at the emit).
 */
class Events
{
    /** @var array|null event => callables */
    private static $listeners = null;

    /** @return array event => list of callables */
    public static function listeners(): array
    {
        if (self::$listeners === null) {
            self::$listeners = self::fromModules(\PanelApp::modules());
        }
        return self::$listeners;
    }

    /** @param \Panel\Module[] $modules */
    public static function fromModules(array $modules): array
    {
        $out = [];
        foreach ($modules as $m) {
            if ($m instanceof ProvidesListeners) {
                foreach ($m->listeners() as $l) {
                    $out[$l[0]][] = $l[1];
                }
            }
        }
        return $out;
    }

    /** @return int number of listeners that ran without an error */
    public static function emit(PanelContext $ctx, string $event, array $data): int
    {
        $ok = 0;
        foreach (self::listeners()[$event] ?? [] as $fn) {
            try {
                $fn($ctx, $data + ['event' => $event]);
                $ok++;
            } catch (\Throwable $e) {
                if ($ctx->app !== null) {
                    $ctx->app->getLogger()->error('Event listener failed', ['event' => $event, 'error' => $e->getMessage()]);
                }
            }
        }
        return $ok;
    }
}
