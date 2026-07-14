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
        'drops_provider', 'drops_url', 'drops_exact_len', 'drops_tlds', 'drops_max_keep', 'drops_day_offset',
        'namecom_username', 'namecom_token',
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
                    <option value="mock" <?= $drops['provider'] !== 'url' ? 'selected' : '' ?>>Mock (sample data)</option>
                    <option value="url" <?= $drops['provider'] === 'url' ? 'selected' : '' ?>>URL feed (live)</option>
                </select>
            </div>
            <div>
                <label>Exact name length</label>
                <input name="drops_exact_len" type="number" min="1" max="63" value="<?= (int)$drops['exact_len'] ?>">
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
        <label>Feed URL (used when provider is "URL feed")</label>
        <input name="drops_url" value="<?= e($drops['url']) ?>" placeholder="https://www.whoisds.com/whois-database/newly-registered-domains/{date_b64}/nrd">
        <p class="field-help">Any URL returning one domain per line (txt/csv, zip supported). Date placeholders are
        replaced with the day being fetched: <code>{date}</code> → 2026-07-14 · <code>{date_ymd}</code> → 20260714 ·
        <code>{date_b64}</code> → base64 of "2026-07-14.zip" (the format WhoisDS links use). Works with WhoisDS
        downloads and most paid drop feeds.</p>
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
