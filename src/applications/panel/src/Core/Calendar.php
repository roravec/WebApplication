<?php
namespace Panel\Core;

use Panel\PanelContext;

/** The shared calendar: dates of all modules (Core\ProvidesCalendar) for the person, by day. */
class Calendar
{
    const KINDS = ['over' => 'po termíne', 'due' => 'splatnosť / termín', 'plan' => 'plánované', 'info' => 'informácia'];

    /** @return array Y-m-d => list of events, sorted (overdue first) */
    public static function events(PanelContext $ctx, string $from, string $to): array
    {
        $out = [];
        foreach (\PanelApp::modules() as $m) {
            if (!$m instanceof ProvidesCalendar) {
                continue;
            }
            try {
                $list = $m->calendar($ctx, $from, $to);
            } catch (\Throwable $e) {
                $list = [];
            }
            foreach ($list as $ev) {
                if ($ev['date'] >= $from && $ev['date'] <= $to) {
                    $out[$ev['date']][] = $ev + ['kind' => 'info'];
                }
            }
        }
        $order = array_flip(array_keys(self::KINDS));
        foreach ($out as &$day) {
            usort($day, function ($a, $b) use ($order) { return [$order[$a['kind']] ?? 9, $a['title']] <=> [$order[$b['kind']] ?? 9, $b['title']]; });
        }
        ksort($out);
        return $out;
    }

    /** Weeks (Monday first) covering the month: list of lists of Y-m-d. */
    public static function monthGrid(string $month): array
    {
        $first = strtotime($month . '-01');
        $start = strtotime('-' . ((int)date('N', $first) - 1) . ' days', $first);
        $last = strtotime(date('Y-m-t', $first));
        $end = strtotime('+' . (7 - (int)date('N', $last)) . ' days', $last);
        $weeks = [];
        for ($d = $start, $i = 0; $d <= $end; $d = strtotime('+1 day', $d), $i++) {
            $weeks[intdiv($i, 7)][] = date('Y-m-d', $d);
        }
        return $weeks;
    }
}
