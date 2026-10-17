<?php
$this->layout('layouts/app', ['title' => 'Dashboard']);
$fmtDur = static function (?int $s): string {
    if ($s === null) { return '—'; }
    return match (true) { $s < 60 => $s . 's', $s < 3600 => round($s / 60) . 'm', default => round($s / 3600, 1) . 'h' };
};
$outTotal = array_sum($byStatus) ?: 0;
$deliveredish = $byStatus['delivered'] + $byStatus['read'];
$max = 1;
foreach ($series as $d) { $max = max($max, $d['in'], $d['out']); }
$n = count($series);
$w = 720; $h = 160; $pad = 24;
$slot = ($w - $pad) / max(1, $n);
$bw = max(2, min(14, $slot / 2 - 2));
?>
<div class="page-head">
  <div><h1>Dashboard</h1><p class="muted">Figures are calculated from your stored data for the selected period.</p></div>
  <form class="filters" method="get" action="/">
    <label class="sr-only" for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>">
    <label class="sr-only" for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>">
    <select name="account" aria-label="WhatsApp account">
      <option value="">All accounts</option>
      <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $accountId === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">Apply</button>
  </form>
</div>

<?php if ($accounts === []): ?>
  <div class="callout">
    <strong>Connect a WhatsApp account to start.</strong>
    <span>Messages, conversations and delivery stats appear here once an account receives traffic.</span>
    <?php if ($app->can('whatsapp.manage')): ?><a class="btn btn-sm" href="/whatsapp/new?provider=meta_cloud">Connect account</a><?php endif; ?>
  </div>
<?php endif; ?>

<section class="kpis" aria-label="Key metrics">
  <a class="kpi" href="/inbox?status=open"><span>Open conversations</span><strong><?= number_format((int) ($conv['open_count'] ?? 0)) ?></strong><small><?= number_format((int) ($conv['total'] ?? 0)) ?> total</small></a>
  <a class="kpi" href="/inbox?assigned=awaiting"><span>Awaiting reply</span><strong><?= number_format((int) ($conv['awaiting'] ?? 0)) ?></strong><small><?= number_format((int) ($conv['unassigned'] ?? 0)) ?> unassigned</small></a>
  <div class="kpi"><span>Avg. reply time</span><strong><?= e($fmtDur($avgResponse)) ?></strong><small><?= $responseSample ? 'from ' . $responseSample . ' replied messages' : 'no replies in period' ?></small></div>
  <div class="kpi"><span>Messages</span><strong><?= number_format($sent + $received) ?></strong><small><?= number_format($received) ?> in · <?= number_format($sent) ?> out</small></div>
  <a class="kpi" href="/contacts"><span>Contacts</span><strong><?= number_format($contactsTotal) ?></strong><small>+<?= number_format($contactsNew) ?> in period · <?= number_format($optedOut) ?> opted out</small></a>
  <div class="kpi"><span>Follow-ups today</span><strong><?= number_format($followUpsToday) ?></strong><small>contacts with a follow-up date today</small></div>
</section>

<div class="grid-2">
  <section class="card">
    <header class="card-head"><h2>Message volume</h2><span class="legend"><i class="sw sw-in"></i>Inbound <i class="sw sw-out"></i>Outbound</span></header>
    <?php if ($sent + $received === 0): ?>
      <p class="empty">No messages in this period.</p>
    <?php else: ?>
    <svg class="chart" viewBox="0 0 <?= $w ?> <?= $h + 22 ?>" role="img" aria-label="Daily inbound and outbound messages">
      <line x1="<?= $pad ?>" y1="<?= $h ?>" x2="<?= $w ?>" y2="<?= $h ?>" class="axis"/>
      <text x="0" y="12" class="tick"><?= $max ?></text>
      <?php $i = 0; foreach ($series as $day => $d):
        $x = $pad + $i * $slot + ($slot - 2 * $bw - 2) / 2;
        $hi = (int) round(($d['in'] / $max) * ($h - 10)); $ho = (int) round(($d['out'] / $max) * ($h - 10)); ?>
        <rect class="bar-in" x="<?= round($x, 1) ?>" y="<?= $h - $hi ?>" width="<?= round($bw, 1) ?>" height="<?= $hi ?>"><title><?= e($day) ?>: <?= $d['in'] ?> inbound</title></rect>
        <rect class="bar-out" x="<?= round($x + $bw + 2, 1) ?>" y="<?= $h - $ho ?>" width="<?= round($bw, 1) ?>" height="<?= $ho ?>"><title><?= e($day) ?>: <?= $d['out'] ?> outbound</title></rect>
        <?php if ($n <= 14 || $i % (int) ceil($n / 10) === 0): ?><text x="<?= round($x, 1) ?>" y="<?= $h + 16 ?>" class="tick"><?= e(substr($day, 5)) ?></text><?php endif; ?>
      <?php $i++; endforeach; ?>
    </svg>
    <?php endif; ?>
  </section>

  <section class="card">
    <header class="card-head"><h2>Outbound delivery</h2><span class="muted small">statuses reported by providers</span></header>
    <?php if ($outTotal === 0): ?>
      <p class="empty">No outbound messages in this period.</p>
    <?php else: ?>
      <ul class="statlist">
        <?php foreach ($byStatus as $s => $c): if ($c === 0) { continue; } ?>
          <li><span class="status status-<?= e($s) ?>"><?= e(ucfirst($s)) ?></span><span><?= number_format($c) ?></span><span class="muted"><?= round($c / $outTotal * 100, 1) ?>%</span></li>
        <?php endforeach; ?>
      </ul>
      <p class="muted small">Delivered or read: <?= round($deliveredish / $outTotal * 100, 1) ?>%. "Submitted" means accepted by the provider and awaiting a delivery receipt.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <header class="card-head"><h2>WhatsApp accounts</h2><a class="small" href="/whatsapp">Manage</a></header>
    <?php if ($accounts === []): ?><p class="empty">No accounts connected.</p><?php else: ?>
    <ul class="list">
      <?php foreach ($accounts as $a): ?>
        <li><span><strong><?= e($a['name']) ?></strong><br><small class="muted"><?= $a['provider'] === 'meta_cloud' ? 'Meta Cloud API (official)' : 'OpenWA gateway (unofficial)' ?> · synced <?= e(time_ago($a['last_synced_at'])) ?></small></span>
          <span class="pill pill-<?= e($a['status']) ?>"><?= e($a['status']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($failedJobs > 0): ?><p class="alert alert-error small"><?= $failedJobs ?> message job(s) failed permanently. Check the account's error history.</p><?php endif; ?>
  </section>

  <section class="card">
    <header class="card-head"><h2>Lead status</h2><a class="small" href="/contacts">Contacts</a></header>
    <?php if ($leadStatuses === []): ?><p class="empty">No contacts yet.</p><?php else: ?>
    <ul class="statlist">
      <?php foreach ($leadStatuses as $l): ?>
        <li><a href="/contacts?status=<?= e(urlencode((string) $l['lead_status'])) ?>"><?= e(ucfirst((string) $l['lead_status'])) ?></a><span><?= number_format((int) $l['n']) ?></span><span class="muted"><?= $contactsTotal ? round($l['n'] / $contactsTotal * 100) : 0 ?>%</span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
