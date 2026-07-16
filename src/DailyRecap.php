<?php
declare(strict_types=1);

namespace Domainzs;

use PDO;

/**
 * Generates the "Daily Recap" — a deep-dive on a day's dropped names:
 * a headline pick with resale range, a ranked top 10, an overlooked
 * "sleeper", a build-a-business pick with product ideas, and a verdict.
 *
 * Uses Claude when an Anthropic key is configured; otherwise a heuristic
 * mock builds a full recap from the scores so the page always works.
 * One recap per date, stored in daily_recaps (regenerate to overwrite).
 */
final class DailyRecap
{
    public function __construct(
        private PDO $pdo,
        private array $config
    ) {
        $this->ensureTable();
    }

    /** Self-heal: create daily_recaps if the migration hasn't been run yet. */
    private function ensureTable(): void
    {
        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS daily_recaps (
                    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    recap_date DATE         NOT NULL,
                    body       MEDIUMTEXT   NOT NULL,
                    drop_count INT UNSIGNED NOT NULL DEFAULT 0,
                    is_ai      TINYINT(1)   NOT NULL DEFAULT 0,
                    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_recap_date (recap_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } catch (\Throwable $e) {
            // If we lack CREATE rights the queries below will surface a clear error.
        }
    }

    /**
     * Return the stored recap for a date, generating it if missing (or $force).
     * @return array{body:array,is_ai:bool,drop_count:int}|null null if no drops
     */
    public function forDate(string $date, bool $force = false): ?array
    {
        if (!$force) {
            $stmt = $this->pdo->prepare('SELECT body, is_ai, drop_count FROM daily_recaps WHERE recap_date = ?');
            $stmt->execute([$date]);
            if ($row = $stmt->fetch()) {
                return [
                    'body'       => json_decode((string)$row['body'], true) ?: [],
                    'is_ai'      => (bool)$row['is_ai'],
                    'drop_count' => (int)$row['drop_count'],
                ];
            }
        }
        return $this->generate($date);
    }

    /**
     * Build (and store) the recap for a date from its top-scored drops.
     * @return array{body:array,is_ai:bool,drop_count:int}|null
     */
    public function generate(string $date, int $topN = 40): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT domain, sld, len, score, score_notes, availability, ai_comment, est_value, moz_da, moz_links
             FROM drops WHERE dropped_date = ? ORDER BY score DESC, len ASC LIMIT ' . max(1, $topN)
        );
        $stmt->execute([$date]);
        $drops = $stmt->fetchAll();
        if (!$drops) {
            return null;
        }

        $ai   = ai_config($this->config);
        $body = null;
        $isAi = false;
        if (trim((string)($ai['api_key'] ?? '')) !== '') {
            $body = $this->aiRecap($drops, $ai);
            $isAi = $body !== null;
        }
        if ($body === null) {
            $body = $this->mockRecap($drops);
        }

        // Live availability for the winners (top pick + top 10) via WhoisFreaks.
        $body['availability'] = $this->checkWinners($body);

        $this->pdo->prepare(
            'INSERT INTO daily_recaps (recap_date, body, drop_count, is_ai) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), drop_count = VALUES(drop_count),
                                     is_ai = VALUES(is_ai), created_at = CURRENT_TIMESTAMP'
        )->execute([$date, json_encode($body, JSON_UNESCAPED_SLASHES), count($drops), $isAi ? 1 : 0]);

