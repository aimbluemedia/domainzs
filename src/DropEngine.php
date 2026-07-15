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
        // Feeds + API passes can legitimately take a while; don't let the
        // host's default execution limit kill the pipeline mid-write.
        @set_time_limit(300);

        $drops = drops_config($this->config);
        // No explicit date (cron): fetch the most recent *published* list —
        // most feeds publish a completed day the next morning (day_offset 1).
        $date = $date ?: date('Y-m-d', time() - max(0, (int)$drops['day_offset']) * 86400);

        // 1. Fetch.
        $client = new DropsClient($drops);
        $raw    = $client->fetch($date);
        $error  = $client->lastError();

        // 2. Filter.
        $tlds      = array_filter(array_map('trim', explode(',', strtolower($drops['tlds']))));
        $exactLen  = max(1, (int)$drops['exact_len']);
        $noHyphens = !empty($drops['no_hyphens']);
        $noDigits  = !empty($drops['no_digits']);
        $matched   = [];
        foreach ($raw as $domain) {
            [$sld, $tld] = split_domain($domain);
            if (!in_array($tld, $tlds, true) || strlen($sld) !== $exactLen) {
                continue;
            }
            if (!preg_match('/^[a-z0-9-]+$/', $sld)) {
                continue;
            }
            if ($noHyphens && str_contains($sld, '-')) {
                continue;
            }
            if ($noDigits && preg_match('/[0-9]/', $sld)) {
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

        // 5. Moz metrics (Domain Authority + linking domains) on the day's best.
        $mozRated = $this->mozRateTop($date);

        // 6. AI pass on the very best of the day.
        $aiRated = $this->aiRateTop($date);

        return [
            'date'     => $date,
            'raw'      => count($raw),
            'matched'  => count($matched),
            'added'    => $added,
            'verified'  => $verified,
            'moz_rated' => $mozRated,
            'ai_rated'  => $aiRated,
            'error'     => $error,
        ];
    }

    /**
     * Re-check availability of the date's best unverified drops.
     * Prefers the name.com API (bulk, with real registration prices);
     * falls back to per-domain RDAP lookups when name.com isn't configured.
     */
    private function verifyTop(string $date, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, domain FROM drops
             WHERE dropped_date = ? AND availability = 'unknown'
             ORDER BY score DESC LIMIT " . $limit
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return 0;
        }

        $namecom = new NameComClient(namecom_config($this->config));

        // In a web request (the admin "Fetch now" button), only the fast bulk
        // name.com check is allowed. Per-domain RDAP lookups can take minutes
        // and freeze the page on shared hosting — that path is cron-only.
        if (!$namecom->isConfigured() && PHP_SAPI !== 'cli') {
            return 0;
        }

        if ($namecom->isConfigured()) {
            $results = $namecom->checkAvailability(array_column($rows, 'domain'));
            $update  = $this->pdo->prepare(
                'UPDATE drops SET availability = ?, reg_price = ? WHERE id = ?'
            );
            $count = 0;
            foreach ($rows as $row) {
                $result = $results[strtolower($row['domain'])] ?? null;
                if ($result === null) {
                    continue; // API error / TLD not supported — stays unknown
                }
                $update->execute([
                    $result['purchasable'] ? 'available' : 'registered',
                    $result['price'],
                    $row['id'],
                ]);
                $count++;
            }
            return $count;
        }

        $rdap   = new RdapClient($this->config['rdap'] ?? []);
        $update = $this->pdo->prepare('UPDATE drops SET availability = ? WHERE id = ?');
        $count  = 0;
        foreach ($rows as $row) {
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

    /** Pull Moz DA / PA / linking domains for the date's top unrated drops. */
    private function mozRateTop(string $date): int
    {
        $moz = new MozClient(moz_config($this->config));
        if (!$moz->isConfigured()) {
            return 0;
        }
        $limit = max(0, (int)(setting('moz_max_per_fetch', '25') ?? 25));
        if ($limit === 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, domain FROM drops
             WHERE dropped_date = ? AND moz_da IS NULL
             ORDER BY score DESC LIMIT ' . $limit
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return 0;
        }

        $metrics = $moz->urlMetrics(array_column($rows, 'domain'));
        $update  = $this->pdo->prepare(
            'UPDATE drops SET moz_da = ?, moz_pa = ?, moz_links = ? WHERE id = ?'
        );
        $count = 0;
        foreach ($rows as $row) {
            $m = $metrics[strtolower($row['domain'])] ?? null;
            if ($m === null) {
                continue; // API error — stays unrated, retried next fetch
            }
            $update->execute([$m['da'], $m['pa'], $m['links'], $row['id']]);
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
