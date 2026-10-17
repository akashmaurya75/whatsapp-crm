<?php $this->layout('layouts/app', ['title' => 'API tokens']); ?>
<div class="page-head"><div><h1>API tokens</h1><p class="muted">Tokens act as the user who created them, with that user's permissions. Base URL: <code><?= e(url('/api/v1')) ?></code></p></div></div>
<?php if ($newToken): ?>
<div class="alert alert-warn"><strong>Copy this token now — it won't be shown again.</strong>
  <div class="copy-row"><input readonly class="mono" value="<?= e($newToken) ?>" aria-label="New API token" data-copy-source><button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></div>
  <p class="small">Use it as <code>Authorization: Bearer &lt;token&gt;</code>.</p>
</div>
<?php endif; ?>
<form method="post" action="/settings/api-tokens" class="card form form-inline">
  <?= csrf_field($app) ?>
  <input name="name" placeholder="Token name, e.g. Shopify sync" required maxlength="100" aria-label="Token name">
  <select name="ttl_days" aria-label="Expiry"><option value="30">Expires in 30 days</option><option value="90" selected>90 days</option><option value="365">1 year</option><option value="0">Never expires</option></select>
  <button class="btn" type="submit">Create token</button>
  <?= field_error($errors, 'name') ?>
</form>
<?php if ($tokens === []): ?><p class="empty">No tokens yet.</p><?php else: ?>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>Name</th><th>Owner</th><th>Created</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
  <tbody><?php foreach ($tokens as $t): ?>
    <tr<?= $t['revoked_at'] ? ' class="muted"' : '' ?>>
      <td><?= e($t['name']) ?></td><td><?= e($t['user_name']) ?></td><td><?= e(time_ago($t['created_at'])) ?></td>
      <td><?= e(time_ago($t['last_used_at'])) ?></td><td><?= $t['expires_at'] ? e(substr((string) $t['expires_at'], 0, 10)) : 'never' ?></td>
      <td><?php if ($t['revoked_at']): ?>revoked<?php else: ?>
        <form method="post" action="/settings/api-tokens/<?= (int) $t['id'] ?>/revoke" data-confirm="Revoke this token? Integrations using it will stop working."><?= csrf_field($app) ?><button class="btn btn-ghost btn-sm" type="submit">Revoke</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
