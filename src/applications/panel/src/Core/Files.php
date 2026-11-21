<?php
namespace Panel\Core;

use Panel\PanelContext;

/**
 * Files of records in the company Nextcloud (docs/panel-architektura.md 4.1). Each record gets its own folder
 * "<root>/<company>/<record type>/<type-id>" (fixed in record_folder when created, so renaming a company does not
 * lose it). The panel's permissions decide who sees the files (Core\Refs); outside people get share links made by the
 * panel (download, or upload-only for customers), logged in the record's history.
 * Small legal documents (invoice PDFs, cost documents) stay in the panel's database — they must be available even when
 * the home server is off.
 */
class Files
{
    const SHARE_DAYS = [7, 14, 30, 90];

    /** @var PanelContext */
    private $ctx;
    /** @var Nextcloud|null|false */
    private $client = false;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function integrations(): Integrations
    {
        try {
            $secret = new \Panel\Secret($this->ctx->app ? $this->ctx->app->getJwtSecret() : '');
        } catch (\RuntimeException $e) {
            $secret = null;
        }
        return new Integrations($this->ctx->db(), $secret);
    }

    /** null = Nextcloud is not set up (the record box then has no "Súbory" part). */
    public function client(): ?Nextcloud
    {
        if ($this->client === false) {
            $cfg = $this->integrations()->config('nextcloud');
            try {
                $this->client = $cfg ? new Nextcloud((string)$cfg['url'], (string)$cfg['user'], (string)$cfg['password']) : null;
            } catch (\InvalidArgumentException $e) {
                $this->client = null;
            }
        }
        return $this->client;
    }

    /** For tests and other storages behind the same interface. */
    public function setClient(?Nextcloud $client): void
    {
        $this->client = $client;
    }

    public function configured(): bool
    {
        return $this->client() !== null;
    }

    private function root(): string
    {
        $cfg = $this->integrations()->config('nextcloud') ?? [];
        return trim((string)($cfg['root'] ?? ''), '/') ?: 'Panel';
    }

    /** Name of an uploaded / requested file: a path sent by a browser or client is dropped, then safeName(). */
    public static function fileName(string $name): string
    {
        return self::safeName(basename(str_replace('\\', '/', $name)));
    }

