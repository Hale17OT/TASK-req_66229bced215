<?php
namespace app\job;

use think\facade\Db;

class SessionCleanupJob implements JobInterface
{
    public function run(): array
    {
        // Mark expired (absolute or idle) sessions as revoked
        $now = date('Y-m-d H:i:s');
        $idle = (int)config('app.studio.session.idle_timeout_seconds');
        $idleCutoff = date('Y-m-d H:i:s', time() - $idle);

        $expired = Db::table('user_sessions')
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now, $idleCutoff) {
                $q->where('expires_at', '<', $now)
                  ->whereOr('last_activity_at', '<', $idleCutoff);
            })
            ->update([
                'revoked_at'    => $now,
                'revoke_reason' => 'gc_expired',
            ]);

        // Hard-delete revoked > 180 days (retention policy §14.5)
        $cutoff = date('Y-m-d H:i:s', time() - 180 * 86400);
        $purged = Db::table('user_sessions')
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->delete();
        return ['expired' => $expired, 'purged' => $purged];
    }
}
