<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Moz Links API (v2) — Domain Authority, Page Authority, and linking root
 * domains for the top-scored drops. An expired domain with real DA/backlinks
 * is worth far more than its name alone.
 *
 * Get free credentials at https://moz.com/products/api (free tier includes a
 * monthly row quota — the per-fetch cap in Settings keeps usage inside it).
 * Auth is HTTP Basic with your Access ID + Secret Key.
 */
final class MozClient
{
    public const MAX_PER_CALL = 50;

    public function __construct(private array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string)($this->cfg['access_id'] ?? '')) !== ''
            && trim((string)($this->cfg['secret_key'] ?? '')) !== '';
    }

    /**
     * Bulk URL metrics.
     *
     * @param string[] $domains
     * @return array<string,array{da:int,pa:int,links:int}> keyed by domain;
     *         domains missing from the response are absent.
     */
    public function urlMetrics(array $domains): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($domains)), self::MAX_PER_CALL) as $chunk) {
            $response = $this->post(['targets' => $chunk]);
            foreach ((array)($response['results'] ?? []) as $i => $result) {
                if (!is_array($result)) {
                    continue;
                }
                // Results come back in target order; map by index.
                $domain = $chunk[$i] ?? null;
                if ($domain === null) {
                    continue;
                }
                $out[strtolower($domain)] = [
                    'da'    => max(0, min(100, (int)($result['domain_authority'] ?? 0))),
                    'pa'    => max(0, min(100, (int)($result['page_authority'] ?? 0))),
                    'links' => max(0, (int)($result['root_domains_to_root_domain'] ?? 0)),
                ];
            }
        }
        return $out;
    }

    /** @return array<mixed> decoded JSON, or [] on any failure */
    private function post(array $payload): array
    {
        $endpoint = trim((string)($this->cfg['endpoint'] ?? '')) ?: 'https://lsapi.seomoz.com/v2/url_metrics';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERPWD        => $this->cfg['access_id'] . ':' . $this->cfg['secret_key'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code !== 200 || !is_string($body)) {
            return [];
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
