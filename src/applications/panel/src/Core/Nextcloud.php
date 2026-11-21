<?php
namespace Panel\Core;

/**
 * Client of the company Nextcloud (docs/panel-architektura.md 4.1): WebDAV (list, folders, upload, download, delete)
 * and the share API (download links, upload-only "file drop" links for customers). The panel's technical user owns the
 * folder "Panel/…"; people reach the files through the panel (permissions of the panel) or through share links.
 * Uploads through the panel are limited by PHP / Cloudflare (100 MB per request) — big files go straight to Nextcloud
 * (its web / desktop app upload in chunks, or an upload link).
 */
class Nextcloud
{
    /** @var string */ private $url;
    /** @var string */ private $user;
    /** @var string */ private $password;
    /** @var callable fn(string $method, string $url, array $headers, ?string $body, int $timeout): array [code, body] */
    private $transport;

    public function __construct(string $url, string $user, string $password, ?callable $transport = null)
    {
        if (!preg_match('#^https?://[^\s/]+#i', $url) || $user === '' || $password === '') {
            throw new \InvalidArgumentException('Nextcloud nie je nastavený celý (adresa, používateľ, heslo aplikácie).');
        }
        $this->url = rtrim($url, '/');
        $this->user = $user;
        $this->password = $password;
        $this->transport = $transport ?? function (string $m, string $u, array $h, ?string $b, int $t): array {
            return \Panel\HttpClient::request($m, $u, $h, $b, $t);
        };
    }

    private function call(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $headers[] = 'Authorization: Basic ' . base64_encode($this->user . ':' . $this->password);
        list($code, $resp) = ($this->transport)($method, $url, $headers, $body, $timeout);
        if ($code === 0) {
            throw new \RuntimeException('Nextcloud neodpovedá (domáci server je vypnutý alebo nedostupný) — súbory sú dočasne nedostupné.');
        }
        if ($code === 401) {
            throw new \RuntimeException('Nextcloud odmietol prihlásenie — skontrolujte používateľa a heslo aplikácie (Nastavenia → Systém → Integrácie).');
        }
        return [(int)$code, (string)$resp];
    }

    private function dav(string $path): string
    {
        $parts = array_map('rawurlencode', array_values(array_filter(explode('/', $path), 'strlen')));
        return $this->url . '/remote.php/dav/files/' . rawurlencode($this->user) . '/' . implode('/', $parts);
    }

    /** Is the server up and the login right? @return string version */
    public function check(): string
    {
        list($code, $body) = $this->call('GET', $this->url . '/status.php', [], null, 10);
        $s = json_decode($body, true);
        if ($code !== 200 || !is_array($s) || empty($s['installed'])) {
            throw new \RuntimeException('Na adrese ' . $this->url . ' nie je Nextcloud (HTTP ' . $code . ').');
        }
        if (!empty($s['maintenance'])) {
            throw new \RuntimeException('Nextcloud je v režime údržby — skúste neskôr.');
        }
        list($code) = $this->call('PROPFIND', $this->dav(''), ['Depth: 0'], null, 10);
        if ($code !== 207) {
            throw new \RuntimeException('Nextcloud nepustil používateľa k súborom (HTTP ' . $code . ').');
        }
        return (string)($s['versionstring'] ?? $s['version'] ?? '');
    }

    /** @return array|null files and folders in the folder (name, size, modified, type, dir); null = the folder does not exist */
    public function listFolder(string $path): ?array
    {
        $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:getlastmodified/>'
            . '<d:getcontentlength/><d:getcontenttype/><d:resourcetype/><oc:size/></d:prop></d:propfind>';
        list($code, $xml) = $this->call('PROPFIND', $this->dav($path), ['Depth: 1', 'Content-Type: application/xml'], $body);
        if ($code === 404) {
            return null;
        }
        if ($code !== 207) {
            throw new \RuntimeException('Zoznam súborov sa nedá načítať (HTTP ' . $code . ').');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new \RuntimeException('Nextcloud vrátil nečitateľný zoznam súborov.');
        }
        $doc->registerXPathNamespace('d', 'DAV:');
        $self = rtrim(parse_url($this->dav($path), PHP_URL_PATH), '/');
        $out = [];
        foreach ($doc->xpath('//d:response') as $r) {
            $r->registerXPathNamespace('d', 'DAV:');
            $href = rtrim(rawurldecode((string)$r->xpath('d:href')[0]), '/');
            if ($href === rawurldecode($self)) {
                continue;   // the folder itself
            }
            $prop = function (string $name) use ($r) {
                $x = $r->xpath('d:propstat/d:prop/' . $name);
                return $x ? (string)$x[0] : '';
            };
            $isDir = (bool)$r->xpath('d:propstat/d:prop/d:resourcetype/d:collection');
            $out[] = ['name' => basename($href), 'dir' => $isDir, 'size' => (int)$prop('d:getcontentlength'),
                'modified' => ($t = strtotime($prop('d:getlastmodified'))) ? date('Y-m-d H:i:s', $t) : '', 'type' => $prop('d:getcontenttype')];
        }
        usort($out, function ($a, $b) { return [$b['dir'], $a['name']] <=> [$a['dir'], $b['name']]; });
        return $out;
    }

