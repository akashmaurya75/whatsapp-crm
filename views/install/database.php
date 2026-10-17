<?php
$this->layout('install/layout', ['title' => 'Database', 'step' => 2]);
$o = static fn (string $k) => (string) ($old[$k] ?? '');
$driver = $o('driver');
?>
<h1 class="auth-title">Database</h1>
<?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="/install/database" class="form" autocomplete="off" data-db-form>
  <input type="hidden" name="_token" value="<?= e($csrf) ?>">
  <fieldset class="choice-group">
    <legend class="sr-only">Database type</legend>
    <?php if (in_array('mysql', $drivers, true)): ?>
    <label class="choice"><input type="radio" name="driver" value="mysql"<?= $driver === 'mysql' ? ' checked' : '' ?>>
      <span><b>MySQL / MariaDB</b><br><small class="muted">Recommended for servers and real use.</small></span></label>
    <?php endif; ?>
    <?php if (in_array('sqlite', $drivers, true)): ?>
    <label class="choice"><input type="radio" name="driver" value="sqlite"<?= $driver === 'sqlite' ? ' checked' : '' ?>>
      <span><b>SQLite</b><br><small class="muted">Zero setup: a single file. Good for trying it on your computer.</small></span></label>
    <?php endif; ?>
  </fieldset>

  <div class="form-grid" data-driver-panel="mysql"<?= $driver === 'mysql' ? '' : ' hidden' ?>>
    <label>Host <input name="host" value="<?= e($o('host')) ?>" placeholder="localhost">
      <small class="hint">Almost always <b>localhost</b> on shared hosting.</small></label>
    <label>Port <input name="port" value="<?= e($o('port')) ?>" inputmode="numeric" placeholder="3306"></label>
    <label class="span-2">Database name <input name="database" value="<?= e($o('database')) ?>" placeholder="whatsapp_crm">
      <small class="hint">On shared hosting, first create the database and user in your panel (cPanel → MySQL Databases, Hostinger → Databases), give the user ALL PRIVILEGES, and enter the full names including the prefix, e.g. <b>cpuser_crm</b>.</small></label>
    <label>Username <input name="username" value="<?= e($o('username')) ?>" autocomplete="off"></label>
    <label>Password <input type="password" name="password" autocomplete="new-password"></label>
  </div>
  <div data-driver-panel="sqlite"<?= $driver === 'sqlite' ? '' : ' hidden' ?>>
    <label>Database file <input name="sqlite_path" value="<?= e($o('sqlite_path')) ?>" placeholder="storage/database.sqlite">
      <small class="hint">Leave empty to use storage/database.sqlite.</small></label>
  </div>
  <div class="form-actions"><a class="btn btn-ghost" href="/install">Back</a><button class="btn" type="submit">Test &amp; continue</button></div>
</form>
