<?php $this->layout('layouts/app', ['title' => 'WhatsApp accounts']); ?>
<div class="page-head">
  <div><h1>WhatsApp accounts</h1><p class="muted">Each account shows its provider type, connection health and this month's usage.</p></div>
  <?php if ($app->can('whatsapp.manage')): ?>
  <div class="actions">
    <a class="btn btn-fb" href="/whatsapp/connect">Continue with Facebook</a>
    <a class="btn btn-ghost" href="/whatsapp/new?provider=meta_cloud">Connect manually (token)</a>
    <?php if (isset($providers['openwa'])): ?><a class="btn btn-ghost" href="/whatsapp/new?provider=openwa">Add OpenWA gateway</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php if ($accounts === []): ?>
  <div class="empty-state card"><h2>Connect your WhatsApp number</h2>
    <p class="muted">Sign in with Facebook and pick your business and number. We set up the official WhatsApp Cloud API for you.</p>
    <?php if ($app->can('whatsapp.manage')): ?><a class="btn btn-fb" href="/whatsapp/connect">Continue with Facebook</a><?php endif; ?></div>
<?php else: ?>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>Account</th><th>Provider</th><th>Number</th><th>Status</th><th>Last sync</th><th>This month</th><th>Last error</th></tr></thead>
  <tbody>
  <?php foreach ($accounts as $a): $p = $providers[$a['provider']] ?? null; ?>
    <tr>
      <td><a href="/whatsapp/<?= (int) $a['id'] ?>"><strong><?= e($a['name']) ?></strong></a></td>
      <td><?= $p ? e($p->label()) : e($a['provider']) ?><?= $p && !$p->isOfficial() ? ' <span class="pill pill-warn">unofficial</span>' : '' ?></td>
      <td class="mono"><?= e($a['phone_e164'] ?? '—') ?></td>
      <td><span class="pill pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
      <td><?= e(time_ago($a['last_synced_at'])) ?></td>
      <td><?= (int) ($usage[$a['id']]['in'] ?? 0) ?> in · <?= (int) ($usage[$a['id']]['out'] ?? 0) ?> out</td>
      <td class="small"><?= $a['last_error'] ? e(mb_substr((string) $a['last_error'], 0, 80)) . ' <span class="muted">(' . e(time_ago($a['last_error_at'])) . ')</span>' : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
