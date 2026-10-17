<?php $this->layout('layouts/guest', ['title' => 'Sign in']); ?>
<h1 class="auth-title">Sign in</h1>
<form method="post" action="/login" class="form" novalidate>
  <?= csrf_field($app) ?>
  <?= field_error($errors, 'email') ?>
  <label>Email
    <input type="email" name="email" value="<?= e(old_value($old, 'email')) ?>" autocomplete="username" required autofocus>
  </label>
  <label>Password
    <input type="password" name="password" autocomplete="current-password" required>
  </label>
  <button type="submit" class="btn btn-block">Sign in</button>
</form>
<p class="auth-links"><a href="/forgot-password">Forgot password?</a><?php if (!empty($registration)): ?> · <a href="/register">Create a company account</a><?php endif; ?></p>
