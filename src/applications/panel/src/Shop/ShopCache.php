<?php
namespace Panel\Shop;

use WebApplication\Database\Database;

/**
 * Short-lived cache of what a connected e-shop answers rarely-changing (order states, shops, currencies, countries,
 * carriers) — every Webservice call costs ~0.3–1 s, so the order pages would be slow without it. Table shop_cache;
 * cleared when the connection is saved, the order part after a state change from the panel.
 */
class ShopCache
{
    /** @var Database */ private $db;
    /** @var int */ private $connectionId;

    public function __construct(Database $db, int $connectionId)
    {
        $this->db = $db;
        $this->connectionId = $connectionId;
    }

    /** Cached value (array) or $load() stored for $ttl seconds. */
    public function remember(string $name, int $ttl, callable $load): array
    {
        $t = $this->db->table('shop_cache');
        $row = $this->db->fetchOne("SELECT data, fetched_at FROM $t WHERE connection_id = ? AND name = ?", [$this->connectionId, $name]);
        if ($row !== false && strtotime($row['fetched_at']) > time() - $ttl) {
            $v = json_decode((string)$row['data'], true);
            if (is_array($v)) {
                return $v;
            }
        }
        $v = $load();
        $this->db->execute("REPLACE INTO $t (connection_id, name, data, fetched_at) VALUES (?, ?, ?, ?)",
            [$this->connectionId, $name, json_encode($v, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        return $v;
    }

    /** @param string $prefix '' = everything of this connection */
    public function clear(string $prefix = ''): void
    {
        $this->db->execute('DELETE FROM ' . $this->db->table('shop_cache') . ' WHERE connection_id = ? AND name LIKE ?',
            [$this->connectionId, addcslashes($prefix, '%_\\') . '%']);
    }
}
