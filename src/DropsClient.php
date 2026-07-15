<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Fetches the raw dropped-domain list for a date.
 *
 * Providers:
 *   'mock'             — deterministic generated sample drops, no network calls.
 *   'whoisfreaks_free' — WhoisFreaks' FREE daily list (~10,000 dropped/expired
 *                        domains) published on GitHub. No API key needed.
 *   'whoisfreaks'      — WhoisFreaks paid Expired & Dropped Domains API
 *                        (full ~400k/day coverage). Needs the API key from
 *                        whoisfreaks.com → billing dashboard.
 *   'url'              — any URL returning one domain per line (.txt/.csv, or
 *                        a .zip/.gz of one). Date placeholders in the URL are
 *                        replaced with the day being fetched:
 *                          {date}      → 2026-07-14
 *                          {date_ymd}  → 20260714
 *                          {date_b64}  → MjAyNi0wNy0xNC56aXA=  (base64 of
 *                                         "2026-07-14.zip", as WhoisDS links use)
 */
final class DropsClient
{
    private const WHOISFREAKS_URL =
        'https://api.whoisfreaks.com/v1.0/whois/droppeddomains?whois=false&date={date}&apiKey={apiKey}';

    private const WHOISFREAKS_FREE_BASE =
        'https://raw.githubusercontent.com/WhoisFreaks/daily-expired-and-dropped-domains/main/';

    private ?string $lastError = null;

    public function __construct(private array $cfg)
    {
    }

    /** Why the last fetch() returned nothing — null when it succeeded. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function isMock(): bool
    {
        return match ($this->cfg['provider'] ?? 'mock') {
            'url'              => ($this->cfg['url'] ?? '') === '',
            'whoisfreaks'      => trim((string)($this->cfg['wf_api_key'] ?? '')) === '',
            'whoisfreaks_free' => false, // no key needed
            default            => true,
        };
    }

    /**
     * @return string[] raw domain names (lowercase), unfiltered
     */
    public function fetch(string $date): array
    {
        $this->lastError = null;

        if ($this->isMock()) {
            return $this->mockList($date);
        }

        if (($this->cfg['provider'] ?? '') === 'whoisfreaks_free') {
            return $this->fetchWhoisFreaksFree($date);
        }

        if (($this->cfg['provider'] ?? '') === 'whoisfreaks') {
            $template = trim((string)($this->cfg['wf_url'] ?? '')) ?: self::WHOISFREAKS_URL;
            $url = strtr($template, [
                '{date}'   => $date,
                '{apiKey}' => trim((string)$this->cfg['wf_api_key']),
            ]);
        } else {
            $url = strtr((string)$this->cfg['url'], [
                '{date}'     => $date,
                '{date_ymd}' => str_replace('-', '', $date),
                '{date_b64}' => base64_encode($date . '.zip'),
            ]);
        }

        $body = $this->download($url);
        if ($body === null) {
            return [];
        }

        // Compressed responses: zip (WhoisDS ships a zip of one .txt) or gzip.
        if (str_starts_with($body, "PK\x03\x04")) {
            if (!class_exists(\ZipArchive::class)) {
                $this->lastError = 'feed is a zip but the PHP zip extension is not installed';
                return [];
            }
            $body = $this->unzipFirst($body);
            if ($body === null) {
                $this->lastError = 'could not extract the zip the feed returned';
                return [];
            }
        } elseif (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body);
            if (!is_string($body)) {
                $this->lastError = 'could not decompress the gzip the feed returned';
                return [];
            }
        }

