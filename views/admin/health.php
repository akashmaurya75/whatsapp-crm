<?php
$this->layout('layouts/app', ['title' => 'System health']);
$workerAge = $lastWorker ? time() - $lastWorker : null;
?>
<div class="page-head"><div><h1>System health</h1><p class="muted">PHP <?= e($php) ?> · database driver <?= e($driver) ?></p></div></div>
<section class="kpis">
  <div class="kpi"><span>Database</span><strong><?= $dbOk ? 'OK' : 'Down' ?></strong></div>
  <?php if ($queueMode === 'inline'): $cronAge = $lastCron ? time() - $lastCron['at'] : null; ?>
  <div class="kpi"><span>Background work (cron)</span><strong><?= $cronAge === null ? 'Never ran' : ($cronAge < 180 ? 'Running' : 'Not running') ?></strong><small><?= $cronAge !== null ? 'last run ' . $cronAge . 's ago (' . e($lastCron['source']) . ')' : 'add the cron job below' ?></small></div>
  <?php else: ?>
  <div class="kpi"><span>Queue worker</span><strong><?= $workerAge === null ? 'Never seen' : ($workerAge < 120 ? 'Running' : 'Stalled') ?></strong><small><?= $workerAge !== null ? 'heartbeat ' . $workerAge . 's ago' : 'start: php bin/console queue:work' ?></small></div>
  <?php endif; ?>
  <div class="kpi"><span>Storage</span><strong><?= $storageWritable ? 'Writable' : 'Not writable' ?></strong><small><?= $diskFree ? round($diskFree / 1073741824, 1) . ' GB free' : '' ?></small></div>
  <div class="kpi"><span>Failed jobs</span><strong><?= count($failed) ?></strong><small>most recent 50</small></div>
</section>
<?php if ($queueMode === 'inline'): ?>
<section class="card"><h2>Cron job (shared hosting)</h2>
  <p class="small">Run every minute (<code>* * * * *</code>) from your hosting panel's Cron Jobs page:</p>
  <pre class="code-block cron-line">/usr/local/bin/php <?= e($basePath) ?>/bin/console cron --quiet</pre>
  <p class="small muted">Use your host's PHP 8.3 path if cron emails say an older PHP is running (e.g. <code>/opt/alt/php83/usr/bin/php</code>).
  <?= $webCron ? 'A web cron URL is also enabled: <code>/cron?key=…</code> with the <code>CRON_KEY</code> from the .env file.' : '' ?></p>
</section>
<?php endif; ?>
<div class="grid-2">
<section class="card"><h2>Queues</h2>
  <?php if ($queues === []): ?><p class="empty">All queues are empty.</p><?php else: ?>
  <table class="table"><thead><tr><th>Queue</th><th>Pending</th><th>In progress</th><th>Oldest</th></tr></thead><tbody>
  <?php foreach ($queues as $q): ?><tr><td><?= e($q['queue']) ?></td><td><?= (int) $q['n'] ?></td><td><?= (int) $q['reserved'] ?></td><td><?= e(time_ago($q['oldest'])) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<section class="card"><h2>Provider accounts</h2>
  <?php if ($accounts === []): ?><p class="empty">No accounts.</p><?php else: ?>
  <ul class="list small"><?php foreach ($accounts as $a): ?><li><span><b><?= e($a['name']) ?></b> · <?= e($a['tenant']) ?><br><span class="muted"><?= e($a['provider']) ?> · synced <?= e(time_ago($a['last_synced_at'])) ?></span></span><span class="pill pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</section>
</div>
<section class="card"><h2>Failed jobs</h2>
  <?php if ($failed === []): ?><p class="empty">No failed jobs.</p><?php else: ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Queue</th><th>Error</th><th>Failed</th><th></th></tr></thead><tbody>
  <?php foreach ($failed as $f): ?><tr><td><?= (int) $f['id'] ?></td><td><?= e($f['queue']) ?></td><td class="small wrap"><?= e(mb_substr((string) $f['exception'], 0, 200)) ?></td><td class="nowrap"><?= e(time_ago($f['failed_at'])) ?></td>
    <td><form method="post" action="/admin/failed-jobs/<?= (int) $f['id'] ?>/retry"><?= csrf_field($app) ?><button class="btn btn-ghost btn-sm" type="submit">Retry</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
