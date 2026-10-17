<?php
$this->layout('layouts/app', ['title' => 'New contact']);
$v = static fn (string $f) => old_value($old, $f);
?>
<div class="page-head"><div><a class="small" href="/contacts">← Contacts</a><h1>New contact</h1></div></div>
<form method="post" action="/contacts" class="card form form-grid">
  <?= csrf_field($app) ?>
  <label>Name <input name="name" value="<?= e($v('name')) ?>" maxlength="160"></label>
  <label>Phone (international) <input name="phone" value="<?= e($v('phone')) ?>" placeholder="+91 98765 43210" required maxlength="32"><?= field_error($errors, 'phone') ?></label>
  <label>Email <input type="email" name="email" value="<?= e($v('email')) ?>" maxlength="191"><?= field_error($errors, 'email') ?></label>
  <label>Company <input name="company" value="<?= e($v('company')) ?>" maxlength="160"></label>
  <label>City <input name="city" value="<?= e($v('city')) ?>" maxlength="120"></label>
  <label>Source <input name="source" value="<?= e($v('source')) ?>" placeholder="website, referral, event…" maxlength="60"></label>
  <label>Owner
    <select name="owner_user_id"><option value="">Unassigned</option>
      <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $v('owner_user_id') === (string) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>Messaging consent
    <select name="consent_status">
      <option value="unknown">Unknown</option>
      <option value="granted"<?= $v('consent_status') === 'granted' ? ' selected' : '' ?>>Granted (customer opted in)</option>
      <option value="revoked"<?= $v('consent_status') === 'revoked' ? ' selected' : '' ?>>Revoked (opted out)</option>
    </select>
  </label>
  <label class="span-2">Address <input name="address" value="<?= e($v('address')) ?>" maxlength="255"></label>
  <div class="span-2 form-actions"><button class="btn" type="submit">Create contact</button><a class="btn btn-ghost" href="/contacts">Cancel</a></div>
</form>
