<?php
$this->layout('layouts/app', ['title' => $contact['name'] ?: $contact['phone_e164']]);
$canEdit = $app->can('contacts.update');
$val = static fn (string $f, string $col) => old_value($old, $f, $contact[$col] ?? '');
?>
<div class="page-head">
  <div><a class="small" href="/contacts">← Contacts</a>
    <h1><?= e($contact['name'] ?: 'Unnamed contact') ?></h1>
    <p class="muted mono"><?= e($contact['phone_e164']) ?><?php if ($contact['opted_out_at']): ?> · <span class="pill pill-error">opted out <?= e(time_ago($contact['opted_out_at'])) ?></span><?php endif; ?><?php if ($contact['archived_at']): ?> · <span class="pill">archived</span><?php endif; ?></p>
  </div>
  <div class="actions">
    <?php if ($app->can('inbox.reply') && $accounts !== []): ?>
      <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/conversation" class="inline-form">
        <?= csrf_field($app) ?>
        <select name="account_id" aria-label="WhatsApp account"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select>
        <button class="btn" type="submit">Open conversation</button>
      </form>
    <?php endif; ?>
    <?php if ($app->can('contacts.delete')): ?>
      <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/<?= $contact['archived_at'] ? 'restore' : 'archive' ?>" data-confirm="<?= $contact['archived_at'] ? 'Restore this contact?' : 'Archive this contact? It will be hidden from lists.' ?>">
        <?= csrf_field($app) ?><button class="btn btn-ghost" type="submit"><?= $contact['archived_at'] ? 'Restore' : 'Archive' ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2 grid-2-wide">
  <form method="post" action="/contacts/<?= (int) $contact['id'] ?>" class="card form form-grid">
    <?= csrf_field($app) ?>
    <h2 class="span-2">Details</h2>
    <fieldset class="span-2 form-grid"<?= $canEdit ? '' : ' disabled' ?>>
    <label>Name <input name="name" value="<?= e($val('name', 'name')) ?>" maxlength="160"></label>
    <label>Phone <input name="phone" value="<?= e($val('phone', 'phone_e164')) ?>" maxlength="32"><?= field_error($errors, 'phone') ?></label>
    <label>Email <input type="email" name="email" value="<?= e($val('email', 'email')) ?>"><?= field_error($errors, 'email') ?></label>
    <label>Company <input name="company" value="<?= e($val('company', 'company')) ?>"></label>
    <label>City <input name="city" value="<?= e($val('city', 'city')) ?>"></label>
    <label>Source <input name="source" value="<?= e($val('source', 'source')) ?>"></label>
    <label>Lead status
      <select name="lead_status"><?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $contact['lead_status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select>
    </label>
    <label>Owner
      <select name="owner_user_id"><option value="">Unassigned</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= (int) $contact['owner_user_id'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>Consent
      <select name="consent_status">
        <?php foreach (['unknown' => 'Unknown', 'granted' => 'Granted', 'revoked' => 'Revoked (opted out)'] as $k => $l): ?><option value="<?= $k ?>"<?= $contact['consent_status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select>
      <?php if ($contact['consent_at']): ?><small class="muted">Recorded <?= e(time_ago($contact['consent_at'])) ?></small><?php endif; ?>
    </label>
    <label>Next follow-up <input type="date" name="next_follow_up_at" value="<?= e($contact['next_follow_up_at'] ? substr((string) $contact['next_follow_up_at'], 0, 10) : '') ?>"><?= field_error($errors, 'next_follow_up_at') ?></label>
    <label class="span-2">Address <input name="address" value="<?= e($val('address', 'address')) ?>"></label>
    </fieldset>
    <?php if ($canEdit): ?><div class="span-2 form-actions"><button class="btn" type="submit">Save changes</button></div><?php endif; ?>
  </form>

  <div class="stack">
    <section class="card">
      <h2>Tags</h2>
      <div class="tags"><?php foreach ($tags as $t): ?><a class="tag" href="/contacts?tag=<?= e(urlencode($t)) ?>"><?= e($t) ?></a><?php endforeach; ?><?= $tags === [] ? '<span class="muted small">No tags</span>' : '' ?></div>
      <?php if ($canEdit): ?>
      <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/tags" class="inline-form">
        <?= csrf_field($app) ?>
        <input name="tags" value="<?= e(implode(', ', $tags)) ?>" placeholder="vip, wholesale" aria-label="Tags, comma separated">
        <button class="btn btn-ghost btn-sm" type="submit">Save tags</button>
      </form>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Conversations</h2>
      <?php if ($conversations === []): ?><p class="empty">No conversations yet.</p><?php else: ?>
      <ul class="list"><?php foreach ($conversations as $cv): ?>
        <li><a href="/inbox/<?= (int) $cv['id'] ?>"><?= e($cv['account_name']) ?></a><span class="muted small"><?= e($cv['status']) ?> · <?= e(time_ago($cv['last_message_at'])) ?></span></li>
      <?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Internal notes</h2>
      <?php if ($canEdit): ?>
      <form method="post" action="/contacts/<?= (int) $contact['id'] ?>/notes" class="form">
        <?= csrf_field($app) ?>
        <textarea name="body" rows="3" maxlength="5000" placeholder="Visible to your team only" aria-label="New note" required></textarea><?= field_error($errors, 'body') ?>
        <button class="btn btn-ghost btn-sm" type="submit">Add note</button>
      </form>
      <?php endif; ?>
      <ul class="notes"><?php foreach ($notes as $n): ?>
        <li><p><?= nl2br(e($n['body'])) ?></p><small class="muted"><?= e($n['author'] ?? 'System') ?> · <?= e(time_ago($n['created_at'])) ?></small></li>
      <?php endforeach; ?></ul>
    </section>
  </div>
</div>
