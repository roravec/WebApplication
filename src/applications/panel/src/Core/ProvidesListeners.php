<?php
namespace Panel\Core;

/**
 * Optional for a module: it reacts to events of other modules (Core\Events) without calling them directly
 * (docs/panel-architektura.md rule 4) — e.g. notifications on "comment.added", later automations.
 */
interface ProvidesListeners
{
    /** @return array list of [event name, fn(\Panel\PanelContext $ctx, array $data): void] */
    public function listeners(): array;
}
