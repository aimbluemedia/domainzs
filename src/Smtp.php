<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Minimal authenticated SMTP client — no dependencies. Sends a single HTML
 * message through a real mailbox (e.g. daily@domainzs.com on Hostinger), which
 * is far more deliverable than PHP's unauthenticated mail().
 *
 * Supports implicit SSL (port 465) and STARTTLS (port 587), AUTH LOGIN.
 * Every send returns [ok, transcript] so failures can be shown to the admin.
 */
final class Smtp
{
    public function __construct(private array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string)($this->cfg['host'] ?? '')) !== ''
            && trim((string)($this->cfg['user'] ?? '')) !== ''
            && trim((string)($this->cfg['pass'] ?? '')) !== '';
    }

    /**
     * @return array{0:bool,1:string} success flag and a human-readable transcript
     */
    public function sendHtml(string $to, string $subject, string $html): array
    {
        $host   = (string)$this->cfg['host'];
        $port   = (int)($this->cfg['port'] ?: 465);
        $secure = strtolower((string)($this->cfg['secure'] ?? 'ssl')); // ssl | tls | none
        $user   = (string)$this->cfg['user'];
        $pass   = (string)$this->cfg['pass'];
        $from    = (string)($this->cfg['from'] ?? $user);
        $fromNm  = (string)($this->cfg['from_name'] ?? 'domainzs');
        $timeout = (int)($this->cfg['timeout'] ?? 20);

        $log = [];
        $transport = ($secure === 'ssl') ? "ssl://{$host}" : $host;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp  = @stream_socket_client("{$transport}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return [false, "connect failed: {$errstr} ({$errno})"];
        }
        stream_set_timeout($fp, $timeout);

        // Read a (possibly multi-line) reply; returns the 3-digit code.
        $read = function () use ($fp, &$log): int {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                // Last line of a reply has a space (not '-') after the code.
                if (strlen($line) >= 4 && $line[3] === ' ') {
                    break;
                }
            }
            $log[] = '< ' . trim($data);
            return (int) substr(ltrim($data), 0, 3);
        };
        $write = function (string $cmd, bool $secret = false) use ($fp, &$log): void {
            $log[] = '> ' . ($secret ? '[hidden]' : $cmd);
            fwrite($fp, $cmd . "\r\n");
        };

        $fail = function (string $why) use ($fp, &$log): array {
            @fclose($fp);
            return [false, $why . "\n" . implode("\n", $log)];
        };

        $ehlo = 'EHLO ' . (parse_url('http://' . ($this->cfg['ehlo'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost');

        if ($read() !== 220) { return $fail('no 220 greeting'); }
        $write($ehlo);
        if ($read() !== 250) { return $fail('EHLO rejected'); }

        if ($secure === 'tls') {
            $write('STARTTLS');
            if ($read() !== 220) { return $fail('STARTTLS rejected'); }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                return $fail('TLS negotiation failed');
            }
            $write($ehlo);
            if ($read() !== 250) { return $fail('EHLO (post-TLS) rejected'); }
        }

        $write('AUTH LOGIN');
        if ($read() !== 334) { return $fail('AUTH LOGIN not accepted'); }
        $write(base64_encode($user), true);
        if ($read() !== 334) { return $fail('username rejected'); }
        $write(base64_encode($pass), true);
        if ($read() !== 235) { return $fail('authentication failed — check the mailbox password'); }

        $write('MAIL FROM:<' . $from . '>');
        if ($read() !== 250) { return $fail('MAIL FROM rejected'); }
        $write('RCPT TO:<' . $to . '>');
        $code = $read();
        if ($code !== 250 && $code !== 251) { return $fail('RCPT TO rejected (bad recipient?)'); }

        $write('DATA');
        if ($read() !== 354) { return $fail('DATA not accepted'); }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeName($fromNm) . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Reply-To: ' . $from,
            'Subject: ' . $this->encodeName($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: domainzs',
        ];
        // Dot-stuff any line beginning with '.' per RFC 5321.
        $bodyOut = preg_replace('/^\./m', '..', $html) ?? $html;
        $write(implode("\r\n", $headers) . "\r\n\r\n" . $bodyOut . "\r\n.");
        if ($read() !== 250) { return $fail('message not accepted after DATA'); }

        $write('QUIT');
        $read();
        @fclose($fp);
        return [true, implode("\n", $log)];
    }

    /** RFC 2047 encode a header value if it has non-ASCII bytes. */
    private function encodeName(string $s): string
    {
        if (preg_match('/[^\x20-\x7e]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }
}
