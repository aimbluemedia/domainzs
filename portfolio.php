<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/layout.php';

use Domainzs\Auth;
use Domainzs\DomainChecker;

Auth::requireLogin();

$checker = new DomainChecker($pdo, $rdap, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $domain = normalize_domain((string)($_POST['domain'] ?? ''));
        if ($domain === null) {
            flash('error', 'That doesn\'t look like a valid domain name.');
        } else {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO domains (domain, kind, renewal_cost, auto_renew, notes)
                 VALUES (?, 'portfolio', ?, ?, ?)"
            );
            $cost = trim((string)($_POST['renewal_cost'] ?? ''));
            $stmt->execute([
                $domain,
                $cost === '' ? null : (float)$cost,
                empty($_POST['auto_renew']) ? 0 : 1,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            if ($stmt->rowCount() === 0) {
                flash('error', "{$domain} is already being tracked.");
            } else {
                // Pull registrar + expiry right away so the row isn't empty.
                $row = $pdo->prepare('SELECT * FROM domains WHERE domain = ?');
                $row->execute([$domain]);
                if ($d = $row->fetch()) {
                    $checker->checkOne($d);
                }
                flash('success', "Added {$domain} to your portfolio.");
            }
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM domains WHERE id = ? AND kind = 'portfolio'");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Domain removed.');
    } elseif ($action === 'toggle_renew') {
        $pdo->prepare("UPDATE domains SET auto_renew = 1 - auto_renew WHERE id = ? AND kind = 'portfolio'")
            ->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('/portfolio.php');
}

$domains = $pdo->query(
    "SELECT * FROM domains WHERE kind = 'portfolio'
     ORDER BY expires_at IS NULL, expires_at ASC"
)->fetchAll();

$renewalTotal = array_sum(array_map(
    fn (array $d): float => (float)($d['renewal_cost'] ?? 0),
    $domains
));

layout_header('Portfolio');
?>
<h1>Portfolio</h1>
<p class="sub">Domains you own. Expiry dates come from RDAP automatically — you'll get reminder
emails at 30, 7, and 1 day(s) out (see config.php).</p>

<div class="card" style="margin-bottom:24px">
    <form method="post" class="row-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="rf-grow">
            <label for="domain">Domain</label>
            <input id="domain" name="domain" placeholder="example.com" required>
        </div>
        <div>
            <label for="renewal_cost">Renewal $/yr</label>
            <input id="renewal_cost" name="renewal_cost" type="number" step="0.01" min="0" placeholder="12.99">
        </div>
        <div class="rf-grow">
            <label for="notes">Notes</label>
            <input id="notes" name="notes" maxlength="500" placeholder="client site, keep forever…">
        </div>
        <label class="rf-check"><input type="checkbox" name="auto_renew" value="1"> auto-renew is on</label>
        <button class="btn btn-primary" type="submit">Add domain</button>
    </form>
</div>

<?php if (!$domains): ?>
    <div class="empty">Your portfolio is empty — add the domains you own above.</div>
<?php else: ?>
    <p class="sub">Tracking <strong><?= count($domains) ?></strong> domain(s)
        <?php if ($renewalTotal > 0): ?> · est. renewals <strong><?= e(money($renewalTotal)) ?></strong>/yr<?php endif; ?></p>
    <table>
        <tr><th>Domain</th><th>Expires</th><th>Countdown</th><th>Registrar</th><th>Renewal</th><th>Auto-renew</th><th>Notes</th><th></th></tr>
        <?php foreach ($domains as $d): ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><?= $d['expires_at'] ? e(substr($d['expires_at'], 0, 10)) : '—' ?></td>
            <td><span class="badge-exp exp-<?= e(expiry_class($d['expires_at'])) ?>"><?= e(expiry_label($d['expires_at'])) ?></span></td>
            <td><?= e($d['registrar'] ?? '—') ?></td>
            <td><?= $d['renewal_cost'] !== null ? e(money((float)$d['renewal_cost'])) : '—' ?></td>
            <td>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_renew">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm" type="submit" title="Click to toggle"><?= $d['auto_renew'] ? '✅ on' : '⚠️ off' ?></button>
                </form>
            </td>
            <td class="notes-cell"><?= e($d['notes'] ?? '') ?></td>
            <td>
                <form class="inline" method="post" onsubmit="return confirm('Stop tracking <?= e($d['domain']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
