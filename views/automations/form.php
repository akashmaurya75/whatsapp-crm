<?php
use App\Domain\Automation\AutomationService as Auto;

$this->layout('layouts/app', ['title' => Auto::TYPES[$type]]);
$o = static fn (string $k, mixed $d = '') => array_key_exists($k, $old) ? $old[$k] : $d;
$reply = $cfg['reply'] ?? ['type' => 'text', 'text' => ''];
$replyType = (string) (($o('reply', []))['type'] ?? ($template ? 'template' : ($reply['type'] === 'none' ? 'text' : $reply['type'])));
$replyText = (string) (($o('reply', []))['text'] ?? ($reply['text'] ?? ''));
$hours = $cfg['hours'] ?? Auto::defaultHours();
$actions = $cfg['actions'] ?? [];
$action = $rule ? '/automations/' . (int) $rule['id'] : '/automations';
$selfUrl = $rule ? '/automations/' . (int) $rule['id'] : '/automations/new?type=' . $type;
?>
<div class="page-head"><div><a class="small" href="/automations">← Automations</a><h1><?= e($rule ? $rule['name'] : Auto::TYPES[$type]) ?></h1></div>
  <?php if ($rule): ?><form method="post" action="/automations/<?= (int) $rule['id'] ?>/delete" data-confirm="Delete this automation?"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Delete</button></form><?php endif; ?>
</div>
<?= field_error($errors, 'type') ?>

