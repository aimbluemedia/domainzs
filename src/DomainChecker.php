<?php
declare(strict_types=1);

namespace Domainzs;

use PDO;

/**
 * The scan engine: refreshes RDAP data for tracked domains, records check
 * history, and raises alerts on the events that matter:
 *
 *   - a WATCHLIST domain becomes available (or enters pending delete)
 *   - a PORTFOLIO domain approaches expiry (30 / 7 / 1 day reminders)
 *
 * Each event alerts exactly once — the alerts table's unique key does the
 * deduplication, so cron can run as often as you like.
 */
final class DomainChecker
{
    public function __construct(
        private PDO $pdo,
        private RdapClient $rdap,
        private array $config
    ) {
    }

    /**
     * Check every domain whose data is stale — or all of them with $force.
     *
     * @return array{checked:int, alerts:array<int,array<string,string>>}
     */
    public function run(bool $force = false): array
    {
        $recheckHours = max(1, (int)($this->config['rdap']['recheck_hours'] ?? 12));
        $sql = 'SELECT * FROM domains';
        if (!$force) {
            $sql .= ' WHERE last_checked_at IS NULL OR last_checked_at < DATE_SUB(NOW(), INTERVAL '
                . $recheckHours . ' HOUR)';
        }
        $domains = $this->pdo->query($sql . ' ORDER BY id')->fetchAll();

        $alerts = [];
        foreach ($domains as $row) {
            $alerts = array_merge($alerts, $this->checkOne($row));
        }

        if ($alerts) {
            (new Notifier($this->config))->sendDigest($alerts);
        }

        return ['checked' => count($domains), 'alerts' => $alerts];
    }

    /** Refresh a single domain row and return any alerts it raised. */
    public function checkOne(array $row): array
    {
        $info = $this->rdap->lookup($row['domain']);

        // On 'unknown' (lookup failed) keep the data we already had — only the
        // check timestamp moves, so stale-but-real data isn't wiped by an outage.
        if ($info['status'] === 'unknown' && $row['status'] !== 'unknown') {
            $this->pdo->prepare('UPDATE domains SET last_checked_at = NOW() WHERE id = ?')
                ->execute([$row['id']]);
            return [];
        }

        $this->pdo->prepare(
            'UPDATE domains SET status = ?, registrar = ?, registered_at = ?, expires_at = ?,
                    rdap_status = ?, last_checked_at = NOW() WHERE id = ?'
        )->execute([
            $info['status'],
            $info['registrar']     ?? $row['registrar'],
            $info['registered_at'] ?? $row['registered_at'],
            $info['expires_at']    ?? $row['expires_at'],
            $info['rdap_status'],
            $row['id'],
        ]);

        $this->pdo->prepare(
            'INSERT INTO domain_checks (domain_id, status, expires_at) VALUES (?, ?, ?)'
        )->execute([$row['id'], $info['status'], $info['expires_at']]);

        $fresh = array_merge($row, $info);
        return $this->collectAlerts($row, $fresh);
    }

    /** Compare old vs new state and record the alerts that should fire. */
    private function collectAlerts(array $old, array $new): array
    {
        $alerts = [];

        if ($new['kind'] === 'watchlist') {
            if ($new['status'] === 'available' && $old['status'] !== 'available') {
                $alerts[] = $this->raise($new, 'available', date('Y-m-d'),
                    "{$new['domain']} is AVAILABLE — register it now!");
            }
            if ($new['status'] === 'pending_delete' && $old['status'] !== 'pending_delete') {
                $alerts[] = $this->raise($new, 'pending_delete', date('Y-m-d'),
                    "{$new['domain']} is pending delete — it may drop soon.");
            }
        }

        if ($new['kind'] === 'portfolio' && !empty($new['expires_at'])) {
            $days = days_until($new['expires_at']);
            if ($days !== null && $days >= 0) {
                // Only the tightest matching threshold fires (a domain 5 days
                // out gets the 7-day reminder, not the 30-day one as well).
                $thresholds = array_map('intval', (array)($this->config['alerts']['expiry_days'] ?? [30, 7, 1]));
                sort($thresholds);
                foreach ($thresholds as $threshold) {
                    if ($days <= $threshold) {
                        $alerts[] = $this->raise(
                            $new,
                            'expiry_' . $threshold,
                            substr((string)$new['expires_at'], 0, 10),
                            "{$new['domain']} expires in {$days} day(s)"
                            . (empty($new['auto_renew']) ? ' and auto-renew is OFF — renew it!' : ' (auto-renew is on).')
                        );
                        break;
                    }
                }
            }
        }

        return array_values(array_filter($alerts));
    }

    /**
     * Insert the alert if this exact event hasn't fired before.
     * Returns the alert payload when it's new, null when it's a duplicate.
     */
    private function raise(array $domain, string $kind, string $ref, string $message): ?array
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO alerts (domain_id, kind, ref) VALUES (?, ?, ?)'
        );
        $stmt->execute([$domain['id'], $kind, $ref]);
        if ($stmt->rowCount() === 0) {
            return null; // already alerted for this event
        }
        return ['domain' => $domain['domain'], 'kind' => $kind, 'message' => $message];
    }
}
