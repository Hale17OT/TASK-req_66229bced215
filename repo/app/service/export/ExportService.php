<?php
namespace app\service\export;

use app\exception\BusinessException;
use app\model\ExportJob;
use app\service\audit\AuditService;
use think\facade\Db;

/**
 * CSV export jobs (spec §16.5, §16.6, ASSUMPTIONS §I).
 *
 * The export is generated synchronously inside `enqueue()` for v1 to keep things
 * simple — the system is offline LAN-only and CSVs are small enough that a few
 * seconds of in-request work is fine. The job table is still used so:
 *   - download URL is shareable
 *   - retention/expiry job can clean up files
 *   - the audit trail records the export
 *
 * Each generated CSV ends with a trailer line:
 *     # rows=<n> sha256=<hex>  generated_by=<username> generated_at=<iso>
 */
class ExportService
{
    private array $kinds = [
        'audit'              => 'exportAudit',
        'reimbursements'     => 'exportReimbursements',
        'settlements'        => 'exportSettlements',
        'budget_utilization' => 'exportBudgetUtilization',
    ];

    public function __construct(private AuditService $audit) {}

    public function enqueue(int $userId, string $kind, array $filters): ExportJob
    {
        if (!isset($this->kinds[$kind])) throw new BusinessException("Unknown export kind: {$kind}", 40000, 422);
        $cfg = (array)config('app.studio.exports');
        $row = ExportJob::create([
            'requested_by_user_id' => $userId,
            'kind'                 => $kind,
            'filters_json'         => $filters,
            'status'               => 'queued',
            'expires_at'           => date('Y-m-d H:i:s', time() + (int)$cfg['expiry_days'] * 86400),
        ]);
        try {
            $row->status = 'running';
            $row->started_at = date('Y-m-d H:i:s');
            $row->save();
            [$path, $rows, $sha] = $this->{$this->kinds[$kind]}($filters, $row->id, $cfg);
            $row->status = 'completed';
            $row->completed_at = date('Y-m-d H:i:s');
            $row->file_path = $path;
            $row->row_count = $rows;
            $row->sha256 = $sha;
            $row->save();
        } catch (\Throwable $e) {
            $row->status = 'failed';
            $row->error = substr($e->getMessage(), 0, 2000);
            $row->save();
        }
        $this->audit->record('export.' . $kind, 'export_job', $row->id, null, $row->toArray(), ['filters' => $filters]);
        return $row;
    }

    private function openWriter(int $jobId, array $cfg, string $prefix): array
    {
        $dir = rtrim((string)$cfg['storage_root'], '/');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/' . $prefix . '-' . $jobId . '-' . date('YmdHis') . '.csv';
        $fh = fopen($path, 'wb') ?: throw new BusinessException('Cannot open export file', 50000, 500);
        return [$path, $fh];
    }

    private function closeWriter($fh, string $path, int $rows, int $userId): array
    {
        fwrite($fh, sprintf("# rows=%d generated_by=%d generated_at=%s\n", $rows, $userId, gmdate('c')));
        fclose($fh);
        $sha = hash_file('sha256', $path);
        // Append the sha line LAST so it includes itself? No — common convention is to write it as the trailer.
        // Re-open for append:
        file_put_contents($path, "# sha256=" . $sha . "\n", FILE_APPEND);
        // Final hash includes the previous trailer; reader can skip lines starting with '#'
        return [$path, $rows, hash_file('sha256', $path)];
    }

    private function applyAuditFilters(\think\db\Query $q, array $f): void
    {
        if (!empty($f['from'])) $q->where('occurred_at', '>=', $f['from']);
        if (!empty($f['to']))   $q->where('occurred_at', '<=', $f['to']);
        if (!empty($f['action'])) $q->where('action', 'like', '%' . $f['action'] . '%');
        if (!empty($f['actor_user_id'])) $q->where('actor_user_id', (int)$f['actor_user_id']);
        if (!empty($f['target_entity'])) $q->where('target_entity', $f['target_entity']);
        if (!empty($f['outcome'])) $q->where('outcome', $f['outcome']);
    }

    private function exportAudit(array $f, int $jobId, array $cfg): array
    {
        $userId = (int)\think\facade\Request::instance()->userId;
        $authz = \think\App::getInstance()->make(\app\service\auth\Authorization::class);
        [$path, $fh] = $this->openWriter($jobId, $cfg, 'audit');
        fputcsv($fh, ['id', 'occurred_at', 'actor_user_id', 'actor_username', 'action',
            'target_entity', 'target_entity_id', 'outcome', 'ip', 'request_id']);
        $rows = 0;
        $q = Db::table('audit_logs')->order('id', 'asc');
        $this->applyAuditFilters($q, $f);
        // BLOCKER fix #4: scope-clip exports the same way the search endpoint does.
        $q = $authz->applyAuditScope($q, $userId);
        $max = (int)$cfg['max_rows'];
        $unmask = $authz->has($userId, 'sensitive.unmask');
        $q->chunk(2000, function ($chunk) use ($fh, &$rows, $max, $unmask) {
            foreach ($chunk as $r) {
                if ($rows >= $max) return false;
                $ip = (string)($r['ip'] ?? '');
                if (!$unmask) $ip = preg_replace('/\.\d+$/', '.xxx', $ip);
                fputcsv($fh, [
                    $r['id'], $r['occurred_at'], $r['actor_user_id'], $r['actor_username'], $r['action'],
                    $r['target_entity'], $r['target_entity_id'], $r['outcome'], $ip, $r['request_id'],
                ]);
                $rows++;
            }
        });
        return $this->closeWriter($fh, $path, $rows, $userId);
    }

