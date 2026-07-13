<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Email notifications. Uses PHP's mail() — on shared hosting that just works;
 * elsewhere point your MTA/relay at it. Disabled until mail.enabled is true
 * and mail.to is set in config.php.
 */
final class Notifier
{
    public function __construct(private array $config)
    {
    }

    public function enabled(): bool
    {
        $mail = $this->config['mail'] ?? [];
        return !empty($mail['enabled']) && !empty($mail['to']);
    }

    /**
     * One digest email for a batch of alerts.
     * @param array<int,array{domain:string,kind:string,message:string}> $alerts
     */
    public function sendDigest(array $alerts): bool
    {
        if (!$this->enabled() || !$alerts) {
            return false;
        }

        $count   = count($alerts);
        $subject = "[domainzs] {$count} domain alert" . ($count === 1 ? '' : 's');

        $lines = ["Your domain watcher found {$count} thing(s) that need attention:", ''];
        foreach ($alerts as $alert) {
            $lines[] = ' • ' . $alert['message'];
        }
        $lines[] = '';
        $base = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
        $lines[] = 'Open the dashboard: ' . ($base !== '' ? $base . '/' : '(set app.base_url in config.php for a link)');

        return $this->send($subject, implode("\n", $lines));
    }

    private function send(string $subject, string $body): bool
    {
        $mail    = $this->config['mail'];
        $headers = 'From: ' . $mail['from'] . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";
        return @mail($mail['to'], $subject, $body, $headers);
    }
}
