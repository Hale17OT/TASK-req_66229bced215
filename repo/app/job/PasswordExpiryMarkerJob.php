<?php
namespace app\job;

use think\facade\Db;

class PasswordExpiryMarkerJob implements JobInterface
{
    public function run(): array
    {
        $days = (int)config('app.studio.password.rotation_days');
        if ($days <= 0) return ['marked' => 0];
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $n = Db::table('users')
            ->where('status', 'active')
            ->where('password_changed_at', '<', $cutoff)
            ->update(['status' => 'password_expired']);
        return ['marked' => $n];
    }
}
