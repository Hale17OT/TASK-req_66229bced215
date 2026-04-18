<?php
namespace app\service\settlement;

use app\exception\BusinessException;
use app\model\LedgerEntry;
use app\model\RefundRecord;
use app\model\Reimbursement;
use app\model\SettlementRecord;
use app\service\audit\AuditService;
use app\service\money\Money;
use app\service\security\FieldMasker;
use app\service\reimbursement\ReimbursementService;
use app\service\workflow\transitions\SettlementMachine;
use think\facade\Db;

class SettlementService
{
    public function __construct(
        private AuditService $audit,
        private ReimbursementService $reimbursements,
        private FieldMasker $masker,
    ) {}

    private function auditSnapshot($row): ?array
    {
        return $row === null ? null : $this->masker->sanitizeForAudit($row->toArray());
    }

    public function record(int $reimbursementId, array $data, int $byUserId): SettlementRecord
    {
        $r = Reimbursement::find($reimbursementId) ?: throw new BusinessException('Reimbursement not found', 40400, 404);
        if (!in_array($r->status, ['approved', 'settlement_pending'], true)) {
            throw new BusinessException('Reimbursement must be approved/pending settlement', 40901, 409);
        }
        $method = (string)($data['method'] ?? '');
        if (!in_array($method, ['cash', 'check', 'terminal_batch_entry'], true)) {
            throw new BusinessException('method invalid', 40000, 422, ['method' => ['cash|check|terminal_batch_entry']]);
        }
        $amount = Money::of((string)($data['gross_amount'] ?? '0'));
        if (!$amount->isPositive()) throw new BusinessException('gross_amount > 0', 40000, 422);
        if ($amount->gt(Money::of((string)$r->amount))) {
            throw new BusinessException('Settlement cannot exceed reimbursement amount', 40000, 422);
        }
        if ($method === 'check' && empty($data['check_number'])) throw new BusinessException('check_number required for check', 40000, 422);
        if ($method === 'terminal_batch_entry' && empty($data['terminal_batch_ref'])) throw new BusinessException('terminal_batch_ref required', 40000, 422);

        return Db::transaction(function () use ($r, $data, $method, $amount, $byUserId) {
            $no = $this->generateSettlementNo();
            $row = SettlementRecord::create([
                'reimbursement_id'      => (int)$r->id,
                'settlement_no'         => $no,
                'method'                => $method,
                'gross_amount'          => (string)$amount,
                'check_number'          => $data['check_number'] ?? null,
                'terminal_batch_ref'    => $data['terminal_batch_ref'] ?? null,
                'cash_receipt_ref'      => $data['cash_receipt_ref'] ?? null,
                'status'                => 'recorded_not_confirmed',
                'recorded_by_user_id'   => $byUserId,
                'notes'                 => $data['notes'] ?? null,
            ]);
            $this->audit->record('settlement.recorded', 'settlement', $row->id, null, $this->auditSnapshot($row));
            return $row;
        });
    }

    public function confirm(SettlementRecord $row, int $byUserId): SettlementRecord
    {
        SettlementMachine::make()->assert($row->status, 'confirmed');
        return Db::transaction(function () use ($row, $byUserId) {
            $before = $this->auditSnapshot($row);
            $row->status = 'confirmed';
            $row->confirmed_at = date('Y-m-d H:i:s');
            $row->confirmed_by_user_id = $byUserId;
            $row->version = (int)$row->version + 1;
            $row->save();
            // Post ledger entries
            LedgerEntry::create([
                'ref_entity_type'   => 'settlement', 'ref_entity_id' => (int)$row->id,
                'account_code'      => 'reimbursement_payable',
                'debit'             => (string)$row->gross_amount, 'credit' => '0',
                'memo'              => 'settle ' . $row->settlement_no,
                'posted_by_user_id' => $byUserId,
            ]);
            LedgerEntry::create([
                'ref_entity_type'   => 'settlement', 'ref_entity_id' => (int)$row->id,
                'account_code'      => 'cash_or_clearing_' . $row->method,
                'debit'             => '0', 'credit' => (string)$row->gross_amount,
                'memo'              => 'settle ' . $row->settlement_no,
                'posted_by_user_id' => $byUserId,
            ]);
            // Drive the reimbursement to settled
            $r = Reimbursement::find($row->reimbursement_id);
            if ($r) $this->reimbursements->markSettled($r, $byUserId);
            $this->audit->record('settlement.confirmed', 'settlement', $row->id, $before, $this->auditSnapshot($row));
            return $row;
        });
    }

    public function refund(SettlementRecord $row, string $amount, string $reason, int $byUserId): RefundRecord
    {
        if (!in_array($row->status, ['confirmed', 'partially_refunded'], true)) {
            throw new BusinessException('Only confirmed/partially_refunded can be refunded', 40901, 409);
        }
        $amt = Money::of($amount);
        if (!$amt->isPositive()) throw new BusinessException('amount > 0', 40000, 422);
        $cumulative = (string)Db::table('refund_records')->where('settlement_id', $row->id)->where('status', 'recorded')->sum('amount');
        $newCumulative = Money::of($cumulative)->add($amt);
        if ($newCumulative->gt(Money::of((string)$row->gross_amount))) {
            throw new BusinessException('Cumulative refund would exceed settled amount', 40000, 422,
                ['amount' => ['cumulative cap = ' . $row->gross_amount]]);
        }

        return Db::transaction(function () use ($row, $amt, $reason, $byUserId, $newCumulative) {
            $refund = RefundRecord::create([
                'settlement_id' => (int)$row->id, 'amount' => (string)$amt,
                'reason' => $reason, 'refunded_by_user_id' => $byUserId,
            ]);
            $newStatus = $newCumulative->eq(Money::of((string)$row->gross_amount)) ? 'refunded' : 'partially_refunded';
            SettlementMachine::make()->assert($row->status, $newStatus);
            $before = $this->auditSnapshot($row);
            $row->status = $newStatus;
            $row->save();
            // Reverse ledger
            LedgerEntry::create([
                'ref_entity_type'   => 'refund', 'ref_entity_id' => (int)$refund->id,
                'account_code'      => 'cash_or_clearing_' . $row->method,
                'debit'             => (string)$amt, 'credit' => '0',
                'memo'              => 'refund of ' . $row->settlement_no,
                'posted_by_user_id' => $byUserId,
            ]);
            LedgerEntry::create([
                'ref_entity_type'   => 'refund', 'ref_entity_id' => (int)$refund->id,
                'account_code'      => 'reimbursement_payable',
                'debit'             => '0', 'credit' => (string)$amt,
                'memo'              => 'refund of ' . $row->settlement_no,
                'posted_by_user_id' => $byUserId,
            ]);
            $this->audit->record('settlement.refunded', 'settlement', $row->id, $before, $this->auditSnapshot($row), [
                'refund_id' => (int)$refund->id, 'amount' => (string)$amt, 'cumulative' => (string)$newCumulative,
            ]);
            return $refund;
        });
    }

    public function markException(SettlementRecord $row, string $reason, int $byUserId): SettlementRecord
    {
        SettlementMachine::make()->assert($row->status, 'exception');
        $before = $this->auditSnapshot($row);
        $row->status = 'exception';
        $row->exception_reason = $reason;
        $row->save();
        $this->audit->record('settlement.exception', 'settlement', $row->id, $before, $this->auditSnapshot($row));
        return $row;
    }

    private function generateSettlementNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'S-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            if (!SettlementRecord::where('settlement_no', $no)->find()) return $no;
        }
        throw new BusinessException('Failed to allocate settlement number', 50000, 500);
    }
}
