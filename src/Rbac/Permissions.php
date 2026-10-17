<?php

declare(strict_types=1);

namespace App\Rbac;

/** Permission catalogue and default role templates (copied into each new tenant). */
final class Permissions
{
    public const ALL = [
        'dashboard.view' => 'View dashboard metrics',
        'contacts.view' => 'View contacts',
        'contacts.create' => 'Create contacts',
        'contacts.update' => 'Edit contacts, tags and notes',
        'contacts.delete' => 'Archive / delete contacts',
        'contacts.export' => 'Export contacts',
        'contacts.import' => 'Import contacts from CSV',
        'templates.view' => 'View message templates',
        'templates.manage' => 'Create, submit and delete message templates',
        'campaigns.view' => 'View campaigns and reports',
        'campaigns.manage' => 'Create, schedule, pause and cancel campaigns',
        'automations.manage' => 'Manage auto-replies and automation rules',
        'inbox.view' => 'View conversations assigned to me or my teams',
        'inbox.view_all' => 'View all conversations in the company',
        'inbox.reply' => 'Send messages',
        'inbox.assign' => 'Assign and transfer conversations',
        'whatsapp.view' => 'View WhatsApp accounts',
        'whatsapp.manage' => 'Connect and configure WhatsApp accounts',
        'users.view' => 'View users',
        'users.manage' => 'Invite, edit and suspend users',
        'roles.manage' => 'Create and edit roles',
        'teams.manage' => 'Manage teams',
        'api_tokens.manage' => 'Create and revoke API tokens',
        'audit.view' => 'View audit log',
        'settings.manage' => 'Manage company settings',
    ];

    /** @return array<string,array{name:string,permissions:list<string>}> */
    public static function defaultRoles(): array
    {
        $all = array_keys(self::ALL);
        $agent = ['dashboard.view', 'contacts.view', 'contacts.create', 'contacts.update', 'inbox.view', 'inbox.reply', 'whatsapp.view', 'templates.view'];
        return [
            'owner' => ['name' => 'Company Owner', 'permissions' => $all],
            'admin' => ['name' => 'Company Admin', 'permissions' => $all],
            'crm_manager' => ['name' => 'CRM Manager', 'permissions' => array_merge($agent, [
                'contacts.delete', 'contacts.export', 'contacts.import', 'inbox.view_all', 'inbox.assign', 'teams.manage', 'users.view',
                'campaigns.view', 'automations.manage',
            ])],
            'team_leader' => ['name' => 'Team Leader', 'permissions' => array_merge($agent, ['inbox.assign', 'users.view', 'campaigns.view'])],
            'sales_agent' => ['name' => 'Sales Agent', 'permissions' => $agent],
            'support_agent' => ['name' => 'Customer Support Agent', 'permissions' => $agent],
            'marketing_manager' => ['name' => 'Marketing Manager', 'permissions' => [
                'dashboard.view', 'contacts.view', 'contacts.create', 'contacts.update', 'contacts.export', 'contacts.import', 'whatsapp.view',
                'templates.view', 'templates.manage', 'campaigns.view', 'campaigns.manage', 'automations.manage',
            ]],
            'analyst' => ['name' => 'Read-Only Analyst', 'permissions' => ['dashboard.view', 'contacts.view', 'inbox.view', 'inbox.view_all',
                'whatsapp.view', 'templates.view', 'campaigns.view']],
        ];
    }
}
