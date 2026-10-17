<?php
/** @var App\Core\App $app */
$user = $app->user();
$isAdmin = $user !== null && $user['tenant_id'] === null;
$path = $app->request?->path ?? '/';
$tenantName = !$isAdmin && $user ? (string) $app->db->scalar('SELECT name FROM tenants WHERE id = ?', [$user['tenant_id']]) : 'Platform admin';
$nav = $isAdmin ? [
    ['/admin', 'Companies', 'building', null],
    ['/admin/health', 'System health', 'pulse', null],
    ['/admin/meta', 'Meta app', 'key', null],
    ['/admin/audit', 'Audit log', 'list', null],
] : [
    ['/', 'Dashboard', 'grid', 'dashboard.view'],
    ['/inbox', 'Inbox', 'chat', 'inbox.view'],
    ['/contacts', 'Contacts', 'users', 'contacts.view'],
    ['/campaigns', 'Campaigns', 'megaphone', 'campaigns.view'],
    ['/templates', 'Templates', 'template', 'templates.view'],
    ['/automations', 'Automations', 'bolt', 'automations.manage'],
    ['/whatsapp', 'WhatsApp accounts', 'phone', 'whatsapp.view'],
    ['/users', 'Users', 'user', 'users.view'],
    ['/teams', 'Teams', 'team', 'teams.manage'],
    ['/roles', 'Roles & permissions', 'shield', 'roles.manage'],
    ['/settings/api-tokens', 'API tokens', 'key', 'api_tokens.manage'],
    ['/audit', 'Audit log', 'list', 'audit.view'],
];
$unread = 0;
if (!$isAdmin && $user && $app->can('inbox.view')) {
    $unread = (int) $app->db->scalar("SELECT COALESCE(SUM(unread_count),0) FROM conversations WHERE tenant_id = ? AND status = 'open' AND (assigned_user_id = ? OR assigned_user_id IS NULL)", [$user['tenant_id'], $user['id']]);
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($app->csrfToken()) ?>">
<title><?= e(($title ?? 'Dashboard') . ' · ' . env('APP_NAME', 'WhatsApp CRM')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<div class="shell" data-shell>
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 5h16v11H8l-4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span>
      <span class="brand-text"><strong><?= e(env('APP_NAME', 'WhatsApp CRM')) ?></strong><small><?= e($tenantName) ?></small></span>
    </div>
    <nav>
      <?php foreach ($nav as [$href, $label, $icon, $perm]): ?>
        <?php if ($perm !== null && !$app->can($perm)) { continue; } ?>
        <?php $active = $href === '/' || $href === '/admin' ? $path === $href : str_starts_with($path, $href); ?>
        <a href="<?= e($href) ?>" class="nav-link<?= $active ? ' is-active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
          <?= $app->view->partial('partials/icon', ['name' => $icon]) ?>
          <span><?= e($label) ?></span>
          <?php if ($href === '/inbox' && $unread > 0): ?><span class="badge badge-accent" title="Unread messages"><?= $unread > 99 ? '99+' : $unread ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="main-col">
    <header class="topbar">
      <button type="button" class="icon-btn" data-toggle-sidebar aria-controls="sidebar" aria-label="Toggle navigation">
        <svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
      <?php if (!$isAdmin && $app->can('contacts.view')): ?>
      <form class="top-search" action="/contacts" method="get" role="search">
        <input type="search" name="q" placeholder="Search contacts…  ( / )" aria-label="Search contacts" data-hotkey="/">
      </form>
      <?php else: ?><div class="top-search"></div><?php endif; ?>
      <button type="button" class="icon-btn" data-theme-toggle aria-label="Toggle dark mode">
        <svg viewBox="0 0 24 24" width="18" height="18"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
      </button>
      <details class="menu">
        <summary class="user-chip" aria-label="Account menu">
          <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? '?'), 0, 1))) ?></span>
          <span class="user-name"><?= e($user['name'] ?? '') ?></span>
        </summary>
        <div class="menu-panel">
          <div class="menu-head"><strong><?= e($user['name'] ?? '') ?></strong><small><?= e($user['email'] ?? '') ?></small></div>
          <a href="/account/security">Security &amp; 2FA</a>
          <form method="post" action="/logout"><?= csrf_field($app) ?><button type="submit">Sign out</button></form>
        </div>
      </details>
    </header>
    <?php if (!empty($toast)): ?>
      <div class="toast toast-<?= e($toast['type'] ?? 'info') ?>" role="status" data-toast><?= e($toast['message'] ?? '') ?><button type="button" class="toast-close" aria-label="Dismiss" data-dismiss>&times;</button></div>
    <?php endif; ?>
    <main id="main" class="content<?= !empty($wide) ? ' content-wide' : '' ?>">
      <?= $content ?>
    </main>
  </div>
</div>
</body>
</html>
