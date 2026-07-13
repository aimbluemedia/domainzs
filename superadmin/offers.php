<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    if ($action === 'read') {
        $pdo->prepare('UPDATE offers SET is_read = 1 WHERE id = ?')->execute([$id]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM offers WHERE id = ?')->execute([$id]);
        flash('success', 'Offer deleted.');
    }
    redirect('/superadmin/offers.php');
}

$offers = $pdo->query(
    'SELECT o.*, l.domain, l.price_cents AS asking_cents FROM offers o
     JOIN listings l ON l.id = o.listing_id
     ORDER BY o.is_read ASC, o.created_at DESC LIMIT 200'
)->fetchAll();

layout_header('Offers', 'admin');
?>
<h1>Offers</h1>
<p class="sub">Inquiries from the public "make an offer" forms. Reply by email — the buyer's address is right there.</p>

<?php if (!$offers): ?>
    <div class="empty">No offers yet. They'll land here (and in your inbox, if email is enabled).</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Asking</th><th>Offer</th><th>From</th><th>Message</th><th>When</th><th></th></tr>
        <?php foreach ($offers as $o): ?>
        <tr <?= !$o['is_read'] ? 'class="row-unread"' : '' ?>>
            <td><strong><?= e($o['domain']) ?></strong></td>
            <td><?= money_cents((int)$o['asking_cents']) ?></td>
            <td><strong><?= $o['amount_cents'] !== null ? money_cents((int)$o['amount_cents']) : 'asking price' ?></strong></td>
            <td><?= e($o['name']) ?><br><a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a></td>
            <td class="notes-cell"><?= e($o['message'] ?? '') ?></td>
            <td class="notes-cell"><?= e($o['created_at']) ?></td>
            <td class="row-actions">
                <?php if (!$o['is_read']): ?>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="btn btn-sm" type="submit">Mark read</button>
                </form>
                <?php endif; ?>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
