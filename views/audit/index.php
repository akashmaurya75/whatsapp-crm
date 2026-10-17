<?php $this->layout('layouts/app', ['title' => 'Audit log']); ?>
<div class="page-head"><div><h1>Audit log</h1><p class="muted">Security-relevant actions<?= $admin ? ' across the platform' : '' ?>.</p></div></div>
<?php if ($rows === []): ?><p class="empty">Nothing recorded yet.</p><?php else: ?>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>When (UTC)</th><?= $admin ? '<th>Company</th>' : '' ?><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Details</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap"><?= e($r['created_at']) ?></td>
      <?= $admin ? '<td>' . e($r['tenant_name'] ?? 'platform') . '</td>' : '' ?>
      <td><?= e($r['user_name'] ?? '—') ?></td>
      <td><code><?= e($r['action']) ?></code></td>
      <td><?= $r['entity_type'] ? e($r['entity_type'] . ' #' . $r['entity_id']) : '—' ?></td>
      <td class="mono"><?= e($r['ip'] ?? '') ?></td>
      <td class="small mono wrap"><?= e(mb_substr((string) ($r['meta'] ?? ''), 0, 160)) ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= $this->partial('partials/pagination', ['page' => $page, 'total' => $total, 'perPage' => 50, 'query' => []]) ?>
<?php endif; ?>
