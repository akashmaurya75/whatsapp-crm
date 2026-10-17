<?php $this->layout('layouts/app', ['title' => 'Security']); ?>
<div class="page-head"><div><h1>Security</h1><p class="muted"><?= e($user['email']) ?></p></div></div>
<div class="grid-2">
  <section class="card">
    <h2>Two-factor authentication</h2>
    <?php if (!empty($recoveryCodes)): ?>
      <div class="alert alert-warn"><strong>Save these recovery codes now.</strong> Each works once if you lose your phone. They will not be shown again.
        <ul class="codes mono"><?php foreach ($recoveryCodes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($user['totp_enabled_at']): ?>
      <p><span class="pill pill-connected">On</span> since <?= e(time_ago($user['totp_enabled_at'])) ?></p>
      <form method="post" action="/account/2fa/disable" class="form" data-confirm="Turn off two-factor authentication?">
        <?= csrf_field($app) ?>
        <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
        <?= field_error($errors, 'current_password') ?>
        <button class="btn btn-ghost" type="submit">Turn off 2FA</button>
      </form>
    <?php elseif ($pendingSecret): ?>
      <ol class="small">
        <li>In Google Authenticator, Authy, 1Password or similar, add an account manually with this key:</li>
      </ol>
      <div class="copy-row"><input readonly class="mono" value="<?= e(trim(chunk_split($pendingSecret, 4, ' '))) ?>" aria-label="Secret key" data-copy-source><button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></div>
      <details class="small"><summary>Setup URI (for password managers)</summary><code class="wrap"><?= e($otpUri) ?></code></details>
      <form method="post" action="/account/2fa/confirm" class="form">
        <?= csrf_field($app) ?>
        <label>2. Enter the 6-digit code it shows <input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label>
        <?= field_error($errors, 'code') ?>
        <button class="btn" type="submit">Confirm and enable</button>
      </form>
    <?php else: ?>
      <p class="muted">Protect your account with a code from an authenticator app at each sign-in.</p>
      <form method="post" action="/account/2fa/start"><?= csrf_field($app) ?><button class="btn" type="submit">Set up 2FA</button></form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Change password</h2>
    <form method="post" action="/account/password" class="form">
      <?= csrf_field($app) ?>
      <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
      <?= $user['totp_enabled_at'] ? '' : field_error($errors, 'current_password') ?>
      <label>New password <input type="password" name="password" required autocomplete="new-password"></label>
      <?= field_error($errors, 'password') ?>
      <label>Confirm new password <input type="password" name="password_confirmation" required autocomplete="new-password"></label>
      <button class="btn" type="submit">Update password</button>
    </form>
  </section>

  <section class="card">
    <h2>Sessions</h2>
    <p class="muted small">Signs you out on every browser and device, including this one.</p>
    <form method="post" action="/account/logout-all" data-confirm="Sign out of all devices?"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Sign out everywhere</button></form>
  </section>

  <section class="card">
    <h2>Recent sign-in attempts</h2>
    <ul class="list small"><?php foreach ($logins as $l): ?>
      <li><span><?= $l['success'] ? '<span class="pill pill-connected">success</span>' : '<span class="pill pill-error">failed</span>' ?> <span class="mono"><?= e($l['ip']) ?></span><br><span class="muted"><?= e(mb_substr((string) $l['user_agent'], 0, 70)) ?></span></span><span class="muted"><?= e(time_ago($l['created_at'])) ?></span></li>
    <?php endforeach; ?></ul>
  </section>
</div>
