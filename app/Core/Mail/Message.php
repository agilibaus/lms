<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Un messaggio email in testo semplice, con intestazioni codificate per UTF-8.
 *
 * Solo testo: niente HTML. Le email della piattaforma sono brevi e contengono
 * un link — l'HTML aggiungerebbe problemi di rendering e filtri antispam senza
 * portare nulla.
 */
class Message
{
    public function __construct(
        public string $toEmail,
        public string $toName,
        public string $subject,
        public string $body,
    ) {
    }

    public function recipient(): string
    {
        return $this->toName === ''
            ? $this->toEmail
            : self::encodeHeader($this->toName) . ' <' . $this->toEmail . '>';
    }

    /**
     * Intestazioni + corpo, pronte per il comando DATA di SMTP.
     */
    public function toRfc822(string $fromEmail, string $fromName): string
    {
        $headers = [
            'From: ' . (($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $fromEmail . '>'),
            'To: ' . $this->recipient(),
            'Subject: ' . self::encodeHeader($this->subject),
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::domainOf($fromEmail) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Auto-Submitted: auto-generated',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->normalizeBody();
    }

    /**
     * Le righe del corpo vanno separate da CRLF, e una riga di solo punto
     * chiuderebbe il comando DATA: va protetta con il "dot stuffing".
     */
    public function normalizeBody(): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $this->body);
        $body = str_replace("\n", "\r\n", $body);

        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /**
     * Codifica MIME "encoded-word" per le intestazioni non ASCII (RFC 2047).
     */
    public static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function domainOf(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'localhost';
    }
}
