<?php
/** @var App\WhatsApp\Provider $provider */
$this->layout('layouts/app', ['title' => $account ? $account['name'] : 'Connect account']);
$isMeta = $provider->key() === 'meta_cloud';
$editable = $account === null || !empty($canManage);
$val = static fn (string $f, ?string $col = null) => old_value($old, $f, $account && $col ? ($account[$col] ?? '') : '');
?>
<div class="page-head">
  <div><a class="small" href="/whatsapp">← WhatsApp accounts</a>
    <h1><?= $account ? e($account['name']) : 'Connect ' . e($provider->label()) ?></h1>
    <?php if ($account): ?><p><span class="pill pill-<?= e($account['status']) ?>"><?= e($account['status']) ?></span> <span class="muted small">last sync <?= e(time_ago($account['last_synced_at'])) ?></span></p><?php endif; ?>
  </div>
  <?php if ($account && $editable): ?>
  <form method="post" action="/whatsapp/<?= (int) $account['id'] ?>/test"><?= csrf_field($app) ?><button class="btn" type="submit">Test connection</button></form>
  <?php endif; ?>
</div>

<?php if (!$provider->isOfficial()): ?>
<div class="alert alert-warn">
  <strong>Unofficial provider.</strong> An OpenWA gateway automates a WhatsApp Web session. It is not the Meta WhatsApp Business Platform,
  may break when WhatsApp changes, and using it can violate WhatsApp's Terms of Service and get the number banned.
  Use the Meta Cloud API for production business messaging.
</div>
<?php endif; ?>

<div class="grid-2 grid-2-wide">
<form method="post" action="<?= $account ? '/whatsapp/' . (int) $account['id'] : '/whatsapp' ?>" class="card form form-grid" autocomplete="off">
  <?= csrf_field($app) ?>
  <input type="hidden" name="provider" value="<?= e($provider->key()) ?>">
  <?php $managed = $account && App\WhatsApp\AccountRepository::isManaged($account); ?>
  <fieldset class="span-2 form-grid"<?= $editable ? '' : ' disabled' ?>>
  <label>Display name <input name="name" value="<?= e($val('name', 'name')) ?>" required maxlength="120"><?= field_error($errors, 'name') ?></label>
  <label>Phone number <input name="phone" value="<?= e($val('phone', 'phone_e164')) ?>" placeholder="+91…" maxlength="32"><?= field_error($errors, 'phone') ?></label>
  <?php if ($isMeta): ?>
    <label>Phone number ID <input name="phone_number_id" value="<?= e($val('phone_number_id', 'phone_number_id')) ?>" inputmode="numeric" required<?= $managed ? ' readonly' : '' ?>><?= field_error($errors, 'phone_number_id') ?></label>
    <label>WhatsApp Business Account ID <input name="business_account_id" value="<?= e($val('business_account_id', 'business_account_id')) ?>" inputmode="numeric"<?= $managed ? ' readonly' : '' ?>><?= field_error($errors, 'business_account_id') ?></label>
  <?php else: ?>
    <label class="span-2">Gateway base URL <input name="base_url" type="url" value="<?= e($val('base_url', 'base_url')) ?>" placeholder="https://wa-gateway.internal:8002" required><?= field_error($errors, 'base_url') ?></label>
  <?php endif; ?>
  <?php $storedCreds = $account ? App\WhatsApp\AccountRepository::credentials($account) : []; ?>
  <?php if ($managed): ?>
    <div class="span-2 alert alert-success small">✓ Connected with Facebook. Access is managed automatically.
      <?php if ($editable): ?><a href="/whatsapp/connect">Reconnect with Facebook</a> if Meta access was removed or the token stopped working.<?php endif; ?></div>
  <?php endif; ?>
  <?php foreach ($managed ? [] : $provider->credentialFields() as $field => [$label, $secret, $required]): ?>
    <label class="span-2"><?= e($label) ?><?= $required ? '' : ' <small class="muted">(optional)</small>' ?>
      <?php if ($secret): ?>
      <input type="password" name="cred_<?= e($field) ?>" autocomplete="new-password" <?= $account && ($storedCreds[$field] ?? '') !== '' ? 'placeholder="•••••••• stored — leave blank to keep"' : '' ?>>
      <?php else: ?>
      <input type="text" name="cred_<?= e($field) ?>" value="<?= e($storedCreds[$field] ?? '') ?>" inputmode="numeric" autocomplete="off">
      <?php endif; ?>
      <?= field_error($errors, 'cred_' . $field) ?>
    </label>
  <?php endforeach; ?>
  </fieldset>
  <p class="span-2 hint">Credentials are encrypted at rest and never shown again.</p>
  <?php if ($editable): ?><div class="span-2 form-actions"><button class="btn" type="submit"><?= $account ? 'Save changes' : 'Save account' ?></button></div><?php endif; ?>
