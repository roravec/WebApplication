<?php
namespace Panel;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends e-mails of the panel (invoices now, task notifications later) with PHPMailer (Composer, src/vendor).
 * Server settings come from config.php → apps.panel.mail (credentials are never stored in the database):
 *   ['transport' => 'smtp'|'mail', 'host', 'port', 'secure' => 'ssl'|'tls'|'', 'user', 'password']
 * Dev: SMTP mail:1025 = Mailpit (http://<dev-server>:8025), nothing leaves the server.
 */
class Mailer
{
    /** @var array */ private $cfg;

    public function __construct(array $config)
    {
        $this->cfg = $config;
    }

    public function configured(): bool
    {
        return class_exists(PHPMailer::class) && (($this->cfg['transport'] ?? '') === 'mail' || !empty($this->cfg['host']));
    }

    /**
     * @param array $m from, from_name, reply_to, to (string[]), cc (string[]), bcc (string[]), subject, text,
     *                 attachments: list of [filename, bytes, mime]
     * @throws \RuntimeException with a readable reason when sending fails
     */
    public function send(array $m): void
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Odosielanie e-mailov nie je nastavené (config.php → apps.panel.mail).');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            if (($this->cfg['transport'] ?? 'smtp') === 'smtp') {
                $mail->isSMTP();
                $mail->Host = (string)$this->cfg['host'];
                $mail->Port = (int)($this->cfg['port'] ?? 587);
                $secure = (string)($this->cfg['secure'] ?? '');
                $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
                $mail->SMTPAutoTLS = $secure !== '';
                if (!empty($this->cfg['user'])) {
                    $mail->SMTPAuth = true;
                    $mail->Username = (string)$this->cfg['user'];
                    $mail->Password = (string)($this->cfg['password'] ?? '');
                }
                $mail->Timeout = 20;
            } else {
                $mail->isMail();
            }
            $mail->setFrom((string)$m['from'], (string)($m['from_name'] ?? ''));
            if (!empty($m['reply_to'])) {
                $mail->addReplyTo((string)$m['reply_to']);
            }
            foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $k => $method) {
                foreach ((array)($m[$k] ?? []) as $addr) {
                    $mail->$method((string)$addr);
                }
            }
            $mail->Subject = (string)$m['subject'];
            $mail->Body = (string)$m['text'];
            $mail->isHTML(false);
            foreach ((array)($m['attachments'] ?? []) as $a) {
                $mail->addStringAttachment($a[1], $a[0], PHPMailer::ENCODING_BASE64, $a[2] ?? 'application/octet-stream');
            }
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            throw new \RuntimeException($mail->ErrorInfo ?: $e->getMessage());
        }
    }

    /** "a@b.sk, c@d.sk" → valid addresses; null when any of them is invalid. */
    public static function parseList(string $list): ?array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                return null;
            }
            $out[] = $a;
        }
        return array_values(array_unique($out));
    }
}