    private function exportReimbursements(array $f, int $jobId, array $cfg): array
    {
        $userId = (int)\think\facade\Request::instance()->userId;
        $authz = \think\App::getInstance()->make(\app\service\auth\Authorization::class);
        $cipher = \app\service\security\FieldCipher::fromEnv();
        $unmask = $authz->has($userId, 'sensitive.unmask');
        [$path, $fh] = $this->openWriter($jobId, $cfg, 'reimbursements');
        fputcsv($fh, ['id', 'reimbursement_no', 'submitter_user_id', 'category_id', 'amount',
            'merchant', 'service_period_start', 'service_period_end', 'status', 'submitted_at',
            'receipt_no_masked']);
        $rows = 0;
        $q = \app\model\Reimbursement::order('id', 'asc');
        $q = $authz->applyReimbursementScope($q, $userId);
        if (!empty($f['from'])) $q->where('submitted_at', '>=', $f['from']);
        if (!empty($f['to']))   $q->where('submitted_at', '<=', $f['to']);
        if (!empty($f['status'])) $q->where('status', $f['status']);
        $q->chunk(2000, function ($chunk) use ($fh, &$rows, $cipher, $unmask) {
            foreach ($chunk as $r) {
                $rcpt = (string)$r->receipt_no; // accessor decrypts
                $rcpt = $unmask ? $rcpt : $cipher->mask($rcpt);
                fputcsv($fh, [
                    $r->id, $r->reimbursement_no, $r->submitter_user_id, $r->category_id, $r->amount,
                    $r->merchant, $r->service_period_start, $r->service_period_end, $r->status, $r->submitted_at,
                    $rcpt,
                ]);
                $rows++;
            }
        });
        return $this->closeWriter($fh, $path, $rows, $userId);
    }

    private function exportSettlements(array $f, int $jobId, array $cfg): array
    {
        $userId = (int)\think\facade\Request::instance()->userId;
        $authz = \think\App::getInstance()->make(\app\service\auth\Authorization::class);
        $cipher = \app\service\security\FieldCipher::fromEnv();
        $unmask = $authz->has($userId, 'sensitive.unmask');
        [$path, $fh] = $this->openWriter($jobId, $cfg, 'settlements');
        fputcsv($fh, ['id', 'settlement_no', 'reimbursement_id', 'method', 'gross_amount', 'status',
            'recorded_at', 'confirmed_at', 'reference_masked']);
        $rows = 0;
        $q = \app\model\SettlementRecord::order('id', 'asc');
        $q = $authz->applySettlementScope($q, $userId);
        if (!empty($f['from'])) $q->where('recorded_at', '>=', $f['from']);
        if (!empty($f['to']))   $q->where('recorded_at', '<=', $f['to']);
        if (!empty($f['status'])) $q->where('status', $f['status']);
        $q->chunk(2000, function ($chunk) use ($fh, &$rows, $cipher, $unmask) {
            foreach ($chunk as $r) {
                $ref = (string)($r->check_number ?? $r->terminal_batch_ref ?? $r->cash_receipt_ref ?? '');
                $ref = $unmask ? $ref : $cipher->mask($ref);
                fputcsv($fh, [$r->id, $r->settlement_no, $r->reimbursement_id, $r->method,
                    $r->gross_amount, $r->status, $r->recorded_at, $r->confirmed_at, $ref]);
                $rows++;
            }
        });
        return $this->closeWriter($fh, $path, $rows, $userId);
    }

    private function exportBudgetUtilization(array $f, int $jobId, array $cfg): array
    {
        $userId = (int)\think\facade\Request::instance()->userId;
        $authz = \think\App::getInstance()->make(\app\service\auth\Authorization::class);
        [$path, $fh] = $this->openWriter($jobId, $cfg, 'budget_utilization');
        fputcsv($fh, ['allocation_id', 'category_id', 'period_id', 'scope_type', 'location_id', 'department_id',
            'cap_amount', 'consumed', 'active', 'available']);
        $rows = 0;
        // HIGH fix audit-2 #2: scope-filter active allocations the same way the
        // /budget/utilization endpoint does, so non-global users do not get
        // org-wide allocations dumped to CSV.
        $q = \app\model\BudgetAllocation::where('status', 'active');
        $q = $authz->applyBudgetAllocationScope($q, $userId);
        $svc = new \app\service\budget\BudgetService();
        foreach ($q->select() as $alloc) {
            $util = $svc->utilizationFor($alloc);
            fputcsv($fh, [$util['allocation_id'], $util['category_id'], $util['period_id'], $util['scope_type'],
                $util['location_id'], $util['department_id'], $util['cap'], $util['confirmed_spend'],
                $util['active_commitments'], $util['available']]);
            $rows++;
        }
        return $this->closeWriter($fh, $path, $rows, $userId);
    }
}
