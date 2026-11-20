<?php
namespace Panel\Invoicing;

/**
 * Versions of the printed invoice (views/invoices/templates/<name>.php).
 * An issued invoice remembers the template it was issued with (invoice.template) and is always printed with it.
 * When the layout must change (new law, new design): COPY the current file to a new name, change the copy, and point
 * CURRENT to it — never edit a template that issued invoices use. Legal TEXTS are not in templates but in
 * Legislation (data with validity dates).
 */
class Templates
{
    const CURRENT = 'invoice-2026-2';   // 2026-11-21: closer to the owner's design + all data of the old shop invoices

    /** View name for an invoice: its own template if it exists, otherwise the current one. */
    public static function viewFor(array $inv): string
    {
        $name = (string)($inv['template'] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9-]+$/', $name) || !is_file(self::dir() . '/' . $name . '.php')) {
            $name = self::CURRENT;
        }
        return 'invoices/templates/' . $name;
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/views/invoices/templates';
    }
}
