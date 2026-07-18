<?php
declare(strict_types=1);

/**
 * "Run now" — run the daily job from the browser, bypassing cron.
 *
 * This host blocks background jobs (exec disabled) and 500s if the whole
 * pipeline (fetch + AI recap, possibly for several days) runs in one request.
 * So the work is chunked: the page's JavaScript calls ?step=1 repeatedly and
 * each call does ONE bounded unit (one day's fetch, or one day's recap, or the
 * email). No single request can time out, and the user watches it progress.
 *
 * Job state lives in the admin's session (settings.sval is only 500 chars).
 */

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

use Domainzs\Auth;
use Domainzs\DropEngine;
use Domainzs\DailyRecap;
use Domainzs\Notifier;

Auth::requireAdmin();

// --------------------------------------------------------------------------
// JSON step endpoint — do exactly one unit of work, then return the state.
// --------------------------------------------------------------------------
if (isset($_GET['step'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(runnow_do_step($pdo, $config));
    exit;
}

// --------------------------------------------------------------------------
// POST: start a run, or cancel one.
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'start') {
        $_SESSION['runnow'] = runnow_build_job($config);
    } elseif ($action === 'cancel') {
        unset($_SESSION['runnow']);
        flash('info', 'Run cancelled.');
    }
    redirect('/superadmin/runnow.php');
}

$job     = $_SESSION['runnow'] ?? null;
$active  = $job && !($job['done'] ?? false) && !empty($job['queue']);
$done    = $job && ($job['done'] ?? false);

layout_header('Run now', 'admin');
?>
<h1>Run now</h1>
<p class="sub">Run the full daily job — fetch the drop list, score &amp; rate it, build the Daily Recap,
and email it — right now, without waiting for cron. It also backfills any recent day the cron skipped.
Work runs one step at a time so it never times out on this host; keep this tab open until it finishes.</p>

<?php if ($active): ?>
    <div class="panel">
        <div class="panel-head"><h2>⏳ Running…</h2>
            <form method="post" onsubmit="return confirm('Stop the current run?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <button class="btn btn-sm btn-danger" type="submit">Stop</button>
            </form>
        </div>
        <div class="progress"><div id="bar" class="progress-bar" style="width:0%"></div></div>
        <p id="status" class="sub" style="margin:10px 0 4px">Starting…</p>
        <ul id="log" class="runlog"></ul>
    </div>
    <script>
    (function () {
        var fails = 0;
        var bar = document.getElementById('bar');
        var status = document.getElementById('status');
        var logEl = document.getElementById('log');
        function render(j) {
            var pct = j.total ? Math.round(((j.total - j.remaining) / j.total) * 100) : 100;
            bar.style.width = pct + '%';
            status.textContent = j.done ? 'Done.' : (j.current || 'Working…');
            logEl.innerHTML = '';
            (j.log || []).forEach(function (line) {
                var li = document.createElement('li');
                li.textContent = line;
                logEl.appendChild(li);
            });
        }
        function tick() {
            fetch('?step=1', { headers: { 'X-Requested-With': 'fetch' } })
                .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
                .then(function (j) {
                    fails = 0;
                    render(j);
                    if (j.done) { setTimeout(function () { location.href = '/superadmin/runnow.php'; }, 700); }
                    else { setTimeout(tick, 400); }
                })
                .catch(function () {
                    fails++;
                    status.textContent = 'A step is taking a while… retrying (' + fails + ').';
                    if (fails > 6) { status.textContent = 'Stopped after repeated errors — reload to resume.'; return; }
                    setTimeout(tick, 2000);
                });
        }
        tick();
    })();
    </script>
