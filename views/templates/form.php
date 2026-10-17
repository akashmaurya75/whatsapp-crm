<?php
use App\Domain\Templates\TemplateService as TS;

$t = $template;
$this->layout('layouts/app', ['title' => $t ? $t['name'] : 'New template']);
$canManage = $app->can('templates.manage');
$edit = $editable && $canManage;
$v = static function (string $f, string $col = '') use ($old, $t): string {
    if (array_key_exists($f, $old)) {
        return is_scalar($old[$f]) ? (string) $old[$f] : '';
    }
    return $t ? (string) ($t[$col ?: $f] ?? '') : '';
};
$buttons = isset($old['buttons']) && is_array($old['buttons']) ? array_values($old['buttons']) : ($t ? TS::buttons($t) : []);
$samples = isset($old['body_samples']) && is_array($old['body_samples']) ? $old['body_samples'] : ($t ? array_combine(range(1, max(1, count(TS::bodySamples($t)))), array_pad(TS::bodySamples($t), max(1, count(TS::bodySamples($t))), '')) : []);
$headerType = $v('header_type') ?: 'none';
$category = $v('category') ?: 'marketing';
?>
<div class="page-head">
  <div><a class="small" href="/templates">← Templates</a>
    <h1><?= $t ? e($t['name']) : 'New template' ?></h1>
    <?php if ($t): ?><p><span class="pill pill-tpl-<?= e($t['status']) ?>"><?= e($t['status']) ?></span>
      <span class="muted small"><?= e(ucfirst((string) $t['category'])) ?> · <?= e(TS::LANGUAGES[$t['language']] ?? $t['language']) ?><?= $t['submitted_at'] ? ' · submitted ' . e(time_ago($t['submitted_at'])) : '' ?></span></p><?php endif; ?>
  </div>
  <?php if ($t && $canManage): ?>
  <div class="actions">
    <form method="post" action="/templates/<?= (int) $t['id'] ?>/duplicate"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Duplicate</button></form>
    <form method="post" action="/templates/<?= (int) $t['id'] ?>/delete" data-confirm="Delete this template<?= $t['provider_template_id'] ? ' here and in Meta' : '' ?>? This can't be undone."><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Delete</button></form>
  </div>
  <?php endif; ?>
</div>

<?php if ($t && $t['status'] === 'rejected'): ?>
  <div class="alert alert-error"><strong>Rejected by Meta:</strong> <?= e($t['rejected_reason'] ?: 'no reason given') ?>. Edit the template below and submit again, or duplicate it under a new name.</div>
<?php elseif ($t && $t['status'] === 'pending'): ?>
  <div class="alert">Waiting for Meta's review. This usually takes minutes, sometimes up to 24 hours. The status updates automatically, or use <b>Sync from Meta</b>.</div>
<?php elseif ($t && $t['status'] === 'approved'): ?>
  <div class="alert alert-success">Approved. Use it in <a href="/campaigns">Campaigns</a>, in the inbox, in automations or through the API.<?= TS::unsendableReason($t) ? ' Note: ' . e(TS::unsendableReason($t)) : '' ?></div>
<?php endif; ?>
<?php foreach (['status', 'delete'] as $k): ?><?= field_error($errors, $k) ?><?php endforeach; ?>

