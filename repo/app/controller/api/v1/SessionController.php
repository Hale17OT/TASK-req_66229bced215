<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\exception\AuthorizationException;
use app\model\UserSession;
use app\service\audit\AuditService;
use app\service\auth\SessionService;
use think\Response;

class SessionController extends BaseController
{
    public function listMine(): Response
    {
        $rows = (new SessionService((array)config('app.studio.session')))
            ->activeForUser((int)$this->request->userId);
        $out = array_map(function ($r) {
            unset($r['session_id']); // never expose
            return $r;
        }, $rows);
        return json_response(0, 'ok', $out);
    }

    public function revoke($id): Response
    {
        $row = UserSession::find($id);
        if (!$row) throw new AuthorizationException('Session not found');
        if ((int)$row->user_id !== (int)$this->request->userId) {
            throw new AuthorizationException('Cannot revoke another user\'s session');
        }
        if ($row->revoked_at) return json_response(0, 'already revoked');
        $svc = SessionService::fromConfig();
        $svc->revoke($row, 'self', (int)$this->request->userId);
        app()->make(AuditService::class)->record('session.revoked', 'user_session', $row->id, null, null, ['by' => 'self']);
        return json_response(0, 'revoked');
    }
}
