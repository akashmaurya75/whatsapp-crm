<?php
use App\Domain\Campaigns\CampaignService as CS;
use App\Domain\Templates\TemplateService as TS;

$this->layout('layouts/app', ['title' => $c['name']]);
$a = isset($old['tags_any']) || isset($old['lead_statuses']) ? [
    'tags_any' => (array) ($old['tags_any'] ?? []), 'tags_none' => (array) ($old['tags_none'] ?? []),
    'lead_statuses' => (array) ($old['lead_statuses'] ?? []), 'active_days' => (int) ($old['active_days'] ?? 0),
] : $audience;
$mapNow = isset($old['map']) && is_array($old['map']) ? array_replace($map, $old['map']) : $map;
$local = $c['scheduled_at'] ? (new DateTimeImmutable($c['scheduled_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz)) : null;
$mode = old_value($old, 'send_mode', $c['scheduled_at'] ? 'later' : 'now');
$requireConsent = array_key_exists('require_consent', $old) ? true : ($old === [] ? (int) $c['require_consent'] === 1 : false);
$elig = (int) $preview['eligible'];
?>
<div class="page-head">
  <div><a class="small" href="/campaigns">← Campaigns</a><h1><?= e($c['name']) ?></h1>
    <p class="muted small">Template <a class="mono" href="/templates/<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a> · <?= e($t['category']) ?> · <span class="pill pill-camp-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></p></div>
  <div class="actions">
    <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/delete" data-confirm="Delete this draft campaign?"><?= csrf_field($app) ?><button class="btn btn-ghost" type="submit">Delete draft</button></form>
  </div>
</div>
<?php if ($c['status'] === 'scheduled'): ?><div class="alert">This campaign is scheduled. Saving changes moves it back to draft, so launch it again afterwards.</div><?php endif; ?>

<div class="camp-layout">
<form method="post" action="/campaigns/<?= (int) $c['id'] ?>" enctype="multipart/form-data" class="form" data-campaign-form>
  <?= csrf_field($app) ?>
  <section class="card step-card">
    <h2><span class="step-no">1</span> Audience</h2>
    <p class="muted small">Leave everything empty to send to all active contacts. Filters combine with AND.</p>
    <div class="form-grid">
      <label>Has any of these tags
        <select name="tags_any[]" multiple size="<?= min(6, max(3, count($tags))) ?>"><?php foreach ($tags as $tg): ?><option<?= in_array($tg, $a['tags_any'], true) ? ' selected' : '' ?>><?= e($tg) ?></option><?php endforeach; ?></select></label>
      <label>Exclude contacts tagged
        <select name="tags_none[]" multiple size="<?= min(6, max(3, count($tags))) ?>"><?php foreach ($tags as $tg): ?><option<?= in_array($tg, $a['tags_none'], true) ? ' selected' : '' ?>><?= e($tg) ?></option><?php endforeach; ?></select></label>
      <label>Lead status
        <select name="lead_statuses[]" multiple size="5"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= in_array($s, $a['lead_statuses'], true) ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></label>
      <label>Only contacts who messaged us in the last
        <select name="active_days"><?php foreach ([0 => 'Any time', 7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '180 days', 365 => '1 year'] as $d => $l): ?><option value="<?= $d ?>"<?= (int) $a['active_days'] === $d ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    </div>
    <p class="hint">Ctrl/⌘-click to select several. Need a list from elsewhere? <a href="/contacts/import">Import a CSV</a> with a tag, then pick that tag here.</p>
  </section>

  <section class="card step-card">
    <h2><span class="step-no">2</span> Personalise</h2>
    <?= $this->partial('partials/var-map', ['t' => $t, 'map' => $mapNow, 'prefix' => 'map', 'errors' => $errors]) ?>
    <?php if (TS::needsMedia($t)): ?>
      <div class="form-grid">
        <label>Upload <?= e($t['header_type']) ?> <input type="file" name="header_media" accept="<?= e(implode(',', TS::MEDIA_LIMITS[$t['header_type']]['mimes'])) ?>">
          <?php if ($c['header_media_id']): ?><small class="hint">✓ A file is uploaded. Choose another to replace it.</small><?php endif; ?></label>
        <label>…or a public https link <input name="header_media_url" value="<?= e(old_value($old, 'header_media_url', (string) $c['header_media_url'])) ?>" placeholder="https://yourshop.com/offer.jpg"></label>
      </div>
      <?= field_error($errors, 'header_media') ?><?= field_error($errors, 'header_media_url') ?>
    <?php endif; ?>
  </section>

  <section class="card step-card">
    <h2><span class="step-no">3</span> Safeguards</h2>
    <label class="check"><input type="checkbox" name="require_consent" value="1"<?= $requireConsent ? ' checked' : '' ?> data-consent-toggle>
      <span>Only send to contacts with recorded WhatsApp consent <small class="muted">(recommended; required by WhatsApp policy for marketing)</small></span></label>
    <label class="check" data-attest<?= $requireConsent ? ' hidden' : '' ?>><input type="checkbox" name="consent_attested" value="1"<?= $c['consent_attested_by'] ? ' checked' : '' ?>>
      <span>I confirm everyone in this audience agreed to receive WhatsApp messages from us. <small class="muted">(This confirmation is recorded in the audit log.)</small></span></label>
    <?= field_error($errors, 'consent_attested') ?>
    <div class="form-grid">
      <label>Skip contacts who got another campaign in the last
        <select name="frequency_cap_hours"><?php foreach ([0 => 'No limit', 12 => '12 hours', 24 => '24 hours', 48 => '2 days', 72 => '3 days', 168 => '7 days'] as $h => $l): ?><option value="<?= $h ?>"<?= (int) old_value($old, 'frequency_cap_hours', (string) $c['frequency_cap_hours']) === $h ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Sending speed
        <select name="rate_per_minute"><?php foreach ([60 => '60 / minute', 300 => '300 / minute', 1000 => '1,000 / minute', 3000 => '3,000 / minute'] as $r => $l): ?><option value="<?= $r ?>"<?= (int) old_value($old, 'rate_per_minute', (string) $c['rate_per_minute']) === $r ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <small class="hint">Opted-out contacts are always excluded. Meta's daily limit for your number still applies.</small></label>
    </div>
  </section>

  <section class="card step-card">
    <h2><span class="step-no">4</span> When</h2>
    <div class="choice-group">
      <label class="choice"><input type="radio" name="send_mode" value="now"<?= $mode === 'now' ? ' checked' : '' ?> data-send-mode><span><b>Send now</b><br><small class="muted">Starts as soon as you launch.</small></span></label>
      <label class="choice"><input type="radio" name="send_mode" value="later"<?= $mode === 'later' ? ' checked' : '' ?> data-send-mode><span><b>Schedule</b><br><small class="muted">Pick a date and time (<?= e($tz) ?>).</small></span></label>
    </div>
    <label data-schedule-at<?= $mode === 'later' ? '' : ' hidden' ?>>Send at <input type="datetime-local" name="scheduled_at" value="<?= e(old_value($old, 'scheduled_at', $local ? $local->format('Y-m-d\TH:i') : '')) ?>"></label>
    <?= field_error($errors, 'scheduled_at') ?>
  </section>
  <div class="form-actions"><button class="btn" type="submit">Save &amp; review</button></div>
</form>

<aside class="camp-side">
  <section class="card" id="review">
    <h2>Review</h2>
    <div class="audience-big"><strong><?= number_format($elig) ?></strong><span>contacts will receive this</span></div>
    <ul class="statlist small">
      <li><span>Matching the audience</span><span><?= number_format((int) $preview['matched']) ?></span><span></span></li>
      <?php foreach ($preview['excluded'] as $reason => $n): ?>
        <li><span class="muted">− <?= e(CS::SKIP_REASONS[$reason] ?? $reason) ?></span><span><?= number_format($n) ?></span><span></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if (isset($preview['excluded']['no_consent']) && $preview['excluded']['no_consent'] > 0): ?>
      <p class="hint">Tip: record consent on contacts (Contacts → consent) or import them with a consent column.</p>
    <?php endif; ?>
    <?php if ($preview['samples'] !== []): ?>
      <h3>Preview for <?= e($preview['samples'][0]['contact']['name'] ?: $preview['samples'][0]['contact']['phone_e164']) ?></h3>
      <div class="wa-bubble wa-static"><?= wa_format($preview['samples'][0]['text']) ?></div>
    <?php endif; ?>
    <?php if ($problems !== []): ?>
      <div class="alert alert-warn small"><b>Before launching:</b><ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?= field_error($errors, 'launch') ?>
    <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/action" data-confirm="<?= $c['scheduled_at'] ? 'Schedule' : 'Send' ?> this campaign to <?= number_format($elig) ?> contacts?">
      <?= csrf_field($app) ?><input type="hidden" name="action" value="launch">
      <button class="btn btn-block" type="submit"<?= $problems !== [] || $c['status'] !== 'draft' ? ' disabled' : '' ?>><?= $c['scheduled_at'] ? '📅 Schedule campaign' : '🚀 Send now' ?></button>
    </form>
    <?php if ($c['status'] === 'draft' && $c['scheduled_at']): ?><p class="hint">Planned for <?= e($local?->format('j M Y, H:i')) ?> (<?= e($tz) ?>).</p><?php endif; ?>
  </section>
  <section class="card">
    <h3>Send a test</h3>
    <form method="post" action="/campaigns/<?= (int) $c['id'] ?>/test" class="inline-form">
      <?= csrf_field($app) ?>
      <input name="test_phone" placeholder="+91 98765 43210" required aria-label="Test phone number" value="<?= e(old_value($old, 'test_phone')) ?>">
      <button class="btn btn-ghost btn-sm" type="submit">Send test</button>
    </form>
    <?= field_error($errors, 'test_phone') ?>
    <p class="hint">Uses the saved settings. Not counted in the campaign report.</p>
  </section>
</aside>
</div>
