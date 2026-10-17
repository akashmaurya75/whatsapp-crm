<?php $this->layout('layouts/guest', ['title' => 'Create account']); ?>
<h1 class="auth-title">Create your company workspace</h1>
<form method="post" action="/register" class="form">
  <?= csrf_field($app) ?>
  <label>Company name <input name="company" value="<?= e(old_value($old, 'company')) ?>" required maxlength="160"></label>
  <?= field_error($errors, 'company') ?>
  <label>Your name <input name="name" value="<?= e(old_value($old, 'name')) ?>" required maxlength="160"></label>
  <?= field_error($errors, 'name') ?>
  <label>Work email <input type="email" name="email" value="<?= e(old_value($old, 'email')) ?>" required autocomplete="email"></label>
  <?= field_error($errors, 'email') ?>
  <label>Password <input type="password" name="password" required autocomplete="new-password"></label>
  <?= field_error($errors, 'password') ?>
  <button type="submit" class="btn btn-block">Create workspace</button>
</form>
<p class="auth-links"><a href="/login">I already have an account</a></p>
