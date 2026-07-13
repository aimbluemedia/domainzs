<?php
declare(strict_types=1);

namespace Domainzs;

use PDO;

/**
 * The daily pipeline, runnable from cron (bin/fetch.php) or the superadmin
 * "Fetch now" button:
 *
 *   1. pull the raw dropped-domain list for a date (DropsClient)
 *   2. filter — keep only the configured TLDs and exact SLD length
 *      (default: 9-character .coms)
 *   3. score every survivor (Scorer) and store new ones
 *   4. verify availability of the top-scored drops via RDAP
 *   5. optional AI second opinion on the very best (AiRater)
 */
final class DropEngine
{
    public function __construct(
        private PDO $pdo,
        private array $config
    ) {
    }

    /**
     * @return array{raw:int, matched:int, added:int, verified:int, ai_rated:int}
     */
    public function run(?string $date = null): array
    {
        $date  = $date ?: date('Y-m-d');
        $drops = drops_config($this->config);

        // 1. Fetch.
        $client = new DropsClient($drops);
        $raw    = $client->fetch($date);

        // 2. Filter.
        $tlds     = array_filter(array_map('trim', explode(',', strtolower($drops['tlds']))));
        $exactLen = max(1, (int)$drops['exact_len']);
        $matched  = [];
        foreach ($raw as $domain) {
            [$sld, $tld] = split_domain($domain);
            if (!in_array($tld, $tlds, true) || strlen($sld) !== $exactLen) {
                continue;
            }
            if (!preg_match('/^[a-z0-9-]+$/', $sld)) {
                continue;
            }
            $matched[$domain] = [$sld, $tld];
            if (count($matched) >= (int)$drops['max_keep']) {
                break;
            }
        }

        // 3. Score + insert (new domains only — the unique key dedupes).
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drops (domain, sld, tld, len, dropped_date, score, score_notes, availability)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $added = 0;
        $mockAvailability = $client->isMock() ? 'available' : 'unknown';
        foreach ($matched as $domain => [$sld, $tld]) {
            $rated = Scorer::score($sld);
            $insert->execute([
                $domain, $sld, $tld, strlen($sld), $date,
                $rated['score'],
                json_encode($rated['notes'], JSON_UNESCAPED_SLASHES),
                $mockAvailability,
            ]);
            $added += $insert->rowCount();
        }

        // 4. RDAP-verify the day's top scorers (skip in mock — they're fake names).
        $verified = 0;
        if (!$client->isMock()) {
            $verified = $this->verifyTop($date, (int)($this->config['rdap']['verify_top'] ?? 25));
        }

        // 5. AI pass on the very best of the day.
        $aiRated = $this->aiRateTop($date);

        return [
            'raw'      => count($raw),
            'matched'  => count($matched),
            'added'    => $added,
            'verified' => $verified,
            'ai_rated' => $aiRated,
        ];
    }

    /** Re-check availability of the date's best unverified drops via RDAP. */
    private function verifyTop(string $date, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $rdap = new RdapClient($this->config['rdap'] ?? []);
        $stmt = $this->pdo->prepare(
            "SELECT id, domain FROM drops
             WHERE dropped_date = ? AND availability = 'unknown'
             ORDER BY score DESC LIMIT " . $limit
        );
        $stmt->execute([$date]);
        $update = $this->pdo->prepare('UPDATE drops SET availability = ? WHERE id = ?');
        $count  = 0;
        foreach ($stmt->fetchAll() as $row) {
            $info = $rdap->lookup($row['domain']);
            $availability = match ($info['status']) {
                'available'  => 'available',
                'registered', 'pending_delete' => 'registered',
                default      => 'unknown',
            };
            $update->execute([$availability, $row['id']]);
            $count++;
        }
        return $count;
    }

    /** AI-rate the date's top drops that don't have a rating yet. */
    private function aiRateTop(string $date): int
    {
        $ai    = ai_config($this->config);
        $limit = max(0, (int)$ai['max_per_fetch']);
        if ($limit === 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, sld, tld, score FROM drops
             WHERE dropped_date = ? AND ai_rating IS NULL
             ORDER BY score DESC LIMIT ' . $limit
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return 0;
        }

        $rater  = new AiRater($ai);
        $update = $this->pdo->prepare(
            'UPDATE drops SET ai_rating = ?, ai_comment = ?, est_value = ? WHERE id = ?'
        );
        $count = 0;
        foreach ($rater->rate($rows) as $id => $verdict) {
            $update->execute([
                $verdict['rating'],
                $verdict['comment'],
                $verdict['est_value'],
                $id,
            ]);
            $count++;
        }
        return $count;
    }
}
