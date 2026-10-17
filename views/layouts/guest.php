<?php /** @var App\Core\App $app */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ?? 'Sign in') . ' · ' . env('APP_NAME', 'WhatsApp CRM')) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="guest">
<main class="auth-wrap">
  <div class="auth-card">
    <div class="brand brand-center">
      <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 5h16v11H8l-4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span>
      <strong><?= e(env('APP_NAME', 'WhatsApp CRM')) ?></strong>
    </div>
    <?php if (!empty($toast)): ?>
      <div class="alert alert-<?= e($toast['type'] ?? 'info') ?>" role="status"><?= e($toast['message'] ?? '') ?></div>
    <?php endif; ?>
    <?= $content ?>
  </div>
</main>
</body>
</html>
