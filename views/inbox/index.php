<?php
$this->layout('layouts/app', ['title' => $conv ? ($conv['contact_name'] ?: $conv['phone_e164']) . ' · Inbox' : 'Inbox', 'wide' => true]);
$query = array_filter(['q' => $filters['q'], 'status' => $filters['status'] !== 'open' ? $filters['status'] : '', 'assigned' => $filters['assigned'], 'account' => $filters['account'] ?: '']);
$qs = query_with($query, []);
$statusIcon = ['queued' => '◷', 'submitted' => '✓', 'sent' => '✓', 'delivered' => '✓✓', 'read' => '✓✓', 'failed' => '!'];
?>
<div class="inbox<?= $conv ? ' has-conversation' : '' ?>">
  <aside class="inbox-list" aria-label="Conversations">
    <form method="get" action="/inbox" class="inbox-filters">
      <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search name, number, message" aria-label="Search conversations">
      <div class="seg">
        <?php foreach (['open' => 'Open', 'closed' => 'Closed', 'archived' => 'Archived'] as $k => $l): ?>
          <a href="/inbox<?= e(query_with($query, ['status' => $k === 'open' ? '' : $k])) ?>" class="<?= $filters['status'] === $k ? 'is-active' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <div class="seg">
        <?php foreach (['' => 'All', 'me' => 'Mine', 'unassigned' => 'Unassigned', 'awaiting' => 'Awaiting'] as $k => $l): ?>
          <a href="/inbox<?= e(query_with($query, ['assigned' => $k])) ?>" class="<?= $filters['assigned'] === $k ? 'is-active' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </form>
    <div data-conv-list data-list-poll="/inbox/updates<?= e(query_with($query + ['page' => $page > 1 ? $page : '', 'active' => $conv ? (int) $conv['id'] : ''], [])) ?>" data-stamp="<?= e($stamp) ?>">
