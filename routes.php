<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Api\V1Controller;
use App\Http\Controllers\Api\V1MessagingController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MetaConnectController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WhatsAppAccountController;
use App\Http\Middleware\ApiAuth;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateAny;
use App\Http\Middleware\Can;
use App\Http\Middleware\Guest;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\Throttle;
use App\Http\Middleware\VerifyCsrf;

return static function (Router $r): void {
    $r->get('/healthz', [HealthController::class, 'check']);
    $r->group([[Throttle::class, 'cron', '20', '60']], function (Router $r): void {
        $r->get('/cron', [CronController::class, 'run']);
    });

    // Provider webhooks: no session, no CSRF; authenticated by URL token + signature.
    $r->group([[Throttle::class, 'webhook', '600', '60']], function (Router $r): void {
        $r->get('/webhooks/whatsapp/{token}', [WebhookController::class, 'verify']);
        $r->post('/webhooks/whatsapp/{token}', [WebhookController::class, 'receive']);
        $r->get('/webhooks/meta', [WebhookController::class, 'metaVerify']);
        $r->post('/webhooks/meta', [WebhookController::class, 'metaReceive']);
    });

    // REST API v1 (bearer tokens).
    $r->group([ApiAuth::class], function (Router $r): void {
        $r->get('/api/v1/me', [V1Controller::class, 'me']);
        $r->get('/api/v1/contacts', [V1Controller::class, 'contacts']);
        $r->post('/api/v1/contacts', [V1Controller::class, 'createContact']);
        $r->get('/api/v1/contacts/{id}', [V1Controller::class, 'contact']);
        $r->patch('/api/v1/contacts/{id}', [V1Controller::class, 'updateContact']);
        $r->delete('/api/v1/contacts/{id}', [V1Controller::class, 'deleteContact']);
        $r->get('/api/v1/conversations', [V1Controller::class, 'conversations']);
        $r->get('/api/v1/conversations/{id}/messages', [V1Controller::class, 'messages']);
        $r->post('/api/v1/conversations/{id}/messages', [V1Controller::class, 'sendMessage']);
        $r->get('/api/v1/whatsapp-accounts', [V1Controller::class, 'accounts']);
        $r->get('/api/v1/templates', [V1MessagingController::class, 'templates']);
        $r->post('/api/v1/messages/template', [V1MessagingController::class, 'sendTemplate']);
        $r->get('/api/v1/campaigns', [V1MessagingController::class, 'campaigns']);
        $r->get('/api/v1/campaigns/{id}', [V1MessagingController::class, 'campaign']);
    });

    // Everything below uses sessions and CSRF protection.
    $r->group([VerifyCsrf::class], function (Router $r): void {
        $r->group([Guest::class], function (Router $r): void {
            $r->get('/login', [AuthController::class, 'showLogin']);
            $r->post('/login', [AuthController::class, 'login'], [[Throttle::class, 'login', '30', '60']]);
            $r->get('/two-factor', [AuthController::class, 'showTwoFactor']);
            $r->post('/two-factor', [AuthController::class, 'twoFactor'], [[Throttle::class, 'login', '30', '60']]);
            $r->get('/forgot-password', [AuthController::class, 'showForgot']);
            $r->post('/forgot-password', [AuthController::class, 'forgot'], [[Throttle::class, 'forgot', '10', '600']]);
            $r->get('/reset-password/{token}', [AuthController::class, 'showReset']);
            $r->post('/reset-password/{token}', [AuthController::class, 'reset'], [[Throttle::class, 'reset', '10', '600']]);
            $r->get('/invite/{token}', [AuthController::class, 'showInvite']);
            $r->post('/invite/{token}', [AuthController::class, 'acceptInvite'], [[Throttle::class, 'reset', '10', '600']]);
            $r->get('/register', [AuthController::class, 'showRegister']);
            $r->post('/register', [AuthController::class, 'register'], [[Throttle::class, 'register', '5', '3600']]);
        });

        $r->post('/logout', [AuthController::class, 'logout']);

        // Own account security (tenant users and super admins).
        $r->group([AuthenticateAny::class], function (Router $r): void {
            $r->get('/account/security', [SecurityController::class, 'show']);
            $r->post('/account/password', [SecurityController::class, 'changePassword']);
            $r->post('/account/2fa/start', [SecurityController::class, 'startTwoFactor']);
            $r->post('/account/2fa/confirm', [SecurityController::class, 'confirmTwoFactor'], [[Throttle::class, '2fa', '10', '300']]);
            $r->post('/account/2fa/disable', [SecurityController::class, 'disableTwoFactor']);
            $r->post('/account/logout-all', [SecurityController::class, 'logoutEverywhere']);
        });

        // Tenant workspace.
        $r->group([Authenticate::class], function (Router $r): void {
            $r->get('/', [DashboardController::class, 'index'], [[Can::class, 'dashboard.view']]);

            $r->get('/contacts', [ContactController::class, 'index'], [[Can::class, 'contacts.view']]);
            $r->get('/contacts/new', [ContactController::class, 'create'], [[Can::class, 'contacts.create']]);
            $r->get('/contacts/export', [ContactController::class, 'export'], [[Can::class, 'contacts.export']]);
            $r->get('/contacts/import', [ContactController::class, 'importForm'], [[Can::class, 'contacts.import']]);
            $r->post('/contacts/import', [ContactController::class, 'import'], [[Can::class, 'contacts.import'], [Throttle::class, 'import', '20', '600']]);
            $r->post('/contacts', [ContactController::class, 'store'], [[Can::class, 'contacts.create']]);
            $r->get('/contacts/{id}', [ContactController::class, 'show'], [[Can::class, 'contacts.view']]);
            $r->post('/contacts/{id}', [ContactController::class, 'update'], [[Can::class, 'contacts.update']]);
            $r->post('/contacts/{id}/archive', [ContactController::class, 'archive'], [[Can::class, 'contacts.delete']]);
            $r->post('/contacts/{id}/restore', [ContactController::class, 'restore'], [[Can::class, 'contacts.delete']]);
            $r->post('/contacts/{id}/tags', [ContactController::class, 'tags'], [[Can::class, 'contacts.update']]);
            $r->post('/contacts/{id}/notes', [ContactController::class, 'note'], [[Can::class, 'contacts.update']]);
            $r->post('/contacts/{id}/conversation', [ContactController::class, 'startConversation'], [[Can::class, 'inbox.reply']]);

            $r->get('/inbox', [InboxController::class, 'index'], [[Can::class, 'inbox.view']]);
            $r->get('/inbox/updates', [InboxController::class, 'updates'], [[Can::class, 'inbox.view']]);
            $r->get('/inbox/{id}', [InboxController::class, 'show'], [[Can::class, 'inbox.view']]);
            $r->get('/inbox/{id}/poll', [InboxController::class, 'poll'], [[Can::class, 'inbox.view']]);
            $r->post('/inbox/{id}/messages', [InboxController::class, 'send'], [[Can::class, 'inbox.reply']]);
            $r->post('/inbox/{id}/assign', [InboxController::class, 'assign'], [[Can::class, 'inbox.view']]);
            $r->post('/inbox/{id}/flag', [InboxController::class, 'flag'], [[Can::class, 'inbox.view']]);
            $r->post('/inbox/{id}/priority', [InboxController::class, 'priority'], [[Can::class, 'inbox.reply']]);
            $r->post('/inbox/{id}/notes', [InboxController::class, 'note'], [[Can::class, 'inbox.reply']]);
            $r->post('/inbox/{id}/template', [InboxController::class, 'sendTemplate'], [[Can::class, 'inbox.reply']]);

            $r->get('/templates', [TemplateController::class, 'index'], [[Can::class, 'templates.view']]);
            $r->get('/templates/new', [TemplateController::class, 'create'], [[Can::class, 'templates.manage']]);
            $r->post('/templates', [TemplateController::class, 'store'], [[Can::class, 'templates.manage']]);
            $r->post('/templates/sync', [TemplateController::class, 'sync'], [[Can::class, 'templates.manage']]);
            $r->get('/templates/{id}', [TemplateController::class, 'show'], [[Can::class, 'templates.view']]);
            $r->post('/templates/{id}', [TemplateController::class, 'update'], [[Can::class, 'templates.manage']]);
            $r->post('/templates/{id}/submit', [TemplateController::class, 'submit'], [[Can::class, 'templates.manage']]);
            $r->post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate'], [[Can::class, 'templates.manage']]);
            $r->post('/templates/{id}/delete', [TemplateController::class, 'destroy'], [[Can::class, 'templates.manage']]);

            $r->get('/campaigns', [CampaignController::class, 'index'], [[Can::class, 'campaigns.view']]);
            $r->post('/campaigns', [CampaignController::class, 'store'], [[Can::class, 'campaigns.manage']]);
            $r->get('/campaigns/{id}', [CampaignController::class, 'show'], [[Can::class, 'campaigns.view']]);
            $r->get('/campaigns/{id}/edit', [CampaignController::class, 'edit'], [[Can::class, 'campaigns.manage']]);
            $r->post('/campaigns/{id}', [CampaignController::class, 'update'], [[Can::class, 'campaigns.manage']]);
            $r->post('/campaigns/{id}/action', [CampaignController::class, 'action'], [[Can::class, 'campaigns.manage']]);
            $r->post('/campaigns/{id}/test', [CampaignController::class, 'test'], [[Can::class, 'campaigns.manage'], [Throttle::class, 'camptest', '20', '600']]);
            $r->post('/campaigns/{id}/duplicate', [CampaignController::class, 'duplicate'], [[Can::class, 'campaigns.manage']]);
            $r->post('/campaigns/{id}/delete', [CampaignController::class, 'destroy'], [[Can::class, 'campaigns.manage']]);
            $r->get('/campaigns/{id}/export', [CampaignController::class, 'export'], [[Can::class, 'campaigns.view']]);

            $r->get('/automations', [AutomationController::class, 'index'], [[Can::class, 'automations.manage']]);
            $r->get('/automations/new', [AutomationController::class, 'create'], [[Can::class, 'automations.manage']]);
            $r->post('/automations', [AutomationController::class, 'store'], [[Can::class, 'automations.manage']]);
            $r->get('/automations/{id}', [AutomationController::class, 'edit'], [[Can::class, 'automations.manage']]);
            $r->post('/automations/{id}', [AutomationController::class, 'update'], [[Can::class, 'automations.manage']]);
            $r->post('/automations/{id}/toggle', [AutomationController::class, 'toggle'], [[Can::class, 'automations.manage']]);
            $r->post('/automations/{id}/delete', [AutomationController::class, 'destroy'], [[Can::class, 'automations.manage']]);

            $r->get('/whatsapp', [WhatsAppAccountController::class, 'index'], [[Can::class, 'whatsapp.view']]);
            $r->get('/whatsapp/new', [WhatsAppAccountController::class, 'create'], [[Can::class, 'whatsapp.manage']]);
            $r->get('/whatsapp/connect', [MetaConnectController::class, 'show'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp/connect', [MetaConnectController::class, 'start'], [[Can::class, 'whatsapp.manage'], [Throttle::class, 'metaconnect', '20', '600']]);
            $r->post('/whatsapp/connect/choose', [MetaConnectController::class, 'choose'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp/connect/cancel', [MetaConnectController::class, 'cancelChoice'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp', [WhatsAppAccountController::class, 'store'], [[Can::class, 'whatsapp.manage']]);
            $r->get('/whatsapp/{id}', [WhatsAppAccountController::class, 'show'], [[Can::class, 'whatsapp.view']]);
            $r->post('/whatsapp/{id}', [WhatsAppAccountController::class, 'update'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp/{id}/test', [WhatsAppAccountController::class, 'test'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp/{id}/diagnose', [WhatsAppAccountController::class, 'diagnose'], [[Can::class, 'whatsapp.manage'], [Throttle::class, 'wadiagnose', '20', '600']]);
            $r->post('/whatsapp/{id}/rotate-webhook', [WhatsAppAccountController::class, 'rotateWebhookToken'], [[Can::class, 'whatsapp.manage']]);
            $r->post('/whatsapp/{id}/delete', [WhatsAppAccountController::class, 'destroy'], [[Can::class, 'whatsapp.manage']]);

            $r->get('/users', [UserController::class, 'index'], [[Can::class, 'users.view']]);
            $r->post('/users/invite', [UserController::class, 'invite'], [[Can::class, 'users.manage']]);
            $r->post('/users/{id}', [UserController::class, 'update'], [[Can::class, 'users.manage']]);
            $r->get('/roles', [RoleController::class, 'index'], [[Can::class, 'roles.manage']]);
            $r->post('/roles', [RoleController::class, 'store'], [[Can::class, 'roles.manage']]);
            $r->post('/roles/{id}', [RoleController::class, 'update'], [[Can::class, 'roles.manage']]);
            $r->get('/teams', [TeamController::class, 'index'], [[Can::class, 'teams.manage']]);
            $r->post('/teams', [TeamController::class, 'store'], [[Can::class, 'teams.manage']]);
            $r->post('/teams/{id}/members', [TeamController::class, 'members'], [[Can::class, 'teams.manage']]);
            $r->post('/teams/{id}/delete', [TeamController::class, 'destroy'], [[Can::class, 'teams.manage']]);

            $r->get('/settings/api-tokens', [ApiTokenController::class, 'index'], [[Can::class, 'api_tokens.manage']]);
            $r->post('/settings/api-tokens', [ApiTokenController::class, 'store'], [[Can::class, 'api_tokens.manage']]);
            $r->post('/settings/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke'], [[Can::class, 'api_tokens.manage']]);
            $r->get('/audit', [AuditController::class, 'index'], [[Can::class, 'audit.view']]);
        });

        // Platform super admin.
        $r->group([RequireSuperAdmin::class], function (Router $r): void {
            $r->get('/admin', [AdminController::class, 'index']);
            $r->post('/admin/tenants', [AdminController::class, 'storeTenant']);
            $r->post('/admin/tenants/{id}/status', [AdminController::class, 'tenantStatus']);
            $r->post('/admin/tenants/{id}/plan', [AdminController::class, 'tenantPlan']);
            $r->post('/admin/plans', [AdminController::class, 'plans']);
            $r->get('/admin/health', [AdminController::class, 'health']);
            $r->get('/admin/meta', [AdminController::class, 'meta']);
            $r->post('/admin/meta', [AdminController::class, 'metaSave']);
            $r->post('/admin/meta/test', [AdminController::class, 'metaTest']);
            $r->post('/admin/meta/tokens', [AdminController::class, 'metaTokens'], [[Throttle::class, 'metatokens', '5', '600']]);
            $r->post('/admin/failed-jobs/{id}/retry', [AdminController::class, 'retryFailed']);
            $r->get('/admin/audit', [AdminController::class, 'audit']);
        });
    });
};