    /** Creates the folder and its parents (existing ones are fine). */
    public function ensureFolder(string $path): void
    {
        $cur = '';
        foreach (array_values(array_filter(explode('/', $path), 'strlen')) as $part) {
            $cur .= '/' . $part;
            list($code) = $this->call('MKCOL', $this->dav($cur));
            if (!in_array($code, [201, 405], true)) {   // 405 = exists already
                throw new \RuntimeException('Priečinok ' . $cur . ' sa v Nextcloude nedá vytvoriť (HTTP ' . $code . ').');
            }
        }
    }

    public function upload(string $path, string $bytes): void
    {
        list($code) = $this->call('PUT', $this->dav($path), ['Content-Type: application/octet-stream'], $bytes, 120);
        if (!in_array($code, [201, 204], true)) {
            throw new \RuntimeException('Súbor sa nepodarilo nahrať (HTTP ' . $code . ($code === 507 ? ' — v Nextcloude chýba miesto' : '') . ').');
        }
    }

    public function download(string $path): string
    {
        list($code, $body) = $this->call('GET', $this->dav($path), [], null, 120);
        if ($code !== 200) {
            throw new \RuntimeException($code === 404 ? 'Súbor už neexistuje.' : 'Súbor sa nedá stiahnuť (HTTP ' . $code . ').');
        }
        return $body;
    }

    public function delete(string $path): void
    {
        list($code) = $this->call('DELETE', $this->dav($path));
        if (!in_array($code, [204, 404], true)) {
            throw new \RuntimeException('Súbor sa nedá zmazať (HTTP ' . $code . ').');
        }
    }

    /**
     * Public link to a folder: read = download / view, upload = only uploading into it (customers sending their files —
     * they don't see what is there). @return array [id, url]
     */
    public function share(string $path, string $kind, ?string $password, ?string $expires): array
    {
        // upload = "file drop": ONLY create (4) — never publicUpload=true, which turns the link into read + write for everyone
        $want = $kind === 'upload' ? 4 : 1;
        $form = ['path' => '/' . trim($path, '/'), 'shareType' => 3, 'permissions' => $want];
        if ($password !== null && $password !== '') {
            $form['password'] = $password;
        }
        if ($expires !== null && $expires !== '') {
            $form['expireDate'] = $expires;
        }
        list($code, $body) = $this->call('POST', $this->url . '/ocs/v2.php/apps/files_sharing/api/v1/shares?format=json',
            ['OCS-APIRequest: true', 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($form));
        $j = json_decode($body, true);
        $data = $j['ocs']['data'] ?? null;
        if ($code !== 200 || !is_array($data) || empty($data['url'])) {
            $msg = (string)($j['ocs']['meta']['message'] ?? '');
            throw new \RuntimeException('Odkaz sa nepodarilo vytvoriť' . ($msg !== '' ? ': ' . $msg : ' (HTTP ' . $code . ')') . '.');
        }
        // permission bits: 1 read, 2 update, 4 create, 8 delete, 16 share (Nextcloud adds 16 to public links by itself)
        $ok = function ($p) use ($kind) { $p = (int)$p; return $kind === 'upload' ? ($p & 4) && !($p & (1 | 2 | 8)) : ($p & 1) && !($p & (2 | 4 | 8)); };
        if (isset($data['permissions']) && !$ok($data['permissions'])) {
            // the server decided otherwise (settings, version) — enforce, and never leave a link with more rights than asked
            list($c2, $b2) = $this->call('PUT', $this->url . '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . rawurlencode((string)$data['id']) . '?format=json',
                ['OCS-APIRequest: true', 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query(['permissions' => $want]));
            $d2 = json_decode($b2, true)['ocs']['data'] ?? null;
            if ($c2 !== 200 || !$ok($d2['permissions'] ?? 15)) {
                $this->unshare((string)$data['id']);
                throw new \RuntimeException('Nextcloud nedovolil odkaz len ' . ($kind === 'upload' ? 'na nahratie' : 'na čítanie')
                    . ' — odkaz sa nevytvoril (skontrolujte nastavenie zdieľania v Nextcloude).');
            }
        }
        return [(string)$data['id'], (string)$data['url']];
    }

    public function unshare(string $id): void
    {
        list($code) = $this->call('DELETE', $this->url . '/ocs/v2.php/apps/files_sharing/api/v1/shares/' . rawurlencode($id) . '?format=json',
            ['OCS-APIRequest: true', 'Accept: application/json']);
        if (!in_array($code, [200, 404], true)) {
            throw new \RuntimeException('Odkaz sa nepodarilo zrušiť (HTTP ' . $code . ').');
        }
    }

    /** Address of the folder in the Nextcloud web (for people who have an account there). */
    public function webUrl(string $path): string
    {
        return $this->url . '/index.php/apps/files/?dir=' . rawurlencode('/' . trim($path, '/'));
    }
}
