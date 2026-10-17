<?php $this->layout('layouts/app', ['title' => 'Users']); ?>
<div class="page-head"><div><h1>Users</h1><p class="muted"><?= count($users) ?> user<?= count($users) === 1 ? '' : 's' ?></p></div></div>
<?php if ($canManage): ?>
<form method="post" action="/users/invite" class="card form form-inline">
  <?= csrf_field($app) ?>
  <h2>Invite a teammate</h2>
  <input name="name" placeholder="Full name" required maxlength="160" value="<?= e(old_value($old, 'name')) ?>" aria-label="Name">
  <input type="email" name="email" placeholder="email@company.com" required value="<?= e(old_value($old, 'email')) ?>" aria-label="Email">
  <select name="role_id" aria-label="Role"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $r['slug'] === 'support_agent' ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit">Send invite</button>
  <?= field_error($errors, 'email') ?><?= field_error($errors, 'name') ?>
</form>
<?php endif; ?>
<?= field_error($errors, 'user') ?>
<div class="table-wrap card"><table class="table">
  <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>2FA</th><th>Last login</th><?= $canManage ? '<th></th>' : '' ?></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $self = (int) $u['id'] === $app->userId(); ?>
    <tr>
      <td><strong><?= e($u['name']) ?></strong><?= $self ? ' <span class="muted small">(you)</span>' : '' ?><br><small class="muted"><?= e($u['email']) ?></small></td>
      <td><?= e($u['role_name'] ?? '—') ?></td>
      <td><span class="pill pill-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
      <td><?= $u['totp_enabled_at'] ? 'On' : '<span class="muted">Off</span>' ?></td>
      <td><?= e(time_ago($u['last_login_at'])) ?></td>
      <?php if ($canManage): ?>
      <td><?php if (!$self): ?>
        <form method="post" action="/users/<?= (int) $u['id'] ?>" class="inline-form">
          <?= csrf_field($app) ?>
          <select name="role_id" aria-label="Role for <?= e($u['name']) ?>"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $u['role_id'] === (int) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
          <?php if ($u['status'] !== 'invited'): ?>
          <select name="status" aria-label="Status"><option value="active"<?= $u['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="suspended"<?= $u['status'] === 'suspended' ? ' selected' : '' ?>>Suspended</option></select>
          <?php endif; ?>
          <button class="btn btn-ghost btn-sm" type="submit">Save</button>
        </form>
      <?php endif; ?></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
