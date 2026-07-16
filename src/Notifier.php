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

    public function __construct(private array $config)
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

    /**
     * The morning Daily Recap digest as an HTML email.
     * @param array $body the recap payload (top_pick, top10, sleeper, …)
     */
    public function sendRecapDigest(string $date, array $body): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $base = rtrim((string)($this->config['app']['base_url'] ?? ''), '/');
        $pick = (string)($body['top_pick']['domain'] ?? '');
        $subject = "[domainzs] Daily Recap — {$date}" . ($pick !== '' ? " · top pick {$pick}" : '');
        return $this->sendHtml($subject, $this->recapHtml($date, $body, $base));
    }

    private function recapHtml(string $date, array $b, string $base): string
    {
        $esc = fn ($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stars = fn (int $n) => str_repeat('★', max(0, min(5, $n))) . str_repeat('☆', 5 - max(0, min(5, $n)));

        $h  = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;color:#1a1a1a">';
        $h .= '<h1 style="font-size:20px;margin:0 0 4px">📊 domainzs Daily Recap</h1>';
        $h .= '<p style="color:#666;margin:0 0 20px">' . $esc($date) . '</p>';
        if (!empty($b['intro'])) {
            $h .= '<p style="font-size:15px;line-height:1.5">' . $esc($b['intro']) . '</p>';
        }

        if (!empty($b['top_pick']['domain'])) {
            $p = $b['top_pick'];
            $h .= '<div style="border:2px solid #4da3ff;border-radius:12px;padding:16px 18px;margin:16px 0">';
            $h .= '<div style="font-size:13px;color:#888">🥇 TOP PICK</div>';
            $h .= '<div style="font-size:24px;font-weight:800;margin:2px 0">' . $esc($p['domain']) . '</div>';
            $h .= '<div style="color:#e6a700;font-size:16px;letter-spacing:2px">' . $stars((int)($p['stars'] ?? 0)) . '</div>';
            if (!empty($p['why'])) {
                $h .= '<ul style="padding-left:18px;margin:10px 0">';
                foreach ((array)$p['why'] as $w) { $h .= '<li style="margin:3px 0">' . $esc($w) . '</li>'; }
                $h .= '</ul>';
            }
            $h .= '<table style="width:100%;margin-top:8px"><tr>'
                . '<td style="color:#888;font-size:12px">WHOLESALE<br><b style="font-size:16px;color:#1a1a1a">' . $esc($p['resale_wholesale'] ?? '—') . '</b></td>'
                . '<td style="color:#888;font-size:12px">END USER<br><b style="font-size:16px;color:#1db954">' . $esc($p['resale_enduser'] ?? '—') . '</b></td>'
                . '</tr></table></div>';
        }

        if (!empty($b['top10'])) {
            $h .= '<h2 style="font-size:16px;margin:22px 0 8px">🏆 Top 10</h2>';
            $h .= '<table style="width:100%;border-collapse:collapse">';
            foreach ((array)$b['top10'] as $i => $t) {
                $h .= '<tr style="border-bottom:1px solid #eee">'
                    . '<td style="padding:8px 6px;color:#4da3ff;font-weight:800;width:24px">' . ($i + 1) . '</td>'
                    . '<td style="padding:8px 6px"><b style="font-size:15px">' . $esc($t['domain'] ?? '') . '</b>'
                    . '<span style="color:#e6a700;margin-left:8px">' . $stars((int)($t['stars'] ?? 0)) . '</span>'
                    . (!empty($t['note']) ? '<br><span style="color:#666;font-size:13px">' . $esc($t['note']) . '</span>' : '')
                    . '</td></tr>';
            }
            $h .= '</table>';
        }

        if (!empty($b['sleeper']['domain'])) {
            $h .= '<h2 style="font-size:16px;margin:22px 0 6px">💤 The sleeper</h2>';
            $h .= '<div style="font-size:18px;font-weight:800">' . $esc($b['sleeper']['domain']) . '</div>';
            $h .= '<p style="color:#555;margin:6px 0">' . $esc($b['sleeper']['why'] ?? '') . '</p>';
        }

        if (!empty($b['builder_pick']['domain'])) {
            $bp = $b['builder_pick'];
            $h .= '<h2 style="font-size:16px;margin:22px 0 6px">🚀 Best to build on</h2>';
            $h .= '<div style="font-size:18px;font-weight:800">' . $esc($bp['domain']) . '</div>';
            if (!empty($bp['business_ideas'])) {
                $h .= '<p style="color:#555;margin:6px 0">Ideas: ' . $esc(implode(' · ', (array)$bp['business_ideas'])) . '</p>';
            }
        }

        if (!empty($b['verdict'])) {
            $h .= '<h2 style="font-size:16px;margin:22px 0 6px">✅ Verdict</h2>';
            $h .= '<p style="font-size:15px;line-height:1.6">' . $esc($b['verdict']) . '</p>';
        }

        if ($base !== '') {
            $h .= '<p style="margin:24px 0 0"><a href="' . $esc($base . '/superadmin/dailyrecap.php') . '" '
                . 'style="background:#1db954;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:700">Open the full recap →</a></p>';
        }
        $h .= '<p style="color:#999;font-size:12px;margin-top:24px">domainzs — rated dropped domains, daily.</p>';
        return $h . '</div>';
    }

    private function send(string $subject, string $body): bool
    {
        $headers = 'From: ' . $this->mail['from'] . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";
        return @mail($this->mail['to'], $subject, $body, $headers);
    }

    private function sendHtml(string $subject, string $html): bool
    {
        $headers = 'From: ' . $this->mail['from'] . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n";
        return @mail($this->mail['to'], $subject, $html, $headers);
    }
}
