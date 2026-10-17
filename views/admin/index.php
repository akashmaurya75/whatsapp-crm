<?php $this->layout('layouts/app', ['title' => 'Companies']); ?>
<div class="page-head"><div><h1>Companies</h1><p class="muted">Platform administration. Message contents are not accessible from here.</p></div></div>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>Company</th><th>Plan</th><th>Users</th><th>WA accounts</th><th>Contacts</th><th>Messages (month)</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if ($tenants === []): ?><tr><td colspan="8" class="empty">No companies yet — create the first one below.</td></tr><?php endif; ?>
  <?php foreach ($tenants as $t): ?>
    <tr>
      <td><strong><?= e($t['name']) ?></strong><br><small class="muted"><?= e($t['slug']) ?> · since <?= e(substr((string) $t['created_at'], 0, 10)) ?></small></td>
      <td>
        <form method="post" action="/admin/tenants/<?= (int) $t['id'] ?>/plan" class="inline-form"><?= csrf_field($app) ?>
          <select name="plan_id" aria-label="Plan"><option value="">No plan</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) $t['plan_id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
          <button class="btn btn-ghost btn-sm" type="submit">Set</button></form>
      </td>
      <td><?= (int) $t['users'] ?></td><td><?= (int) $t['accounts'] ?></td><td><?= number_format((int) $t['contacts']) ?></td><td><?= number_format((int) $t['messages_month']) ?></td>
      <td><span class="pill pill-<?= e($t['status']) ?>"><?= e($t['status']) ?></span><?php if ($t['suspended_reason']): ?><br><small class="muted"><?= e($t['suspended_reason']) ?></small><?php endif; ?></td>
      <td>
        <form method="post" action="/admin/tenants/<?= (int) $t['id'] ?>/status" class="inline-form" data-confirm="<?= $t['status'] === 'active' ? 'Suspend ' . e($t['name']) . '? All its users are signed out and sending stops.' : 'Reactivate ' . e($t['name']) . '?' ?>">
          <?= csrf_field($app) ?>
          <?php if ($t['status'] === 'active'): ?>
            <input type="hidden" name="status" value="suspended"><input name="reason" placeholder="Reason" maxlength="255" aria-label="Suspension reason"><button class="btn btn-danger btn-sm" type="submit">Suspend</button>
          <?php else: ?>
            <input type="hidden" name="status" value="active"><button class="btn btn-sm" type="submit">Reactivate</button>
          <?php endif; ?>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<div class="grid-2">
<form method="post" action="/admin/tenants" class="card form">
  <?= csrf_field($app) ?>
  <h2>Create company</h2>
  <label>Company name <input name="company" required maxlength="160" value="<?= e(old_value($old, 'company')) ?>"></label><?= field_error($errors, 'company') ?>
  <label>Owner name <input name="owner_name" required maxlength="160" value="<?= e(old_value($old, 'owner_name')) ?>"></label><?= field_error($errors, 'owner_name') ?>
  <label>Owner email <input type="email" name="owner_email" required value="<?= e(old_value($old, 'owner_email')) ?>"></label><?= field_error($errors, 'owner_email') ?>
  <label>Temporary owner password <input type="password" name="owner_password" required autocomplete="new-password"></label><?= field_error($errors, 'owner_password') ?>
  <label>Plan <select name="plan_id"><option value="">No plan</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn" type="submit">Create company</button>
</form>

<section class="card">
  <h2>Subscription plans</h2>
  <p class="muted small">Prices are configuration, not code. WhatsApp conversation charges billed by Meta are separate from these plans.</p>
  <ul class="list small"><?php foreach ($plans as $p): ?>
    <li><span><b><?= e($p['name']) ?></b> <code><?= e($p['code']) ?></code><br><span class="muted"><?= e(number_format($p['price_cents'] / 100, 2) . ' ' . $p['currency'] . ' / ' . $p['billing_interval']) ?> · agents <?= e($p['max_agents'] ?? '∞') ?> · accounts <?= e($p['max_whatsapp_accounts'] ?? '∞') ?> · contacts <?= e($p['max_contacts'] ?? '∞') ?></span></span></li>
  <?php endforeach; ?></ul>
  <form method="post" action="/admin/plans" class="form form-grid">
    <?= csrf_field($app) ?>
    <label>Code <input name="code" required pattern="[a-z0-9_\-]+" maxlength="64"></label><label>Name <input name="name" required maxlength="120"></label>
    <label>Price (minor units) <input name="price_cents" type="number" min="0" required value="0"></label><label>Currency <input name="currency" maxlength="3" value="USD" required></label>
    <label>Interval <select name="billing_interval"><option value="month">Monthly</option><option value="year">Yearly</option></select></label>
    <label>Max agents <input name="max_agents" type="number" min="1"></label>
    <label>Max WA accounts <input name="max_whatsapp_accounts" type="number" min="1"></label><label>Max contacts <input name="max_contacts" type="number" min="1"></label>
    <div class="span-2 form-actions"><button class="btn btn-ghost" type="submit">Save plan (creates or updates by code)</button></div>
    <?= field_error($errors, 'code') ?>
  </form>
</section>
</div>
