<?php
/**
 * domainzs configuration sample.
 *
 * Copy this file to config.php and fill in your values:
 *     cp config.sample.php config.php
 *
 * config.php is git-ignored so your secrets never get committed.
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
        // Used to sign/secure the session. Change this to a long random string.
        'secret'   => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        // Timezone used for displaying expiry dates and countdowns.
        'timezone' => getenv('APP_TZ') ?: 'America/New_York',
    ],

    // --- RDAP (domain registration data) ---
    // RDAP is the free, credential-less successor to WHOIS. Lookups go through
    // the https://rdap.org/ bootstrap redirector, which forwards each query to
    // the right registry. No API key needed.
    'rdap' => [
        'endpoint' => getenv('RDAP_ENDPOINT') ?: 'https://rdap.org',
        // Seconds to wait for a lookup before giving up (status stays 'unknown').
        'timeout'  => (int)(getenv('RDAP_TIMEOUT') ?: 10),
        // Set true to run on realistic sample data with no network calls —
        // handy for demos and offline development. The dashboard shows a
        // banner while mock mode is on.
        'mock'     => (bool)(getenv('RDAP_MOCK') ?: false),
        // Re-check a domain when its data is older than this many hours.
        'recheck_hours' => (int)(getenv('RDAP_RECHECK_HOURS') ?: 12),
    ],

    // --- Email notifications (optional) ---
    // Uses PHP's mail() by default. For Gmail/SMTP, configure your server's
    // sendmail or set up an SMTP relay. Leave 'to' blank to disable email.
    'mail' => [
        'enabled' => (bool)(getenv('MAIL_ENABLED') ?: false),
        'to'      => getenv('MAIL_TO') ?: '',
        'from'    => getenv('MAIL_FROM') ?: 'domainzs@localhost',
    ],

    // --- Alert rules ---
    'alerts' => [
        // Send a renewal reminder when a portfolio domain expires within
        // each of these day thresholds (one email per threshold).
        'expiry_days' => [30, 7, 1],
    ],
];
