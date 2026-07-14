<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Fetches the raw dropped-domain list for a date.
 *
 * Providers:
 *   'mock' — deterministic generated sample drops, zero network calls.
 *   'url'  — any URL returning one domain per line (.txt/.csv, or a .zip
 *            containing such a file). Date placeholders in the URL are
 *            replaced with the day being fetched:
 *              {date}      → 2026-07-14
 *              {date_ymd}  → 20260714
 *              {date_b64}  → MjAyNi0wNy0xNC56aXA=   (base64 of "2026-07-14.zip",
 *                             the format WhoisDS download links use)
 *            Works with WhoisDS downloads and most paid drop-list feeds.
 */
final class DropsClient
{
    public function __construct(private array $cfg)
    {
    }

    public function isMock(): bool
    {
        return ($this->cfg['provider'] ?? 'mock') !== 'url' || ($this->cfg['url'] ?? '') === '';
    }

    /**
     * @return string[] raw domain names (lowercase), unfiltered
     */
    public function fetch(string $date): array
    {
        if ($this->isMock()) {
            return $this->mockList($date);
        }

        $url = strtr((string)$this->cfg['url'], [
            '{date}'     => $date,
            '{date_ymd}' => str_replace('-', '', $date),
            '{date_b64}' => base64_encode($date . '.zip'),
        ]);
        $body = $this->download($url);
        if ($body === null) {
            return [];
        }

        // Zip archives: extract the first entry (WhoisDS ships a zip of one .txt).
        if (str_starts_with($body, "PK\x03\x04") && class_exists(\ZipArchive::class)) {
            $body = $this->unzipFirst($body) ?? '';
        }

        $domains = [];
        foreach (preg_split('/[\r\n,;]+/', $body) ?: [] as $line) {
            $line = strtolower(trim($line, " \t\"'"));
            if ($line !== '' && str_contains($line, '.') && !str_starts_with($line, '#')) {
                $domains[] = $line;
            }
        }
        return $domains;
    }

    private function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'domainzs/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($code === 200 && is_string($body)) ? $body : null;
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
