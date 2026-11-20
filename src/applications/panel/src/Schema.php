<?php
namespace Panel;

use WebApplication\Database\Database;

/**
 * Database migrations of the panel: sql/NNN_name.sql, applied in order, each once (table <prefix>schema_migration).
 * Runs automatically on the first request after a deploy — the hosting has no SSH, so nobody has to run anything.
 * In the files {p} is the table prefix; statements end with ";" at the end of a line; full-line "--" comments are ignored.
 * Write migrations so that re-running a half-applied file is harmless (CREATE TABLE IF NOT EXISTS, INSERT IGNORE …):
 * MySQL commits every DDL statement immediately, so a file can't be rolled back as a whole.
 */
class Schema
{
    /** Applies pending migrations; returns the names applied (empty when up to date). */
    public static function migrate(Database $db, string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files);
        if (!$files) {
            return [];
        }
        $latest = (int)basename(end($files));
        $table = $db->table('schema_migration');
        try {
            $current = (int)$db->fetchValue("SELECT MAX(version) FROM $table");
        } catch (\PDOException $e) {
            $current = -1;   // table doesn't exist yet
        }
        if ($current >= $latest) {
            return [];
        }

        $lockName = 'schema_' . $db->getPrefix();
        if ((int)$db->fetchValue('SELECT GET_LOCK(?, 30)', [$lockName]) !== 1) {
            throw new \RuntimeException('Database update is already running, try again in a moment.');
        }
        try {
            $db->execute("CREATE TABLE IF NOT EXISTS $table (
                version INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(190) NOT NULL, applied_at DATETIME NOT NULL
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $done = array_map('intval', array_column($db->query("SELECT version FROM $table"), 'version'));
            $applied = [];
            foreach ($files as $file) {
                $version = (int)basename($file);
                if (in_array($version, $done, true)) {
                    continue;
                }
                foreach (self::statements((string)file_get_contents($file), $db->getPrefix()) as $sql) {
                    $db->pdo()->exec($sql);
                }
                $db->execute("INSERT INTO $table (version, name, applied_at) VALUES (?, ?, ?)",
                    [$version, basename($file), date('Y-m-d H:i:s')]);
                $applied[] = basename($file);
            }
            return $applied;
        } finally {
            $db->fetchValue('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /** @return string[] */
    public static function statements(string $sql, string $prefix): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', str_replace(["\r\n", '{p}'], ["\n", $prefix], $sql));
        return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)), 'strlen'));
    }
}
