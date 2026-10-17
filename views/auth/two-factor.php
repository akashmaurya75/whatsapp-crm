<?php $this->layout('layouts/guest', ['title' => 'Two-factor verification']); ?>
<h1 class="auth-title">Two-factor verification</h1>
<p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
<form method="post" action="/two-factor" class="form">
  <?= csrf_field($app) ?>
  <?= field_error($errors, 'code') ?>
  <label>Code
    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" required autofocus>
  </label>
  <button type="submit" class="btn btn-block">Verify</button>
</form>
<p class="auth-links"><a href="/login">Start over</a></p>
