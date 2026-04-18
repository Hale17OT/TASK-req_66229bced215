<?php
namespace app\service\reimbursement;

use app\exception\AuthorizationException;
use app\exception\BusinessException;
use app\model\ApprovalComment;
use app\model\ApprovalWorkflowInstance;
use app\model\ApprovalWorkflowStep;
use app\model\BudgetOverride;
use app\model\FundCommitment;
use app\model\Reimbursement;
use app\service\audit\AuditService;
use app\service\auth\Authorization;
use app\service\budget\BudgetService;
use app\service\budget\CommitmentService;
use app\service\money\Money;
use app\service\security\FieldMasker;
use app\service\workflow\transitions\ReimbursementMachine;
use think\facade\Db;

class ReimbursementService
{
    public function __construct(
        private BudgetService $budget,
        private CommitmentService $commitment,
        private DuplicateRegistry $duplicate,
        private AuditService $audit,
        private FieldMasker $masker,
        private Authorization $authz,
    ) {}

    /** Sanitized snapshot for audit logging — keeps field names, masks values. */
    private function auditSnapshot(?Reimbursement $r): ?array
    {
        return $r === null ? null : $this->masker->sanitizeForAudit($r->toArray());
    }

    /**
     * Validate caller-supplied scope IDs against their data scope. A submitter
     * may only assign `scope_location_id` / `scope_department_id` values that
     * lie inside the scope they hold (global users bypass). Unset / null scope
     * fields are allowed — the submission simply has no explicit scope, which
     * reviewers/approvers gate on separately. Blocks cross-scope tampering on
     * create / update / submit.
     */
    private function assertPayloadScope(int $userId, ?int $locationId, ?int $departmentId): void
    {
        if ($locationId === null && $departmentId === null) return;
        $this->authz->assertScopePermitted(
            $userId,
            $locationId,
            $departmentId,
            'Requested reimbursement scope is outside your authorization'
        );
    }