        return ['body' => $body, 'is_ai' => $isAi, 'drop_count' => count($drops)];
    }

    /**
     * Live availability check for the recap's winners (top pick + top 10)
     * via the WhoisFreaks Domain Availability API. Falls back to the
     * availability already stored on the drops row when WhoisFreaks isn't
     * configured or a name isn't returned.
     *
     * @return array<string,string> domain => available|registered|unknown
     */
    private function checkWinners(array $body): array
    {
        $domains = [];
        if (!empty($body['top_pick']['domain'])) {
            $domains[] = (string) $body['top_pick']['domain'];
        }
        foreach ((array)($body['top10'] ?? []) as $t) {
            if (!empty($t['domain'])) {
                $domains[] = (string) $t['domain'];
            }
        }
        foreach (['sleeper', 'builder_pick'] as $k) {
            if (!empty($body[$k]['domain'])) {
                $domains[] = (string) $body[$k]['domain'];
            }
        }
        $domains = array_values(array_unique(array_map('strtolower', $domains)));
        if (!$domains) {
            return [];
        }

        $result = [];
        $key = (string) setting('whoisfreaks_api_key', (string)($this->config['drops']['whoisfreaks_api_key'] ?? ''));
        $wf  = new WhoisFreaksClient($key, (string) setting('whoisfreaks_avail_url', ''));
        if ($wf->isConfigured()) {
            $result = $wf->availability($domains);
        }

        // Fill any gaps from what the fetch already stored on the drops row.
        $missing = array_values(array_diff($domains, array_keys(array_filter($result, fn ($v) => $v !== 'unknown'))));
        if ($missing) {
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $stmt = $this->pdo->prepare("SELECT domain, availability FROM drops WHERE domain IN ($ph) AND availability <> 'unknown'");
            $stmt->execute($missing);
            foreach ($stmt->fetchAll() as $row) {
                $d = strtolower((string)$row['domain']);
                if (($result[$d] ?? 'unknown') === 'unknown') {
                    $result[$d] = (string) $row['availability'];
                }
            }
        }
        return $result;
    }

    /** Ask Claude for the structured recap. Returns null on any failure. */
    private function aiRecap(array $drops, array $ai): ?array
    {
        $list = [];
        foreach ($drops as $d) {
            $list[] = [
                'domain'       => $d['domain'],
                'length'       => (int)$d['len'],
                'score'        => (int)$d['score'],
                'availability' => $d['availability'],
            ];
        }
        $context = trim((string) setting('recap_context', ''));

        $prompt = "You are a professional domain-name investor and startup advisor writing a punchy "
            . "\"Daily Recap\" for a portfolio of freshly dropped domains. Analyse ONLY the domains below "
            . "(JSON): choose the strongest names, weighing brandability, length, pronounceability, and "
            . "end-user resale potential.\n\nDomains:\n" . json_encode($list) . "\n\n"
            . ($context !== '' ? "About the owner (weave into the build-a-business pick): {$context}\n\n" : '')
            . "Reply with ONLY a JSON object, no prose, in exactly this shape:\n"
            . '{'
            . '"intro":"1-2 sentence framing of today\'s batch",'
            . '"top_pick":{"domain":"","stars":5,"why":["3-5 short bullets"],"positioning":["3-6 use cases"],"resale_wholesale":"$500–2,000","resale_enduser":"$5,000–25,000+"},'
            . '"top10":[{"domain":"","stars":5,"note":"one short reason"}],'
            . '"sleeper":{"domain":"","why":"2-3 sentences on overlooked upside"},'
            . '"builder_pick":{"domain":"","why":["bullets"],"business_ideas":["4-8 concrete product ideas"]},'
            . '"verdict":"final paragraph: pure-investing pick vs build-a-business pick"'
            . '}. Use only domains from the list. top10 = up to 10, best first, stars are 1-5 integers.';

        $payload = json_encode([
            'model'      => (string)$ai['model'],
            'max_tokens' => 3000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . (string)$ai['api_key'],
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($res)) {
            return null;
        }
        $data = json_decode($res, true);
        $text = (string)($data['content'][0]['text'] ?? '');
        if (!preg_match('/\{.*\}/s', $text, $m)) {
            return null;
        }
        $obj = json_decode($m[0], true);
        return is_array($obj) && isset($obj['top_pick']) ? $obj : null;
    }

    /** Heuristic recap built from the scores, so the page works with no API key. */
    private function mockRecap(array $drops): array
    {
        $top = $drops[0];
        $short = null;
        foreach ($drops as $d) {
            if ((int)$d['len'] <= 6) { $short = $d; break; }
        }
        $sleeper = $short ?? ($drops[1] ?? $top);

        // Build-a-business pick: the best name that reads as a real brand word.
        $builder = $top;
        foreach ($drops as $d) {
            $notes = json_decode((string)$d['score_notes'], true) ?: [];
            foreach ($notes as $n) {
                if (str_contains($n, 'real word')) { $builder = $d; break 2; }
            }
        }

        $stars = fn (int $score): int => max(1, min(5, (int)round($score / 20)));
        $band  = function (int $score): array {
            if ($score >= 80) return ['$800–2,500', '$8,000–30,000+'];
            if ($score >= 65) return ['$300–1,200', '$3,000–12,000'];
            return ['$100–500', '$1,000–4,000'];
        };
        [$wLow, $wHigh] = $band((int)$top['score']);

        $topPickNotes = json_decode((string)$top['score_notes'], true) ?: [];

        $top10 = [];
        foreach (array_slice($drops, 0, 10) as $d) {
            $notes = json_decode((string)$d['score_notes'], true) ?: [];
            $top10[] = [
                'domain' => $d['domain'],
                'stars'  => $stars((int)$d['score']),
                'note'   => $notes[0] ?? ('score ' . (int)$d['score']),
            ];
        }

        return [
            'intro' => 'Heuristic recap of the day\'s ' . count($drops) . ' best names (add an Anthropic API key in Settings for a full AI deep-dive). Ranked by the brandability score.',
            'top_pick' => [
                'domain' => $top['domain'],
                'stars'  => $stars((int)$top['score']),
                'why'    => array_slice(array_merge(
                    ['Highest-scoring name in today\'s batch (' . (int)$top['score'] . '/99).'],
                    $topPickNotes
                ), 0, 5),
                'positioning' => ['Brandable startup', 'SaaS / app', 'Agency or product site', 'Content or media brand'],
                'resale_wholesale' => $wLow,
                'resale_enduser'   => $wHigh,
            ],
            'top10'   => $top10,
            'sleeper' => [
                'domain' => $sleeper['domain'],
                'why'    => 'Only ' . (int)$sleeper['len'] . ' characters — short names are rare and hold value even when overlooked, and this one still scored ' . (int)$sleeper['score'] . '/99.',
            ],
            'builder_pick' => [
                'domain' => $builder['domain'],
                'why'    => ['Reads as a real brand you could launch on', 'Short enough to be memorable', 'Scored ' . (int)$builder['score'] . '/99 for brandability'],
                'business_ideas' => ['SaaS product', 'Newsletter / media brand', 'Directory or marketplace', 'AI tool', 'Agency landing page', 'Affiliate content site'],
            ],
            'verdict' => 'For pure resale, ' . $top['domain'] . ' is the strongest standalone brand today. To build on, ' . $builder['domain'] . ' gives you a memorable name to launch a real product. Add an AI key for a sharper, personalised deep-dive.',
        ];
    }
}
