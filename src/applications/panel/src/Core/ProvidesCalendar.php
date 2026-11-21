<?php
namespace Panel\Core;

/**
 * Optional for a module: its dates on the shared calendar (docs/panel-architektura.md chapter 2 "Kalendár") — due
 * dates, recurring costs, later tasks, rentals, calibrations, complaint deadlines. Only what the person may see.
 */
interface ProvidesCalendar
{
    /**
     * @param string $from Y-m-d, $to Y-m-d (inclusive)
     * @return array list of ['date' (Y-m-d), 'title', 'url' (panel path), 'kind' (due | over | plan | info)]
     */
    public function calendar(\Panel\PanelContext $ctx, string $from, string $to): array;
}
