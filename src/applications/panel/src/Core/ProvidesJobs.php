<?php
namespace Panel\Core;

/**
 * Optional for a module: its scheduled jobs (Core\Scheduler — one cron URL + a quiet tick from open panel pages).
 * A job must be safe to run again (it checks itself what is due) and quick (shared hosting time limit).
 */
interface ProvidesJobs
{
    /**
     * @return array list of ['key' ("module.job"), 'label' (Slovak), 'every' (minutes between runs), 'hour' (optional:
     *               not before this hour of the day — for daily jobs), 'run' => fn(\Panel\PanelContext $system): string
     *               (what it did, one line; throw on failure)]
     */
    public function jobs(): array;
}
