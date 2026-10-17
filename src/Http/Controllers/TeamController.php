<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

final class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $members = [];
        foreach ($this->app->db->all('SELECT tm.team_id, tm.user_id FROM team_members tm WHERE tm.tenant_id = ?', [$tid]) as $m) {
            $members[$m['team_id']][$m['user_id']] = true;
        }
        return $this->app->render('teams/index', [
            'teams' => $this->app->db->all('SELECT * FROM teams WHERE tenant_id = ? ORDER BY name', [$tid]),
            'users' => $this->app->db->all("SELECT id, name FROM users WHERE tenant_id = ? AND status <> 'suspended' ORDER BY name", [$tid]),
            'members' => $members,
        ]);
    }

    public function store(Request $request): Response
    {
        $name = mb_substr($request->str('name'), 0, 120);
        if ($name === '') {
            return $this->backWithErrors($request, ['name' => 'Team name is required.'], '/teams');
        }
        if ($this->app->db->scalar('SELECT 1 FROM teams WHERE tenant_id = ? AND name = ?', [$this->app->tenant->id(), $name]) !== null) {
            return $this->backWithErrors($request, ['name' => 'A team with this name exists.'], '/teams');
        }
        $id = $this->app->db->insert('teams', ['tenant_id' => $this->app->tenant->id(), 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        $this->app->auditLog('team.created', 'team', $id, ['name' => $name]);
        $this->flash('success', 'Team created.');
        return Response::redirect('/teams');
    }

    public function members(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $team = $this->app->db->first('SELECT * FROM teams WHERE id = ? AND tenant_id = ?', [$request->intParam('id'), $tid]) ?? throw HttpException::notFound();
        $ids = array_map('intval', array_keys((array) ($request->post['members'] ?? [])));
        $valid = $ids === [] ? [] : array_column($this->app->db->all(
            'SELECT id FROM users WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$tid], $ids)
        ), 'id');
        $this->app->db->transaction(function () use ($team, $valid, $tid): void {
            $this->app->db->run('DELETE FROM team_members WHERE team_id = ? AND tenant_id = ?', [$team['id'], $tid]);
            foreach ($valid as $uid) {
                $this->app->db->insert('team_members', ['team_id' => (int) $team['id'], 'user_id' => (int) $uid, 'tenant_id' => $tid]);
            }
        });
        $this->app->auditLog('team.members_updated', 'team', (int) $team['id'], ['members' => $valid]);
        $this->flash('success', 'Team members saved.');
        return Response::redirect('/teams');
    }

    public function destroy(Request $request): Response
    {
        $tid = $this->app->tenant->id();
        $team = $this->app->db->first('SELECT * FROM teams WHERE id = ? AND tenant_id = ?', [$request->intParam('id'), $tid]) ?? throw HttpException::notFound();
        $this->app->db->run('DELETE FROM teams WHERE id = ? AND tenant_id = ?', [$team['id'], $tid]);
        $this->app->auditLog('team.deleted', 'team', (int) $team['id'], ['name' => $team['name']]);
        $this->flash('success', 'Team deleted.');
        return Response::redirect('/teams');
    }
}