</form>

<div class="stack">
  <?php if ($account && $managed): ?>
  <section class="card">
    <h2>Webhook</h2>
    <p class="small">Incoming messages and template approvals arrive automatically through the platform's Meta app. Nothing to configure.</p>
  </section>
  <?php endif; ?>
  <?php if ($account && $isMeta): ?>
  <section class="card" id="receiving">
    <h2>Receiving messages</h2>
    <ul class="list small">
      <li><span>Last delivery from Meta</span><b><?= $account['last_webhook_at'] ? e(time_ago($account['last_webhook_at'])) : 'never' ?></b></li>
      <?php if (!empty($account['last_webhook_result'])): ?><li><span>Result</span><span class="<?= str_starts_with((string) $account['last_webhook_result'], 'received') ? '' : 'field-error' ?>"><?= e($account['last_webhook_result']) ?></span></li><?php endif; ?>
      <li><span>Last customer message</span><b><?= $account['last_message_in_at'] ? e(time_ago($account['last_message_in_at'])) : 'never' ?></b></li>
    </ul>
    <?php if (!empty($diagnosis)): ?>
      <ul class="checklist diag">
        <?php foreach ($diagnosis as $d): ?>
          <li class="<?= $d['state'] === 'ok' ? 'ok' : ($d['state'] === 'fail' ? 'bad' : 'warn') ?>">
            <span class="check-icon" aria-hidden="true"><?= $d['state'] === 'ok' ? '✓' : ($d['state'] === 'fail' ? '✕' : '!') ?></span>
            <span><?= e($d['label']) ?><?= !empty($d['fixed']) ? ' <b class="pill pill-connected">fixed</b>' : '' ?><br><small class="<?= $d['state'] === 'fail' ? 'field-error' : 'muted' ?>"><?= e($d['detail']) ?></small></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="small muted">Messages not showing up in the Inbox? This checks your Meta setup step by step and fixes the webhook subscription automatically.</p>
    <?php endif; ?>
    <?php if ($editable): ?>
    <form method="post" action="/whatsapp/<?= (int) $account['id'] ?>/diagnose"><?= csrf_field($app) ?><button class="btn btn-sm" type="submit">Check and fix</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($account && !$managed): ?>
  <section class="card">
    <h2>Webhook</h2>
    <p class="small"><?= $isMeta ? 'In Meta App Dashboard → WhatsApp → Configuration, set this Callback URL and your verify token, then subscribe to the <b>messages</b> field.' : 'Configure your OpenWA gateway to POST events to this URL (e.g. <code>--webhook</code>).' ?></p>
    <div class="copy-row"><input readonly value="<?= e($webhookUrl) ?>" aria-label="Webhook URL" data-copy-source><button type="button" class="btn btn-ghost btn-sm" data-copy>Copy</button></div>
    <?php if ($editable): ?>
    <form method="post" action="/whatsapp/<?= (int) $account['id'] ?>/rotate-webhook" data-confirm="Rotate the webhook URL? The old URL stops working immediately."><?= csrf_field($app) ?><button class="btn btn-ghost btn-sm" type="submit">Rotate URL</button></form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <section class="card">
    <h2>Capabilities</h2>
    <?php $caps = array_map(static fn ($c) => $c->value, $provider->capabilities()); ?>
    <ul class="caps small"><?php foreach (App\WhatsApp\Capability::cases() as $cap): ?><li class="<?= in_array($cap->value, $caps, true) ? 'cap-on' : 'cap-off' ?>"><?= e($cap->label()) ?></li><?php endforeach; ?></ul>
    <?php if ($isMeta): ?><p class="muted small">The Cloud API does not expose group management, message editing or typing indicators, so those stay disabled.</p><?php endif; ?>
  </section>
  <?php if ($account && !empty($recentErrors)): ?>
  <section class="card">
    <h2>Recent send errors</h2>
    <ul class="list small"><?php foreach ($recentErrors as $er): ?><li><span><b><?= e($er['error_code']) ?></b> <?= e($er['error_message']) ?></span><span class="muted"><?= e(time_ago($er['updated_at'])) ?></span></li><?php endforeach; ?></ul>
  </section>
  <?php endif; ?>
  <?php if ($account && $editable): ?>
  <section class="card danger-zone">
    <h2>Delete account</h2>
    <p class="small">Deletes this account and all of its conversations and messages. Type <b><?= e($account['name']) ?></b> to confirm.</p>
    <form method="post" action="/whatsapp/<?= (int) $account['id'] ?>/delete" class="inline-form"><?= csrf_field($app) ?>
      <input name="confirm" aria-label="Account name" required><button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
    <?= field_error($errors, 'confirm') ?>
  </section>
  <?php endif; ?>
</div>
</div>
