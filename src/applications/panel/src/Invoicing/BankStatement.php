<?php
namespace Panel\Invoicing;

/**
 * Bank statement in ISO 20022 CAMT.053 (BkToCstmrStmt) — the SEPA / Slovak Banking Association standard that Slovak
 * banks export (Fio: "SBA XML", Tatra banka, SLSP, VÚB, ČSOB …). Versions camt.053.001.02 – .08 (the namespace and
 * the status element differ; the elements we need are the same).
 * Slovak payment symbols are in EndToEndId as "/VS<vs>/SS<ss>/KS<ks>" (SBA convention) or "VS:<vs>SS:<ss>KS:<ks>"
 * (Fio since 6. 4. 2020, API doc 16. 10. 2025 ch. 5.3.1.9); some banks put the VS into
 * the structured creditor reference or the message — all of them are searched.
 */
class BankStatement
{
    /**
     * @return array{iban: string, currency: string, entries: array} entries: ref, date, amount (negative = debit),
     *         currency, vs, ss, ks, payer, payer_iban, message
     */
    public static function parseCamt053(string $xml): array
    {
        if (strpos($xml, 'BkToCstmrStmt') === false) {
            throw new \InvalidArgumentException('Súbor nie je výpis vo formáte CAMT.053 (XML).');
        }
        // namespace-free access; LIBXML_NONET + no entity substitution = no external entities (XXE)
        $xml = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($doc === false || !isset($doc->BkToCstmrStmt)) {
            throw new \InvalidArgumentException('Výpis sa nedá prečítať (poškodené XML).');
        }
        $out = ['iban' => '', 'currency' => '', 'entries' => []];
        foreach ($doc->BkToCstmrStmt->Stmt as $stmt) {
            $out['iban'] = $out['iban'] ?: strtoupper((string)$stmt->Acct->Id->IBAN);
            $out['currency'] = $out['currency'] ?: (string)$stmt->Acct->Ccy;
            foreach ($stmt->Ntry as $n) {
                $status = trim((string)($n->Sts->Cd ?? '') ?: (string)$n->Sts);
                if ($status !== '' && $status !== 'BOOK') {
                    continue;   // pending / info entries are not money on the account yet
                }
                $sign = (string)$n->CdtDbtInd === 'DBIT' ? -1 : 1;
                $txs = isset($n->NtryDtls->TxDtls) ? $n->NtryDtls->TxDtls : [null];
                $count = isset($n->NtryDtls->TxDtls) ? count($n->NtryDtls->TxDtls) : 1;
                $i = -1;
                foreach ($txs as $tx) {
                    $i++;
                    $amount = ($tx !== null && $count > 1 && isset($tx->Amt)) ? (float)$tx->Amt : (float)$n->Amt;
                    $e2e = $tx !== null ? (string)$tx->Refs->EndToEndId : '';
                    $parts = [];
                    if ($tx !== null && isset($tx->RmtInf->Ustrd)) {
                        foreach ($tx->RmtInf->Ustrd as $u) {
                            $parts[] = trim((string)$u);
                        }
                    }
                    $message = trim(implode(' ', $parts));
                    $cref = $tx !== null ? (string)($tx->RmtInf->Strd->CdtrRefInf->Ref ?? '') : '';
                    $party = $sign > 0 ? 'Dbtr' : 'Cdtr';
                    $payer = $tx !== null ? trim((string)($tx->RltdPties->$party->Nm ?? '') ?: (string)($tx->RltdPties->$party->Pty->Nm ?? '')) : '';
                    $payerIban = $tx !== null ? (string)($tx->RltdPties->{$party . 'Acct'}->Id->IBAN ?? '') : '';
                    $ref = (string)($tx !== null ? ($tx->Refs->AcctSvcrRef ?? '') : '') ?: ((string)$n->AcctSvcrRef ?: (string)$n->NtryRef);
                    if ($count > 1 && $ref !== '' && $ref === ((string)$n->AcctSvcrRef ?: (string)$n->NtryRef)) {
                        $ref .= '-' . $i;
                    }
                    $sym = self::symbols($e2e . ' ' . $cref . ' ' . $message);
                    $out['entries'][] = [
                        'ref' => mb_substr($ref, 0, 64, 'UTF-8'),
                        'date' => (string)($n->BookgDt->Dt ?? '') ?: substr((string)($n->BookgDt->DtTm ?? ''), 0, 10),
                        'amount' => round($sign * $amount, 2),
                        'currency' => (string)$n->Amt['Ccy'],
                        'vs' => $sym['vs'], 'ss' => $sym['ss'], 'ks' => $sym['ks'],
                        'payer' => $payer, 'payer_iban' => $payerIban, 'message' => $message,
                    ];
                }
            }
        }
        return $out;
    }

    /** VS / SS / KS from "/VS123/SS456/KS0308", "VS:123", "VS 123" … @return array{vs: string, ss: string, ks: string} */
    public static function symbols(string $text): array
    {
        $r = ['vs' => '', 'ss' => '', 'ks' => ''];
        foreach (['vs', 'ss', 'ks'] as $k) {
            // "/VS123/SS/KS0308" (SBA), "/VS/123/SS/…" (old), "VS:123SS:456KS:0308" (Fio since 2020), "VS 123"
            if (preg_match('/(?<![A-Za-z])' . strtoupper($k) . '[\s:\-\/]*(\d{1,10})/i', $text, $m)) {
                $r[$k] = ltrim($m[1], '0') === '' ? '0' : $m[1];
            }
        }
        if ($r['vs'] === '' && preg_match('/^\s*(\d{1,10})\s*$/', explode(' ', trim($text))[0] ?? '', $m)) {
            $r['vs'] = $m[1];   // plain number as the end-to-end reference
        }
        return $r;
    }
}
