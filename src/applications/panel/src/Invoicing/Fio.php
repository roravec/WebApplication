<?php
namespace Panel\Invoicing;

use Panel\HttpClient;

/**
 * Fio banka API (official doc "API Bankovnictví", version 16. 10. 2025): movements of a period in the SBA XML format
 * (= camt.053.001.02, parsed by BankStatement). Read-only token from Fio internet banking (Nastavenia → API).
 * Limit: one request per token per 30 seconds (HTTP 409 otherwise).
 */
class Fio
{
    const URL = 'https://fioapi.fio.cz/v1/rest/periods/%s/%s/%s/transactions.sba_xml';

    /** @return string CAMT.053 XML */
    public static function movements(string $token, string $from, string $to): string
    {
        if (!preg_match('/^[A-Za-z0-9]{20,100}$/', $token)) {
            throw new \InvalidArgumentException('Neplatný Fio token.');
        }
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new \InvalidArgumentException('Neplatný dátum.');
            }
        }
        list($code, $body) = HttpClient::get(sprintf(self::URL, $token, $from, $to), ['Accept: application/xml'], 30);
        if ($code === 409) {
            throw new \RuntimeException('Fio dovoľuje sťahovať raz za 30 sekúnd — skúste o chvíľu.');
        }
        if ($code === 401 || $code === 403 || $code === 404) {
            throw new \RuntimeException('Fio token nie je platný alebo nemá oprávnenie (HTTP ' . $code . ').');
        }
        if ($code !== 200) {
            // never put the URL (contains the token) into the message. Observed 2026-11-19: with an invalid token
            // Fio doesn't answer at all on /periods (timeout) and answers HTTP 500 on other endpoints.
            throw new \RuntimeException($code === 0
                ? 'Fio banka neodpovedala do 30 sekúnd. Pri neplatnom tokene Fio neodpovedá vôbec — skontrolujte token pri firme (Firmy → bankové účty).'
                : 'Fio banka vrátila chybu (HTTP ' . $code . ')' . ($code === 500 ? ' — najčastejšie neplatný token.' : '.'));
        }
        return $body;
    }
}
