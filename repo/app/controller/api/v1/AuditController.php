<?php
namespace app\controller\api\v1;

use app\BaseController;
use app\service\auth\Authorization;
use app\service\security\FieldMasker;
use think\facade\Db;
use think\Response;

/**
 * Audit log search.
 *
 * Authorization is layered:
 *   1. `audit.view` permission required (caller must explicitly hold it).
 *   2. Non-global viewers are scope-clipped to rows whose `actor_user_id`
 *      equals the caller's user id (see Authorization::applyAuditScope).
 *   3. IPs are masked unless caller has `sensitive.unmask`.
 */
class AuditController extends BaseController
{
    public function search(): Response
    {
        $authz = app()->make(Authorization::class);
        $userId = (int)$this->request->userId;

        // BLOCKER fix #1: explicit RBAC check — was previously absent.
        $authz->requirePermission($userId, 'audit.view');

        $q = Db::table('audit_logs')->order('id', 'desc');
        $q = $authz->applyAuditScope($q, $userId);

        if ($from = $this->request->get('from'))            $q->where('occurred_at', '>=', $from);
        if ($to   = $this->request->get('to'))              $q->where('occurred_at', '<=', $to);
        if ($act  = $this->request->get('action'))          $q->where('action', 'like', '%' . $act . '%');
        if ($te   = $this->request->get('target_entity'))   $q->where('target_entity', $te);
        if ($au   = (int)$this->request->get('actor_user_id', 0)) $q->where('actor_user_id', $au);
        if ($oc   = $this->request->get('outcome'))         $q->where('outcome', $oc);

        $size = min(500, max(10, (int)$this->request->get('size', 100)));
        $page = $q->paginate(['list_rows' => $size, 'page' => max(1, (int)$this->request->get('page', 1))]);

        // Mask sensitive output fields per FieldMasker policy.
        $masker = app()->make(FieldMasker::class);
        $masked = $masker->maskList($page->items(), $userId, 'audit');
        $arr = $page->toArray();
        $arr['data'] = $masked;
        return json_response(0, 'ok', $arr);
    }
}
