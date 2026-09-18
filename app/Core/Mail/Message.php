<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * Un messaggio email in testo semplice, con intestazioni codificate per UTF-8.
 *
 * Il corpo resta solo testo: niente HTML. Le email della piattaforma sono
 * brevi e contengono un link — l'HTML aggiungerebbe problemi di rendering e
 * filtri antispam senza portare nulla.
 *
 * Può però avere allegati (oggi: il file .ics degli inviti alle sessioni
 * live). Con un allegato il messaggio diventa multipart/mixed; senza, resta
 * esattamente il messaggio di prima, byte per byte.
 */
class Message
{
    /** @var string|null Confine fra le parti, calcolato una volta sola. */
    private ?string $boundary = null;

    /**
     * @param array<int, array{filename: string, mimeType: string, content: string}> $attachments
     */
    public function __construct(
        public string $toEmail,
        public string $toName,
        public string $subject,
        public string $body,
        public array $attachments = [],
    ) {
    }

    public function recipient(): string
    {
        return $this->toName === ''
            ? $this->toEmail
            : self::encodeHeader($this->toName) . ' <' . $this->toEmail . '>';
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * Intestazioni + corpo, pronte per il comando DATA di SMTP.
     */
    public function toRfc822(string $fromEmail, string $fromName): string
    {
        $headers = array_merge(
            [
                'From: ' . (($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $fromEmail . '>'),
                'To: ' . $this->recipient(),
                'Subject: ' . self::encodeHeader($this->subject),
                'Date: ' . date(DATE_RFC2822),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . self::domainOf($fromEmail) . '>',
                'MIME-Version: 1.0',
            ],
            $this->contentHeaders(),
            ['Auto-Submitted: auto-generated']
        );

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->mimeBody();
    }

    /**
     * Le intestazioni che descrivono il contenuto: una sola parte di testo,
     * oppure l'involucro multipart quando ci sono allegati.
     *
     * @return string[]
     */
    public function contentHeaders(): array
    {
        if (!$this->hasAttachments()) {
            return [
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
        }

        return ['Content-Type: multipart/mixed; boundary="' . $this->boundary() . '"'];
    }

    /**
     * Il corpo pronto da spedire: normalizzato, e con le parti degli allegati
     * quando ce ne sono.
     */
    public function mimeBody(): string
    {
        if (!$this->hasAttachments()) {
            return $this->normalizeBody();
        }

        $boundary = $this->boundary();
        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $this->body,
        ];

        foreach ($this->attachments as $attachment) {
            $filename = self::encodeHeader(basename($attachment['filename']));

            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: ' . $attachment['mimeType'] . '; name="' . $filename . '"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
            $parts[] = '';
            // Le righe base64 non cominciano mai con un punto, quindi la
            // protezione applicata dopo non le tocca.
            $parts[] = rtrim(chunk_split(base64_encode($attachment['content']), 76, "\n"), "\n");
        }

        $parts[] = '--' . $boundary . '--';

        return self::normalize(implode("\n", $parts) . "\n");
    }

    /**
     * Le righe del corpo vanno separate da CRLF, e una riga di solo punto
     * chiuderebbe il comando DATA: va protetta con il "dot stuffing".
     */
    public function normalizeBody(): string
    {
        return self::normalize($this->body);
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

    private static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\n", "\r\n", $text);

        return preg_replace('/^\./m', '..', $text) ?? $text;
    }

    private function boundary(): string
    {
        return $this->boundary ??= '=_pistacchio_' . bin2hex(random_bytes(12));
    }

    private static function domainOf(string $email): string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? 'localhost';
    }
}
