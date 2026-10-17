<?php
$this->layout('install/layout', ['title' => 'Accounts', 'step' => 3]);
$o = static fn (string $k) => (string) ($old[$k] ?? '');
$err = static fn (string $k) => isset($errors[$k]) ? '<span class="field-error" role="alert">' . e($errors[$k]) . '</span>' : '';
?>
<h1 class="auth-title">Your application &amp; accounts</h1>
<?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error" role="alert">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" action="/install/app" class="form" data-install-form>
  <input type="hidden" name="_token" value="<?= e($csrf) ?>">

  <h2 class="install-h2">Where are you installing?</h2>
  <fieldset class="choice-group choice-group-3">
    <legend class="sr-only">Hosting type</legend>
    <label class="choice"><input type="radio" name="hosting" value="shared"<?= $o('hosting') === 'shared' ? ' checked' : '' ?>>
      <span><b>Shared hosting</b><br><small class="muted">cPanel, Hostinger, Plesk, DirectAdmin… One cron job runs the background work.</small></span></label>
    <label class="choice"><input type="radio" name="hosting" value="vps"<?= $o('hosting') === 'vps' ? ' checked' : '' ?>>
      <span><b>VPS / own server</b><br><small class="muted">You have SSH and can run a background service.</small></span></label>
    <label class="choice"><input type="radio" name="hosting" value="local"<?= $o('hosting') === 'local' ? ' checked' : '' ?>>
      <span><b>This computer</b><br><small class="muted">For trying it out.</small></span></label>
  </fieldset>
  <?= $err('hosting') ?>

  <h2 class="install-h2">Application</h2>
  <div class="form-grid">
    <label>App name <input name="app_name" value="<?= e($o('app_name')) ?>" maxlength="60" required><?= $err('app_name') ?></label>
    <label>Website address (URL) <input name="app_url" value="<?= e($o('app_url')) ?>" required inputmode="url"><?= $err('app_url') ?>
      <small class="hint">Exactly how people will open it, e.g. https://crm.yourcompany.com</small></label>
    <label>Default country calling code <input name="country_code" value="<?= e($o('country_code')) ?>" maxlength="4" inputmode="numeric" required><?= $err('country_code') ?>
      <small class="hint">Used for local numbers without +, e.g. 91 for India.</small></label>
    <label>Time zone
      <select name="timezone"><?php foreach ($timezones as $tz): ?><option<?= $o('timezone') === $tz ? ' selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select><?= $err('timezone') ?></label>
  </div>

  <h2 class="install-h2">Your company</h2>
  <div class="form-grid">
    <label class="span-2">Company name <input name="company" value="<?= e($o('company')) ?>" maxlength="160" required><?= $err('company') ?></label>
    <label>Your name <input name="owner_name" value="<?= e($o('owner_name')) ?>" maxlength="160" required autocomplete="name"><?= $err('owner_name') ?></label>
    <label>Your email (login) <input type="email" name="owner_email" value="<?= e($o('owner_email')) ?>" required autocomplete="email"><?= $err('owner_email') ?></label>
    <label>Password <input type="password" name="owner_password" required autocomplete="new-password" minlength="<?= (int) $minPassword ?>"><?= $err('owner_password') ?></label>
    <label>Confirm password <input type="password" name="owner_password_confirmation" required autocomplete="new-password"></label>
  </div>

  <h2 class="install-h2">Platform admin <small class="muted">— manages companies, plans and system health</small></h2>
  <div class="form-grid">
    <label class="span-2">Admin email <input type="email" name="admin_email" value="<?= e($o('admin_email')) ?>" required><?= $err('admin_email') ?>
      <small class="hint">Must differ from your company login.</small></label>
    <label>Admin password <input type="password" name="admin_password" required autocomplete="new-password" minlength="<?= (int) $minPassword ?>"><?= $err('admin_password') ?></label>
    <label>Confirm admin password <input type="password" name="admin_password_confirmation" required autocomplete="new-password"></label>
  </div>
  <p class="hint">Passwords: at least <?= (int) $minPassword ?> characters with letters and numbers.</p>

  <label class="check"><input type="checkbox" name="allow_registration" value="1"<?= $o('allow_registration') ? ' checked' : '' ?>>
    <span>Let other companies sign up by themselves (SaaS mode). <small class="muted">Leave off for a private install.</small></span></label>

  <div class="form-actions"><a class="btn btn-ghost" href="/install/database">Back</a><button class="btn" type="submit" data-install-submit>Install now</button></div>
</form>
