<?php $this->layout('layouts/app', ['title' => 'Teams']); ?>
<div class="page-head"><div><h1>Teams</h1><p class="muted">Conversations assigned to a team are visible to all of its members.</p></div>
  <form method="post" action="/teams" class="inline-form"><?= csrf_field($app) ?><input name="name" placeholder="Team name" required maxlength="120" aria-label="Team name"><button class="btn" type="submit">Create team</button></form>
</div>
<?= field_error($errors, 'name') ?>
<?php if ($teams === []): ?><div class="empty-state card"><h2>No teams yet</h2><p class="muted">Create teams such as Sales or Support to route conversations.</p></div><?php endif; ?>
<div class="stack">
<?php foreach ($teams as $t): ?>
  <section class="card">
    <div class="card-head"><h2><?= e($t['name']) ?></h2>
      <form method="post" action="/teams/<?= (int) $t['id'] ?>/delete" data-confirm="Delete team <?= e($t['name']) ?>?"><?= csrf_field($app) ?><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form></div>
    <form method="post" action="/teams/<?= (int) $t['id'] ?>/members" class="perm-grid">
      <?= csrf_field($app) ?>
      <?php foreach ($users as $u): ?>
        <label class="check"><input type="checkbox" name="members[<?= (int) $u['id'] ?>]" value="1"<?= isset($members[$t['id']][$u['id']]) ? ' checked' : '' ?>> <?= e($u['name']) ?></label>
      <?php endforeach; ?>
      <div class="form-actions"><button class="btn btn-sm" type="submit">Save members</button></div>
    </form>
  </section>
<?php endforeach; ?>
</div>
