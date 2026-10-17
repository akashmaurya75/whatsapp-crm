<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title ?? 'Install') ?> · WhatsApp CRM installer</title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="guest">
<main class="auth-wrap">
  <div class="auth-card install-card">
    <div class="brand brand-center">
      <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M4 5h16v11H8l-4 4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span>
      <strong>WhatsApp CRM · Installer</strong>
    </div>
    <ol class="install-steps" aria-label="Installation steps">
      <?php foreach ([1 => 'Check', 2 => 'Database', 3 => 'Accounts', 4 => 'Done'] as $n => $label): ?>
        <li class="<?= $n < $step ? 'is-done' : ($n === $step ? 'is-current' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>><span><?= $n ?></span><?= e($label) ?></li>
      <?php endforeach; ?>
    </ol>
    <?= $content ?>
  </div>
</main>
</body>
</html>
