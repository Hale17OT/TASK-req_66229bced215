<?php
namespace app\service\budget;

use app\exception\BusinessException;
use app\exception\IllegalStateTransitionException;
use app\model\FundCommitment;
use think\facade\Db;

/**
 * Commitment freeze lifecycle (spec §9.6):
 *   pending → active   (becomes effective when reimbursement enters review)
 *   active  → consumed (when reimbursement is settled / posted to ledger)
 *   active  → released (when reimbursement is rejected / withdrawn)
 *   active  → cancelled
 *   pending → released | cancelled
 *
 *  Per ASSUMPTIONS §E.18: consumed at settled (NOT at approved) — gives most
 *  accurate "approved-but-not-yet-paid" picture.
 */
class CommitmentService
{
    private array $allowed = [
        'pending'  => ['active', 'released', 'cancelled'],
        'active'   => ['consumed', 'released', 'cancelled'],
        'released' => [],
        'consumed' => [],
        'cancelled' => [],
    ];

    public function freeze(int $allocationId, int $reimbursementId, string $amount, string $initial = 'active', ?string $note = null): FundCommitment
    {
        // Spec: freeze activates at submission time
        $row = FundCommitment::create([
            'allocation_id'    => $allocationId,
            'reimbursement_id' => $reimbursementId,
            'amount'           => $amount,
            'status'           => $initial,
            'notes'            => $note,
        ]);
        return $row;
    }

    public function transition(FundCommitment $row, string $to, ?string $note = null): FundCommitment
    {
        $from = (string)$row->status;
        if (!in_array($to, $this->allowed[$from] ?? [], true)) {
            throw new IllegalStateTransitionException('fund_commitment', $from, $to);
        }
        $row->status = $to;
        if ($note) $row->notes = trim(((string)$row->notes) . "\n[" . date('Y-m-d H:i:s') . "] " . $note);
        $row->save();
        return $row;
    }

    public function releaseAllForReimbursement(int $reimbursementId, string $reason): int
    {
        $rows = FundCommitment::where('reimbursement_id', $reimbursementId)
            ->whereIn('status', ['pending', 'active'])->select();
        $n = 0;
        foreach ($rows as $r) {
            $this->transition($r, 'released', $reason);
            $n++;
        }
        return $n;
    }

    public function consumeAllForReimbursement(int $reimbursementId, string $reason): int
    {
        $rows = FundCommitment::where('reimbursement_id', $reimbursementId)
            ->whereIn('status', ['pending', 'active'])->select();
        $n = 0;
        foreach ($rows as $r) {
            $this->transition($r, 'consumed', $reason);
            $n++;
        }
        return $n;
    }
}
