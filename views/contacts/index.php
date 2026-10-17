<?php
$this->layout('layouts/app', ['title' => 'Contacts']);
$query = array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'tag' => $filters['tag'], 'archived' => $filters['archived'] ? '1' : '', 'sort' => $filters['sort'], 'dir' => $filters['dir']]);
$sortLink = static function (string $col, string $label) use ($filters, $query): string {
    $dir = $filters['sort'] === $col && $filters['dir'] === 'asc' ? 'desc' : 'asc';
    $arrow = $filters['sort'] === $col ? ($filters['dir'] === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="' . e(query_with($query, ['sort' => $col, 'dir' => $dir, 'page' => null])) . '">' . e($label) . $arrow . '</a>';
};
?>
<div class="page-head">
  <div><h1>Contacts</h1><p class="muted"><?= number_format($total) ?> <?= $filters['archived'] ? 'archived' : 'active' ?> contact<?= $total === 1 ? '' : 's' ?></p></div>
  <div class="actions">
    <?php if ($app->can('contacts.import')): ?><a class="btn btn-ghost" href="/contacts/import">Import CSV</a><?php endif; ?>
    <?php if ($app->can('contacts.export')): ?><a class="btn btn-ghost" href="/contacts/export<?= e(query_with($query, [])) ?>">Export CSV</a><?php endif; ?>
    <?php if ($app->can('contacts.create')): ?><a class="btn" href="/contacts/new">New contact</a><?php endif; ?>
  </div>
</div>
<form class="filters card" method="get" action="/contacts">
  <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, phone, email or company" aria-label="Search">
  <select name="status" aria-label="Lead status"><option value="">Any status</option>
    <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
  </select>
  <select name="tag" aria-label="Tag"><option value="">Any tag</option>
    <?php foreach ($tags as $t): ?><option value="<?= e($t) ?>"<?= $filters['tag'] === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
  </select>
  <label class="check"><input type="checkbox" name="archived" value="1"<?= $filters['archived'] ? ' checked' : '' ?>> Archived</label>
  <button class="btn btn-ghost" type="submit">Filter</button>
  <?php if ($query !== []): ?><a class="small" href="/contacts">Clear</a><?php endif; ?>
</form>
<?php if ($contacts === []): ?>
  <div class="empty-state card">
    <h2><?= $query === [] ? 'No contacts yet' : 'No contacts match these filters' ?></h2>
    <p class="muted"><?= $query === [] ? 'Contacts are created automatically when someone messages your WhatsApp number, or you can add them here.' : 'Try a broader search.' ?></p>
    <?php if ($query === [] && $app->can('contacts.create')): ?><a class="btn" href="/contacts/new">Add a contact</a><?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap card">
  <table class="table">
    <thead><tr>
      <th><?= $sortLink('name', 'Name') ?></th><th>Phone</th><th>Company</th><th><?= $sortLink('lead_status', 'Status') ?></th>
      <th>Owner</th><th>Consent</th><th><?= $sortLink('last_contacted_at', 'Last contact') ?></th><th><?= $sortLink('created_at', 'Created') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
      <tr>
        <td><a href="/contacts/<?= (int) $c['id'] ?>"><strong><?= e($c['name'] ?: 'Unnamed') ?></strong></a><?php if ($c['email']): ?><br><small class="muted"><?= e($c['email']) ?></small><?php endif; ?></td>
        <td class="mono"><?= e($c['phone_e164']) ?></td>
        <td><?= e($c['company'] ?? '—') ?></td>
        <td><span class="pill"><?= e($c['lead_status']) ?></span></td>
        <td><?= e($c['owner_name'] ?? '—') ?></td>
        <td><?= $c['opted_out_at'] ? '<span class="pill pill-error">opted out</span>' : e($c['consent_status']) ?></td>
        <td><?= e(time_ago($c['last_contacted_at'])) ?></td>
        <td><?= e(time_ago($c['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= $this->partial('partials/pagination', ['page' => $page, 'total' => $total, 'perPage' => $perPage, 'query' => $query]) ?>
<?php endif; ?>
