<?php
$this->layout('layouts/app', ['title' => 'Campaigns']);
$canManage = $app->can('campaigns.manage');
$tabs = ['' => 'All', 'draft' => 'Drafts', 'scheduled' => 'Scheduled', 'running' => 'Sending', 'paused' => 'Paused', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$fmt = static function (?string $utc) use ($tz): string {
    if (!$utc) { return '—'; }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('j M Y, H:i');
};
$pct = static fn (int $a, int $b) => $b > 0 ? round($a / $b * 100) . '%' : '—';
?>
<div class="page-head">
  <div><h1>Campaigns</h1><p class="muted">Send approved templates to a chosen audience now or at a planned time. Times are in <?= e($tz) ?>.</p></div>
</div>

<?php if ($canManage): ?>
<details class="card new-campaign"<?= !empty($errors) || $campaigns === [] ? ' open' : '' ?>>
  <summary><strong>+ New campaign</strong></summary>
  <?php if ($templates === []): ?>
    <p class="muted">You need at least one <b>approved</b> template first. <a href="/templates">Go to templates →</a></p>
  <?php else: ?>
  <form method="post" action="/campaigns" class="form form-inline">
    <?= csrf_field($app) ?>
    <label>Campaign name <input name="name" required maxlength="160" placeholder="Diwali offer – Oct 2026" value="<?= e(old_value($old, 'name')) ?>"></label>
    <label>Template
      <select name="template_id" required>
        <?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"<?= old_value($old, 'template_id') === (string) $tp['id'] ? ' selected' : '' ?>><?= e($tp['name']) ?> (<?= e($tp['category']) ?>, <?= e($tp['language']) ?>)</option><?php endforeach; ?>
      </select></label>
    <button class="btn" type="submit">Continue →</button>
    <?= field_error($errors, 'name') ?><?= field_error($errors, 'template_id') ?>
  </form>
  <?php endif; ?>
</details>
<?php endif; ?>

<div class="tabs">
  <?php foreach ($tabs as $k => $l): ?><a href="/campaigns<?= $k !== '' ? '?status=' . $k : '' ?>" class="<?= $status === $k ? 'is-active' : '' ?>"><?= $l ?></a><?php endforeach; ?>
</div>
<?php if ($campaigns === []): ?>
  <div class="empty-state card"><h2>No campaigns yet</h2><p class="muted">Plan your first broadcast: choose a template, pick who gets it, and send now or schedule it.</p></div>
<?php else: ?>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>Campaign</th><th>Status</th><th>When</th><th>Audience</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Replied</th><th>Failed</th></tr></thead>
  <tbody>
  <?php foreach ($campaigns as $c): $sent = (int) $c['sent_n']; ?>
    <tr>
      <td><a href="/campaigns/<?= (int) $c['id'] ?><?= in_array($c['status'], ['draft'], true) && $canManage ? '/edit' : '' ?>"><strong><?= e($c['name']) ?></strong></a><br><small class="muted mono"><?= e($c['template_name']) ?> · <?= e($c['account_name']) ?></small></td>
      <td><span class="pill pill-camp-<?= e($c['status']) ?>"><?= e($c['status'] === 'running' ? 'sending' : $c['status']) ?></span></td>
      <td class="nowrap small"><?= e($c['status'] === 'draft' ? ($c['scheduled_at'] ? 'planned ' . $fmt($c['scheduled_at']) : '—') : $fmt($c['started_at'] ?? $c['scheduled_at'])) ?></td>
      <td><?= $c['status'] === 'draft' || $c['status'] === 'scheduled' ? '—' : number_format((int) $c['total_recipients']) ?></td>
      <td><?= number_format($sent) ?></td>
      <td><?= number_format((int) $c['delivered_n']) ?> <small class="muted"><?= $pct((int) $c['delivered_n'], $sent) ?></small></td>
      <td><?= number_format((int) $c['read_n']) ?> <small class="muted"><?= $pct((int) $c['read_n'], $sent) ?></small></td>
      <td><?= number_format((int) $c['replied_n']) ?> <small class="muted"><?= $pct((int) $c['replied_n'], $sent) ?></small></td>
      <td><?= (int) $c['failed_n'] > 0 ? '<span class="status-failed">' . number_format((int) $c['failed_n']) . '</span>' : '0' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
