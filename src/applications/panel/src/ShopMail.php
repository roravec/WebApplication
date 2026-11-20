<?php
namespace Panel;

/**
 * E-mails to customers in the look of the e-shop they bought in (owner 2026-11-20: the customer must recognise who
 * writes) — invoices from orders and messages from the order page. Brand = ShopOrders::brand(): name, web, logo bytes.
 */
class ShopMail
{
    /** HTML e-mail in the e-shop's look: its logo (or name) on top, the text, who issues the document at the bottom. */
    public static function html(array $brand, string $text, string $footer, bool $logo): string
    {
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $head = $logo ? '<img src="cid:shoplogo" alt="' . $e($brand['name']) . '" style="max-width:240px;max-height:90px;border:0">'
            : '<span style="font-size:22px;font-weight:bold">' . $e($brand['name']) . '</span>';
        $body = nl2br($e($text));
        if ($brand['web'] !== '') {
            $body = str_replace($e($brand['web']), '<a href="' . $e($brand['web']) . '" style="color:#1a56db">' . $e($brand['web']) . '</a>', $body);
        }
        return '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:8px">'
            . '<tr><td style="padding:24px 28px;border-bottom:1px solid #e5e7eb">' . $head . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55">' . $body . '</td></tr>'
            . ($footer !== '' ? '<tr><td style="padding:16px 28px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb">' . $e($footer) . '</td></tr>' : '')
            . '</table></td></tr></table></body></html>';
    }


    /**
     * Mail array for Mailer::send with the HTML part and the embedded logo when there is a brand.
     * @param array|null $brand ShopOrders::brand() or null (plain text)
     */
    public static function message(array $m, ?array $brand, string $footer = ''): array
    {
        if ($brand === null || $brand['name'] === '') {
            return $m;
        }
        $logo = $brand['logo'] !== '';
        $m['html'] = self::html($brand, (string)$m['text'], $footer, $logo);
        $m['text'] = (string)$m['text'] . ($footer !== '' ? "\n\n-- \n" . $footer : '');
        $m['embedded'] = $logo ? [['shoplogo', $brand['logo'], $brand['logo_mime'], 'logo']] : [];
        return $m;
    }
}
