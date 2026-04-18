<?php
namespace app\job;

use think\facade\Db;

class ExportFileExpiryJob implements JobInterface
{
    public function run(): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = Db::table('export_jobs')
            ->where('status', 'completed')
            ->where('expires_at', '<', $now)
            ->select();
        $deleted = 0;
        foreach ($rows as $r) {
            if (!empty($r['file_path']) && is_file($r['file_path'])) {
                @unlink($r['file_path']);
            }
            Db::table('export_jobs')->where('id', $r['id'])->update([
                'status' => 'expired', 'file_path' => null,
            ]);
            $deleted++;
        }
        return ['expired' => $deleted];
    }
}
