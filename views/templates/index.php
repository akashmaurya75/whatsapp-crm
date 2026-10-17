<?php
$this->layout('layouts/app', ['title' => 'Templates']);
$canManage = $app->can('templates.manage');
$tabs = ['' => 'All', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected', 'draft' => 'Drafts'];
?>
<div class="page-head">
  <div><h1>Message templates</h1><p class="muted">Pre-approved messages for starting conversations, campaigns and notifications. Meta reviews every template.</p></div>
  <div class="actions">
    <?php if ($canManage && $accounts !== []): ?>
      <form method="post" action="/templates/sync" class="inline-form"><?= csrf_field($app) ?>
        <?php if (count($accounts) > 1): ?><select name="whatsapp_account_id" aria-label="Account"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <?php else: ?><input type="hidden" name="whatsapp_account_id" value="<?= (int) $accounts[0]['id'] ?>"><?php endif; ?>
        <button class="btn btn-ghost" type="submit">↻ Sync from Meta</button></form>
      <a class="btn" href="/templates/new">New template</a>
    <?php endif; ?>
  </div>
</div>
<?php if ($accounts === []): ?>
  <div class="callout"><strong>Templates need a Meta WhatsApp Cloud API account.</strong><span>Connect one first. Unofficial gateways can't use approved templates.</span>
    <?php if ($app->can('whatsapp.manage')): ?><a class="btn btn-sm" href="/whatsapp/new?provider=meta_cloud">Connect account</a><?php endif; ?></div>
<?php endif; ?>
<div class="tabs">
  <?php foreach ($tabs as $k => $l): ?>
    <a href="/templates<?= e(query_with(['q' => $q], ['status' => $k])) ?>" class="<?= $status === $k ? 'is-active' : '' ?>"><?= $l ?><?php if ($k !== '' && !empty($counts[$k])): ?> <span class="count"><?= (int) $counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
  <form method="get" action="/templates" class="tabs-search"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search templates" aria-label="Search templates"></form>
</div>
<?php if ($templates === []): ?>
  <div class="empty-state card"><h2>No templates here</h2>
    <p class="muted">Create one, or press <b>Sync from Meta</b> to import templates you already made in WhatsApp Manager.</p></div>
<?php else: ?>
<div class="tpl-grid">
  <?php foreach ($templates as $t): ?>
    <a class="tpl-card card" href="/templates/<?= (int) $t['id'] ?>">
      <div class="tpl-card-head">
        <strong class="mono"><?= e($t['name']) ?></strong>
        <span class="pill pill-tpl-<?= e($t['status']) ?>"><?= e($t['status']) ?></span>
      </div>
      <div class="muted small"><?= e(ucfirst((string) $t['category'])) ?> · <?= e(App\Domain\Templates\TemplateService::LANGUAGES[$t['language']] ?? $t['language']) ?> · <?= e($t['account_name']) ?><?= $t['quality_score'] ? ' · quality ' . e($t['quality_score']) : '' ?></div>
      <p class="tpl-snippet"><?= wa_format(mb_strimwidth((string) $t['body'], 0, 160, '…')) ?></p>
      <?php if ($t['status'] === 'rejected' && $t['rejected_reason']): ?><p class="field-error small">Rejected: <?= e($t['rejected_reason']) ?></p><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
