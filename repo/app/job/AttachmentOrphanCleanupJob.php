<?php
namespace app\job;

use think\facade\Db;

class AttachmentOrphanCleanupJob implements JobInterface
{
    public function run(): array
    {
        // Soft-deleted > 30 days: hard-delete files + rows
        $cutoff = date('Y-m-d H:i:s', time() - 30 * 86400);
        $rows = Db::table('reimbursement_attachments')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->select();
        $purged = 0;
        foreach ($rows as $r) {
            if (!empty($r['storage_path']) && is_file($r['storage_path'])) {
                @unlink($r['storage_path']);
            }
            Db::table('reimbursement_attachments')->where('id', $r['id'])->delete();
            $purged++;
        }
        return ['purged' => $purged];
    }
}
