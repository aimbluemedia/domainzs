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
        foreach (array_values(array_unique($domains)) as $domain) {
            $out[strtolower($domain)] = $this->checkOne($domain);
        }
        return $out;
    }

    /** Check one domain, recording the last attempt for diagnostics. */
    public function checkOne(string $domain): string
    {
        // A custom override template wins; otherwise try both param names.
        $custom = trim($this->urlTemplate);
        $urls = $custom !== ''
            ? [strtr($custom, ['{apiKey}' => rawurlencode($this->apiKey), '{domain}' => rawurlencode($domain)])]
            : [
                self::BASE . '?apiKey=' . rawurlencode($this->apiKey) . '&domainName=' . rawurlencode($domain),
                self::BASE . '?apiKey=' . rawurlencode($this->apiKey) . '&domain=' . rawurlencode($domain),
            ];

        foreach ($urls as $url) {
            [$code, $body] = $this->get($url);
            $status = $this->parse($body);
            // Record the last attempt (key masked) for the Test button.
            $this->lastDebug = [
                'url'    => preg_replace('/apiKey=[^&]+/', 'apiKey=***', $url),
                'http'   => $code,
                'body'   => is_string($body) ? mb_substr($body, 0, 400) : null,
                'status' => $status,
            ];
            if ($status !== 'unknown') {
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
            CURLOPT_TIMEOUT        => 20,
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
        if ($raw === null) {
            return 'unknown';
        }
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
}
