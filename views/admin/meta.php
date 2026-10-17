<?php $this->layout('layouts/app', ['title' => 'Meta app']); ?>
<div class="page-head"><div><h1>Meta app — "Continue with Facebook"</h1>
  <p class="muted">Set this up once. Then every company can connect its WhatsApp number in one click, without copying tokens.</p></div>
  <span class="pill <?= $ready ? 'pill-connected' : 'pill-pending' ?>"><?= $ready ? 'ready' : 'not configured' ?></span>
</div>
<?php if (!$https): ?><div class="alert alert-warn">APP_URL is not https. Facebook sign-in and Meta webhooks need a public <b>https://</b> address.</div><?php endif; ?>

<div class="grid-2 grid-2-wide">
<form method="post" action="/admin/meta" class="card form" autocomplete="off">
  <?= csrf_field($app) ?>
  <h2>App credentials</h2>
  <label>App ID <input name="app_id" value="<?= e(old_value($old, 'app_id', $cfg['app_id'])) ?>" inputmode="numeric" class="mono"><?= field_error($errors, 'app_id') ?></label>
  <label>App Secret <input type="password" name="app_secret" autocomplete="new-password" placeholder="<?= $cfg['app_secret'] !== '' ? '•••••••• stored — leave blank to keep' : '' ?>"><?= field_error($errors, 'app_secret') ?></label>
  <label>Embedded Signup configuration ID <input name="config_id" value="<?= e(old_value($old, 'config_id', $cfg['config_id'])) ?>" inputmode="numeric" class="mono"><?= field_error($errors, 'config_id') ?></label>
  <p class="hint">Stored encrypted. Values in .env (META_APP_ID, …) are used only when these are empty.</p>
  <div class="form-actions"><button class="btn" type="submit">Save</button></div>
  <hr>
  <h2>Webhook (paste these into Meta)</h2>
  <label>Callback URL <span class="copy-row"><input readonly value="<?= e($callback) ?>" data-copy-source><button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></span></label>
  <label>Verify token <span class="copy-row"><input readonly value="<?= e($verify) ?>" class="mono" data-copy-source><button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></span></label>
  <label class="check"><input type="checkbox" name="rotate_verify" value="1"> <span>Generate a new verify token when saving <small class="muted">(update it in Meta afterwards)</small></span></label>
</form>
<div class="stack">
  <section class="card">
    <h2>Check</h2>
    <form method="post" action="/admin/meta/test"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Test App ID &amp; Secret with Meta</button></form>
    <p class="small muted"><?= (int) $connected ?> Meta WhatsApp number(s) connected on this platform.</p>
  </section>
  <section class="card">
    <h2>One-time setup in Meta</h2>
    <ol class="small setup-steps">
      <li>At <b>developers.facebook.com</b> create an app (type <b>Business</b>) linked to your verified Meta business portfolio, and add the <b>WhatsApp</b> product.</li>
      <li><b>App settings → Basic:</b> copy the App ID and App Secret here. Under <i>App domains</i> add <code><?= e($domain ?: 'your-domain') ?></code>, and add a privacy policy URL.</li>
      <li><b>Facebook Login for Business → Settings:</b> turn on <i>Login with the JavaScript SDK</i> and add <code>https://<?= e($domain ?: 'your-domain') ?></code> to <i>Allowed domains for the JavaScript SDK</i>.</li>
      <li><b>Facebook Login for Business → Configurations → Create:</b> choose <i>WhatsApp Embedded Signup</i>, select the WhatsApp product (this uses Embedded Signup v4) and the permissions <code>whatsapp_business_management</code> and <code>whatsapp_business_messaging</code>. Copy the <b>configuration ID</b> here.</li>
      <li><b>WhatsApp → Configuration → Webhook:</b> paste the Callback URL and Verify token shown here, then subscribe to <code>messages</code>, <code>message_template_status_update</code> and <code>account_update</code>.</li>
      <li>To let <b>other companies</b> connect (SaaS), complete Meta business verification and app review for those two permissions (become a <i>Tech Provider</i>). Until then only people with a role on your app can use the button.</li>
    </ol>
  </section>
</div>
</div>
