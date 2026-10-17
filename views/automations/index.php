<?php
use App\Domain\Automation\AutomationService as Auto;

$this->layout('layouts/app', ['title' => 'Automations']);
$summary = static function (?array $rule): string {
    if ($rule === null) { return ''; }
    $r = Auto::config($rule)['reply'] ?? ['type' => 'none'];
    return $r['type'] === 'text' ? mb_strimwidth((string) $r['text'], 0, 140, '…') : ($r['type'] === 'template' ? 'Sends an approved template' : 'No reply');
};
$card = function (string $type, ?array $rule, string $desc) use ($app, $summary): string {
    ob_start(); ?>
    <section class="card auto-card">
      <div class="card-head">
        <div><h2><?= e(Auto::TYPES[$type]) ?></h2><p class="muted small"><?= e($desc) ?></p></div>
        <?php if ($rule): ?>
          <form method="post" action="/automations/<?= (int) $rule['id'] ?>/toggle"><?= csrf_field($app) ?>
            <button class="switch<?= (int) $rule['is_active'] ? ' is-on' : '' ?>" type="submit" role="switch" aria-checked="<?= (int) $rule['is_active'] ? 'true' : 'false' ?>" aria-label="<?= (int) $rule['is_active'] ? 'Turn off' : 'Turn on' ?>"><span></span></button></form>
        <?php endif; ?>
      </div>
      <?php if ($rule): ?>
        <div class="wa-bubble wa-static small"><?= wa_format($summary($rule)) ?></div>
        <?php if ($type === 'away'): $cfg = Auto::config($rule); ?>
          <p class="small muted">Open: <?php $o = []; foreach (Auto::DAYS as $k => $d) { $h = $cfg['hours'][$k] ?? null; if (!empty($h['open'])) { $o[] = substr($d, 0, 3) . ' ' . $h['start'] . '–' . $h['end']; } } echo e($o ? implode(', ', $o) : 'always closed'); ?> (<?= e($cfg['timezone'] ?? 'UTC') ?>)</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="/automations/<?= (int) $rule['id'] ?>">Edit</a>
      <?php else: ?>
        <a class="btn btn-sm" href="/automations/new?type=<?= e($type) ?>">Set up</a>
      <?php endif; ?>
    </section>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head"><div><h1>Automations</h1><p class="muted">Reply instantly, even when nobody is online. Auto-replies never go to contacts who opted out.</p></div></div>

<div class="grid-2">
  <?= $card('welcome', $welcome, 'Sent once, the first time a new contact messages you.') ?>
  <?= $card('away', $away, 'Sent when someone writes outside your business hours (at most once every few hours per contact).') ?>
</div>

<section class="card">
  <div class="card-head"><div><h2>Keyword replies</h2><p class="muted small">When a message matches, reply and/or tag the contact, assign the chat or update the lead. First match wins (by priority).</p></div>
    <a class="btn" href="/automations/new?type=keyword">+ Add keyword reply</a></div>
  <?php if ($keywords === []): ?>
    <p class="empty">No keyword replies yet. Examples: "price" → send price list, "order status" → assign to Support team.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Keywords</th><th>Reply</th><th>Actions</th><th>Priority</th><th>On</th></tr></thead>
    <tbody><?php foreach ($keywords as $k): $cfg = Auto::config($k); $acts = $cfg['actions'] ?? []; ?>
      <tr>
        <td><a href="/automations/<?= (int) $k['id'] ?>"><strong><?= e($k['name']) ?></strong></a></td>
        <td class="small"><?= e(Auto::MATCH[$cfg['match'] ?? 'contains'] ?? '') ?>: <?php foreach ((array) ($cfg['keywords'] ?? []) as $kw): ?><span class="tag"><?= e($kw) ?></span> <?php endforeach; ?></td>
        <td class="small"><?= e($summary($k)) ?></td>
        <td class="small"><?= e(implode(', ', array_filter([
            !empty($acts['add_tags']) ? 'tag: ' . implode(', ', $acts['add_tags']) : '', !empty($acts['assign_team_id']) ? 'assign team' : '',
            !empty($acts['assign_user_id']) ? 'assign agent' : '', !empty($acts['lead_status']) ? 'lead → ' . $acts['lead_status'] : '']))) ?: '—' ?></td>
        <td><?= (int) $k['priority'] ?></td>
        <td><form method="post" action="/automations/<?= (int) $k['id'] ?>/toggle"><?= csrf_field($app) ?><button class="switch<?= (int) $k['is_active'] ? ' is-on' : '' ?>" type="submit" role="switch" aria-checked="<?= (int) $k['is_active'] ? 'true' : 'false' ?>" aria-label="Toggle <?= e($k['name']) ?>"><span></span></button></form></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Recent activity</h2>
  <?php if ($runs === []): ?><p class="empty">Nothing has run yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>Automation</th><th>Contact</th><th>Result</th></tr></thead>
    <tbody><?php foreach ($runs as $r): ?>
      <tr><td class="nowrap small"><?= e(time_ago($r['created_at'])) ?></td><td><?= e($r['rule_name']) ?></td>
        <td><a href="/inbox/<?= (int) $r['conversation_id'] ?>"><?= e($r['contact_name'] ?: $r['phone_e164']) ?></a></td>
        <td class="small"><span class="msg-status status-<?= $r['status'] === 'sent' ? 'delivered' : ($r['status'] === 'failed' ? 'failed' : 'queued') ?>"><?= e($r['status']) ?></span> <?= e((string) $r['detail']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
