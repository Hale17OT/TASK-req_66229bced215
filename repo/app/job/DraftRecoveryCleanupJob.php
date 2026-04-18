<?php
namespace app\job;

use think\facade\Db;

class DraftRecoveryCleanupJob implements JobInterface
{
    public function run(): array
    {
        $n = Db::table('draft_recovery')
            ->where('expires_at', '<', date('Y-m-d H:i:s'))
            ->delete();
        return ['deleted' => $n];
    }
}
