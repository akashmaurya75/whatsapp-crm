<?php $this->layout('layouts/app', ['title' => 'Connect WhatsApp']); ?>
<div class="page-head"><div><a class="small" href="/whatsapp">← WhatsApp accounts</a><h1>Connect your WhatsApp number</h1>
  <p class="muted">Sign in with Facebook, choose your business and number, and we set up everything else automatically.</p></div></div>

<?php if (!empty($reconnect) && !$choice): ?>
  <div class="alert alert-warn"><strong>Reconnecting <?= e($reconnect['name']) ?> <span class="mono"><?= e($reconnect['phone_e164'] ?? '') ?></span>.</strong>
    In the Facebook window, pick the same business and number. Your conversations, templates and settings are kept,
    and paused campaigns can be resumed afterwards.</div>
<?php endif; ?>

<?php if ($choice): ?>
  <section class="card connect-card">
    <h2>Which number do you want to connect?</h2>
    <p class="muted small">Your WhatsApp Business account has more than one number. Choose one; you can connect another later.</p>
    <form method="post" action="/whatsapp/connect/choose" class="form">
      <?= csrf_field($app) ?>
      <?php foreach ($choice['options'] as $o): ?>
        <label class="choice"><input type="radio" name="phone_number_id" value="<?= e($o['id']) ?>" required>
          <span><b><?= e($o['display_phone_number']) ?></b> · <?= e($o['verified_name']) ?><br><small class="muted">Business account <?= e($o['waba_id']) ?><?= $o['quality_rating'] ? ' · quality ' . e(strtolower((string) $o['quality_rating'])) : '' ?></small></span></label>
      <?php endforeach; ?>
      <?= field_error($errors, 'phone_number_id') ?>
      <div class="form-actions"><button class="btn" type="submit">Connect this number</button></div>
    </form>
    <form method="post" action="/whatsapp/connect/cancel"><?= csrf_field($app) ?><button class="link-btn" type="submit">Start over</button></form>
  </section>
<?php elseif (!$ready): ?>
  <section class="card connect-card">
    <h2>Facebook sign-in isn't set up yet</h2>
    <p>The platform administrator needs to add the Meta app once (Admin → <b>Meta app</b>). Until then you can connect manually with your own access token.</p>
    <a class="btn btn-ghost" href="/whatsapp/new?provider=meta_cloud">Connect manually instead</a>
  </section>
<?php else: ?>
  <section class="card connect-card" data-meta-signup data-app-id="<?= e($appId) ?>" data-config-id="<?= e($configId) ?>" data-version="<?= e($version) ?>" data-post-url="/whatsapp/connect">
    <div class="connect-hero">
      <button type="button" class="btn btn-fb" data-fb-login disabled>
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>
        Continue with Facebook
      </button>
      <p class="alert small" data-fb-status>Loading Facebook…</p>
      <?php if (!$https): ?><p class="alert alert-warn small">Facebook requires this page to be opened over <b>https://</b>. On your computer, use a tunnel (see the install guide).</p><?php endif; ?>
    </div>
    <ol class="connect-steps">
      <li><b>Sign in</b> with the Facebook account that manages your business.</li>
      <li><b>Choose or create</b> your Meta business portfolio and WhatsApp Business account.</li>
      <li><b>Add your number</b> and verify it with the code WhatsApp sends (SMS or call). The number must not be in use on the WhatsApp app; delete that account first, or use a new number.</li>
      <li>Done. We subscribe to your messages, register the number, import your templates and open your new account.</li>
    </ol>
    <details class="small">
      <summary>Advanced: number already has a two-step verification PIN?</summary>
      <label>Existing 6-digit PIN <input data-fb-pin inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="off"></label>
      <p class="hint">Leave empty for new numbers; we create a PIN for you and store it encrypted.</p>
    </details>
  </section>
  <script src="<?= e(asset('meta-signup.js')) ?>" defer></script>
<?php endif; ?>
<p class="muted small">Meta bills WhatsApp conversations directly to your business. Add a payment method in WhatsApp Manager after connecting to send template messages.</p>
