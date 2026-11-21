<?php
namespace Panel;

use WebApplication\Database\Database;
use WebApplication\Entity\Client;

/** Activity log shared by all panel modules (invoices, companies, later tasks): who did what and when. */
class Audit
{
    const ACTIONS = [
        'create' => 'vytvoril', 'update' => 'upravil', 'delete' => 'zmazal', 'issue' => 'vystavil',
        'credit_note' => 'vytvoril dobropis', 'print' => 'vytlačil', 'send' => 'odoslal e-mailom', 'remind' => 'poslal upomienku', 'send_failed' => 'neodoslal e-mail (chyba)', 'export' => 'exportoval',
        'payment' => 'zapísal úhradu', 'payment_delete' => 'zmazal úhradu', 'bank_import' => 'importoval výpis', 'bank_download' => 'stiahol pohyby z banky', 'state' => 'zmenil stav objednávky',
        'accounting' => 'poslal doklady účtovníkovi', 'accounting_failed' => 'neodoslal doklady účtovníkovi (chyba)',
        'paid' => 'označil ako uhradený', 'unpaid' => 'zrušil úhradu', 'cancel' => 'stornoval', 'file' => 'priložil súbor', 'save' => 'uložil', 'summary' => 'poslal mesačný súhrn',
        'unissue' => 'vrátil do konceptu (omylom vystavený)', 'lines' => 'upravil položky objednávky v e-shope',
    ];

    public static function log(Database $db, ?Client $user, string $entity, int $entityId, string $action, string $details = ''): void
    {
        $db->execute('INSERT INTO ' . $db->table('audit') . ' (created_at, client_id, client_name, entity, entity_id, action, details)
                      VALUES (?, ?, ?, ?, ?, ?, ?)',
            [date('Y-m-d H:i:s'), $user ? $user->getId() : 0, $user ? $user->getName() : '', $entity, $entityId, $action, $details]);
    }

    public static function forEntity(Database $db, string $entity, int $entityId): array
    {
        return $db->query('SELECT * FROM ' . $db->table('audit') . ' WHERE entity = ? AND entity_id = ? ORDER BY id', [$entity, $entityId]);
    }

    public static function label(string $action): string
    {
        return self::ACTIONS[$action] ?? $action;
    }
}
