<?php
/** @var App\Core\App $app */
$loggedIn = $app->user() !== null;
$this->layout($loggedIn ? 'layouts/app' : 'layouts/guest', ['title' => 'Error ' . $status]);
?>
<section class="empty-state">
  <p class="eyebrow">Error <?= (int) $status ?></p>
  <h1><?= e(match ((int) $status) { 403 => 'Not allowed', 404 => 'Page not found', 419 => 'Session expired', 429 => 'Slow down', default => 'Something went wrong' }) ?></h1>
  <p class="muted"><?= e($message) ?></p>
  <p><a class="btn" href="<?= $loggedIn ? '/' : '/login' ?>">Go back home</a></p>
  <p class="muted small">Reference: <?= e($app->requestId) ?></p>
</section>
