<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * RDAP lookups — registration data for any domain, no API key required.
 *
 * Queries go through the rdap.org bootstrap redirector, which forwards each
 * domain to the authoritative registry RDAP server. A 404 from RDAP means the
 * domain is not registered (i.e. available). When RDAP can't be reached we
 * fall back to a DNS NS-record check, which can at least confirm a domain is
 * taken.
 *
 * MOCK mode (config rdap.mock = true) answers from deterministic sample data
 * with no network calls, so the whole app can be explored offline.
 */
final class RdapClient
{
    public function __construct(private array $cfg)
    {
    }

    public function isMock(): bool
    {
        return !empty($this->cfg['mock']);
    }

    /**
     * Look up one domain.
     *
     * @return array{status:string, registrar:?string, registered_at:?string,
     *               expires_at:?string, rdap_status:?string}
     *         status is 'registered' | 'available' | 'pending_delete' | 'unknown'
     */
    public function lookup(string $domain): array
    {
        if ($this->isMock()) {
            return $this->mockLookup($domain);
        }

        [$httpCode, $body] = $this->fetch($domain);

        if ($httpCode === 404) {
            return self::result('available');
        }
        if ($httpCode === 200 && $body !== null) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                return $this->parse($data);
            }
        }

        // RDAP unavailable — a DNS NS check can still confirm "taken".
        if (checkdnsrr($domain . '.', 'NS')) {
            return self::result('registered');
        }
        return self::result('unknown');
    }

    /** @return array{0:int,1:?string} HTTP status code (0 on failure) and body. */
    private function fetch(string $domain): array
    {
        $url = rtrim((string)$this->cfg['endpoint'], '/') . '/domain/' . rawurlencode($domain);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => (int)($this->cfg['timeout'] ?? 10),
            CURLOPT_HTTPHEADER     => ['Accept: application/rdap+json'],
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : null];
    }

    /** Pull the fields we track out of an RDAP domain object. */
    private function parse(array $data): array
    {
        $registeredAt = null;
        $expiresAt    = null;
        foreach ((array)($data['events'] ?? []) as $event) {
            $action = (string)($event['eventAction'] ?? '');
            $date   = self::toDbDate((string)($event['eventDate'] ?? ''));
            if ($date === null) {
                continue;
            }
            if ($action === 'registration') {
                $registeredAt = $date;
            } elseif ($action === 'expiration') {
                $expiresAt = $date;
            }
        }

        $registrar = null;
        foreach ((array)($data['entities'] ?? []) as $entity) {
            if (!in_array('registrar', (array)($entity['roles'] ?? []), true)) {
                continue;
            }
            foreach ((array)($entity['vcardArray'][1] ?? []) as $prop) {
                if (($prop[0] ?? '') === 'fn' && is_string($prop[3] ?? null) && $prop[3] !== '') {
                    $registrar = $prop[3];
                    break 2;
                }
            }
        }

        $codes  = array_map('strval', (array)($data['status'] ?? []));
        $status = 'registered';
        foreach ($codes as $code) {
            if (str_contains(strtolower($code), 'pending delete')) {
                $status = 'pending_delete';
                break;
            }
        }

        return self::result($status, $registrar, $registeredAt, $expiresAt, implode(', ', $codes) ?: null);
    }

    /** ISO-8601 RDAP eventDate → local 'Y-m-d H:i:s' for MySQL DATETIME. */
    private static function toDbDate(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }
        try {
            $dt = new \DateTime($iso);
        } catch (\Exception $e) {
            return null;
        }
        $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    }

    private static function result(
        string $status,
        ?string $registrar = null,
        ?string $registeredAt = null,
        ?string $expiresAt = null,
        ?string $rdapStatus = null
    ): array {
        return [
            'status'        => $status,
            'registrar'     => $registrar,
            'registered_at' => $registeredAt,
            'expires_at'    => $expiresAt,
            'rdap_status'   => $rdapStatus,
        ];
    }

    /**
     * Deterministic sample data: the same domain always gets the same answer,
     * spread across every status so all app features can be exercised.
     */
    private function mockLookup(string $domain): array
    {
        $seed = crc32($domain);
        $roll = $seed % 10;

        if ($roll < 2) { // 20% available
            return self::result('available');
        }
        if ($roll === 2) { // 10% dropping soon
            $expires = date('Y-m-d H:i:s', strtotime('-35 days'));
            return self::result('pending_delete', 'MockRegistrar Inc.', null, $expires, 'pending delete');
        }

        $registrars = ['Namecheap, Inc.', 'GoDaddy.com, LLC', 'Cloudflare, Inc.', 'Porkbun LLC', 'Gandi SAS'];
        $expiresIn  = ($seed % 397) - 6; // -6 .. 390 days from now
        return self::result(
            'registered',
            $registrars[$seed % count($registrars)],
            date('Y-m-d H:i:s', strtotime('-' . (300 + $seed % 2500) . ' days')),
            date('Y-m-d H:i:s', strtotime(($expiresIn >= 0 ? '+' : '') . $expiresIn . ' days')),
            'client transfer prohibited'
        );
    }
}
