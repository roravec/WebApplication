<?php
namespace Panel\Invoicing;

/**
 * PAY by square — payment QR code of the Slovak Banking Association (SBA).
 * Official specification: "by square – PAY specifications 1.2.0" (2025-10-09, sbaonline.sk), summary in docs/legislativa.md:
 *  1. fields in the order of the XSD schema, separated by TAB (TABs inside values → space);
 *  2. CRC32 of that UTF-8 string, little-endian, put in front (4 bytes);
 *  3. LZMA compression (lc=3, lp=0, pb=2, dictionary 128 KiB), preceded by the uncompressed length (uint16 LE);
 *  4. header: 4 × 4 bits — by square type 0 (PAY), version 0, document type 0, reserved 0;
 *  5. Base32hex (0-9 A-V), padded with zero bits to a multiple of 5 → text for a QR code (alphanumeric mode, level L).
 * Since 1.2.0 the beneficiary name is REQUIRED; the beneficiary fields (added in 1.1.0) go after all payments.
 *
 * The shared hosting has no `xz` binary, so LZMA is done here in PHP: a literal-only LZMA stream (valid LZMA without
 * matches — no compression, which is fine for ~150 bytes; decoders know the length from the header).
 * The result was verified by decoding with Python's lzma (liblzma) — see tests/PanelInvoicingTest.php.
 */
class PayBySquare
{
    /** Specification version implemented (only for information; the header version of PAY by square stays 0). */
    const SPEC = '1.2.0';
    const MAX_SEQUENCE = 550;

    /**
     * @param array $p amount (float), currency, due_date (Y-m-d), vs, ks, ss, note, iban, bic,
     *                 beneficiary_name (required), beneficiary_address1, beneficiary_address2
     */
    public static function encode(array $p): string
    {
        $v = function ($k, int $max) use ($p) {
            $s = str_replace("\t", ' ', trim((string)($p[$k] ?? '')));
            return mb_substr($s, 0, $max, 'UTF-8');
        };
        $name = $v('beneficiary_name', 70);
        if ($name === '') {
            throw new \InvalidArgumentException('PAY by square: meno príjemcu je povinné (špecifikácia 1.2.0).');
        }
        $iban = strtoupper(str_replace(' ', '', (string)($p['iban'] ?? '')));
        if ($iban === '') {
            throw new \InvalidArgumentException('PAY by square: chýba IBAN.');
        }
        $due = (string)($p['due_date'] ?? '');
        $fields = [
            '',                                                          // InvoiceID
            '1',                                                         // Payments (count)
            '1',                                                         // PaymentOptions: paymentorder
            isset($p['amount']) && $p['amount'] !== null ? Calculator::fmt((float)$p['amount']) : '',
            strtoupper($v('currency', 3)) ?: 'EUR',
            $due !== '' ? date('Ymd', strtotime($due)) : '',             // PaymentDueDate YYYYMMDD
            $v('vs', 10), $v('ks', 4), $v('ss', 10),
            '',                                                          // OriginatorsReferenceInformation
            $v('note', 140),
            '1',                                                         // BankAccounts (count)
            $iban, strtoupper($v('bic', 11)),
            '0',                                                         // StandingOrderExt
            '0',                                                         // DirectDebitExt
            $name, $v('beneficiary_address1', 70), $v('beneficiary_address2', 70),
        ];
        $data = implode("\t", $fields);
        if (mb_strlen($data, 'UTF-8') > self::MAX_SEQUENCE) {
            throw new \InvalidArgumentException('PAY by square: údaje sú dlhšie ako ' . self::MAX_SEQUENCE . ' znakov.');
        }
        $payload = pack('V', crc32($data)) . $data;
        $bytes = "\x00\x00" . pack('v', strlen($payload)) . self::lzmaLiterals($payload);
        return self::base32hex($bytes);
    }

    public static function base32hex(string $bytes): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
        if ($bytes === '') {
            return '';   // str_split('') is [''] before PHP 8.2
        }
        $bits = '';
        foreach (str_split($bytes) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        if (strlen($bits) % 5) {
            $bits .= str_repeat('0', 5 - strlen($bits) % 5);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ minimal LZMA encoder (literals only)

    /** @var string */ private static $out;
    /** @var int */ private static $low;
    /** @var int */ private static $range;
    /** @var int */ private static $cache;
    /** @var int */ private static $cacheSize;

    /**
     * Raw LZMA1 stream (lc=3, lp=0, pb=2, no end marker) that encodes every byte as a literal.
     * State stays 0 (only literals), so: isMatch[0][pos & 3] = 0, then the byte with the literal coder of context
     * prevByte >> 5 (lc = 3).
     */
    public static function lzmaLiterals(string $data): string
    {
        self::$out = '';
        self::$low = 0;
        self::$range = 0xFFFFFFFF;
        self::$cache = 0;
        self::$cacheSize = 1;
        $isMatch = array_fill(0, 4, 1024);              // state 0 × posState 0..3
        $literal = array_fill(0, 8 * 0x300, 1024);      // 2^lc contexts × 0x300 probabilities
        $prev = 0;
        $n = strlen($data);
        for ($pos = 0; $pos < $n; $pos++) {
            self::bit($isMatch[$pos & 3], 0);
            $byte = ord($data[$pos]);
            $base = ($prev >> 5) * 0x300;
            $symbol = 1;
            for ($i = 7; $i >= 0; $i--) {
                $b = ($byte >> $i) & 1;
                self::bit($literal[$base + $symbol], $b);
                $symbol = ($symbol << 1) | $b;
            }
            $prev = $byte;
        }
        for ($i = 0; $i < 5; $i++) {
            self::shiftLow();
        }
        return self::$out;
    }

    private static function bit(int &$prob, int $bit): void
    {
        $bound = (self::$range >> 11) * $prob;
        if ($bit === 0) {
            self::$range = $bound;
            $prob += (2048 - $prob) >> 5;
        } else {
            self::$low += $bound;
            self::$range -= $bound;
            $prob -= $prob >> 5;
        }
        while (self::$range < 0x01000000) {
            self::$range = (self::$range << 8) & 0xFFFFFFFF;
            self::shiftLow();
        }
    }

    private static function shiftLow(): void
    {
        if (self::$low < 0xFF000000 || self::$low > 0xFFFFFFFF) {
            $carry = self::$low >> 32;
            $temp = self::$cache;
            do {
                self::$out .= chr(($temp + $carry) & 0xFF);
                $temp = 0xFF;
            } while (--self::$cacheSize !== 0);
            self::$cache = (self::$low >> 24) & 0xFF;
        }
        self::$cacheSize++;
        self::$low = (self::$low & 0x00FFFFFF) << 8;
    }
}
