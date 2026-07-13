<?php
/**
 * domainzs configuration sample.
 *
 * Copy this file to config.php and fill in your values:
 *     cp config.sample.php config.php
 *
 * config.php is git-ignored so your secrets never get committed.
 * Most operational settings (drop filters, feed provider, AI key, email) can
 * also be managed in the UI at /superadmin/settings.php — values saved there
 * override the defaults below.
 */

return [
    // --- Database (MySQL / MariaDB) ---
    'db' => [
        // On shared hosting (e.g. Hostinger) host is usually 'localhost'.
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'domainzs',
        'user'     => getenv('DB_USER') ?: 'domainzs',
        'password' => getenv('DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],

    // --- App / session ---
    'app' => [
        'secret'   => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        'timezone' => getenv('APP_TZ') ?: 'America/New_York',
    ],

    // --- Dropped-domain feed ---
    // provider:
    //   'mock' — generated sample drops, no network calls (great for exploring)
    //   'url'  — any URL returning a plain-text list of domains (one per line,
    //            .zip / .txt / .csv supported). Use {date} as a YYYY-MM-DD
    //            placeholder, e.g. a WhoisDS / paid feed download link.
    'drops' => [
        'provider'  => getenv('DROPS_PROVIDER') ?: 'mock',
        'url'       => getenv('DROPS_URL') ?: '',
        // The filter: keep only SLDs of exactly this length…
        'exact_len' => (int)(getenv('DROPS_EXACT_LEN') ?: 9),
        // …on these TLDs (comma-separated, no dots).
        'tlds'      => getenv('DROPS_TLDS') ?: 'com',
        // Safety cap per fetch (a full day's .com drop list is 100k+ lines).
        'max_keep'  => (int)(getenv('DROPS_MAX_KEEP') ?: 500),
    ],

    // --- RDAP availability verification ---
    // The top-scored drops are re-verified via RDAP (free, no API key) so the
    // member area shows live availability. rdap.org 404 == available.
    'rdap' => [
        'endpoint'   => getenv('RDAP_ENDPOINT') ?: 'https://rdap.org',
        'timeout'    => (int)(getenv('RDAP_TIMEOUT') ?: 10),
        'verify_top' => (int)(getenv('RDAP_VERIFY_TOP') ?: 25),
    ],

    // --- AI rating (Anthropic Claude) — optional second opinion ---
    // Get a key at https://console.anthropic.com/. Leave blank to run the AI
    // in MOCK mode (heuristic comments, no API calls).
    'ai' => [
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
        'model'   => getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-8',
        // Max drops sent to the AI per fetch (cost control).
        'max_per_fetch' => (int)(getenv('AI_MAX_PER_FETCH') ?: 15),
    ],

    // --- Email notifications (optional) ---
    // Offers on your listings + fetch digests. Uses PHP's mail().
    'mail' => [
        'enabled' => (bool)(getenv('MAIL_ENABLED') ?: false),
        'to'      => getenv('MAIL_TO') ?: '',
        'from'    => getenv('MAIL_FROM') ?: 'domainzs@localhost',
    ],
];
