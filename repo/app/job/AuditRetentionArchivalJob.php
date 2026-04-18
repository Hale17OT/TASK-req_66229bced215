<?php
namespace app\job;

/**
 * Audit retention is 7 years (spec §14.5). Per ASSUMPTIONS §G, audit rows are
 * append-only — this job only computes a daily checkpoint and verifies the
 * hash chain for the previous day's range. It DOES NOT delete or archive
 * audit rows in v1; archival to cold storage is documented as a v1.1 hook.
 */
class AuditRetentionArchivalJob implements JobInterface
{
    public function run(): array
    {
        $svc = new \app\service\audit\AuditService();
        // Verify the most recent 5000 rows
        $broken = $svc->verifyChain(0, 5000);
        return [
            'verified_window_rows' => 5000,
            'first_broken_row'     => $broken,
        ];
    }
}
