<?php
namespace app\service\auth;

use app\exception\AuthorizationException;
use app\model\Reimbursement;
use app\model\SettlementRecord;
use think\db\BaseQuery;

/**
 * Centralized authorization façade — every controller/service that touches a
 * privileged action goes through here so the rules are in one place.
 *
 * Layered model:
 *   1. RBAC permission check    (`requireAny`, `has`, `hasAny`)
 *   2. Object-level rule        (`assertCanViewReimbursement`, ...)
 *   3. Data-scope rule          (`applyReimbursementScope`, ...)
 *
 * All `assertCan…` and `require*` methods throw `AuthorizationException` (→ HTTP
 * 403). Predicate `can…` methods return bool. List queries get scope clauses
 * appended with the same rule the per-row checks use, so list/show/export
 * agree on visibility.
 */
final class Authorization
{
    public function __construct(
        private PermissionResolver $perms,
        private ScopeFilter $scope,
    ) {}

    // -----------------------------------------------------------------
    // RBAC
    // -----------------------------------------------------------------

    public function has(int $userId, string $perm): bool
    {
        return $userId > 0 && $this->perms->has($userId, $perm);
    }

    public function hasAny(int $userId, array $perms): bool
    {
        return $userId > 0 && $this->perms->hasAny($userId, $perms);
    }

    public function requireAny(int $userId, array $perms, string $msg = ''): void
    {
        if (!$this->hasAny($userId, $perms)) {
            throw new AuthorizationException($msg ?: 'Missing required permission: ' . implode(' | ', $perms));
        }
    }

    public function requirePermission(int $userId, string $perm, string $msg = ''): void
    {
        $this->requireAny($userId, [$perm], $msg);
    }

    public function scopeOf(int $userId): array
    {
        return $this->perms->scopeFor($userId);
    }

    public function isGlobal(int $userId): bool
    {
        return !empty($this->scopeOf($userId)['global']);
    }

    /**
     * Verifies a (location, department) pair is permitted for this caller.
     * Global users always pass. Non-global callers must match at least one
     * side of their data scope via `ScopeFilter::permits()`.
     *
     * Used by every write path that accepts caller-supplied scope IDs
     * (reimbursement draft create/update/submit, budget precheck probes) so
     * clients cannot reach into scopes they do not hold.
     */
    public function scopePermits(int $userId, ?int $locationId, ?int $departmentId): bool
    {
        if ($this->isGlobal($userId)) return true;
        return $this->scope->permits($this->scopeOf($userId), $locationId, $departmentId);
    }

    public function assertScopePermitted(int $userId, ?int $locationId, ?int $departmentId, string $msg = 'Requested scope is outside your authorization'): void
    {
        if (!$this->scopePermits($userId, $locationId, $departmentId)) {
            throw new AuthorizationException($msg);
        }
    }

    // -----------------------------------------------------------------
    // Reimbursement object-level rules (spec §11.4 + §11.2)
    // -----------------------------------------------------------------

    /**
     * A user may VIEW a reimbursement when ANY of:
     *   - they are global (admin)
     *   - they are the submitter
     *   - they hold a reviewer/approver/finance/audit perm AND the row sits
     *     within their data scope
     */
    public function canViewReimbursement(int $userId, Reimbursement $r): bool
    {
        if ($userId <= 0) return false;
        if ($this->isGlobal($userId)) return true;
        if ((int)$r->submitter_user_id === $userId) return true;

        $hasPriv = $this->hasAny($userId, [
            'reimbursement.review',
            'reimbursement.approve',
            'reimbursement.reject',
            'reimbursement.override_cap',
            'audit.view',
            'ledger.view',
            'settlement.record',
            'settlement.confirm',
            'settlement.refund',
        ]);
        if (!$hasPriv) return false;

        return $this->scope->permits(
            $this->scopeOf($userId),
            $r->scope_location_id !== null ? (int)$r->scope_location_id : null,
            $r->scope_department_id !== null ? (int)$r->scope_department_id : null,
        );
    }

    public function assertCanViewReimbursement(int $userId, Reimbursement $r): void
    {
        if (!$this->canViewReimbursement($userId, $r)) {
            throw new AuthorizationException('Reimbursement is outside your authorization');
        }
    }

    /** Submitter-only mutations (edit-draft, withdraw). */
    public function assertCanModifyOwnReimbursement(int $userId, Reimbursement $r, string $action): void
    {
        if ($this->isGlobal($userId)) return; // admin override
        if ((int)$r->submitter_user_id !== $userId) {
            throw new AuthorizationException("Only the submitter may {$action} this reimbursement");
        }
    }

