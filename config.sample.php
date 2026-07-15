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
    //   'mock'        — generated sample drops, no network calls (for exploring)
    //   'whoisfreaks' — WhoisFreaks Expired & Dropped Domains API (recommended).
    //                   Set the API key from whoisfreaks.com → billing dashboard.
    //   'url'         — any URL returning a plain-text list of domains (one per
    //                   line, .zip / .txt / .csv). Use {date} as a YYYY-MM-DD
    //                   placeholder, e.g. a WhoisDS / paid feed download link.
    'drops' => [
        'provider'  => getenv('DROPS_PROVIDER') ?: 'mock',
        'url'       => getenv('DROPS_URL') ?: '',
        'whoisfreaks_api_key' => getenv('WHOISFREAKS_API_KEY') ?: '',
        // Only needed if WhoisFreaks' download link differs from the built-in
        // default — paste it with {date} and {apiKey} placeholders.
        'whoisfreaks_url'     => getenv('WHOISFREAKS_URL') ?: '',
        // The filter: keep only SLDs of exactly this length…
        'exact_len' => (int)(getenv('DROPS_EXACT_LEN') ?: 9),
        // …on these TLDs (comma-separated, no dots).
        'tlds'      => getenv('DROPS_TLDS') ?: 'com',
        // Safety cap per fetch (a full day's .com drop list is 100k+ lines).
        'max_keep'  => (int)(getenv('DROPS_MAX_KEEP') ?: 500),
        // Cron (no explicit date) fetches the list from this many days ago —
        // most feeds publish a completed day the next morning, so 1 = yesterday.
        'day_offset' => (int)(getenv('DROPS_DAY_OFFSET') ?: 1),
    ],

    // --- Moz Links API (optional) — Domain Authority + backlink metrics ---
    // Free credentials at https://moz.com/products/api. When set, the top
    // drops of each fetch get DA / PA / linking-domain counts on the board.
    'moz' => [
        'access_id'  => getenv('MOZ_ACCESS_ID') ?: '',
        'secret_key' => getenv('MOZ_SECRET_KEY') ?: '',
    ],

    // --- name.com API (recommended) ---
    // Create an API token at https://www.name.com/account/settings/api.
    // When configured, the day's top drops are availability-checked in bulk
    // through name.com (50 per call) with real registration prices — instead
    // of one-by-one RDAP lookups. Also manageable in /superadmin/settings.php.
    'namecom' => [
        'username' => getenv('NAMECOM_USERNAME') ?: '',
        'token'    => getenv('NAMECOM_TOKEN') ?: '',
        // true = use the api.dev.name.com test environment (needs its own token).
        'test'     => (bool)(getenv('NAMECOM_TEST') ?: false),
    ],

    // --- RDAP availability verification (fallback when name.com is not set) ---
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
