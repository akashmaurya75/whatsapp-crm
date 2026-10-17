<?php
/** Conversation list (also re-rendered by /inbox/updates for live refresh). */
?>
    <?php if ($list === []): ?>
      <p class="empty"><?= $accounts === [] ? 'Connect a WhatsApp account to receive messages.' : 'No conversations here.' ?></p>
    <?php endif; ?>
    <ul class="conv-list">
      <?php foreach ($list as $c): $active = $conv && (int) $conv['id'] === (int) $c['id']; ?>
        <li><a href="/inbox/<?= (int) $c['id'] . e($qs) ?>" class="conv-item<?= $active ? ' is-active' : '' ?><?= (int) $c['unread_count'] > 0 ? ' is-unread' : '' ?>"<?= $active ? ' aria-current="true"' : '' ?>>
          <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($c['contact_name'] ?: '#'), 0, 1))) ?></span>
          <span class="conv-main">
            <span class="conv-top"><strong><?= e($c['contact_name'] ?: $c['phone_e164']) ?></strong><time><?= e(time_ago($c['last_message_at'])) ?></time></span>
            <span class="conv-preview"><?= (int) $c['is_pinned'] ? '📌 ' : '' ?><?= e($c['last_message_preview'] ?? '') ?></span>
            <span class="conv-meta"><?= e($c['account_name']) ?><?= $c['assignee_name'] ? ' · ' . e($c['assignee_name']) : ' · unassigned' ?><?= $c['priority'] !== 'normal' ? ' · <b class="prio-' . e($c['priority']) . '">' . e($c['priority']) . '</b>' : '' ?></span>
          </span>
          <?php if ((int) $c['unread_count'] > 0): ?><span class="badge badge-accent"><?= (int) $c['unread_count'] ?></span><?php endif; ?>
        </a></li>
      <?php endforeach; ?>
    </ul>