    /**
     * Reviewer/approver actions: caller must hold the named permission AND the
     * row must sit within their data scope.
     */
    public function assertCanReviewReimbursement(int $userId, Reimbursement $r, string $perm): void
    {
        $this->requirePermission($userId, $perm);
        if ($this->isGlobal($userId)) return;
        if (!$this->scope->permits(
            $this->scopeOf($userId),
            $r->scope_location_id !== null ? (int)$r->scope_location_id : null,
            $r->scope_department_id !== null ? (int)$r->scope_department_id : null,
        )) {
            throw new AuthorizationException('Reimbursement is outside your data scope');
        }
    }

    /**
     * Apply scope-aware filtering to a reimbursement listing. Submitter rows
     * are always visible to the submitter; non-global reviewers also see rows
     * inside their scope.
     */
    public function applyReimbursementScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];
        return $q->where(function ($sub) use ($userId, $locs, $deps) {
            $sub->where('submitter_user_id', $userId);
            if (!empty($locs)) $sub->whereOr('scope_location_id', 'in', $locs);
            if (!empty($deps)) $sub->whereOr('scope_department_id', 'in', $deps);
        });
    }

    // -----------------------------------------------------------------
    // Settlement object-level rules — settlements inherit scope from their
    // parent reimbursement.
    // -----------------------------------------------------------------

    public function canViewSettlement(int $userId, SettlementRecord $s): bool
    {
        if ($userId <= 0) return false;
        if ($this->isGlobal($userId)) return true;
        if (!$this->hasAny($userId, [
            'settlement.record', 'settlement.confirm', 'settlement.refund',
            'ledger.view', 'audit.view',
        ])) return false;
        $r = Reimbursement::find($s->reimbursement_id);
        if (!$r) return false;
        return $this->scope->permits(
            $this->scopeOf($userId),
            $r->scope_location_id !== null ? (int)$r->scope_location_id : null,
            $r->scope_department_id !== null ? (int)$r->scope_department_id : null,
        );
    }

    public function assertCanViewSettlement(int $userId, SettlementRecord $s): void
    {
        if (!$this->canViewSettlement($userId, $s)) {
            throw new AuthorizationException('Settlement is outside your authorization');
        }
    }

    /**
     * Restrict a settlement listing to rows whose parent reimbursement is
     * within the caller's scope. Implemented via subquery to keep the index
     * usage sane.
     */
    public function applySettlementScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];
        return $q->whereExists(function ($sub) use ($userId, $locs, $deps) {
            $sub->table('reimbursements')
                ->whereRaw('reimbursements.id = settlement_records.reimbursement_id')
                ->where(function ($w) use ($userId, $locs, $deps) {
                    $w->where('reimbursements.submitter_user_id', $userId);
                    if (!empty($locs)) $w->whereOr('reimbursements.scope_location_id', 'in', $locs);
                    if (!empty($deps)) $w->whereOr('reimbursements.scope_department_id', 'in', $deps);
                });
        });
    }

    // -----------------------------------------------------------------
    // Budget allocation / commitment scope
    // -----------------------------------------------------------------

    public function applyBudgetAllocationScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];
        return $q->where(function ($sub) use ($locs, $deps) {
            $sub->where('scope_type', 'org');
            if (!empty($locs)) $sub->whereOr(function ($w) use ($locs) {
                $w->where('scope_type', 'location')->whereIn('location_id', $locs);
            });
            if (!empty($deps)) $sub->whereOr(function ($w) use ($deps) {
                $w->where('scope_type', 'department')->whereIn('department_id', $deps);
            });
        });
    }

    public function applyCommitmentScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];
        return $q->whereExists(function ($sub) use ($userId, $locs, $deps) {
            $sub->table('reimbursements')
                ->whereRaw('reimbursements.id = fund_commitments.reimbursement_id')
                ->where(function ($w) use ($userId, $locs, $deps) {
                    $w->where('reimbursements.submitter_user_id', $userId);
                    if (!empty($locs)) $w->whereOr('reimbursements.scope_location_id', 'in', $locs);
                    if (!empty($deps)) $w->whereOr('reimbursements.scope_department_id', 'in', $deps);
                });
        });
    }

    // -----------------------------------------------------------------
    // Audit scope — non-global viewers see only their own actor rows.
    // -----------------------------------------------------------------

    public function applyAuditScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        return $q->where('actor_user_id', $userId);
    }

    // -----------------------------------------------------------------
    // Reconciliation scope — non-global operators can only see / start
    // reconciliation runs whose scope they themselves are entitled to.
    // Runs are org-level records, so `applyReconciliationRunScope()`
    // limits the listing to runs the caller started. The per-run
    // exception-scan queries in `ReconciliationService` use the same
    // caller scope to clip settlements / reimbursements pulled in.
    // -----------------------------------------------------------------

    public function applyReconciliationRunScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        return $q->where('started_by_user_id', $userId);
    }

    // -----------------------------------------------------------------
    // Schedule adjustment reviewer scope — joins through schedule_entries
    // so reviewers only see adjustments whose target entry sits inside
    // their authorized location/department. Mirrors the rule the
    // approve/reject paths enforce (HIGH fix audit-3 #2).
    // -----------------------------------------------------------------

    public function applyScheduleAdjustmentScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];
        return $q->whereExists(function ($sub) use ($userId, $locs, $deps) {
            $sub->table('schedule_entries')
                ->whereRaw('schedule_entries.id = schedule_adjustment_requests.target_entry_id')
                ->where(function ($w) use ($userId, $locs, $deps) {
                    // Always include rows for entries the caller themself
                    // owns (a reviewer who's also a coach sees their own).
                    $w->where('schedule_entries.coach_user_id', $userId);
                    if (!empty($locs)) $w->whereOr('schedule_entries.location_id',   'in', $locs);
                    if (!empty($deps)) $w->whereOr('schedule_entries.department_id', 'in', $deps);
                });
        });
    }

    // -----------------------------------------------------------------
    // Ledger scope — entries inherit scope from their parent settlement
    // (and that settlement inherits from its parent reimbursement). Refund
    // ledger rows go through refund_records → settlement_records → reimb.
    // Lines whose ref_entity_type isn't one of these are hidden from
    // non-global viewers (defensive default: nothing leaks).
    // -----------------------------------------------------------------

    public function applyLedgerScope(BaseQuery $q, int $userId): BaseQuery
    {
        if ($this->isGlobal($userId)) return $q;
        $scope = $this->scopeOf($userId);
        $locs  = $scope['locations'] ?? [];
        $deps  = $scope['departments'] ?? [];

        return $q->where(function ($outer) use ($userId, $locs, $deps) {
            $outer->where(function ($w) use ($userId, $locs, $deps) {
                $w->where('ref_entity_type', 'settlement')
                  ->whereExists(function ($sub) use ($userId, $locs, $deps) {
                      $sub->table('settlement_records')
                          ->whereRaw('settlement_records.id = ledger_entries.ref_entity_id')
                          ->whereExists(function ($sub2) use ($userId, $locs, $deps) {
                              $sub2->table('reimbursements')
                                   ->whereRaw('reimbursements.id = settlement_records.reimbursement_id')
                                   ->where(function ($wr) use ($userId, $locs, $deps) {
                                       $wr->where('reimbursements.submitter_user_id', $userId);
                                       if (!empty($locs)) $wr->whereOr('reimbursements.scope_location_id', 'in', $locs);
                                       if (!empty($deps)) $wr->whereOr('reimbursements.scope_department_id', 'in', $deps);
                                   });
                          });
                  });
            })->whereOr(function ($w) use ($userId, $locs, $deps) {
                $w->where('ref_entity_type', 'refund')
                  ->whereExists(function ($sub) use ($userId, $locs, $deps) {
                      $sub->table('refund_records')
                          ->whereRaw('refund_records.id = ledger_entries.ref_entity_id')
                          ->whereExists(function ($sub2) use ($userId, $locs, $deps) {
                              $sub2->table('settlement_records')
                                   ->whereRaw('settlement_records.id = refund_records.settlement_id')
                                   ->whereExists(function ($sub3) use ($userId, $locs, $deps) {
                                       $sub3->table('reimbursements')
                                            ->whereRaw('reimbursements.id = settlement_records.reimbursement_id')
                                            ->where(function ($wr) use ($userId, $locs, $deps) {
                                                $wr->where('reimbursements.submitter_user_id', $userId);
                                                if (!empty($locs)) $wr->whereOr('reimbursements.scope_location_id', 'in', $locs);
                                                if (!empty($deps)) $wr->whereOr('reimbursements.scope_department_id', 'in', $deps);
                                            });
                                   });
                          });
                  });
            });
        });
    }
}
