<?php
namespace Panel\Shop;

use Panel\HttpClient;

/**
 * PrestaShop Webservice (REST/XML) client — works with ANY PrestaShop 1.7 / 8 (local or remote), see
 * docs/sklad-specifikacia.md. The shop's admin creates a webservice key (Advanced parameters → Webservice) with the
 * resources the panel needs; the key is stored encrypted in the panel (Panel\Secret).
 * Auth: HTTP Basic with the key as the user name (the key is never put into the URL).
 * Multistore: `id_shop` / `id_group_shop` URL parameters select the context; stock rows (stock_availables) belong to a
 * shop or — when the shop group shares stock — to the shop group.
 */
class PrestaShopApi
{
    /** @var string */ private $base;
    /** @var string */ private $key;
    /** @var int */ private $timeout;

    /** @param string $shopUrl e.g. https://www.example.sk/ or https://example.sk/store/ */
    public function __construct(string $shopUrl, string $key, int $timeout = 20)
    {
        if (!preg_match('#^https?://#i', $shopUrl)) {
            throw new \InvalidArgumentException('Adresa e-shopu musí začínať http:// alebo https://');
        }
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            throw new \InvalidArgumentException('Kľúč Webservice musí mať 32 znakov (písmená a čísla).');
        }
        $this->base = rtrim($shopUrl, '/') . '/api/';
        $this->key = $key;
        $this->timeout = $timeout;
    }

    /**
     * GET a resource list or one object.
     * @param array $params display, filter[...] (passed as 'filter' => ['id' => '[1|2]']), limit, sort, id_shop, id_group_shop
     */
    public function get(string $resource, array $params = []): \SimpleXMLElement
    {
        return $this->call('GET', $resource, $params);
    }

    /** PUT a full object (as returned by get() of one object, modified). */
    public function put(string $resource, int $id, \SimpleXMLElement $xml, array $params = []): \SimpleXMLElement
    {
        return $this->call('PUT', $resource . '/' . $id, $params, $xml->asXML());
    }

    /** POST a new object; $xml = the <prestashop><resource>…</resource></prestashop> document. */
    public function post(string $resource, string $xml, array $params = []): \SimpleXMLElement
    {
        return $this->call('POST', $resource, $params, $xml);
    }

    /** DELETE one object. */
    public function delete(string $resource, int $id, array $params = []): void
    {
        $this->call('DELETE', $resource . '/' . $id, $params);
    }

    /**
     * Several GETs in parallel. @param array $requests key => [resource, params] (null entries are skipped)
     * @param string[] $optional keys whose failure gives null instead of an exception (e.g. a resource the key may lack)
     * @return array key => SimpleXMLElement|null
     */
    public function getMany(array $requests, array $optional = []): array
    {
        $http = [];
        foreach (array_filter($requests) as $k => $r) {
            $http[$k] = ['GET', $this->url($r[0], $r[1] ?? []), $this->headers(), null];
        }
        $out = [];
        foreach (HttpClient::requestMany($http, $this->timeout) as $k => $res) {
            try {
                $out[$k] = $this->parse('GET', $requests[$k][0], $res[0], $res[1]);
            } catch (\RuntimeException $e) {
                if (!in_array($k, $optional, true)) {
                    throw $e;
                }
                $out[$k] = null;
            }
        }
        return $out;
    }

    private function call(string $method, string $path, array $params, ?string $body = null): \SimpleXMLElement
    {
        list($code, $res) = HttpClient::request($method, $this->url($path, $params), $this->headers(), $body, $this->timeout);
        return $this->parse($method, $path, $code, $res);
    }

    private function url(string $path, array $params): string
    {
        $flat = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $sub => $sv) {
                    $flat[] = rawurlencode($k . '[' . $sub . ']') . '=' . rawurlencode((string)$sv);
                }
            } else {
                $flat[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
        }
        return $this->base . $path . ($flat ? '?' . implode('&', $flat) : '');
    }

    private function headers(): array
    {
        return ['Authorization: Basic ' . base64_encode($this->key . ':'), 'Accept: application/xml',
            'Content-Type: application/xml; charset=utf-8', 'Output-Format: XML'];
    }

    /** HTTP answer → XML, or a readable exception (its code = the HTTP status, 0 = no connection). */
    private function parse(string $method, string $path, int $code, string $res): \SimpleXMLElement
    {
        if ($code === 0) {
            throw new \RuntimeException('E-shop je nedostupný (' . $res . ').');
        }
        if ($code === 401) {
            throw new \RuntimeException('E-shop odmietol kľúč Webservice (neplatný alebo neaktívny kľúč).', 401);
        }
        if ($method === 'DELETE' && $code >= 200 && $code < 300 && trim($res) === '') {
            return new \SimpleXMLElement('<prestashop/>');   // PrestaShop answers a DELETE with an empty body
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($res, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($code >= 400 || $xml === false) {
            $msg = $xml !== false && isset($xml->errors->error) ? trim((string)$xml->errors->error[0]->message) : '';
            if ($code === 405 || $code === 403 || stripos($msg, 'permission') !== false || stripos($msg, 'not allowed') !== false) {
                throw new \RuntimeException('Kľúč Webservice nemá oprávnenie na „' . strtok($path, '/') . '“ (' . $method . ') — doplňte ho v e-shope.', 403);
            }
            throw new \RuntimeException('E-shop vrátil chybu (HTTP ' . $code . ')' . ($msg !== '' ? ': ' . $msg : '.'), $code);
        }
        return $xml;
    }

    /** Language-dependent field → text in the given language id (or the first one). */
    public static function text(\SimpleXMLElement $field, int $langId = 0): string
    {
        if (isset($field->language)) {
            foreach ($field->language as $l) {
                if (($langId === 0 || (int)$l['id'] === $langId) && trim((string)$l) !== '') {
                    return (string)$l;
                }
            }
            foreach ($field->language as $l) {      // no text in that language → the first non-empty one
                if (trim((string)$l) !== '') {
                    return (string)$l;
                }
            }
            return '';
        }
        return (string)$field;
    }
}