<?= $this->partial('inbox/list', ['list' => $list, 'conv' => $conv, 'qs' => $qs, 'accounts' => $accounts]) ?>
    </div>
    <?= $this->partial('partials/pagination', ['page' => $page, 'total' => $total, 'perPage' => 30, 'query' => $query]) ?>
  </aside>

  <?php if ($conv === null): ?>
    <section class="inbox-thread inbox-empty"><p class="muted">Select a conversation.</p></section>
  <?php else: ?>
  <section class="inbox-thread" aria-label="Conversation">
    <header class="thread-head">
      <a class="icon-btn back-btn" href="/inbox<?= e($qs) ?>" aria-label="Back to list">←</a>
      <div><strong><?= e($conv['contact_name'] ?: $conv['phone_e164']) ?></strong><br><small class="muted mono"><?= e($conv['phone_e164']) ?> · via <?= e($conv['account_name']) ?></small></div>
      <div class="thread-actions">
        <?php foreach ($conv['status'] === 'open' ? ['close' => 'Close'] : ['reopen' => 'Reopen'] as $a => $l): ?>
          <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/flag"><?= csrf_field($app) ?><input type="hidden" name="action" value="<?= $a ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $l ?></button></form>
        <?php endforeach; ?>
        <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/flag"><?= csrf_field($app) ?><input type="hidden" name="action" value="<?= (int) $conv['is_pinned'] ? 'unpin' : 'pin' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= (int) $conv['is_pinned'] ? 'Unpin' : 'Pin' ?></button></form>
        <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/flag"><?= csrf_field($app) ?><input type="hidden" name="action" value="unread"><button class="btn btn-ghost btn-sm" type="submit">Mark unread</button></form>
        <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/flag"><?= csrf_field($app) ?><input type="hidden" name="action" value="<?= (int) $conv['is_archived'] ? 'unarchive' : 'archive' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= (int) $conv['is_archived'] ? 'Unarchive' : 'Archive' ?></button></form>
      </div>
    </header>

    <div class="messages" data-messages data-poll="/inbox/<?= (int) $conv['id'] ?>/poll" data-last-id="<?= $messages ? (int) end($messages)['id'] : 0 ?>" aria-live="polite">
      <?php if (count($messages) === 50): ?><a class="load-older small" href="/inbox/<?= (int) $conv['id'] ?>?before=<?= (int) $messages[0]['id'] ?>">Load older messages</a><?php endif; ?>
      <?php if ($messages === []): ?><p class="empty">No messages yet.</p><?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <div class="msg msg-<?= $m['direction'] === 'in' ? 'in' : 'out' ?>" id="m<?= (int) $m['id'] ?>" data-id="<?= (int) $m['id'] ?>">
          <?php if ($m['reply_to_message_id']): ?><a class="msg-quote" href="#m<?= (int) $m['reply_to_message_id'] ?>">↩ reply</a><?php endif; ?>
          <?php if ($m['type'] === 'template'): ?><span class="msg-type">📋 template<?= $m['campaign_id'] ? ' · campaign' : '' ?></span>
          <?php elseif ($m['type'] !== 'text'): ?><span class="msg-type">[<?= e($m['type']) ?><?= $m['media_mime'] ? ' · ' . e($m['media_mime']) : '' ?>]</span><?php endif; ?>
          <div class="msg-body"><?= wa_format((string) $m['body']) ?></div>
          <div class="msg-meta">
            <?php if ($m['direction'] === 'out' && $m['sender_name']): ?><span><?= e($m['sender_name']) ?></span><?php endif; ?>
            <time title="<?= e($m['created_at']) ?> UTC"><?= e(substr((string) $m['created_at'], 11, 5)) ?></time>
            <?php if ($m['direction'] === 'out'): ?><span class="msg-status status-<?= e($m['status']) ?>" data-status title="<?= e($m['status'] . ($m['error_message'] ? ': ' . $m['error_message'] : '')) ?>"><?= e($statusIcon[$m['status']] ?? '') ?> <?= e($m['status']) ?></span><?php endif; ?>
            <?php if ($app->can('inbox.reply')): ?><button type="button" class="link-btn" data-reply="<?= (int) $m['id'] ?>" data-reply-text="<?= e(mb_substr((string) $m['body'], 0, 60)) ?>">Reply</button><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($app->can('inbox.reply')): ?>
    <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/messages" class="composer" data-composer>
      <?= csrf_field($app) ?>
      <input type="hidden" name="idempotency_key" value="<?= e(random_token(16)) ?>">
      <input type="hidden" name="reply_to" value="" data-reply-to>
      <div class="replying" data-replying hidden><span data-replying-text></span> <button type="button" class="link-btn" data-cancel-reply>cancel</button></div>
      <?= field_error($errors, 'body') ?>
      <?php if ($conv['opted_out_at']): ?>
        <p class="alert alert-error small">This contact opted out. Sending is disabled.</p>
      <?php elseif ($conv['provider'] === 'meta_cloud' && !$windowOpen): ?>
        <p class="alert alert-warn small">The 24-hour window is closed. Meta only allows an approved template until the customer writes again. Choose one below.</p>
      <?php else: ?>
        <div class="composer-row">
          <textarea name="body" rows="2" maxlength="4096" placeholder="Type a message — Enter to send, Shift+Enter for a new line" aria-label="Message" required data-hotkey="r"></textarea>
          <button class="btn" type="submit">Send</button>
        </div>
      <?php endif; ?>
    </form>
    <?php if (!$conv['opted_out_at'] && $conv['provider'] === 'meta_cloud'): ?>
    <details class="tpl-send"<?= !$windowOpen || $pickedTemplate || isset($errors['template']) ? ' open' : '' ?>>
      <summary>📋 Send a template</summary>
      <?php if ($templates === []): ?>
        <p class="small muted">No approved templates for this number yet.<?= $app->can('templates.manage') ? ' <a href="/templates/new">Create one</a>.' : '' ?></p>
      <?php else: ?>
        <form method="get" action="/inbox/<?= (int) $conv['id'] ?>" class="inline-form">
          <select name="template" aria-label="Template" data-autosubmit>
            <option value="">Choose a template…</option>
            <?php foreach ($templates as $tp): ?><option value="<?= (int) $tp['id'] ?>"<?= $pickedTemplate && (int) $pickedTemplate['id'] === (int) $tp['id'] ? ' selected' : '' ?>><?= e($tp['name']) ?> · <?= e($tp['language']) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-ghost btn-sm" type="submit">Use</button>
        </form>
        <?php if ($pickedTemplate): ?>
          <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/template" class="form tpl-send-form">
            <?= csrf_field($app) ?>
            <input type="hidden" name="template_id" value="<?= (int) $pickedTemplate['id'] ?>">
            <input type="hidden" name="idempotency_key" value="<?= e(random_token(16)) ?>">
            <div class="wa-bubble wa-static small"><?= wa_format(App\Domain\Templates\TemplateService::render($pickedTemplate, [])) ?></div>
            <?php foreach ($pickedTemplate['slots'] as $slot => $label): ?>
              <label class="small"><?= e($label) ?> <input name="vars[<?= e($slot) ?>]" value="<?= e((string) ($pickedTemplate['prefill'][$slot] ?? '')) ?>" required maxlength="1024"></label>
            <?php endforeach; ?>
            <?php if (App\Domain\Templates\TemplateService::needsMedia($pickedTemplate)): ?>
              <label class="small">Link to the <?= e($pickedTemplate['header_type']) ?> (https) <input name="media_link" type="url" required placeholder="https://…"></label>
            <?php endif; ?>
            <button class="btn btn-sm" type="submit">Send template</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
      <?= field_error($errors, 'template') ?>
    </details>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <aside class="inbox-side" aria-label="Contact details">
    <section>
      <h2><?= e($conv['contact_name'] ?: 'Unnamed') ?></h2>
      <p class="mono small"><?= e($conv['phone_e164']) ?></p>
      <?php if ($conv['contact_email']): ?><p class="small"><?= e($conv['contact_email']) ?></p><?php endif; ?>
      <?php if ($conv['contact_company']): ?><p class="small"><?= e($conv['contact_company']) ?></p><?php endif; ?>
      <p class="small">Lead: <span class="pill"><?= e($conv['lead_status']) ?></span></p>
      <?php if ($app->can('contacts.view')): ?><a class="small" href="/contacts/<?= (int) $conv['contact_id'] ?>">Open contact →</a><?php endif; ?>
      <div class="tags"><?php foreach ($tags as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></div>
    </section>
    <section>
      <h3>Assignment</h3>
      <?php if ($app->can('inbox.assign')): ?>
      <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/assign" class="form">
        <?= csrf_field($app) ?>
        <select name="assigned_user_id" aria-label="Agent"><option value="">No agent</option>
          <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= (int) $conv['assigned_user_id'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="assigned_team_id" aria-label="Team"><option value="">No team</option>
          <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $conv['assigned_team_id'] === (int) $t['id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
        <?= field_error($errors, 'assigned_user_id') ?>
        <button class="btn btn-ghost btn-sm" type="submit">Assign / transfer</button>
      </form>
      <?php elseif ($conv['assigned_user_id'] === null): ?>
      <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/assign"><?= csrf_field($app) ?><input type="hidden" name="assigned_user_id" value="<?= (int) $app->userId() ?>"><button class="btn btn-sm" type="submit">Assign to me</button></form>
      <?php else: ?><p class="small">Assigned to <?= e(array_column($agents, 'name', 'id')[$conv['assigned_user_id']] ?? '—') ?></p><?php endif; ?>
      <?php if ($history !== []): ?><ul class="mini-log"><?php foreach ($history as $h): ?><li><?= e($h['to_name'] ?? $h['team_name'] ?? 'Unassigned') ?> <span class="muted">by <?= e($h['by_name'] ?? 'system') ?>, <?= e(time_ago($h['created_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
    <?php if ($app->can('inbox.reply')): ?>
    <section>
      <h3>Priority</h3>
      <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/priority" class="inline-form">
        <?= csrf_field($app) ?>
        <select name="priority" aria-label="Priority"><?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?><option<?= $conv['priority'] === $p ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select>
        <button class="btn btn-ghost btn-sm" type="submit">Set</button>
      </form>
    </section>
    <section>
      <h3>Internal notes <small class="muted">(never sent to the customer)</small></h3>
      <form method="post" action="/inbox/<?= (int) $conv['id'] ?>/notes" class="form">
        <?= csrf_field($app) ?>
        <textarea name="body" rows="2" maxlength="5000" aria-label="Note" required></textarea><?= field_error($errors, 'note') ?>
        <button class="btn btn-ghost btn-sm" type="submit">Add note</button>
      </form>
      <ul class="notes"><?php foreach (array_slice($notes, 0, 10) as $n): ?><li><p><?= nl2br(e($n['body'])) ?></p><small class="muted"><?= e($n['author'] ?? '') ?> · <?= e(time_ago($n['created_at'])) ?></small></li><?php endforeach; ?></ul>
    </section>
    <?php endif; ?>
    <section>
      <h3>Channel capabilities</h3>
      <ul class="caps small"><?php foreach (App\WhatsApp\Capability::cases() as $cap): ?><li class="<?= in_array($cap->value, $caps, true) ? 'cap-on' : 'cap-off' ?>"><?= e($cap->label()) ?></li><?php endforeach; ?></ul>
    </section>
  </aside>
  <?php endif; ?>
</div>