    private static function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') return null;
        return (int)$v;
    }

    public function createDraft(int $userId, array $data): Reimbursement
    {
        $this->validatePayload($data, requireFull: false);
        $loc = self::intOrNull($data['scope_location_id'] ?? null);
        $dep = self::intOrNull($data['scope_department_id'] ?? null);
        $this->assertPayloadScope($userId, $loc, $dep);
        $no = $this->generateReimbursementNo();
        $r = Reimbursement::create([
            'reimbursement_no'     => $no,
            'submitter_user_id'    => $userId,
            'scope_location_id'    => $loc,
            'scope_department_id'  => $dep,
            'category_id'          => (int)($data['category_id'] ?? 0) ?: null,
            'amount'               => (string)Money::of($data['amount'] ?? '0'),
            'merchant'             => substr((string)($data['merchant'] ?? ''), 0, 255),
            'service_period_start' => (string)($data['service_period_start'] ?? date('Y-m-d')),
            'service_period_end'   => (string)($data['service_period_end'] ?? date('Y-m-d')),
            'receipt_no'           => substr((string)($data['receipt_no'] ?? ''), 0, 100),
            'description'          => $data['description'] ?? null,
            'status'               => 'draft',
        ]);
        $this->audit->record('reimbursement.created', 'reimbursement', $r->id, null, $this->auditSnapshot($r));
        return $r;
    }

    public function updateDraft(Reimbursement $r, int $userId, array $data): Reimbursement
    {
        if ((int)$r->submitter_user_id !== $userId) throw new AuthorizationException('Not your draft');
        if ($r->status !== 'draft' && $r->status !== 'needs_revision') {
            throw new BusinessException('Only draft / needs_revision can be edited', 40901, 409);
        }
        if (isset($data['version']) && (int)$data['version'] !== (int)$r->version) {
            throw new BusinessException('Stale version — reload', 40913, 409);
        }
        $before = $this->auditSnapshot($r);
        // Scope guard before any assignment: treat the proposed scope as the
        // incoming value if the caller supplied it, otherwise keep the row's
        // existing value. Prevents updates that move a draft into a scope the
        // caller does not hold.
        $proposedLoc = array_key_exists('scope_location_id', $data)
            ? self::intOrNull($data['scope_location_id'])
            : ($r->scope_location_id !== null ? (int)$r->scope_location_id : null);
        $proposedDep = array_key_exists('scope_department_id', $data)
            ? self::intOrNull($data['scope_department_id'])
            : ($r->scope_department_id !== null ? (int)$r->scope_department_id : null);
        $this->assertPayloadScope($userId, $proposedLoc, $proposedDep);
        foreach (['category_id', 'merchant', 'service_period_start',
                  'service_period_end', 'receipt_no', 'description'] as $k) {
            if (array_key_exists($k, $data)) $r->{$k} = $data[$k];
        }
        if (array_key_exists('scope_location_id', $data))   $r->scope_location_id   = $proposedLoc;
        if (array_key_exists('scope_department_id', $data)) $r->scope_department_id = $proposedDep;
        if (isset($data['amount'])) $r->amount = (string)Money::of((string)$data['amount']);
        $r->version = (int)$r->version + 1;
        $r->save();
        $this->audit->record('reimbursement.updated', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
        return $r;
    }

    public function submit(Reimbursement $r, int $userId): Reimbursement
    {
        if ((int)$r->submitter_user_id !== $userId) throw new AuthorizationException('Not your draft');
        $machine = ReimbursementMachine::make();
        $isResubmit = $r->status === 'needs_revision';
        $machine->assert($r->status, $isResubmit ? 'resubmitted' : 'submitted');

        // Re-verify the draft's stored scope against the caller on submit —
        // protects against a scope assignment that was valid when the draft
        // was created but which the caller has since lost authority over.
        $this->assertPayloadScope(
            $userId,
            $r->scope_location_id   !== null ? (int)$r->scope_location_id   : null,
            $r->scope_department_id !== null ? (int)$r->scope_department_id : null,
        );

        // Validate full payload + attachments
        $this->validatePayload($r->toArray(), requireFull: true);
        $attCount = (int)Db::table('reimbursement_attachments')->where('reimbursement_id', $r->id)->whereNull('deleted_at')->count();
        if ($attCount < 1) throw new BusinessException('At least 1 attachment required', 40044, 422, ['attachments' => ['min 1']]);

        return Db::transaction(function () use ($r, $isResubmit) {
            // Duplicate guard
            $this->duplicate->reserve($r);

            // Resolve allocation, freeze commitment
            $alloc = $this->budget->findApplicableAllocation(
                (int)$r->category_id, $r->scope_location_id ? (int)$r->scope_location_id : null,
                $r->scope_department_id ? (int)$r->scope_department_id : null, (string)$r->service_period_start
            );
            if (!$alloc) {
                throw new BusinessException('No active budget allocation for this category / scope / period', 40050, 422);
            }
            $util = $this->budget->utilizationFor($alloc);
            $remainingAfter = Money::of($util['available'])->sub(Money::of((string)$r->amount));

            $before = $this->auditSnapshot($r);
            if ($remainingAfter->isNegative()) {
                // Over cap: park in pending_override_review (admin must override)
                $r->status = 'pending_override_review';
            } else {
                $r->status = $isResubmit ? 'resubmitted' : 'submitted';
            }
            $r->submitted_at = date('Y-m-d H:i:s');
            $r->version = (int)$r->version + 1;
            $r->save();

            // Activate commitment freeze
            $this->commitment->freeze((int)$alloc->id, (int)$r->id, (string)$r->amount, 'active', 'auto: submission freeze');

            // Workflow instance
            $instance = ApprovalWorkflowInstance::where('reimbursement_id', $r->id)->find();
            if (!$instance) {
                $instance = ApprovalWorkflowInstance::create([
                    'reimbursement_id' => $r->id,
                    'current_step'     => 'review',
                    'state'            => 'open',
                ]);
            }
            ApprovalWorkflowStep::create([
                'instance_id'   => $instance->id,
                'step_name'     => 'submit',
                'actor_user_id' => $r->submitter_user_id,
                'action'        => $isResubmit ? 'resubmit' : 'submit',
                'before_status' => $before['status'],
                'after_status'  => $r->status,
                'comment'       => null,
            ]);

            $this->audit->record($isResubmit ? 'reimbursement.resubmitted' : 'reimbursement.submitted',
                'reimbursement', $r->id, $before, $this->auditSnapshot($r), [
                    'allocation_id' => (int)$alloc->id,
                    'remaining_after' => (string)$remainingAfter,
                    'over_cap' => $remainingAfter->isNegative(),
                ]);
            return $r;
        });
    }

    public function review(Reimbursement $r, int $reviewerId): void
    {
        if (in_array($r->status, ['submitted', 'resubmitted'], true)) {
            $machine = ReimbursementMachine::make();
            $machine->assert($r->status, 'under_review');
            $before = $this->auditSnapshot($r);
            $r->status = 'under_review';
            $r->save();
            $this->logStep($r, $reviewerId, 'review', 'review', $before['status'], $r->status, null);
            $this->audit->record('reimbursement.under_review', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
        }
    }

    public function approve(Reimbursement $r, int $approverId, ?string $comment = null): Reimbursement
    {
        // Move into review first if needed
        if (in_array($r->status, ['submitted', 'resubmitted'], true)) $this->review($r, $approverId);

        $machine = ReimbursementMachine::make();
        $machine->assert($r->status, 'approved');

        return Db::transaction(function () use ($r, $approverId, $comment, $machine) {
            // Re-precheck — funds must still be available unless override exists
            if (!BudgetOverride::where('reimbursement_id', $r->id)->find()) {
                $alloc = $this->budget->findApplicableAllocation(
                    (int)$r->category_id, $r->scope_location_id ? (int)$r->scope_location_id : null,
                    $r->scope_department_id ? (int)$r->scope_department_id : null, (string)$r->service_period_start
                );
                if (!$alloc) throw new BusinessException('No allocation available', 40050, 422);
                $util = $this->budget->utilizationFor($alloc);
                // available already counts this commitment as active; subtract back to compare cleanly
                $availableExclThis = Money::of($util['available'])->add(Money::of((string)$r->amount));
                if ($availableExclThis->lt(Money::of((string)$r->amount))) {
                    throw new BusinessException('Cap exceeded — admin override required', 40060, 409);
                }
            }

            $before = $this->auditSnapshot($r);
            $r->status = 'approved';
            $r->decided_at = date('Y-m-d H:i:s');
            $r->save();
            // Move from approved → settlement_pending (the natural next step)
            $machine->assert($r->status, 'settlement_pending');
            $r->status = 'settlement_pending';
            $r->save();

            if ($comment) ApprovalComment::create(['reimbursement_id' => $r->id, 'user_id' => $approverId, 'body' => $comment]);
            $this->logStep($r, $approverId, 'approve', 'approve', $before['status'], $r->status, $comment);
            $this->audit->record('reimbursement.approved', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
            return $r;
        });
    }

    public function reject(Reimbursement $r, int $reviewerId, string $comment): Reimbursement
    {
        if (mb_strlen($comment) < (int)config('app.studio.reimbursement.reason_min_chars_reject')) {
            throw new BusinessException('Rejection comment min ' . config('app.studio.reimbursement.reason_min_chars_reject') . ' chars', 40000, 422, ['comment' => ['too short']]);
        }
        if (in_array($r->status, ['submitted', 'resubmitted', 'pending_override_review'], true)) {
            $this->review($r, $reviewerId); // attempt move to under_review only for submitted/resubmitted
        }
        $machine = ReimbursementMachine::make();
        $machine->assert($r->status, 'rejected');
        $before = $this->auditSnapshot($r);
        return Db::transaction(function () use ($r, $reviewerId, $comment, $before) {
            $r->status = 'rejected';
            $r->decided_at = date('Y-m-d H:i:s');
            $r->save();
            ApprovalComment::create(['reimbursement_id' => $r->id, 'user_id' => $reviewerId, 'body' => $comment]);
            $this->logStep($r, $reviewerId, 'reject', 'reject', $before['status'], $r->status, $comment);
            $this->commitment->releaseAllForReimbursement((int)$r->id, 'rejected');
            $this->audit->record('reimbursement.rejected', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
            return $r;
        });
    }

    public function needsRevision(Reimbursement $r, int $reviewerId, string $comment): Reimbursement
    {
        if (in_array($r->status, ['submitted', 'resubmitted'], true)) $this->review($r, $reviewerId);
        $machine = ReimbursementMachine::make();
        $machine->assert($r->status, 'needs_revision');
        $before = $this->auditSnapshot($r);
        return Db::transaction(function () use ($r, $reviewerId, $comment, $before) {
            $r->status = 'needs_revision';
            $r->save();
            // Release freeze while user revises
            $this->commitment->releaseAllForReimbursement((int)$r->id, 'needs_revision');
            ApprovalComment::create(['reimbursement_id' => $r->id, 'user_id' => $reviewerId, 'body' => $comment]);
            $this->logStep($r, $reviewerId, 'needs_revision', 'needs_revision', $before['status'], $r->status, $comment);
            $this->audit->record('reimbursement.needs_revision', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
            return $r;
        });
    }

    public function withdraw(Reimbursement $r, int $userId): Reimbursement
    {
        if ((int)$r->submitter_user_id !== $userId) throw new AuthorizationException('Only submitter can withdraw');
        $allowed = ['submitted', 'pending_override_review', 'needs_revision', 'resubmitted', 'under_review'];
        if (!in_array($r->status, $allowed, true)) throw new BusinessException('Cannot withdraw in status ' . $r->status, 40901, 409);
        $before = $this->auditSnapshot($r);
        return Db::transaction(function () use ($r, $before) {
            $r->status = 'withdrawn';
            $r->save();
            $this->commitment->releaseAllForReimbursement((int)$r->id, 'withdrawn');
            $this->audit->record('reimbursement.withdrawn', 'reimbursement', $r->id, $before, $this->auditSnapshot($r));
            return $r;
        });
    }

    public function override(Reimbursement $r, int $adminId, string $reason): Reimbursement
    {
        $minLen = (int)config('app.studio.reimbursement.reason_min_chars_override');
        if (mb_strlen($reason) < $minLen) {
            throw new BusinessException("Override reason min {$minLen} chars", 40000, 422, ['reason' => ['too short']]);
        }
        if ($r->status !== 'pending_override_review') {
            throw new BusinessException('Reimbursement is not pending override', 40901, 409);
        }
        $alloc = $this->budget->findApplicableAllocation(
            (int)$r->category_id, $r->scope_location_id ? (int)$r->scope_location_id : null,
            $r->scope_department_id ? (int)$r->scope_department_id : null, (string)$r->service_period_start
        );
        if (!$alloc) throw new BusinessException('No allocation', 40050, 422);
        $util = $this->budget->utilizationFor($alloc);
        $availableBefore = Money::of($util['available']);
        $availableAfter  = $availableBefore; // already counts this commitment as active

        return Db::transaction(function () use ($r, $adminId, $reason, $alloc, $availableBefore, $availableAfter) {
            BudgetOverride::create([
                'reimbursement_id'    => (int)$r->id,
                'allocation_id'       => (int)$alloc->id,
                'requested_amount'    => (string)$r->amount,
                'available_before'    => (string)$availableBefore,
                'available_after'     => (string)$availableAfter,
                'reason'              => $reason,
                'approved_by_user_id' => $adminId,
            ]);
            $before = $this->auditSnapshot($r);
            $r->status = 'under_review';
            $r->save();
            $this->logStep($r, $adminId, 'override', 'override', $before['status'], $r->status, $reason);
            $this->audit->record('reimbursement.override', 'reimbursement', $r->id, $before, $this->auditSnapshot($r), [
                'reason' => $reason, 'allocation_id' => (int)$alloc->id,
            ]);
            return $r;
        });
    }

    public function markSettled(Reimbursement $r, int $byUserId): Reimbursement
    {
        $machine = ReimbursementMachine::make();
        $machine->assert($r->status, 'settled');
        $before = $this->auditSnapshot($r);
        return Db::transaction(function () use ($r, $byUserId, $before) {
            $r->status = 'settled';
            $r->save();
            $this->commitment->consumeAllForReimbursement((int)$r->id, 'settled');
            $this->audit->record('reimbursement.settled', 'reimbursement', $r->id, $before, $this->auditSnapshot($r), ['by' => $byUserId]);
            return $r;
        });
    }

    private function logStep(Reimbursement $r, int $actorId, string $stepName, string $action, string $beforeStatus, string $afterStatus, ?string $comment): void
    {
        $instance = ApprovalWorkflowInstance::where('reimbursement_id', $r->id)->find();
        if (!$instance) {
            $instance = ApprovalWorkflowInstance::create(['reimbursement_id' => $r->id, 'current_step' => $stepName, 'state' => 'open']);
        }
        ApprovalWorkflowStep::create([
            'instance_id'   => $instance->id,
            'step_name'     => $stepName,
            'actor_user_id' => $actorId,
            'action'        => $action,
            'before_status' => $beforeStatus,
            'after_status'  => $afterStatus,
            'comment'       => $comment,
        ]);
    }

    private function validatePayload(array $d, bool $requireFull): void
    {
        $errs = [];
        if ($requireFull) {
            foreach (['merchant', 'service_period_start', 'service_period_end', 'receipt_no', 'category_id'] as $f) {
                if (empty($d[$f])) $errs[$f] = ['required'];
            }
        }
        if (isset($d['amount'])) {
            $m = Money::of((string)$d['amount']);
            if (!$m->isPositive()) $errs['amount'][] = '> 0 required';
        }
        if (isset($d['service_period_start'], $d['service_period_end'])) {
            if ($d['service_period_end'] < $d['service_period_start']) $errs['service_period_end'][] = 'must be on/after start';
        }
        if (isset($d['receipt_no']) && (mb_strlen((string)$d['receipt_no']) < 1 || mb_strlen((string)$d['receipt_no']) > 100)) {
            $errs['receipt_no'][] = '1-100 chars';
        }
        if (isset($d['merchant']) && mb_strlen((string)$d['merchant']) > 255) $errs['merchant'][] = 'max 255';
        if ($errs) throw new BusinessException('Validation failed', 40000, 422, $errs);
    }

    private function generateReimbursementNo(): string
    {
        // R-YYYYMMDD-<6 chars>  (collision-vanishingly unlikely; UNIQUE constraint catches anyway)
        for ($i = 0; $i < 5; $i++) {
            $no = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            if (!Reimbursement::where('reimbursement_no', $no)->find()) return $no;
        }
        throw new BusinessException('Failed to allocate reimbursement number', 50000, 500);
    }
}
