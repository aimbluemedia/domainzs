<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fields = [
        'hero_title', 'hero_subtitle', 'upgrade_note',
        'drops_provider', 'drops_url', 'drops_min_len', 'drops_max_len', 'drops_tlds', 'drops_max_keep', 'drops_day_offset',
        'whoisfreaks_api_key', 'whoisfreaks_url',
        'namecom_username', 'namecom_token',
        'moz_access_id', 'moz_secret_key', 'moz_max_per_fetch',
        'ai_api_key', 'ai_model', 'ai_max_per_fetch',
        'mail_enabled', 'mail_to', 'mail_from',
    ];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            set_setting($field, trim((string)$_POST[$field]));
        }
    }
    // Checkboxes: absent when unchecked.
    set_setting('mail_enabled', empty($_POST['mail_enabled']) ? '0' : '1');
    set_setting('namecom_test', empty($_POST['namecom_test']) ? '0' : '1');
    set_setting('drops_no_hyphens', empty($_POST['drops_no_hyphens']) ? '0' : '1');
    set_setting('drops_no_digits', empty($_POST['drops_no_digits']) ? '0' : '1');
    flash('success', 'Settings saved.');
    redirect('/superadmin/settings.php');
}

$drops   = drops_config($config);
$namecom = namecom_config($config);
$ai      = ai_config($config);
$mail    = mail_config($config);
$namecomOn = (new \Domainzs\NameComClient($namecom))->isConfigured();

layout_header('Settings', 'admin');
?>
<h1>Settings</h1>
<p class="sub">Saved to the database and immediately live — they override the defaults in config.php.</p>