        $domains = $this->parseList($body);
        if (!$domains) {
            $this->lastError = 'the feed responded but contained no domains'
                . (stripos($body, '<html') !== false ? ' (it returned an HTML page — a login wall or error page, not a list)' : '');
        }
        return $domains;
    }

    /**
     * WhoisFreaks' free daily GitHub list. Tries the dated file, then the
     * archive copy, then falls back to the always-current "latest" file.
     *
     * @return string[]
     */
    private function fetchWhoisFreaksFree(string $date): array
    {
        $candidates = [
            "{$date}-free-dropped-domains.csv",
            "archive/{$date}-free-dropped-domains.csv",
            '0-latest-free-dropped-domains.csv',
        ];
        foreach ($candidates as $file) {
            $this->lastError = null;
            $body = $this->download(self::WHOISFREAKS_FREE_BASE . $file);
            if ($body !== null) {
                $domains = $this->parseList($body);
                if ($domains) {
                    return $domains;
                }
            }
        }
        $this->lastError = $this->lastError ?: 'the free WhoisFreaks list could not be fetched';
        return [];
    }

    /**
     * Pull domain names out of whatever the feed returned: plain lines, CSV
     * (first column = domain, header rows skipped), or JSON (any string that
     * looks like a domain, so differing response shapes still work).
     *
     * @return string[]
     */
    private function parseList(string $body): array
    {
        $domains = [];

        $trimmed = ltrim($body);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                array_walk_recursive($data, function ($value) use (&$domains): void {
                    if (is_string($value)) {
                        $value = strtolower(trim($value));
                        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $value)) {
                            $domains[] = $value;
                        }
                    }
                });
                return array_values(array_unique($domains));
            }
        }

        foreach (preg_split('/[\r\n]+/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // CSV rows: the domain is the first column.
            $first = strtolower(trim(explode(',', $line, 2)[0], " \t\"'"));
            if ($first === '' || !str_contains($first, '.') || str_contains($first, '@')) {
                continue;
            }
            if (in_array($first, ['domain', 'domain_name', 'domainname'], true)) {
                continue; // CSV header
            }
            $domains[] = $first;
        }
        return array_values(array_unique($domains));
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($body)) {
            return $body;
        }

        // Surface the feed's own error message when it sent one (e.g. the
        // WhoisFreaks JSON error body for a bad key or exhausted credits).
        $detail = '';
        if (is_string($body) && $body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $msg = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
                if (is_string($msg) && $msg !== '') {
                    $detail = ' — "' . mb_substr($msg, 0, 160) . '"';
                }
            }
        }
        $this->lastError = $code > 0
            ? "feed returned HTTP {$code}{$detail}"
                . ($code === 404 && $detail === '' ? " — that date's list may not be published yet (try yesterday)" : '')
                . ($code === 401 || $code === 403 ? ' — check the API key' : '')
            : 'could not reach the feed' . ($err !== '' ? " ({$err})" : '');
        return null;
    }

    private function unzipFirst(string $zipBytes): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'drops');
        if ($tmp === false) {
            return null;
        }
        file_put_contents($tmp, $zipBytes);
        $zip = new \ZipArchive();
        $out = null;
        if ($zip->open($tmp) === true) {
            if ($zip->numFiles > 0) {
                $out = $zip->getFromIndex(0) ?: null;
            }
            $zip->close();
        }
        unlink($tmp);
        return $out;
    }

    /**
     * Deterministic sample drop list — same date always yields the same
     * domains, spread across great/decent/junk names so the scorer, member
     * board, and marketplace can all be exercised offline.
     *
     * @return string[]
     */
    private function mockList(string $date): array
    {
        $seed = crc32($date);
        $rand = function () use (&$seed): int {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $seed;
        };

        $firsts  = ['cloud', 'spark', 'brand', 'smart', 'quick', 'solar', 'metro', 'pixel', 'prime', 'nova', 'swift', 'stone', 'tiger', 'lunar', 'vivid', 'royal', 'grand', 'rapid', 'coast', 'green', 'mint', 'bold', 'pure', 'true', 'wild', 'blue', 'gold', 'iron', 'echo', 'flux'];
        $seconds = ['base', 'zone', 'hub', 'labs', 'wire', 'gram', 'port', 'gate', 'form', 'cast', 'flow', 'peak', 'nest', 'dock', 'mark', 'path', 'rank', 'spot', 'core', 'edge', 'leaf', 'vault', 'craft', 'quest', 'pilot', 'scout', 'forge', 'stack'];
        $letters = 'abcdefghijklmnopqrstuvwxyz';

        $out = [];
        // Brandable two-word combos (any length — the filter keeps the right ones).
        for ($i = 0; $i < 400; $i++) {
            $out[] = $firsts[$rand() % count($firsts)] . $seconds[$rand() % count($seconds)] . '.com';
        }
        // Random junk of the target lengths, so low scores exist too.
        for ($i = 0; $i < 150; $i++) {
            $len  = 8 + $rand() % 3; // 8..10
            $name = '';
            for ($j = 0; $j < $len; $j++) {
                $name .= $letters[$rand() % 26];
            }
            $out[] = $name . '.com';
        }
        // A few other TLDs to prove the filter drops them.
        foreach (['fastcloud.net', 'brandhubz.org', 'ninecharz.io'] as $other) {
            $out[] = $other;
        }
        return array_values(array_unique($out));
    }
}
