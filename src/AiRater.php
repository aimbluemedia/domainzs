<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Optional AI second opinion on the top-scored drops, via the Anthropic API.
 * With no API key configured it runs in MOCK mode: comments and value
 * estimates are derived from the heuristic score, so the UI is identical.
 */
final class AiRater
{
    public function __construct(private array $cfg)
    {
    }

    public function isMock(): bool
    {
        return trim((string)($this->cfg['api_key'] ?? '')) === '';
    }

    /**
     * @param array<int,array{id:int|string,sld:string,tld:string,score:int|string}> $rows
     * @return array<int,array{rating:int,comment:string,est_value:int}> keyed by drop id
     */
    public function rate(array $rows): array
    {
        if ($this->isMock()) {
            return $this->mockRate($rows);
        }
        $live = $this->liveRate($rows);
        // If the API call fails, fall back to heuristics so the pipeline never stalls.
        return $live ?? $this->mockRate($rows);
    }

    private function liveRate(array $rows): ?array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = ['id' => (int)$row['id'], 'domain' => $row['sld'] . '.' . $row['tld']];
        }

        $prompt = "You are a domain-name appraiser. For each domain below, rate its brandability"
            . " and resale potential.\n\nDomains (JSON):\n" . json_encode($list) . "\n\n"
            . "Reply with ONLY a JSON array, one object per domain:\n"
            . '[{"id": <id>, "rating": <0-99 integer>, "comment": "<one plain-English sentence'
            . ' a beginner understands>", "est_value": <estimated resale value in whole USD>}]';

        $payload = json_encode([
            'model'      => (string)$this->cfg['model'],
            'max_tokens' => 2000,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . (string)$this->cfg['api_key'],
                'anthropic-version: 2023-06-01',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            return null;
        }

        $data = json_decode($body, true);
        $text = (string)($data['content'][0]['text'] ?? '');
        // Tolerate the model wrapping the JSON in prose or a code fence.
        if (!preg_match('/\[.*\]/s', $text, $m)) {
            return null;
        }
        $items = json_decode($m[0], true);
        if (!is_array($items)) {
            return null;
        }

        $out = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id > 0) {
                $out[$id] = [
                    'rating'    => max(0, min(99, (int)($item['rating'] ?? 0))),
                    'comment'   => mb_substr(trim((string)($item['comment'] ?? '')), 0, 300),
                    'est_value' => max(0, (int)($item['est_value'] ?? 0)),
                ];
            }
        }
        return $out ?: null;
    }

    /** Heuristic stand-in: rating tracks the score, value scales with it. */
    private function mockRate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $score = (int)$row['score'];
            $name  = $row['sld'] . '.' . $row['tld'];
            $out[(int)$row['id']] = [
                'rating'    => max(3, min(99, $score + ((crc32($name) % 11) - 5))),
                'comment'   => $score >= 70
                    ? "Strong brandable — short, readable, and memorable enough to resell."
                    : ($score >= 50
                        ? "Decent name with some appeal; could work for a niche project."
                        : "Weak name — hard to say or remember, low resale odds."),
                'est_value' => $score >= 70 ? 40 * $score : ($score >= 50 ? 12 * $score : 2 * $score),
            ];
        }
        return $out;
    }
}
