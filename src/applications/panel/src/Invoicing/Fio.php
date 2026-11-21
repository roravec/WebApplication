<?php
namespace Panel\Invoicing;

use Panel\HttpClient;

/**
 * Fio banka API (official doc "API Bankovnictví", version 16. 10. 2025): movements of a period in Fio's JSON format,
 * converted to the same structure as BankStatement::parseCamt053 (the review + pairing is shared with the file upload).
 * NOT "sba_xml": that format exists only for official statements (/by-id/); on /periods/ Fio answers HTTP 500
 * "errorCode 6420 Chyba aplikace" — the same status as for a bad token (verified with a real token 2026-11-21).
 * Read-only token from Fio internet banking (Nastavenia → API).
 * Limit: one request per token per 30 seconds (HTTP 409 otherwise). Token = 64 characters, usable 5 minutes after its
 * authorization, valid max. 180 days (or auto-extended at each login); data older than 90 days only after unlocking the
 * history in internet banking (HTTP 422 otherwise); HTTP 500 = token does not exist / is not active (doc chapter 8).
 */
class Fio
{
    const URL = 'https://fioapi.fio.cz/v1/rest/periods/%s/%s/%s/transactions.json';

    /** @return array the same shape as BankStatement::parseCamt053 (iban, currency, entries) */
    public static function movements(string $token, string $from, string $to): array
    {
        if (!preg_match('/^[A-Za-z0-9]{20,100}$/', $token)) {
            throw new \InvalidArgumentException('Neplatný Fio token.');
        }
        foreach ([$from, $to] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new \InvalidArgumentException('Neplatný dátum.');
            }
        }
        list($code, $body) = HttpClient::get(sprintf(self::URL, $token, $from, $to), ['Accept: application/json'], 30);
        // Fio's own error text (XML <message>, e.g. "Chyba aplikace" with <errorCode>) — never contains the token
        $fio = '';
        if ($code !== 200 && preg_match('#<message>([^<]{1,200})</message>#', (string)$body, $m)) {
            $fio = ' Fio: „' . trim($m[1]) . '“' . (preg_match('#<errorCode>(\d{1,10})</errorCode>#', (string)$body, $c) ? ' (kód ' . $c[1] . ')' : '') . '.';
        }
        if ($code === 409) {
            throw new \RuntimeException('Fio dovoľuje sťahovať raz za 30 sekúnd — skúste o chvíľu.');
        }
        if ($code === 401 || $code === 403 || $code === 404) {
            throw new \RuntimeException('Fio token nie je platný alebo nemá oprávnenie (HTTP ' . $code . ').' . $fio);
        }
        if ($code === 500) {
            // doc chapter 8 "Známé chybové stavy": 500 = the token does not exist or is not active
            throw new \RuntimeException('Fio hlási chybu (HTTP 500).' . $fio . ' Najčastejšie ide o neexistujúci alebo neaktívny token: token začne'
                . ' fungovať až 5 minút po autorizácii v internetbankingu (pri účte s podpisom viacerých osôb ho musia podpísať všetci), platí'
                . ' najviac 180 dní (ak nie je zapnuté automatické predlžovanie) a len pre jeden účet. Skontrolujte ho v internetbankingu:'
                . ' Nastavenia → API.');
        }
        if ($code === 422) {
            throw new \RuntimeException('Fio: údaje staršie ako 90 dní treba najprv odomknúť v internetbankingu (Nastavenia → API → zámok pri'
                . ' tokene, platí 10 minút) — alebo zvoľte obdobie z posledných 90 dní.');
        }
        if ($code === 413) {
            throw new \RuntimeException('Fio: v období je viac ako 50 000 pohybov — zvoľte kratšie obdobie.');
        }
        if ($code !== 200) {
            // never put the URL (contains the token) into the message
            throw new \RuntimeException($code === 0
                ? 'Fio banka neodpovedala do 30 sekúnd — skúste to znova o chvíľu. Ak to trvá, skontrolujte token pri firme (Firmy → bankové účty).'
                : 'Fio banka vrátila chybu (HTTP ' . $code . ').' . $fio);
        }
        return self::parseJson($body);
    }

    /**
     * Fio JSON (accountStatement) → ['iban', 'currency', 'entries' => [ref, date, amount, currency, vs, ss, ks, payer,
     * payer_iban, message]]. Columns per the doc ch. 5.3: 22 ID pohybu (= ref, unique per movement), 0 date
     * "2026-11-06+0100", 1 amount (signed), 14 currency, 2 counter-account (IBAN for SEPA payments, else a local
     * number + 3 bank code), 10 counter-account name, 4 KS, 5 VS, 6 SS, 16 message for the recipient,
     * 7 user identification. /periods/ returns booked movements only.
     */
    public static function parseJson(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['accountStatement']['info'])) {
            throw new \RuntimeException('Fio banka vrátila neočakávanú odpoveď (nie je to zoznam pohybov).');
        }
        $info = $d['accountStatement']['info'];
        $out = ['iban' => strtoupper(str_replace(' ', '', (string)($info['iban'] ?? ''))), 'currency' => (string)($info['currency'] ?? ''), 'entries' => []];
        $list = $d['accountStatement']['transactionList']['transaction'] ?? [];
        foreach (is_array($list) ? $list : [] as $t) {
            $col = function (int $n) use ($t): string {
                $v = $t['column' . $n]['value'] ?? null;
                return is_scalar($v) ? trim((string)$v) : '';
            };
            if (!isset($t['column1']['value'])) {
                continue;
            }
            $account = strtoupper(str_replace(' ', '', $col(2)));
            $message = $col(16) !== '' ? $col(16) : $col(7);
            $sym = ['vs' => self::digits($col(5)), 'ss' => self::digits($col(6)), 'ks' => self::digits($col(4))];
            if ($sym['vs'] === '') {
                $sym = array_merge($sym, array_filter(BankStatement::symbols($message), 'strlen'));
            }
            $out['entries'][] = [
                'ref' => mb_substr($col(22), 0, 64, 'UTF-8'),
                'date' => substr($col(0), 0, 10),
                'amount' => round((float)$t['column1']['value'], 2),
                'currency' => $col(14) ?: $out['currency'],
                'vs' => $sym['vs'], 'ss' => $sym['ss'], 'ks' => $sym['ks'],
                'payer' => $col(10),
                'payer_iban' => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $account) ? $account : '',
                'message' => $message,
            ];
        }
        return $out;
    }

    private static function digits(string $s): string
    {
        return preg_match('/^\d{1,10}$/', $s) ? $s : '';
    }
}
