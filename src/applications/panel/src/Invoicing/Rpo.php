<?php
namespace Panel\Invoicing;

/**
 * Register of legal entities (Register právnických osôb, Štatistický úrad SR) — public API, data under CC BY 4.0
 * ("Zdroj: RPO, ŠÚ SR"). https://api.statistics.sk/rpo/v1/search?identifier=<IČO>
 * Gives the current name, registered address and the commercial register entry (súd, oddiel, vložka).
 * DIČ / IČ DPH are NOT in RPO (they are kept by Finančná správa).
 */
class Rpo
{
    const URL = 'https://api.statistics.sk/rpo/v1/search?identifier=';
    const SOURCE = 'Zdroj: Register právnických osôb, ŠÚ SR (CC BY 4.0)';

    /** @return array|null name, street, zip, city, country, registration — null when not found */
    public static function lookup(string $ico, int $timeout = 8): ?array
    {
        $ico = preg_replace('/\D/', '', $ico);
        if (!preg_match('/^\d{6,8}$/', $ico)) {
            throw new \InvalidArgumentException('IČO musí mať 6–8 číslic.');
        }
        $json = self::get(self::URL . $ico, $timeout);
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['results'])) {
            throw new \RuntimeException('Register neodpovedal správne.');
        }
        foreach ($data['results'] as $r) {
            if (!empty($r['termination'])) {
                continue;   // dissolved entity
            }
            return self::map($r);
        }
        return null;
    }

    /** Current values (no validTo) of one RPO record → our fields. */
    public static function map(array $r): array
    {
        $current = function (array $list) {
            foreach ($list as $item) {
                if (empty($item['validTo'])) {
                    return $item;
                }
            }
            return $list ? end($list) : null;
        };
        $name = $current($r['fullNames'] ?? []);
        $a = $current($r['addresses'] ?? []) ?: [];
        $city = (string)($a['municipality']['value'] ?? '');
        $no = (int)($a['regNumber'] ?? 0) > 0
            ? $a['regNumber'] . (!empty($a['buildingNumber']) ? '/' . $a['buildingNumber'] : '')
            : (string)($a['buildingNumber'] ?? '');
        $street = trim((!empty($a['street']) ? $a['street'] : $city) . ' ' . $no);
        $zip = (string)($a['postalCodes'][0] ?? '');
        if (preg_match('/^\d{5}$/', $zip)) {
            $zip = substr($zip, 0, 3) . ' ' . substr($zip, 3);
        }
        $countryCode = (string)($a['country']['code'] ?? '703');
        return [
            'name' => (string)($name['value'] ?? ''),
            'street' => $street,
            'zip' => $zip,
            'city' => $city,
            'country' => $countryCode === '703' ? 'SK' : '',
            'registration' => self::registration($r['sourceRegister'] ?? []),
        ];
    }

    /** "Obchodný register Okresného súdu Trenčín, oddiel Sro, vložka č. 43969/R" from the RPO register data. */
    public static function registration(array $reg): string
    {
        $office = '';
        foreach ($reg['registrationOffices'] ?? [] as $o) {
            if (empty($o['validTo'])) {
                $office = (string)$o['value'];
            }
        }
        $number = '';
        foreach ($reg['registrationNumbers'] ?? [] as $n) {
            if (empty($n['validTo'])) {
                $number = (string)$n['value'];
            }
        }
        if ($office === '' || $number === '') {
            return '';
        }
        $register = (string)($reg['value']['value'] ?? '');
        if (($reg['value']['code'] ?? '') === '1' && preg_match('#^([^/]+)/(.+)$#', $number, $m)) {
            // genitive of the court: "Okresný súd Trenčín" → "Okresného súdu Trenčín", "Mestský súd …" → "Mestského súdu …"
            $court = preg_replace(['/^Okresný súd/u', '/^Mestský súd/u'], ['Okresného súdu', 'Mestského súdu'], $office);
            return 'Obchodný register ' . $court . ', oddiel ' . $m[1] . ', vložka č. ' . $m[2];
        }
        return trim($register . ', ' . $office . ', č. ' . $number, ', ');
    }

    private static function get(string $url, int $timeout): string
    {
        list($code, $body) = \Panel\HttpClient::get($url, ['Accept: application/json'], $timeout);
        if ($code !== 200) {
            throw new \RuntimeException($code === 429 ? 'Register je dočasne preťažený, skúste o chvíľu.'
                : 'Register je nedostupný' . ($code === 0 ? ' (' . $body . ')' : ' (HTTP ' . $code . ')') . '.');
        }
        return $body;
    }
}
