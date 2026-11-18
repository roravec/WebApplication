<?php
namespace Panel;

/** Slovak formatting of money, numbers and dates for templates ($f in views). */
class Format
{
    public function money($amount, string $currency = 'EUR'): string
    {
        $symbol = $currency === 'EUR' ? '€' : $currency;
        return number_format((float)$amount, 2, ',', ' ') . ' ' . $symbol;
    }

    public function number($value, int $decimals = 2): string
    {
        return number_format((float)$value, $decimals, ',', ' ');
    }

    public function date(?string $value): string
    {
        if ($value === null || $value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $t = strtotime($value);
        return $t ? date('j. n. Y', $t) : $value;
    }

    public function dateTime(?string $value): string
    {
        if ($value === null || $value === '' || strpos($value, '0000-00-00') === 0) {
            return '';
        }
        $t = strtotime($value);
        return $t ? date('j. n. Y H:i', $t) : $value;
    }
}