<div class="tpl-builder">
<form method="post" action="<?= $t ? '/templates/' . (int) $t['id'] : '/templates' ?>" enctype="multipart/form-data" class="card form" data-tpl-form>
  <?= csrf_field($app) ?>
  <fieldset class="form"<?= $edit ? '' : ' disabled' ?>>
  <div class="form-grid">
    <label>WhatsApp number
      <select name="whatsapp_account_id" required><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $v('whatsapp_account_id') === (string) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?></select>
      <?= field_error($errors, 'whatsapp_account_id') ?></label>
    <label>Template name <input name="name" value="<?= e($v('name')) ?>" placeholder="diwali_offer_2026" pattern="[a-z0-9_]+" maxlength="191" required class="mono" data-tpl-name>
      <small class="hint">Lowercase letters, numbers and _ only.</small><?= field_error($errors, 'name') ?></label>
    <label>Category
      <select name="category" data-tpl-category>
        <?php foreach (TS::CATEGORIES as $k => $l): ?><option value="<?= $k ?>"<?= $category === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select>
      <small class="hint" data-cat-hint>Marketing: offers, announcements. Utility: order, payment and delivery updates.</small><?= field_error($errors, 'category') ?></label>
    <label>Language
      <select name="language"><?php foreach (TS::LANGUAGES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($v('language') ?: 'en') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <?= field_error($errors, 'language') ?></label>
  </div>

  <h2 class="install-h2">Header <small class="muted">optional</small></h2>
  <div class="seg seg-inline" role="radiogroup" aria-label="Header type">
    <?php foreach (TS::HEADER_TYPES as $k => $l): ?>
      <label class="seg-opt"><input type="radio" name="header_type" value="<?= $k ?>"<?= $headerType === $k ? ' checked' : '' ?> data-header-type><span><?= $l ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="form-grid" data-header-panel="text">
    <label>Header text <input name="header_text" value="<?= e($v('header_text')) ?>" maxlength="60" data-preview-src="header_text"><small class="hint">Max 60 characters, one {{1}} allowed.</small><?= field_error($errors, 'header_text') ?></label>
    <label>Example for {{1}} <input name="header_sample" value="<?= e($v('header_sample')) ?>" maxlength="200"><?= field_error($errors, 'header_sample') ?></label>
  </div>
  <div data-header-panel="media">
    <label>Sample file <input type="file" name="header_media" accept="image/jpeg,image/png,video/mp4,application/pdf">
      <small class="hint">Meta reviews this sample. JPG/PNG up to 5 MB, MP4 up to 16 MB, PDF up to 16 MB. When sending, you choose the actual file.<?= $t && $t['header_media_handle'] ? ' A sample is already uploaded.' : '' ?></small>
      <?= field_error($errors, 'header_media') ?></label>
  </div>

  <h2 class="install-h2">Body</h2>
  <label class="sr-only" for="tpl-body">Message body</label>
  <div class="editor-tools">
    <button type="button" class="btn btn-ghost btn-sm" data-insert-var>+ Add variable</button>
    <button type="button" class="btn btn-ghost btn-sm" data-wrap="*" title="Bold"><b>B</b></button>
    <button type="button" class="btn btn-ghost btn-sm" data-wrap="_" title="Italic"><i>I</i></button>
    <button type="button" class="btn btn-ghost btn-sm" data-wrap="~" title="Strikethrough"><s>S</s></button>
    <span class="muted small" data-count>0/1024</span>
  </div>
  <textarea id="tpl-body" name="body" rows="7" maxlength="1024" required data-tpl-body placeholder="Hi {{1}}, your order {{2}} has been shipped and will reach you by {{3}}. Thank you for shopping with us!"><?= e($v('body')) ?></textarea>
  <?= field_error($errors, 'body') ?>
  <div data-samples data-initial='<?= e(json_encode((object) $samples)) ?>'>
    <?php foreach ($samples as $n => $sv): ?>
      <label>Example for {{<?= (int) $n ?>}} <input name="body_samples[<?= (int) $n ?>]" value="<?= e((string) $sv) ?>" maxlength="200"></label>
    <?php endforeach; ?>
  </div>
  <?= field_error($errors, 'body_samples') ?>

  <label><span>Footer <small class="muted">optional</small></span> <input name="footer" value="<?= e($v('footer')) ?>" maxlength="60" placeholder="Reply STOP to unsubscribe" data-tpl-footer>
    <?= field_error($errors, 'footer') ?></label>

  <h2 class="install-h2">Buttons <small class="muted">optional, up to 10</small></h2>
  <div class="btn-rows" data-buttons>
    <?php for ($i = 0; $i < 10; $i++): $b = $buttons[$i] ?? ['type' => '', 'text' => '', 'value' => '']; ?>
      <div class="btn-row"<?= $i >= max(3, count($buttons)) ? ' data-extra hidden' : '' ?>>
        <select name="buttons[<?= $i ?>][type]" aria-label="Button <?= $i + 1 ?> type" data-btn-type>
          <option value="">—</option>
          <option value="quick_reply"<?= $b['type'] === 'quick_reply' ? ' selected' : '' ?>>Quick reply</option>
          <option value="url"<?= $b['type'] === 'url' ? ' selected' : '' ?>>Visit website</option>
          <option value="phone"<?= $b['type'] === 'phone' ? ' selected' : '' ?>>Call phone</option>
          <?php if ($b['type'] === 'other'): ?><option value="other" selected>Other (<?= e($b['value']) ?>)</option><?php endif; ?>
        </select>
        <input name="buttons[<?= $i ?>][text]" value="<?= e($b['text']) ?>" maxlength="25" placeholder="Button label" aria-label="Button <?= $i + 1 ?> label" data-btn-text>
        <input name="buttons[<?= $i ?>][value]" value="<?= e($b['value']) ?>" placeholder="https://… or +91…" aria-label="Button <?= $i + 1 ?> link or phone" data-btn-value>
      </div>
    <?php endfor; ?>
  </div>
  <button type="button" class="btn btn-ghost btn-sm" data-more-buttons>+ More buttons</button>
  <?= field_error($errors, 'buttons') ?>
  <p class="hint">Website links may end with {{1}} for a personal part, e.g. https://shop.com/track/{{1}}.</p>
  </fieldset>

  <?php if ($edit): ?>
  <div class="form-actions sticky-actions">
    <button class="btn btn-ghost" type="submit" name="intent" value="draft">Save draft</button>
    <button class="btn" type="submit" name="intent" value="submit" data-confirm-click="Submit this template to Meta for approval?">Submit for approval</button>
  </div>
  <?php endif; ?>
</form>

<aside class="tpl-preview-col" aria-label="Preview">
  <div class="phone">
    <div class="phone-top"><span class="avatar">W</span><span><b><?= e(env('APP_NAME', 'Business')) ?></b><br><small>WhatsApp Business</small></span></div>
    <div class="phone-chat">
      <div class="wa-bubble" data-preview>
        <div class="wa-media" data-p-media hidden>🖼</div>
        <div class="wa-header" data-p-header></div>
        <div class="wa-body" data-p-body></div>
        <div class="wa-footer" data-p-footer></div>
        <div class="wa-time">12:00</div>
      </div>
      <div class="wa-buttons" data-p-buttons></div>
    </div>
  </div>
  <ul class="tips small">
    <li>Templates with clear, specific wording get approved faster.</li>
    <li>Don't put a variable at the very start or end of the message.</li>
    <li>Give realistic examples (Meta reviews with them).</li>
    <li>Utility templates are cheaper but must relate to a transaction the customer made.</li>
  </ul>
</aside>
</div>
