<?php
use App\Domain\Campaigns\CampaignService as CS;

$this->layout('layouts/app', ['title' => $c['name']]);
$canManage = $app->can('campaigns.manage');
$fmt = static function (?string $utc) use ($tz): string {
    return $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('j M Y, H:i') : '—';
};
$s = $stats;
$base = max(1, $s['accepted']);
$audience = max(1, $s['audience']);
$funnel = [
    ['Audience', $s['audience'], 100, ''],
    ['Sent', $s['accepted'], $s['accepted'] / $audience * 100, 'submitted'],
    ['Delivered', $s['delivered_total'], $s['delivered_total'] / $audience * 100, 'delivered'],
    ['Read', $s['read'], $s['read'] / $audience * 100, 'read'],
    ['Replied', $s['replied'], $s['replied'] / $audience * 100, 'replied'],
];
$statusLabels = ['pending' => 'Waiting', 'queued' => 'Queued', 'submitted' => 'Sent', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed', 'skipped' => 'Skipped'];
?>
<div class="page-head">
  <div><a class="small" href="/campaigns">← Campaigns</a><h1><?= e($c['name']) ?></h1>
    <p class="muted small"><span class="pill pill-camp-<?= e($c['status']) ?>"><?= e($c['status'] === 'running' ? 'sending' : $c['status']) ?></span>
      Template <a class="mono" href="/templates/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a>
      · <?= $c['status'] === 'scheduled' ? 'scheduled for ' . e($fmt($c['scheduled_at'])) : 'started ' . e($fmt($c['started_at'])) ?>
      <?= $c['completed_at'] ? ' · all queued ' . e($fmt($c['completed_at'])) : '' ?> (<?= e($tz) ?>)</p>
  </div>
  <div class="actions">
    <?php if ($canManage): ?>
      <?php if (in_array($c['status'], ['running', 'scheduled'], true)): ?>
        <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/action"><?= csrf_field($app) ?><input type="hidden" name="action" value="pause"><button class="btn btn-ghost" type="submit">⏸ Pause</button></form>
      <?php elseif ($c['status'] === 'paused'): ?>
        <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/action"><?= csrf_field($app) ?><input type="hidden" name="action" value="resume"><button class="btn" type="submit">▶ Resume</button></form>
      <?php endif; ?>
      <?php if (!in_array($c['status'], ['completed', 'cancelled', 'draft'], true)): ?>
        <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/action" data-confirm="Cancel this campaign? Messages not yet sent will be dropped."><?= csrf_field($app) ?><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Cancel</button></form>
      <?php endif; ?>
      <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/duplicate"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Duplicate</button></form>
    <?php endif; ?>
    <a class="btn btn-ghost" href="/campaigns/<?= (int) $c['id'] ?>/export">Export CSV</a>
  </div>
</div>
<?php if ($c['last_error']): ?><div class="alert alert-error"><?= e($c['last_error']) ?></div><?php endif; ?>
<?php if ($c['status'] === 'running'): ?><div class="alert">Sending at up to <?= (int) $c['rate_per_minute'] ?> messages per minute. This page shows live numbers; refresh to update.</div><?php endif; ?>

<section class="kpis">
  <div class="kpi"><span>Audience</span><strong><?= number_format($s['audience']) ?></strong><small><?= number_format($s['skipped']) ?> skipped</small></div>
  <div class="kpi"><span>Sent</span><strong><?= number_format($s['accepted']) ?></strong><small><?= number_format($s['in_progress']) ?> waiting</small></div>
  <div class="kpi"><span>Delivered</span><strong><?= number_format($s['delivered_total']) ?></strong><small><?= round($s['delivered_total'] / $base * 100) ?>% of sent</small></div>
  <div class="kpi"><span>Read</span><strong><?= number_format($s['read']) ?></strong><small><?= round($s['read'] / $base * 100) ?>% of sent</small></div>
  <div class="kpi"><span>Replied</span><strong><?= number_format($s['replied']) ?></strong><small><?= round($s['replied'] / $base * 100) ?>% of sent</small></div>
  <div class="kpi"><span>Failed</span><strong class="<?= $s['failed'] ? 'status-failed' : '' ?>"><?= number_format($s['failed']) ?></strong><small>see errors below</small></div>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Funnel</h2>
    <div class="funnel">
      <?php foreach ($funnel as [$label, $n, $w, $key]): ?>
        <div class="funnel-row"><span><?= e($label) ?></span><svg class="funnel-bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="funnel-track" width="100" height="10"/><rect class="funnel-fill" width="<?= max(0.5, min(100, round($w, 1))) ?>" height="10"/></svg><b><?= number_format($n) ?></b></div>
      <?php endforeach; ?>
    </div>
    <p class="hint">Read receipts only arrive from people who have them switched on, so "read" is a minimum.</p>
  </section>
  <section class="card">
    <h2>Skipped &amp; why</h2>
    <?php if ($skips === []): ?><p class="empty">Nobody was skipped.</p><?php else: ?>
      <ul class="statlist small"><?php foreach ($skips as $k): ?><li><span><?= e(CS::SKIP_REASONS[$k['skip_reason']] ?? $k['skip_reason']) ?></span><span><?= number_format((int) $k['n']) ?></span><span></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head"><h2>Recipients</h2>
    <div class="tabs tabs-sm">
      <?php foreach (['' => 'All', 'read' => 'Read', 'delivered' => 'Delivered', 'replied' => 'Replied', 'failed' => 'Failed', 'skipped' => 'Skipped', 'pending' => 'Waiting'] as $k => $l): ?>
        <a href="/campaigns/<?= (int) $c['id'] ?><?= $k !== '' ? '?r=' . $k : '' ?>" class="<?= $filter === $k ? 'is-active' : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($recipients === []): ?><p class="empty"><?= $c['status'] === 'scheduled' ? 'Recipients are listed once the campaign starts.' : 'No recipients in this view.' ?></p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Contact</th><th>Status</th><th>Details</th><th>Updated</th></tr></thead>
    <tbody><?php foreach ($recipients as $r): ?>
      <tr>
        <td><?= e($r['name'] ?: '—') ?><br><small class="mono muted"><?= e($r['phone_e164']) ?></small></td>
        <td><span class="msg-status status-<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span><?= $r['replied_at'] ? ' <span class="pill pill-connected">replied</span>' : '' ?></td>
        <td class="small"><?= e($r['error'] ?: (CS::SKIP_REASONS[$r['skip_reason'] ?? ''] ?? '')) ?></td>
        <td class="small nowrap"><?= e(time_ago($r['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= $this->partial('partials/pagination', ['page' => $page, 'total' => $total, 'perPage' => 50, 'query' => ['r' => $filter]]) ?>
  <?php endif; ?>
</section>