<form method="post" action="<?= e($action) ?>" class="card form auto-form">
  <?= csrf_field($app) ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <div class="form-grid">
    <label>Name <input name="name" value="<?= e((string) $o('name', $rule['name'] ?? Auto::TYPES[$type])) ?>" maxlength="120"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= ($old ? !empty($old['is_active']) : (int) ($rule['is_active'] ?? 1) === 1) ? ' checked' : '' ?>> <span>Active</span></label>
  </div>

  <?php if ($type === 'keyword'): ?>
    <h2 class="install-h2">When a message…</h2>
    <div class="form-grid">
      <label>Match
        <select name="match"><?php foreach (Auto::MATCH as $k => $l): ?><option value="<?= $k ?>"<?= (string) $o('match', $cfg['match'] ?? 'contains') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Priority <input type="number" name="priority" min="1" max="1000" value="<?= (int) $o('priority', $rule['priority'] ?? 100) ?>"><small class="hint">Lower runs first.</small></label>
      <label class="span-2">Keywords <textarea name="keywords" rows="2" placeholder="price, rate, cost, kitna" required><?= e((string) $o('keywords', implode(', ', (array) ($cfg['keywords'] ?? [])))) ?></textarea>
        <small class="hint">Separate with commas. Not case-sensitive. "Contains" matches whole words.</small><?= field_error($errors, 'keywords') ?></label>
    </div>
  <?php endif; ?>

  <?php if ($type === 'away'): ?>
    <h2 class="install-h2">Business hours</h2>
    <label>Time zone <select name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option<?= (string) $o('timezone', $cfg['timezone'] ?? $tz) === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
    <div class="hours">
      <?php foreach (Auto::DAYS as $k => $d): $h = $hours[$k]; ?>
        <div class="hours-row">
          <label class="check"><input type="checkbox" name="hours[<?= $k ?>][open]" value="1"<?= !empty($h['open']) ? ' checked' : '' ?>> <span><?= e($d) ?></span></label>
          <input type="time" name="hours[<?= $k ?>][start]" value="<?= e($h['start']) ?>" aria-label="<?= e($d) ?> opens">
          <span class="muted">to</span>
          <input type="time" name="hours[<?= $k ?>][end]" value="<?= e($h['end']) ?>" aria-label="<?= e($d) ?> closes">
        </div>
      <?php endforeach; ?>
    </div>
    <?= field_error($errors, 'hours') ?><?= field_error($errors, 'timezone') ?>
    <p class="hint">Unticked days are closed all day. If closing time is before opening time, hours run past midnight.</p>
  <?php endif; ?>

  <h2 class="install-h2">Reply</h2>
  <div class="choice-group">
    <label class="choice"><input type="radio" name="reply[type]" value="text"<?= $replyType === 'text' ? ' checked' : '' ?> data-reply-type><span><b>Text message</b><br><small class="muted">Free text (the customer just wrote, so the 24-hour window is open).</small></span></label>
    <label class="choice"><input type="radio" name="reply[type]" value="template"<?= $replyType === 'template' ? ' checked' : '' ?> data-reply-type><span><b>Approved template</b><br><small class="muted">Use one with buttons or media.</small></span></label>
  </div>
  <div data-reply-panel="text"<?= $replyType === 'text' ? '' : ' hidden' ?>>
    <textarea name="reply[text]" rows="4" maxlength="4096" placeholder="<?= $type === 'away' ? "Thanks for reaching out! We're closed right now and will reply first thing tomorrow." : ($type === 'welcome' ? 'Hi! 👋 Welcome to our store. How can we help you today?' : 'Here is our latest price list: https://…') ?>"><?= e($replyText) ?></textarea>
    <?php if ($type === 'keyword'): ?><p class="hint">Leave empty to only run the actions below.</p><?php endif; ?>
  </div>
  <div data-reply-panel="template"<?= $replyType === 'template' ? '' : ' hidden' ?>>
    <?php if ($templates === []): ?><p class="muted small">No approved templates yet. <a href="/templates/new">Create one</a>.</p><?php else: ?>
      <div class="inline-form">
        <select name="reply[template_id]" aria-label="Template" data-template-picker data-base-url="<?= e($selfUrl . (str_contains($selfUrl, '?') ? '&' : '?') . 'template=') ?>">
          <option value="">Choose a template…</option>
          <?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"<?= $template && (int) $template['id'] === (int) $tp['id'] ? ' selected' : '' ?>><?= e($tp['name']) ?></option><?php endforeach; ?>
        </select>
        <noscript><span class="hint">Pick a template, save, then fill its variables.</span></noscript>
      </div>
      <?php if ($template): ?><?= $this->partial('partials/var-map', ['t' => $template, 'map' => $map, 'prefix' => 'reply[map]', 'errors' => $errors]) ?><?php endif; ?>
    <?php endif; ?>
  </div>
  <?= field_error($errors, 'reply') ?>

  <?php if ($type === 'keyword'): ?>
    <h2 class="install-h2">Also do</h2>
    <div class="form-grid">
      <label>Add tags <input name="actions[add_tags]" value="<?= e(implode(', ', (array) ($actions['add_tags'] ?? []))) ?>" placeholder="interested, price-enquiry"></label>
      <label>Set lead status <select name="actions[lead_status]"><option value="">Don't change</option><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= ($actions['lead_status'] ?? '') === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></label>
      <label>Assign to team <select name="actions[assign_team_id]"><option value="">—</option><?php foreach ($teams as $tm): ?><option value="<?= (int) $tm['id'] ?>"<?= (int) ($actions['assign_team_id'] ?? 0) === (int) $tm['id'] ? ' selected' : '' ?>><?= e($tm['name']) ?></option><?php endforeach; ?></select></label>
      <label>Assign to agent <select name="actions[assign_user_id]"><option value="">—</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) ($actions['assign_user_id'] ?? 0) === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
    </div>
    <?= field_error($errors, 'actions') ?>
  <?php endif; ?>

  <?php if ($type !== 'welcome'): ?>
    <label>Don't repeat for the same contact within
      <select name="cooldown_minutes"><?php foreach ([0 => 'No limit', 30 => '30 minutes', 60 => '1 hour', 240 => '4 hours', 720 => '12 hours', 1440 => '24 hours'] as $m => $l): ?><option value="<?= $m ?>"<?= (int) $o('cooldown_minutes', $rule['cooldown_minutes'] ?? ($type === 'away' ? 240 : 0)) === $m ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <div class="form-actions"><button class="btn" type="submit">Save automation</button><a class="btn btn-ghost" href="/automations">Cancel</a></div>
</form>
