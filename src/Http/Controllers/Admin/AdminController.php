<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\PasswordPolicy;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantProvisioner;
use App\WhatsApp\MetaAutoConfigurator;
use App\WhatsApp\MetaPlatform;
use App\WhatsApp\TokenMonitor;

/**
 * Platform administration. Deliberately has no access to message contents:
 * only tenant metadata, counts, plans and system health.
 */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $db = $this->app->db;
        return $this->app->render('admin/index', [
            'tenants' => $db->all(
                'SELECT t.*, p.name AS plan_name,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users,
                    (SELECT COUNT(*) FROM whatsapp_accounts a WHERE a.tenant_id = t.id) AS accounts,
                    (SELECT COUNT(*) FROM contacts c WHERE c.tenant_id = t.id) AS contacts,
                    (SELECT COUNT(*) FROM messages m WHERE m.tenant_id = t.id AND m.created_at >= ?) AS messages_month
                 FROM tenants t LEFT JOIN subscription_plans p ON p.id = t.plan_id ORDER BY t.created_at DESC',
                [gmdate('Y-m-01 00:00:00')]
            ),
            'plans' => $db->all('SELECT * FROM subscription_plans ORDER BY price_cents'),
        ]);
    }

    public function storeTenant(Request $request): Response
    {
        $v = Validator::make($request->post, [
            'company' => 'required|string|max:160', 'owner_name' => 'required|string|max:160',
            'owner_email' => 'required|email|max:191', 'plan_id' => 'nullable|int',
        ]);
        $errors = $v->errors();
        $d = $v->validated();
        $password = (string) ($request->post['owner_password'] ?? '');
        if (($err = PasswordPolicy::check($password, (string) ($d['owner_email'] ?? ''))) !== null) {
            $errors['owner_password'] = $err;
        }
        if (!isset($errors['owner_email']) && $this->app->db->scalar('SELECT 1 FROM users WHERE email = ?', [strtolower((string) $d['owner_email'])]) !== null) {
            $errors['owner_email'] = 'Email already in use.';
        }
        if (!empty($d['plan_id']) && $this->app->db->scalar('SELECT 1 FROM subscription_plans WHERE id = ?', [$d['plan_id']]) === null) {
            $errors['plan_id'] = 'Unknown plan.';
        }
        if ($errors !== []) {
            return $this->backWithErrors($request, $errors, '/admin');
        }
        $ids = (new TenantProvisioner($this->app->db))->provision(
            (string) $d['company'], (string) $d['owner_name'], (string) $d['owner_email'], $password, $d['plan_id'] ?: null
        );
        $this->app->auditLog('admin.tenant_created', 'tenant', $ids['tenant_id'], ['company' => $d['company']]);
        $this->flash('success', 'Company created. The owner can now sign in.');
        return Response::redirect('/admin');
    }

    public function tenantStatus(Request $request): Response
    {
        $tenant = $this->app->db->first('SELECT * FROM tenants WHERE id = ?', [$request->intParam('id')]) ?? throw HttpException::notFound();
        $status = $request->str('status');
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new HttpException(422, 'Invalid status');
        }
        $this->app->db->update('tenants', [
            'status' => $status,
            'suspended_at' => $status === 'suspended' ? now() : null,
            'suspended_reason' => $status === 'suspended' ? mb_substr($request->str('reason'), 0, 255) : null,
            'updated_at' => now(),
        ], ['id' => $tenant['id']]);
        $this->app->auditLog('admin.tenant_' . $status, 'tenant', (int) $tenant['id'], ['reason' => $request->str('reason')]);
        $this->flash('success', $tenant['name'] . ($status === 'suspended' ? ' suspended. Its users are signed out and sending stops.' : ' reactivated.'));
        return Response::redirect('/admin');
    }

    public function tenantPlan(Request $request): Response
    {
        $tenant = $this->app->db->first('SELECT * FROM tenants WHERE id = ?', [$request->intParam('id')]) ?? throw HttpException::notFound();
        $planId = (int) ($request->post['plan_id'] ?? 0);
        if ($planId > 0 && $this->app->db->scalar('SELECT 1 FROM subscription_plans WHERE id = ?', [$planId]) === null) {
            throw HttpException::notFound();
        }
        $this->app->db->update('tenants', ['plan_id' => $planId ?: null, 'updated_at' => now()], ['id' => $tenant['id']]);
        $this->app->auditLog('admin.tenant_plan_changed', 'tenant', (int) $tenant['id'], ['plan_id' => $planId ?: null]);
        $this->flash('success', 'Plan updated.');
        return Response::redirect('/admin');
    }

    public function plans(Request $request): Response
    {
        $v = Validator::make($request->post, [
            'code' => 'required|string|max:64', 'name' => 'required|string|max:120', 'price_cents' => 'required|int',
            'currency' => 'required|string|max:3', 'billing_interval' => 'required|in:month,year',
            'max_agents' => 'nullable|int', 'max_whatsapp_accounts' => 'nullable|int', 'max_contacts' => 'nullable|int',
        ]);
        if ($v->fails()) {
            return $this->backWithErrors($request, $v->errors(), '/admin');
        }
        $d = $v->validated();
        if (!preg_match('/^[a-z0-9_\-]+$/', (string) $d['code'])) {
            return $this->backWithErrors($request, ['code' => 'Use lowercase letters, numbers, - or _.'], '/admin');
        }
        $row = ['name' => $d['name'], 'price_cents' => max(0, (int) $d['price_cents']), 'currency' => strtoupper((string) $d['currency']),
            'billing_interval' => $d['billing_interval'], 'max_agents' => $d['max_agents'], 'max_whatsapp_accounts' => $d['max_whatsapp_accounts'],
            'max_contacts' => $d['max_contacts'], 'updated_at' => now()];
        $existing = $this->app->db->scalar('SELECT id FROM subscription_plans WHERE code = ?', [$d['code']]);
        if ($existing !== null) {
            $this->app->db->update('subscription_plans', $row, ['id' => (int) $existing]);
        } else {
            $this->app->db->insert('subscription_plans', $row + ['code' => $d['code'], 'is_active' => 1, 'created_at' => now()]);
        }
        $this->app->auditLog('admin.plan_saved', 'plan', null, ['code' => $d['code']]);
        $this->flash('success', 'Plan saved.');
        return Response::redirect('/admin');
    }

    public function health(Request $request): Response
    {
        $db = $this->app->db;
        $dbOk = true;
        try {
            $db->scalar('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }
        $storage = base_path('storage');
        return $this->app->render('admin/health', [
            'dbOk' => $dbOk, 'driver' => $db->driver,
            'queues' => $db->all('SELECT queue, COUNT(*) AS n, MIN(created_at) AS oldest, SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved FROM jobs GROUP BY queue'),
            'failed' => $db->all('SELECT id, queue, exception, failed_at FROM failed_jobs ORDER BY id DESC LIMIT 50'),
            'accounts' => $db->all("SELECT a.id, a.name, a.provider, a.status, a.last_synced_at, a.last_error_at, t.name AS tenant FROM whatsapp_accounts a JOIN tenants t ON t.id = a.tenant_id ORDER BY a.status, a.name"),
            'diskFree' => @disk_free_space($storage) ?: null,
            'storageWritable' => is_writable($storage),
            'php' => PHP_VERSION,
            'lastWorker' => is_file($storage . '/worker.heartbeat') ? (int) file_get_contents($storage . '/worker.heartbeat') : null,
            'queueMode' => strtolower((string) env('QUEUE_MODE', 'worker')),
            'lastCron' => \App\Core\BackgroundRunner::lastRun(),
            'lastSchedule' => \App\Core\Scheduler::stamp(\App\Core\Scheduler::STAMP) ?: null,
            'webCron' => strlen((string) env('CRON_KEY', '')) >= 16,
            'basePath' => base_path(),
        ]);
    }

    public function retryFailed(Request $request): Response
    {
        if ($this->app->queue->retryFailed($request->intParam('id'))) {
            $this->app->auditLog('admin.job_retried', 'failed_job', $request->intParam('id'));
            $this->flash('success', 'Job re-queued.');
        }
        return Response::redirect('/admin/health');
    }

    // ------------------------------------------------------------ platform Meta app

    public function meta(Request $request): Response
    {
        $verify = MetaPlatform::ensureVerifyToken($this->app->db);
        $cfg = MetaPlatform::config($this->app->db);
        return $this->app->render('admin/meta', [
            'cfg' => $cfg, 'verify' => $verify, 'ready' => MetaPlatform::signupReady($this->app->db),
            'callback' => url('/webhooks/meta'), 'domain' => (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST),
            'https' => str_starts_with((string) env('APP_URL', ''), 'https://'),
            'connected' => (int) $this->app->db->scalar("SELECT COUNT(*) FROM whatsapp_accounts WHERE provider = 'meta_cloud'"),
            'autoconfig' => $this->app->session->getFlash('autoconfig'),
            'tokens' => (array) $this->app->db->first("SELECT
                SUM(CASE WHEN token_checked_at IS NOT NULL AND token_expires_at IS NULL AND status <> 'needs_reconnect' THEN 1 ELSE 0 END) AS never,
                SUM(CASE WHEN token_expires_at IS NOT NULL AND status <> 'needs_reconnect' THEN 1 ELSE 0 END) AS dated,
                MIN(CASE WHEN status <> 'needs_reconnect' THEN token_expires_at END) AS next_expiry,
                SUM(CASE WHEN status = 'needs_reconnect' THEN 1 ELSE 0 END) AS broken,
                SUM(CASE WHEN token_checked_at IS NULL AND status <> 'needs_reconnect' THEN 1 ELSE 0 END) AS unchecked
                FROM whatsapp_accounts WHERE provider = 'meta_cloud'"),
        ]);
    }

    /** Run the daily token check now for every Facebook-connected number. */
    public function metaTokens(Request $request): Response
    {
        $n = (new TokenMonitor($this->app->db, $this->app->http, $this->app->mailer))->checkAll();
        $this->app->auditLog('platform.meta_tokens_checked', null, null, ['checked' => $n]);
        $this->flash('success', "Checked $n Facebook-connected number(s) with Meta.");
        return Response::redirect('/admin/meta');
    }

    public function metaSave(Request $request): Response
    {
        $appId = $request->str('app_id');
        $configId = $request->str('config_id');
        $secret = trim((string) ($request->post['app_secret'] ?? ''));
        $errors = [];
        if ($appId !== '' && !preg_match('/^\d{5,25}$/', $appId)) {
            $errors['app_id'] = 'The App ID is a number (App settings → Basic).';
        }
        if ($configId !== '' && !preg_match('/^\d{5,25}$/', $configId)) {
            $errors['config_id'] = 'The configuration ID is a number (Facebook Login for Business → Configurations).';
        }
        if ($secret !== '' && !preg_match('/^[a-f0-9]{32}$/i', $secret)) {
            $errors['app_secret'] = 'The App Secret is 32 letters/numbers (App settings → Basic → Show).';
        }
        if ($errors !== []) {
            return $this->backWithErrors($request, $errors, '/admin/meta');
        }
        $values = ['app_id' => $appId, 'config_id' => $configId, 'app_secret' => $secret];
        if (!empty($request->post['rotate_verify'])) {
            $values['verify_token'] = random_token(24);
        }
        MetaPlatform::save($this->app->db, $values, $this->app->userId());
        $this->app->auditLog('admin.meta_app_saved', null, null, ['app_id' => $appId, 'secret_changed' => $secret !== '', 'verify_rotated' => !empty($request->post['rotate_verify'])]);
        if (!empty($request->post['autoconfigure'])) {
            return $this->metaAutoconfigure($request);
        }
        $this->flash('success', 'Meta app settings saved.');
        return Response::redirect('/admin/meta');
    }

    /** Register the platform webhook with Meta and report what is left to do by hand. */
    public function metaAutoconfigure(Request $request): Response
    {
        $checks = (new MetaAutoConfigurator($this->app->db, $this->app->http))->run(url('/webhooks/meta'));
        $failed = array_filter($checks, static fn (array $c) => $c['state'] === MetaAutoConfigurator::FAIL) !== [];
        $this->app->auditLog('admin.meta_autoconfigured', null, null, ['ok' => !$failed,
            'checks' => array_map(static fn (array $c) => $c['label'] . ': ' . $c['state'], $checks)]);
        $this->app->session->flash('autoconfig', $checks);
        $this->flash($failed ? 'error' : 'success', $failed ? 'Auto-configure stopped at a step that needs attention (see the checklist).'
            : 'Meta app settings saved and webhook configured automatically.');
        return Response::redirect('/admin/meta');
    }

    /** Checks the App ID + secret pair with Meta (app access token). */
    public function metaTest(Request $request): Response
    {
        $cfg = MetaPlatform::config($this->app->db);
        if ($cfg['app_id'] === '' || $cfg['app_secret'] === '') {
            $this->flash('error', 'Save the App ID and App Secret first.');
            return Response::redirect('/admin/meta');
        }
        $base = rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/');
        try {
            $r = $this->app->http->request('GET', $base . '/' . MetaPlatform::graphVersion() . '/' . $cfg['app_id']
                . '?' . http_build_query(['fields' => 'name', 'access_token' => $cfg['app_id'] . '|' . $cfg['app_secret']]));
            $json = json_decode($r['body'], true) ?: [];
            $ok = $r['status'] === 200 && isset($json['name']);
            $this->flash($ok ? 'success' : 'error', $ok ? 'Meta accepted the credentials for app "' . $json['name'] . '".'
                : 'Meta rejected the App ID / Secret: ' . (string) ($json['error']['message'] ?? 'HTTP ' . $r['status']));
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not reach Meta: ' . $e->getMessage());
        }
        return Response::redirect('/admin/meta');
    }

    public function audit(Request $request): Response
    {
        $page = $this->page($request);
        return $this->app->render('audit/index', [
            'rows' => $this->app->db->all(
                'SELECT a.*, u.name AS user_name, t.name AS tenant_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                 LEFT JOIN tenants t ON t.id = a.tenant_id ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50)
            ),
            'total' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM audit_logs'),
            'page' => $page,
            'admin' => true,
        ]);
    }
}
