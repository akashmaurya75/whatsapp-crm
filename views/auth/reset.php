<?php $this->layout('layouts/guest', ['title' => 'Set password']); ?>
<h1 class="auth-title"><?= e($title) ?></h1>
<form method="post" action="<?= e($action . $token) ?>" class="form">
  <?= csrf_field($app) ?>
  <?= field_error($errors, 'password') ?>
  <label>New password
    <input type="password" name="password" autocomplete="new-password" minlength="<?= (int) env('PASSWORD_MIN_LENGTH', 12) ?>" required autofocus>
  </label>
  <label>Confirm password
    <input type="password" name="password_confirmation" autocomplete="new-password" required>
  </label>
  <p class="hint">At least <?= (int) env('PASSWORD_MIN_LENGTH', 12) ?> characters, including letters and numbers.</p>
  <button type="submit" class="btn btn-block">Save password</button>
</form>
