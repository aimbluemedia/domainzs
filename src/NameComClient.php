<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * name.com Core API (v4) client.
 *
 * Used to bulk-verify availability of the day's top drops and pull the real
 * registration price for each — one API call covers up to 50 domains, so it's
 * much faster (and more accurate) than per-domain RDAP lookups.
 *
 * Auth is HTTP Basic with your name.com username + API token
 * (create one at https://www.name.com/account/settings/api).
 * The test environment (api.dev.name.com) needs its own token.
 */
final class NameComClient
{
    public const MAX_PER_CHECK = 50;

    public function __construct(private array $cfg)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string)($this->cfg['username'] ?? '')) !== ''
            && trim((string)($this->cfg['token'] ?? '')) !== '';
    }

    public function endpoint(): string
    {
        $custom = trim((string)($this->cfg['endpoint'] ?? ''));
        if ($custom !== '') {
            return rtrim($custom, '/');
        }
        return !empty($this->cfg['test']) ? 'https://api.dev.name.com' : 'https://api.name.com';
    }

    /**
     * Bulk availability + pricing. Chunks the list into MAX_PER_CHECK batches.
     *
     * @param string[] $domains
     * @return array<string,array{purchasable:bool,price:?float,premium:bool}>
     *         keyed by domain name; domains missing from the response (API
     *         error, unsupported TLD) are simply absent.
     */
    public function checkAvailability(array $domains): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($domains)), self::MAX_PER_CHECK) as $chunk) {
            $response = $this->post('/v4/domains:checkAvailability', ['domainNames' => $chunk]);
            foreach ((array)($response['results'] ?? []) as $result) {
                $name = strtolower((string)($result['domainName'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $out[$name] = [
                    'purchasable' => !empty($result['purchasable']),
                    'price'       => isset($result['purchasePrice']) ? (float)$result['purchasePrice'] : null,
                    'premium'     => !empty($result['premium']),
                ];
            }
        }
        return $out;
    }

    /** @return array<mixed> decoded JSON, or [] on any failure */
    private function post(string $path, array $payload): array
    {
        $ch = curl_init($this->endpoint() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 30),
            CURLOPT_USERPWD        => $this->cfg['username'] . ':' . $this->cfg['token'],
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
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
