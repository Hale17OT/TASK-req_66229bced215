<?php
namespace app\service\audit;

use app\model\AuditLog;
use think\facade\Db;
use think\facade\Request;

/**
 * Central audit logger (spec §9.11, §11.4, §14.4):
 *  - append-only (DB triggers also enforce)
 *  - hash-chained: row_hash = sha256(prev_hash || canonical(payload))
 *  - records actor, IP, request id, before/after diffs
 */
class AuditService
{
    public function record(
        string $action,
        string $targetEntity,
        string|int $targetEntityId,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        bool $success = true
    ): AuditLog {
        $req = Request::instance();
        $actorId = property_exists($req, 'userId') ? $req->userId : null;
        $actorName = null;
        if ($actorId) {
            $actorName = (string)Db::table('users')->where('id', $actorId)->value('username');
        }

        return Db::transaction(function () use ($action, $targetEntity, $targetEntityId, $before, $after, $metadata, $success, $req, $actorId, $actorName) {
            // Use SELECT ... FOR UPDATE on a sentinel row to serialise hash-chain writes.
            // Cheap because we hold the lock briefly.
            $prevHash = (string)(Db::table('audit_logs')
                ->order('id', 'desc')->lock(true)->limit(1)->value('row_hash') ?? '');

            $payload = [
                'action'        => $action,
                'target'        => "{$targetEntity}:{$targetEntityId}",
                'actor_user_id' => $actorId,
                'before'        => $before,
                'after'         => $after,
                'metadata'      => $metadata,
                'occurred_at'   => gmdate('Y-m-d\TH:i:s.u\Z'),
            ];
            $canonical = canonical_json($payload);
            $rowHash = hash('sha256', $prevHash . '|' . $canonical);

            return AuditLog::create([
                'occurred_at'      => date('Y-m-d H:i:s'),
                'actor_user_id'    => $actorId,
                'actor_username'   => $actorName,
                'action'           => $action,
                'target_entity'    => $targetEntity,
                'target_entity_id' => (string)$targetEntityId,
                'outcome'          => $success ? 'success' : 'failure',
                'ip'               => $req->ip(),
                'request_id'       => substr((string)$req->header('idempotency-key', $req->header('x-request-id', '')), 0, 64),
                'correlation_id'   => null,
                'before_json'      => $before,
                'after_json'       => $after,
                'metadata_json'    => $metadata,
                'prev_hash'        => $prevHash ?: null,
                'row_hash'         => $rowHash,
            ]);
        });
    }

    /** Verifies the hash chain for a slice of audit rows. Returns first broken id, or null if intact. */
    public function verifyChain(int $fromId = 0, int $limit = 1000): ?int
    {
        $rows = AuditLog::where('id', '>=', $fromId)->order('id', 'asc')->limit($limit)->select();
        $prev = $fromId > 0
            ? (string)(Db::table('audit_logs')->where('id', '<', $fromId)->order('id', 'desc')->value('row_hash') ?? '')
            : '';
        foreach ($rows as $r) {
            $payload = [
                'action'        => $r->action,
                'target'        => "{$r->target_entity}:{$r->target_entity_id}",
                'actor_user_id' => $r->actor_user_id,
                'before'        => $r->before_json,
                'after'         => $r->after_json,
                'metadata'      => $r->metadata_json,
                'occurred_at'   => gmdate('Y-m-d\TH:i:s.u\Z', strtotime((string)$r->occurred_at)),
            ];
            $expected = hash('sha256', $prev . '|' . canonical_json($payload));
            if ($expected !== $r->row_hash) return (int)$r->id;
            $prev = $r->row_hash;
        }
        return null;
    }
}
