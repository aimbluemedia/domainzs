<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * WhoisFreaks Domain Availability API — checks whether specific domains are
 * still registrable. Uses the same API key as the WhoisFreaks drop feed.
 *
 *   GET https://api.whoisfreaks.com/v1.0/domain/availability?apiKey=KEY&domainName=X
 *       -> {"domain_name":"x.com","domain_available":true} (shapes vary; we
 *          parse several). The param name has been documented as both
 *          "domainName" and "domain", so we try both.
 *
 * Used to verify the Daily Recap's handful of winners (top pick + top 10),
 * so the check is a few credits per recap.
 */
final class WhoisFreaksClient
{
    private const BASE = 'https://api.whoisfreaks.com/v1.0/domain/availability';

    /** Filled with the last raw request/response for the diagnostic tool. */
    public ?array $lastDebug = null;

    /** Once one param style works, reuse it for the rest of the batch. */
    private ?string $goodParam = null;

    public function __construct(private string $apiKey, private string $urlTemplate = '')
    {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * @param string[] $domains
     * @return array<string,string> domain => 'available' | 'registered' | 'unknown'
     */
    public function availability(array $domains): array
    {
        $out = [];
        if (!$this->isConfigured()) {
            return $out;
        }
        $domains = array_values(array_unique(array_map('strtolower', $domains)));

        // Try a single BULK request first (no per-request rate-limit cap).
        if (trim($this->urlTemplate) === '' && count($domains) > 1) {
            $bulk = $this->bulkAvailability($domains);
            if ($bulk !== null) {
                foreach ($domains as $d) {
                    $out[$d] = $bulk[$d] ?? 'unknown';
                }
                return $out;
            }
        }

        // Fallback: one request per domain (subject to the 10/min free limit).
        foreach ($domains as $domain) {
            $out[$domain] = $this->checkOne($domain);
        }
        return $out;
    }

    /**
     * Bulk availability — one POST for up to 100 domains, so a batch of winners
     * isn't throttled. Returns null if the endpoint/shape isn't recognised, so
     * the caller falls back to single lookups.
     *
     * @param string[] $domains
     * @return array<string,string>|null
     */
    public function bulkAvailability(array $domains): ?array
    {
        $url = self::BASE . '?apiKey=' . rawurlencode($this->apiKey);
        $payload = json_encode(['domainNames' => array_values($domains)]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->lastDebug = [
            'url'  => preg_replace('/apiKey=[^&]+/', 'apiKey=***', $url) . ' (bulk POST ' . count($domains) . ')',
            'http' => $code,
            'body' => is_string($body) ? mb_substr($body, 0, 400) : null,
        ];
        if ($code !== 200 || !is_string($body)) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        // Find the results list: a top-level array, or the first nested array.
        $list = isset($data[0]) && is_array($data[0]) ? $data : null;
        if ($list === null) {
            foreach ($data as $v) {
                if (is_array($v) && isset($v[0]) && is_array($v[0])) { $list = $v; break; }
            }
        }
        if ($list === null) {
            return null;
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $d = strtolower((string)($item['domain'] ?? $item['domainName'] ?? $item['domain_name'] ?? ''));
            if ($d === '') {
                continue;
            }
            $raw = $item['domainAvailability'] ?? $item['domain_available'] ?? $item['available'] ?? $item['availability'] ?? null;
            $out[$d] = $this->normalise($raw);
        }
        return $out ?: null;
    }

    /** Normalise a boolean/string availability value. */
    private function normalise(mixed $raw): string
    {
        if (is_bool($raw)) {
            return $raw ? 'available' : 'registered';
        }
        $s = strtolower(trim((string) $raw));
        if (in_array($s, ['available', 'true', '1', 'yes'], true)) {
            return 'available';
        }
        if (in_array($s, ['unavailable', 'registered', 'false', '0', 'no', 'taken'], true)) {
            return 'registered';
        }
        return 'unknown';
    }

    /** Check one domain, recording the last attempt for diagnostics. */
    public function checkOne(string $domain): string
    {
        $custom = trim($this->urlTemplate);
        if ($custom !== '') {
            $params = ['custom'];
        } elseif ($this->goodParam !== null) {
            $params = [$this->goodParam];            // reuse the style that worked
        } else {
            $params = ['domainName', 'domain'];      // first call tries both
        }

        foreach ($params as $p) {
            $url = $p === 'custom'
                ? strtr($custom, ['{apiKey}' => rawurlencode($this->apiKey), '{domain}' => rawurlencode($domain)])
                : self::BASE . '?apiKey=' . rawurlencode($this->apiKey) . '&' . $p . '=' . rawurlencode($domain);

            [$code, $body] = $this->get($url);
            $status = $this->parse($body);
            $this->lastDebug = [
                'url'    => preg_replace('/apiKey=[^&]+/', 'apiKey=***', $url),
                'http'   => $code,
                'body'   => is_string($body) ? mb_substr($body, 0, 400) : null,
                'status' => $status,
            ];
            if ($status !== 'unknown') {
                if ($p !== 'custom') {
                    $this->goodParam = $p;
                }
                return $status;
            }
        }
        return 'unknown';
    }

    /** @return array{0:int,1:?string} */
    private function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : null];
    }

    /** Tolerate the many availability field/shape variants WhoisFreaks uses. */
    private function parse(?string $body): string
    {
        if ($body === null) {
            return 'unknown';
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return 'unknown';
        }
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        $raw = $data['domain_available'] ?? $data['domainAvailability'] ?? $data['domain_availability']
            ?? $data['available'] ?? $data['availability'] ?? null;
        return $raw === null ? 'unknown' : $this->normalise($raw);
    }
}
