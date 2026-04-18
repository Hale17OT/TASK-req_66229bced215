<?php
namespace app\job;

use think\facade\Db;

class LockoutExpiryJob implements JobInterface
{
    public function run(): array
    {
        $n = Db::table('users')
            ->where('status', 'locked')
            ->whereNotNull('locked_until')
            ->where('locked_until', '<=', date('Y-m-d H:i:s'))
            ->update([
                'status'             => 'active',
                'locked_until'       => null,
                'failed_login_count' => 0,
            ]);
        return ['released' => $n];
    }
}
