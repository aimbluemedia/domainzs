<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Email notifications. Uses PHP's mail() — on shared hosting that just works;
 * elsewhere point your MTA/relay at it. Disabled until mail is enabled and a
 * "to" address is set (config.php or /superadmin/settings.php).
 */
final class Notifier
{
    private array $mail;

    public function __construct(array $config)
    {
        $this->mail = mail_config($config);
    }

    public function enabled(): bool
    {
        return !empty($this->mail['enabled']) && !empty($this->mail['to']);
    }

    /** Digest after a fetch: how many drops matched and the day's best names. */
    public function sendFetchDigest(string $date, array $stats, array $topDrops): bool
    {
        if (!$this->enabled() || $stats['added'] === 0) {
            return false;
        }
        $lines = [
            "Drop report for {$date}:",
            '',
            " • {$stats['raw']} domains in the feed",
            " • {$stats['matched']} matched your filter",
            " • {$stats['added']} new drops added",
            '',
            'Top of the board:',
        ];
        foreach ($topDrops as $drop) {
            $lines[] = sprintf(' • %s — score %d', $drop['domain'], (int)$drop['score']);
        }
        return $this->send("[domainzs] {$stats['added']} new drops for {$date}", implode("\n", $lines));
    }

    /** New offer on a marketplace listing. */
    public function sendOfferAlert(string $domain, string $name, string $email, ?int $amountCents, string $message): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $body = "New offer on {$domain}:\n\n"
            . "From:   {$name} <{$email}>\n"
            . 'Offer:  ' . ($amountCents !== null ? money_cents($amountCents) : '(none given)') . "\n\n"
            . ($message !== '' ? "Message:\n{$message}\n" : '');
        return $this->send("[domainzs] Offer on {$domain}", $body);
    }

    private function send(string $subject, string $body): bool
    {
        $headers = 'From: ' . $this->mail['from'] . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";
        return @mail($this->mail['to'], $subject, $body, $headers);
    }
}
