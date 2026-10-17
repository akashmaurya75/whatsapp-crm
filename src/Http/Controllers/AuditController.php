<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $page = $this->page($request);
        $tid = $this->app->tenant->id();
        return $this->app->render('audit/index', [
            'rows' => $this->app->db->all(
                'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50),
                [$tid]
            ),
            'total' => (int) $this->app->db->scalar('SELECT COUNT(*) FROM audit_logs WHERE tenant_id = ?', [$tid]),
            'page' => $page,
            'admin' => false,
        ]);
    }
}
