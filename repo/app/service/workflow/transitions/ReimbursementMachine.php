<?php
namespace app\service\workflow\transitions;

use app\service\workflow\StateMachine;

/** Spec §10.4 reimbursement state machine. */
class ReimbursementMachine
{
    public static function make(): StateMachine
    {
        return new StateMachine('reimbursement', [
            'draft'                    => ['submitted', 'cancelled'],
            'submitted'                => ['under_review', 'rejected', 'withdrawn', 'pending_override_review'],
            'pending_override_review'  => ['under_review', 'rejected'],
            'under_review'             => ['approved', 'rejected', 'needs_revision'],
            'needs_revision'           => ['resubmitted', 'withdrawn'],
            'resubmitted'              => ['under_review', 'rejected', 'pending_override_review'],
            'approved'                 => ['settlement_pending', 'settled'],
            'settlement_pending'       => ['settled', 'rejected'], // 'rejected' here only via admin recall path
            'settled'                  => ['partially_refunded', 'refunded'],
            'partially_refunded'       => ['refunded', 'partially_refunded'], // additional refunds
            'refunded'                 => [],
            'rejected'                 => [],
            'withdrawn'                => [],
            'cancelled'                => [],
        ]);
    }
}