<form method="post" class="stack">
    <?= csrf_field() ?>

    <div class="panel">
        <h2 style="margin-top:0">📡 Drop feed &amp; filter</h2>
        <div class="row">
            <div>
                <label>Provider</label>
                <select name="drops_provider">
                    <option value="mock" <?= !in_array($drops['provider'], ['url', 'whoisfreaks', 'whoisfreaks_free'], true) ? 'selected' : '' ?>>Mock (sample data)</option>
                    <option value="whoisfreaks_free" <?= $drops['provider'] === 'whoisfreaks_free' ? 'selected' : '' ?>>WhoisFreaks FREE daily list (no key)</option>
                    <option value="whoisfreaks" <?= $drops['provider'] === 'whoisfreaks' ? 'selected' : '' ?>>WhoisFreaks paid API</option>
                    <option value="url" <?= $drops['provider'] === 'url' ? 'selected' : '' ?>>URL feed (custom)</option>
                </select>
            </div>
            <div>
                <label>Min length</label>
                <input name="drops_min_len" type="number" min="1" max="63" value="<?= (int)$drops['min_len'] ?>">
            </div>
            <div>
                <label>Max length</label>
                <input name="drops_max_len" type="number" min="1" max="63" value="<?= (int)$drops['max_len'] ?>">
            </div>
            <div>
                <label>TLDs (comma-separated)</label>
                <input name="drops_tlds" value="<?= e($drops['tlds']) ?>" placeholder="com">
            </div>
            <div>
                <label>Max kept per fetch</label>
                <input name="drops_max_keep" type="number" min="10" max="10000" value="<?= (int)$drops['max_keep'] ?>">
            </div>
            <div>
                <label>Daily fetch pulls</label>
                <select name="drops_day_offset">
                    <option value="1" <?= (int)$drops['day_offset'] === 1 ? 'selected' : '' ?>>yesterday's list (last 24h — recommended)</option>
                    <option value="0" <?= (int)$drops['day_offset'] === 0 ? 'selected' : '' ?>>today's list</option>
                    <option value="2" <?= (int)$drops['day_offset'] === 2 ? 'selected' : '' ?>>2 days ago</option>
                </select>
            </div>
        </div>
        <div class="row" style="margin-top:12px">
            <label class="checkbox"><input type="checkbox" name="drops_no_hyphens" value="1" <?= !empty($drops['no_hyphens']) ? 'checked' : '' ?>> No hyphens (skip names containing "-")</label>
            <label class="checkbox"><input type="checkbox" name="drops_no_digits" value="1" <?= !empty($drops['no_digits']) ? 'checked' : '' ?>> No digits (skip names containing 0-9)</label>
        </div>
        <p class="field-help"><strong>WhoisFreaks FREE daily list</strong> needs no key — it pulls the ~10,000
        dropped/expired domains WhoisFreaks publishes each day (github.com/WhoisFreaks/daily-expired-and-dropped-domains).
        The paid API below covers the full ~400k/day.</p>
        <label>WhoisFreaks API key (used when provider is "WhoisFreaks paid API")</label>
        <input name="whoisfreaks_api_key" value="<?= e($drops['wf_api_key']) ?>" autocomplete="off"
               placeholder="from whoisfreaks.com → billing dashboard">
        <label>WhoisFreaks URL override (optional)</label>
        <input name="whoisfreaks_url" value="<?= e($drops['wf_url']) ?>"
               placeholder="only if your dashboard's download link differs — use {date} and {apiKey}">
        <p class="field-help">Default endpoint: <code>api.whoisfreaks.com/v1.0/whois/droppeddomains?whois=false&amp;date={date}&amp;apiKey=…</code>
        If WhoisFreaks gives you a different download link, paste it above with <code>{date}</code> and
        <code>{apiKey}</code> placeholders — no code change needed.</p>

        <label>Feed URL (used when provider is "URL feed")</label>
        <input name="drops_url" value="<?= e($drops['url']) ?>" placeholder="https://…/{date}.zip">
        <p class="field-help">Any URL returning one domain per line (txt/csv, zip/gzip supported). Date placeholders are
        replaced with the day being fetched: <code>{date}</code> → 2026-07-14 · <code>{date_ymd}</code> → 20260714 ·
        <code>{date_b64}</code> → base64 of "2026-07-14.zip" (the format WhoisDS links use).</p>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">🏷️ name.com API
            <?= $namecomOn ? '<span class="badge-st st-free">configured</span>' : '<span class="badge-st st-taken">not set</span>' ?></h2>
        <div class="row">
            <div>
                <label>name.com username</label>
                <input name="namecom_username" value="<?= e($namecom['username']) ?>" autocomplete="off">
            </div>
            <div class="rf-grow">
                <label>API token</label>
                <input name="namecom_token" type="password" value="<?= e($namecom['token']) ?>" autocomplete="new-password" placeholder="paste your API token">
            </div>
        </div>
        <label class="checkbox"><input type="checkbox" name="namecom_test" value="1" <?= $namecom['test'] ? 'checked' : '' ?>>
            Use the test environment (api.dev.name.com — needs its own token)</label>
        <p class="field-help">Create a token at <strong>name.com → Account → Settings → API tokens</strong>
        (https://www.name.com/account/settings/api). When configured, each fetch bulk-checks the top drops through
        name.com — live availability plus the real registration price shown on the drop board. Without it, the app
        falls back to free RDAP checks (no prices).</p>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">📈 Moz — Domain Authority &amp; links
            <?= (new \Domainzs\MozClient(moz_config($config)))->isConfigured()
                ? '<span class="badge-st st-free">configured</span>' : '<span class="badge-st st-taken">not set</span>' ?></h2>
        <div class="row">
            <div>
                <label>Moz Access ID</label>
                <input name="moz_access_id" value="<?= e(moz_config($config)['access_id']) ?>" autocomplete="off">
            </div>
            <div class="rf-grow">
                <label>Moz Secret Key</label>
                <input name="moz_secret_key" type="password" value="<?= e(moz_config($config)['secret_key']) ?>" autocomplete="new-password">
            </div>
            <div>
                <label>Max Moz-checked per fetch</label>
                <input name="moz_max_per_fetch" type="number" min="0" max="200" value="<?= (int)(setting('moz_max_per_fetch', '25') ?? 25) ?>">
            </div>
        </div>
        <p class="field-help">Free credentials at moz.com/products/api. When set, the top drops of each fetch get
        <strong>Domain Authority</strong>, Page Authority, and <strong>linking root domains</strong> — an expired name
        with real backlinks is worth far more than its spelling. The per-fetch cap keeps you inside Moz's free
        monthly quota.</p>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">🧠 AI rating (optional)</h2>
        <div class="row">
            <div class="rf-grow">
                <label>Anthropic API key</label>
                <input name="ai_api_key" value="<?= e($ai['api_key']) ?>" placeholder="sk-ant-… (blank = heuristic mock)">
            </div>
            <div>
                <label>Model</label>
                <input name="ai_model" value="<?= e($ai['model']) ?>">
            </div>
            <div>
                <label>Max AI-rated per fetch</label>
                <input name="ai_max_per_fetch" type="number" min="0" max="200" value="<?= (int)$ai['max_per_fetch'] ?>">
            </div>
        </div>
        <p class="field-help">Get a key at console.anthropic.com. With no key, the AI column uses heuristic comments so the UI still works.</p>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">✉️ Email</h2>
        <label class="checkbox"><input type="checkbox" name="mail_enabled" value="1" <?= $mail['enabled'] ? 'checked' : '' ?>> Send email notifications (offers + fetch digests)</label>
        <div class="row">
            <div><label>To</label><input name="mail_to" type="email" value="<?= e($mail['to']) ?>"></div>
            <div><label>From</label><input name="mail_from" value="<?= e($mail['from']) ?>"></div>
        </div>
    </div>

    <div class="panel">
        <h2 style="margin-top:0">🏠 Homepage copy</h2>
        <label>Hero title</label>
        <input name="hero_title" value="<?= e(setting('hero_title', 'The best dropped 9-letter .coms — found and rated for you, daily.')) ?>">
        <label>Hero subtitle</label>
        <input name="hero_subtitle" value="<?= e(setting('hero_subtitle', 'domainzs pulls every freshly dropped domain, keeps the 9-character .coms, and scores each one for brandability and resale value — so you only look at names worth registering.')) ?>">
        <label>Upgrade note (shown to free members on their Account page)</label>
        <input name="upgrade_note" value="<?= e(setting('upgrade_note', 'To upgrade: reply to your welcome email or contact the site owner — your account is activated the same day.')) ?>">
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
</form>
<?php
layout_footer();