<?php else: ?>
    <?php if ($done && !empty($job['log'])): ?>
    <div class="panel">
        <div class="panel-head"><h2>✅ Last run finished</h2></div>
        <ul class="runlog"><?php foreach ($job['log'] as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <form method="post" style="margin-top:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="start">
        <button class="btn btn-scan" type="submit">▶ Run the daily job now</button>
    </form>
    <p class="sub" style="margin-top:14px">Tip: this is your backup for when Hostinger's cron doesn't fire.
    When the cron is working, you don't need it — the job runs on its own each morning.</p>
<?php endif; ?>
<?php
layout_footer();


/**
 * Build the work queue: any missing day in the trailing window (oldest first),
 * then the target day, with fetch → recap steps per day and one email at the end.
 */
function runnow_build_job(array $config): array
{
    $drops  = drops_config($config);
    $offset = max(0, (int)$drops['day_offset']);
    $target = date('Y-m-d', time() - $offset * 86400);
    $maxDays = 4;
    $start  = date('Y-m-d', strtotime($target) - ($maxDays - 1) * 86400);

    global $pdo;
    $have = [];
    try {
        $q = $pdo->prepare('SELECT DISTINCT dropped_date FROM drops WHERE dropped_date BETWEEN ? AND ?');
        $q->execute([$start, $target]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $d) { $have[(string)$d] = true; }
    } catch (\Throwable $e) {
        // no drops table yet — the fetch step will surface a clear error
    }

    $days = [];
    for ($i = $maxDays - 1; $i >= 1; $i--) {
        $d = date('Y-m-d', strtotime($target) - $i * 86400);
        if (!isset($have[$d])) { $days[] = $d; }
    }
    $days[] = $target;

    $queue = [];
    foreach ($days as $d) {
        $queue[] = ['date' => $d, 'stage' => 'fetch', 'tries' => 0];
        $queue[] = ['date' => $d, 'stage' => 'recap', 'tries' => 0];
    }
    $queue[] = ['date' => $target, 'stage' => 'email', 'tries' => 0];

    return ['queue' => $queue, 'total' => count($queue), 'target' => $target, 'log' => [], 'done' => false];
}

/**
 * Execute one queued step. Writes the incremented try-count to the session
 * BEFORE the slow work (and releases the session lock), so a step that gets
 * killed by the host is retried a bounded number of times, then skipped —
 * the loop can never get stuck.
 *
 * @return array{done:bool,total:int,remaining:int,current:?string,log:string[]}
 */
function runnow_do_step(\PDO $pdo, array $config): array
{
    $job = $_SESSION['runnow'] ?? null;
    if (!$job || empty($job['queue'])) {
        if ($job) { $job['done'] = true; $_SESSION['runnow'] = $job; }
        return ['done' => true, 'total' => (int)($job['total'] ?? 0), 'remaining' => 0,
                'current' => null, 'log' => $job['log'] ?? []];
    }

    $head = $job['queue'][0];
    $date = (string)$head['date'];
    $stage = (string)$head['stage'];

    // Too many attempts on this step → skip it so the run can finish.
    if ((int)$head['tries'] >= 3) {
        array_shift($job['queue']);
        $job['log'][] = "⚠️ Skipped {$stage} for {$date} (kept timing out).";
        return runnow_finish_or_state($job);
    }

    // Persist the attempt, then release the lock during the slow work.
    $job['queue'][0]['tries'] = (int)$head['tries'] + 1;
    $_SESSION['runnow'] = $job;
    session_write_close();

    $label = null;
    try {
        if ($stage === 'fetch') {
            $stats = (new DropEngine($pdo, $config))->run($date);
            $label = "Fetched {$date}: {$stats['matched']} matched, {$stats['added']} new.";
            if (!empty($stats['error'])) { $label .= " (feed note: {$stats['error']})"; }
            if ($date === ($job['target'] ?? '')) {
                set_setting('cron_last_run', date('Y-m-d H:i:s'));
                set_setting('cron_last_summary', "{$date}: {$stats['matched']} matched, {$stats['added']} new");
            }
            // Push the day's top 5 to the fetch digest, like the cron does.
            try {
                $top = $pdo->prepare('SELECT domain, score FROM drops WHERE dropped_date = ? ORDER BY score DESC LIMIT 5');
                $top->execute([$date]);
                (new Notifier($config))->sendFetchDigest($date, $stats, $top->fetchAll());
            } catch (\Throwable $e) { /* digest is best-effort */ }
        } elseif ($stage === 'recap') {
            $recap = (new DailyRecap($pdo, $config))->forDate($date);
            $label = $recap === null
                ? "No drops for {$date} — no recap."
                : 'Recap built for ' . $date . ' — top pick: ' . ($recap['body']['top_pick']['domain'] ?? '—') . '.';
        } elseif ($stage === 'email') {
            $recap = (new DailyRecap($pdo, $config))->stored($date);
            if ($recap === null) {
                $label = "No recap to email for {$date}.";
            } else {
                $sent = email_recap_once($config, $date, $recap['body']);
                $label = $sent ? "Emailed the {$date} recap." : "Recap email skipped (already sent or email off).";
            }
        }
    } catch (\Throwable $e) {
        $label = "⚠️ {$stage} for {$date} errored: " . $e->getMessage();
    }

    // Reopen the session and record the result (head still at front).
    session_start();
    $job = $_SESSION['runnow'] ?? $job;
    array_shift($job['queue']);
    if ($label !== null) { $job['log'][] = $label; }
    return runnow_finish_or_state($job);
}

/** Mark done if the queue is empty; persist; return the JSON state. */
function runnow_finish_or_state(array $job): array
{
    if (empty($job['queue'])) {
        $job['done'] = true;
    }
    $_SESSION['runnow'] = $job;
    $remaining = count($job['queue']);
    $current = $remaining
        ? ucfirst((string)$job['queue'][0]['stage']) . ' ' . (string)$job['queue'][0]['date'] . '…'
        : null;
    return [
        'done'      => (bool)($job['done'] ?? false),
        'total'     => (int)($job['total'] ?? 0),
        'remaining' => $remaining,
        'current'   => $current,
        'log'       => $job['log'] ?? [],
    ];
}
