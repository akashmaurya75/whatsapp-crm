<?php
/** @var int $page @var int $total @var int $perPage @var array $query */
$pages = max(1, (int) ceil($total / max(1, $perPage)));
if ($pages <= 1) { return; }
?>
<nav class="pagination" aria-label="Pagination">
  <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e(query_with($query, ['page' => $page - 1])) ?>" rel="prev">← Previous</a><?php endif; ?>
  <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> total</span>
  <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e(query_with($query, ['page' => $page + 1])) ?>" rel="next">Next →</a><?php endif; ?>
</nav>
