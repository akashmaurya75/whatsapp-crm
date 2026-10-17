<?php $this->layout('layouts/app', ['title' => 'Roles & permissions']); ?>
<div class="page-head"><div><h1>Roles &amp; permissions</h1><p class="muted">Changes apply the next time affected users sign in.</p></div>
  <form method="post" action="/roles" class="inline-form"><?= csrf_field($app) ?><input name="name" placeholder="New role name" required maxlength="100" aria-label="New role name"><button class="btn" type="submit">Create role</button></form>
</div>
<?= field_error($errors, 'role') ?><?= field_error($errors, 'name') ?>
<div class="stack">
<?php foreach ($roles as $r): $owner = $r['slug'] === 'owner'; ?>
  <details class="card" id="role-<?= (int) $r['id'] ?>">
    <summary><strong><?= e($r['name']) ?></strong> <span class="muted small"><?= (int) $r['user_count'] ?> user(s) · <?= count($grants[$r['id']] ?? []) ?> permission(s)<?= $r['is_system'] ? ' · default role' : ' · custom' ?></span></summary>
    <form method="post" action="/roles/<?= (int) $r['id'] ?>" class="perm-grid">
      <?= csrf_field($app) ?>
      <?php foreach ($permissions as $name => $desc): ?>
        <label class="check"><input type="checkbox" name="permissions[<?= e($name) ?>]" value="1"<?= isset($grants[$r['id']][$name]) ? ' checked' : '' ?><?= $owner ? ' disabled' : '' ?>> <span><b><?= e($name) ?></b><br><small class="muted"><?= e($desc) ?></small></span></label>
      <?php endforeach; ?>
      <?php if (!$owner): ?><div class="form-actions"><button class="btn btn-sm" type="submit">Save permissions</button></div><?php endif; ?>
    </form>
  </details>
<?php endforeach; ?>
</div>
