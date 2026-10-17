<?php $this->layout('layouts/app', ['title' => 'Import contacts']); ?>
<div class="page-head"><div><a class="small" href="/contacts">← Contacts</a><h1>Import contacts</h1>
  <p class="muted">Upload a CSV file (from Excel or Google Sheets: File → Save as / Download → CSV).</p></div></div>

<?php if (!empty($result)): ?>
  <section class="card">
    <h2>Import finished</h2>
    <section class="kpis">
      <div class="kpi"><span>Created</span><strong><?= number_format((int) $result['created']) ?></strong></div>
      <div class="kpi"><span>Updated</span><strong><?= number_format((int) $result['updated']) ?></strong></div>
      <div class="kpi"><span>Skipped</span><strong><?= number_format((int) $result['skipped']) ?></strong></div>
    </section>
    <?php if (!empty($result['tag_names'])): ?><p>Tagged: <?php foreach ($result['tag_names'] as $tg): ?><a class="tag" href="/contacts?tag=<?= e(urlencode($tg)) ?>"><?= e($tg) ?></a> <?php endforeach; ?>. Use this tag to target them in a <a href="/campaigns">campaign</a>.</p><?php endif; ?>
    <?php if (!empty($result['errors'])): ?>
      <details><summary><?= count($result['errors']) ?> row note(s)</summary>
        <ul class="list small"><?php foreach ($result['errors'] as $er): ?><li><span>Row <?= (int) $er['row'] ?></span><span><?= e($er['reason']) ?></span></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
  </section>
<?php endif; ?>

<div class="grid-2 grid-2-wide">
<form method="post" action="/contacts/import" enctype="multipart/form-data" class="card form">
  <?= csrf_field($app) ?>
  <label>CSV file <input type="file" name="file" accept=".csv,text/csv" required><?= field_error($errors, 'file') ?></label>
  <label>Add these tags to everyone imported <input name="tags" placeholder="diwali-2026, wholesale" value="<?= e(old_value($old, 'tags')) ?>"></label>
  <label>Source <input name="source" placeholder="import" maxlength="60" value="<?= e(old_value($old, 'source')) ?>"></label>
  <label class="check"><input type="checkbox" name="update_existing" value="1"<?= old_value($old, 'update_existing') ? ' checked' : '' ?>> <span>Update contacts that already exist (matched by phone number)</span></label>
  <label class="check"><input type="checkbox" name="consent_all" value="1" data-consent-all<?= old_value($old, 'consent_all') ? ' checked' : '' ?>> <span>Mark everyone as having given WhatsApp consent</span></label>
  <label class="check" data-consent-confirm hidden><input type="checkbox" name="consent_confirm" value="1"> <span>I confirm all these people agreed to receive WhatsApp messages from us. <small class="muted">(Recorded in the audit log. Contacts who previously opted out stay opted out.)</small></span></label>
  <?= field_error($errors, 'consent_all') ?>
  <div class="form-actions"><button class="btn" type="submit">Import</button></div>
</form>
<section class="card">
  <h2>File format</h2>
  <p class="small">The first row must be column names. Only <b>phone</b> is required.</p>
  <pre class="code-block">name,phone,email,city,tags,consent
Asha Verma,+91 98765 43210,asha@example.com,Pune,vip;wholesale,yes
Ravi Kumar,09812345678,,Kanpur,,no</pre>
  <ul class="small">
    <li><b>phone</b> (or mobile / whatsapp): with country code; local numbers use your default code.</li>
    <li><b>name</b>, or <b>first name</b> + <b>last name</b>; <b>email</b>, <b>company</b>, <b>city</b>, <b>address</b>, <b>source</b>, <b>lead status</b>.</li>
    <li><b>tags</b>: separate several with ; or |.</li>
    <li><b>consent</b>: yes/no. "no" marks the contact as opted out.</li>
    <li>Up to 20,000 rows and 5 MB per file. Duplicate numbers in the file are skipped.</li>
  </ul>
</section>
</div>
