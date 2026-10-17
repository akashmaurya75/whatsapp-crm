<?php $this->layout('install/layout', ['title' => 'Installer key', 'step' => 1]); ?>
<h1 class="auth-title">Enter the installer key</h1>
<p class="muted">You're opening the installer from another computer. To prove you control this server, open the file
<code>storage/install-key.txt</code> in the application folder (via SSH, FTP or your hosting file manager) and enter the key it contains.</p>
<?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/install/unlock" class="form">
  <input type="hidden" name="_token" value="<?= e($csrf) ?>">
  <label>Installer key <input name="install_key" autocomplete="off" required autofocus class="mono" maxlength="40"></label>
  <button class="btn btn-block" type="submit">Continue</button>
</form>
<p class="hint">The file is created the first time this page loads. Opening the installer on the server itself (localhost) skips this step.</p>
