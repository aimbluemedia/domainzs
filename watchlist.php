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
                "INSERT IGNORE INTO domains (domain, kind, notes) VALUES (?, 'watchlist', ?)"
            );
            $stmt->execute([$domain, trim((string)($_POST['notes'] ?? '')) ?: null]);
            if ($stmt->rowCount() === 0) {
                flash('error', "{$domain} is already being tracked.");
            } else {
                $row = $pdo->prepare('SELECT * FROM domains WHERE domain = ?');
                $row->execute([$domain]);
                if ($d = $row->fetch()) {
                    $checker->checkOne($d);
                    $fresh = $pdo->prepare('SELECT status FROM domains WHERE domain = ?');
                    $fresh->execute([$domain]);
                    if ($fresh->fetchColumn() === 'available') {
                        flash('success', "{$domain} is available RIGHT NOW — go register it!");
                    } else {
                        flash('success', "Watching {$domain}. You'll be alerted when it becomes available.");
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM domains WHERE id = ? AND kind = 'watchlist'");
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Domain removed from the watchlist.');
    }
    redirect('/watchlist.php');
}

$domains = $pdo->query(
    "SELECT * FROM domains WHERE kind = 'watchlist'
     ORDER BY FIELD(status, 'available', 'pending_delete', 'registered', 'unknown'), domain"
)->fetchAll();

layout_header('Watchlist');
?>
<h1>Watchlist</h1>
<p class="sub">Domains you want. domainzs checks them on every scan and emails you the moment
one becomes available or enters pending delete.</p>

<div class="card" style="margin-bottom:24px">
    <form method="post" class="row-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="rf-grow">
            <label for="domain">Domain</label>
            <input id="domain" name="domain" placeholder="thenameiwant.com" required>
        </div>
        <div class="rf-grow">
            <label for="notes">Notes</label>
            <input id="notes" name="notes" maxlength="500" placeholder="perfect for the side project…">
        </div>
        <button class="btn btn-primary" type="submit">Watch domain</button>
    </form>
</div>

<?php if (!$domains): ?>
    <div class="empty">Not watching anything yet — add a dream domain above.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Status</th><th>Current expiry</th><th>Registrar</th><th>RDAP flags</th><th>Notes</th><th></th></tr>
        <?php foreach ($domains as $d): [$label, $class] = status_meta($d['status']); ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="badge-st st-<?= e($class) ?>"><?= e($label) ?></span></td>
            <td><?= $d['expires_at'] ? e(substr($d['expires_at'], 0, 10)) . ' <span class="sub-inline">(' . e(expiry_label($d['expires_at'])) . ')</span>' : '—' ?></td>
            <td><?= e($d['registrar'] ?? '—') ?></td>
            <td class="notes-cell"><?= e($d['rdap_status'] ?? '—') ?></td>
            <td class="notes-cell"><?= e($d['notes'] ?? '') ?></td>
            <td>
                <form class="inline" method="post" onsubmit="return confirm('Stop watching <?= e($d['domain']) ?>?')">
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
