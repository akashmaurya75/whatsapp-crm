<?php $this->layout('layouts/guest', ['title' => 'Reset password']); ?>
<h1 class="auth-title">Reset your password</h1>
<p class="muted">We'll email you a link that is valid for 60 minutes.</p>
<form method="post" action="/forgot-password" class="form">
  <?= csrf_field($app) ?>
  <label>Email
    <input type="email" name="email" autocomplete="email" required autofocus>
  </label>
  <button type="submit" class="btn btn-block">Send reset link</button>
</form>
<p class="auth-links"><a href="/login">Back to sign in</a></p>
