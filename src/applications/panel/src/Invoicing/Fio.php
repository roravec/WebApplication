<?php
namespace Panel\Invoicing;

use Panel\HttpClient;

/**
 * Fio banka API (official doc "API Bankovnictví", version 16. 10. 2025): movements of a period in the SBA XML format
 * (= camt.053.001.02, parsed by BankStatement). Read-only token from Fio internet banking (Nastavenia → API).
 * Limit: one request per token per 30 seconds (HTTP 409 otherwise). Token = 64 characters, usable 5 minutes after its
 * authorization, valid max. 180 days (or auto-extended at each login); data older than 90 days only after unlocking the
 * history in internet banking (HTTP 422 otherwise); HTTP 500 = token does not exist / is not active (doc chapter 8).
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
        if ($code === 500) {
            // doc chapter 8 "Známé chybové stavy": 500 = the token does not exist or is not active
            throw new \RuntimeException('Fio hlási neexistujúci alebo neaktívny token (HTTP 500). Token začne fungovať až 5 minút po'
                . ' autorizácii v internetbankingu (pri účte s podpisom viacerých osôb ho musia podpísať všetci), platí najviac 180 dní'
                . ' (ak nie je zapnuté automatické predlžovanie) a len pre jeden účet. Skontrolujte ho v internetbankingu: Nastavenia → API.');
        }
        if ($code === 422) {
            throw new \RuntimeException('Fio: údaje staršie ako 90 dní treba najprv odomknúť v internetbankingu (Nastavenia → API → zámok pri'
                . ' tokene, platí 10 minút) — alebo zvoľte obdobie z posledných 90 dní.');
        }
        if ($code === 413) {
            throw new \RuntimeException('Fio: v období je viac ako 50 000 pohybov — zvoľte kratšie obdobie.');
        }
        if ($code !== 200) {
            // never put the URL (contains the token) into the message. Observed 2026-11-19: with an invalid token
            // Fio sometimes doesn't answer at all on /periods (timeout).
            throw new \RuntimeException($code === 0
                ? 'Fio banka neodpovedala do 30 sekúnd. Pri neplatnom tokene Fio niekedy neodpovedá vôbec — skontrolujte token pri firme (Firmy → bankové účty).'
                : 'Fio banka vrátila chybu (HTTP ' . $code . ').');
        }
        return $body;
    }
}
