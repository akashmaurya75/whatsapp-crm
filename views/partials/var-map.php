<?php
/** Variable mapping rows. @var array $t template @var array $map @var string $prefix input name prefix @var array $errors */
use App\Domain\Templates\TemplateService as TS;

$slots = TS::slots($t);
if ($slots === []) {
    echo '<p class="muted small">This template has no variables.</p>';
    return;
}
?>
<div class="varmap">
  <?php foreach ($slots as $slot => $label): $m = $map[$slot] ?? ['source' => 'static', 'value' => '', 'fallback' => '']; ?>
    <div class="varmap-row" data-varmap-row>
      <span class="varmap-label"><?= e($label) ?></span>
      <select name="<?= e($prefix) ?>[<?= e($slot) ?>][source]" aria-label="<?= e($label) ?> source" data-varmap-source>
        <?php foreach (TS::SOURCES as $k => $l): ?><option value="<?= $k ?>"<?= $m['source'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <input name="<?= e($prefix) ?>[<?= e($slot) ?>][value]" value="<?= e($m['value']) ?>" placeholder="Text to use" aria-label="<?= e($label) ?> fixed text" data-varmap-static maxlength="500">
      <input name="<?= e($prefix) ?>[<?= e($slot) ?>][fallback]" value="<?= e($m['fallback']) ?>" placeholder="If empty, use…" aria-label="<?= e($label) ?> fallback" data-varmap-fallback maxlength="100">
      <?= field_error($errors, 'map_' . $slot) ?>
    </div>
  <?php endforeach; ?>
</div>
