<?php
namespace Panel;

/** Rights levels of employees (stored in panel_client.rights; higher includes lower). */
class Rights
{
    const VIEW = 10;      // see orders, invoices
    const INVOICE = 50;   // issue invoices, credit notes
    const ADMIN = 100;    // manage employees, companies, settings

    public static function labels(): array
    {
        return [self::VIEW => 'Prehliadanie', self::INVOICE => 'Fakturácia', self::ADMIN => 'Správca'];
    }

    public static function label(int $rights): string
    {
        $label = 'Bez prístupu';
        foreach (self::labels() as $level => $name) {
            if ($rights >= $level) {
                $label = $name;
            }
        }
        return $label;
    }
}