    /** A name usable as one file / folder name (slashes and control characters replaced). */
    public static function safeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $name));
        $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 180, 'UTF-8');
        return $name === '.' || $name === '..' ? '' : $name;
    }

    /** The record's folder; null when it does not exist yet and $create is false. @param array $record Refs::resolve */
    public function folder(array $record, bool $create): ?string
    {
        $t = $this->ctx->db()->table('record_folder');
        $path = $this->ctx->db()->fetchValue("SELECT path FROM $t WHERE ref = ?", [$record['ref']]);
        if ($path) {
            return (string)$path;
        }
        if (!$create) {
            return null;
        }
        $company = '';
        if (!empty($record['company_id'])) {
            $company = (string)$this->ctx->db()->fetchValue('SELECT name FROM ' . $this->ctx->db()->table('company') . ' WHERE id = ?', [$record['company_id']]);
        }
        $path = $this->root() . '/' . (self::safeName($company) ?: 'Spoločné') . '/' . self::safeName($record['label']) . '/' . str_replace(':', '-', $record['ref']);
        $this->client()->ensureFolder($path);
        $this->ctx->db()->execute("INSERT IGNORE INTO $t (ref, path, created_at) VALUES (?, ?, ?)", [$record['ref'], $path, date('Y-m-d H:i:s')]);
        return (string)$this->ctx->db()->fetchValue("SELECT path FROM $t WHERE ref = ?", [$record['ref']]);
    }

    /** @return array files of the record ([] when it has no folder yet) */
    public function listFiles(array $record): array
    {
        $folder = $this->folder($record, false);
        return $folder === null ? [] : ($this->client()->listFolder($folder) ?? []);
    }

    /** @return string the stored name (a file with the same name gets " (2)" …) */
    public function upload(array $record, string $name, string $bytes): string
    {
        $name = self::fileName($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Súbor nemá platný názov.');
        }
        $folder = $this->folder($record, true);
        $existing = array_column($this->client()->listFolder($folder) ?? [], 'name');
        $final = $name;
        for ($i = 2; in_array($final, $existing, true); $i++) {
            $dot = strrpos($name, '.');
            $final = $dot > 0 ? substr($name, 0, $dot) . " ($i)" . substr($name, $dot) : "$name ($i)";
        }
        $this->client()->upload($folder . '/' . $final, $bytes);
        return $final;
    }

    public function download(array $record, string $name): string
    {
        $folder = $this->folder($record, false);
        if ($folder === null || self::fileName($name) !== $name || $name === '') {
            throw new \RuntimeException('Súbor neexistuje.');
        }
        return $this->client()->download($folder . '/' . $name);
    }

    public function delete(array $record, string $name): void
    {
        $folder = $this->folder($record, false);
        if ($folder === null || self::fileName($name) !== $name || $name === '') {
            throw new \RuntimeException('Súbor neexistuje.');
        }
        $this->client()->delete($folder . '/' . $name);
    }

    public function shares(string $ref): array
    {
        return $this->ctx->db()->query('SELECT * FROM ' . $this->ctx->db()->table('file_share') . ' WHERE ref = ? AND revoked_at IS NULL ORDER BY id DESC', [$ref]);
    }

    /** New share link of the record's folder. @return array the stored row */
    public function share(array $record, string $kind, string $password, int $days): array
    {
        if (!in_array($kind, ['read', 'upload'], true)) {
            throw new \InvalidArgumentException('Neznámy druh odkazu.');
        }
        if ($password !== '' && mb_strlen($password, 'UTF-8') < 8) {
            throw new \InvalidArgumentException('Heslo odkazu musí mať aspoň 8 znakov (alebo nechajte prázdne).');
        }
        $expires = $days > 0 ? date('Y-m-d', strtotime('+' . min(365, $days) . ' days')) : null;
        list($id, $url) = $this->client()->share($this->folder($record, true), $kind, $password, $expires);
        $u = $this->ctx->user();
        $t = $this->ctx->db()->table('file_share');
        $this->ctx->db()->execute("INSERT INTO $t (ref, kind, remote_id, url, expires, has_password, client_id, client_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$record['ref'], $kind, $id, $url, $expires, $password !== '' ? 1 : 0, $u ? $u->getId() : null, $u ? $u->getName() : '', date('Y-m-d H:i:s')]);
        return $this->ctx->db()->fetchOne("SELECT * FROM $t WHERE id = ?", [$this->ctx->db()->lastInsertId()]);
    }

    public function findShare(int $id): ?array
    {
        $r = $this->ctx->db()->fetchOne('SELECT * FROM ' . $this->ctx->db()->table('file_share') . ' WHERE id = ?', [$id]);
        return $r === false ? null : $r;
    }

    public function revoke(array $share): void
    {
        $this->client()->unshare((string)$share['remote_id']);
        $this->ctx->db()->execute('UPDATE ' . $this->ctx->db()->table('file_share') . ' SET revoked_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $share['id']]);
    }

    /** Largest upload through the panel in bytes (PHP limits; Cloudflare allows 100 MB). */
    public static function uploadLimit(): int
    {
        $bytes = function ($v) {
            $v = trim((string)$v);
            $n = (float)$v;
            switch (strtolower(substr($v, -1))) {
                case 'g': $n *= 1024;   // no break
                case 'm': $n *= 1024;   // no break
                case 'k': $n *= 1024;
            }
            return (int)$n;
        };
        $limits = array_filter([$bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size')), 100 * 1024 * 1024]);
        return (int)min($limits);
    }
}
