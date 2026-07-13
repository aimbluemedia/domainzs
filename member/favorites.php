<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;

Auth::requireMember();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (($_POST['action'] ?? '') === 'unfav') {
        $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND drop_id = ?')
            ->execute([Auth::userId(), (int)($_POST['drop_id'] ?? 0)]);
    }
    redirect('/member/favorites.php');
}

$stmt = $pdo->prepare(
    'SELECT d.*, f.created_at AS faved_at FROM favorites f
     JOIN drops d ON d.id = f.drop_id
     WHERE f.user_id = ? ORDER BY f.created_at DESC'
);
$stmt->execute([Auth::userId()]);
$drops = $stmt->fetchAll();

layout_header('Favorites', 'member');
?>
<h1>Favorites</h1>
<p class="sub">Names you starred from the drop board. Register them at your registrar before someone else does!</p>

<?php if (!$drops): ?>
    <div class="empty">No favorites yet — star names on the <a href="/member/drops.php">Drop Board</a>.</div>
<?php else: ?>
    <table>
        <tr><th>Domain</th><th>Score</th><th>Dropped</th><th>AI says</th><th>Est.</th><th></th></tr>
        <?php foreach ($drops as $d): ?>
        <tr>
            <td><strong><?= e($d['domain']) ?></strong></td>
            <td><span class="scorepill sc-<?= e(score_class((int)$d['score'])) ?>"><?= (int)$d['score'] ?></span></td>
            <td><?= e($d['dropped_date']) ?></td>
            <td class="notes-cell"><?= $d['ai_comment'] ? e($d['ai_comment']) : '—' ?></td>
            <td><?= $d['est_value'] ? '~$' . number_format((float)$d['est_value']) : '—' ?></td>
            <td>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unfav">
                    <input type="hidden" name="drop_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-sm btn-danger" type="submit">Remove</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
layout_footer();
