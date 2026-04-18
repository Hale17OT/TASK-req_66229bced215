<?php
namespace app\service\settlement;

use app\model\ReconciliationException;
use app\model\ReconciliationRun;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use think\facade\Db;

class ReconciliationService
{
    public function __construct(
        private AuditService $audit,
        private Authorization $authz,
    ) {}

    /**
     * Build the SQL clause + bindings that restrict a reconciliation scan to
     * the caller's data scope. Global operators get a tautology so the query
     * shape stays identical for both branches.
     *
     * Returns [clauseSql, bindings].  The clause always starts with `AND` so
     * it can be appended to an existing WHERE.
     *
     * @param string $reimbAlias the SQL alias used for the reimbursements row
     *                           inside the outer query (e.g. "r" or "reimb").
     */
    private function scopeClause(int $userId, string $reimbAlias): array
    {
        if ($this->authz->isGlobal($userId)) {
            return [' AND 1=1', []];
        }
        $scope = $this->authz->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];

        $parts = [];
        $binds = [];
        // Always include rows the caller owns — a submitter reconciling their
        // own record should be able to see their own orphans regardless.
        $parts[] = "{$reimbAlias}.submitter_user_id = ?";
        $binds[] = $userId;
        if (!empty($locs)) {
            $parts[] = "{$reimbAlias}.scope_location_id IN (" .
                implode(',', array_fill(0, count($locs), '?')) . ')';
            foreach ($locs as $l) $binds[] = (int)$l;
        }
        if (!empty($deps)) {
            $parts[] = "{$reimbAlias}.scope_department_id IN (" .
                implode(',', array_fill(0, count($deps), '?')) . ')';
            foreach ($deps as $d) $binds[] = (int)$d;
        }
        return [' AND (' . implode(' OR ', $parts) . ')', $binds];
    }

    public function start(int $byUserId, string $periodStart, string $periodEnd): ReconciliationRun
    {
        $run = ReconciliationRun::create([
            'started_by_user_id' => $byUserId,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'status'             => 'running',
        ]);
        $exceptions = 0;

        // 1) approved reimbursements lacking a confirmed settlement in window.
        // Scope-gated: non-global callers see only reimbursements inside
        // their own data scope (location/department or self-submitted).
        [$orphScope, $orphBinds] = $this->scopeClause($byUserId, 'r');
        $orphans = Db::query("
            SELECT r.id AS reimbursement_id
              FROM reimbursements r
         LEFT JOIN settlement_records s
                ON s.reimbursement_id = r.id AND s.status = 'confirmed'
             WHERE r.decided_at BETWEEN ? AND ?
               AND r.status IN ('approved','settlement_pending')
               AND s.id IS NULL
               {$orphScope}
        ", array_merge([$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'], $orphBinds));
        foreach ($orphans as $o) {
            ReconciliationException::create([
                'run_id' => (int)$run->id,
                'reimbursement_id' => (int)$o['reimbursement_id'],
                'exception_type' => 'unsettled_approved',
                'detail_json' => ['note' => 'approved but never confirmed in window'],
            ]);
            $exceptions++;
        }

        // 2) settlements with unbalanced ledger. Same scope gate — we join
        // back to the parent reimbursement so out-of-scope settlements can't
        // surface in a non-global operator's exception list.
        [$unbalScope, $unbalBinds] = $this->scopeClause($byUserId, 'reimb');
        $unbalanced = Db::query("
            SELECT settle.id AS settlement_id,
                   SUM(le.debit) - SUM(le.credit) AS net
              FROM settlement_records settle
              JOIN ledger_entries le
                ON le.ref_entity_type = 'settlement' AND le.ref_entity_id = settle.id
              JOIN reimbursements reimb
                ON reimb.id = settle.reimbursement_id
             WHERE settle.recorded_at BETWEEN ? AND ?
               {$unbalScope}
             GROUP BY settle.id
            HAVING ABS(net) > 0
        ", array_merge([$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59'], $unbalBinds));
        foreach ($unbalanced as $u) {
            ReconciliationException::create([
                'run_id' => (int)$run->id,
                'settlement_id' => (int)$u['settlement_id'],
                'exception_type' => 'ledger_unbalanced',
                'detail_json' => ['net' => $u['net']],
            ]);
            $exceptions++;
        }

        $run->status = 'completed';
        $run->completed_at = date('Y-m-d H:i:s');
        $run->summary_json = ['exception_count' => $exceptions];
        $run->save();
        $this->audit->record('reconciliation.completed', 'reconciliation_run', $run->id, null, $run->toArray());
        return $run;
    }
}
