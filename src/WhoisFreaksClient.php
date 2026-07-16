<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * WhoisFreaks Domain Availability API — checks whether specific domains are
 * still registrable. Uses the same API key as the WhoisFreaks drop feed.
 *
 *   GET https://api.whoisfreaks.com/v1.0/domain/availability?apiKey=KEY&domain=X
 *       -> {"domain":"x.com","domainAvailability": true|false}
 *
 * Used to verify the Daily Recap's handful of winners (top pick + top 10),
 * so the check is a few credits per recap.
 */
final class WhoisFreaksClient
{
    private const DEFAULT_URL =
        'https://api.whoisfreaks.com/v1.0/domain/availability?apiKey={apiKey}&domain={domain}';

    public function __construct(private string $apiKey, private string $urlTemplate = '')
    {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    /**
     * Check availability of a small set of domains.
     * @param string[] $domains
     * @return array<string,string> domain => 'available' | 'registered' | 'unknown'
     */
    public function availability(array $domains): array
    {
        $out = [];
        if (!$this->isConfigured()) {
            return $out;
        }
        $template = trim($this->urlTemplate) !== '' ? $this->urlTemplate : self::DEFAULT_URL;

        foreach (array_values(array_unique($domains)) as $domain) {
            $url = strtr($template, [
                '{apiKey}' => rawurlencode($this->apiKey),
                '{domain}' => rawurlencode($domain),
            ]);
            $out[strtolower($domain)] = $this->parse($this->get($url));
        }
        return $out;
    }

    private function get(string $url): ?array
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
        if ($code !== 200 || !is_string($body)) {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** Tolerate boolean or string availability shapes. */
    private function parse(?array $data): string
    {
        if ($data === null) {
            return 'unknown';
        }
        // Bulk endpoints may wrap results in an array.
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        $raw = $data['domainAvailability'] ?? $data['domain_availability'] ?? $data['available'] ?? null;
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
