<?php $this->layout('install/layout', ['title' => 'Requirements', 'step' => 1]); ?>
<h1 class="auth-title">Let's install WhatsApp CRM</h1>
<p class="muted">This takes about two minutes. First, a quick check that this server can run the application.</p>
<?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<ul class="checklist">
  <?php foreach ($checks as $c): ?>
    <li class="<?= $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') ?>">
      <span class="check-icon" aria-hidden="true"><?= $c['ok'] ? '✓' : ($c['required'] ? '✕' : '!') ?></span>
      <span><?= e($c['label']) ?><?= $c['required'] ? '' : ' <small class="muted">(optional)</small>' ?>
        <?php if (!$c['ok']): ?><br><small class="<?= $c['required'] ? 'field-error' : 'muted' ?>"><?= e($c['help']) ?></small><?php endif; ?>
      </span>
    </li>
  <?php endforeach; ?>
</ul>
<?php if (!empty($server)): ?>
<ul class="list small">
  <?php foreach ($server as $k => $v): ?><li><span><?= e($k) ?></span><b><?= e($v) ?></b></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($ok): ?>
  <a class="btn btn-block" href="/install/database">Continue</a>
<?php else: ?>
  <p class="alert alert-error">Fix the items marked ✕, then reload this page.</p>
  <a class="btn btn-ghost btn-block" href="/install">Check again</a>
<?php endif; ?>
