<?php

declare(strict_types=1);

namespace ExpressPHP\Mail;

use DateTimeImmutable;
use Throwable;

final class SMTPMailer
{
    // TLS 1.0 and 1.1 are deprecated (RFC 8996) and are never negotiated.
    private const TLS_METHODS = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    private static array $config = [];

    /**
     * Stores mail settings: enabled flag, default mailer, sender, timeout, EHLO domain, and mailer profiles.
     */
    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Returns the name of the default mailer profile.
     */
    public static function defaultMailer(): string
    {
        return (string)(self::$config['default'] ?? 'smtp');
    }

    /**
     * Sends one message through the named mailer, using STARTTLS or SSL, and AUTH LOGIN when a username is
     * configured.
     */
    public function send(
        string  $mailer,
        string  $recipient,
        string  $subject,
        string  $body,
        bool    $html = true,
        ?string $replyTo = null,
    ): void {
        $config = $this->configuration($mailer);
        $this->assertEmail($recipient, 'recipient');
        if ($replyTo !== null) {
            $this->assertEmail($replyTo, 'reply-to address');
        }

        $socket = $this->connect($config);
        try {
            $this->expect($socket, [220]);
            $hostname = $config['ehlo_domain'];
            $this->command($socket, 'EHLO ' . $hostname, [250]);

            if ($config['encryption'] === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, self::TLS_METHODS)) {
                    throw new MailException('Unable to start TLS encryption.');
                }
                $this->command($socket, 'EHLO ' . $hostname, [250]);
            }

            if ($config['username'] !== '') {
                $this->command($socket, 'AUTH LOGIN', [334]);
                $this->command($socket, base64_encode($config['username']), [334]);
                $this->command($socket, base64_encode($config['password']), [235]);
            }

            $this->command($socket, 'MAIL FROM:<' . $config['from_address'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $message = $this->message($config, $recipient, $subject, $body, $html, $replyTo);
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            $this->write($socket, $message . "\r\n.\r\n");
            $this->expect($socket, [250]);

            // The server has accepted the message; a failed goodbye must not report it as undelivered.
            try {
                $this->command($socket, 'QUIT', [221]);
            } catch (Throwable) {
            }
        } catch (Throwable $exception) {
            throw $exception instanceof MailException
                ? $exception
                : new MailException('SMTP delivery failed.', 0, $exception);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Validates and returns the named mailer's settings, refusing incomplete profiles and authentication without
     * encryption.
     */
    private function configuration(string $mailer): array
    {
        if (!(bool)(self::$config['enabled'] ?? false)) {
            throw new MailException('Email delivery is disabled.');
        }

        $config = self::$config['mailers'][$mailer] ?? null;
        if (!is_array($config)) {
            throw new MailException("Mailer [{$mailer}] is not configured.");
        }

        $config = [
            'host' => trim((string)($config['host'] ?? '')),
            'port' => (int)($config['port'] ?? 587),
            'encryption' => strtolower((string)($config['encryption'] ?? 'tls')),
            'username' => (string)($config['username'] ?? ''),
            'password' => (string)($config['password'] ?? ''),
            'timeout' => max(1, (int)(self::$config['timeout'] ?? 10)),
            'from_address' => trim((string)(self::$config['from_address'] ?? '')),
            'from_name' => trim((string)(self::$config['from_name'] ?? 'ExpressPHP')),
        ];

        if ($config['host'] === '' || $config['from_address'] === '') {
            throw new MailException("Mailer [{$mailer}] is incomplete.");
        }
        if (!in_array($config['encryption'], ['none', 'tls', 'ssl'], true)) {
            throw new MailException('Mail encryption must be none, tls, or ssl.');
        }
        if ($config['encryption'] === 'none' && $config['username'] !== '') {
            throw new MailException('SMTP authentication requires tls or ssl encryption.');
        }
        $this->assertEmail($config['from_address'], 'from address');

        // Identify as the sender's domain rather than leaking the internal server host name.
        $domain = trim((string)(self::$config['ehlo_domain'] ?? ''));
        if ($domain === '') {
            $domain = substr((string)strrchr($config['from_address'], '@'), 1);
        }
        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/', $domain) !== 1) {
            throw new MailException('The mail EHLO domain is invalid.');
        }
        $config['ehlo_domain'] = $domain;
        return $config;
    }

    /**
     * Opens the SMTP connection with certificate verification and TLS 1.2 or newer.
     */
    private function connect(array $config): mixed
    {
        $scheme = $config['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $context = stream_context_create(['ssl' => [
            'crypto_method' => self::TLS_METHODS,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $config['host'],
        ]]);
        $socket = @stream_socket_client(
            $scheme . '://' . $config['host'] . ':' . $config['port'],
            $errorCode,
            $errorMessage,
            $config['timeout'],
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new MailException("Could not connect to SMTP server: {$errorMessage} ({$errorCode}).");
        }
        stream_set_timeout($socket, $config['timeout']);
        return $socket;
    }

    /**
     * Sends one SMTP command and checks the reply code.
     */
    private function command(mixed $socket, #[\SensitiveParameter] string $command, array $expected): string
    {
        $this->write($socket, $command . "\r\n");
        return $this->expect($socket, $expected);
    }

    /**
     * TLS streams can accept fewer bytes than offered, so write until everything has been sent.
     */
    private function write(mixed $socket, #[\SensitiveParameter] string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));
            if ($result === false || $result === 0) {
                $metadata = stream_get_meta_data($socket);
                throw new MailException(($metadata['timed_out'] ?? false)
                    ? 'SMTP server timed out.'
                    : 'SMTP connection closed while sending.');
            }
            $written += $result;
        }
    }

    /**
     * Reads an SMTP reply, which may span several lines, and checks its code.
     */
    private function expect(mixed $socket, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($socket, 8192);
            if ($line === false) {
                $metadata = stream_get_meta_data($socket);
                throw new MailException(($metadata['timed_out'] ?? false)
                    ? 'SMTP server timed out.'
                    : 'SMTP server closed the connection.');
            }
            $response .= $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new MailException('SMTP server rejected the request: ' . trim($response));
        }
        return $response;
    }

    /**
     * Builds the message headers and the Base64-encoded body.
     */
    private function message(
        array   $config,
        string  $recipient,
        string  $subject,
        string  $body,
        bool    $html,
        ?string $replyTo,
    ): string {
        $headers = [
            'Date: ' . (new DateTimeImmutable())->format(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $config['ehlo_domain'] . '>',
            'From: ' . $this->mailbox($config['from_address'], $config['from_name']),
            'To: <' . $recipient . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: ' . ($html ? 'text/html' : 'text/plain') . '; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }

    /**
     * Formats an address with an encoded display name.
     */
    private function mailbox(string $address, string $name): string
    {
        return $name === '' ? '<' . $address . '>' : $this->encodeHeader($name) . ' <' . $address . '>';
    }

    /**
     * Encodes a header value as RFC 2047 words of at most 75 characters, folded onto continuation lines.
     * Words are split on UTF-8 character boundaries so no character is cut in half.
     */
    private function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        if ($value === '') {
            return '';
        }

        // 45 raw bytes encode to 60 base64 characters, leaving room for the 12-character =?UTF-8?B?...?= wrapper.
        $words = [];
        $chunk = '';
        foreach (preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($value) as $character) {
            if ($chunk !== '' && strlen($chunk) + strlen($character) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';

        return implode("\r\n ", $words);
    }

    /**
     * Rejects invalid addresses and addresses containing line breaks.
     */
    private function assertEmail(string $email, string $label): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, "\n")) {
            throw new MailException("The {$label} is invalid.");
        }
    }
}
