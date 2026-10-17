<?php
$this->layout('install/layout', ['title' => 'Installed', 'step' => 4]);
$local = !empty($info['local']);
$path = (string) ($info['path'] ?? '/var/www/whatsapp-crm');
?>
<h1 class="auth-title">WhatsApp CRM is installed 🎉</h1>
<div class="alert alert-success">The installer has locked itself and can't be run again.</div>
<ul class="list">
  <li><span>Company login</span><b><?= e($info['owner'] ?? '') ?></b></li>
  <li><span>Platform admin login</span><b><?= e($info['admin'] ?? '') ?></b></li>
</ul>

<?php
$hosting = (string) ($info['hosting'] ?? ($local ? 'local' : 'shared'));
$php = (array) ($info['php'] ?? []);
$phpBin = $php[0] ?? '/usr/local/bin/php';
$origin = preg_replace('#^(https?://[^/]+).*$#', '$1', (string) ($info['url'] ?? ''));
$cronUrl = $origin . '/cron?key=' . (string) ($info['cron_key'] ?? '');
?>
<?php if (!empty($info['forced_https_off'])): ?>
  <div class="alert alert-warn">Your address starts with https, but this server didn't report https (common with Cloudflare "Flexible" SSL),
  so automatic https redirects were left off to avoid a redirect loop. Use Cloudflare "Full" SSL or install an SSL certificate in your panel,
  then set <code>APP_FORCE_HTTPS=true</code> in the <code>.env</code> file.</div>
<?php endif; ?>

<?php if ($hosting === 'shared'): ?>
  <h2 class="install-h2">One more thing: add one cron job</h2>
  <p class="small">It sends your campaigns and processes incoming messages in the background. In your panel open
  <b>Cron Jobs</b> (cPanel → Advanced → Cron Jobs · Hostinger → Advanced → Cron Jobs · Plesk → Scheduled Tasks),
  choose <b>Once per minute</b> (<code>* * * * *</code>) and paste this command:</p>
  <pre class="code-block cron-line"><?= e($phpBin) ?> <?= e($path) ?>/bin/console cron --quiet</pre>
  <?php if (count($php) > 1): ?>
    <p class="small muted">Other PHP programs found on this server: <?php foreach (array_slice($php, 1) as $p): ?><code><?= e($p) ?></code> <?php endforeach; ?></p>
  <?php elseif ($php === []): ?>
    <p class="small muted">If the cron email says "PHP 8.3 required", replace <code>/usr/local/bin/php</code> with your host's PHP 8.3 path
    (e.g. <code>/opt/alt/php83/usr/bin/php</code> or <code>/opt/cpanel/ea-php83/root/usr/bin/php</code>).</p>
  <?php endif; ?>
  <p class="small muted">Hostinger's cron form asks only for the part after <code>php</code>: enter <code><?= e($path) ?>/bin/console cron --quiet</code>.</p>
  <details class="small">
    <summary>My panel can only call a URL (or has no cron)</summary>
    <p>Use this secret address every minute, from your panel or a free service such as cron-job.org. Keep it private.</p>
    <pre class="code-block cron-line">wget -q -O /dev/null "<?= e($cronUrl) ?>"</pre>
    <pre class="code-block cron-line"><?= e($cronUrl) ?></pre>
  </details>
  <p class="small muted">Messages are also processed right after page visits, so the app keeps working while you set this up.
  You can check that the cron runs under Admin → System health.</p>
<?php elseif ($local): ?>
  <h2 class="install-h2">One more thing: start the background worker</h2>
  <p class="small">It receives WhatsApp messages and sends your replies. Without it, messages stay "queued".
  Open a second terminal in the app folder and run:</p>
  <pre class="code-block">php bin/console queue:work</pre>
  <p class="small muted">Next time, <code>./start.sh</code> does this for you.</p>
  <p class="small muted">To receive real WhatsApp messages on your computer, Meta must be able to reach it. Use a tunnel such as
  <code>cloudflared tunnel --url <?= e($origin ?: 'http://localhost:8000') ?></code> and put that https address in <code>APP_URL</code> in the <code>.env</code> file.</p>
<?php else: ?>
  <h2 class="install-h2">One more thing: start the background worker</h2>
  <p class="small">On the server (SSH), install the worker service and the scheduler:</p>
  <pre class="code-block cron-line">sudo cp <?= e($path) ?>/deploy/whatsapp-crm-worker.service /etc/systemd/system/
sudo sed -i 's#/var/www/whatsapp-crm#<?= e($path) ?>#' /etc/systemd/system/whatsapp-crm-worker.service
sudo systemctl daemon-reload &amp;&amp; sudo systemctl enable --now whatsapp-crm-worker
echo '* * * * * www-data cd <?= e($path) ?> &amp;&amp; php bin/console schedule:run' | sudo tee /etc/cron.d/whatsapp-crm</pre>
<?php endif; ?>

<h2 class="install-h2">Next steps</h2>
<ol class="small">
  <li>Sign in and turn on two-factor authentication (top-right menu → Security).</li>
  <li>Connect your number: WhatsApp accounts → Continue with Facebook, or connect manually (see docs/WHATSAPP_SETUP.md).</li>
  <li>Invite your team under Users.</li>
</ol>
<a class="btn btn-block" href="/login">Go to sign in</a>
